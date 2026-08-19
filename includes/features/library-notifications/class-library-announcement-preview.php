<?php
/**
 * Server-to-server School audience preview client.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Announcement_Preview {

    const ENDPOINT_PATH = '/api/internal/announcement-audience/preview';

    public static function run($definition) {
        $definition = TSOL_Library_Announcement_Audience_Contract::normalize($definition);
        if (is_wp_error($definition)) {
            return new WP_Error('announcement_preview_invalid');
        }
        if (!TSOL_Library_Auth_Settings::configured()) {
            return new WP_Error('announcement_preview_not_configured');
        }
        $url = (string) apply_filters('tsol_library_announcement_preview_url', TSOL_Library_Auth_Settings::app_url() . self::ENDPOINT_PATH);
        $body = wp_json_encode(array('schemaVersion' => 1, 'definition' => $definition), JSON_UNESCAPED_SLASHES);
        if (!is_string($body) || strlen($body) > 65536) {
            return new WP_Error('announcement_preview_invalid');
        }
        $response = wp_remote_post($url, array(
            'timeout' => (int) apply_filters('tsol_library_announcement_preview_timeout', 60),
            'redirection' => 0,
            'sslverify' => true,
            'headers' => array(
                'content-type' => 'application/json',
                'x-tsol-client-id' => TSOL_Library_Auth_Settings::client_id(),
                'x-tsol-client-secret' => TSOL_Library_Auth_Settings::client_secret(),
            ),
            'body' => $body,
        ));
        if (is_wp_error($response) || 200 !== (int) wp_remote_retrieve_response_code($response)) {
            return new WP_Error('announcement_preview_unavailable');
        }
        $result = json_decode((string) wp_remote_retrieve_body($response), true);
        return self::validate_result($result, TSOL_Library_Announcement_Audience_Contract::hash($definition));
    }

    public static function validate_result($result, $expected_hash) {
        $keys = array('status', 'definitionHash', 'generatedAt', 'pages', 'counts');
        if (!is_array($result) || !self::has_exact_keys($result, $keys) || 'ready' !== $result['status'] || $expected_hash !== $result['definitionHash']) {
            return new WP_Error('announcement_preview_invalid_response');
        }
        if (!is_string($result['generatedAt']) || false === strtotime($result['generatedAt']) || !is_int($result['pages']) || $result['pages'] < 1 || $result['pages'] > 100) {
            return new WP_Error('announcement_preview_invalid_response');
        }
        $count_keys = array('scannedWordpressUsers', 'wordpressCandidates', 'linkedCandidates', 'eligible', 'unlinked', 'excluded', 'relationshipSuppressed', 'eligibleAdministrators');
        if (!is_array($result['counts']) || !self::has_exact_keys($result['counts'], $count_keys)) {
            return new WP_Error('announcement_preview_invalid_response');
        }
        foreach ($result['counts'] as $count) {
            if (!is_int($count) || $count < 0) {
                return new WP_Error('announcement_preview_invalid_response');
            }
        }
        return $result;
    }

    private static function has_exact_keys($value, $expected) {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }
}
