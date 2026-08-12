<?php
/**
 * Durable WordPress security-event delivery to the Library.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Auth_Revocation {

    const SCHEMA_VERSION = '20260812.1';
    const SCHEMA_OPTION = 'tsol_library_auth_revocation_schema_version';
    const CRON_HOOK = 'tsol_library_auth_revocation_deliver';
    const AUDIENCE = 'tsol-library-auth';
    const ENDPOINT_PATH = '/api/internal/auth/revoke';
    const LOCK_SECONDS = 60;
    const WATCHDOG_SECONDS = 60;

    private const EVENTS = array(
        'user.deleted',
        'user.identity_changed',
        'user.password_reset',
        'user.provider_identity_corrected',
        'user.roles_changed',
        'user.security_locked',
        'user.sessions_forced_logout',
        'user.suspended',
    );

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tsol_library_auth_revocation_outbox';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            jti varchar(36) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            event varchar(64) NOT NULL,
            attempt_count int(10) unsigned NOT NULL DEFAULT 0,
            next_attempt_at datetime NOT NULL,
            locked_until datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (jti),
            KEY next_attempt (next_attempt_at, locked_until),
            KEY user_event (user_id, event)
        ) {$charset_collate};");

        $installed = $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        if ($installed) {
            update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        }
        return $installed;
    }

    public static function maybe_install() {
        if (get_option(self::SCHEMA_OPTION) !== self::SCHEMA_VERSION) {
            self::install();
        }
        self::schedule_delivery(time() + self::WATCHDOG_SECONDS);
    }

    public static function register_hooks() {
        if (self::has_password_change_hook()) {
            add_action('wp_set_password', array(__CLASS__, 'password_changed'), 10, 2);
        } else {
            add_action('after_password_reset', array(__CLASS__, 'password_reset'), 10, 2);
        }
        add_action('deleted_user', array(__CLASS__, 'user_deleted'), 10, 3);
        add_action('profile_update', array(__CLASS__, 'profile_updated'), 10, 3);
        add_action('set_user_role', array(__CLASS__, 'roles_changed'), 10, 3);
        add_action('tsol_library_auth_revocation_requested', array(__CLASS__, 'queue'), 10, 2);
        add_action(self::CRON_HOOK, array(__CLASS__, 'deliver_pending'));
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function password_changed($password, $user_id) {
        unset($password);
        self::queue($user_id, 'user.password_reset');
    }

    public static function password_reset($user) {
        if ($user instanceof WP_User) {
            self::queue($user->ID, 'user.password_reset');
        }
    }

    public static function user_deleted($user_id) {
        self::queue($user_id, 'user.deleted');
    }

    public static function profile_updated($user_id, $old_user_data, $userdata = array()) {
        unset($userdata);
        $current = get_userdata($user_id);
        if (
            !self::has_password_change_hook()
            && $current instanceof WP_User
            && $old_user_data instanceof WP_User
            && !hash_equals((string) $old_user_data->user_pass, (string) $current->user_pass)
        ) {
            self::queue($user_id, 'user.password_reset');
        }
        if (
            $current instanceof WP_User
            && $old_user_data instanceof WP_User
            && (
                !hash_equals((string) $old_user_data->user_email, (string) $current->user_email)
                || (int) $old_user_data->user_status !== (int) $current->user_status
            )
        ) {
            self::queue($user_id, 'user.identity_changed');
        }
    }

    public static function roles_changed($user_id) {
        self::queue($user_id, 'user.roles_changed');
    }

    /**
     * Queue a supported security event immediately in the durable outbox.
     *
     * Suspension/security plugins and administrator tooling should call:
     * do_action('tsol_library_auth_revocation_requested', $user_id, 'user.suspended');
     */
    public static function queue($user_id, $event) {
        global $wpdb;

        $user_id = absint($user_id);
        $event = sanitize_text_field((string) $event);
        if ($user_id <= 0 || !in_array($event, self::EVENTS, true)) {
            return false;
        }

        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(self::table(), array(
            'jti' => wp_generate_uuid4(),
            'user_id' => $user_id,
            'event' => $event,
            'attempt_count' => 0,
            'next_attempt_at' => $now,
            'locked_until' => null,
            'created_at' => $now,
        ), array('%s', '%d', '%s', '%d', '%s', '%s', '%s'));
        if (false === $inserted) {
            TSOL_Library_Auth_Logger::event('revocation', array('outcome' => 'failure', 'error' => 'outbox_failed', 'endpoint' => 'auth_revocation', 'user_id' => $user_id));
            return false;
        }

        self::schedule_delivery(time());
        if ((!defined('WP_CLI') || !WP_CLI) && !wp_doing_cron() && function_exists('spawn_cron')) {
            spawn_cron(time());
        }
        return true;
    }

    public static function deliver_pending() {
        global $wpdb;

        $now_timestamp = time();
        self::schedule_delivery($now_timestamp + self::WATCHDOG_SECONDS);
        $now = gmdate('Y-m-d H:i:s', $now_timestamp);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT jti, user_id, event, attempt_count FROM ' . self::table() . ' WHERE next_attempt_at <= %s AND (locked_until IS NULL OR locked_until < %s) ORDER BY created_at ASC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
                $now,
                $now
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            self::schedule_next_pending();
            return;
        }

        $jti = (string) $row['jti'];
        $locked = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::table() . ' SET locked_until = %s WHERE jti = %s AND (locked_until IS NULL OR locked_until < %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
                gmdate('Y-m-d H:i:s', $now_timestamp + self::LOCK_SECONDS),
                $jti,
                $now
            )
        );
        if (1 !== $locked) {
            self::schedule_next_pending();
            return;
        }

        $result = self::send($jti, (int) $row['user_id'], (string) $row['event'], $now_timestamp);
        if ($result['success']) {
            $wpdb->delete(self::table(), array('jti' => $jti), array('%s'));
        } else {
            $attempt = max(0, (int) $row['attempt_count']) + 1;
            $wpdb->update(
                self::table(),
                array(
                    'attempt_count' => $attempt,
                    'next_attempt_at' => gmdate('Y-m-d H:i:s', $now_timestamp + self::retry_delay($attempt)),
                    'locked_until' => null,
                ),
                array('jti' => $jti),
                array('%d', '%s', '%s'),
                array('%s')
            );
        }

        do_action('tsol_library_auth_revocation_delivery_result', array(
            'success' => (bool) $result['success'],
            'status_code' => (int) $result['status_code'],
            'error_code' => (string) $result['error_code'],
            'attempt' => max(0, (int) $row['attempt_count']) + 1,
        ));
        self::schedule_next_pending();
    }

    private static function send($jti, $user_id, $event, $timestamp) {
        $url = self::endpoint_url();
        $secret = self::secret();
        if ($url === '' || strlen($secret) < 32) {
            return array('success' => false, 'status_code' => 0, 'error_code' => 'not_configured');
        }

        $body = wp_json_encode(array(
            'version' => 1,
            'event' => (string) $event,
            'audience' => self::AUDIENCE,
            'jti' => (string) $jti,
            'wordpress_user_id' => (string) $user_id,
            'issued_at' => (int) $timestamp,
        ), JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return array('success' => false, 'status_code' => 0, 'error_code' => 'encoding_failed');
        }

        $signature = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
        $response = wp_remote_post($url, array(
            'blocking' => true,
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-TSOL-Webhook-Timestamp' => (string) $timestamp,
                'X-TSOL-Webhook-Signature' => 'sha256=' . $signature,
            ),
            'redirection' => 0,
            'reject_unsafe_urls' => !self::is_local_url($url),
            'sslverify' => true,
            'timeout' => 5,
            'user-agent' => 'TSOL-Library-Auth-Revocation/' . TSOL_SITE_PLUGIN_VERSION,
        ));
        if (is_wp_error($response)) {
            return array('success' => false, 'status_code' => 0, 'error_code' => 'transport_failed');
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        $accepted = ($status_code >= 200 && $status_code < 300) || 409 === $status_code;
        return array(
            'success' => $accepted,
            'status_code' => $status_code,
            'error_code' => $accepted ? '' : 'http_error',
        );
    }

    private static function endpoint_url() {
        $app_url = TSOL_Library_Auth_Settings::app_url();
        $url = $app_url === '' ? '' : $app_url . self::ENDPOINT_PATH;
        return (string) apply_filters('tsol_library_auth_revocation_url', $url);
    }

    private static function secret() {
        $secret = defined('TSOL_LIBRARY_AUTH_REVOCATION_SECRET')
            ? trim((string) TSOL_LIBRARY_AUTH_REVOCATION_SECRET)
            : '';
        $secret = trim((string) apply_filters('tsol_library_auth_revocation_secret', $secret));
        $disallowed = array(TSOL_Library_Auth_Settings::client_secret());
        if (defined('TSOL_LIBRARY_CATALOGUE_WEBHOOK_SECRET')) {
            $disallowed[] = trim((string) TSOL_LIBRARY_CATALOGUE_WEBHOOK_SECRET);
        }
        foreach ($disallowed as $candidate) {
            if ($candidate !== '' && $secret !== '' && hash_equals($candidate, $secret)) {
                return '';
            }
        }
        return $secret;
    }

    private static function is_local_url($url) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        return in_array($host, array('localhost', '127.0.0.1', '::1'), true);
    }

    private static function has_password_change_hook() {
        global $wp_version;
        return version_compare((string) $wp_version, '6.2', '>=');
    }

    private static function retry_delay($attempt) {
        $delays = array(10, 30, 120, 600, 1800, 3600);
        $index = min(count($delays) - 1, max(0, (int) $attempt - 1));
        return (int) $delays[$index];
    }

    private static function schedule_next_pending() {
        global $wpdb;

        $next = $wpdb->get_var('SELECT MIN(next_attempt_at) FROM ' . self::table()); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
        if (is_string($next) && $next !== '') {
            self::schedule_delivery(max(time(), (int) strtotime($next . ' UTC')));
        }
    }

    private static function schedule_delivery($timestamp) {
        $scheduled = wp_next_scheduled(self::CRON_HOOK);
        $timestamp = max(time(), (int) $timestamp);
        if (false !== $scheduled && (int) $scheduled > $timestamp) {
            wp_unschedule_event((int) $scheduled, self::CRON_HOOK);
            $scheduled = false;
        }
        if (false === $scheduled) {
            wp_schedule_single_event($timestamp, self::CRON_HOOK);
        }
    }
}
