<?php
/**
 * Versioned, bounded audience language for future School announcements.
 */

if (!defined('ABSPATH')) {
    exit;
}
class TSOL_Library_Announcement_Audience_Contract {

    const SCHEMA_VERSION = 1;
    const MAX_GROUPS = 10;
    const MAX_CONDITIONS_PER_GROUP = 5;
    const MAX_MEMBERSHIPS = 20;
    const MAX_SPECIFIC_USERS = 100;

    private const CONDITION_ORDER = array(
        'AUTHENTICATED_SCHOOL_USER',
        'CAN_ACCESS_CONTENT',
        'ACTIVE_MEMBERSHIP',
        'ACTIVE_RELATIONSHIP',
        'SPECIFIC_USERS',
    );

    public static function normalize($value) {
        if (!self::is_object_array($value) || !self::has_exact_keys($value, array('schemaVersion', 'groups', 'exclude'))) {
            return self::error('audience_definition_invalid');
        }
        if (self::SCHEMA_VERSION !== $value['schemaVersion']) {
            return self::error('audience_schema_unsupported');
        }
        if (!self::is_list($value['groups']) || empty($value['groups']) || count($value['groups']) > self::MAX_GROUPS) {
            return self::error('audience_groups_invalid');
        }
        if (!self::is_list($value['exclude']) || count($value['exclude']) > 1) {
            return self::error('audience_exclusions_invalid');
        }

        $groups = array();
        foreach ($value['groups'] as $raw_group) {
            $group = self::normalize_group($raw_group);
            if (is_wp_error($group)) {
                return $group;
            }
            $groups[] = $group;
        }
        usort($groups, static function ($left, $right) {
            return strcmp(wp_json_encode($left), wp_json_encode($right));
        });
        for ($index = 1; $index < count($groups); $index++) {
            if (wp_json_encode($groups[$index - 1]) === wp_json_encode($groups[$index])) {
                return self::error('audience_group_duplicate');
            }
        }

        $exclude = array();
        foreach ($value['exclude'] as $raw_exclusion) {
            $condition = self::normalize_condition($raw_exclusion, true);
            if (is_wp_error($condition)) {
                return $condition;
            }
            $exclude[] = $condition;
        }

        $specific_users = array();
        $memberships = array();
        $all_conditions = $exclude;
        foreach ($groups as $group) {
            $all_conditions = array_merge($all_conditions, $group['all']);
        }
        foreach ($all_conditions as $condition) {
            if ('SPECIFIC_USERS' === $condition['type']) {
                foreach ($condition['wordpressUserIds'] as $user_id) {
                    $specific_users[$user_id] = true;
                }
            }
            if ('ACTIVE_MEMBERSHIP' === $condition['type']) {
                foreach ($condition['membershipIds'] as $membership_id) {
                    $memberships[$membership_id] = true;
                }
            }
        }
        if (count($specific_users) > self::MAX_SPECIFIC_USERS) {
            return self::error('audience_specific_users_invalid');
        }
        if (count($memberships) > self::MAX_MEMBERSHIPS) {
            return self::error('audience_memberships_invalid');
        }

        return array(
            'schemaVersion' => self::SCHEMA_VERSION,
            'groups' => $groups,
            'exclude' => $exclude,
        );
    }

    public static function hash($value) {
        $normalized = self::normalize($value);
        if (is_wp_error($normalized)) {
            return $normalized;
        }
        return hash('sha256', wp_json_encode($normalized, JSON_UNESCAPED_SLASHES));
    }

    public static function explain($value) {
        $normalized = self::normalize($value);
        if (is_wp_error($normalized)) {
            return $normalized;
        }
        $groups = array();
        foreach ($normalized['groups'] as $index => $group) {
            $groups[] = array(
                'token' => 'g' . $index,
                'operator' => 'ALL',
                'conditions' => array_map(array(__CLASS__, 'explain_condition'), $group['all']),
            );
        }
        $condition_count = 1 === count($groups) ? count($groups[0]['conditions']) : 0;
        $summary = 1 === count($groups)
            ? sprintf('All %d condition%s in group 1', $condition_count, 1 === $condition_count ? '' : 's')
            : sprintf('Any of %d audience groups', count($groups));
        return array(
            'schemaVersion' => self::SCHEMA_VERSION,
            'summary' => $summary,
            'groups' => $groups,
            'exclusions' => array_map(array(__CLASS__, 'explain_condition'), $normalized['exclude']),
        );
    }

