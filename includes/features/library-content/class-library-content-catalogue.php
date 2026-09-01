<?php
/**
 * Minimal, deterministic WordPress catalogue projection for the Library sync.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Content_Catalogue {

    const SCHEMA_VERSION = '20260830.1';
    const DEFAULT_PAGE_SIZE = 50;
    const MAX_PAGE_SIZE = 100;

    public static function snapshot($after_id = 0, $per_page = self::DEFAULT_PAGE_SIZE) {
        $after_id = max(0, (int) $after_id);
        $per_page = min(self::MAX_PAGE_SIZE, max(1, (int) $per_page));
        $snapshot_cursor = TSOL_Library_Content_Changes::current_cursor();

        $all_ids = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::post_types(),
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'posts_per_page' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'no_found_rows' => true,
            'suppress_filters' => false,
        ));

        $ids = array_values(array_filter(array_map('intval', $all_ids), static function ($post_id) use ($after_id) {
            return $post_id > $after_id;
        }));
        $items = array();
        foreach ($ids as $post_id) {
            $record = self::record($post_id);
            if (!is_wp_error($record)) {
                $items[] = $record;
                if (count($items) > $per_page) {
                    break;
                }
            }
        }

        // Legacy or otherwise non-exportable posts can share the registered
        // post types. Paginate the records that were actually emitted so the
        // cursor always names the final item in the response. The School
        // importer deliberately rejects any other cursor to prevent gaps.
        $has_more = count($items) > $per_page;
        $items = array_slice($items, 0, $per_page);
        $next_after_id = empty($items) ? null : (int) end($items)['wordpress_id'];

        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'snapshot_cursor' => (string) $snapshot_cursor,
            'next_after_id' => $next_after_id,
            'has_more' => $has_more,
            'items' => $items,
        );
    }

    public static function changes($after_cursor = 0, $limit = 100) {
        $after_cursor = max(0, (int) $after_cursor);
        $limit = min(200, max(1, (int) $limit));
        $rows = TSOL_Library_Content_Changes::after($after_cursor, $limit + 1);
        $has_more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $changes = array();

        foreach ($rows as $row) {
            $post_id = (int) $row['post_id'];
            $record = 'upsert' === (string) $row['action'] ? self::record($post_id) : new WP_Error('deleted');
            $action = is_wp_error($record) ? 'delete' : 'upsert';
            $changes[] = array(
                'cursor' => (string) ((int) $row['id']),
                'post_id' => $post_id,
                'action' => $action,
                'changed_at' => mysql_to_rfc3339((string) $row['changed_at']),
                'item' => 'upsert' === $action ? $record : null,
            );
        }

        $next_cursor = empty($rows) ? $after_cursor : (int) end($rows)['id'];
        return array(
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'next_cursor' => (string) $next_cursor,
            'has_more' => $has_more,
            'changes' => $changes,
        );
    }

    public static function record($post_id) {
        $post = get_post((int) $post_id);
        if (!$post instanceof WP_Post || !self::is_exportable_post($post)) {
            return new WP_Error('unknown_catalogue_content', __('The requested Library catalogue record does not exist.', 'libertyclassroom-library'));
        }

        $thumbnail_id = (int) get_post_thumbnail_id($post->ID);
        $thumbnail_url = $thumbnail_id > 0 ? (string) wp_get_attachment_image_url($thumbnail_id, 'large') : '';
        $excerpt = trim((string) $post->post_excerpt);
        // Catalogue descriptions are intentionally authored safe metadata.
        // Never derive them from a protected legacy body: it can contain embed
        // URLs, download instructions, passwords, or transcript fragments.
        $excerpt = preg_replace('~https?://[^\s<]+~iu', ' ', $excerpt);
        $excerpt = trim(preg_replace('/\s+/u', ' ', (string) $excerpt));
        $overview_html = TSOL_Library_Content_HTML_Sanitizer::sanitize((string) $post->post_content);
        $record_type = self::record_type($post);
        $public_description_html = in_array($record_type, array('course', 'lesson'), true)
            ? $overview_html
            : '';
        $learning_outcomes = TSOL_Library_Content_Model::COURSE_POST_TYPE === $post->post_type
            ? TSOL_Library_Content_Model::sanitize_course_learning_outcomes(get_post_meta(
                $post->ID,
                TSOL_Library_Content_Model::META_COURSE_LEARNING_OUTCOMES,
                true
            ))
            : array();

        $speaker_context = TSOL_Library_Content_Model::effective_speaker_context($post->ID);
        $availability = TSOL_Library_Content_Model::ITEM_POST_TYPE === $post->post_type
            ? TSOL_Library_Content_Model::availability($post->ID)
            : TSOL_Library_Content_Model::AVAILABILITY_AVAILABLE;
        $release_at_gmt = TSOL_Library_Content_Model::AVAILABILITY_COMING_SOON === $availability
            ? TSOL_Library_Content_Model::release_at_gmt($post->ID)
            : '';
        $record = array(
            'wordpress_id' => (int) $post->ID,
            'content_uuid' => sanitize_text_field((string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_UUID, true)),
            'record_type' => $record_type,
            'content_type' => self::content_type($post),
            'status' => (string) $post->post_status,
            'availability' => $availability,
            'release_at' => '' !== $release_at_gmt
                ? gmdate('c', strtotime($release_at_gmt . ' UTC'))
                : null,
            'slug' => (string) $post->post_name,
            'title' => html_entity_decode(wp_strip_all_tags((string) $post->post_title), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')),
            'excerpt' => html_entity_decode(wp_strip_all_tags($excerpt), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')),
            'overview_html' => $overview_html,
            'public_description_html' => $public_description_html,
            'learning_outcomes' => $learning_outcomes,
            'ai_assistant_enabled' => in_array($post->post_type, array(
                TSOL_Library_Content_Model::COURSE_POST_TYPE,
                TSOL_Library_Content_Model::SERIES_POST_TYPE,
            ), true) && (bool) get_post_meta($post->ID, TSOL_Library_Content_Model::META_AI_ASSISTANT_ENABLED, true),
            'ai_assistant_questions' => in_array($post->post_type, array(
                TSOL_Library_Content_Model::COURSE_POST_TYPE,
                TSOL_Library_Content_Model::SERIES_POST_TYPE,
            ), true) ? TSOL_Library_Content_Model::sanitize_ai_assistant_questions(get_post_meta(
                $post->ID,
                TSOL_Library_Content_Model::META_AI_ASSISTANT_QUESTIONS,
                true
            )) : array(),
            'published_at' => self::post_date($post->post_date_gmt),
            'modified_at' => self::post_date($post->post_modified_gmt),
            'last_updated_at' => self::last_updated_at($post),
            'position' => (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_POSITION, true),
            // Transitional compatibility for the existing rebuildable Library
            // projection. Homepage promotion now has its own explicit contract.
            'featured' => false,
            'homepage' => TSOL_Library_Homepage_Curation::placement($post->ID),
            // Transitional compatibility for the existing rebuildable Library
            // projection. WordPress no longer models a separate version state.
            'current' => false,
            'thumbnail' => $thumbnail_url === '' ? null : array(
                'url' => esc_url_raw($thumbnail_url),
                'alt' => sanitize_text_field((string) get_post_meta($thumbnail_id, '_wp_attachment_image_alt', true)),
            ),
            'course_collections' => self::terms($post->ID, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY),
            'topics' => self::terms($post->ID, TSOL_Library_Content_Model::TOPIC_TAXONOMY),
            'speaker_source' => (string) $speaker_context['source'],
            'speakers' => self::speakers($speaker_context['speaker_ids']),
            'media' => self::media($post->ID),
            'resources' => self::resources($post->ID),
            'course' => self::course_context($post),
            'series' => self::series_context($post),
            'authorization_post_id' => (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true),
            'migration_key' => sanitize_key((string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_MIGRATION_KEY, true)),
            'migration_version' => sanitize_text_field((string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_MIGRATION_VERSION, true)),
            'source_modified_at' => self::post_date((string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SOURCE_MODIFIED_GMT, true)),
            'content_fingerprint' => sanitize_text_field((string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_CONTENT_FINGERPRINT, true)),
        );

        return $record;
    }

    public static function is_exportable_post(WP_Post $post) {
        return in_array((string) $post->post_type, TSOL_Library_Content_Model::post_types(), true)
            && !in_array((string) $post->post_status, array('auto-draft', 'inherit', 'trash'), true)
            && (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true) > 0
            && (string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_UUID, true) !== '';
    }

    private static function record_type(WP_Post $post) {
        if (TSOL_Library_Content_Model::COURSE_POST_TYPE === $post->post_type) {
            return 'course';
        }
        if (TSOL_Library_Content_Model::SERIES_POST_TYPE === $post->post_type) {
            return 'series';
        }
        if ((int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_COURSE_ID, true) > 0) {
            return 'lesson';
        }
        return 'item';
    }

    private static function content_type(WP_Post $post) {
        if (TSOL_Library_Content_Model::COURSE_POST_TYPE === $post->post_type) {
            return 'course';
        }
        if (TSOL_Library_Content_Model::SERIES_POST_TYPE === $post->post_type) {
            return 'series';
        }
        return (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_COURSE_ID, true) > 0
            ? 'lesson'
            : 'recording';
    }

    private static function last_updated_at(WP_Post $post) {
        $last_updated_gmt = (string) $post->post_modified_gmt;
        $parent_meta_key = TSOL_Library_Content_Model::COURSE_POST_TYPE === $post->post_type
            ? TSOL_Library_Content_Model::META_COURSE_ID
            : (TSOL_Library_Content_Model::SERIES_POST_TYPE === $post->post_type
                ? TSOL_Library_Content_Model::META_SERIES_ID
                : '');

        if ('' !== $parent_meta_key) {
            $child_ids = get_posts(array(
                'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
                'post_status' => 'publish',
                'numberposts' => -1,
                'fields' => 'ids',
                'meta_key' => $parent_meta_key,
                'meta_value' => (int) $post->ID,
                'suppress_filters' => true,
            ));
            foreach ($child_ids as $child_id) {
                $child = get_post((int) $child_id);
                if ($child instanceof WP_Post && strtotime((string) $child->post_modified_gmt) > strtotime($last_updated_gmt)) {
                    $last_updated_gmt = (string) $child->post_modified_gmt;
                }
            }
        }

        return self::post_date($last_updated_gmt);
    }

    private static function series_context(WP_Post $post) {
        if (TSOL_Library_Content_Model::SERIES_POST_TYPE === $post->post_type) {
            return array(
                'series_id' => (int) $post->ID,
                'item_label' => self::series_label($post->ID, false),
                'item_label_plural' => self::series_label($post->ID, true),
                'sort' => self::series_sort($post->ID),
                'ongoing' => (bool) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_ONGOING, true),
                'group' => null,
                'groups' => self::series_groups($post->ID),
            );
        }

        $series_id = (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_ID, true);
        if ($series_id <= 0 || TSOL_Library_Content_Model::SERIES_POST_TYPE !== get_post_type($series_id)) {
            return null;
        }
        $group_key = sanitize_key((string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_GROUP_KEY, true));
        $registry_group = self::structure_group($series_id, $group_key);
        return array(
            'series_id' => $series_id,
            'item_label' => self::series_label($series_id, false),
            'item_label_plural' => self::series_label($series_id, true),
            'sort' => self::series_sort($series_id),
            'ongoing' => (bool) get_post_meta($series_id, TSOL_Library_Content_Model::META_SERIES_ONGOING, true),
            'group' => array(
                'key' => '' !== $group_key ? $group_key : 'episodes',
                'title' => is_array($registry_group)
                    ? (string) $registry_group['title']
                    : sanitize_text_field((string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_GROUP_TITLE, true)),
                'position' => is_array($registry_group)
                    ? (int) $registry_group['position']
                    : max(1, (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_GROUP_POSITION, true)),
            ),
            'groups' => array(),
        );
    }

    private static function series_groups($series_id) {
        $item_ids = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => TSOL_Library_Content_Model::META_SERIES_ID,
            'meta_value' => (int) $series_id,
            'suppress_filters' => true,
        ));
        $groups = array();
        $registry = class_exists('TSOL_Library_Structure') ? TSOL_Library_Structure::registry((int) $series_id) : array();
        $registry_by_key = array();
        foreach ($registry as $entry) {
            $registry_by_key[(string) $entry['key']] = $entry;
        }
        foreach (array_map('intval', $item_ids) as $item_id) {
            $item = get_post($item_id);
            if (!$item instanceof WP_Post || !self::is_exportable_post($item)) {
                continue;
            }
            $key = sanitize_key((string) get_post_meta($item_id, TSOL_Library_Content_Model::META_SERIES_GROUP_KEY, true));
            $key = '' !== $key ? $key : 'episodes';
            if (!isset($groups[$key])) {
                $registry_group = isset($registry_by_key[$key]) ? $registry_by_key[$key] : null;
                $groups[$key] = array(
                    'key' => $key,
                    'title' => is_array($registry_group)
                        ? (string) $registry_group['title']
                        : sanitize_text_field((string) get_post_meta($item_id, TSOL_Library_Content_Model::META_SERIES_GROUP_TITLE, true)),
                    'position' => is_array($registry_group)
                        ? (int) $registry_group['position']
                        : max(1, (int) get_post_meta($item_id, TSOL_Library_Content_Model::META_SERIES_GROUP_POSITION, true)),
                    'item_ids' => array(),
                    'item_positions' => array(),
                );
            }
            $groups[$key]['item_ids'][] = $item_id;
            $groups[$key]['item_positions'][$item_id] = (int) get_post_meta($item_id, TSOL_Library_Content_Model::META_POSITION, true);
        }
        foreach ($groups as &$group) {
            usort($group['item_ids'], static function ($left, $right) use ($group) {
                $left_position = $group['item_positions'][$left];
                $right_position = $group['item_positions'][$right];
                return $left_position === $right_position ? ($left <=> $right) : ($left_position <=> $right_position);
            });
            unset($group['item_positions']);
            if ('' === $group['title']) {
                $group['title'] = __('Series episodes', 'libertyclassroom-library');
            }
        }
        unset($group);
        uasort($groups, static function ($left, $right) {
            return $left['position'] === $right['position']
                ? strnatcasecmp($left['title'], $right['title'])
                : ($left['position'] <=> $right['position']);
        });
        return array_values($groups);
    }

    private static function series_label($series_id, $plural) {
        $key = $plural ? TSOL_Library_Content_Model::META_SERIES_ITEM_LABEL_PLURAL : TSOL_Library_Content_Model::META_SERIES_ITEM_LABEL;
        $label = sanitize_text_field((string) get_post_meta((int) $series_id, $key, true));
        if ('' !== $label) {
            return $label;
        }
        return $plural ? 'episodes' : 'episode';
    }

    private static function series_sort($series_id) {
        return 'asc' === sanitize_key((string) get_post_meta((int) $series_id, TSOL_Library_Content_Model::META_SERIES_SORT, true)) ? 'asc' : 'desc';
    }

    private static function course_context(WP_Post $post) {
        if (TSOL_Library_Content_Model::COURSE_POST_TYPE === $post->post_type) {
            $sections = self::course_sections($post->ID);
            return array('course_id' => (int) $post->ID, 'section' => null, 'sections' => $sections);
        }

        $course_id = (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_COURSE_ID, true);
        if ($course_id <= 0 || TSOL_Library_Content_Model::COURSE_POST_TYPE !== get_post_type($course_id)) {
            return null;
        }

        $section_key = (string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SECTION_KEY, true);
        $registry_section = self::structure_group($course_id, $section_key);
        $section_title = is_array($registry_section)
            ? (string) $registry_section['title']
            : sanitize_text_field((string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SECTION_TITLE, true));
        if ('' === $section_title) {
            $section_title = __('Course content', 'libertyclassroom-library');
        }
        return array(
            'course_id' => $course_id,
            'section' => array(
                'id' => self::section_numeric_id($course_id, $section_key),
                'uuid' => self::section_key($section_key),
                'title' => $section_title,
                'position' => is_array($registry_section)
                    ? (int) $registry_section['position']
                    : max(1, (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SECTION_POSITION, true)),
            ),
            'sections' => array(),
        );
    }

    private static function course_sections($course_id) {
        $lesson_ids = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'fields' => 'ids',
            'meta_key' => TSOL_Library_Content_Model::META_COURSE_ID,
            'meta_value' => (int) $course_id,
            'suppress_filters' => true,
        ));
        $groups = array();
        $registry = class_exists('TSOL_Library_Structure') ? TSOL_Library_Structure::registry((int) $course_id) : array();
        $registry_by_key = array();
        foreach ($registry as $entry) {
            $registry_by_key[(string) $entry['key']] = $entry;
        }
        foreach (array_map('intval', $lesson_ids) as $lesson_id) {
            $lesson = get_post($lesson_id);
            if (!$lesson instanceof WP_Post || !self::is_exportable_post($lesson)) {
                continue;
            }
            $key = self::section_key((string) get_post_meta($lesson_id, TSOL_Library_Content_Model::META_SECTION_KEY, true));
            if (!isset($groups[$key])) {
                $registry_section = isset($registry_by_key[$key]) ? $registry_by_key[$key] : null;
                $groups[$key] = array(
                    'id' => self::section_numeric_id($course_id, $key),
                    'uuid' => $key,
                    'title' => is_array($registry_section)
                        ? (string) $registry_section['title']
                        : sanitize_text_field((string) get_post_meta($lesson_id, TSOL_Library_Content_Model::META_SECTION_TITLE, true)),
                    'position' => is_array($registry_section)
                        ? (int) $registry_section['position']
                        : max(1, (int) get_post_meta($lesson_id, TSOL_Library_Content_Model::META_SECTION_POSITION, true)),
                    'lesson_ids' => array(),
                    'lesson_positions' => array(),
                );
            }
            $groups[$key]['lesson_ids'][] = $lesson_id;
            $groups[$key]['lesson_positions'][$lesson_id] = (int) get_post_meta($lesson_id, TSOL_Library_Content_Model::META_POSITION, true);
        }

        foreach ($groups as &$section) {
            usort($section['lesson_ids'], static function ($left, $right) use ($section) {
                $left_position = $section['lesson_positions'][$left];
                $right_position = $section['lesson_positions'][$right];
                return $left_position === $right_position ? ($left <=> $right) : ($left_position <=> $right_position);
            });
            unset($section['lesson_positions']);
            if ('' === $section['title']) {
                $section['title'] = __('Course content', 'libertyclassroom-library');
            }
        }
        unset($section);
        uasort($groups, static function ($left, $right) {
            return $left['position'] === $right['position']
                ? strnatcasecmp($left['title'], $right['title'])
                : ($left['position'] <=> $right['position']);
        });
        return array_values($groups);
    }

    private static function section_key($value) {
        $key = sanitize_key((string) $value);
        return '' !== $key ? $key : 'course-content';
    }

    private static function section_numeric_id($course_id, $key) {
        $unsigned = (int) sprintf('%u', crc32((int) $course_id . '|' . self::section_key($key)));
        return ($unsigned % 2147483646) + 1;
    }

    private static function structure_group($parent_id, $key) {
        if (!class_exists('TSOL_Library_Structure')) {
            return null;
        }
        $key = sanitize_key((string) $key);
        foreach (TSOL_Library_Structure::registry((int) $parent_id) as $group) {
            if ($key === (string) $group['key']) {
                return $group;
            }
        }
        return null;
    }

    private static function terms($post_id, $taxonomy) {
        $terms = wp_get_post_terms((int) $post_id, $taxonomy);
        if (is_wp_error($terms)) {
            return array();
        }

        return array_values(array_map(static function ($term) use ($taxonomy) {
            $description = trim(preg_replace('/\s+/u', ' ', html_entity_decode(
                wp_strip_all_tags((string) $term->description),
                ENT_QUOTES | ENT_HTML5,
                get_bloginfo('charset')
            )));
            $overview_html = '';
            $hero_image = null;
            $featured_course_id = null;
            if (TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY === $taxonomy) {
                $overview_html = TSOL_Library_Content_HTML_Sanitizer::sanitize((string) get_term_meta(
                    (int) $term->term_id,
                    TSOL_Library_Content_Model::COLLECTION_META_OVERVIEW,
                    true
                ));
                $hero_image_id = (int) get_term_meta(
                    (int) $term->term_id,
                    TSOL_Library_Content_Model::COLLECTION_META_HERO_IMAGE_ID,
                    true
                );
                $hero_image_url = $hero_image_id > 0 && wp_attachment_is_image($hero_image_id)
                    ? (string) wp_get_attachment_image_url($hero_image_id, 'large')
                    : '';
                if ('' !== $hero_image_url) {
                    $hero_image = array(
                        'wordpress_id' => $hero_image_id,
                        'url' => esc_url_raw($hero_image_url),
                        'alt' => sanitize_text_field((string) get_post_meta($hero_image_id, '_wp_attachment_image_alt', true)),
                    );
                }
                $requested_featured_course_id = (int) get_term_meta(
                    (int) $term->term_id,
                    TSOL_Library_Content_Model::COLLECTION_META_FEATURED_COURSE_ID,
                    true
                );
                if ($requested_featured_course_id > 0
                    && TSOL_Library_Content_Model::COURSE_POST_TYPE === get_post_type($requested_featured_course_id)
                    && has_term((int) $term->term_id, $taxonomy, $requested_featured_course_id)
                ) {
                    $featured_course_id = $requested_featured_course_id;
                }
            }

            return array(
                'wordpress_id' => (int) $term->term_id,
                'taxonomy' => (string) $taxonomy,
                'slug' => (string) $term->slug,
                'name' => html_entity_decode(wp_strip_all_tags((string) $term->name), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')),
                'parent_wordpress_id' => (int) $term->parent ?: null,
                'description' => $description,
                'overview_html' => $overview_html,
                'hero_image' => $hero_image,
                'featured_course_wordpress_id' => $featured_course_id,
                'appearance' => TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY === $taxonomy
                    ? TSOL_Library_Content_Model::collection_appearance((int) $term->term_id)
                    : null,
            );
        }, $terms));
    }

    private static function speakers($speaker_ids) {
        $speaker_ids = array_values(array_unique(array_filter(array_map('absint', (array) $speaker_ids))));
        $records = array();
        foreach ($speaker_ids as $speaker_id) {
            $speaker = get_post($speaker_id);
            if (!$speaker instanceof WP_Post
                || TSOL_Library_Content_Model::SPEAKER_POST_TYPE !== $speaker->post_type
                || 'publish' !== (string) $speaker->post_status
            ) {
                continue;
            }

            $image_id = TSOL_Library_Content_Model::sanitize_speaker_image_id(get_post_thumbnail_id($speaker_id));
            $image_size = TSOL_Library_Content_Model::speaker_image_display_size($image_id);
            $image_url = $image_id > 0 ? (string) wp_get_attachment_image_url($image_id, $image_size) : '';
            if ('' === $image_url && $image_id > 0) {
                $image_url = (string) wp_get_attachment_url($image_id);
            }
            $about_html = TSOL_Library_Content_HTML_Sanitizer::sanitize((string) $speaker->post_content);
            $short_bio = TSOL_Library_Content_HTML_Sanitizer::sanitize_plain_text_summary((string) $speaker->post_excerpt);
            if ('' === $short_bio) {
                $short_bio = wp_trim_words(
                    TSOL_Library_Content_HTML_Sanitizer::sanitize_plain_text_summary($about_html),
                    50,
                    '…'
                );
            }
            $records[] = array(
                'wordpress_id' => $speaker_id,
                'content_uuid' => sanitize_text_field((string) get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_UUID, true)),
                'slug' => (string) $speaker->post_name,
                'name' => html_entity_decode(wp_strip_all_tags((string) $speaker->post_title), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')),
                'status' => (string) $speaker->post_status,
                'image' => '' === $image_url ? null : array(
                    'wordpress_id' => $image_id,
                    'url' => esc_url_raw($image_url),
                    'alt' => sanitize_text_field((string) get_post_meta($image_id, '_wp_attachment_image_alt', true)),
                ),
                'job_title' => sanitize_text_field((string) get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_JOB_TITLE, true)),
                'organization' => sanitize_text_field((string) get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_ORGANIZATION, true)),
                'short_bio' => $short_bio,
                'about' => $about_html,
                'website_url' => TSOL_Library_Content_Model::sanitize_speaker_url(
                    get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_WEBSITE_URL, true)
                ),
                'social_links' => TSOL_Library_Content_Model::sanitize_speaker_social_links(
                    get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_SOCIAL_LINKS, true)
                ),
            );
        }
        return $records;
    }

    private static function media($post_id) {
        $assets = get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_MEDIA_ASSETS, true);
        if (!is_array($assets)) {
            return array();
        }
        $assets = array_values(array_filter($assets, 'is_array'));
        return array_values(array_map(static function ($asset) {
            $attachment_id = absint($asset['attachment_id'] ?? 0);
            $url = esc_url_raw((string) ($asset['url'] ?? ($asset['source_url'] ?? '')));
            $mime_type = sanitize_mime_type((string) ($asset['mime_type'] ?? ''));
            if ($attachment_id > 0) {
                if ($url === '') {
                    $url = esc_url_raw((string) wp_get_attachment_url($attachment_id));
                }
                if ($mime_type === '') {
                    $mime_type = sanitize_mime_type((string) get_post_mime_type($attachment_id));
                }
            }
            return array(
                'key' => sanitize_key((string) ($asset['key'] ?? '')),
                'provider' => sanitize_key((string) ($asset['provider'] ?? '')),
                'provider_id' => sanitize_text_field((string) ($asset['provider_id'] ?? '')),
                'privacy_hash' => sanitize_text_field((string) ($asset['privacy_hash'] ?? '')),
                'url' => $url,
                'attachment_id' => $attachment_id,
                'mime_type' => $mime_type,
                'duration_seconds' => absint($asset['duration_seconds'] ?? 0),
                'position' => absint($asset['position'] ?? 0),
            );
        }, $assets));
    }

    private static function resources($post_id) {
        $resources = get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_RESOURCES, true);
        if (!is_array($resources)) {
            return array();
        }
        $resources = array_values(array_filter($resources, 'is_array'));
        return array_values(array_map(static function ($resource) {
            $attachment_id = absint($resource['attachment_id'] ?? 0);
            $url = esc_url_raw((string) ($resource['url'] ?? ''));
            if ($url === '' && $attachment_id > 0) {
                $url = esc_url_raw((string) wp_get_attachment_url($attachment_id));
            }
            return array(
                'key' => sanitize_key((string) ($resource['key'] ?? '')),
                'type' => sanitize_key((string) ($resource['type'] ?? 'link')),
                'label' => sanitize_text_field((string) ($resource['label'] ?? '')),
                'url' => $url,
                'attachment_id' => $attachment_id,
                'position' => absint($resource['position'] ?? 0),
            );
        }, $resources));
    }

    private static function post_date($mysql_gmt) {
        $mysql_gmt = trim((string) $mysql_gmt);
        if ($mysql_gmt === '' || $mysql_gmt === '0000-00-00 00:00:00') {
            return null;
        }
        return mysql_to_rfc3339($mysql_gmt);
    }
}
