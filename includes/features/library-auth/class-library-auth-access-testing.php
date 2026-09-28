<?php
/**
 * Administrator workflow for viewing the Library as a WordPress member.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Auth_Access_Testing {

    private const OPEN_ACTION = 'tsol_library_open_access_test';
    private const FINISH_ACTION = 'tsol_library_finish_access_test';
    private const TRANSIENT_PREFIX = 'tsol_library_access_test_';
    private const TOKEN_TTL = 8 * HOUR_IN_SECONDS;

    public function init() {
        add_action('admin_post_' . self::OPEN_ACTION, array($this, 'open'));
        add_action('admin_post_' . self::FINISH_ACTION, array($this, 'finish'));
        add_filter('user_row_actions', array($this, 'user_row_actions'), 10, 2);
        add_filter('mepr-admin-members-cols', array($this, 'memberpress_columns'));
        add_action('mepr_members_list_table_row', array($this, 'memberpress_cell'), 10, 4);
    }

    public function user_row_actions($actions, $user) {
        if (!($user instanceof WP_User)) {
            return $actions;
        }

        $url = $this->switch_url($user);
        if ($url === '') {
            return $actions;
        }

        $actions['tsol_library_access_test'] = sprintf(
            '<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
            esc_url($url),
            esc_html__('View Library access', 'member-library')
        );
        return $actions;
    }

    public function memberpress_columns($columns) {
        if (!is_array($columns) || isset($columns['col_library_access_test'])) {
            return $columns;
        }

        $next = array();
        $inserted = false;
        foreach ($columns as $key => $label) {
            $next[$key] = $label;
            if ($key === 'col_login_as_user') {
                $next['col_library_access_test'] = __('Library access', 'member-library');
                $inserted = true;
            }
        }
        if (!$inserted) {
            $next['col_library_access_test'] = __('Library access', 'member-library');
        }
        return $next;
    }

    public function memberpress_cell($attributes, $record, $column_name, $column_display_name) {
        unset($column_display_name);
        if ($column_name !== 'col_library_access_test') {
            return;
        }

        $user = isset($record->username) ? get_user_by('login', (string) $record->username) : false;
        $url = $user instanceof WP_User ? $this->switch_url($user) : '';
        $link = $url === ''
            ? '&mdash;'
            : sprintf(
                '<a class="button button-small" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                esc_url($url),
                esc_html__('View access', 'member-library')
            );

        // MemberPress supplies the escaped table-cell attributes to this hook.
        echo '<td ' . $attributes . '>' . $link . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function open() {
        nocache_headers();
        $mode = isset($_GET['mode']) && is_scalar($_GET['mode'])
            ? sanitize_key(wp_unslash($_GET['mode']))
            : 'member';

        if ($mode === 'admin') {
            if (!MemberLibrary_Auth_Settings::configured() || !current_user_can('manage_options')) {
                $this->deny();
            }
            $this->redirect_to_library(self::library_login_url('stop'));
        }

        if (!MemberLibrary_Auth_Settings::configured() || !class_exists('w357LoginAsUser')) {
            $this->deny();
        }

        $target_id = get_current_user_id();
        $switcher = new w357LoginAsUser();
        $old_user = $switcher->get_old_user();
        if (
            !$target_id
            || !($old_user instanceof WP_User)
            || !$switcher->authenticate_old_user($old_user)
            || !user_can($old_user, 'manage_options')
            || !user_can($old_user, 'login_as_user', $target_id)
        ) {
            $this->deny();
        }

        try {
            $token = bin2hex(random_bytes(24));
        } catch (Throwable $error) {
            unset($error);
            $this->deny();
        }

        $stored = set_transient(self::transient_key($token), array(
            'version' => 1,
            'actor_id' => (int) $old_user->ID,
            'target_id' => $target_id,
        ), self::TOKEN_TTL);
        if (!$stored) {
            $this->deny();
        }

        $this->redirect_to_library(self::library_login_url('start', $token));
    }

    public function finish() {
        nocache_headers();
        $token = isset($_GET['token']) && is_scalar($_GET['token'])
            ? strtolower((string) wp_unslash($_GET['token']))
            : '';
        if (!self::valid_token($token) || !class_exists('w357LoginAsUser')) {
            $this->deny();
        }

        $key = self::transient_key($token);
        $test = get_transient($key);
        $switcher = new w357LoginAsUser();
        $old_user = $switcher->get_old_user();
        $target_id = get_current_user_id();
        if (
            !is_array($test)
            || (int) ($test['version'] ?? 0) !== 1
            || (int) ($test['target_id'] ?? 0) !== $target_id
            || !($old_user instanceof WP_User)
            || (int) ($test['actor_id'] ?? 0) !== (int) $old_user->ID
            || !$switcher->authenticate_old_user($old_user)
            || !user_can($old_user, 'manage_options')
            || !user_can($old_user, 'login_as_user', $target_id)
        ) {
            $this->deny();
        }

        delete_transient($key);
        $return_bridge = add_query_arg(array(
            'action' => self::OPEN_ACTION,
            'mode' => 'admin',
        ), admin_url('admin-post.php'));
        $back_url = add_query_arg('redirect_to', $return_bridge, w357LoginAsUser::back_url($old_user));
        wp_safe_redirect($back_url, 302, 'Member Library Access Test');
        exit;
    }

    public static function valid_token($token) {
        return is_string($token) && preg_match('/^[a-f0-9]{48}$/D', $token) === 1;
    }

    public static function transient_key($token) {
        return self::TRANSIENT_PREFIX . hash('sha256', (string) $token);
    }

    public static function library_login_url($mode, $token = '') {
        $return_to = '/?access_test=0';
        if ($mode === 'start' && self::valid_token($token)) {
            $return_to = '/?access_test=1&access_test_token=' . $token;
        }
        return MemberLibrary_Auth_Settings::app_url() . '/auth/login?return_to=' . rawurlencode($return_to);
    }

    private function switch_url(WP_User $target) {
        if (
            !MemberLibrary_Auth_Settings::configured()
            || !class_exists('w357LoginAsUser')
            || !current_user_can('manage_options')
            || !current_user_can('login_as_user', $target->ID)
        ) {
            return '';
        }

        $bridge = add_query_arg('action', self::OPEN_ACTION, admin_url('admin-post.php'));
        $switch = w357LoginAsUser::loginasuser_url($target, array(
            'logout_redirect_url' => admin_url('users.php'),
        ));
        return add_query_arg('redirect_to', $bridge, $switch);
    }

    private function redirect_to_library($url) {
        wp_redirect($url, 302, 'Member Library Access Test'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Exact configured Library origin.
        exit;
    }

    private function deny() {
        wp_die(
            esc_html__('This Library access test is unavailable or has expired.', 'member-library'),
            esc_html__('Library access test unavailable', 'member-library'),
            array('response' => 403)
        );
    }
}
