<?php
/** Read-only contract for the additive New Marketer Workshop migration. */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(class_exists('TSOL_Library_New_Marketer_Workshop_Import'), 'The workshop importer is not loaded.');
$migration = new TSOL_Library_New_Marketer_Workshop_Import();
$preview = $migration->preview();
$verification = $migration->verify();

$assert(52 === (int) $preview['course']['lessons'], 'The locked source does not contain 52 lessons.');
$assert(7 === (int) $preview['course']['sections'], 'The workshop preview does not contain seven sections.');
$assert(28 === (int) $preview['native_memberpress_rule']['condition_count'], 'The locked legacy rule does not contain 28 conditions.');
$assert(0 === (int) $preview['native_memberpress_rule']['permission_changes'], 'The preview reports a permission change.');
$assert('applied' === (string) $verification['phase'], 'The workshop import is not applied.');
$assert(TSOL_Library_New_Marketer_Workshop_Import::EDITORIAL_VERSION === (string) $verification['editorial_version'], 'The canonical workshop editorial migration is not applied.');
$assert(TSOL_Library_New_Marketer_Workshop_Import::ARTWORK_VERSION === (string) $verification['artwork_version'], 'The canonical flat workshop artwork migration is not applied.');
$assert(TSOL_Library_New_Marketer_Workshop_Import::SPEAKER_VERSION === (string) $verification['speaker_version'], 'The canonical workshop speaker migration is not applied.');
$assert(array('courses' => 1, 'lessons' => 52, 'total' => 53) === $verification['normalized'], 'The workshop target inventory changed.');
$assert(1 === (int) $verification['native_rule_count'], 'The workshop does not own exactly one MemberPress rule.');
$assert(28 === (int) $verification['native_rule_condition_count'], 'The native workshop rule does not have exactly 28 conditions.');
$assert(0 === (int) $verification['access_matrix']['allow_to_deny'], 'The workshop migration removes legacy access.');
$assert(0 === (int) $verification['access_matrix']['deny_to_allow'], 'The workshop migration broadens legacy access.');
$assert(53 === (int) $verification['access_matrix']['targets_checked'], 'The workshop matrix did not cover the Course and all lessons.');
$assert(212 === (int) $verification['access_matrix']['runtime_decisions_checked'], 'The real entitlement path did not cover four user categories across all 53 targets.');
$assert(!empty($verification['legacy_page_unchanged']), 'The legacy workshop page changed.');
$assert(!empty($verification['legacy_rule_unchanged']), 'The legacy workshop rule changed.');
$assert(0 === (int) $verification['identities_emitted'], 'The workshop verification emitted member identities.');

$course_id = (int) $verification['authorization_post_id'];
$course = get_post($course_id);
$assert($course instanceof WP_Post && 'publish' === $course->post_status, 'The workshop Course is not published.');
$assert('The New Marketer Workshop' === (string) get_the_title($course_id), 'The workshop Course title changed.');
$course_thumbnail_id = (int) get_post_thumbnail_id($course_id);
$assert($course_thumbnail_id > 0, 'The workshop Course has no canonical thumbnail.');
$course_thumbnail_file = $course_thumbnail_id > 0 ? get_attached_file($course_thumbnail_id) : '';
$assert(is_string($course_thumbnail_file) && is_file($course_thumbnail_file) && TSOL_Library_New_Marketer_Workshop_Import::THUMBNAIL_SOURCE_SHA256 === hash_file('sha256', $course_thumbnail_file), 'The workshop Course does not use the pinned 16:9 video thumbnail.');
$assert(empty(wp_get_object_terms($course_id, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, array('fields' => 'ids'))), 'The workshop was incorrectly classified as a Masterclass or another Collection.');

$speaker_id = (int) ($verification['speaker_id'] ?? 0);
$speaker = get_post($speaker_id);
$assert($speaker instanceof WP_Post && 'Charles Terrence Harper' === $speaker->post_title, 'The workshop Charles Terrence Harper speaker profile is missing.');
$assert(array($speaker_id) === TSOL_Library_Content_Model::direct_speaker_ids($course_id), 'The workshop Course is not assigned only to Charles Terrence Harper.');
$assert('Creator and Technical Trainer' === (string) get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_JOB_TITLE, true), 'The workshop speaker job title changed.');
$assert('The PLR Show / GainMindshare' === (string) get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_ORGANIZATION, true), 'The workshop speaker organisation changed.');
$speaker_headshot_id = (int) get_post_thumbnail_id($speaker_id);
$speaker_headshot_file = $speaker_headshot_id > 0 ? get_attached_file($speaker_headshot_id) : '';
$assert(is_string($speaker_headshot_file) && is_file($speaker_headshot_file) && TSOL_Library_New_Marketer_Workshop_Import::SPEAKER_HEADSHOT_SOURCE_SHA256 === hash_file('sha256', $speaker_headshot_file), 'The workshop speaker does not use the pinned first-party Charles Harper headshot.');

