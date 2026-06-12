<?php
/**
 * Cookie consent settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Cookie_Consent_Settings {

    public const OPTION = 'tsol_cookie_consent_settings';
    public const OPTION_GROUP = 'tsol_cookie_consent_settings';
    public const COOKIE_NAME = 'tsol_cookie_consent';

    public static function register_settings() {
        register_setting(self::OPTION_GROUP, self::OPTION, array(
            'sanitize_callback' => array(__CLASS__, 'sanitize_settings'),
            'default' => self::defaults(),
        ));
    }

    public static function defaults() {
        return array(
            'enabled' => '1',
            'banner_enabled' => '1',
            'consent_version' => gmdate('Y-m'),
            'cookie_lifetime_days' => 180,
            'banner_position' => 'bottom',
            'show_reopen_button' => '1',
            'reopen_position' => 'bottom_left',
            'show_admin_bar_button' => '1',
            'respect_gpc' => '1',
            'google_consent_mode' => '1',
            'gtm_container_id' => 'GTM-PXK2RMK',
            'google_ads_id' => 'AW-10959370418',
            'privacy_url' => 'https://access.tomwoods.com/privacy',
            'terms_url' => 'https://access.tomwoods.com/terms',
            'banner_eyebrow' => 'Privacy choices',
            'banner_title' => 'Choose how TSoL uses cookies',
            'banner_intro' => 'We use essential cookies to run the site. With your permission, we also use analytics and marketing cookies to understand what is working and improve the experience.',
            'accept_all_label' => 'Accept all',
            'reject_all_label' => 'Reject optional',
            'manage_label' => 'Manage choices',
            'save_label' => 'Save choices',
            'settings_label' => 'Cookie settings',
            'close_label' => 'Close',
            'preferences_title' => 'Cookie preferences',
            'preferences_intro' => 'Choose what TSoL can use in this browser. You can update this later from the cookie settings button.',
            'necessary_label' => 'Essential',
            'necessary_description' => 'Required for login, checkout, security, and core site behavior. These cannot be switched off here.',
            'analytics_enabled' => '1',
            'analytics_label' => 'Analytics',
            'analytics_description' => 'Helps us understand page visits, traffic sources, and what content is useful so we can improve the site.',
            'marketing_enabled' => '1',
            'marketing_label' => 'Marketing',
            'marketing_description' => 'Helps measure campaigns, improve ad relevance, and avoid showing irrelevant promotions.',
            'analytics_script_urls' => '',
            'analytics_inline_scripts' => '',
            'marketing_script_urls' => '',
            'marketing_inline_scripts' => '',
        );
    }

    public static function get_settings() {
        $stored = get_option(self::OPTION, null);
        $settings = is_array($stored) ? $stored : self::defaults();
        $settings = wp_parse_args($settings, self::defaults());

        $settings['cookie_lifetime_days'] = max(30, absint($settings['cookie_lifetime_days']));
        $settings['banner_position'] = self::sanitize_banner_position($settings['banner_position']);
        $settings['reopen_position'] = self::sanitize_reopen_position($settings['reopen_position']);

        foreach (array('privacy_url', 'terms_url') as $url_key) {
            $settings[$url_key] = esc_url_raw($settings[$url_key]);
        }

        return $settings;
    }

    public static function get_categories($settings = null) {
        $settings = is_array($settings) ? wp_parse_args($settings, self::defaults()) : self::get_settings();

        return array(
            'necessary' => array(
                'key' => 'necessary',
                'label' => $settings['necessary_label'],
                'description' => $settings['necessary_description'],
                'required' => true,
                'enabled' => true,
            ),
            'analytics' => array(
                'key' => 'analytics',
                'label' => $settings['analytics_label'],
                'description' => $settings['analytics_description'],
                'required' => false,
                'enabled' => $settings['analytics_enabled'] === '1',
            ),
            'marketing' => array(
                'key' => 'marketing',
                'label' => $settings['marketing_label'],
                'description' => $settings['marketing_description'],
                'required' => false,
                'enabled' => $settings['marketing_enabled'] === '1',
            ),
        );
    }

    public static function get_banner_positions() {
        return array(
            'bottom' => __('Bottom bar', 'tomschooloflife-plugin'),
            'bottom_left' => __('Bottom left', 'tomschooloflife-plugin'),
            'bottom_right' => __('Bottom right', 'tomschooloflife-plugin'),
            'top' => __('Top bar', 'tomschooloflife-plugin'),
        );
    }

    public static function get_reopen_positions() {
        return array(
            'bottom_right' => __('Bottom right', 'tomschooloflife-plugin'),
            'bottom_left' => __('Bottom left', 'tomschooloflife-plugin'),
            'top_right' => __('Top right', 'tomschooloflife-plugin'),
            'top_left' => __('Top left', 'tomschooloflife-plugin'),
        );
    }

    public static function get_script_payload($settings = null) {
        $settings = is_array($settings) ? wp_parse_args($settings, self::defaults()) : self::get_settings();

        return array(
            'analytics' => array(
                'urls' => self::parse_script_urls($settings['analytics_script_urls']),
                'inline' => self::split_inline_scripts($settings['analytics_inline_scripts']),
            ),
            'marketing' => array(
                'urls' => self::parse_script_urls($settings['marketing_script_urls']),
                'inline' => self::split_inline_scripts($settings['marketing_inline_scripts']),
            ),
        );
    }

    public static function get_consent_from_cookie($settings = null) {
        $settings = is_array($settings) ? wp_parse_args($settings, self::defaults()) : self::get_settings();

        if (empty($_COOKIE[self::COOKIE_NAME])) {
            return null;
        }

        $raw_cookie = rawurldecode((string) wp_unslash($_COOKIE[self::COOKIE_NAME]));
        $decoded = json_decode($raw_cookie, true);

        if (!is_array($decoded) || !isset($decoded['version']) || $decoded['version'] !== $settings['consent_version']) {
            return null;
        }

        return array(
            'version' => sanitize_text_field($decoded['version']),
            'necessary' => true,
            'analytics' => !empty($decoded['analytics']),
            'marketing' => !empty($decoded['marketing']),
            'timestamp' => isset($decoded['timestamp']) ? sanitize_text_field($decoded['timestamp']) : '',
            'source' => isset($decoded['source']) ? sanitize_key($decoded['source']) : '',
        );
    }

    public static function get_consent_mode_state($consent = null) {
        $analytics_granted = is_array($consent) && !empty($consent['analytics']);
        $marketing_granted = is_array($consent) && !empty($consent['marketing']);

        return array(
            'analytics_storage' => $analytics_granted ? 'granted' : 'denied',
            'ad_storage' => $marketing_granted ? 'granted' : 'denied',
            'ad_user_data' => $marketing_granted ? 'granted' : 'denied',
            'ad_personalization' => $marketing_granted ? 'granted' : 'denied',
            'personalization_storage' => $marketing_granted ? 'granted' : 'denied',
            'functionality_storage' => 'granted',
            'security_storage' => 'granted',
            'wait_for_update' => 500,
        );
    }

    public static function sanitize_settings($value) {
        $value = is_array($value) ? $value : array();
        $current = get_option(self::OPTION, array());
        $base = is_array($current) ? wp_parse_args($current, self::defaults()) : self::defaults();

        $sanitized = $base;
        $checkboxes = array(
            'enabled',
            'banner_enabled',
            'show_reopen_button',
            'show_admin_bar_button',
            'respect_gpc',
            'google_consent_mode',
            'analytics_enabled',
            'marketing_enabled',
        );
        $text_fields = array(
            'consent_version',
            'gtm_container_id',
            'google_ads_id',
            'banner_eyebrow',
            'banner_title',
            'accept_all_label',
            'reject_all_label',
            'manage_label',
            'save_label',
            'settings_label',
            'close_label',
            'preferences_title',
            'necessary_label',
            'analytics_label',
            'marketing_label',
        );
        $textarea_fields = array(
            'banner_intro',
            'preferences_intro',
            'necessary_description',
            'analytics_description',
            'marketing_description',
        );

        foreach ($checkboxes as $key) {
            if (array_key_exists($key, $value)) {
                $sanitized[$key] = self::sanitize_checkbox($value[$key]);
            }
        }

        foreach ($text_fields as $key) {
            if (array_key_exists($key, $value)) {
                $sanitized[$key] = sanitize_text_field(wp_unslash($value[$key]));
            }
        }

        foreach ($textarea_fields as $key) {
            if (array_key_exists($key, $value)) {
                $sanitized[$key] = sanitize_textarea_field(wp_unslash($value[$key]));
            }
        }

        if (array_key_exists('cookie_lifetime_days', $value)) {
            $sanitized['cookie_lifetime_days'] = max(30, absint($value['cookie_lifetime_days']));
        }

        if (array_key_exists('banner_position', $value)) {
            $sanitized['banner_position'] = self::sanitize_banner_position(wp_unslash($value['banner_position']));
        }

        if (array_key_exists('reopen_position', $value)) {
            $sanitized['reopen_position'] = self::sanitize_reopen_position(wp_unslash($value['reopen_position']));
        }

        if (array_key_exists('privacy_url', $value)) {
            $sanitized['privacy_url'] = esc_url_raw(wp_unslash($value['privacy_url']));
        }

        if (array_key_exists('terms_url', $value)) {
            $sanitized['terms_url'] = esc_url_raw(wp_unslash($value['terms_url']));
        }

        if (array_key_exists('analytics_script_urls', $value)) {
            $sanitized['analytics_script_urls'] = self::sanitize_script_urls(wp_unslash($value['analytics_script_urls']));
        }

        if (array_key_exists('marketing_script_urls', $value)) {
            $sanitized['marketing_script_urls'] = self::sanitize_script_urls(wp_unslash($value['marketing_script_urls']));
        }

        if (array_key_exists('analytics_inline_scripts', $value)) {
            $sanitized['analytics_inline_scripts'] = self::sanitize_inline_scripts(wp_unslash($value['analytics_inline_scripts']));
        }

        if (array_key_exists('marketing_inline_scripts', $value)) {
            $sanitized['marketing_inline_scripts'] = self::sanitize_inline_scripts(wp_unslash($value['marketing_inline_scripts']));
        }

        return $sanitized;
    }

    private static function parse_script_urls($value) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        $urls = array();

        foreach ((array) $lines as $line) {
            $url = trim((string) $line);

            if ($url === '') {
                continue;
            }

            $url = esc_url_raw($url);

            if ($url) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    private static function split_inline_scripts($value) {
        $value = trim((string) $value);

        if ($value === '') {
            return array();
        }

        $blocks = preg_split('/\n-{3,}\n/', $value);
        $blocks = array_map('trim', (array) $blocks);
        $blocks = array_filter($blocks);

        return array_values($blocks);
    }

    private static function sanitize_script_urls($value) {
        return implode("\n", self::parse_script_urls($value));
    }

    private static function sanitize_inline_scripts($value) {
        $value = (string) $value;

        if (!current_user_can('unfiltered_html')) {
            return sanitize_textarea_field($value);
        }

        $value = wp_check_invalid_utf8($value);
        $value = preg_replace('/<\/?script\b[^>]*>/i', '', $value);

        return trim((string) $value);
    }

    private static function sanitize_checkbox($value) {
        return $value === '1' ? '1' : '0';
    }

    private static function sanitize_banner_position($value) {
        $value = sanitize_key((string) $value);

        return array_key_exists($value, self::get_banner_positions()) ? $value : 'bottom';
    }

    private static function sanitize_reopen_position($value) {
        $value = sanitize_key((string) $value);

        return array_key_exists($value, self::get_reopen_positions()) ? $value : 'bottom_right';
    }
}
