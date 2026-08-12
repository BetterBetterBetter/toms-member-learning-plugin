<?php
/**
 * Durable catalogue webhook outbox, signing, and retry contract.
 *
 * Run: php -d memory_limit=512M /usr/local/bin/wp eval-file
 * tests/library-catalogue-webhook-contract.php --skip-themes
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract check through WP-CLI.');
}

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(class_exists('TSOL_Library_Catalogue_Webhook'), 'Catalogue webhook delivery is not loaded.');
$assert(has_action('tsol_library_catalogue_change_recorded', array('TSOL_Library_Catalogue_Webhook', 'queue_change')) !== false, 'The durable change journal is not connected to the webhook outbox.');
$assert(has_action(TSOL_Library_Catalogue_Webhook::CRON_HOOK, array('TSOL_Library_Catalogue_Webhook', 'deliver_pending')) !== false, 'The webhook delivery cron handler is not registered.');

TSOL_Library_Catalogue_Webhook::maybe_install();
global $wpdb;
$table = TSOL_Library_Catalogue_Webhook::table();
$assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table, 'The durable webhook outbox table is missing.');

$existing_rows = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
$existing_schedule = wp_next_scheduled(TSOL_Library_Catalogue_Webhook::CRON_HOOK);
$assert(0 === $existing_rows, 'The webhook outbox must be drained before running the isolated delivery contract.');
$assert(false === $existing_schedule, 'The webhook cron must be idle before running the isolated delivery contract.');

$secret = 'wordpress-catalogue-webhook-contract-secret-123456789';
$requests = array();
$transport_outcome = 'success';
$secret_filter = static function () use ($secret) {
    return $secret;
};
$url_filter = static function () {
    return 'https://library.example.test/api/internal/catalogue/wake';
};
$http_filter = static function ($preempt, $args, $url) use (&$requests, &$transport_outcome) {
    unset($preempt);
    $requests[] = array('args' => $args, 'url' => $url);
    if ('failure' === $transport_outcome) {
        return new WP_Error('contract_transport_failure', 'Synthetic contract failure.');
    }
    return array(
        'headers' => array(),
        'body' => '',
        'response' => array('code' => 202, 'message' => 'Accepted'),
        'cookies' => array(),
        'filename' => null,
    );
};
add_filter('tsol_library_catalogue_webhook_secret', $secret_filter);
add_filter('tsol_library_catalogue_webhook_url', $url_filter);
add_filter('pre_http_request', $http_filter, 10, 3);

$insert_delivery = static function ($delivery_id, $cursor) use ($wpdb, $table) {
    $now = current_time('mysql', true);
    return $wpdb->insert(
        $table,
        array(
            'delivery_id' => $delivery_id,
            'change_cursor' => $cursor,
            'attempt_count' => 0,
            'next_attempt_at' => $now,
            'locked_until' => null,
            'created_at' => $now,
        ),
        array('%s', '%d', '%d', '%s', '%s', '%s')
    );
};

$cursor = TSOL_Library_Content_Changes::current_cursor() + 1;
$delivery_id = wp_generate_uuid4();
if (0 === $existing_rows && false === $existing_schedule) {
    $assert(false !== $insert_delivery($delivery_id, $cursor), 'Could not create the disposable webhook delivery.');
    TSOL_Library_Catalogue_Webhook::deliver_pending();
    $assert(1 === count($requests), 'A due webhook delivery did not perform exactly one HTTP request.');
    $assert(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE delivery_id = %s', $delivery_id)), 'An accepted webhook delivery remained in the outbox.'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.

    if (!empty($requests)) {
        $request = $requests[0];
        $body = (string) $request['args']['body'];
        $payload = json_decode($body, true);
        $timestamp = (string) ($request['args']['headers']['X-TSOL-Webhook-Timestamp'] ?? '');
        $signature = (string) ($request['args']['headers']['X-TSOL-Webhook-Signature'] ?? '');
        $expected_signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        $assert(TSOL_Library_Catalogue_Webhook::ENDPOINT_PATH === wp_parse_url((string) $request['url'], PHP_URL_PATH), 'Webhook delivery used an unexpected endpoint path.');
        $assert(isset($request['args']['redirection']) && 0 === (int) $request['args']['redirection'], 'Webhook delivery allowed HTTP redirects.');
        $assert(!empty($request['args']['sslverify']), 'Webhook delivery disabled TLS verification.');
        $assert(5 === (int) $request['args']['timeout'], 'Webhook delivery does not use the bounded transport timeout.');
        $assert(hash_equals($expected_signature, $signature), 'Webhook delivery signature does not cover the timestamp and exact raw body.');
        $assert(is_array($payload) && 1 === (int) ($payload['version'] ?? 0), 'Webhook payload version changed.');
        $assert(is_array($payload) && TSOL_Library_Catalogue_Webhook::EVENT === ($payload['event'] ?? ''), 'Webhook payload event changed.');
        $assert(is_array($payload) && TSOL_Library_Catalogue_Webhook::AUDIENCE === ($payload['audience'] ?? ''), 'Webhook payload audience changed.');
        $assert(is_array($payload) && $delivery_id === ($payload['delivery_id'] ?? ''), 'Webhook payload omitted its idempotency key.');
        $assert(is_array($payload) && (string) $cursor === ($payload['cursor'] ?? ''), 'Webhook payload omitted the durable change cursor.');
        $assert(is_array($payload) && (int) $timestamp === (int) ($payload['issued_at'] ?? -1), 'Webhook timestamp header and payload disagree.');
        $assert(is_array($payload) && array() === array_diff(array_keys($payload), array('version', 'event', 'audience', 'delivery_id', 'cursor', 'issued_at')), 'Webhook payload leaked fields outside the wake-up contract.');
        $assert(false === strpos($body, 'post_id') && false === strpos($body, 'authorization') && false === strpos($body, 'member'), 'Webhook payload contains catalogue or authorization data.');
    }

    $retry_delivery_id = wp_generate_uuid4();
    $transport_outcome = 'failure';
    $assert(false !== $insert_delivery($retry_delivery_id, $cursor + 1), 'Could not create the disposable retry delivery.');
    TSOL_Library_Catalogue_Webhook::deliver_pending();
    $retry_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE delivery_id = %s', $retry_delivery_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
    $assert(is_array($retry_row) && 1 === (int) $retry_row['attempt_count'], 'A failed webhook delivery did not persist its retry attempt.');
    $retry_delay = is_array($retry_row) ? strtotime($retry_row['next_attempt_at'] . ' UTC') - time() : 0;
    $assert($retry_delay >= 8 && $retry_delay <= 12, 'The first retry was not scheduled with the expected bounded delay.');

    $transport_outcome = 'success';
    $wpdb->update($table, array('next_attempt_at' => current_time('mysql', true)), array('delivery_id' => $retry_delivery_id), array('%s'), array('%s'));
    TSOL_Library_Catalogue_Webhook::deliver_pending();
    $assert(3 === count($requests), 'A retry did not perform a second HTTP request.');
    if (3 === count($requests)) {
        $retried_payload = json_decode((string) $requests[2]['args']['body'], true);
        $assert($retry_delivery_id === ($retried_payload['delivery_id'] ?? ''), 'A retry changed its idempotency key.');
    }
    $assert(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE delivery_id = %s', $retry_delivery_id)), 'A successful retry remained in the outbox.'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
}

remove_filter('pre_http_request', $http_filter, 10);
remove_filter('tsol_library_catalogue_webhook_url', $url_filter);
remove_filter('tsol_library_catalogue_webhook_secret', $secret_filter);
if (false === $existing_schedule) {
    wp_clear_scheduled_hook(TSOL_Library_Catalogue_Webhook::CRON_HOOK);
}
$wpdb->delete($table, array('delivery_id' => $delivery_id), array('%s'));
if (isset($retry_delivery_id)) {
    $wpdb->delete($table, array('delivery_id' => $retry_delivery_id), array('%s'));
}

if (!empty($failures)) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error('TSOL Library catalogue webhook contract failed with ' . count($failures) . ' issue(s).');
}

WP_CLI::success('TSOL Library catalogue webhook contract passed.');
