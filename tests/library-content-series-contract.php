<?php
/** Read-only contract for the guarded six-Series and Collections migration. */

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

$base = (new TSOL_Library_Catalogue_Import())->verify();
$report = (new TSOL_Library_Series_Import())->verify();
$target_status = (string) $report['target_status'];
$reviewable_statuses = array('publish', 'draft', 'pending', 'private', 'future');
$authorization_mode = (string) $report['authorization_mode'];
$assert(150 === (int) $base['authorization_delegations_equivalent'], 'The original 150 authorization delegations changed.');
$assert(6 === (int) $report['series'] && 121 === (int) $report['episodes'], 'The structure is not six Series containing all 121 non-course items.');
$assert(array(
    'Sessions' => 96,
    'Live Events' => 18,
    'Unconference 2025' => 3,
    'New Member Orientation' => 2,
    'Limitless Book Club' => 1,
    'Member Calls' => 1,
) === $report['series_summary'], 'The locked Series inventory changed.');

$expected = array(
    'series-sessions' => array('Sessions', 96, 'sessions', 'desc', true),
    'series-live-events' => array('Live Events', 18, 'talks', 'desc', true),
    'series-unconference-2025' => array('Unconference 2025', 3, 'sessions', 'asc', false),
    'series-new-member-orientation' => array('New Member Orientation', 2, 'versions', 'desc', true),
    'series-limitless-book-club' => array('Limitless Book Club', 1, 'sessions', 'desc', true),
    'series-member-calls' => array('Member Calls', 1, 'calls', 'desc', true),
);
$all_episode_ids = array();
foreach ($expected as $key => $shape) {
    $series_id = (int) ($report['series_ids'][$key] ?? 0);
    $series = get_post($series_id);
    $assert($series instanceof WP_Post && in_array((string) $series->post_status, $reviewable_statuses, true), sprintf('%s is not in a reviewable editorial status.', $shape[0]));
    $assert($series instanceof WP_Post && $shape[0] === (string) $series->post_title, sprintf('%s title changed.', $shape[0]));
    $assert($shape[2] === (string) get_post_meta($series_id, TSOL_Library_Content_Model::META_SERIES_ITEM_LABEL_PLURAL, true), sprintf('%s plural label changed.', $shape[0]));
    $assert($shape[3] === (string) get_post_meta($series_id, TSOL_Library_Content_Model::META_SERIES_SORT, true), sprintf('%s ordering changed.', $shape[0]));
    $assert($shape[4] === (bool) get_post_meta($series_id, TSOL_Library_Content_Model::META_SERIES_ONGOING, true), sprintf('%s ongoing state changed.', $shape[0]));
    $assert(array() === get_post_meta($series_id, TSOL_Library_Content_Model::META_MEDIA_ASSETS, true), sprintf('%s unexpectedly owns playable media.', $shape[0]));

    $episode_ids = get_posts(array(
        'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
        'post_status' => $reviewable_statuses,
        'numberposts' => -1,
        'fields' => 'ids',
        'meta_key' => TSOL_Library_Content_Model::META_SERIES_ID,
        'meta_value' => $series_id,
        'suppress_filters' => true,
    ));
    $episode_ids = array_map('intval', $episode_ids);
    $all_episode_ids = array_merge($all_episode_ids, $episode_ids);
    $assert((int) $shape[1] === count($episode_ids), sprintf('%s has the wrong episode count.', $shape[0]));
    $positions = array();
    foreach ($episode_ids as $episode_id) {
        $positions[] = (int) get_post_meta($episode_id, TSOL_Library_Content_Model::META_POSITION, true);
        $source_id = (int) get_post_meta($episode_id, TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID, true);
        $authorization_id = (int) get_post_meta($episode_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true);
        $assert(0 === (int) get_post_meta($episode_id, TSOL_Library_Content_Model::META_COURSE_ID, true), sprintf('Series episode %d also belongs to a Course.', $episode_id));
        $expected_authorization_id = 'tsol_native' === $authorization_mode ? $series_id : $source_id;
        $assert($authorization_id === $expected_authorization_id, sprintf('Series episode %d has an authorization pointer inconsistent with the migration phase.', $episode_id));
    }
    sort($positions, SORT_NUMERIC);
    $assert(range(1, (int) $shape[1]) === $positions, sprintf('%s positions are not contiguous.', $shape[0]));
}

$assert(121 === count(array_unique($all_episode_ids)), 'A non-course item is ungrouped or belongs to multiple Series.');
$standalone_count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} p
     LEFT JOIN {$wpdb->postmeta} c ON c.post_id=p.ID AND c.meta_key=%s
     LEFT JOIN {$wpdb->postmeta} s ON s.post_id=p.ID AND s.meta_key=%s
     WHERE p.post_type=%s AND p.post_status NOT IN ('trash','auto-draft','inherit')
       AND COALESCE(CAST(c.meta_value AS UNSIGNED),0)=0
       AND COALESCE(CAST(s.meta_value AS UNSIGNED),0)=0",
    TSOL_Library_Content_Model::META_COURSE_ID,
    TSOL_Library_Content_Model::META_SERIES_ID,
    TSOL_Library_Content_Model::ITEM_POST_TYPE
));
$assert(0 === $standalone_count, 'A normalized content item remains standalone.');

$masterclasses = get_term_by('slug', 'masterclasses', TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY);
$assert($masterclasses instanceof WP_Term, 'The Masterclasses Collection is missing.');
$masterclass_courses = $masterclasses instanceof WP_Term
    ? get_objects_in_term((int) $masterclasses->term_id, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY)
    : array();
$assert(!is_wp_error($masterclass_courses) && 5 === count($masterclass_courses), 'The Masterclasses Collection does not contain five Courses.');
if (!is_wp_error($masterclass_courses)) {
    $masterclass_titles = array_map(static function ($course_id) {
        return (string) get_the_title((int) $course_id);
    }, $masterclass_courses);
    sort($masterclass_titles, SORT_STRING);
    $expected_masterclass_titles = array('Against the Machine', 'Social Media', 'Tax Strategy Intensive', 'The $100 Medicine Cabinet', 'The AI Advantage');
    sort($expected_masterclass_titles, SORT_STRING);
    $assert($expected_masterclass_titles === $masterclass_titles, 'The Masterclasses Collection contains redundant or unexpected Course titles.');
}
$retired_collection_rows = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE taxonomy=%s",
    TSOL_Library_Series_Import::RETIRED_COLLECTION_TAXONOMY
));
$assert(0 === $retired_collection_rows, 'Retired mixed-content Collections remain in the database.');

$legacy_series_meta = (int) $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type IN ('mpcs-course','page') AND pm.meta_key IN ('_tsol_library_series_id','_tsol_library_series_group_key','_tsol_library_series_group_title','_tsol_library_series_group_position')"
);
$assert(0 === $legacy_series_meta, 'Series relationship metadata leaked onto legacy sources.');

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}

WP_CLI::line(wp_json_encode(array(
    'series' => $report['series_summary'],
    'episodes' => count($all_episode_ids),
    'standalone_items' => $standalone_count,
    'masterclass_courses' => count($masterclass_courses),
    'retired_mixed_collections' => $retired_collection_rows,
    'legacy_series_meta_rows' => $legacy_series_meta,
    'authorization_delegations_equivalent' => $base['authorization_delegations_equivalent'],
    'authorization_mode' => $authorization_mode,
    'target_status' => $target_status,
    'target_statuses' => $report['target_statuses'],
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
WP_CLI::success('Six-Series and Collections contract passed without legacy or MemberPress mutation.');
