<?php
/**
 * Private WordPress editorial model for School announcements.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Announcement_Model {

    const POST_TYPE = 'tsol_announcement';
    const CAP_EDIT = 'edit_tsol_announcements';
    const CAP_PUBLISH = 'publish_tsol_announcements';
    const CAP_SCHEDULE = 'schedule_tsol_announcements';
    const CAP_MANAGE_AUDIENCE = 'manage_tsol_announcement_audiences';
    const CAP_VIEW_DELIVERY = 'view_tsol_announcement_delivery';
    const CAPABILITY_VERSION = '20260814.1';
    const CAPABILITY_OPTION = 'tsol_library_announcement_capability_version';

    const META_SUMMARY = '_tsol_announcement_summary';
    const META_DESTINATION_TYPE = '_tsol_announcement_destination_type';
    const META_DESTINATION_ID = '_tsol_announcement_destination_id';
    const META_DESTINATION_UUID = '_tsol_announcement_destination_uuid';
    const META_AUDIENCE_PRESET = '_tsol_announcement_audience_preset';
    const META_AUDIENCE = '_tsol_announcement_audience';
    const META_AUDIENCE_HASH = '_tsol_announcement_audience_hash';
    const META_AUDIENCE_SUMMARY = '_tsol_announcement_audience_summary';
    const META_EXPIRY_GMT = '_tsol_announcement_expiry_gmt';
    const META_UPDATED_BY = '_tsol_announcement_updated_by';
    const META_PREVIEW = '_tsol_announcement_preview';
    const META_AUDIT = '_tsol_announcement_audit';

    const MAX_SUBJECT_LENGTH = 160;
    const MAX_SUMMARY_LENGTH = 500;
    const MAX_BODY_LENGTH = 5000;

    private static $request_notices = array();

    public static function register() {
        register_post_type(self::POST_TYPE, array(
            'labels' => array(
                'name' => __('Announcements', 'member-library'),
                'singular_name' => __('Announcement', 'member-library'),
                'add_new' => __('Add announcement', 'member-library'),
                'add_new_item' => __('Add announcement', 'member-library'),
                'edit_item' => __('Edit announcement', 'member-library'),
                'new_item' => __('New announcement', 'member-library'),
                'search_items' => __('Search announcements', 'member-library'),
                'not_found' => __('No announcements found.', 'member-library'),
                'not_found_in_trash' => __('No announcements found in Trash.', 'member-library'),
                'all_items' => __('Announcements', 'member-library'),
                'menu_name' => __('Announcements', 'member-library'),
            ),
            'public' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'show_ui' => MemberLibrary_Announcement_Flags::admin_enabled(),
            'show_in_menu' => MemberLibrary_Announcement_Flags::admin_enabled() ? 'tsol-library' : false,
            'show_in_admin_bar' => false,
            'show_in_nav_menus' => false,
            'show_in_rest' => false,
            'query_var' => false,
            'rewrite' => false,
            'has_archive' => false,
            'can_export' => false,
            'supports' => array('title', 'author', 'revisions'),
            'capabilities' => self::post_type_capabilities(),
            'map_meta_cap' => false,
            'delete_with_user' => false,
            'menu_icon' => 'dashicons-megaphone',
        ));

        foreach (self::registered_meta() as $meta_key => $type) {
            register_post_meta(self::POST_TYPE, $meta_key, array(
                'type' => $type,
                'single' => true,
                'show_in_rest' => false,
                'auth_callback' => static function () {
                    return current_user_can(self::CAP_EDIT);
                },
            ));
        }
    }

    public static function maybe_install_capabilities() {
        if (self::CAPABILITY_VERSION === get_option(self::CAPABILITY_OPTION)) {
            return;
        }
        self::install_capabilities();
    }

    public static function install_capabilities() {
        $editor = get_role('editor');
        if ($editor instanceof WP_Role) {
            $editor->add_cap(self::CAP_EDIT);
        }

        $administrator = get_role('administrator');
        if ($administrator instanceof WP_Role) {
            foreach (self::dedicated_capabilities() as $capability) {
                $administrator->add_cap($capability);
            }
        }
        update_option(self::CAPABILITY_OPTION, self::CAPABILITY_VERSION, false);
    }

    public static function filter_post_data($data, $postarr) {
        if (self::POST_TYPE !== (string) ($data['post_type'] ?? '')) {
            return $data;
        }

        if (array_key_exists('post_title', $data)) {
            $title = sanitize_text_field(wp_unslash($data['post_title']));
            if (self::text_length($title) > self::MAX_SUBJECT_LENGTH) {
                $title = self::text_slice($title, self::MAX_SUBJECT_LENGTH);
                self::queue_notice('error', __('The subject was limited to 160 characters.', 'member-library'));
            }
            $data['post_title'] = wp_slash($title);
        }

        if (array_key_exists('post_content', $data)) {
            $body = self::sanitize_body(wp_unslash($data['post_content']));
            if (self::text_length(wp_strip_all_tags($body)) > self::MAX_BODY_LENGTH) {
                $post_id = isset($postarr['ID']) ? absint($postarr['ID']) : 0;
                $previous = $post_id > 0 ? (string) get_post_field('post_content', $post_id) : '';
                $body = self::sanitize_body($previous);
                self::queue_notice('error', __('The body was not changed because it exceeds 5,000 characters.', 'member-library'));
            }
            $data['post_content'] = wp_slash($body);
        }

        if (in_array((string) ($data['post_status'] ?? ''), array('publish', 'future', 'private'), true)
            && !MemberLibrary_Announcement_Flags::publish_enabled()) {
            $data['post_status'] = 'draft';
            self::queue_notice('warning', __('Publishing and scheduling are disabled. The announcement remains a draft.', 'member-library'));
        }

        return $data;
    }

    public static function revision_meta_keys($keys) {
        return array_values(array_unique(array_merge((array) $keys, array(
            self::META_SUMMARY,
            self::META_DESTINATION_TYPE,
            self::META_DESTINATION_ID,
            self::META_DESTINATION_UUID,
            self::META_AUDIENCE_PRESET,
            self::META_AUDIENCE,
            self::META_AUDIENCE_HASH,
            self::META_AUDIENCE_SUMMARY,
            self::META_EXPIRY_GMT,
        ))));
    }

    public static function sanitize_body($value) {
        $source = strip_shortcodes((string) $value);
        $source = preg_replace('/<!--.*?-->/s', '', $source);
        $source = preg_replace('#<(script|style|iframe|video|audio|object|svg|math|canvas|template|noscript|form)\b[^>]*>.*?</\1\s*>#is', '', (string) $source);
        $source = preg_replace('~<(\/?)h1\b[^>]*>~i', '<$1h2>', (string) $source);
        $source = preg_replace('~<(\/?)h[4-6]\b[^>]*>~i', '<$1h3>', (string) $source);
        return trim((string) wp_kses(wpautop((string) $source), array(
            'p' => array(),
            'br' => array(),
            'h2' => array(),
            'h3' => array(),
            'ul' => array(),
            'ol' => array(),
            'li' => array(),
            'strong' => array(),
            'b' => array(),
            'em' => array(),
            'i' => array(),
            'blockquote' => array(),
        )));
    }

    public static function text_length($value) {
        return function_exists('mb_strlen') ? mb_strlen((string) $value) : strlen((string) $value);
    }

    public static function text_slice($value, $length) {
        return function_exists('mb_substr') ? mb_substr((string) $value, 0, $length) : substr((string) $value, 0, $length);
    }

    public static function queue_notice($type, $message) {
        $type = in_array($type, array('error', 'warning', 'success', 'info'), true) ? $type : 'info';
        self::$request_notices[] = array('type' => $type, 'message' => sanitize_text_field($message));
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            set_transient('tsol_announcement_notices_' . $user_id, self::$request_notices, MINUTE_IN_SECONDS);
        }
    }

    public static function pull_notices() {
        $notices = self::$request_notices;
        $user_id = get_current_user_id();
        if ($user_id > 0) {
            $stored = get_transient('tsol_announcement_notices_' . $user_id);
            delete_transient('tsol_announcement_notices_' . $user_id);
            if (is_array($stored)) {
                $notices = array_merge($notices, $stored);
            }
        }
        self::$request_notices = array();
        return array_values(array_unique($notices, SORT_REGULAR));
    }

    public static function dedicated_capabilities() {
        return array(self::CAP_EDIT, self::CAP_PUBLISH, self::CAP_SCHEDULE, self::CAP_MANAGE_AUDIENCE, self::CAP_VIEW_DELIVERY);
    }

    private static function post_type_capabilities() {
        return array(
            'edit_post' => self::CAP_EDIT,
            'read_post' => self::CAP_EDIT,
            'delete_post' => self::CAP_EDIT,
            'edit_posts' => self::CAP_EDIT,
            'edit_others_posts' => self::CAP_EDIT,
            'delete_posts' => self::CAP_EDIT,
            'delete_others_posts' => self::CAP_EDIT,
            'delete_private_posts' => self::CAP_PUBLISH,
            'delete_published_posts' => self::CAP_PUBLISH,
            'edit_private_posts' => self::CAP_EDIT,
            'edit_published_posts' => self::CAP_EDIT,
            'publish_posts' => self::CAP_PUBLISH,
            'read_private_posts' => self::CAP_EDIT,
            'create_posts' => self::CAP_EDIT,
        );
    }

    private static function registered_meta() {
        return array(
            self::META_SUMMARY => 'string',
            self::META_DESTINATION_TYPE => 'string',
            self::META_DESTINATION_ID => 'integer',
            self::META_DESTINATION_UUID => 'string',
            self::META_AUDIENCE_PRESET => 'string',
            self::META_AUDIENCE => 'string',
            self::META_AUDIENCE_HASH => 'string',
            self::META_AUDIENCE_SUMMARY => 'string',
            self::META_EXPIRY_GMT => 'string',
            self::META_UPDATED_BY => 'integer',
            self::META_PREVIEW => 'object',
            self::META_AUDIT => 'array',
        );
    }
}
