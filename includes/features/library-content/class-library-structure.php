<?php
/**
 * Parent-owned structure registries and safe structure mutations.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Structure {

    const MIGRATION_OPTION = 'tsol_library_structure_registry_version';
    const MIGRATION_VERSION = '20260812.1';
    const MAX_GROUPS = 200;
    const MAX_ITEMS = 1000;

    public static function maybe_migrate() {
        if (self::MIGRATION_VERSION === (string) get_option(self::MIGRATION_OPTION, '')) {
            return;
        }

        foreach (array(MemberLibrary_Content_Model::COURSE_POST_TYPE, MemberLibrary_Content_Model::SERIES_POST_TYPE) as $post_type) {
            $parent_ids = get_posts(array(
                'post_type' => $post_type,
                'post_status' => 'any',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
                'no_found_rows' => true,
                'suppress_filters' => true,
            ));

            foreach ($parent_ids as $parent_id) {
                $meta_key = self::registry_meta_key($post_type);
                if ('' === $meta_key || metadata_exists('post', (int) $parent_id, $meta_key)) {
                    continue;
                }

                update_post_meta((int) $parent_id, $meta_key, self::derive_registry((int) $parent_id));
            }
        }

        update_option(self::MIGRATION_OPTION, self::MIGRATION_VERSION, false);
    }

    public static function registry_meta_key($post_type) {
        if (MemberLibrary_Content_Model::COURSE_POST_TYPE === $post_type) {
            return MemberLibrary_Content_Model::META_COURSE_SECTIONS;
        }
        if (MemberLibrary_Content_Model::SERIES_POST_TYPE === $post_type) {
            return MemberLibrary_Content_Model::META_SERIES_GROUPS;
        }
        return '';
    }

    public static function group_meta_keys($post_type) {
        if (MemberLibrary_Content_Model::COURSE_POST_TYPE === $post_type) {
            return array(
                'key' => MemberLibrary_Content_Model::META_SECTION_KEY,
                'title' => MemberLibrary_Content_Model::META_SECTION_TITLE,
                'position' => MemberLibrary_Content_Model::META_SECTION_POSITION,
            );
        }
        if (MemberLibrary_Content_Model::SERIES_POST_TYPE === $post_type) {
            return array(
                'key' => MemberLibrary_Content_Model::META_SERIES_GROUP_KEY,
                'title' => MemberLibrary_Content_Model::META_SERIES_GROUP_TITLE,
                'position' => MemberLibrary_Content_Model::META_SERIES_GROUP_POSITION,
            );
        }
        return array();
    }

    public static function child_parent_meta_key($post_type) {
        if (MemberLibrary_Content_Model::COURSE_POST_TYPE === $post_type) {
            return MemberLibrary_Content_Model::META_COURSE_ID;
        }
        if (MemberLibrary_Content_Model::SERIES_POST_TYPE === $post_type) {
            return MemberLibrary_Content_Model::META_SERIES_ID;
        }
        return '';
    }

    public static function stored_registry($parent_id) {
        $post_type = get_post_type((int) $parent_id);
        $meta_key = self::registry_meta_key($post_type);
        if ('' === $meta_key || !metadata_exists('post', (int) $parent_id, $meta_key)) {
            return null;
        }

        return MemberLibrary_Content_Model::sanitize_structure_registry(
            get_post_meta((int) $parent_id, $meta_key, true)
        );
    }

    public static function registry($parent_id) {
        $parent_id = (int) $parent_id;
        $stored = self::stored_registry($parent_id);
        $registry = is_array($stored) ? $stored : self::derive_registry($parent_id);
        $seen = array();

        foreach ($registry as $group) {
            $seen[(string) $group['key']] = true;
        }

        // Compatibility guard: surface legacy child groups that are not yet in
        // a stored registry rather than hiding their content from an editor.
        foreach (self::derive_registry($parent_id) as $group) {
            if (!isset($seen[(string) $group['key']])) {
                $registry[] = $group;
                $seen[(string) $group['key']] = true;
            }
        }

        return MemberLibrary_Content_Model::sanitize_structure_registry($registry);
    }

    public static function derive_registry($parent_id) {
        $parent_id = (int) $parent_id;
        $post_type = get_post_type($parent_id);
        $group_keys = self::group_meta_keys($post_type);
        if (empty($group_keys)) {
            return array();
        }

        $groups = array();
        foreach (self::children($parent_id) as $child) {
            $key = sanitize_key((string) get_post_meta($child->ID, $group_keys['key'], true));
            $title = sanitize_text_field((string) get_post_meta($child->ID, $group_keys['title'], true));
            $position = max(1, (int) get_post_meta($child->ID, $group_keys['position'], true));

            if ('' === $key) {
                $key = self::new_group_key('group', $child->ID);
            }
            if ('' === $title) {
                $title = __('Ungrouped', 'member-library');
            }

            if (!isset($groups[$key]) || $position < (int) $groups[$key]['position']) {
                $groups[$key] = array(
                    'key' => $key,
                    'title' => $title,
                    'position' => $position,
                );
            }
        }

        return MemberLibrary_Content_Model::sanitize_structure_registry(array_values($groups));
    }

    public static function children($parent_id) {
        $parent_id = (int) $parent_id;
        $post_type = get_post_type($parent_id);
        $parent_meta_key = self::child_parent_meta_key($post_type);
        if ('' === $parent_meta_key) {
            return array();
        }

        return get_posts(array(
            'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
            'post_status' => 'any',
            'posts_per_page' => -1,
            'orderby' => array(
                'meta_value_num' => 'ASC',
                'ID' => 'ASC',
            ),
            'meta_key' => MemberLibrary_Content_Model::META_POSITION,
            'meta_query' => array(
                array(
                    'key' => $parent_meta_key,
                    'value' => $parent_id,
                    'compare' => '=',
                    'type' => 'NUMERIC',
                ),
            ),
            'suppress_filters' => true,
        ));
    }

    public static function group_options($parent_id) {
        return array_map(static function ($group) {
            return array(
                'key' => (string) $group['key'],
                'title' => (string) $group['title'],
            );
        }, self::registry((int) $parent_id));
    }

    public static function new_group_key($prefix = 'group', $seed = '') {
        $prefix = sanitize_key((string) $prefix);
        if ('' === $prefix) {
            $prefix = 'group';
        }
        $seed = sanitize_key((string) $seed);
        if ('' === $seed) {
            $seed = strtolower(wp_generate_password(12, false, false));
        }
        return sanitize_key($prefix . '-' . $seed);
    }

    public static function snapshot($parent_id) {
        $parent_id = (int) $parent_id;
        $post = get_post($parent_id);
        if (!$post || !in_array($post->post_type, array(
            MemberLibrary_Content_Model::COURSE_POST_TYPE,
            MemberLibrary_Content_Model::SERIES_POST_TYPE,
        ), true)) {
            return new WP_Error('invalid_structure_parent', __('The requested Library structure does not exist.', 'member-library'));
        }

        $group_keys = self::group_meta_keys($post->post_type);
        $registry = self::registry($parent_id);
        $groups = array();

        foreach ($registry as $group) {
            $groups[(string) $group['key']] = array(
                'key' => (string) $group['key'],
                'title' => (string) $group['title'],
                'position' => (int) $group['position'],
                'items' => array(),
            );
        }

        foreach (self::children($parent_id) as $child) {
            $key = sanitize_key((string) get_post_meta($child->ID, $group_keys['key'], true));
            if ('' === $key) {
                $key = self::new_group_key('group', $child->ID);
            }
            if (!isset($groups[$key])) {
                $groups[$key] = array(
                    'key' => $key,
                    'title' => sanitize_text_field((string) get_post_meta($child->ID, $group_keys['title'], true)) ?: __('Ungrouped', 'member-library'),
                    'position' => max(1, (int) get_post_meta($child->ID, $group_keys['position'], true)),
                    'items' => array(),
                );
            }

            $groups[$key]['items'][] = array(
                'id' => (int) $child->ID,
                'title' => get_the_title($child),
                'status' => (string) $child->post_status,
                'statusLabel' => get_post_status_object($child->post_status) ? get_post_status_object($child->post_status)->label : $child->post_status,
                'availability' => MemberLibrary_Content_Model::availability($child->ID),
                'releaseAt' => MemberLibrary_Content_Model::release_at_gmt($child->ID),
                'position' => (int) get_post_meta($child->ID, MemberLibrary_Content_Model::META_POSITION, true),
                'editUrl' => get_edit_post_link($child->ID, 'raw'),
            );
        }

        $groups = array_values($groups);
        usort($groups, static function ($left, $right) {
            $position = ((int) $left['position']) <=> ((int) $right['position']);
            return 0 !== $position ? $position : strcasecmp((string) $left['title'], (string) $right['title']);
        });
        foreach ($groups as &$group) {
            usort($group['items'], static function ($left, $right) {
                $position = ((int) $left['position']) <=> ((int) $right['position']);
                return 0 !== $position ? $position : ((int) $left['id']) <=> ((int) $right['id']);
            });
        }
        unset($group);

        $canonical_groups = $groups;
        if (self::is_descending_series($parent_id)) {
            $groups = array_reverse($groups);
            foreach ($groups as &$group) {
                $group['items'] = array_reverse($group['items']);
            }
            unset($group);
        }

        $item_count = 0;
        foreach ($groups as $group) {
            $item_count += count($group['items']);
        }

        return array(
            'parentId' => $parent_id,
            'parentType' => $post->post_type,
            'parentTitle' => get_the_title($post),
            'parentEditUrl' => get_edit_post_link($parent_id, 'raw'),
            'itemLabel' => MemberLibrary_Content_Model::COURSE_POST_TYPE === $post->post_type
                ? __('lesson', 'member-library')
                : self::series_item_label($parent_id),
            'groupLabel' => MemberLibrary_Content_Model::COURSE_POST_TYPE === $post->post_type
                ? __('section', 'member-library')
                : __('group', 'member-library'),
            'descending' => self::is_descending_series($parent_id),
            'groups' => $groups,
            'groupCount' => count($groups),
            'itemCount' => $item_count,
            'revision' => self::revision_from_groups($parent_id, $canonical_groups),
        );
    }

    public static function save_display_structure($parent_id, $payload, $revision) {
        $parent_id = (int) $parent_id;
        $snapshot = self::snapshot($parent_id);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        if (!hash_equals((string) $snapshot['revision'], (string) $revision)) {
            return new WP_Error(
                'structure_conflict',
                __('This structure changed in another tab or by another administrator. Reload before saving so no work is overwritten.', 'member-library')
            );
        }
        if (!is_array($payload) || !isset($payload['groups']) || !is_array($payload['groups'])) {
            return new WP_Error('invalid_structure', __('The submitted structure is invalid.', 'member-library'));
        }

        $submitted = $payload['groups'];
        if (count($submitted) > self::MAX_GROUPS) {
            return new WP_Error('too_many_groups', __('The submitted structure contains too many groups.', 'member-library'));
        }

        $expected_items = array_map(static function ($child) {
            return (int) $child->ID;
        }, self::children($parent_id));
        sort($expected_items, SORT_NUMERIC);

        $seen_groups = array();
        $seen_items = array();
        $normalized = array();
        foreach ($submitted as $group) {
            if (!is_array($group)) {
                return new WP_Error('invalid_group', __('One of the submitted groups is invalid.', 'member-library'));
            }

            $key = sanitize_key(isset($group['key']) ? (string) $group['key'] : '');
            $title = sanitize_text_field(isset($group['title']) ? (string) $group['title'] : '');
            if ('' === $key || '' === $title || isset($seen_groups[$key])) {
                return new WP_Error('invalid_group', __('Every group needs a unique key and a title.', 'member-library'));
            }
            $seen_groups[$key] = true;

            $items = isset($group['items']) && is_array($group['items']) ? array_map('absint', $group['items']) : array();
            foreach ($items as $item_id) {
                if ($item_id <= 0 || isset($seen_items[$item_id])) {
                    return new WP_Error('invalid_items', __('Every item must appear exactly once.', 'member-library'));
                }
                $seen_items[$item_id] = true;
            }

            $normalized[] = array(
                'key' => $key,
                'title' => $title,
                'items' => $items,
            );
        }

        if (count($seen_items) > self::MAX_ITEMS) {
            return new WP_Error('too_many_items', __('The submitted structure contains too many items.', 'member-library'));
        }

        $submitted_items = array_keys($seen_items);
        sort($submitted_items, SORT_NUMERIC);
        if ($expected_items !== $submitted_items) {
            return new WP_Error(
                'structure_membership_changed',
                __('The content in this structure changed while you were editing. Reload before saving.', 'member-library')
            );
        }

        $post_type = (string) $snapshot['parentType'];
        $parent_meta_key = self::child_parent_meta_key($post_type);
        foreach ($submitted_items as $item_id) {
            if (MemberLibrary_Content_Model::ITEM_POST_TYPE !== get_post_type($item_id)
                || $parent_id !== (int) get_post_meta($item_id, $parent_meta_key, true)
            ) {
                return new WP_Error('invalid_parent', __('An item no longer belongs to this structure. Reload before saving.', 'member-library'));
            }
        }

        // The builder presents newest-first series in frontend order. Convert
        // that display order back to canonical ascending positions on save.
        if (self::is_descending_series($parent_id)) {
            $normalized = array_reverse($normalized);
            foreach ($normalized as &$group) {
                $group['items'] = array_reverse($group['items']);
            }
            unset($group);
        }

        $group_meta = self::group_meta_keys($post_type);
        $registry = array();
        $operations = array();
        $series_position = 0;
        foreach ($normalized as $group_index => $group) {
            $group_position = $group_index + 1;
            $registry[] = array(
                'key' => $group['key'],
                'title' => $group['title'],
                'position' => $group_position,
            );

            foreach ($group['items'] as $item_index => $item_id) {
                $item_position = MemberLibrary_Content_Model::SERIES_POST_TYPE === $post_type
                    ? ++$series_position
                    : $item_index + 1;
                self::queue_meta_operation($operations, $item_id, $group_meta['key'], $group['key']);
                self::queue_meta_operation($operations, $item_id, $group_meta['title'], $group['title']);
                self::queue_meta_operation($operations, $item_id, $group_meta['position'], $group_position);
                self::queue_meta_operation($operations, $item_id, MemberLibrary_Content_Model::META_POSITION, $item_position);
            }
        }
        self::queue_meta_operation($operations, $parent_id, self::registry_meta_key($post_type), $registry);

        $applied = array();
        foreach ($operations as $operation) {
            $result = update_post_meta($operation['post_id'], $operation['key'], $operation['new']);
            if (false === $result && !self::meta_values_equal(
                get_post_meta($operation['post_id'], $operation['key'], true),
                $operation['new']
            )) {
                self::rollback_operations($applied);
                return new WP_Error('structure_save_failed', __('WordPress could not save the complete structure. No intentional changes were kept.', 'member-library'));
            }
            if (false !== $result) {
                $applied[] = $operation;
            }
        }

        return self::snapshot($parent_id);
    }

    public static function is_descending_series($parent_id) {
        return MemberLibrary_Content_Model::SERIES_POST_TYPE === get_post_type((int) $parent_id)
            && 'desc' === strtolower((string) get_post_meta((int) $parent_id, MemberLibrary_Content_Model::META_SERIES_SORT, true));
    }

    private static function series_item_label($parent_id) {
        $label = sanitize_text_field((string) get_post_meta((int) $parent_id, MemberLibrary_Content_Model::META_SERIES_ITEM_LABEL, true));
        return '' !== $label ? strtolower($label) : __('episode', 'member-library');
    }

    private static function revision_from_groups($parent_id, $groups) {
        $data = array(
            'parentId' => (int) $parent_id,
            'groups' => array_map(static function ($group) {
                return array(
                    'key' => (string) $group['key'],
                    'title' => (string) $group['title'],
                    'position' => (int) $group['position'],
                    'items' => array_map(static function ($item) {
                        return array(
                            'id' => (int) $item['id'],
                            'position' => (int) $item['position'],
                        );
                    }, $group['items']),
                );
            }, $groups),
        );
        return hash('sha256', (string) wp_json_encode($data));
    }

    private static function queue_meta_operation(&$operations, $post_id, $key, $new_value) {
        $old_value = get_post_meta((int) $post_id, (string) $key, true);
        if (self::meta_values_equal($old_value, $new_value)) {
            return;
        }

        $operations[] = array(
            'post_id' => (int) $post_id,
            'key' => (string) $key,
            'old' => $old_value,
            'existed' => metadata_exists('post', (int) $post_id, (string) $key),
            'new' => $new_value,
        );
    }

    private static function meta_values_equal($left, $right) {
        if (is_array($left) || is_object($left) || is_array($right) || is_object($right)) {
            return maybe_serialize($left) === maybe_serialize($right);
        }
        return (string) $left === (string) $right;
    }

    private static function rollback_operations($operations) {
        foreach (array_reverse($operations) as $operation) {
            if ($operation['existed']) {
                update_post_meta($operation['post_id'], $operation['key'], $operation['old']);
            } else {
                delete_post_meta($operation['post_id'], $operation['key']);
            }
        }
    }
}
