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

$assert(class_exists('MemberLibrary_Catalogue_Webhook'), 'Catalogue webhook delivery is not loaded.');
$assert(has_action('tsol_library_catalogue_change_recorded', array('MemberLibrary_Catalogue_Webhook', 'queue_change')) !== false, 'The durable change journal is not connected to the webhook outbox.');
$assert(has_action(MemberLibrary_Catalogue_Webhook::CRON_HOOK, array('MemberLibrary_Catalogue_Webhook', 'deliver_pending')) !== false, 'The webhook delivery cron handler is not registered.');
$assert(has_action(MemberLibrary_Catalogue_Webhook::WATCHDOG_HOOK, array('MemberLibrary_Catalogue_Webhook', 'deliver_pending')) !== false, 'The recurring webhook recovery watchdog is not registered.');

MemberLibrary_Catalogue_Webhook::maybe_install();
global $wpdb;
$table = MemberLibrary_Catalogue_Webhook::table();
$assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table, 'The durable webhook outbox table is missing.');

$existing_rows = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
$existing_schedule = wp_next_scheduled(MemberLibrary_Catalogue_Webhook::CRON_HOOK);
$existing_watchdog = wp_next_scheduled(MemberLibrary_Catalogue_Webhook::WATCHDOG_HOOK);
$missing_option_marker = new stdClass();
$existing_last_delivery = get_option(MemberLibrary_Catalogue_Webhook::LAST_DELIVERY_OPTION, $missing_option_marker);
$assert(0 === $existing_rows, 'The webhook outbox must be drained before running the isolated delivery contract.');
$assert(false !== wp_next_scheduled(MemberLibrary_Catalogue_Webhook::WATCHDOG_HOOK), 'The one-minute delivery watchdog was not installed.');

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