$expected_sections = array(
    'Goals, Offers & Your Market' => 4,
    'Build Your Marketing Platform' => 8,
    'Offers, Content & Monetization' => 7,
    'Community, Affiliates & Authority' => 5,
    'Product & Marketing Systems' => 7,
    'Audience, Traffic & Brand Growth' => 10,
    'Scale, Automate & Put It Into Practice' => 11,
);
$registry = TSOL_Library_Content_Model::sanitize_structure_registry(
    get_post_meta($course_id, TSOL_Library_Content_Model::META_COURSE_SECTIONS, true)
);
$assert(array_keys($expected_sections) === array_map(static function ($section) {
    return (string) $section['title'];
}, $registry), 'The workshop section order or titles changed.');

$lesson_ids = get_posts(array(
    'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
    'post_status' => 'publish',
    'posts_per_page' => -1,
    'fields' => 'ids',
    'suppress_filters' => true,
    'meta_query' => array(
        'relation' => 'AND',
        array('key' => TSOL_Library_New_Marketer_Workshop_Import::META_IMPORT_VERSION, 'value' => TSOL_Library_New_Marketer_Workshop_Import::VERSION),
        array('key' => TSOL_Library_Content_Model::META_COURSE_ID, 'value' => $course_id, 'type' => 'NUMERIC'),
    ),
));
$assert(52 === count($lesson_ids), 'The published workshop curriculum does not contain 52 lessons.');
$lesson_slugs = array();
$actual_sections = array_fill_keys(array_keys($expected_sections), array());
foreach (array_map('intval', $lesson_ids) as $lesson_id) {
    $assert($course_id === (int) get_post_meta($lesson_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true), 'A workshop lesson does not delegate to the Course rule.');
    $assets = get_post_meta($lesson_id, TSOL_Library_Content_Model::META_MEDIA_ASSETS, true);
    $assert(is_array($assets) && 1 === count($assets), 'A workshop lesson does not have exactly one media asset.');
    $lesson = get_post($lesson_id);
    $assert($lesson instanceof WP_Post && sanitize_title($lesson->post_title) === (string) $lesson->post_name, 'A workshop lesson slug does not match its canonical title.');
    $lesson_slugs[] = (string) $lesson->post_name;
    $section_title = (string) get_post_meta($lesson_id, TSOL_Library_Content_Model::META_SECTION_TITLE, true);
    if (!array_key_exists($section_title, $actual_sections)) {
        $failures[] = 'A workshop lesson belongs to an unexpected section.';
    } else {
        $actual_sections[$section_title][] = (int) get_post_meta($lesson_id, TSOL_Library_Content_Model::META_POSITION, true);
    }
}
$assert(52 === count(array_unique($lesson_slugs)), 'The workshop canonical lesson slugs are not unique.');
foreach ($expected_sections as $section_title => $expected_count) {
    $positions = $actual_sections[$section_title];
    sort($positions, SORT_NUMERIC);
    $assert($expected_count === count($positions), sprintf('Workshop section %s has the wrong lesson count.', $section_title));
    $assert(range(1, $expected_count) === $positions, sprintf('Workshop section %s does not have contiguous lesson positions.', $section_title));
}

$owned_rule_ids = get_posts(array(
    'post_type' => MeprRule::$cpt,
    'post_status' => array_values(get_post_stati()),
    'posts_per_page' => -1,
    'fields' => 'ids',
    'suppress_filters' => true,
    'meta_key' => TSOL_Library_Access_Rules_Migration::META_VERSION,
    'meta_value' => TSOL_Library_New_Marketer_Workshop_Import::VERSION,
));
$assert(1 === count($owned_rule_ids), 'The workshop importer does not own exactly one rule.');
if (1 === count($owned_rule_ids)) {
    $rule = new MeprRule((int) $owned_rule_ids[0]);
    $assert('publish' === get_post_status((int) $owned_rule_ids[0]), 'The workshop native rule is not published.');
    $assert('single_' . TSOL_Library_Content_Model::COURSE_POST_TYPE === (string) $rule->mepr_type, 'The workshop rule does not target one TSOL Course.');
    $assert((string) $course_id === (string) $rule->mepr_content, 'The workshop rule targets the wrong Course.');
    $assert(28 === count($rule->access_conditions()), 'The workshop rule condition inventory changed.');
}

$assert(!metadata_exists('post', TSOL_Library_New_Marketer_Workshop_Import::SOURCE_POST_ID, TSOL_Library_New_Marketer_Workshop_Import::META_IMPORT_VERSION), 'The legacy source page received migration ownership metadata.');
$assert(!metadata_exists('post', TSOL_Library_New_Marketer_Workshop_Import::SOURCE_POST_ID, TSOL_Library_Content_Model::META_MIGRATION_KEY), 'The legacy source page received normalized catalogue metadata.');

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}

WP_CLI::line(wp_json_encode(array(
    'scope' => 'tsol-library-new-marketer-workshop',
    'phase' => $verification['phase'],
    'courses' => $verification['normalized']['courses'],
    'lessons' => $verification['normalized']['lessons'],
    'native_rules' => $verification['native_rule_count'],
    'native_conditions' => $verification['native_rule_condition_count'],
    'users_checked' => $verification['access_matrix']['users_checked'],
    'decisions_checked' => $verification['access_matrix']['decisions_checked'],
    'allow_to_deny' => $verification['access_matrix']['allow_to_deny'],
    'deny_to_allow' => $verification['access_matrix']['deny_to_allow'],
    'legacy_mutations' => 0,
    'identities_emitted' => 0,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
WP_CLI::success('New Marketer Workshop import and exact legacy access equivalence passed.');
