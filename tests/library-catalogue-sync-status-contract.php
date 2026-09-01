<?php
/**
 * Signed School status request and WordPress health surface contract.
 *
 * Run: php -d memory_limit=512M /usr/local/bin/wp eval-file
 * tests/library-catalogue-sync-status-contract.php --skip-themes
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

$assert(class_exists('MemberLibrary_Catalogue_Sync_Status'), 'Catalogue sync status UI is not loaded.');
$status_ui = new MemberLibrary_Catalogue_Sync_Status();
$tests = $status_ui->register_site_health_test(array());
$assert(isset($tests['direct'][MemberLibrary_Catalogue_Sync_Status::SITE_HEALTH_TEST]), 'Catalogue delivery is absent from WordPress Site Health.');

$secret = 'catalogue-status-wordpress-contract-secret-123456789';
$requests = array();
$response_payload = array(
    'version' => 1,
    'schema_version' => '20260821.2',
    'cursor' => '50169',
    'last_successful_sync_at' => '2026-08-13T08:27:01.000Z',
    'latest_run' => array(
        'status' => 'SUCCEEDED',
        'completed_at' => '2026-08-13T08:27:01.000Z',
        'error_code' => null,
    ),
    'pending_wakeups' => 0,
);
$secret_filter = static function () use ($secret) {
    return $secret;
};
$url_filter = static function () {
    return 'https://school.example.test/api/internal/catalogue/status';
};
$http_filter = static function ($preempt, $args, $url) use (&$requests, &$response_payload) {
    unset($preempt);
    $requests[] = array('args' => $args, 'url' => $url);
    return array(
        'headers' => array('content-type' => 'application/json'),
        'body' => wp_json_encode($response_payload, JSON_UNESCAPED_SLASHES),
        'response' => array('code' => 200, 'message' => 'OK'),
        'cookies' => array(),
        'filename' => null,
    );
};
add_filter('tsol_library_catalogue_webhook_secret', $secret_filter);
add_filter('tsol_library_catalogue_status_url', $url_filter);
add_filter('pre_http_request', $http_filter, 10, 3);

$source_cursor = '50169';
$status = MemberLibrary_Catalogue_Webhook::school_status($source_cursor);
$assert(!empty($status['ok']), 'A valid signed School status response was rejected.');
$assert($source_cursor === ($status['cursor'] ?? ''), 'School status omitted its current catalogue cursor.');
$assert(1 === count($requests), 'School status did not perform exactly one request.');
if (!empty($requests)) {
    $request = $requests[0];
    $body = (string) $request['args']['body'];
    $payload = json_decode($body, true);
    $timestamp = (string) ($request['args']['headers']['X-TSOL-Catalogue-Timestamp'] ?? '');
    $signature = (string) ($request['args']['headers']['X-TSOL-Catalogue-Signature'] ?? '');
    $expected_signature = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    $assert(MemberLibrary_Catalogue_Webhook::STATUS_ENDPOINT_PATH === wp_parse_url((string) $request['url'], PHP_URL_PATH), 'Status request used an unexpected endpoint path.');
    $assert(!empty($request['args']['blocking']) && 5 === (int) $request['args']['timeout'], 'Status request did not use a bounded blocking transport.');
    $assert(0 === (int) $request['args']['redirection'] && !empty($request['args']['sslverify']), 'Status request allows redirects or disables TLS verification.');
    $assert(hash_equals($expected_signature, $signature), 'Status signature does not cover the timestamp and exact raw body.');
    $assert(is_array($payload) && array() === array_diff(array_keys($payload), array('version', 'event', 'audience', 'request_id', 'source_cursor', 'issued_at')), 'Status request leaked fields outside its operational contract.');
    $assert(is_array($payload) && 'catalogue.status.requested' === ($payload['event'] ?? ''), 'Status request event changed.');
    $assert(is_array($payload) && $source_cursor === ($payload['source_cursor'] ?? ''), 'Status request omitted the source cursor.');
    $assert(false === strpos($body, 'post_id') && false === strpos($body, 'member') && false === strpos($body, 'secret'), 'Status request contains editorial, member, or secret data.');
}

$response_payload['unexpected'] = true;
$invalid = MemberLibrary_Catalogue_Webhook::school_status($source_cursor);
$assert(empty($invalid['ok']) && 'invalid_response' === ($invalid['error_code'] ?? ''), 'Status client accepted a response with unknown fields.');

$response_payload = array(
    'version' => 1,
    'schema_version' => '20260821.2',
    'cursor' => '50169',
    'last_successful_sync_at' => null,
    'latest_run' => array(
        'status' => 'FAILED',
        'completed_at' => null,
        'error_code' => 'PRIVATE RAW ERROR',
    ),
    'pending_wakeups' => 0,
);
$invalid_error = MemberLibrary_Catalogue_Webhook::school_status($source_cursor);
$assert(empty($invalid_error['ok']), 'Status client accepted a non-allowlisted School error code.');

remove_filter('pre_http_request', $http_filter, 10);
remove_filter('tsol_library_catalogue_status_url', $url_filter);
remove_filter('tsol_library_catalogue_webhook_secret', $secret_filter);

$local = MemberLibrary_Catalogue_Webhook::delivery_status();
$assert(isset($local['source_cursor'], $local['pending'], $local['last_delivery'], $local['watchdog_scheduled_at']), 'Local catalogue health omitted required operational fields.');
$assert(null !== $local['watchdog_scheduled_at'], 'Local catalogue health reports a missing recovery watchdog.');
$assert(!isset($local['secret']) && !isset($local['delivery_id']), 'Local catalogue health exposed a secret or delivery id.');

if (!empty($failures)) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error('TSOL Library catalogue sync-status contract failed with ' . count($failures) . ' issue(s).');
}

WP_CLI::success('TSOL Library catalogue sync-status contract passed.');
