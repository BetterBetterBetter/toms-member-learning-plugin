<?php
/**
 * Protected catalogue, incremental cursor, and batch-access contract.
 *
 * Run: php -d memory_limit=512M /usr/local/bin/wp eval-file
 * tests/library-content-catalogue-contract.php --skip-themes
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

$assert(class_exists('TSOL_Library_Content_Catalogue'), 'Catalogue serializer is not loaded.');
$assert(class_exists('TSOL_Library_Content_Changes'), 'Catalogue change cursor is not loaded.');

global $wpdb;
$changes_table = TSOL_Library_Content_Changes::table();
$assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $changes_table)) === $changes_table, 'Catalogue change table is missing.');

$routes = rest_get_server()->get_routes();
foreach (array(
    '/tsol-library/v1/catalogue',
    '/tsol-library/v1/catalogue/(?P<post_id>\d+)',
    '/tsol-library/v1/changes',
    '/tsol-library/v1/content-access/(?P<user_id>\d+)',
) as $route) {
    $assert(isset($routes[$route]), 'Missing protected catalogue route: ' . $route);
}

$all = array();
$after_id = 0;
$snapshot_cursor = null;
do {
    $page = TSOL_Library_Content_Catalogue::snapshot($after_id, 37);
    $assert($page['schema_version'] === TSOL_Library_Content_Catalogue::SCHEMA_VERSION, 'Snapshot schema version changed.');
    $assert(is_string($page['snapshot_cursor']) && ctype_digit($page['snapshot_cursor']), 'Snapshot cursor is not an unsigned integer string.');
    if (null === $snapshot_cursor) {
        $snapshot_cursor = $page['snapshot_cursor'];
    }
    $all = array_merge($all, $page['items']);
    $after_id = $page['next_after_id'];
} while ($page['has_more']);

$ids = array_map('intval', array_column($all, 'wordpress_id'));
$assert(count($all) === 207, 'Complete catalogue did not contain 207 normalized records.');
$assert(count(array_unique($ids)) === 207, 'Complete catalogue contained duplicate WordPress IDs.');
$assert($ids === array_values(array_unique($ids)), 'Catalogue pagination was not deterministic.');
$type_counts = array_count_values(array_column($all, 'record_type'));
$assert(($type_counts['course'] ?? 0) === 7, 'Catalogue did not contain seven courses.');
$assert(($type_counts['series'] ?? 0) === 6, 'Catalogue did not contain the six locked Series.');
$assert(($type_counts['lesson'] ?? 0) === 73, 'Catalogue did not contain 73 lessons.');
$assert(($type_counts['item'] ?? 0) === 121, 'Catalogue did not contain 121 playable content items.');
$assert(array_sum(array_map(static function ($item) { return count($item['media']); }, $all)) === 199, 'Catalogue did not contain 199 playable media records.');
$assert(array_sum(array_map(static function ($item) { return count($item['resources']); }, $all)) >= 30, 'Catalogue lost one or more of the 30 locked imported resources.');
$assert(array_sum(array_map(static function ($item) { return 'course' === $item['record_type'] ? count($item['course']['sections']) : 0; }, $all)) === 8, 'Catalogue did not contain eight course sections.');
$series_records = array_values(array_filter($all, static function ($item) { return 'series' === $item['record_type']; }));
$assert(6 === count($series_records), 'Catalogue did not emit exactly six Series records.');
$series_expected = array(
    'Sessions' => array('sessions', 'desc', true, array('year-2022' => 28, 'year-2023' => 26, 'year-2024' => 21, 'year-2025' => 18, 'year-2026' => 3)),
    'Live Events' => array('talks', 'desc', true, array('year-2022' => 6, 'year-2023' => 10, 'year-2024' => 2)),
    'Unconference 2025' => array('sessions', 'asc', false, array('event' => 3)),
    'New Member Orientation' => array('versions', 'desc', true, array('versions' => 2)),
    'Limitless Book Club' => array('sessions', 'desc', true, array('sessions' => 1)),
    'Member Calls' => array('calls', 'desc', true, array('calls' => 1)),
);
foreach ($series_records as $series_record) {
    $title = (string) $series_record['title'];
    $series = $series_record['series'];
    $expected = $series_expected[$title] ?? null;
    $assert(is_array($expected), 'Catalogue emitted an unexpected Series: ' . $title);
    if (!is_array($expected)) {
        continue;
    }
    $assert($expected[0] === (string) $series['item_label_plural'], $title . ' item labels were not projected.');
    $assert($expected[1] === (string) $series['sort'], $title . ' ordering was not projected.');
    $assert($expected[2] === (bool) $series['ongoing'], $title . ' ongoing state was not projected.');
    $actual_groups = array();
    foreach ($series['groups'] as $group) {
        $actual_groups[(string) $group['key']] = count($group['item_ids']);
    }
    $assert($expected[3] === $actual_groups, $title . ' groups do not contain the locked episode counts.');
}
$episode_records = array_values(array_filter($all, static function ($item) { return 'item' === $item['record_type'] && null !== $item['series']; }));
$assert(121 === count($episode_records), 'Catalogue did not project all 121 Series episodes.');
$standalone_records = array_values(array_filter($all, static function ($item) { return 'item' === $item['record_type'] && null === $item['series'] && null === $item['course']; }));
$assert(0 === count($standalone_records), 'Catalogue still projects a standalone playable item.');
$masterclass_courses = array_values(array_filter($all, static function ($item) {
    if ('course' !== $item['record_type']) {
        return false;
    }
    return in_array('masterclasses', array_column($item['course_collections'], 'slug'), true);
}));
$assert(5 === count($masterclass_courses), 'Catalogue did not classify exactly five Courses as Masterclasses.');
$masterclass_titles = array_column($masterclass_courses, 'title');
sort($masterclass_titles, SORT_STRING);
$expected_masterclass_titles = array('Against the Machine', 'Social Media', 'Tax Strategy Intensive', 'The $100 Medicine Cabinet', 'The AI Advantage');
sort($expected_masterclass_titles, SORT_STRING);
$assert($expected_masterclass_titles === $masterclass_titles, 'Catalogue retained redundant or unexpected Masterclass Course titles.');
$ordinary_courses = array_values(array_filter($all, static function ($item) {
    return 'course' === $item['record_type'] && empty($item['course_collections']);
}));
$ordinary_titles = array_column($ordinary_courses, 'title');
sort($ordinary_titles, SORT_STRING);
$assert(array('Freedom OS', 'The New Marketer Workshop') === $ordinary_titles, 'The two ordinary Courses were not preserved outside Collections.');
$new_marketer_courses = array_values(array_filter($all, static function ($item) {
    return 'course' === $item['record_type'] && 'The New Marketer Workshop' === (string) $item['title'];
}));
$assert(1 === count($new_marketer_courses), 'The New Marketer Workshop Course is missing from the catalogue.');
if (1 === count($new_marketer_courses)) {
    $new_marketer_course = $new_marketer_courses[0];
    $new_marketer_lessons = array_values(array_filter($all, static function ($item) use ($new_marketer_course) {
        return 'lesson' === $item['record_type']
            && (int) ($item['course']['course_id'] ?? 0) === (int) $new_marketer_course['wordpress_id'];
    }));
    $assert(52 === count($new_marketer_lessons), 'The New Marketer Workshop did not project all 52 lessons.');
    $assert(1 === count($new_marketer_course['course']['sections']), 'The New Marketer Workshop did not project its flat Lessons section.');
}
$assert(count(array_filter($all, static function ($item) { return array_key_exists('collections', $item); })) === 0, 'Retired mixed-content Collections remain in the catalogue contract.');
$assert(count(array_filter($all, static function ($item) { return (int) $item['authorization_post_id'] <= 0; })) === 0, 'A catalogue record omitted its authorization source.');
$assert(count(array_filter($all, static function ($item) { return (string) $item['migration_key'] === ''; })) === 0, 'A catalogue record omitted its stable migration key.');
$assert(count(array_filter($all, static function ($item) { return preg_match('~https?://~i', (string) $item['excerpt']); })) === 0, 'Catalogue browse metadata exposed a protected asset URL through an excerpt.');
$assert(count(array_filter($all, static function ($item) { return !array_key_exists('overview_html', $item); })) === 0, 'Catalogue record omitted its automatic Description field.');
foreach ($all as $item) {
    $overview_html = isset($item['overview_html']) ? (string) $item['overview_html'] : '';
    $assert(false === stripos($overview_html, '<script'), 'Unsafe script markup survived in an automatic Library Description.');
    $assert(false === stripos($overview_html, '<iframe'), 'Legacy player markup survived in an automatic Library Description.');
    $assert(!preg_match('~<(p|div|figure|span|a)(?:\s[^>]*)?>(?:(?:\s|&nbsp;|&#160;|&#x0*a0;|\xC2\xA0)|<br\s*/?>|<!--.*?-->)*</\1\s*>~is', $overview_html), 'A visually empty block survived in an automatic Library Description.');
}
$assert(count(array_filter($all, static function ($item) { return !array_key_exists('speaker_source', $item); })) === 0, 'Catalogue record omitted its effective Speaker source.');
$assert(count(array_filter($all, static function ($item) {
    return !in_array((string) ($item['speaker_source'] ?? ''), array('direct', 'course', 'series', 'none'), true);
})) === 0, 'Catalogue emitted an unknown effective Speaker source.');
$assert(count(array_filter($all, static function ($item) {
    return 'lesson' === (string) $item['record_type'] && 'course' !== (string) ($item['speaker_source'] ?? '');
})) === 0, 'An imported Course lesson did not inherit its Course Speakers.');
$assert(count(array_filter($all, static function ($item) {
    return 'item' === (string) $item['record_type'] && null !== $item['series'] && 'series' !== (string) ($item['speaker_source'] ?? '');
})) === 0, 'An imported Series item did not inherit its Series Speakers.');

