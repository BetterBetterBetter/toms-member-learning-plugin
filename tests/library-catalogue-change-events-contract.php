<?php
/**
 * Editorial event coverage for the durable catalogue change journal.
 *
 * Run: php -d memory_limit=512M /usr/local/bin/wp eval-file
 * tests/library-catalogue-change-events-contract.php --skip-themes
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

$assert(has_action('created_term', array('TSOL_Library_Content_Changes', 'record_edited_term')) !== false, 'Created terms are not connected to the catalogue journal.');
$assert(has_action('edited_term', array('TSOL_Library_Content_Changes', 'record_edited_term')) !== false, 'Renamed terms are not connected to the catalogue journal.');
$assert(has_action('delete_term', array('TSOL_Library_Content_Changes', 'record_deleted_term')) !== false, 'Deleted terms are not connected to the catalogue journal.');

global $wpdb;
$changes_table = TSOL_Library_Content_Changes::table();
$fixtures = array(
    array(
        'post_type' => TSOL_Library_Content_Model::COURSE_POST_TYPE,
        'taxonomy' => TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY,
        'title' => 'Liberty catalogue Collection event contract',
    ),
    array(
        'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
        'taxonomy' => TSOL_Library_Content_Model::TOPIC_TAXONOMY,
        'title' => 'Liberty catalogue Topic event contract',
    ),
);

$created_post_ids = array();
$created_term_ids = array();

try {
    foreach ($fixtures as $index => $fixture) {
        $post_id = wp_insert_post(array(
            'post_type' => $fixture['post_type'],
            'post_status' => 'draft',
            'post_title' => $fixture['title'],
        ), true);
        $assert(!is_wp_error($post_id), 'Could not create the disposable taxonomy event post.');
        if (is_wp_error($post_id)) {
            continue;
        }
        $post_id = (int) $post_id;
        $created_post_ids[] = $post_id;
        update_post_meta($post_id, TSOL_Library_Content_Model::META_UUID, wp_generate_uuid4());
        update_post_meta($post_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, $post_id);

        $term = wp_insert_term(
            'Liberty catalogue event term ' . $index . ' ' . wp_generate_password(8, false, false),
            $fixture['taxonomy']
        );
        $assert(!is_wp_error($term), 'Could not create the disposable projected taxonomy term.');
        if (is_wp_error($term)) {
            continue;
        }
        $term_id = (int) $term['term_id'];
        $created_term_ids[] = array($term_id, $fixture['taxonomy']);

        $before_assignment = TSOL_Library_Content_Changes::current_cursor();
        wp_set_object_terms($post_id, array($term_id), $fixture['taxonomy'], false);
        $after_assignment = TSOL_Library_Content_Changes::current_cursor();
        $assert($after_assignment > $before_assignment, 'Assigning a projected taxonomy did not advance the catalogue cursor.');

        $before_rename = $after_assignment;
        $renamed = wp_update_term($term_id, $fixture['taxonomy'], array(
            'name' => 'TSOL renamed catalogue event term ' . $index . ' ' . wp_generate_password(8, false, false),
        ));
        $assert(!is_wp_error($renamed), 'Could not rename the disposable projected taxonomy term.');
        $after_rename = TSOL_Library_Content_Changes::current_cursor();
        $assert($after_rename > $before_rename, 'Renaming an assigned projected taxonomy did not advance the catalogue cursor.');

        $latest_rename = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT post_id, action FROM ' . $changes_table . ' WHERE id > %d AND post_id = %d ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
                $before_rename,
                $post_id
            ),
            ARRAY_A
        );
        $assert(is_array($latest_rename) && 'upsert' === $latest_rename['action'], 'A projected taxonomy rename did not journal its assigned record as an upsert.');

        $before_delete = $after_rename;
        $deleted = wp_delete_term($term_id, $fixture['taxonomy']);
        $assert(!is_wp_error($deleted) && false !== $deleted, 'Could not delete the disposable projected taxonomy term.');
        $after_delete = TSOL_Library_Content_Changes::current_cursor();
        $assert($after_delete > $before_delete, 'Deleting an assigned projected taxonomy did not advance the catalogue cursor.');
        array_pop($created_term_ids);

        $latest_delete = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT post_id, action FROM ' . $changes_table . ' WHERE id > %d AND post_id = %d ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
                $before_delete,
                $post_id
            ),
            ARRAY_A
        );
        $assert(is_array($latest_delete) && 'upsert' === $latest_delete['action'], 'A projected taxonomy deletion did not journal its assigned record as an upsert.');
    }
} finally {
    foreach ($created_term_ids as $term_fixture) {
        wp_delete_term((int) $term_fixture[0], (string) $term_fixture[1]);
    }
    foreach ($created_post_ids as $post_id) {
        wp_delete_post((int) $post_id, true);
    }
}

if (!empty($failures)) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error('Liberty Classroom Library catalogue change-event contract failed with ' . count($failures) . ' issue(s).');
}

WP_CLI::success('Liberty Classroom Library catalogue change-event contract passed.');
