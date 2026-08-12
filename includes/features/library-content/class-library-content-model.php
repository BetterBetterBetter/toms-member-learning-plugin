<?php
/**
 * Canonical WordPress content types and metadata for the TSOL Library.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Content_Model {

    const COURSE_POST_TYPE = 'tsol_library_course';
    const SERIES_POST_TYPE = 'tsol_library_series';
    const ITEM_POST_TYPE = 'tsol_library_item';
    const COURSE_COLLECTION_TAXONOMY = 'tsol_course_collection';
    const TOPIC_TAXONOMY = 'tsol_topic';
    const SPEAKER_POST_TYPE = 'tsol_library_speaker';
    const SPEAKER_IMAGE_SIZE = 'tsol-library-speaker-headshot';

    // Compatibility marker retained for already-applied importer state. The
    // dedicated Library post types now establish catalogue identity directly;
    // this value is never an editable or runtime inclusion gate.
    const META_INCLUDE = '_tsol_library_include';
    const META_CONTENT_TYPE = '_tsol_library_content_type';
    const META_POSITION = '_tsol_library_position';
    const META_FEATURED = '_tsol_library_featured';
    const META_CURRENT = '_tsol_library_current';
    const META_MEDIA_ASSETS = '_tsol_library_media_assets';
    const META_RESOURCES = '_tsol_library_resources';
    const META_MIGRATION_KEY = '_tsol_library_migration_key';
    const META_MIGRATION_VERSION = '_tsol_library_migration_version';
    const META_LEGACY_SOURCE_ID = '_tsol_library_legacy_source_post_id';
    const META_LEGACY_SOURCE_TYPE = '_tsol_library_legacy_source_post_type';
    const META_AUTHORIZATION_POST_ID = '_tsol_library_authorization_post_id';
    const META_SOURCE_MODIFIED_GMT = '_tsol_library_source_modified_gmt';
    const META_CONTENT_FINGERPRINT = '_tsol_library_content_fingerprint';
    const META_UUID = '_tsol_library_uuid';
    const META_COURSE_ID = '_tsol_library_course_id';
    const META_SERIES_ID = '_tsol_library_series_id';
    const META_SERIES_GROUP_KEY = '_tsol_library_series_group_key';
    const META_SERIES_GROUP_TITLE = '_tsol_library_series_group_title';
    const META_SERIES_GROUP_POSITION = '_tsol_library_series_group_position';
    const META_SERIES_ITEM_LABEL = '_tsol_library_series_item_label';
    const META_SERIES_ITEM_LABEL_PLURAL = '_tsol_library_series_item_label_plural';
    const META_SERIES_SORT = '_tsol_library_series_sort';
    const META_SERIES_ONGOING = '_tsol_library_series_ongoing';
    const META_SECTION_KEY = '_tsol_library_section_key';
    const META_SECTION_TITLE = '_tsol_library_section_title';
    const META_SECTION_POSITION = '_tsol_library_section_position';
    const META_COURSE_SECTIONS = '_tsol_library_course_sections';
    const META_SERIES_GROUPS = '_tsol_library_series_groups';
    const META_SPEAKER_IDS = '_tsol_library_speaker_id';
    const META_SPEAKER_MODE = '_tsol_library_speaker_mode';

    const SPEAKER_MODE_INHERIT = 'inherit';
    const SPEAKER_MODE_DIRECT = 'direct';
    const SPEAKER_MODE_NONE = 'none';

    const SPEAKER_META_UUID = '_tsol_library_speaker_uuid';
    const SPEAKER_META_JOB_TITLE = '_tsol_library_speaker_job_title';
    const SPEAKER_META_ORGANIZATION = '_tsol_library_speaker_organization';
    const SPEAKER_META_WEBSITE_URL = '_tsol_library_speaker_website_url';
    const SPEAKER_META_SOCIAL_LINKS = '_tsol_library_speaker_social_links';

    public static function register() {
        add_image_size(self::SPEAKER_IMAGE_SIZE, 640, 640, true);
        self::register_post_types();
        self::register_taxonomies();
        self::register_metadata();
    }

    public static function post_types() {
        return array(self::COURSE_POST_TYPE, self::SERIES_POST_TYPE, self::ITEM_POST_TYPE);
    }

    public static function metadata_keys() {
        return array(
            self::META_INCLUDE,
            self::META_CONTENT_TYPE,
            self::META_POSITION,
            self::META_FEATURED,
            self::META_CURRENT,
            self::META_MEDIA_ASSETS,
            self::META_RESOURCES,
            self::META_MIGRATION_KEY,
            self::META_MIGRATION_VERSION,
            self::META_LEGACY_SOURCE_ID,
            self::META_LEGACY_SOURCE_TYPE,
            self::META_AUTHORIZATION_POST_ID,
            self::META_SOURCE_MODIFIED_GMT,
            self::META_CONTENT_FINGERPRINT,
            self::META_UUID,
            self::META_COURSE_ID,
            self::META_SERIES_ID,
            self::META_SERIES_GROUP_KEY,
            self::META_SERIES_GROUP_TITLE,
            self::META_SERIES_GROUP_POSITION,
            self::META_SERIES_ITEM_LABEL,
            self::META_SERIES_ITEM_LABEL_PLURAL,
            self::META_SERIES_SORT,
            self::META_SERIES_ONGOING,
            self::META_SECTION_KEY,
            self::META_SECTION_TITLE,
            self::META_SECTION_POSITION,
            self::META_COURSE_SECTIONS,
            self::META_SERIES_GROUPS,
            self::META_SPEAKER_IDS,
            self::META_SPEAKER_MODE,
        );
    }

    /**
     * Return only the metadata contract registered for one Library content
     * type. The union returned by metadata_keys() remains useful to change
     * tracking and legacy-isolation checks.
     */
    public static function metadata_keys_for_post_type($post_type) {
        $keys = array_values(array_diff(self::metadata_keys(), array(
            self::META_COURSE_SECTIONS,
            self::META_SERIES_GROUPS,
        )));
        if (self::COURSE_POST_TYPE === $post_type) {
            $keys[] = self::META_COURSE_SECTIONS;
        } elseif (self::SERIES_POST_TYPE === $post_type) {
            $keys[] = self::META_SERIES_GROUPS;
        }
        return $keys;
    }

    public static function direct_speaker_ids($post_id) {
        return array_values(array_unique(array_filter(array_map(
            'absint',
            get_post_meta((int) $post_id, self::META_SPEAKER_IDS, false)
        ))));
    }

    public static function speaker_parent($post_id) {
        if (self::ITEM_POST_TYPE !== get_post_type((int) $post_id)) {
            return null;
        }

        $course_id = (int) get_post_meta((int) $post_id, self::META_COURSE_ID, true);
        if ($course_id > 0 && self::COURSE_POST_TYPE === get_post_type($course_id)) {
            return array(
                'id' => $course_id,
                'source' => 'course',
                'label' => __('Course', 'tomschooloflife-plugin'),
            );
        }

        $series_id = (int) get_post_meta((int) $post_id, self::META_SERIES_ID, true);
        if ($series_id > 0 && self::SERIES_POST_TYPE === get_post_type($series_id)) {
            return array(
                'id' => $series_id,
                'source' => 'series',
                'label' => __('Series', 'tomschooloflife-plugin'),
            );
        }

        return null;
    }

    public static function speaker_mode($post_id) {
        if (self::ITEM_POST_TYPE !== get_post_type((int) $post_id)) {
            return self::SPEAKER_MODE_DIRECT;
        }

        $stored = sanitize_key((string) get_post_meta((int) $post_id, self::META_SPEAKER_MODE, true));
        if (in_array($stored, array(self::SPEAKER_MODE_INHERIT, self::SPEAKER_MODE_DIRECT, self::SPEAKER_MODE_NONE), true)) {
            return $stored;
        }
        if (!empty(self::direct_speaker_ids($post_id))) {
            return self::SPEAKER_MODE_DIRECT;
        }
        return null !== self::speaker_parent($post_id)
            ? self::SPEAKER_MODE_INHERIT
            : self::SPEAKER_MODE_NONE;
    }

    public static function effective_speaker_context($post_id) {
        $post_id = (int) $post_id;
        $mode = self::speaker_mode($post_id);
        $parent = self::speaker_parent($post_id);

        if (self::ITEM_POST_TYPE === get_post_type($post_id)
            && self::SPEAKER_MODE_INHERIT === $mode
            && is_array($parent)
        ) {
            return array(
                'mode' => $mode,
                'source' => (string) $parent['source'],
                'parent_id' => (int) $parent['id'],
                'parent_label' => (string) $parent['label'],
                'speaker_ids' => self::direct_speaker_ids((int) $parent['id']),
            );
        }

        if (self::SPEAKER_MODE_DIRECT === $mode) {
            return array(
                'mode' => $mode,
                'source' => 'direct',
                'parent_id' => is_array($parent) ? (int) $parent['id'] : 0,
                'parent_label' => is_array($parent) ? (string) $parent['label'] : '',
                'speaker_ids' => self::direct_speaker_ids($post_id),
            );
        }

        return array(
            'mode' => self::SPEAKER_MODE_NONE,
            'source' => 'none',
            'parent_id' => is_array($parent) ? (int) $parent['id'] : 0,
            'parent_label' => is_array($parent) ? (string) $parent['label'] : '',
            'speaker_ids' => array(),
        );
    }

    public static function speaker_metadata_keys() {
        return array(
            self::SPEAKER_META_UUID,
            self::SPEAKER_META_JOB_TITLE,
            self::SPEAKER_META_ORGANIZATION,
            self::SPEAKER_META_WEBSITE_URL,
            self::SPEAKER_META_SOCIAL_LINKS,
            '_thumbnail_id',
        );
    }

    public static function speaker_social_platforms() {
        return array(
            'linkedin' => __('LinkedIn', 'tomschooloflife-plugin'),
            'x' => __('X / Twitter', 'tomschooloflife-plugin'),
            'youtube' => __('YouTube', 'tomschooloflife-plugin'),
            'instagram' => __('Instagram', 'tomschooloflife-plugin'),
            'facebook' => __('Facebook', 'tomschooloflife-plugin'),
            'tiktok' => __('TikTok', 'tomschooloflife-plugin'),
            'bluesky' => __('Bluesky', 'tomschooloflife-plugin'),
            'podcast' => __('Podcast', 'tomschooloflife-plugin'),
            'other' => __('Other', 'tomschooloflife-plugin'),
        );
    }

    public static function sanitize_speaker_image_id($value) {
        $attachment_id = absint($value);
        if ($attachment_id <= 0 || 'attachment' !== get_post_type($attachment_id)) {
            return 0;
        }

        $mime_type = (string) get_post_mime_type($attachment_id);
        return 0 === strpos($mime_type, 'image/') ? $attachment_id : 0;
    }

    public static function ensure_speaker_image_size($attachment_id) {
        $attachment_id = self::sanitize_speaker_image_id($attachment_id);
        if ($attachment_id <= 0) {
            return false;
        }

        $metadata = wp_get_attachment_metadata($attachment_id);
        if (!is_array($metadata)) {
            return false;
        }
        if (!empty($metadata['sizes'][self::SPEAKER_IMAGE_SIZE]['file'])) {
            return true;
        }

        $width = absint($metadata['width'] ?? 0);
        $height = absint($metadata['height'] ?? 0);
        if ($width > 0 && $width === $height && $width <= 640) {
            return true;
        }

        $file = get_attached_file($attachment_id);
        $edge = min(640, $width, $height);
        if (!is_string($file) || !is_file($file) || $edge <= 0) {
            return false;
        }

        $square = image_make_intermediate_size($file, $edge, $edge, true);
        if (!is_array($square) || empty($square['file'])) {
            return false;
        }
        if (!isset($metadata['sizes']) || !is_array($metadata['sizes'])) {
            $metadata['sizes'] = array();
        }
        $metadata['sizes'][self::SPEAKER_IMAGE_SIZE] = $square;
        wp_update_attachment_metadata($attachment_id, $metadata);

        $updated = wp_get_attachment_metadata($attachment_id);
        return is_array($updated) && !empty($updated['sizes'][self::SPEAKER_IMAGE_SIZE]['file']);
    }

    public static function speaker_image_display_size($attachment_id) {
        $attachment_id = self::sanitize_speaker_image_id($attachment_id);
        $metadata = $attachment_id > 0 ? wp_get_attachment_metadata($attachment_id) : array();
        if (is_array($metadata) && !empty($metadata['sizes'][self::SPEAKER_IMAGE_SIZE]['file'])) {
            return self::SPEAKER_IMAGE_SIZE;
        }
        if (is_array($metadata)
            && absint($metadata['width'] ?? 0) > 0
            && absint($metadata['width'] ?? 0) === absint($metadata['height'] ?? 0)
        ) {
            return 'full';
        }
        return 'thumbnail';
    }

    public static function sanitize_speaker_url($value) {
        $raw_url = trim((string) $value);
        $raw_parts = wp_parse_url($raw_url);
        if (!is_array($raw_parts)
            || empty($raw_parts['host'])
            || !isset($raw_parts['scheme'])
            || !in_array(strtolower((string) $raw_parts['scheme']), array('http', 'https'), true)
        ) {
            return '';
        }

        $url = esc_url_raw($raw_url, array('http', 'https'));
        $parts = wp_parse_url($url);
        return is_array($parts)
            && !empty($parts['host'])
            && isset($parts['scheme'])
            && in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true)
                ? $url
                : '';
    }

    public static function sanitize_speaker_social_links($value) {
        if (!is_array($value)) {
            return array();
        }

        $platforms = self::speaker_social_platforms();
        $links = array();
        foreach (array_values($value) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $url = self::sanitize_speaker_url($row['url'] ?? '');
            if ('' === $url) {
                continue;
            }
            $platform = sanitize_key((string) ($row['platform'] ?? 'other'));
            if (!isset($platforms[$platform])) {
                $platform = 'other';
            }
            $links[] = array(
                'platform' => $platform,
                'url' => $url,
            );
        }

        return $links;
    }

    public static function sanitize_media_assets($value) {
        if (!is_array($value)) {
            return array();
        }

        $assets = array();
        foreach ($value as $index => $asset) {
            if (!is_array($asset)) {
                continue;
            }

            $normalized = TSOL_Library_Media_Normalizer::normalize_asset($asset, $index + 1);
            if (is_wp_error($normalized)) {
                continue;
            }
            $assets[] = $normalized;
        }

        usort($assets, static function ($left, $right) {
            return $left['position'] <=> $right['position'];
        });

        return array_values($assets);
    }

    public static function sanitize_resources($value) {
        if (!is_array($value)) {
            return array();
        }

        $resources = array();
        foreach ($value as $index => $resource) {
            if (!is_array($resource)) {
                continue;
            }

            $type = isset($resource['type']) ? sanitize_key($resource['type']) : 'link';
            if (!in_array($type, array('link', 'download'), true)) {
                $type = 'link';
            }

            $resources[] = array(
                'key' => !empty($resource['key']) ? sanitize_key($resource['key']) : 'resource-' . ($index + 1),
                'type' => $type,
                'label' => isset($resource['label']) ? sanitize_text_field($resource['label']) : '',
                'url' => isset($resource['url']) ? esc_url_raw($resource['url']) : '',
                'attachment_id' => isset($resource['attachment_id']) ? absint($resource['attachment_id']) : 0,
                'position' => isset($resource['position']) ? absint($resource['position']) : ($index + 1),
            );
        }

        usort($resources, static function ($left, $right) {
            return $left['position'] <=> $right['position'];
        });

        return array_values($resources);
    }

    /**
     * Make the private TSOL content types available as native MemberPress Rule
     * targets without making them publicly queryable in WordPress.
     */
    public static function add_memberpress_rule_post_types($post_types) {
        foreach (self::post_types() as $post_type) {
            $object = get_post_type_object($post_type);
            if ($object instanceof WP_Post_Type) {
                $post_types[$post_type] = $object;
            }
        }
        return $post_types;
    }

    private static function register_post_types() {
        register_post_type(self::COURSE_POST_TYPE, array(
            'labels' => array(
                'name' => __('Library Courses', 'tomschooloflife-plugin'),
                'singular_name' => __('Library Course', 'tomschooloflife-plugin'),
                'menu_name' => __('Courses', 'tomschooloflife-plugin'),
                'add_new_item' => __('Add New Library Course', 'tomschooloflife-plugin'),
                'edit_item' => __('Edit Library Course', 'tomschooloflife-plugin'),
                'new_item' => __('New Library Course', 'tomschooloflife-plugin'),
                'view_item' => __('Preview Library Course', 'tomschooloflife-plugin'),
                'search_items' => __('Search Library Courses', 'tomschooloflife-plugin'),
                'not_found' => __('No Library Courses found.', 'tomschooloflife-plugin'),
                'not_found_in_trash' => __('No Library Courses found in Trash.', 'tomschooloflife-plugin'),
            ),
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => TSOL_Library_Admin_Navigation::MENU_SLUG,
            'show_in_rest' => false,
            'exclude_from_search' => true,
            'has_archive' => false,
            'hierarchical' => false,
            'capability_type' => 'page',
            'map_meta_cap' => true,
            'rewrite' => false,
            'query_var' => false,
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'revisions'),
            'taxonomies' => array(self::COURSE_COLLECTION_TAXONOMY, self::TOPIC_TAXONOMY),
        ));

        register_post_type(self::SERIES_POST_TYPE, array(
            'labels' => array(
                'name' => __('Library Series', 'tomschooloflife-plugin'),
                'singular_name' => __('Library Series', 'tomschooloflife-plugin'),
                'menu_name' => __('Series', 'tomschooloflife-plugin'),
                'add_new_item' => __('Add New Library Series', 'tomschooloflife-plugin'),
                'edit_item' => __('Edit Library Series', 'tomschooloflife-plugin'),
                'new_item' => __('New Library Series', 'tomschooloflife-plugin'),
                'view_item' => __('Preview Library Series', 'tomschooloflife-plugin'),
                'search_items' => __('Search Library Series', 'tomschooloflife-plugin'),
                'not_found' => __('No Library Series found.', 'tomschooloflife-plugin'),
                'not_found_in_trash' => __('No Library Series found in Trash.', 'tomschooloflife-plugin'),
            ),
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => TSOL_Library_Admin_Navigation::MENU_SLUG,
            'show_in_rest' => false,
            'exclude_from_search' => true,
            'has_archive' => false,
            'hierarchical' => false,
            'capability_type' => 'page',
            'map_meta_cap' => true,
            'rewrite' => false,
            'query_var' => false,
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'revisions'),
            'taxonomies' => array(self::TOPIC_TAXONOMY),
        ));

        register_post_type(self::ITEM_POST_TYPE, array(
            'labels' => array(
                'name' => __('Library Content', 'tomschooloflife-plugin'),
                'singular_name' => __('Library Content Item', 'tomschooloflife-plugin'),
                'menu_name' => __('Content', 'tomschooloflife-plugin'),
                'add_new_item' => __('Add New Library Item', 'tomschooloflife-plugin'),
                'edit_item' => __('Edit Library Item', 'tomschooloflife-plugin'),
                'new_item' => __('New Library Item', 'tomschooloflife-plugin'),
                'view_item' => __('View Library Item', 'tomschooloflife-plugin'),
                'search_items' => __('Search Library Items', 'tomschooloflife-plugin'),
                'not_found' => __('No Library Items found.', 'tomschooloflife-plugin'),
                'not_found_in_trash' => __('No Library Items found in Trash.', 'tomschooloflife-plugin'),
            ),
            // Protected content is served by the authenticated Library contract,
            // never by an unauthenticated WordPress single-post route.
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => TSOL_Library_Admin_Navigation::MENU_SLUG,
            'show_in_rest' => false,
            'exclude_from_search' => true,
            'has_archive' => false,
            'hierarchical' => false,
            'capability_type' => 'page',
            'map_meta_cap' => true,
            'rewrite' => false,
            'query_var' => false,
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'revisions'),
            'taxonomies' => array(self::TOPIC_TAXONOMY),
        ));

        register_post_type(self::SPEAKER_POST_TYPE, array(
            'labels' => array(
                'name' => __('Library Speakers', 'tomschooloflife-plugin'),
                'singular_name' => __('Library Speaker', 'tomschooloflife-plugin'),
                'menu_name' => __('Speakers', 'tomschooloflife-plugin'),
                'add_new_item' => __('Add New Speaker', 'tomschooloflife-plugin'),
                'edit_item' => __('Edit Speaker', 'tomschooloflife-plugin'),
                'new_item' => __('New Speaker', 'tomschooloflife-plugin'),
                'view_item' => __('Preview Speaker', 'tomschooloflife-plugin'),
                'search_items' => __('Search Speakers', 'tomschooloflife-plugin'),
                'not_found' => __('No Speakers found.', 'tomschooloflife-plugin'),
                'not_found_in_trash' => __('No Speakers found in Trash.', 'tomschooloflife-plugin'),
                'featured_image' => __('Headshot', 'tomschooloflife-plugin'),
                'set_featured_image' => __('Choose headshot', 'tomschooloflife-plugin'),
                'remove_featured_image' => __('Remove headshot', 'tomschooloflife-plugin'),
                'use_featured_image' => __('Use as headshot', 'tomschooloflife-plugin'),
            ),
            'public' => false,
            'publicly_queryable' => false,
            'show_ui' => true,
            'show_in_menu' => TSOL_Library_Admin_Navigation::MENU_SLUG,
            'show_in_rest' => false,
            'exclude_from_search' => true,
            'has_archive' => false,
            'hierarchical' => false,
            'capability_type' => 'page',
            'map_meta_cap' => true,
            'rewrite' => false,
            'query_var' => false,
            'supports' => array('title', 'editor', 'thumbnail', 'revisions'),
        ));
    }

    private static function register_taxonomies() {
        register_taxonomy(self::COURSE_COLLECTION_TAXONOMY, array(self::COURSE_POST_TYPE), array(
            'labels' => array(
                'name' => __('Collections', 'tomschooloflife-plugin'),
                'singular_name' => __('Collection', 'tomschooloflife-plugin'),
                'menu_name' => __('Collections', 'tomschooloflife-plugin'),
                'all_items' => __('All Collections', 'tomschooloflife-plugin'),
                'edit_item' => __('Edit Collection', 'tomschooloflife-plugin'),
                'add_new_item' => __('Add New Collection', 'tomschooloflife-plugin'),
            ),
            // MemberPress discovers custom-taxonomy rule targets through the
            // public flag. The attached Course CPT remains private and this
            // taxonomy has no query variable, rewrite, REST, or frontend route.
            'public' => true,
            'publicly_queryable' => false,
            'query_var' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_admin_column' => true,
            'show_in_rest' => false,
            'hierarchical' => true,
            'rewrite' => false,
        ));

        register_taxonomy(self::TOPIC_TAXONOMY, self::post_types(), array(
            'labels' => array(
                'name' => __('Library Topics', 'tomschooloflife-plugin'),
                'singular_name' => __('Library Topic', 'tomschooloflife-plugin'),
            ),
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => false,
            'show_admin_column' => true,
            'show_in_rest' => false,
            'hierarchical' => false,
            'rewrite' => false,
        ));

    }

    private static function register_metadata() {
        foreach (self::post_types() as $post_type) {
            self::register_meta($post_type, self::META_INCLUDE, 'boolean', 'rest_sanitize_boolean');
            self::register_meta($post_type, self::META_CONTENT_TYPE, 'string', 'sanitize_key');
            self::register_meta($post_type, self::META_POSITION, 'integer', 'absint');
            self::register_meta($post_type, self::META_FEATURED, 'boolean', 'rest_sanitize_boolean');
            self::register_meta($post_type, self::META_CURRENT, 'boolean', 'rest_sanitize_boolean');
            self::register_meta($post_type, self::META_MEDIA_ASSETS, 'array', array(__CLASS__, 'sanitize_media_assets'));
            self::register_meta($post_type, self::META_RESOURCES, 'array', array(__CLASS__, 'sanitize_resources'));
            self::register_meta($post_type, self::META_MIGRATION_KEY, 'string', 'sanitize_key');
            self::register_meta($post_type, self::META_MIGRATION_VERSION, 'string', 'sanitize_text_field');
            self::register_meta($post_type, self::META_LEGACY_SOURCE_ID, 'integer', 'absint');
            self::register_meta($post_type, self::META_LEGACY_SOURCE_TYPE, 'string', 'sanitize_key');
            self::register_meta($post_type, self::META_AUTHORIZATION_POST_ID, 'integer', 'absint');
            self::register_meta($post_type, self::META_SOURCE_MODIFIED_GMT, 'string', 'sanitize_text_field');
            self::register_meta($post_type, self::META_CONTENT_FINGERPRINT, 'string', 'sanitize_text_field');
            self::register_meta($post_type, self::META_UUID, 'string', 'sanitize_text_field');
            self::register_meta($post_type, self::META_COURSE_ID, 'integer', 'absint');
            self::register_meta($post_type, self::META_SERIES_ID, 'integer', 'absint');
            self::register_meta($post_type, self::META_SERIES_GROUP_KEY, 'string', 'sanitize_key');
            self::register_meta($post_type, self::META_SERIES_GROUP_TITLE, 'string', 'sanitize_text_field');
            self::register_meta($post_type, self::META_SERIES_GROUP_POSITION, 'integer', 'absint');
            self::register_meta($post_type, self::META_SERIES_ITEM_LABEL, 'string', 'sanitize_text_field');
            self::register_meta($post_type, self::META_SERIES_ITEM_LABEL_PLURAL, 'string', 'sanitize_text_field');
            self::register_meta($post_type, self::META_SERIES_SORT, 'string', 'sanitize_key');
            self::register_meta($post_type, self::META_SERIES_ONGOING, 'boolean', 'rest_sanitize_boolean');
            self::register_meta($post_type, self::META_SECTION_KEY, 'string', 'sanitize_key');
            self::register_meta($post_type, self::META_SECTION_TITLE, 'string', 'sanitize_text_field');
            self::register_meta($post_type, self::META_SECTION_POSITION, 'integer', 'absint');
            self::register_meta($post_type, self::META_SPEAKER_MODE, 'string', 'sanitize_key');
            register_post_meta($post_type, self::META_SPEAKER_IDS, array(
                'type' => 'integer',
                'single' => false,
                'show_in_rest' => false,
                'sanitize_callback' => 'absint',
                'auth_callback' => static function ($allowed, $meta_key, $post_id) {
                    return current_user_can('edit_post', $post_id);
                },
            ));
        }

        self::register_meta(self::COURSE_POST_TYPE, self::META_COURSE_SECTIONS, 'array', array(__CLASS__, 'sanitize_structure_registry'));
        self::register_meta(self::SERIES_POST_TYPE, self::META_SERIES_GROUPS, 'array', array(__CLASS__, 'sanitize_structure_registry'));

        self::register_meta(self::SPEAKER_POST_TYPE, self::SPEAKER_META_UUID, 'string', 'sanitize_text_field');
        self::register_meta(self::SPEAKER_POST_TYPE, self::SPEAKER_META_JOB_TITLE, 'string', 'sanitize_text_field');
        self::register_meta(self::SPEAKER_POST_TYPE, self::SPEAKER_META_ORGANIZATION, 'string', 'sanitize_text_field');
        self::register_meta(self::SPEAKER_POST_TYPE, self::SPEAKER_META_WEBSITE_URL, 'string', array(__CLASS__, 'sanitize_speaker_url'));
        self::register_meta(self::SPEAKER_POST_TYPE, self::SPEAKER_META_SOCIAL_LINKS, 'array', array(__CLASS__, 'sanitize_speaker_social_links'));
    }

    private static function register_meta($post_type, $key, $type, $sanitize_callback) {
        register_post_meta($post_type, $key, array(
            'type' => $type,
            'single' => true,
            'show_in_rest' => false,
            'sanitize_callback' => $sanitize_callback,
            'auth_callback' => static function ($allowed, $meta_key, $post_id) {
                return current_user_can('edit_post', $post_id);
            },
        ));
    }

    public static function sanitize_structure_registry($value) {
        if (!is_array($value)) {
            return array();
        }

        $registry = array();
        $seen = array();
        foreach (array_slice(array_values($value), 0, 200) as $index => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $key = sanitize_key((string) ($entry['key'] ?? ''));
            $title = sanitize_text_field((string) ($entry['title'] ?? ''));
            if ('' === $key || '' === $title || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $registry[] = array(
                'key' => $key,
                'title' => $title,
                'position' => isset($entry['position']) ? max(1, absint($entry['position'])) : $index + 1,
            );
        }

        usort($registry, static function ($left, $right) {
            return (int) $left['position'] === (int) $right['position']
                ? strnatcasecmp((string) $left['title'], (string) $right['title'])
                : ((int) $left['position'] <=> (int) $right['position']);
        });
        return array_values($registry);
    }

}
