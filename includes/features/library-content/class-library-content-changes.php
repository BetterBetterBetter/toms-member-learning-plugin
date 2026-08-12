<?php
/**
 * Durable, monotonic change cursor for the derived Library catalogue.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Content_Changes {

    const SCHEMA_VERSION = '20260809.1';
    const SCHEMA_OPTION = 'tsol_library_content_changes_schema_version';

    private static $recording = false;
    private static $deleting_speaker_content_ids = array();

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tsol_library_content_changes';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            action varchar(20) NOT NULL,
            changed_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY post_id (post_id),
            KEY changed_at (changed_at)
        ) {$charset_collate};";

        dbDelta($sql);
        update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
    }

    public static function maybe_install() {
        if (get_option(self::SCHEMA_OPTION) !== self::SCHEMA_VERSION) {
            self::install();
        }
    }

    public static function register_hooks() {
        add_action('save_post', array(__CLASS__, 'record_saved_post'), 100, 3);
        add_action('transition_post_status', array(__CLASS__, 'record_status_change'), 100, 3);
        add_action('before_delete_post', array(__CLASS__, 'record_deleted_post'), 10, 2);
        add_action('deleted_post', array(__CLASS__, 'record_post_deleted'), 10, 2);
        add_action('set_object_terms', array(__CLASS__, 'record_term_change'), 100, 6);
        add_action('added_post_meta', array(__CLASS__, 'record_meta_change'), 100, 4);
        add_action('updated_post_meta', array(__CLASS__, 'record_meta_change'), 100, 4);
        add_action('deleted_post_meta', array(__CLASS__, 'record_meta_change'), 100, 4);
        add_action('tsol_library_content_changed', array(__CLASS__, 'record_current_state'), 10, 1);
    }

    public static function current_cursor() {
        global $wpdb;
        return (int) $wpdb->get_var('SELECT COALESCE(MAX(id), 0) FROM ' . self::table()); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
    }

    public static function after($cursor, $limit) {
        global $wpdb;

        $cursor = max(0, (int) $cursor);
        $limit = min(200, max(1, (int) $limit));
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, post_id, action, changed_at FROM ' . self::table() . ' WHERE id > %d ORDER BY id ASC LIMIT %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
                $cursor,
                $limit
            ),
            ARRAY_A
        );
    }

    public static function record_saved_post($post_id, $post, $update) {
        unset($update);
        if ($post instanceof WP_Post) {
            if (TSOL_Library_Content_Model::SPEAKER_POST_TYPE === $post->post_type) {
                self::record_speaker_content((int) $post_id);
                return;
            }
            self::record_post_state((int) $post_id, $post);
        }
    }

    public static function record_status_change($new_status, $old_status, $post) {
        unset($new_status, $old_status);
        if ($post instanceof WP_Post) {
            if (TSOL_Library_Content_Model::SPEAKER_POST_TYPE === $post->post_type) {
                self::record_speaker_content((int) $post->ID);
                return;
            }
            self::record_post_state((int) $post->ID, $post);
        }
    }

    public static function record_deleted_post($post_id, $post) {
        if (!$post instanceof WP_Post) {
            return;
        }
        if (TSOL_Library_Content_Model::SPEAKER_POST_TYPE === $post->post_type) {
            $content_ids = self::speaker_content_ids((int) $post_id);
            foreach ($content_ids as $content_id) {
                $content_ids = array_merge($content_ids, self::inheriting_child_ids((int) $content_id));
            }
            self::$deleting_speaker_content_ids[(int) $post_id] = array_values(array_unique(array_map('intval', $content_ids)));
            return;
        }
        if (self::has_library_identity($post)) {
            self::record((int) $post_id, 'delete');
        }
    }

    public static function record_post_deleted($post_id, $post) {
        if (!$post instanceof WP_Post || TSOL_Library_Content_Model::SPEAKER_POST_TYPE !== $post->post_type) {
            return;
        }
        $content_ids = self::$deleting_speaker_content_ids[(int) $post_id] ?? array();
        unset(self::$deleting_speaker_content_ids[(int) $post_id]);
        foreach ($content_ids as $content_id) {
            self::record_current_state((int) $content_id);
        }
    }

    public static function record_term_change($object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids) {
        unset($terms, $tt_ids, $append, $old_tt_ids);
        if (in_array((string) $taxonomy, array(
            TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY,
            TSOL_Library_Content_Model::TOPIC_TAXONOMY,
        ), true)) {
            self::record_current_state((int) $object_id);
        }
    }

    public static function record_meta_change($meta_id, $post_id, $meta_key, $meta_value) {
        unset($meta_id, $meta_value);
        if (in_array((string) $meta_key, TSOL_Library_Content_Model::metadata_keys(), true)) {
            self::record_current_state((int) $post_id);
            if (TSOL_Library_Content_Model::META_SPEAKER_IDS === (string) $meta_key) {
                foreach (self::inheriting_child_ids((int) $post_id) as $child_id) {
                    self::record_current_state($child_id);
                }
            }
            return;
        }
        if (TSOL_Library_Content_Model::SPEAKER_POST_TYPE === get_post_type((int) $post_id)
            && in_array((string) $meta_key, TSOL_Library_Content_Model::speaker_metadata_keys(), true)
        ) {
            self::record_speaker_content((int) $post_id);
        }
    }

    public static function record_speaker_content($speaker_id) {
        $content_ids = self::speaker_content_ids((int) $speaker_id);
        foreach ($content_ids as $content_id) {
            self::record_current_state((int) $content_id);
            foreach (self::inheriting_child_ids((int) $content_id) as $child_id) {
                self::record_current_state($child_id);
            }
        }
    }

    public static function record_current_state($post_id) {
        $post = get_post((int) $post_id);
        if ($post instanceof WP_Post) {
            self::record_post_state((int) $post_id, $post);
        }
    }

    private static function record_post_state($post_id, WP_Post $post) {
        if (!self::has_library_identity($post)) {
            return;
        }
        self::record($post_id, TSOL_Library_Content_Catalogue::is_exportable_post($post) ? 'upsert' : 'delete');
    }

    private static function has_library_identity(WP_Post $post) {
        if (!in_array((string) $post->post_type, TSOL_Library_Content_Model::post_types(), true)) {
            return false;
        }

        return (string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_UUID, true) !== '';
    }

    private static function speaker_content_ids($speaker_id) {
        if ($speaker_id <= 0) {
            return array();
        }
        return array_values(array_unique(array_map('intval', get_posts(array(
            'post_type' => TSOL_Library_Content_Model::post_types(),
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => TSOL_Library_Content_Model::META_SPEAKER_IDS,
            'meta_value' => $speaker_id,
            'suppress_filters' => true,
        )))));
    }

    private static function inheriting_child_ids($parent_id) {
        $parent_type = get_post_type((int) $parent_id);
        $meta_key = TSOL_Library_Content_Model::COURSE_POST_TYPE === $parent_type
            ? TSOL_Library_Content_Model::META_COURSE_ID
            : (TSOL_Library_Content_Model::SERIES_POST_TYPE === $parent_type
                ? TSOL_Library_Content_Model::META_SERIES_ID
                : '');
        if ('' === $meta_key) {
            return array();
        }

        $child_ids = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => $meta_key,
            'meta_value' => (int) $parent_id,
            'suppress_filters' => true,
        ));

        return array_values(array_filter(array_map('intval', $child_ids), static function ($child_id) {
            return TSOL_Library_Content_Model::SPEAKER_MODE_INHERIT === TSOL_Library_Content_Model::speaker_mode($child_id);
        }));
    }

    private static function record($post_id, $action) {
        global $wpdb;

        if (self::$recording || $post_id <= 0 || !in_array($action, array('upsert', 'delete'), true)) {
            return;
        }

        self::$recording = true;
        try {
            $inserted = $wpdb->insert(
                self::table(),
                array(
                    'post_id' => (int) $post_id,
                    'action' => $action,
                    'changed_at' => current_time('mysql', true),
                ),
                array('%d', '%s', '%s')
            );
            $cursor = (int) $wpdb->insert_id;
        } finally {
            self::$recording = false;
        }

        if (false !== $inserted && $cursor > 0) {
            do_action('tsol_library_catalogue_change_recorded', $cursor);
        }
    }
}