$ai_course_records = array_values(array_filter($all, static function ($item) {
    return 'course' === (string) $item['record_type'] && 'The AI Advantage' === (string) $item['title'];
}));
$assert(1 === count($ai_course_records), 'The Speaker inheritance contract could not identify The AI Advantage Course.');
if (1 === count($ai_course_records)) {
    $ai_course = $ai_course_records[0];
    $ai_course_speaker_ids = array_map('intval', array_column($ai_course['speakers'], 'wordpress_id'));
    $assert('direct' === (string) ($ai_course['speaker_source'] ?? ''), 'The AI Advantage Course did not retain its direct Speaker source.');
    $assert(!empty($ai_course_speaker_ids), 'The AI Advantage Course has no published Speaker to exercise inheritance.');
    $ai_lessons = array_values(array_filter($all, static function ($item) use ($ai_course) {
        return 'lesson' === (string) $item['record_type']
            && (int) ($item['course']['course_id'] ?? 0) === (int) $ai_course['wordpress_id'];
    }));
    $assert(!empty($ai_lessons), 'The AI Advantage Course has no lessons to exercise Speaker inheritance.');
    foreach ($ai_lessons as $ai_lesson) {
        $assert('course' === (string) ($ai_lesson['speaker_source'] ?? ''), 'An AI Advantage lesson did not identify its Course as the Speaker source.');
        $assert(
            $ai_course_speaker_ids === array_map('intval', array_column($ai_lesson['speakers'], 'wordpress_id')),
            'An AI Advantage lesson did not project the parent Course Speaker order.'
        );
    }
}

