<?php
/**
 * Durable authentication revocation delivery contract.
 *
 * Run: php -d memory_limit=512M /usr/local/bin/wp eval-file
 * tests/library-auth-revocation-contract.php --skip-themes
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

$assert(class_exists('MemberLibrary_Auth_Revocation'), 'Authentication revocation delivery is not loaded.');
$assert(has_action('wp_set_password', array('MemberLibrary_Auth_Revocation', 'password_changed')) !== false, 'Password changes do not queue Library revocation.');
$assert(has_action('deleted_user', array('MemberLibrary_Auth_Revocation', 'user_deleted')) !== false, 'User deletion does not queue Library revocation.');
$assert(has_action('profile_update', array('MemberLibrary_Auth_Revocation', 'profile_updated')) !== false, 'Identity changes do not queue Library revocation.');
$assert(has_action('set_user_role', array('MemberLibrary_Auth_Revocation', 'roles_changed')) !== false, 'Role changes do not queue Library revocation.');
$assert(has_action('tsol_library_auth_revocation_requested', array('MemberLibrary_Auth_Revocation', 'queue')) !== false, 'Security integrations cannot request Library revocation.');
$assert(has_action(MemberLibrary_Auth_Revocation::CRON_HOOK, array('MemberLibrary_Auth_Revocation', 'deliver_pending')) !== false, 'The revocation cron handler is not registered.');

MemberLibrary_Auth_Revocation::maybe_install();
MemberLibrary_Auth_Repository::install();
global $wpdb;
$table = MemberLibrary_Auth_Revocation::table();
$messages_table = MemberLibrary_Auth_Repository::messages_table();
$assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table, 'The durable revocation outbox table is missing.');
$assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $messages_table)) === $messages_table, 'The one-time authentication-message table is missing.');

$message_jti = wp_generate_uuid4();
$first_consume = MemberLibrary_Auth_Repository::consume_message($message_jti, 'auth.logout', time() + 60);
$replay_consume = MemberLibrary_Auth_Repository::consume_message($message_jti, 'auth.logout', time() + 60);
$assert(true === $first_consume, 'A valid authentication message could not be consumed.');
$assert(is_wp_error($replay_consume) && 'message_replay' === $replay_consume->get_error_code(), 'An authentication-message replay was not rejected.');
$wpdb->delete($messages_table, array('jti' => $message_jti), array('%s'));

$existing_rows = (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . $table); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
$existing_schedule = wp_next_scheduled(MemberLibrary_Auth_Revocation::CRON_HOOK);
$assert(0 === $existing_rows, 'The revocation outbox must be drained before running the isolated delivery contract.');
$assert(false !== $existing_schedule, 'The revocation delivery watchdog is not scheduled.');
wp_clear_scheduled_hook(MemberLibrary_Auth_Revocation::CRON_HOOK);

$secret = 'wordpress-auth-revocation-contract-secret-123456789';
$requests = array();
$transport_outcome = 'success';
$response_code = 202;
$secret_filter = static function () use ($secret) {
    return $secret;
};
$url_filter = static function () {
    return 'https://library.example.test/api/internal/auth/revoke';
};
$http_filter = static function ($preempt, $args, $url) use (&$requests, &$transport_outcome, &$response_code) {
    unset($preempt);
    $requests[] = array('args' => $args, 'url' => $url);
    if ('failure' === $transport_outcome) {
        return new WP_Error('contract_transport_failure', 'Synthetic contract failure.');
    }
    return array(
        'headers' => array(),
        'body' => '',
        'response' => array('code' => $response_code, 'message' => 'Contract response'),
        'cookies' => array(),
        'filename' => null,
    );
};
add_filter('tsol_library_auth_revocation_secret', $secret_filter);
add_filter('tsol_library_auth_revocation_url', $url_filter);
add_filter('pre_http_request', $http_filter, 10, 3);

$insert_delivery = static function ($jti, $user_id, $event) use ($wpdb, $table) {
    $now = current_time('mysql', true);
    return $wpdb->insert($table, array(
        'jti' => $jti,
        'user_id' => $user_id,
        'event' => $event,
        'attempt_count' => 0,
        'next_attempt_at' => $now,
        'locked_until' => null,
        'created_at' => $now,
    ), array('%s', '%d', '%s', '%d', '%s', '%s', '%s'));
};

$user_id = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1");
$jti = wp_generate_uuid4();
if (0 === $existing_rows && $user_id > 0) {
    $assert(false !== $insert_delivery($jti, $user_id, 'user.password_reset'), 'Could not create the disposable revocation delivery.');
    MemberLibrary_Auth_Revocation::deliver_pending();
    $assert(1 === count($requests), 'A due revocation did not perform exactly one HTTP request.');
    $assert(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE jti = %s', $jti)), 'An accepted revocation remained in the outbox.'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.

    if (!empty($requests)) {
        $request = $requests[0];
        $body = (string) $request['args']['body'];
        $payload = json_decode($body, true);
        $timestamp = (string) ($request['args']['headers']['X-TSOL-Webhook-Timestamp'] ?? '');
        $signature = (string) ($request['args']['headers']['X-TSOL-Webhook-Signature'] ?? '');
        $expected_signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);

        $assert(MemberLibrary_Auth_Revocation::ENDPOINT_PATH === wp_parse_url((string) $request['url'], PHP_URL_PATH), 'Revocation delivery used an unexpected endpoint path.');
        $assert(0 === (int) ($request['args']['redirection'] ?? -1), 'Revocation delivery allowed HTTP redirects.');
        $assert(!empty($request['args']['sslverify']), 'Revocation delivery disabled TLS verification.');
        $assert(5 === (int) ($request['args']['timeout'] ?? 0), 'Revocation delivery does not use the bounded transport timeout.');
        $assert(hash_equals($expected_signature, $signature), 'Revocation signature does not cover the timestamp and exact raw body.');
        $assert(is_array($payload) && 1 === (int) ($payload['version'] ?? 0), 'Revocation payload version changed.');
        $assert(is_array($payload) && 'user.password_reset' === ($payload['event'] ?? ''), 'Revocation payload event changed.');
        $assert(is_array($payload) && MemberLibrary_Auth_Revocation::AUDIENCE === ($payload['audience'] ?? ''), 'Revocation payload audience changed.');
        $assert(is_array($payload) && $jti === ($payload['jti'] ?? ''), 'Revocation payload omitted its one-time jti.');
        $assert(is_array($payload) && (string) $user_id === ($payload['wordpress_user_id'] ?? ''), 'Revocation payload omitted the canonical WordPress user ID.');
        $assert(is_array($payload) && (int) $timestamp === (int) ($payload['issued_at'] ?? -1), 'Revocation timestamp header and payload disagree.');
        $assert(is_array($payload) && array() === array_diff(array_keys($payload), array('version', 'event', 'audience', 'jti', 'wordpress_user_id', 'issued_at')), 'Revocation payload leaked fields outside the contract.');
    }

    $replayed_jti = wp_generate_uuid4();
    $response_code = 409;
    $assert(false !== $insert_delivery($replayed_jti, $user_id, 'user.sessions_forced_logout'), 'Could not create the disposable replay acknowledgement.');
    MemberLibrary_Auth_Revocation::deliver_pending();
    $assert(0 === (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE jti = %s', $replayed_jti)), 'A receiver-confirmed replay remained in the outbox.'); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.

    $retry_jti = wp_generate_uuid4();
    $response_code = 202;
    $transport_outcome = 'failure';
    $assert(false !== $insert_delivery($retry_jti, $user_id, 'user.suspended'), 'Could not create the disposable retry delivery.');
    MemberLibrary_Auth_Revocation::deliver_pending();
    $retry_row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . $table . ' WHERE jti = %s', $retry_jti), ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
    $assert(is_array($retry_row) && 1 === (int) $retry_row['attempt_count'], 'A failed revocation did not persist its retry attempt.');
    $retry_delay = is_array($retry_row) ? strtotime($retry_row['next_attempt_at'] . ' UTC') - time() : 0;
    $assert($retry_delay >= 8 && $retry_delay <= 12, 'The first revocation retry was not scheduled with the expected bounded delay.');
}

remove_filter('pre_http_request', $http_filter, 10);
remove_filter('tsol_library_auth_revocation_url', $url_filter);
remove_filter('tsol_library_auth_revocation_secret', $secret_filter);
wp_clear_scheduled_hook(MemberLibrary_Auth_Revocation::CRON_HOOK);
if (false !== $existing_schedule) {
    wp_schedule_single_event(max(time(), (int) $existing_schedule), MemberLibrary_Auth_Revocation::CRON_HOOK);
}
foreach (array($jti, $replayed_jti ?? '', $retry_jti ?? '') as $cleanup_jti) {
    if ($cleanup_jti !== '') {
        $wpdb->delete($table, array('jti' => $cleanup_jti), array('%s'));
    }
}

// Managed hosts that cannot edit wp-config may store the revocation secret as a
// write-only WordPress setting; a server constant still wins when present.
if (!defined('TSOL_LIBRARY_AUTH_REVOCATION_SECRET')) {
    $secret_method = new ReflectionMethod(MemberLibrary_Auth_Revocation::class, 'secret');
    $secret_method->setAccessible(true);
    $previous_secret_option = get_option(MemberLibrary_Auth_Settings::AUTH_REVOCATION_SECRET_OPTION, null);
    $settings = new MemberLibrary_Auth_Settings();
    $option_secret = 'option-managed-revocation-secret-' . wp_generate_password(24, false);
    update_option(MemberLibrary_Auth_Settings::AUTH_REVOCATION_SECRET_OPTION, $option_secret, false);
    $assert($option_secret === MemberLibrary_Auth_Settings::auth_revocation_secret(), 'The write-only revocation secret setting is not exposed by the settings service.');
    $assert($option_secret === $secret_method->invoke(null), 'Revocation delivery does not use the write-only revocation secret setting when no constant is defined.');
    $assert($option_secret === $settings->sanitize_auth_revocation_secret(''), 'A blank revocation secret submission did not keep the saved value.');
    $assert($option_secret === $settings->sanitize_auth_revocation_secret('too-short'), 'A revocation secret under 32 characters was accepted.');
    if (MemberLibrary_Auth_Settings::client_secret() !== '') {
        $assert($option_secret === $settings->sanitize_auth_revocation_secret(MemberLibrary_Auth_Settings::client_secret()), 'The revocation secret accepted the Library client secret as its value.');
    }
    if (MemberLibrary_Auth_Settings::catalogue_webhook_secret() !== '') {
        $assert($option_secret === $settings->sanitize_auth_revocation_secret(MemberLibrary_Auth_Settings::catalogue_webhook_secret()), 'The revocation secret accepted the catalogue webhook secret as its value.');
    }
    $replacement_secret = 'replacement-revocation-secret-' . wp_generate_password(24, false);
    $assert($replacement_secret === $settings->sanitize_auth_revocation_secret($replacement_secret), 'A valid replacement revocation secret was rejected.');
    if (null === $previous_secret_option) {
        delete_option(MemberLibrary_Auth_Settings::AUTH_REVOCATION_SECRET_OPTION);
    } else {
        update_option(MemberLibrary_Auth_Settings::AUTH_REVOCATION_SECRET_OPTION, $previous_secret_option, false);
    }
}

$assert(false === MemberLibrary_Auth_Revocation::queue(0, 'user.suspended'), 'Revocation accepted an invalid WordPress user ID.');
$assert(false === MemberLibrary_Auth_Revocation::queue($user_id, 'user.unknown'), 'Revocation accepted an unknown event type.');

if (!empty($failures)) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error('Library authentication revocation contract failed with ' . count($failures) . ' issue(s).');
}

WP_CLI::success('Library authentication revocation contract passed.');
