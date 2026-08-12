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
        $remote_address = isset($_SERVER['REMOTE_ADDR']) ? (string) wp_unslash($_SERVER['REMOTE_ADDR']) : 'unknown';
        $client_address = (string) apply_filters('tsol_library_auth_client_ip', $remote_address);
        $key = 'tsol_lib_rl_' . substr(hash('sha256', $scope . "\n" . $client_address . "\n" . (string) $subject), 0, 40);
        $now = time();
        $state = get_transient($key);

        if (!is_array($state) || empty($state['started_at']) || ($now - (int) $state['started_at']) >= $window_seconds) {
            $state = array('count' => 0, 'started_at' => $now);
        }

        if ((int) $state['count'] >= $limit) {
            $retry_after = max(1, $window_seconds - ($now - (int) $state['started_at']));
            return new WP_Error('rate_limited', __('Too many requests. Please try again shortly.', 'tomschooloflife-plugin'), array('retry_after' => $retry_after));
        }

        $state['count'] = (int) $state['count'] + 1;
        set_transient($key, $state, $window_seconds);
        return true;
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