$encoded = wp_json_encode($all);
foreach (array('membership_ids', 'memberpress_rules', 'client_secret', 'legacy_source_post_id') as $forbidden) {
    $assert(strpos($encoded, $forbidden) === false, 'Catalogue payload exposed forbidden authority field: ' . $forbidden);
}

if (TSOL_Library_Auth_Settings::configured()) {
    $request = new WP_REST_Request('GET', '/tsol-library/v1/catalogue');
    $request->set_header('x-tsol-client-id', TSOL_Library_Auth_Settings::client_id());
    $request->set_header('x-tsol-client-secret', TSOL_Library_Auth_Settings::client_secret());
    $request->set_param('per_page', 25);
    $response = rest_do_request($request);
    $data = $response->get_data();
    $headers = array_change_key_case($response->get_headers(), CASE_LOWER);
    $assert($response->get_status() === 200, 'Valid server credentials could not fetch the catalogue.');
    $assert(isset($data['items']) && count($data['items']) === 25, 'Protected catalogue pagination ignored per_page.');
    $assert(isset($headers['cache-control']) && strpos($headers['cache-control'], 'no-store') !== false, 'Catalogue response was not marked no-store.');

    $browser_request = new WP_REST_Request('GET', '/tsol-library/v1/catalogue');
    $browser_request->set_header('origin', home_url('/'));
    $browser_request->set_header('x-tsol-client-id', TSOL_Library_Auth_Settings::client_id());
    $browser_request->set_header('x-tsol-client-secret', TSOL_Library_Auth_Settings::client_secret());
    $assert(rest_do_request($browser_request)->get_status() === 403, 'Catalogue accepted a browser-origin request.');

    $invalid_request = new WP_REST_Request('GET', '/tsol-library/v1/catalogue');
    $invalid_request->set_header('x-tsol-client-id', TSOL_Library_Auth_Settings::client_id());
    $invalid_request->set_header('x-tsol-client-secret', 'invalid-secret-value-that-is-never-valid');
    $assert(rest_do_request($invalid_request)->get_status() === 401, 'Catalogue accepted an invalid client secret.');

    $admin_ids = get_users(array('role' => 'administrator', 'fields' => 'ID', 'number' => 1));
    $batch_request = new WP_REST_Request('POST', '/tsol-library/v1/content-access/' . (int) $admin_ids[0]);
    $batch_request->set_header('content-type', 'application/json');
    $batch_request->set_header('x-tsol-client-id', TSOL_Library_Auth_Settings::client_id());
    $batch_request->set_header('x-tsol-client-secret', TSOL_Library_Auth_Settings::client_secret());
    $batch_request->set_body(wp_json_encode(array('post_ids' => array_slice($ids, 0, 4))));
    $batch_response = rest_do_request($batch_request);
    $batch_data = $batch_response->get_data();
    $assert($batch_response->get_status() === 200, 'Batch content access rejected valid server credentials.');
    $assert(isset($batch_data['items']) && count($batch_data['items']) === 4, 'Batch content access did not classify every requested post.');
    $assert(count(array_filter($batch_data['items'], static function ($item) { return !empty($item['can_access']); })) === 4, 'WordPress administrator was denied in a batch content decision.');

    $oversized = new WP_REST_Request('POST', '/tsol-library/v1/content-access/' . (int) $admin_ids[0]);
    $oversized->set_header('content-type', 'application/json');
    $oversized->set_header('x-tsol-client-id', TSOL_Library_Auth_Settings::client_id());
    $oversized->set_header('x-tsol-client-secret', TSOL_Library_Auth_Settings::client_secret());
    $oversized->set_body(wp_json_encode(array('post_ids' => range(1, 201))));
    $assert(rest_do_request($oversized)->get_status() === 400, 'Batch content access accepted more than 200 IDs.');
} else {
    $failures[] = 'Bridge is not configured, so protected endpoint checks could not run.';
}

