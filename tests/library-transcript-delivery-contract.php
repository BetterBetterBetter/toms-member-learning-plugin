<?php
/**
 * Private transcript source and signed School delivery contract.
 *
 * Run: php -d memory_limit=512M /usr/local/bin/wp eval-file
 * tests/library-transcript-delivery-contract.php --skip-themes
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(class_exists('TSOL_Library_Content_Transcripts'), 'Transcript delivery is not loaded.');
$assert(false !== has_action(TSOL_Library_Content_Transcripts::RETRY_HOOK, array('TSOL_Library_Content_Transcripts', 'deliver')), 'Transcript retry handler is not registered.');

$transcript_keys = array(
    TSOL_Library_Content_Model::META_TRANSCRIPT_CONTENT,
    TSOL_Library_Content_Model::META_TRANSCRIPT_HASH,
    TSOL_Library_Content_Model::META_TRANSCRIPT_LANGUAGE,
    TSOL_Library_Content_Model::META_TRANSCRIPT_FILENAME,
    TSOL_Library_Content_Model::META_TRANSCRIPT_MODIFIED_AT,
    TSOL_Library_Content_Model::META_TRANSCRIPT_VERSION,
);
$item_keys = TSOL_Library_Content_Model::metadata_keys_for_post_type(TSOL_Library_Content_Model::ITEM_POST_TYPE);
foreach ($transcript_keys as $key) {
    $assert(in_array($key, $item_keys, true), sprintf('Transcript source key %s is not portable.', $key));
}
foreach (array(TSOL_Library_Content_Model::COURSE_POST_TYPE, TSOL_Library_Content_Model::SERIES_POST_TYPE) as $post_type) {
    foreach ($transcript_keys as $key) {
        $assert(!in_array($key, TSOL_Library_Content_Model::metadata_keys_for_post_type($post_type), true), sprintf('Transcript source key %s leaked onto %s.', $key, $post_type));
    }
}

$vtt = "WEBVTT\n\n00:00:00.000 --> 00:00:02.000\nContract transcript.\n";
$assert($vtt === TSOL_Library_Content_Model::sanitize_transcript_vtt($vtt), 'A valid WebVTT source was changed.');
$assert('' === TSOL_Library_Content_Model::sanitize_transcript_vtt('plain text'), 'A non-WebVTT source was accepted.');

$post_id = wp_insert_post(array(
    'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
    'post_status' => 'draft',
    'post_title' => 'Disposable transcript contract item',
), true);
if (is_wp_error($post_id)) {
    throw new RuntimeException($post_id->get_error_message());
}

$uuid = wp_generate_uuid4();
$modified_at = '2026-08-27T12:00:00.000Z';
update_post_meta($post_id, TSOL_Library_Content_Model::META_UUID, $uuid);
update_post_meta($post_id, TSOL_Library_Content_Model::META_MEDIA_ASSETS, array(array(
    'key' => 'primary',
    'provider' => 'vimeo',
    'provider_id' => '123456789',
    'url' => 'https://vimeo.com/123456789',
    'position' => 1,
)));
update_post_meta($post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_HASH, hash('sha256', $vtt));
update_post_meta($post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_LANGUAGE, 'en');
update_post_meta($post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_FILENAME, 'lesson.vtt');
update_post_meta($post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_MODIFIED_AT, $modified_at);
update_post_meta($post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_VERSION, 3);
update_post_meta($post_id, TSOL_Library_Content_Model::META_TRANSCRIPT_CONTENT, wp_slash($vtt));

$issued_at = 1787832000;
$payload = TSOL_Library_Content_Transcripts::delivery_payload($post_id, $issued_at);
$expected_keys = array('version', 'event', 'audience', 'issued_at', 'content_uuid', 'provider', 'provider_video_id', 'provider_track_id', 'provenance', 'language', 'display_language', 'name', 'kind', 'source_modified_at', 'source_version', 'content_sha256', 'vtt');
$assert($expected_keys === array_keys($payload), 'Transcript delivery payload keys or ordering changed.');
$assert($uuid === ($payload['content_uuid'] ?? ''), 'Transcript payload lost stable content identity.');
$assert('vimeo' === ($payload['provider'] ?? '') && '123456789' === ($payload['provider_video_id'] ?? ''), 'Transcript payload lost stable media identity.');
$assert(hash('sha256', $vtt) === ($payload['content_sha256'] ?? ''), 'Transcript payload hash does not cover the source.');
$assert($vtt === ($payload['vtt'] ?? ''), 'Transcript payload changed the WebVTT source.');

$secret = 'wordpress-transcript-contract-secret-1234567890';
$requests = array();
$secret_filter = static function () use ($secret) {
    return $secret;
};
$url_filter = static function () {
    return 'https://school.example.test/api/internal/transcripts/import';
};
$http_filter = static function ($preempt, $args, $url) use (&$requests) {
    unset($preempt);
    $requests[] = array('args' => $args, 'url' => $url);
    return array(
        'headers' => array(),
        'body' => '{"accepted":true,"unchanged":false,"segment_count":1,"chunk_count":1}',
        'response' => array('code' => 200, 'message' => 'OK'),
        'cookies' => array(),
        'filename' => null,
    );
};
add_filter('tsol_library_catalogue_webhook_secret', $secret_filter);
add_filter('tsol_library_transcript_endpoint_url', $url_filter);
add_filter('pre_http_request', $http_filter, 10, 3);

$assert(true === TSOL_Library_Content_Transcripts::deliver($post_id), 'A valid transcript delivery was not accepted.');
$assert(1 === count($requests), 'Transcript delivery did not make exactly one request.');
if (!empty($requests)) {
    $request = $requests[0];
    $body = (string) $request['args']['body'];
    $timestamp = (string) ($request['args']['headers']['X-TSOL-Transcript-Timestamp'] ?? '');
    $signature = (string) ($request['args']['headers']['X-TSOL-Transcript-Signature'] ?? '');
    $assert(hash_equals('sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature), 'Transcript signature does not cover the timestamp and exact body.');
    $assert(TSOL_Library_Content_Transcripts::ENDPOINT_PATH === wp_parse_url($request['url'], PHP_URL_PATH), 'Transcript delivery used the wrong endpoint.');
    $assert(0 === (int) $request['args']['redirection'], 'Transcript delivery allows redirects.');
    $assert(!empty($request['args']['sslverify']) && !empty($request['args']['blocking']), 'Transcript delivery weakened transport safety.');
}
$assert('delivered' === get_post_meta($post_id, TSOL_Library_Content_Transcripts::STATUS_META, true), 'Successful delivery was not recorded.');

remove_filter('pre_http_request', $http_filter, 10);
remove_filter('tsol_library_transcript_endpoint_url', $url_filter);
remove_filter('tsol_library_catalogue_webhook_secret', $secret_filter);
$scheduled = wp_next_scheduled(TSOL_Library_Content_Transcripts::RETRY_HOOK, array((int) $post_id));
if (false !== $scheduled) {
    wp_unschedule_event($scheduled, TSOL_Library_Content_Transcripts::RETRY_HOOK, array((int) $post_id));
}
wp_delete_post($post_id, true);

if (!empty($failures)) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error(sprintf('%d transcript contract assertion(s) failed.', count($failures)));
}
WP_CLI::success('Library transcript source and signed delivery contract passed.');
