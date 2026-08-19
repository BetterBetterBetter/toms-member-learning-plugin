<?php
/**
 * Read-only WordPress/MemberPress half of the School audience resolver.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Announcement_Audience_Resolver {

    public static function page($value, $after_user_id, $per_page) {
        global $wpdb;

        $definition = TSOL_Library_Announcement_Audience_Contract::normalize($value);
        if (is_wp_error($definition)) {
            return $definition;
        }
        if (!is_int($after_user_id) || $after_user_id < 0 || !is_int($per_page) || $per_page < 1 || $per_page > 200) {
            return new WP_Error('audience_request_invalid');
        }

        $context = self::resolver_context($definition);
        if (is_wp_error($context)) {
            return $context;
        }
        $user_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
            $after_user_id,
            $per_page + 1
        )));
        if (empty($user_ids)) {
            return new WP_Error('audience_cursor_invalid');
        }
        $has_more = count($user_ids) > $per_page;
        $scan_ids = array_slice($user_ids, 0, $per_page);
        $candidates = array();

        foreach ($scan_ids as $user_id) {
            $candidate = self::candidate($definition, $context, $user_id);
            if (is_wp_error($candidate)) {
                return $candidate;
            }
            if (null !== $candidate) {
                $candidates[] = $candidate;
            }
        }

        $hash = TSOL_Library_Announcement_Audience_Contract::hash($definition);
        if (is_wp_error($hash)) {
            return $hash;
        }
        return array(
            'schemaVersion' => TSOL_Library_Announcement_Audience_Contract::SCHEMA_VERSION,
            'definitionHash' => $hash,
            'generatedAt' => gmdate('c'),
            'afterUserId' => $after_user_id,
            'nextAfterUserId' => (int) end($scan_ids),
            'hasMore' => $has_more,
            'scannedCount' => count($scan_ids),
            'candidates' => $candidates,
        );
    }

    private static function resolver_context($definition) {
        $content_uuids = array();
        $membership_ids = array();
        foreach ($definition['groups'] as $group) {
            foreach ($group['all'] as $condition) {
                if ('CAN_ACCESS_CONTENT' === $condition['type']) {
                    $content_uuids[$condition['contentUuid']] = true;
                }
                if ('ACTIVE_MEMBERSHIP' === $condition['type']) {
                    foreach ($condition['membershipIds'] as $membership_id) {
                        $membership_ids[$membership_id] = true;
                    }
                }
            }
        }
        if ((!empty($content_uuids) || !empty($membership_ids)) && (!class_exists('MeprUser') || !class_exists('MeprRule'))) {
            return new WP_Error('memberpress_unavailable');
        }

        $content_ids = array();
        foreach (array_keys($content_uuids) as $uuid) {
            $posts = get_posts(array(
                'post_type' => array(TSOL_Library_Content_Model::COURSE_POST_TYPE, TSOL_Library_Content_Model::SERIES_POST_TYPE),
                'post_status' => 'publish',
                'posts_per_page' => 2,
                'fields' => 'ids',
                'no_found_rows' => true,
                'suppress_filters' => true,
                'meta_key' => TSOL_Library_Content_Model::META_UUID,
                'meta_value' => $uuid,
            ));
            if (1 !== count($posts)) {
                return new WP_Error('unknown_audience_content');
            }
            $content_ids[$uuid] = (int) $posts[0];
        }
        foreach (array_keys($membership_ids) as $membership_id) {
            $product = get_post((int) $membership_id);
            if (!$product || 'memberpressproduct' !== $product->post_type || 'publish' !== $product->post_status) {
                return new WP_Error('unknown_audience_membership');
            }
        }
        return array('content_ids' => $content_ids);
    }

    private static function candidate($definition, $context, $user_id) {
        $memberships = null;
        $groups = array();
        foreach ($definition['groups'] as $index => $group) {
            $matches = true;
            foreach ($group['all'] as $condition) {
                $condition_matches = self::condition_matches($condition, $context, $user_id, $memberships);
                if (is_wp_error($condition_matches)) {
                    return $condition_matches;
                }
                if (!$condition_matches) {
                    $matches = false;
                    break;
                }
            }
            if ($matches) {
                $groups[] = 'g' . $index;
            }
        }
        if (empty($groups)) {
            return null;
        }

        $excluded = false;
        foreach ($definition['exclude'] as $condition) {
            if (in_array($user_id, $condition['wordpressUserIds'], true)) {
                $excluded = true;
                break;
            }
        }
        return array(
            'wordpressUserId' => (int) $user_id,
            'groups' => $groups,
            'excluded' => (bool) $excluded,
            'administrator' => (bool) user_can($user_id, 'manage_options'),
        );
    }

    private static function condition_matches($condition, $context, $user_id, &$memberships) {
        switch ($condition['type']) {
            case 'AUTHENTICATED_SCHOOL_USER':
            case 'ACTIVE_RELATIONSHIP':
                return true;
            case 'SPECIFIC_USERS':
                return in_array($user_id, $condition['wordpressUserIds'], true);
            case 'ACTIVE_MEMBERSHIP':
                if (null === $memberships) {
                    $memberships = array_map('intval', (array) (new MeprUser($user_id))->active_product_subscriptions());
                }
                return !empty(array_intersect($condition['membershipIds'], $memberships));
            case 'CAN_ACCESS_CONTENT':
                $result = TSOL_Library_Auth_Entitlements::for_content($user_id, $context['content_ids'][$condition['contentUuid']]);
                return is_wp_error($result) ? $result : !empty($result['can_access']);
        }
        return new WP_Error('audience_condition_unsupported');
    }
}
