<?php
/**
 * TSOL-owned Library model and pristine MemberPress Courses boundary contract.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

global $wpdb;
$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(TSOL_Library_Content_Model::post_types() === array(
    TSOL_Library_Content_Model::COURSE_POST_TYPE,
    TSOL_Library_Content_Model::SERIES_POST_TYPE,
    TSOL_Library_Content_Model::ITEM_POST_TYPE,
), 'The Library model includes a non-TSOL post type.');
$assert(!TSOL_Library_Content_Admin::supports_post_type('mpcs-course'), 'TSOL editor still supports MemberPress Courses.');
$assert(!TSOL_Library_Content_Admin::supports_post_type('mpcs-lesson'), 'TSOL editor still supports MemberPress Lessons.');

foreach (TSOL_Library_Content_Model::post_types() as $post_type) {
    $object = get_post_type_object($post_type);
    $assert($object instanceof WP_Post_Type, sprintf('%s is not registered.', $post_type));
    if ($object instanceof WP_Post_Type) {
        $assert(false === $object->public, sprintf('%s unexpectedly has a WordPress frontend.', $post_type));
        $assert(false === $object->publicly_queryable, sprintf('%s is publicly queryable.', $post_type));
        $assert(true === $object->show_ui, sprintf('%s is missing its admin UI.', $post_type));
        $assert(false === $object->show_in_rest, sprintf('%s uses the generic REST API.', $post_type));
    }
    $meta = get_registered_meta_keys('post', $post_type);
    foreach (TSOL_Library_Content_Model::metadata_keys_for_post_type($post_type) as $key) {
        $assert(isset($meta[$key]), sprintf('%s is not registered for %s.', $key, $post_type));
    }
    if (TSOL_Library_Content_Model::COURSE_POST_TYPE !== $post_type) {
        $assert(!isset($meta[TSOL_Library_Content_Model::META_COURSE_SECTIONS]), sprintf('Course section registry is registered for %s.', $post_type));
        $assert(!isset($meta[TSOL_Library_Content_Model::META_COURSE_LEARNING_OUTCOMES]), sprintf('Course learning outcomes are registered for %s.', $post_type));
    }
    if (TSOL_Library_Content_Model::SERIES_POST_TYPE !== $post_type) {
        $assert(!isset($meta[TSOL_Library_Content_Model::META_SERIES_GROUPS]), sprintf('Series group registry is registered for %s.', $post_type));
    }
    if (TSOL_Library_Content_Model::ITEM_POST_TYPE !== $post_type) {
        $assert(!isset($meta[TSOL_Library_Content_Model::META_AVAILABILITY]), sprintf('Content availability is registered for %s.', $post_type));
        $assert(!isset($meta[TSOL_Library_Content_Model::META_RELEASE_AT_GMT]), sprintf('Content release time is registered for %s.', $post_type));
    }
}

foreach (array('mpcs-course', 'mpcs-lesson') as $legacy_type) {
    $registered = get_registered_meta_keys('post', $legacy_type);
    foreach (TSOL_Library_Content_Model::metadata_keys() as $key) {
        $assert(!isset($registered[$key]), sprintf('TSOL meta %s is still registered on %s.', $key, $legacy_type));
    }
}

foreach (array(
    TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY,
    TSOL_Library_Content_Model::TOPIC_TAXONOMY,
) as $taxonomy_name) {
    $taxonomy = get_taxonomy($taxonomy_name);
    $assert($taxonomy instanceof WP_Taxonomy, sprintf('%s is not registered.', $taxonomy_name));
    if ($taxonomy instanceof WP_Taxonomy) {
        $assert(!in_array('mpcs-course', $taxonomy->object_type, true), sprintf('%s is attached to MemberPress Courses.', $taxonomy_name));
        $assert(!in_array('mpcs-lesson', $taxonomy->object_type, true), sprintf('%s is attached to MemberPress Lessons.', $taxonomy_name));
    }
}
$assert(!taxonomy_exists('tsol_speaker'), 'The retired Speaker taxonomy is still registered.');
$speaker_type = get_post_type_object(TSOL_Library_Content_Model::SPEAKER_POST_TYPE);
$assert($speaker_type instanceof WP_Post_Type, 'The private Library Speaker profile type is not registered.');
if ($speaker_type instanceof WP_Post_Type) {
    $assert(false === $speaker_type->public && false === $speaker_type->publicly_queryable, 'Speaker profiles unexpectedly have a WordPress frontend.');
    $assert(true === $speaker_type->show_ui && false === $speaker_type->show_in_rest, 'Speaker profiles do not have the required private wp-admin model.');
}

$rule_types = array_keys(MeprRule::get_types());
foreach (array(
    'all_' . TSOL_Library_Content_Model::COURSE_POST_TYPE,
    'single_' . TSOL_Library_Content_Model::COURSE_POST_TYPE,
    'all_' . TSOL_Library_Content_Model::SERIES_POST_TYPE,
    'single_' . TSOL_Library_Content_Model::SERIES_POST_TYPE,
    'all_' . TSOL_Library_Content_Model::ITEM_POST_TYPE,
    'single_' . TSOL_Library_Content_Model::ITEM_POST_TYPE,
) as $rule_type) {
    $assert(in_array($rule_type, $rule_types, true), sprintf('MemberPress rule type %s is missing.', $rule_type));
}
$course_collection_rule_type = 'tax_' . TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY . '||cpt_' . TSOL_Library_Content_Model::COURSE_POST_TYPE;
$assert(in_array($course_collection_rule_type, $rule_types, true), 'MemberPress cannot target a Collection.');
$assert(!in_array('all_' . TSOL_Library_Content_Model::SPEAKER_POST_TYPE, $rule_types, true), 'MemberPress exposes all Speaker profiles as a rule target.');
$assert(!in_array('single_' . TSOL_Library_Content_Model::SPEAKER_POST_TYPE, $rule_types, true), 'MemberPress exposes individual Speaker profiles as rule targets.');
$assert(!is_object_in_taxonomy(TSOL_Library_Content_Model::ITEM_POST_TYPE, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY), 'Collections are attached to Library Content items.');
$assert(!is_object_in_taxonomy(TSOL_Library_Content_Model::SERIES_POST_TYPE, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY), 'Collections are attached to Series.');

$assert(false === has_filter('views_edit-mpcs-course', array('TSOL_Library_Content_Legacy_Sources', 'filter_course_views')), 'TSOL still filters MemberPress Course views.');
$assert(false === has_filter('use_block_editor_for_post', array('TSOL_Library_Content_Admin', 'use_memberpress_course_editor')), 'TSOL still forces the MemberPress Course editor.');

$legacy_courses = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'mpcs-course' AND post_status = 'publish'");
$legacy_lessons = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'mpcs-lesson'");
$legacy_tsol_meta = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type IN ('mpcs-course','mpcs-lesson') AND pm.meta_key LIKE '_tsol_library_%'"
);
$assert(124 === $legacy_courses, 'The legacy MemberPress Course count changed.');
$assert(0 === $legacy_lessons, 'Unexpected MemberPress Lessons exist.');
$assert(0 === $legacy_tsol_meta, 'TSOL metadata exists on legacy MemberPress records.');

$db = new \memberpress\courses\lib\Db();
$tsol_sections = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$db->sections} WHERE uuid LIKE 'tsol-%'");
$assert(0 === $tsol_sections, 'TSOL-owned MemberPress section rows still exist.');

$course_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tsol_library_course' AND post_status NOT IN ('trash','auto-draft')");
$series_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tsol_library_series' AND post_status NOT IN ('trash','auto-draft')");
$content_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tsol_library_item' AND post_status NOT IN ('trash','auto-draft')");
$series_episode_count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id=p.ID AND pm.meta_key=%s WHERE p.post_type=%s AND p.post_status NOT IN ('trash','auto-draft') AND CAST(pm.meta_value AS UNSIGNED)>0",
    TSOL_Library_Content_Model::META_SERIES_ID,
    TSOL_Library_Content_Model::ITEM_POST_TYPE
));
$access_verification = (new TSOL_Library_Access_Rules_Migration())->verify();
$assert(6 <= $course_count, 'The locked six-Course baseline is missing.');
$assert(6 <= $series_count, 'The locked six-Series baseline is missing.');
$assert(142 <= $content_count, 'The locked 142-item content baseline is missing.');
$assert(121 <= $series_episode_count, 'The locked 121-episode Series baseline is missing.');
$expected_authorization_mode = 'activated' === $access_verification['phase'] ? 'tsol_native' : 'legacy_delegation';
$assert($expected_authorization_mode === $access_verification['authorization_mode'], 'The modernization authorization mode is inconsistent with its phase.');
$assert(0 === (int) $access_verification['matrix']['allow_to_deny'], 'The proposed native policy removes member access.');

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}

WP_CLI::line(wp_json_encode(array(
    'legacy_memberpress_courses' => $legacy_courses,
    'legacy_memberpress_lessons' => $legacy_lessons,
    'legacy_tsol_meta_rows' => $legacy_tsol_meta,
    'memberpress_section_rows_owned_by_tsol' => $tsol_sections,
    'tsol_courses' => $course_count,
    'tsol_series' => $series_count,
    'tsol_content' => $content_count,
    'tsol_series_episodes' => $series_episode_count,
    'authorization_mode' => $access_verification['authorization_mode'],
    'modern_access_allow_to_deny' => $access_verification['matrix']['allow_to_deny'],
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
WP_CLI::success('TSOL-owned Library model is isolated from the legacy MemberPress Courses system.');
