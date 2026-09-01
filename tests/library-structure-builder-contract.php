<?php
/**
 * Mutation contract for the parent-owned Library Structure Builder.
 *
 * Only uniquely named disposable draft posts are created and then removed.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$failures = array();
$created_post_ids = array();
$original_user_id = get_current_user_id();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};
$create_post = static function ($post_type, $title) use (&$created_post_ids) {
    $post_id = wp_insert_post(array(
        'post_type' => $post_type,
        'post_status' => 'draft',
        'post_title' => $title,
    ), true);
    if (is_wp_error($post_id)) {
        throw new RuntimeException($post_id->get_error_message());
    }
    $created_post_ids[] = (int) $post_id;
    return (int) $post_id;
};
$assign_child = static function ($item_id, $parent_type, $parent_id, $key, $title, $group_position, $position) {
    $is_course = TSOL_Library_Content_Model::COURSE_POST_TYPE === $parent_type;
    update_post_meta($item_id, $is_course ? TSOL_Library_Content_Model::META_COURSE_ID : TSOL_Library_Content_Model::META_SERIES_ID, $parent_id);
    update_post_meta($item_id, $is_course ? TSOL_Library_Content_Model::META_SERIES_ID : TSOL_Library_Content_Model::META_COURSE_ID, 0);
    update_post_meta($item_id, $is_course ? TSOL_Library_Content_Model::META_SECTION_KEY : TSOL_Library_Content_Model::META_SERIES_GROUP_KEY, $key);
    update_post_meta($item_id, $is_course ? TSOL_Library_Content_Model::META_SECTION_TITLE : TSOL_Library_Content_Model::META_SERIES_GROUP_TITLE, $title);
    update_post_meta($item_id, $is_course ? TSOL_Library_Content_Model::META_SECTION_POSITION : TSOL_Library_Content_Model::META_SERIES_GROUP_POSITION, $group_position);
    update_post_meta($item_id, TSOL_Library_Content_Model::META_POSITION, $position);
    update_post_meta($item_id, TSOL_Library_Content_Model::META_UUID, wp_generate_uuid4());
};

try {
    TSOL_Library_Content_Model::register();
    $assert(class_exists('TSOL_Library_Structure'), 'The parent-owned structure service is unavailable.');
    $assert(class_exists('TSOL_Library_Structure_Admin'), 'The Structure Builder admin controller is unavailable.');

    $administrator_ids = get_users(array('role' => 'administrator', 'fields' => 'ids', 'number' => 1));
    if (empty($administrator_ids)) {
        throw new RuntimeException('No administrator is available for the Structure Builder contract.');
    }
    wp_set_current_user((int) $administrator_ids[0]);

    $course_id = $create_post(TSOL_Library_Content_Model::COURSE_POST_TYPE, 'TSOL structure contract course');
    $lesson_one = $create_post(TSOL_Library_Content_Model::ITEM_POST_TYPE, 'TSOL structure contract lesson one');
    $lesson_two = $create_post(TSOL_Library_Content_Model::ITEM_POST_TYPE, 'TSOL structure contract lesson two');
    update_post_meta($course_id, TSOL_Library_Content_Model::META_COURSE_SECTIONS, array(
        array('key' => 'section-alpha', 'title' => 'Alpha', 'position' => 1),
        array('key' => 'section-beta', 'title' => 'Beta', 'position' => 2),
    ));
    $assign_child($lesson_one, TSOL_Library_Content_Model::COURSE_POST_TYPE, $course_id, 'section-alpha', 'Alpha', 1, 1);
    $assign_child($lesson_two, TSOL_Library_Content_Model::COURSE_POST_TYPE, $course_id, 'section-alpha', 'Alpha', 1, 2);
    update_post_meta($lesson_one, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, 700001);
    update_post_meta($lesson_two, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, 700002);
    $lesson_one_uuid = get_post_meta($lesson_one, TSOL_Library_Content_Model::META_UUID, true);
    $lesson_two_uuid = get_post_meta($lesson_two, TSOL_Library_Content_Model::META_UUID, true);

    $snapshot = TSOL_Library_Structure::snapshot($course_id);
    $assert(!is_wp_error($snapshot), 'A valid Course structure did not produce a snapshot.');
    $assert(2 === (int) $snapshot['groupCount'] && 2 === (int) $snapshot['itemCount'], 'The Course snapshot counts headings as content or lost content.');

    $admin = new TSOL_Library_Structure_Admin();
    ob_start();
    $admin->render_compact_summary(get_post($course_id));
    $summary_html = ob_get_clean();
    $assert(false === strpos($summary_html, '<ol'), 'The compact summary still uses browser-generated list numbering.');
    $assert(false !== strpos($summary_html, 'Open structure builder'), 'The compact Course widget does not lead to the Structure Builder.');

    $original_get = $_GET;
    $_GET = array(TSOL_Library_Structure_Admin::RETURN_ARG => $course_id);
    ob_start();
    $admin->render_contextual_return(get_post($lesson_one));
    $course_return_html = ob_get_clean();
    $_GET = $original_get;
    $assert(false !== strpos($course_return_html, 'Back to Course structure'), 'A Course lesson opened from the builder has no contextual return button.');
    $assert(false !== strpos($course_return_html, 'value="' . $course_id . '"'), 'The Course return context is not preserved in the edit form.');

    $original_post = $_POST;
    $_POST = array(TSOL_Library_Structure_Admin::RETURN_ARG => $course_id);
    $redirect_location = $admin->preserve_contextual_return('post.php?post=' . $lesson_one . '&action=edit', $lesson_one);
    $_POST = $original_post;
    $assert(false !== strpos($redirect_location, TSOL_Library_Structure_Admin::RETURN_ARG . '=' . $course_id), 'Saving a Course lesson discarded its Structure Builder return context.');

    $course_payload = array('groups' => array(
        array('key' => 'section-beta', 'title' => 'Beta renamed', 'items' => array($lesson_two)),
        array('key' => 'section-alpha', 'title' => 'Alpha', 'items' => array($lesson_one)),
        array('key' => 'section-empty', 'title' => 'Empty section', 'items' => array()),
    ));
    $saved = TSOL_Library_Structure::save_display_structure($course_id, $course_payload, $snapshot['revision']);
    $assert(!is_wp_error($saved), 'A valid complete Course structure was rejected.');
    $assert(3 === (int) $saved['groupCount'], 'An empty parent-owned Course section was not retained.');
    $assert('section-beta' === get_post_meta($lesson_two, TSOL_Library_Content_Model::META_SECTION_KEY, true), 'Moving a lesson did not update its stable section key.');
    $assert('Beta renamed' === get_post_meta($lesson_two, TSOL_Library_Content_Model::META_SECTION_TITLE, true), 'Renaming a section did not refresh derived compatibility metadata.');
    $assert(1 === (int) get_post_meta($lesson_two, TSOL_Library_Content_Model::META_POSITION, true), 'Course item positions were not made contiguous within their section.');
    $assert(700001 === (int) get_post_meta($lesson_one, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true), 'Reordering changed lesson one’s MemberPress authorization pointer.');
    $assert(700002 === (int) get_post_meta($lesson_two, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true), 'Reordering changed lesson two’s MemberPress authorization pointer.');
    $assert($lesson_one_uuid === get_post_meta($lesson_one, TSOL_Library_Content_Model::META_UUID, true), 'Reordering changed lesson one’s immutable content UUID.');
    $assert($lesson_two_uuid === get_post_meta($lesson_two, TSOL_Library_Content_Model::META_UUID, true), 'Reordering changed lesson two’s immutable content UUID.');

    $stale = TSOL_Library_Structure::save_display_structure($course_id, $course_payload, $snapshot['revision']);
    $assert(is_wp_error($stale) && 'structure_conflict' === $stale->get_error_code(), 'A stale Structure Builder revision did not fail safely.');
    $duplicate = TSOL_Library_Structure::save_display_structure($course_id, array('groups' => array(
        array('key' => 'section-alpha', 'title' => 'Alpha', 'items' => array($lesson_one, $lesson_one)),
    )), $saved['revision']);
    $assert(is_wp_error($duplicate), 'A duplicate/incomplete item payload was accepted.');

    $series_id = $create_post(TSOL_Library_Content_Model::SERIES_POST_TYPE, 'TSOL structure contract series');
    $episode_old = $create_post(TSOL_Library_Content_Model::ITEM_POST_TYPE, 'TSOL structure contract old episode');
    $episode_new = $create_post(TSOL_Library_Content_Model::ITEM_POST_TYPE, 'TSOL structure contract new episode');
    update_post_meta($series_id, TSOL_Library_Content_Model::META_SERIES_SORT, 'desc');
    update_post_meta($series_id, TSOL_Library_Content_Model::META_SERIES_GROUPS, array(
        array('key' => 'group-old', 'title' => '2025', 'position' => 1),
        array('key' => 'group-new', 'title' => '2026', 'position' => 2),
    ));
    $assign_child($episode_old, TSOL_Library_Content_Model::SERIES_POST_TYPE, $series_id, 'group-old', '2025', 1, 1);
    $assign_child($episode_new, TSOL_Library_Content_Model::SERIES_POST_TYPE, $series_id, 'group-new', '2026', 2, 2);
    update_post_meta($episode_old, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, 800001);
    update_post_meta($episode_new, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, 800002);

    $original_get = $_GET;
    $_GET = array(TSOL_Library_Structure_Admin::RETURN_ARG => $series_id);
    ob_start();
    $admin->render_contextual_return(get_post($episode_new));
    $series_return_html = ob_get_clean();
    $_GET = $original_get;
    $assert(false !== strpos($series_return_html, 'Back to Series structure'), 'A Series episode opened from the builder has no contextual return button.');

    $series_snapshot = TSOL_Library_Structure::snapshot($series_id);
    $assert('group-new' === (string) $series_snapshot['groups'][0]['key'], 'A newest-first Series is not presented in frontend order.');
    $series_payload = array('groups' => array(
        array('key' => 'group-new', 'title' => 'Current year', 'items' => array($episode_new)),
        array('key' => 'group-old', 'title' => 'Archive', 'items' => array($episode_old)),
    ));
    $series_saved = TSOL_Library_Structure::save_display_structure($series_id, $series_payload, $series_snapshot['revision']);
    $assert(!is_wp_error($series_saved), 'A valid newest-first Series structure was rejected.');
    $registry = get_post_meta($series_id, TSOL_Library_Content_Model::META_SERIES_GROUPS, true);
    $assert('group-old' === (string) $registry[0]['key'] && 'group-new' === (string) $registry[1]['key'], 'Visible newest-first Series order was not converted to canonical ascending positions.');
    $assert('Current year' === get_post_meta($episode_new, TSOL_Library_Content_Model::META_SERIES_GROUP_TITLE, true), 'A Series group rename did not refresh derived episode metadata.');
    $assert(800001 === (int) get_post_meta($episode_old, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true), 'Series structure edits changed the old episode authorization pointer.');
    $assert(800002 === (int) get_post_meta($episode_new, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true), 'Series structure edits changed the new episode authorization pointer.');

    if (!empty($failures)) {
        WP_CLI::error(implode("\n", array_values(array_unique($failures))));
    }

    WP_CLI::line(wp_json_encode(array(
        'course_groups' => (int) $saved['groupCount'],
        'course_items' => (int) $saved['itemCount'],
        'series_display_order' => array_column($series_saved['groups'], 'key'),
        'authorization_pointers_changed' => 0,
        'uuid_changes' => 0,
        'stale_revision_rejected' => true,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    WP_CLI::success('Liberty Classroom Library Structure Builder mutation contract passed.');
} finally {
    foreach (array_reverse($created_post_ids) as $post_id) {
        wp_delete_post((int) $post_id, true);
    }
    wp_set_current_user($original_user_id);
}
