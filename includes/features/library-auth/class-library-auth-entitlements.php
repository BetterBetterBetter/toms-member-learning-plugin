<?php
/**
 * MemberPress authorizes members; WordPress permissions authorize administrators.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Auth_Entitlements {

    public static function for_content($user_id, $post_id) {
        if (!class_exists('MeprUser') || !class_exists('MeprRule')) {
            return new WP_Error('memberpress_unavailable', __('MemberPress is unavailable.', 'member-library'));
        }

        $user = get_user_by('id', (int) $user_id);
        if (!$user) {
            return new WP_Error('unknown_user', __('The WordPress user does not exist.', 'member-library'));
        }

        $requested_post = get_post((int) $post_id);
        if (!$requested_post) {
            return new WP_Error('unknown_content', __('The requested content does not exist.', 'member-library'));
        }

        $authorization_post_id = (int) get_post_meta(
            $requested_post->ID,
            MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID,
            true
        );
        $is_library_target = in_array(
            (string) $requested_post->post_type,
            MemberLibrary_Content_Model::post_types(),
            true
        );
        if (!$is_library_target || $authorization_post_id <= 0) {
            $authorization_post_id = (int) $requested_post->ID;
        }

        $post = get_post($authorization_post_id);
        if (!$post) {
            return new WP_Error('unknown_authorization_content', __('The content access source does not exist.', 'member-library'));
        }

        $has_admin_access = user_can((int) $user_id, 'manage_options');
        $has_core_access = $post->post_status === 'publish' || user_can((int) $user_id, 'read_post', (int) $post->ID);
        if (!empty($post->post_password) && !user_can((int) $user_id, 'edit_post', (int) $post->ID)) {
            $has_core_access = false;
        }
        $rules = MeprRule::get_rules($post);
        $is_protected = !empty($rules);
        $can_access = $has_core_access && ($has_admin_access || !$is_protected);
        if (!$can_access && $has_core_access && $is_protected) {
            $can_access = !MeprRule::is_locked_for_user(new MeprUser((int) $user_id), $post);
        }

        return array(
            'can_access' => $can_access,
            'is_protected' => $is_protected,
            'access_source' => $has_admin_access ? 'wordpress_admin' : ($is_protected ? 'memberpress' : 'public'),
            'post_id' => (int) $requested_post->ID,
            'post_type' => (string) $requested_post->post_type,
            'authorization_post_id' => (int) $post->ID,
            'authorization_post_type' => (string) $post->post_type,
            'checked_at' => gmdate('c'),
        );
    }
}
