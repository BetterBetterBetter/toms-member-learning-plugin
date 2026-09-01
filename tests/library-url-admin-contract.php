<?php
/**
 * Disposable contract for canonical Library URL editing.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$failures = array();
$created_post_ids = array();
$original_user_id = get_current_user_id();
$original_post = $_POST;
$original_get = $_GET;
$url_admin = new MemberLibrary_URL_Admin();

$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$create_post = static function ($post_type, $title, $slug, $status = 'draft') use (&$created_post_ids) {
    $post_id = wp_insert_post(array(
        'post_type' => $post_type,
        'post_status' => $status,
        'post_title' => $title,
        'post_name' => $slug,
    ), true);
    if (is_wp_error($post_id)) {
        throw new RuntimeException($post_id->get_error_message());
    }
    $created_post_ids[] = (int) $post_id;
    return (int) $post_id;
};

try {
    MemberLibrary_Content_Model::register();
    $administrator_ids = get_users(array(
        'role' => 'administrator',
        'fields' => 'ids',
        'number' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ));
    if (empty($administrator_ids)) {
        throw new RuntimeException('No administrator is available for the Library URL contract.');
    }
    wp_set_current_user((int) $administrator_ids[0]);
    $suffix = strtolower(wp_generate_password(8, false, false));
    $course_id = $create_post(
        MemberLibrary_Content_Model::COURSE_POST_TYPE,
        'URL contract course',
        'url-contract-course-' . $suffix
    );
    $series_id = $create_post(
        MemberLibrary_Content_Model::SERIES_POST_TYPE,
        'URL contract series',
        'url-contract-series-' . $suffix
    );
    $item_id = $create_post(
        MemberLibrary_Content_Model::ITEM_POST_TYPE,
        'URL contract lesson',
        'url-contract-lesson-' . $suffix
    );
    $speaker_id = $create_post(
        MemberLibrary_Content_Model::SPEAKER_POST_TYPE,
        'URL contract speaker',
        'url-contract-speaker-' . $suffix
    );
    $unsaved_parent_id = $create_post(
        MemberLibrary_Content_Model::COURSE_POST_TYPE,
        'Unsaved Parent Slug ' . $suffix,
        ''
    );

    $assert(
        array(
            MemberLibrary_Content_Model::COURSE_POST_TYPE,
            MemberLibrary_Content_Model::SERIES_POST_TYPE,
            MemberLibrary_Content_Model::ITEM_POST_TYPE,
            MemberLibrary_Content_Model::SPEAKER_POST_TYPE,
        ) === MemberLibrary_URL_Admin::supported_post_types(),
        'The URL editor does not cover every Library-owned public slug.'
    );
    $assert(!MemberLibrary_URL_Admin::supports_post_type('post'), 'The Library URL editor leaked onto native Posts.');

    $course = get_post($course_id);
    $series = get_post($series_id);
    $item = get_post($item_id);
    $speaker = get_post($speaker_id);
    $assert('/courses/' . $course->post_name === MemberLibrary_URL_Admin::public_path($course), 'Course URL mapping is incorrect.');
    $assert('/series/' . $series->post_name === MemberLibrary_URL_Admin::public_path($series), 'Series URL mapping is incorrect.');
    $assert('/speakers/' . $speaker->post_name === MemberLibrary_URL_Admin::public_path($speaker), 'Speaker URL mapping is incorrect.');
    $assert('/recordings/' . $item->post_name === MemberLibrary_URL_Admin::public_path($item), 'Standalone Content URL mapping is incorrect.');

    update_post_meta($item_id, MemberLibrary_Content_Model::META_COURSE_ID, $course_id);
    $assert(
        '/courses/' . $course->post_name . '/' . $item->post_name === MemberLibrary_URL_Admin::public_path($item),
        'Course lesson URL mapping is incorrect.'
    );
    delete_post_meta($item_id, MemberLibrary_Content_Model::META_COURSE_ID);
    update_post_meta($item_id, MemberLibrary_Content_Model::META_SERIES_ID, $series_id);
    $assert(
        '/series/' . $series->post_name . '/' . $item->post_name === MemberLibrary_URL_Admin::public_path($item),
        'Series episode URL mapping is incorrect.'
    );
    delete_post_meta($item_id, MemberLibrary_Content_Model::META_SERIES_ID);
    update_post_meta($item_id, MemberLibrary_Content_Model::META_COURSE_ID, $unsaved_parent_id);
    $assert(
        '/courses/unsaved-parent-slug-' . $suffix . '/' . $item->post_name === MemberLibrary_URL_Admin::public_path($item),
        'A parent without a stored slug did not receive a safe title-derived path preview.'
    );
    delete_post_meta($item_id, MemberLibrary_Content_Model::META_COURSE_ID);
    $_GET['tsol_course_id'] = (string) $course_id;
    $assert(
        '/courses/' . $course->post_name . '/' . $item->post_name === MemberLibrary_URL_Admin::public_path($item),
        'A new lesson did not inherit its requested Course path preview.'
    );
    $_GET = $original_get;

    $content_admin = new MemberLibrary_Content_Admin();
    ob_start();
    $content_admin->render_details_meta_box($item);
    $placement_html = ob_get_clean();
    $assert(
        false !== strpos($placement_html, 'data-library-parent-slug="' . $course->post_name . '"'),
        'The Course selector omitted the canonical parent slug used by live URL previews.'
    );
    $assert(
        false !== strpos($placement_html, 'data-library-parent-slug="' . $series->post_name . '"'),
        'The Series selector omitted the canonical parent slug used by live URL previews.'
    );

    ob_start();
    $url_admin->render_editor($course);
    $editor_html = ob_get_clean();
    foreach (array(
        'Library path:',
        'data-library-slug-edit',
        '>Edit<',
        'data-library-slug-confirm',
        '>OK<',
        'data-library-slug-cancel',
        '>Cancel<',
        'name="' . MemberLibrary_URL_Admin::SLUG_FIELD . '"',
        '/courses/',
        'data-library-slug-text>' . $course->post_name,
        'Only the final path segment is editable',
    ) as $expected_copy) {
        $assert(false !== strpos($editor_html, $expected_copy), 'The URL editor omitted: ' . $expected_copy);
    }
    foreach (array('data-library-url-preview', 'Result:', 'Use title') as $removed_copy) {
        $assert(false === strpos($editor_html, $removed_copy), 'The compact slug editor retained: ' . $removed_copy);
    }
    $assert(false === strpos($editor_html, 'Existing links will not redirect automatically.'), 'Draft content received a published-link warning.');

    $published_id = $create_post(
        MemberLibrary_Content_Model::ITEM_POST_TYPE,
        'Published URL contract item',
        'published-url-contract-' . $suffix,
        'publish'
    );
    ob_start();
    $url_admin->render_editor(get_post($published_id));
    $published_html = ob_get_clean();
    $assert(false !== strpos($published_html, 'Existing links will not redirect automatically.'), 'Published content omitted the link-change warning.');

    $url_admin->init();
    $_POST = array(
        MemberLibrary_URL_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_URL_Admin::NONCE_ACTION),
        MemberLibrary_URL_Admin::SLUG_FIELD => 'A Better / Sharing Slug!',
    );
    wp_update_post(array('ID' => $item_id, 'post_title' => 'A renamed lesson'));
    $assert('a-better-sharing-slug' === get_post_field('post_name', $item_id), 'The URL editor did not normalize and save an explicit slug.');

    $_POST[MemberLibrary_URL_Admin::SLUG_FIELD] = '';
    wp_update_post(array('ID' => $item_id, 'post_title' => 'Generated From This Title'));
    $assert('generated-from-this-title' === get_post_field('post_name', $item_id), 'Clearing the slug did not regenerate it from the title.');

    $_POST[MemberLibrary_URL_Admin::SLUG_FIELD] = 'generated-from-this-title';
    wp_update_post(array('ID' => $item_id, 'post_title' => 'Title Changes Must Not Rewrite URLs'));
    $assert('generated-from-this-title' === get_post_field('post_name', $item_id), 'Changing a title unexpectedly rewrote an established slug.');

    $_POST = array(
        MemberLibrary_URL_Admin::NONCE_NAME => 'invalid',
        MemberLibrary_URL_Admin::SLUG_FIELD => 'must-not-save',
    );
    wp_update_post(array('ID' => $item_id, 'post_excerpt' => 'Nonce regression check'));
    $assert('generated-from-this-title' === get_post_field('post_name', $item_id), 'An invalid nonce changed a Library slug.');

    $collision_slug = 'url-collision-' . $suffix;
    $collision_owner_id = $create_post(
        MemberLibrary_Content_Model::ITEM_POST_TYPE,
        'Collision owner',
        $collision_slug,
        'publish'
    );
    $collision_target_id = $create_post(
        MemberLibrary_Content_Model::ITEM_POST_TYPE,
        'Collision target',
        'collision-target-' . $suffix,
        'publish'
    );
    $_POST = array(
        MemberLibrary_URL_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_URL_Admin::NONCE_ACTION),
        MemberLibrary_URL_Admin::SLUG_FIELD => $collision_slug,
    );
    wp_update_post(array('ID' => $collision_target_id, 'post_excerpt' => 'Collision regression check'));
    $resolved_collision_slug = (string) get_post_field('post_name', $collision_target_id);
    $assert($collision_slug !== $resolved_collision_slug, 'A published duplicate slug was accepted unchanged.');
    $assert(str_starts_with($resolved_collision_slug, $collision_slug . '-'), 'The duplicate slug did not receive a readable WordPress suffix.');
    $assert($collision_slug === get_post_field('post_name', $collision_owner_id), 'Resolving a collision changed the existing URL owner.');

    foreach (MemberLibrary_URL_Admin::supported_post_types() as $post_type) {
        $post_type_object = get_post_type_object($post_type);
        $assert(
            $post_type_object instanceof WP_Post_Type
            && false === $post_type_object->public
            && false === $post_type_object->publicly_queryable
            && false === $post_type_object->rewrite,
            sprintf('Adding slug editing made %s publicly routable in WordPress.', $post_type)
        );
    }
} finally {
    remove_action('edit_form_after_title', array($url_admin, 'render_editor'), 5);
    remove_filter('wp_insert_post_data', array($url_admin, 'filter_post_data'), 20);
    remove_action('admin_enqueue_scripts', array($url_admin, 'enqueue_assets'));
    $_POST = $original_post;
    $_GET = $original_get;
    wp_set_current_user($original_user_id);
    foreach (array_reverse($created_post_ids) as $post_id) {
        wp_delete_post($post_id, true);
    }
}

if (!empty($failures)) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    throw new RuntimeException(sprintf('Library URL admin contract failed with %d issue(s).', count($failures)));
}

WP_CLI::success('Library URL admin contract passed.');
