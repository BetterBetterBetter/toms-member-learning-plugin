<?php
/** Contract for staged or activated TSOL-native rules and legacy isolation. */

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

$migration = new TSOL_Library_Access_Rules_Migration();
$status = $migration->status();
$verification = $migration->verify();
$expected_user_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}");

$owned_ids = array_map('intval', get_posts(array(
    'post_type' => 'memberpressrule',
    'post_status' => array_values(get_post_stati()),
    'posts_per_page' => -1,
    'fields' => 'ids',
    'suppress_filters' => true,
    'meta_key' => TSOL_Library_Access_Rules_Migration::META_VERSION,
    'meta_value' => TSOL_Library_Access_Rules_Migration::VERSION,
)));
$published_legacy_ids = array_map('intval', get_posts(array(
    'post_type' => 'memberpressrule',
    'post_status' => 'publish',
    'posts_per_page' => -1,
    'fields' => 'ids',
    'suppress_filters' => true,
    'meta_query' => array(array(
        'key' => TSOL_Library_Access_Rules_Migration::META_VERSION,
        'compare' => 'NOT EXISTS',
    )),
)));

$phase = (string) $status['phase'];
$assert(in_array($phase, array('staged', 'activated'), true), 'The access migration is not staged or activated.');
$assert($phase === $verification['phase'], 'Verification changed the access migration phase.');
$assert(8 === count($owned_ids), 'Exactly eight migration-owned MemberPress rules were not staged.');
$assert(8 === (int) $verification['native_rules_verified'], 'Verification did not cover all eight native rules.');
$assert(22 === count($published_legacy_ids), 'The 22 published legacy MemberPress rules changed.');
$published_native_count = count(array_filter($owned_ids, static function ($rule_id) {
    return 'publish' === get_post_status($rule_id);
}));
$assert(('activated' === $phase ? 8 : 0) === $published_native_count, 'The TSOL-native rule publication state is incorrect.');
$assert(('activated' === $phase ? 'tsol_native' : 'legacy_delegation') === $verification['authorization_mode'], 'The authorization-pointer mode is incorrect.');
$assert(154 === (int) $verification['targets_checked'], 'Verification did not cover all 154 TSOL records.');
$assert($expected_user_count === (int) $verification['matrix']['users_checked'], 'The complete current local user population was not checked.');
$assert($expected_user_count * 154 === (int) $verification['matrix']['decisions_checked'], 'The complete current user-by-content matrix was not checked.');
$assert(0 === (int) $verification['matrix']['allow_to_deny'], 'The native policy would remove existing access.');
$assert(18 === (int) $verification['matrix']['deny_to_allow'], 'The recorded Social Media Course-root correction changed.');
$assert(empty($verification['matrix']['unexpected_policy_differences']), 'An unregistered access difference was found.');
$assert(0 === (int) $verification['identities_emitted'], 'The access report emitted member identities.');

$course_targets = (array) MeprRule::get_contents_array('single_' . TSOL_Library_Content_Model::COURSE_POST_TYPE);
$series_targets = (array) MeprRule::get_contents_array('single_' . TSOL_Library_Content_Model::SERIES_POST_TYPE);
$content_targets = (array) MeprRule::get_contents_array('single_' . TSOL_Library_Content_Model::ITEM_POST_TYPE);
$collection_targets = (array) MeprRule::get_contents_array(
    'tax_' . TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY . '||cpt_' . TSOL_Library_Content_Model::COURSE_POST_TYPE
);
$assert(7 === count($course_targets), 'MemberPress does not list all seven TSOL Courses as individual rule targets.');
$assert(6 === count($series_targets), 'MemberPress does not list all six TSOL Series as individual rule targets.');
$expected_content_target_ids = array_map('intval', get_posts(array(
    'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
    'post_status' => array_values(get_post_stati()),
    'posts_per_page' => -1,
    'fields' => 'ids',
    'suppress_filters' => true,
    'meta_query' => array(array(
        'key' => TSOL_Library_Content_Model::META_MIGRATION_KEY,
        'compare' => 'EXISTS',
    )),
)));
$listed_content_target_ids = array_map('intval', array_keys($content_targets));
$assert(
    194 === count($expected_content_target_ids)
        && empty(array_diff($expected_content_target_ids, $listed_content_target_ids)),
    'MemberPress does not list every normalized TSOL Content record as an individual rule target.'
);
$assert(1 === count($collection_targets) && in_array('Masterclasses', array_values($collection_targets), true), 'MemberPress does not list the Masterclasses Collection as a rule target.');

$legacy_pointer_count = 0;
$native_pointer_count = 0;
$all_targets_published = true;
$target_ids = get_posts(array(
    'post_type' => TSOL_Library_Content_Model::post_types(),
    'post_status' => array_values(get_post_stati()),
    'posts_per_page' => -1,
    'fields' => 'ids',
    'suppress_filters' => true,
    'meta_query' => array(array(
        'key' => TSOL_Library_Content_Model::META_MIGRATION_KEY,
        'compare' => 'EXISTS',
    )),
));
foreach ($target_ids as $target_id) {
    if ('publish' !== get_post_status($target_id)) {
        $all_targets_published = false;
    }
    $authorization_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true);
    if ($authorization_id > 0 && !in_array(get_post_type($authorization_id), TSOL_Library_Content_Model::post_types(), true)) {
        $legacy_pointer_count++;
    }
    $expected_native_id = (int) $target_id;
    if (TSOL_Library_Content_Model::ITEM_POST_TYPE === get_post_type($target_id)) {
        $course_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_COURSE_ID, true);
        $series_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_SERIES_ID, true);
        $expected_native_id = $course_id > 0 ? $course_id : $series_id;
    }
    if ($authorization_id === $expected_native_id) {
        $native_pointer_count++;
    }
}
$assert(!$verification['activation_blocked_until_targets_are_published'] === $all_targets_published, 'The publication activation gate is incorrect.');
$assert(('activated' === $phase ? 0 : 154) === $legacy_pointer_count, 'The historical migration legacy authorization-pointer count is incorrect.');
$assert(('activated' === $phase ? 207 : 53) === $native_pointer_count, 'The combined native authorization-pointer count is incorrect.');

$condition_count = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->prefix}mepr_rule_access_conditions WHERE rule_id IN (" . implode(',', $owned_ids) . ')'
);
$assert(91 === $condition_count, 'The staged native condition inventory changed.');

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}

WP_CLI::line(wp_json_encode(array(
    'scope' => 'tsol-library-access-rules',
    'phase' => $verification['phase'],
    'legacy_rules' => count($published_legacy_ids),
    'native_rules' => count($owned_ids),
    'native_rule_status' => $verification['native_rule_status'],
    'native_access_conditions' => $condition_count,
    'targets_checked' => $verification['targets_checked'],
    'users_checked' => $verification['matrix']['users_checked'],
    'decisions_checked' => $verification['matrix']['decisions_checked'],
    'allow_to_deny' => $verification['matrix']['allow_to_deny'],
    'deny_to_allow_approved_candidate' => $verification['matrix']['deny_to_allow'],
    'identities_emitted' => 0,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
WP_CLI::success('TSOL-native MemberPress rules passed with legacy authority intact.');
