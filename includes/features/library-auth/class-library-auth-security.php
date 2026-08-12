<?php
/**
 * Rate limiting and redacted audit events for Library authentication.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Auth_Rate_Limiter {

    public static function check($scope, $limit, $window_seconds, $subject = '') {
        $scope = sanitize_key((string) $scope);
        $limit = max(1, (int) apply_filters('tsol_library_auth_rate_limit', $limit, $scope));
        $window_seconds = max(10, (int) $window_seconds);
        $subject = trim((string) $subject);
        $identity = $subject === '' ? 'address:' . self::client_address() : 'subject:' . substr($subject, 0, 256);
        $key = hash('sha256', $scope . "\n" . $identity);
        $now = time();
        $state = TSOL_Library_Auth_Repository::increment_rate_limit($key, $now, $window_seconds);
        if (is_wp_error($state)) {
            return $state;
        }
        if ((int) $state['count'] > $limit) {
            $retry_after = max(1, (int) $state['expires_at'] - $now);
            return new WP_Error('rate_limited', __('Too many requests. Please try again shortly.', 'tomschooloflife-plugin'), array('retry_after' => $retry_after));
        }
        return true;
    }

    public static function client_address() {
        $remote_address = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : 'unknown';
        $client_address = trim((string) apply_filters('tsol_library_auth_client_ip', $remote_address));
        return $client_address === '' ? 'unknown' : substr($client_address, 0, 128);
    }
}

class TSOL_Library_Auth_Logger {

    public static function event($event, $context = array()) {
        $event = sanitize_key((string) $event);
        $allowed = array('outcome', 'error', 'endpoint', 'user_id', 'client_id', 'duration_ms', 'item_count');
        $payload = array('event' => $event, 'component' => 'tsol_library_auth');
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $context)) {
                continue;
            }
            if (in_array($key, array('user_id', 'duration_ms', 'item_count'), true)) {
                $payload[$key] = absint($context[$key]);
            } else {
                $payload[$key] = substr(sanitize_text_field((string) $context[$key]), 0, 128);
            }
        }

        /**
         * Fires for a redacted Library authentication audit event.
         * Tokens, codes, secrets, URLs, query strings, and email addresses are never included.
         */
        do_action('tsol_library_auth_audit_event', $payload);

        $is_success = isset($payload['outcome']) && $payload['outcome'] === 'success';
        if ($is_success && !apply_filters('tsol_library_auth_log_successes', false)) {
            return;
        }

        error_log(wp_json_encode($payload)); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
    }
}
