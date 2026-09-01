<?php
/**
 * Default-off release controls for School announcements.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Announcement_Flags {

    const OPTION = 'tsol_library_announcement_flags';

    public static function admin_enabled() {
        return self::enabled('admin_editor', 'TSOL_LIBRARY_ANNOUNCEMENT_ADMIN_ENABLED');
    }

    public static function preview_enabled() {
        return self::enabled('audience_preview', 'TSOL_LIBRARY_ANNOUNCEMENT_PREVIEW_ENABLED');
    }

    public static function publish_enabled() {
        return self::enabled('publish_schedule', 'TSOL_LIBRARY_ANNOUNCEMENT_PUBLISH_ENABLED');
    }

    public static function self_test_enabled() {
        return self::enabled('self_test', 'TSOL_LIBRARY_ANNOUNCEMENT_SELF_TEST_ENABLED');
    }

    private static function enabled($key, $constant) {
        $environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        if ('production' === $environment) {
            $value = defined($constant) && true === constant($constant);
        } else {
            $flags = get_option(self::OPTION, array());
            $value = is_array($flags) && !empty($flags[$key]);
        }

        return (bool) apply_filters('tsol_library_announcement_' . $key . '_enabled', $value);
    }
}