$cursor_before = TSOL_Library_Content_Changes::current_cursor();
$fixture_id = wp_insert_post(array(
    'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
    'post_status' => 'draft',
    'post_title' => 'TSOL catalogue contract fixture',
    'post_name' => 'tsol-catalogue-contract-fixture',
    'post_content' => 'Protected body must not become browse metadata. Password: do-not-export. https://player.vimeo.com/video/123456',
), true);
$assert(!is_wp_error($fixture_id), 'Could not create the disposable catalogue fixture.');

if (!is_wp_error($fixture_id)) {
    $fixture_id = (int) $fixture_id;
    update_post_meta($fixture_id, TSOL_Library_Content_Model::META_CONTENT_TYPE, 'video');
    update_post_meta($fixture_id, TSOL_Library_Content_Model::META_MIGRATION_KEY, 'catalogue-contract-fixture');
    update_post_meta($fixture_id, TSOL_Library_Content_Model::META_MIGRATION_VERSION, 'contract');
    update_post_meta($fixture_id, TSOL_Library_Content_Model::META_UUID, wp_generate_uuid4());
    update_post_meta($fixture_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, $fixture_id);
    $assert(!metadata_exists('post', $fixture_id, TSOL_Library_Content_Model::META_INCLUDE), 'The automatic Library-CPT fixture unexpectedly received the retired inclusion flag.');
    do_action('tsol_library_content_changed', $fixture_id);

    $upserts = TSOL_Library_Content_Catalogue::changes($cursor_before, 100);
    $fixture_upserts = array_values(array_filter($upserts['changes'], static function ($change) use ($fixture_id) {
        return (int) $change['post_id'] === $fixture_id && $change['action'] === 'upsert' && is_array($change['item']);
    }));
    $assert(count(array_filter($upserts['changes'], static function ($change) use ($fixture_id) {
        return (int) $change['post_id'] === $fixture_id && $change['action'] === 'upsert' && is_array($change['item']);
    })) > 0, 'Incremental cursor did not emit the fixture upsert.');
    $assert(!empty($fixture_upserts) && $fixture_upserts[0]['item']['excerpt'] === '', 'Protected body content leaked into catalogue browse metadata.');
    $assert(!empty($fixture_upserts) && false !== strpos((string) $fixture_upserts[0]['item']['overview_html'], 'Protected body must not become browse metadata.'), 'Main editor content was not automatically projected into the protected Library Description.');
    $assert(!empty($fixture_upserts) && 'draft' === $fixture_upserts[0]['item']['status'], 'The protected projection did not retain draft status for administrator preview.');
    $assert(!empty($fixture_upserts) && $fixture_upserts[0]['item']['media'] === array(), 'Missing media metadata emitted a phantom asset.');
    $assert(!empty($fixture_upserts) && $fixture_upserts[0]['item']['resources'] === array(), 'Missing resource metadata emitted a phantom resource.');
    $assert(!empty($fixture_upserts) && 'none' === $fixture_upserts[0]['item']['speaker_source'], 'Standalone Content did not default to no effective Speaker source.');
    $assert(!empty($fixture_upserts) && array() === $fixture_upserts[0]['item']['speakers'], 'Standalone Content emitted phantom effective Speakers.');

    $cursor_before_text_update = TSOL_Library_Content_Changes::current_cursor();
    wp_update_post(array(
        'ID' => $fixture_id,
        'post_excerpt' => 'Automatic Library excerpt.',
        'post_content' => '<p>&nbsp;</p><iframe src="https://player.example.test/video"></iframe><script>alert("no")</script><p>An automatic <strong>Library Description</strong>.</p><div><p><br /></p></div>[private_embed]secret[/private_embed]',
    ));
    $description_record = TSOL_Library_Content_Catalogue::record($fixture_id);
    $description_html = (string) $description_record['overview_html'];
    $assert('Automatic Library excerpt.' === (string) $description_record['excerpt'], 'WordPress Excerpt was not projected automatically into catalogue browse metadata.');
    $assert(false !== strpos($description_html, '<strong>Library Description</strong>'), 'Automatic editor formatting was not projected into the Description.');
    $assert(false === strpos($description_html, '<script') && false === strpos($description_html, 'alert("no")'), 'Unsafe script markup or content survived Description sanitization.');
    $assert(false === strpos($description_html, '<iframe'), 'A legacy player survived Description sanitization.');
    $assert(false === strpos($description_html, 'private_embed') && false === strpos($description_html, 'secret'), 'A legacy shortcode survived Description sanitization.');
    $assert(false === strpos($description_html, '&nbsp;') && false === strpos($description_html, '<br'), 'Empty legacy spacing survived Description sanitization.');

    $text_updates = TSOL_Library_Content_Catalogue::changes($cursor_before_text_update, 100);
    $fixture_text_updates = array_values(array_filter($text_updates['changes'], static function ($change) use ($fixture_id) {
        return (int) $change['post_id'] === $fixture_id
            && 'upsert' === $change['action']
            && is_array($change['item']);
    }));
    $assert(!empty($fixture_text_updates), 'Saving WordPress text content did not emit an incremental catalogue upsert.');
    $assert(!empty($fixture_text_updates) && 'Automatic Library excerpt.' === (string) $fixture_text_updates[0]['item']['excerpt'], 'Incremental catalogue upsert did not carry the updated WordPress Excerpt.');
    $assert(!empty($fixture_text_updates) && false !== strpos((string) $fixture_text_updates[0]['item']['overview_html'], '<strong>Library Description</strong>'), 'Incremental catalogue upsert did not carry the updated WordPress Description.');

    $cursor_after_upsert = (int) $upserts['next_cursor'];
    wp_trash_post($fixture_id);
    $deletes = TSOL_Library_Content_Catalogue::changes($cursor_after_upsert, 100);
    $assert(count(array_filter($deletes['changes'], static function ($change) use ($fixture_id) {
        return (int) $change['post_id'] === $fixture_id && $change['action'] === 'delete' && null === $change['item'];
    })) > 0, 'Incremental cursor did not emit the fixture tombstone.');

    wp_delete_post($fixture_id, true);
    $wpdb->delete($changes_table, array('post_id' => $fixture_id), array('%d'));
}

if (!empty($failures)) {
    WP_CLI::error(implode("\n", $failures));
}

WP_CLI::success('TSOL Library protected catalogue contract checks passed.');