    public static function explain_condition($condition) {
        switch ($condition['type']) {
            case 'AUTHENTICATED_SCHOOL_USER':
                return 'Has a linked School account';
            case 'CAN_ACCESS_CONTENT':
                return 'Can currently access content ' . $condition['contentUuid'];
            case 'ACTIVE_MEMBERSHIP':
                return 1 === count($condition['membershipIds'])
                    ? 'Has the selected active membership'
                    : sprintf('Has any of %d selected active memberships', count($condition['membershipIds']));
            case 'ACTIVE_RELATIONSHIP':
                return ('course' === $condition['targetType'] ? 'Actively enrolled in Course ' : 'Actively following Series ')
                    . $condition['contentUuid'] . ' with in-app updates enabled';
            case 'SPECIFIC_USERS':
                return 1 === count($condition['wordpressUserIds'])
                    ? '1 specifically selected user'
                    : sprintf('%d specifically selected users', count($condition['wordpressUserIds']));
        }
        return '';
    }

    private static function normalize_group($value) {
        if (!self::is_object_array($value) || !self::has_exact_keys($value, array('all')) || !self::is_list($value['all'])) {
            return self::error('audience_group_invalid');
        }
        if (empty($value['all']) || count($value['all']) > self::MAX_CONDITIONS_PER_GROUP) {
            return self::error('audience_group_size_invalid');
        }
        $conditions = array();
        $types = array();
        foreach ($value['all'] as $raw_condition) {
            $condition = self::normalize_condition($raw_condition, false);
            if (is_wp_error($condition)) {
                return $condition;
            }
            if (isset($types[$condition['type']])) {
                return self::error('audience_condition_duplicate');
            }
            $types[$condition['type']] = true;
            $conditions[] = $condition;
        }
        usort($conditions, static function ($left, $right) {
            return array_search($left['type'], self::CONDITION_ORDER, true)
                <=> array_search($right['type'], self::CONDITION_ORDER, true);
        });
        return array('all' => $conditions);
    }

    private static function normalize_condition($value, $exclusion) {
        if (!self::is_object_array($value) || !isset($value['type']) || !is_string($value['type'])) {
            return self::error('audience_condition_invalid');
        }
        $type = $value['type'];
        if ($exclusion && 'SPECIFIC_USERS' !== $type) {
            return self::error('audience_exclusion_unsupported');
        }
        switch ($type) {
            case 'AUTHENTICATED_SCHOOL_USER':
                if (!self::has_exact_keys($value, array('type'))) {
                    return self::error('audience_condition_fields_invalid');
                }
                return array('type' => $type);
            case 'CAN_ACCESS_CONTENT':
                if (!self::has_exact_keys($value, array('type', 'contentUuid'))) {
                    return self::error('audience_condition_fields_invalid');
                }
                $uuid = self::uuid($value['contentUuid']);
                return '' === $uuid ? self::error('audience_content_uuid_invalid') : array('type' => $type, 'contentUuid' => $uuid);
            case 'ACTIVE_MEMBERSHIP':
                if (!self::has_exact_keys($value, array('type', 'membershipIds'))) {
                    return self::error('audience_condition_fields_invalid');
                }
                $ids = self::positive_ids($value['membershipIds'], self::MAX_MEMBERSHIPS);
                return is_wp_error($ids) ? self::error('audience_memberships_invalid') : array('type' => $type, 'membershipIds' => $ids);
            case 'ACTIVE_RELATIONSHIP':
                if (!self::has_exact_keys($value, array('type', 'contentUuid', 'targetType')) || !in_array($value['targetType'], array('course', 'series'), true)) {
                    return self::error('audience_relationship_invalid');
                }
                $uuid = self::uuid($value['contentUuid']);
                return '' === $uuid ? self::error('audience_content_uuid_invalid') : array(
                    'type' => $type,
                    'contentUuid' => $uuid,
                    'targetType' => $value['targetType'],
                );
            case 'SPECIFIC_USERS':
                if (!self::has_exact_keys($value, array('type', 'wordpressUserIds'))) {
                    return self::error('audience_condition_fields_invalid');
                }
                $ids = self::positive_ids($value['wordpressUserIds'], self::MAX_SPECIFIC_USERS);
                return is_wp_error($ids) ? self::error('audience_specific_users_invalid') : array('type' => $type, 'wordpressUserIds' => $ids);
            default:
                return self::error('audience_condition_unsupported');
        }
    }

    private static function positive_ids($value, $maximum) {
        if (!self::is_list($value) || empty($value) || count($value) > $maximum) {
            return self::error('audience_ids_invalid');
        }
        $ids = array();
        foreach ($value as $id) {
            if (!is_int($id) || $id <= 0) {
                return self::error('audience_ids_invalid');
            }
            $ids[$id] = $id;
        }
        sort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private static function uuid($value) {
        if (!is_string($value) || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value)) {
            return '';
        }
        return strtolower($value);
    }

    private static function is_object_array($value) {
        return is_array($value) && !self::is_list($value);
    }

    private static function is_list($value) {
        if (!is_array($value)) {
            return false;
        }
        return empty($value) || array_keys($value) === range(0, count($value) - 1);
    }

    private static function has_exact_keys($value, $expected) {
        if (!is_array($value)) {
            return false;
        }
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    private static function error($code) {
        return new WP_Error($code, __('The announcement audience definition is invalid.', 'tomschooloflife-plugin'));
    }
}
