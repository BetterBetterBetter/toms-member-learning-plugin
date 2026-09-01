<?php
/**
 * Plugin dependency checks.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Dependencies {

    public static function access_sso_available() {
        if (class_exists('AccessPlatformSSO')) {
            return true;
        }

        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $known_basenames = array(
            'access-platform-sso/access-platform-sso.php',
            'wp-access-sso/access-platform-sso.php',
            'wp-access-sso-1.1.3/access-platform-sso.php',
        );

        foreach ($known_basenames as $basename) {
            if (is_plugin_active($basename)) {
                return true;
            }
        }

        $active_plugins = (array) get_option('active_plugins', array());
        foreach ($active_plugins as $plugin) {
            if (basename($plugin) === 'access-platform-sso.php') {
                return true;
            }
        }

        return false;
    }
}