$cursor = MemberLibrary_Content_Changes::current_cursor() + 1;
$delivery_id = wp_generate_uuid4();
if (0 === $existing_rows) {
    $assert(false !== $insert_delivery($delivery_id, $cursor), 'Could not create the disposable webhook delivery.');
    MemberLibrary_Catalogue_Webhook::deliver_pending();
    $assert(1 === count($requests), 'A due webhook delivery did not perform exactly one HTTP request.');
    $assert(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE delivery_id = %s', $delivery_id)), 'An accepted webhook delivery remained in the outbox.'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.

    if (!empty($requests)) {
        $request = $requests[0];
        $body = (string) $request['args']['body'];
        $payload = json_decode($body, true);
        $timestamp = (string) ($request['args']['headers']['X-TSOL-Webhook-Timestamp'] ?? '');
        $signature = (string) ($request['args']['headers']['X-TSOL-Webhook-Signature'] ?? '');
        $expected_signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        $assert(MemberLibrary_Catalogue_Webhook::ENDPOINT_PATH === wp_parse_url((string) $request['url'], PHP_URL_PATH), 'Webhook delivery used an unexpected endpoint path.');
        $assert(isset($request['args']['redirection']) && 0 === (int) $request['args']['redirection'], 'Webhook delivery allowed HTTP redirects.');
        $assert(!empty($request['args']['sslverify']), 'Webhook delivery disabled TLS verification.');
        $assert(5 === (int) $request['args']['timeout'], 'Webhook delivery does not use the bounded transport timeout.');
        $assert(!empty($request['args']['blocking']), 'Confirmed webhook delivery was not blocking.');
        $assert(hash_equals($expected_signature, $signature), 'Webhook delivery signature does not cover the timestamp and exact raw body.');
        $assert(is_array($payload) && 1 === (int) ($payload['version'] ?? 0), 'Webhook payload version changed.');
        $assert(is_array($payload) && MemberLibrary_Catalogue_Webhook::EVENT === ($payload['event'] ?? ''), 'Webhook payload event changed.');
        $assert(is_array($payload) && MemberLibrary_Catalogue_Webhook::AUDIENCE === ($payload['audience'] ?? ''), 'Webhook payload audience changed.');
        $assert(is_array($payload) && $delivery_id === ($payload['delivery_id'] ?? ''), 'Webhook payload omitted its idempotency key.');
        $assert(is_array($payload) && (string) $cursor === ($payload['cursor'] ?? ''), 'Webhook payload omitted the durable change cursor.');
        $assert(is_array($payload) && (int) $timestamp === (int) ($payload['issued_at'] ?? -1), 'Webhook timestamp header and payload disagree.');
        $assert(is_array($payload) && array() === array_diff(array_keys($payload), array('version', 'event', 'audience', 'delivery_id', 'cursor', 'issued_at')), 'Webhook payload leaked fields outside the wake-up contract.');
        $assert(false === strpos($body, 'post_id') && false === strpos($body, 'authorization') && false === strpos($body, 'member'), 'Webhook payload contains catalogue or authorization data.');
    }

    $immediate_filter = static function () {
        return true;
    };
    add_filter('tsol_library_catalogue_immediate_delivery_enabled', $immediate_filter);
    $immediate_delivery_id = null;
    MemberLibrary_Catalogue_Webhook::queue_change($cursor + 1);
    MemberLibrary_Catalogue_Webhook::flush_queued_change();
    remove_filter('tsol_library_catalogue_immediate_delivery_enabled', $immediate_filter);
    $immediate_row = $wpdb->get_row('SELECT delivery_id, change_cursor FROM ' . $table . ' ORDER BY change_cursor DESC LIMIT 1', ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
    $immediate_delivery_id = is_array($immediate_row) ? (string) $immediate_row['delivery_id'] : null;
    $assert(2 === count($requests), 'A normal save did not dispatch one immediate wake-up after persisting its outbox row.');
    $assert(is_array($immediate_row) && (string) ($cursor + 1) === (string) $immediate_row['change_cursor'], 'Immediate delivery removed or changed the durable outbox row before confirmation.');
    if (isset($requests[1])) {
        $assert(empty($requests[1]['args']['blocking']), 'Immediate wake-up delivery blocked the WordPress save request.');
        $assert(1 === (int) $requests[1]['args']['timeout'], 'Immediate wake-up does not use its bounded one-second connect timeout.');
        $immediate_payload = json_decode((string) $requests[1]['args']['body'], true);
        $assert($immediate_delivery_id === ($immediate_payload['delivery_id'] ?? ''), 'Immediate delivery did not reuse the durable outbox idempotency key.');
    }
    MemberLibrary_Catalogue_Webhook::deliver_pending();
    $assert(3 === count($requests), 'The durable outbox did not later confirm the immediate wake-up with a blocking request.');
    $assert(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE delivery_id = %s', $immediate_delivery_id)), 'Confirmed immediate wake-up remained in the outbox.'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.

    $retry_delivery_id = wp_generate_uuid4();
    $transport_outcome = 'failure';
    $assert(false !== $insert_delivery($retry_delivery_id, $cursor + 1), 'Could not create the disposable retry delivery.');
    MemberLibrary_Catalogue_Webhook::deliver_pending();
    $retry_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE delivery_id = %s', $retry_delivery_id), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
    $assert(is_array($retry_row) && 1 === (int) $retry_row['attempt_count'], 'A failed webhook delivery did not persist its retry attempt.');
    $retry_delay = is_array($retry_row) ? strtotime($retry_row['next_attempt_at'] . ' UTC') - time() : 0;
    $assert($retry_delay >= 8 && $retry_delay <= 12, 'The first retry was not scheduled with the expected bounded delay.');
    $assert(false !== wp_next_scheduled(MemberLibrary_Catalogue_Webhook::WATCHDOG_HOOK), 'A failed delivery lost the recurring recovery watchdog.');

    $transport_outcome = 'success';
    $assert(MemberLibrary_Catalogue_Webhook::retry_pending_now(), 'The administrator retry did not drain an accepted pending delivery.');
    $assert(5 === count($requests), 'A retry did not perform a second HTTP request.');
    if (5 === count($requests)) {
        $retried_payload = json_decode((string) $requests[4]['args']['body'], true);
        $assert($retry_delivery_id === ($retried_payload['delivery_id'] ?? ''), 'A retry changed its idempotency key.');
    }
    $assert(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE delivery_id = %s', $retry_delivery_id)), 'A successful retry remained in the outbox.'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
}

remove_filter('pre_http_request', $http_filter, 10);
remove_filter('tsol_library_catalogue_webhook_url', $url_filter);
remove_filter('tsol_library_catalogue_webhook_secret', $secret_filter);
if (false === $existing_schedule) {
    wp_clear_scheduled_hook(MemberLibrary_Catalogue_Webhook::CRON_HOOK);
}
if (false === $existing_watchdog) {
    wp_clear_scheduled_hook(MemberLibrary_Catalogue_Webhook::WATCHDOG_HOOK);
}
$wpdb->delete($table, array('delivery_id' => $delivery_id), array('%s'));
if (isset($retry_delivery_id)) {
    $wpdb->delete($table, array('delivery_id' => $retry_delivery_id), array('%s'));
}
if ($missing_option_marker === $existing_last_delivery) {
    delete_option(MemberLibrary_Catalogue_Webhook::LAST_DELIVERY_OPTION);
} else {
    update_option(MemberLibrary_Catalogue_Webhook::LAST_DELIVERY_OPTION, $existing_last_delivery, false);
}

if (!empty($failures)) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error('TSOL Library catalogue webhook contract failed with ' . count($failures) . ' issue(s).');
}

WP_CLI::success('TSOL Library catalogue webhook contract passed.');
