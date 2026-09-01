<?php
/** Contract ensuring grouped Library Items authorize through their parent Course. */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$course_id = 0;
$item_id = 0;
try {
    $course_id = wp_insert_post(array(
        'post_type' => TSOL_Library_Content_Model::COURSE_POST_TYPE,
        'post_status' => 'draft',
        'post_title' => 'Authorization contract Course',
    ), true);
    if (is_wp_error($course_id)) {
        throw new RuntimeException($course_id->get_error_message());
    }
    $item_id = wp_insert_post(array(
        'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
        'post_status' => 'draft',
        'post_title' => 'Authorization contract Item',
    ), true);
    if (is_wp_error($item_id)) {
        throw new RuntimeException($item_id->get_error_message());
    }
    update_post_meta($item_id, TSOL_Library_Content_Model::META_COURSE_ID, (int) $course_id);

    $service = new TSOL_Library_Access_Groups();
    if ((int) $course_id !== $service->native_authorization_post_id((int) $course_id)) {
        throw new RuntimeException('A Course does not authorize through itself.');
    }
    if ((int) $course_id !== $service->native_authorization_post_id((int) $item_id)) {
        throw new RuntimeException('A Library Item does not authorize through its parent Course.');
    }
} finally {
    if ((int) $item_id > 0) {
        wp_delete_post((int) $item_id, true);
    }
    if ((int) $course_id > 0) {
        wp_delete_post((int) $course_id, true);
    }
}

WP_CLI::success('Library Item authorization resolves through its parent Course.');
