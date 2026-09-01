<?php
/**
 * Contract for Course Collection editorial fields and catalogue projection.
 *
 * Run: wp eval-file tests/library-collection-editorial-contract.php --skip-themes
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$failures = array();
$created_course_id = 0;
$created_term_id = 0;
$original_user_id = get_current_user_id();
$original_post = $_POST;
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    MemberLibrary_Content_Model::register();
    $administrator_ids = get_users(array('role' => 'administrator', 'fields' => 'ids', 'number' => 1));
    if (empty($administrator_ids)) {
        throw new RuntimeException('No administrator is available for the Collection editorial contract.');
    }
    wp_set_current_user((int) $administrator_ids[0]);

    $term = wp_insert_term('Collection editorial contract', MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY, array(
        'slug' => 'collection-editorial-contract',
        'description' => 'A focused public introduction.',
    ));
    if (is_wp_error($term)) {
        throw new RuntimeException($term->get_error_message());
    }
    $created_term_id = (int) $term['term_id'];

    $created_course_id = (int) wp_insert_post(array(
        'post_type' => MemberLibrary_Content_Model::COURSE_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => 'Collection editorial Course',
        'post_name' => 'collection-editorial-course',
        'post_excerpt' => 'A Course used to verify Collection projection.',
    ));
    update_post_meta($created_course_id, MemberLibrary_Content_Model::META_UUID, wp_generate_uuid4());
    update_post_meta($created_course_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, $created_course_id);
    update_post_meta($created_course_id, MemberLibrary_Content_Model::META_MIGRATION_KEY, 'collection-editorial-course');
    update_post_meta($created_course_id, MemberLibrary_Content_Model::META_MIGRATION_VERSION, '1');
    update_post_meta($created_course_id, MemberLibrary_Content_Model::META_CONTENT_FINGERPRINT, 'collection-editorial-fingerprint');
    wp_set_object_terms($created_course_id, array($created_term_id), MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY);

    $admin = new MemberLibrary_Collection_Admin();
    $_POST = array(
        MemberLibrary_Collection_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_Collection_Admin::NONCE_ACTION),
        MemberLibrary_Collection_Admin::PAYLOAD_NAME => array(
            'overview_html' => '<h2>Build real capability</h2><p style="color:red">A safe <strong>overview</strong>.</p><script>alert(1)</script>',
            'hero_image_id' => 999999,
            'featured_course_id' => $created_course_id,
            'appearance_enabled' => '1',
            'light_background' => '#1f4e79',
            'light_foreground' => '#ffffff',
            'dark_background' => '#8fc8f0',
            'dark_foreground' => '#10263a',
        ),
    );
    $admin->save_fields($created_term_id, 0, MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY);

    $stored_overview = (string) get_term_meta($created_term_id, MemberLibrary_Content_Model::COLLECTION_META_OVERVIEW, true);
    $assert(false !== strpos($stored_overview, '<h2>Build real capability</h2>'), 'Collection overview lost safe semantic markup.');
    $assert(false === strpos($stored_overview, 'style='), 'Collection overview retained pasted styles.');
    $assert(false === strpos($stored_overview, '<script'), 'Collection overview retained a script.');
    $assert('' === (string) get_term_meta($created_term_id, MemberLibrary_Content_Model::COLLECTION_META_HERO_IMAGE_ID, true), 'Collection accepted a non-image hero attachment.');
    $assert($created_course_id === (int) get_term_meta($created_term_id, MemberLibrary_Content_Model::COLLECTION_META_FEATURED_COURSE_ID, true), 'Collection did not retain its assigned featured Course.');
    $expected_appearance = array(
        'light_background' => '#1f4e79',
        'light_foreground' => '#ffffff',
        'dark_background' => '#8fc8f0',
        'dark_foreground' => '#10263a',
    );
    $assert($expected_appearance === MemberLibrary_Content_Model::collection_appearance($created_term_id), 'Collection did not retain its accessible light and dark colors.');

    $record = MemberLibrary_Content_Catalogue::record($created_course_id);
    $assert(!is_wp_error($record), 'Collection editorial Course was not exportable.');
    $projected_collection = !is_wp_error($record) ? ($record['course_collections'][0] ?? null) : null;
    $assert(is_array($projected_collection), 'Course omitted its Collection projection.');
    if (is_array($projected_collection)) {
        $assert('A focused public introduction.' === (string) $projected_collection['description'], 'Collection description was not projected as plain text.');
        $assert($stored_overview === (string) $projected_collection['overview_html'], 'Collection overview did not match the sanitized WordPress source.');
        $assert(null === $projected_collection['hero_image'], 'Collection projected an invalid hero image.');
        $assert($created_course_id === (int) $projected_collection['featured_course_wordpress_id'], 'Collection featured Course was not projected.');
        $assert($expected_appearance === $projected_collection['appearance'], 'Collection appearance was not projected.');
    }

    ob_start();
    $admin->render_edit_fields(get_term($created_term_id), MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY);
    $editor_html = (string) ob_get_clean();
    $assert(false !== strpos($editor_html, 'Landing page overview'), 'Collection editor omitted its overview field.');
    $assert(false !== strpos($editor_html, 'Hero artwork'), 'Collection editor omitted its hero artwork field.');
    $assert(false !== strpos($editor_html, 'Featured Course'), 'Collection editor omitted its featured Course field.');
    $assert(false !== strpos($editor_html, 'Collection colors'), 'Collection editor omitted its light and dark color controls.');
    $assert(false !== strpos($editor_html, 'Collection editorial Course'), 'Collection editor did not offer an assigned Course as featured content.');
} finally {
    $_POST = $original_post;
    if ($created_course_id > 0) {
        wp_delete_post($created_course_id, true);
    }
    if ($created_term_id > 0) {
        wp_delete_term($created_term_id, MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY);
    }
    wp_set_current_user($original_user_id);
}

if (!empty($failures)) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    throw new RuntimeException(sprintf('Collection editorial contract failed with %d issue(s).', count($failures)));
}

WP_CLI::success('Collection editorial contract passed.');
