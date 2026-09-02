<?php
/**
 * Converts the guided announcement editor into the bounded audience contract.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Announcement_Audience_Builder {

    const PRESET_ALL_LINKED = 'all_linked';
    const PRESET_CONTENT_ACCESS = 'content_access';
    const PRESET_RELATIONSHIP = 'active_relationship';
    const PRESET_MEMBERSHIP = 'active_membership';
    const PRESET_SPECIFIC_USERS = 'specific_users';

    public static function build($payload) {
        $payload = is_array($payload) ? $payload : array();
        $destination = self::destination(isset($payload['destination_id']) ? absint($payload['destination_id']) : 0);
        if (is_wp_error($destination)) {
            return $destination;
        }

        $preset = sanitize_key((string) ($payload['audience_preset'] ?? self::PRESET_ALL_LINKED));
        if (!isset(self::presets()[$preset])) {
            return new WP_Error('announcement_preset_invalid', __('Choose a supported audience preset.', 'member-library'));
        }

        $conditions = array();
        if ('general' !== $destination['type']) {
            $conditions[] = array('type' => 'CAN_ACCESS_CONTENT', 'contentUuid' => $destination['uuid']);
        }
        switch ($preset) {
            case self::PRESET_ALL_LINKED:
                $conditions[] = array('type' => 'AUTHENTICATED_SCHOOL_USER');
                break;
            case self::PRESET_CONTENT_ACCESS:
                if ('general' === $destination['type']) {
                    return new WP_Error('announcement_destination_required', __('Select a published Course or Series for this audience.', 'member-library'));
                }
                break;
            case self::PRESET_RELATIONSHIP:
                if ('general' === $destination['type']) {
                    return new WP_Error('announcement_destination_required', __('Select a published Course or Series for this audience.', 'member-library'));
                }
                $conditions[] = array(
                    'type' => 'ACTIVE_RELATIONSHIP',
                    'contentUuid' => $destination['uuid'],
                    'targetType' => $destination['type'],
                );
                break;
            case self::PRESET_MEMBERSHIP:
                $membership_ids = self::valid_membership_ids($payload['membership_ids'] ?? array());
                if (is_wp_error($membership_ids)) {
                    return $membership_ids;
                }
                $conditions[] = array('type' => 'ACTIVE_MEMBERSHIP', 'membershipIds' => $membership_ids);
                break;
            case self::PRESET_SPECIFIC_USERS:
                $user_ids = self::valid_user_ids($payload['specific_user_ids'] ?? array(), false);
                if (is_wp_error($user_ids)) {
                    return $user_ids;
                }
                $conditions[] = array('type' => 'SPECIFIC_USERS', 'wordpressUserIds' => $user_ids);
                break;
        }

        $exclude_ids = self::positive_ids($payload['exclude_user_ids'] ?? array());
        $exclude = empty($exclude_ids) ? array() : array(array('type' => 'SPECIFIC_USERS', 'wordpressUserIds' => $exclude_ids));
        $definition = MemberLibrary_Announcement_Audience_Contract::normalize(array(
            'schemaVersion' => MemberLibrary_Announcement_Audience_Contract::SCHEMA_VERSION,
            'groups' => array(array('all' => $conditions)),
            'exclude' => $exclude,
        ));
        if (is_wp_error($definition)) {
            return new WP_Error($definition->get_error_code(), __('The selected audience is outside the safe limits.', 'member-library'));
        }

        return array(
            'preset' => $preset,
            'definition' => $definition,
            'hash' => MemberLibrary_Announcement_Audience_Contract::hash($definition),
            'explanation' => MemberLibrary_Announcement_Audience_Contract::explain($definition),
            'summary' => self::summary(
                $preset,
                $destination,
                count(self::ids_for_condition($definition, 'ACTIVE_MEMBERSHIP', 'membershipIds')),
                count(self::ids_for_condition($definition, 'SPECIFIC_USERS', 'wordpressUserIds'))
            ),
            'destination' => $destination,
        );
    }

    public static function default_build() {
        return self::build(array('audience_preset' => self::PRESET_ALL_LINKED, 'destination_id' => 0));
    }

    public static function presets() {
        return array(
            self::PRESET_ALL_LINKED => sprintf(
                /* translators: %s: configured member-facing application name. */
                __('Everyone signed in to %s', 'member-library'),
                MemberLibrary_Brand::app_name()
            ),
            self::PRESET_CONTENT_ACCESS => __('Everyone with access to the destination', 'member-library'),
            self::PRESET_RELATIONSHIP => __('Enrolled in this Course or following this Series', 'member-library'),
            self::PRESET_MEMBERSHIP => __('Active MemberPress membership', 'member-library'),
            self::PRESET_SPECIFIC_USERS => __('Specific users', 'member-library'),
        );
    }

    public static function destination($post_id) {
        if ($post_id <= 0) {
            return array('id' => 0, 'type' => 'general', 'uuid' => '', 'title' => __('General Library announcement', 'member-library'));
        }
        $post = get_post($post_id);
        $types = array(
            MemberLibrary_Content_Model::COURSE_POST_TYPE => 'course',
            MemberLibrary_Content_Model::SERIES_POST_TYPE => 'series',
        );
        if (!$post instanceof WP_Post || !isset($types[$post->post_type]) || 'publish' !== $post->post_status) {
            return new WP_Error('announcement_destination_invalid', __('Select a published Library Course or Series.', 'member-library'));
        }
        $uuid = strtolower(sanitize_text_field((string) get_post_meta($post_id, MemberLibrary_Content_Model::META_UUID, true)));
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid)) {
            return new WP_Error('announcement_destination_invalid', __('The selected destination has no valid Library identity.', 'member-library'));
        }
        return array(
            'id' => (int) $post_id,
            'type' => $types[$post->post_type],
            'uuid' => $uuid,
            'title' => html_entity_decode(wp_strip_all_tags($post->post_title), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset')),
        );
    }

    public static function ids_for_condition($definition, $type, $field) {
        foreach ((array) ($definition['groups'] ?? array()) as $group) {
            foreach ((array) ($group['all'] ?? array()) as $condition) {
                if ($type === ($condition['type'] ?? '') && isset($condition[$field]) && is_array($condition[$field])) {
                    return array_map('intval', $condition[$field]);
                }
            }
        }
        return array();
    }

    public static function exclusion_ids($definition) {
        if (isset($definition['exclude'][0]['wordpressUserIds']) && is_array($definition['exclude'][0]['wordpressUserIds'])) {
            return array_map('intval', $definition['exclude'][0]['wordpressUserIds']);
        }
        return array();
    }

    private static function valid_membership_ids($value) {
        $ids = self::positive_ids($value);
        if (empty($ids) || count($ids) > MemberLibrary_Announcement_Audience_Contract::MAX_MEMBERSHIPS) {
            return new WP_Error('announcement_memberships_invalid', __('Select between 1 and 20 active memberships.', 'member-library'));
        }
        foreach ($ids as $post_id) {
            $post = get_post($post_id);
            if (!$post instanceof WP_Post || 'memberpressproduct' !== $post->post_type || 'publish' !== $post->post_status) {
                return new WP_Error('announcement_memberships_invalid', __('One or more selected memberships are unavailable.', 'member-library'));
            }
        }
        return $ids;
    }

    private static function valid_user_ids($value, $allow_empty) {
        $ids = self::positive_ids($value);
        if ((!$allow_empty && empty($ids)) || count($ids) > MemberLibrary_Announcement_Audience_Contract::MAX_SPECIFIC_USERS) {
            return new WP_Error('announcement_specific_users_invalid', __('Select between 1 and 100 WordPress users.', 'member-library'));
        }
        foreach ($ids as $user_id) {
            if (!get_user_by('id', $user_id)) {
                return new WP_Error('announcement_specific_users_invalid', __('One or more selected users no longer exists.', 'member-library'));
            }
        }
        return $ids;
    }

    private static function positive_ids($value) {
        $values = is_array($value) ? $value : preg_split('/[\s,]+/', (string) $value);
        $ids = array();
        foreach ((array) $values as $candidate) {
            $candidate = absint($candidate);
            if ($candidate > 0) {
                $ids[$candidate] = $candidate;
            }
        }
        sort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private static function summary($preset, $destination, $membership_count, $user_count) {
        $destination_title = (string) $destination['title'];
        switch ($preset) {
            case self::PRESET_CONTENT_ACCESS:
                return sprintf(__('Everyone with current access to %s', 'member-library'), $destination_title);
            case self::PRESET_RELATIONSHIP:
                return 'course' === $destination['type']
                    ? sprintf(__('Members enrolled in %s with updates enabled and current access', 'member-library'), $destination_title)
                    : sprintf(__('Members following %s with updates enabled and current access', 'member-library'), $destination_title);
            case self::PRESET_MEMBERSHIP:
                return sprintf(_n('Members with 1 selected active membership', 'Members with any of %d selected active memberships', max(1, $membership_count), 'member-library'), max(1, $membership_count));
            case self::PRESET_SPECIFIC_USERS:
                return sprintf(_n('1 specifically selected user', '%d specifically selected users', max(1, $user_count), 'member-library'), max(1, $user_count));
            default:
                if ('general' === $destination['type']) {
                    return sprintf(
                        /* translators: %s: configured member-facing application name. */
                        __('Everyone signed in to %s', 'member-library'),
                        MemberLibrary_Brand::app_name()
                    );
                }
                return sprintf(
                    /* translators: 1: configured member-facing application name, 2: destination title. */
                    __('Everyone signed in to %1$s with current access to %2$s', 'member-library'),
                    MemberLibrary_Brand::app_name(),
                    $destination_title
                );
        }
    }
}
