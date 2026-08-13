<?php
/**
 * Durable, signed wake-up delivery for the Library catalogue projection.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Catalogue_Webhook {

    const SCHEMA_VERSION = '20260812.2';
    const SCHEMA_OPTION = 'tsol_library_catalogue_webhook_schema_version';
    const CRON_HOOK = 'tsol_library_catalogue_webhook_deliver';
    const WATCHDOG_HOOK = 'tsol_library_catalogue_webhook_watchdog';
    const WATCHDOG_SCHEDULE = 'tsol_library_catalogue_every_minute';
    const EVENT = 'catalogue.changes.available';
    const AUDIENCE = 'tsol-library-catalogue';
    const ENDPOINT_PATH = '/api/internal/catalogue/wake';
    const STATUS_ENDPOINT_PATH = '/api/internal/catalogue/status';
    const LOCK_SECONDS = 60;
    const LAST_DELIVERY_OPTION = 'tsol_library_catalogue_webhook_last_delivery';

    private static $queued_cursor = 0;

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tsol_library_catalogue_webhook_outbox';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE {$table} (
            delivery_id varchar(36) NOT NULL,
            change_cursor bigint(20) unsigned NOT NULL,
            attempt_count int(10) unsigned NOT NULL DEFAULT 0,
            next_attempt_at datetime NOT NULL,
            locked_until datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (delivery_id),
            KEY next_attempt (next_attempt_at, locked_until),
            KEY change_cursor (change_cursor)
        ) {$charset_collate};";

        dbDelta($sql);
        $installed = self::table() === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', self::table()));
        if ($installed) {
            update_option(self::SCHEMA_OPTION, self::SCHEMA_VERSION, false);
        }
        return $installed;
    }

    public static function maybe_install() {
        if (get_option(self::SCHEMA_OPTION) !== self::SCHEMA_VERSION) {
            self::install();
        }
        self::ensure_watchdog();
    }

    public static function register_hooks() {
        add_filter('cron_schedules', array(__CLASS__, 'register_cron_schedule'));
        add_action('tsol_library_catalogue_change_recorded', array(__CLASS__, 'queue_change'), 10, 1);
        add_action('shutdown', array(__CLASS__, 'flush_queued_change'), 20);
        add_action(self::CRON_HOOK, array(__CLASS__, 'deliver_pending'));
        add_action(self::WATCHDOG_HOOK, array(__CLASS__, 'deliver_pending'));
    }

    public static function register_cron_schedule($schedules) {
        if (!is_array($schedules)) {
            $schedules = array();
        }
        $schedules[self::WATCHDOG_SCHEDULE] = array(
            'interval' => MINUTE_IN_SECONDS,
            'display' => __('Every minute (TSOL catalogue delivery)', 'tomschooloflife-plugin'),
        );
        return $schedules;
    }

    public static function activate() {
        add_filter('cron_schedules', array(__CLASS__, 'register_cron_schedule'));
        self::ensure_watchdog();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::WATCHDOG_HOOK);
    }

    public static function ensure_watchdog() {
        if (false === wp_next_scheduled(self::WATCHDOG_HOOK)) {
            // Make the first recovery pass due immediately. WordPress then keeps
            // the watchdog on the dedicated one-minute recurrence.
            wp_schedule_event(time(), self::WATCHDOG_SCHEDULE, self::WATCHDOG_HOOK);
        }
    }

    public static function queue_change($cursor) {
        $cursor = max(0, (int) $cursor);
        if ($cursor > self::$queued_cursor) {
            self::$queued_cursor = $cursor;
        }
    }

    public static function flush_queued_change() {
        global $wpdb;

        $cursor = self::$queued_cursor;
        self::$queued_cursor = 0;
        if ($cursor <= 0) {
            return;
        }

        $now = current_time('mysql', true);
        $delivery_id = wp_generate_uuid4();
        $inserted = $wpdb->insert(
            self::table(),
            array(
                'delivery_id' => $delivery_id,
                'change_cursor' => $cursor,
                'attempt_count' => 0,
                'next_attempt_at' => $now,
                'locked_until' => null,
                'created_at' => $now,
            ),
            array('%s', '%d', '%d', '%s', '%s', '%s')
        );
        if (false === $inserted) {
            return;
        }

        $wpdb->query(
            $wpdb->prepare(
                'DELETE FROM ' . self::table() . ' WHERE change_cursor < %d AND (locked_until IS NULL OR locked_until < %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
                $cursor,
                $now
            )
        );
        self::schedule_delivery(time());

        $normal_web_request = (!defined('WP_CLI') || !WP_CLI) && !wp_doing_cron();
        if (apply_filters('tsol_library_catalogue_immediate_delivery_enabled', $normal_web_request)) {
            // This is a best-effort acceleration only. The durable outbox row is
            // intentionally retained until a later blocking delivery receives a
            // successful response, so an interrupted PHP request cannot lose work.
            self::send($delivery_id, (string) $cursor, time(), false);
        }
        if ($normal_web_request && function_exists('spawn_cron')) {
            spawn_cron(time());
        }
    }

    public static function deliver_pending() {
        global $wpdb;

        $now_timestamp = time();
        $now = gmdate('Y-m-d H:i:s', $now_timestamp);
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT delivery_id, change_cursor, attempt_count FROM ' . self::table() . ' WHERE next_attempt_at <= %s AND (locked_until IS NULL OR locked_until < %s) ORDER BY change_cursor DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
                $now,
                $now
            ),
            ARRAY_A
        );
        if (!is_array($row)) {
            self::schedule_next_pending();
            return;
        }

        $delivery_id = (string) $row['delivery_id'];
        $locked = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::table() . ' SET locked_until = %s WHERE delivery_id = %s AND (locked_until IS NULL OR locked_until < %s)', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
                gmdate('Y-m-d H:i:s', $now_timestamp + self::LOCK_SECONDS),
                $delivery_id,
                $now
            )
        );
        if (1 !== $locked) {
            self::schedule_next_pending();
            return;
        }

        $result = self::send($delivery_id, (string) $row['change_cursor'], $now_timestamp);
        if ($result['success']) {
            $wpdb->query(
                $wpdb->prepare(
                    'DELETE FROM ' . self::table() . ' WHERE change_cursor <= %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
                    (int) $row['change_cursor']
                )
            );
        } else {
            $attempt = max(0, (int) $row['attempt_count']) + 1;
            $wpdb->update(
                self::table(),
                array(
                    'attempt_count' => $attempt,
                    'next_attempt_at' => gmdate('Y-m-d H:i:s', $now_timestamp + self::retry_delay($attempt)),
                    'locked_until' => null,
                ),
                array('delivery_id' => $delivery_id),
                array('%d', '%s', '%s'),
                array('%s')
            );
        }

        do_action('tsol_library_catalogue_webhook_delivery_result', array(
            'success' => (bool) $result['success'],
            'status_code' => (int) $result['status_code'],
            'error_code' => (string) $result['error_code'],
            'attempt' => max(0, (int) $row['attempt_count']) + 1,
            'cursor' => (string) $row['change_cursor'],
        ));
        self::record_delivery_result(
            $result,
            (string) $row['change_cursor'],
            max(0, (int) $row['attempt_count']) + 1,
            $now_timestamp
        );
        self::schedule_next_pending();
    }

    private static function send($delivery_id, $cursor, $timestamp, $blocking = true) {
        $url = self::endpoint_url();
        $secret = self::secret();
        if ($url === '' || strlen($secret) < 32) {
            return array('success' => false, 'status_code' => 0, 'error_code' => 'not_configured');
        }

        $body = wp_json_encode(array(
            'version' => 1,
            'event' => self::EVENT,
            'audience' => self::AUDIENCE,
            'delivery_id' => $delivery_id,
            'cursor' => (string) $cursor,
            'issued_at' => (int) $timestamp,
        ), JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return array('success' => false, 'status_code' => 0, 'error_code' => 'encoding_failed');
        }

        $signature = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
        $response = wp_remote_post($url, array(
            'blocking' => (bool) $blocking,
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-TSOL-Webhook-Timestamp' => (string) $timestamp,
                'X-TSOL-Webhook-Signature' => 'sha256=' . $signature,
            ),
            'redirection' => 0,
            'reject_unsafe_urls' => !self::is_local_url($url),
            'sslverify' => true,
            'timeout' => $blocking ? 5 : 1,
            'user-agent' => 'TSOL-Library-Catalogue-Webhook/' . TSOL_SITE_PLUGIN_VERSION,
        ));
        if (is_wp_error($response)) {
            return array('success' => false, 'status_code' => 0, 'error_code' => 'transport_failed');
        }

        if (!$blocking) {
            return array('success' => true, 'status_code' => 0, 'error_code' => '');
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        return array(
            'success' => $status_code >= 200 && $status_code < 300,
            'status_code' => $status_code,
            'error_code' => $status_code >= 200 && $status_code < 300 ? '' : 'http_error',
        );
    }

    public static function delivery_status() {
        global $wpdb;

        $table = self::table();
        $installed = $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $pending = array(
            'count' => 0,
            'oldest_at' => null,
            'next_attempt_at' => null,
            'max_attempts' => 0,
        );
        if ($installed) {
            $row = $wpdb->get_row(
                'SELECT COUNT(*) AS pending_count, MIN(created_at) AS oldest_at, MIN(next_attempt_at) AS next_attempt_at, MAX(attempt_count) AS max_attempts FROM ' . $table, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
                ARRAY_A
            );
            if (is_array($row)) {
                $pending = array(
                    'count' => max(0, (int) $row['pending_count']),
                    'oldest_at' => self::mysql_utc_or_null($row['oldest_at']),
                    'next_attempt_at' => self::mysql_utc_or_null($row['next_attempt_at']),
                    'max_attempts' => max(0, (int) $row['max_attempts']),
                );
            }
        }

        $last_delivery = get_option(self::LAST_DELIVERY_OPTION, array());
        $last_delivery = is_array($last_delivery) ? $last_delivery : array();
        $latest_change_at = $wpdb->get_var('SELECT MAX(changed_at) FROM ' . TSOL_Library_Content_Changes::table()); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.

        return array(
            'configured' => self::endpoint_url() !== '' && strlen(self::secret()) >= 32,
            'outbox_installed' => $installed,
            'source_cursor' => (string) TSOL_Library_Content_Changes::current_cursor(),
            'latest_change_at' => self::mysql_utc_or_null($latest_change_at),
            'pending' => $pending,
            'cron_scheduled_at' => self::timestamp_or_null(wp_next_scheduled(self::CRON_HOOK)),
            'watchdog_scheduled_at' => self::timestamp_or_null(wp_next_scheduled(self::WATCHDOG_HOOK)),
            'last_delivery' => array(
                'success' => isset($last_delivery['success']) ? (bool) $last_delivery['success'] : null,
                'status_code' => max(0, (int) ($last_delivery['status_code'] ?? 0)),
                'error_code' => self::safe_error_code($last_delivery['error_code'] ?? ''),
                'attempt' => max(0, (int) ($last_delivery['attempt'] ?? 0)),
                'cursor' => self::cursor_or_empty($last_delivery['cursor'] ?? ''),
                'recorded_at' => self::mysql_utc_or_null($last_delivery['recorded_at'] ?? null),
            ),
        );
    }

    public static function school_status($source_cursor) {
        $url = self::status_endpoint_url();
        $secret = self::secret();
        $source_cursor = self::cursor_or_empty($source_cursor);
        if ($url === '' || strlen($secret) < 32 || $source_cursor === '') {
            return array('ok' => false, 'error_code' => 'not_configured');
        }

        $timestamp = time();
        $body = wp_json_encode(array(
            'version' => 1,
            'event' => 'catalogue.status.requested',
            'audience' => self::AUDIENCE,
            'request_id' => wp_generate_uuid4(),
            'source_cursor' => $source_cursor,
            'issued_at' => $timestamp,
        ), JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return array('ok' => false, 'error_code' => 'encoding_failed');
        }

        $signature = hash_hmac('sha256', (string) $timestamp . '.' . $body, $secret);
        $response = wp_remote_post($url, array(
            'blocking' => true,
            'body' => $body,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-TSOL-Catalogue-Timestamp' => (string) $timestamp,
                'X-TSOL-Catalogue-Signature' => 'sha256=' . $signature,
            ),
            'redirection' => 0,
            'reject_unsafe_urls' => !self::is_local_url($url),
            'sslverify' => true,
            'timeout' => 5,
            'user-agent' => 'TSOL-Library-Catalogue-Status/' . TSOL_SITE_PLUGIN_VERSION,
        ));
        if (is_wp_error($response)) {
            return array('ok' => false, 'error_code' => 'transport_failed');
        }
        $status_code = (int) wp_remote_retrieve_response_code($response);
        $response_body = (string) wp_remote_retrieve_body($response);
        if ($status_code < 200 || $status_code >= 300 || strlen($response_body) > 4096) {
            return array('ok' => false, 'error_code' => 'status_unavailable');
        }

        $payload = json_decode($response_body, true);
        if (!self::valid_school_status_payload($payload)) {
            return array('ok' => false, 'error_code' => 'invalid_response');
        }

        return array(
            'ok' => true,
            'schema_version' => (string) $payload['schema_version'],
            'cursor' => (string) $payload['cursor'],
            'last_successful_sync_at' => self::iso8601_or_null($payload['last_successful_sync_at']),
            'latest_run' => $payload['latest_run'],
            'pending_wakeups' => (int) $payload['pending_wakeups'],
        );
    }

    private static function endpoint_url() {
        $app_url = TSOL_Library_Auth_Settings::app_url();
        $url = $app_url === '' ? '' : $app_url . self::ENDPOINT_PATH;
        return (string) apply_filters('tsol_library_catalogue_webhook_url', $url);
    }

    private static function status_endpoint_url() {
        $app_url = TSOL_Library_Auth_Settings::app_url();
        $url = $app_url === '' ? '' : $app_url . self::STATUS_ENDPOINT_PATH;
        return (string) apply_filters('tsol_library_catalogue_status_url', $url);
    }

    private static function secret() {
        $secret = defined('TSOL_LIBRARY_CATALOGUE_WEBHOOK_SECRET')
            ? (string) TSOL_LIBRARY_CATALOGUE_WEBHOOK_SECRET
            : '';
        $secret = trim((string) apply_filters('tsol_library_catalogue_webhook_secret', $secret));
        $client_secret = TSOL_Library_Auth_Settings::client_secret();
        if ($secret !== '' && $client_secret !== '' && hash_equals($client_secret, $secret)) {
            return '';
        }
        return $secret;
    }

    private static function is_local_url($url) {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        return in_array($host, array('localhost', '127.0.0.1', '::1'), true);
    }

    private static function retry_delay($attempt) {
        $delays = array(10, 30, 120, 600, 1800, 3600);
        $index = min(count($delays) - 1, max(0, (int) $attempt - 1));
        return (int) $delays[$index];
    }

    private static function record_delivery_result($result, $cursor, $attempt, $timestamp) {
        update_option(self::LAST_DELIVERY_OPTION, array(
            'success' => !empty($result['success']),
            'status_code' => max(0, (int) ($result['status_code'] ?? 0)),
            'error_code' => self::safe_error_code($result['error_code'] ?? ''),
            'attempt' => max(1, (int) $attempt),
            'cursor' => self::cursor_or_empty($cursor),
            'recorded_at' => gmdate('Y-m-d H:i:s', (int) $timestamp),
        ), false);
    }

    private static function valid_school_status_payload($payload) {
        if (!is_array($payload)) {
            return false;
        }
        $expected_keys = array('version', 'schema_version', 'cursor', 'last_successful_sync_at', 'latest_run', 'pending_wakeups');
        $actual_keys = array_keys($payload);
        sort($expected_keys, SORT_STRING);
        sort($actual_keys, SORT_STRING);
        if ($expected_keys !== $actual_keys
            || 1 !== (int) $payload['version']
            || !is_string($payload['schema_version'])
            || strlen($payload['schema_version']) > 40
            || self::cursor_or_empty($payload['cursor']) === ''
            || !is_int($payload['pending_wakeups'])
            || $payload['pending_wakeups'] < 0
            || null === self::iso8601_or_null($payload['last_successful_sync_at']) && null !== $payload['last_successful_sync_at']
        ) {
            return false;
        }

        if (null === $payload['latest_run']) {
            return true;
        }
        if (!is_array($payload['latest_run'])) {
            return false;
        }
        $expected_run_keys = array('status', 'completed_at', 'error_code');
        $actual_run_keys = array_keys($payload['latest_run']);
        sort($expected_run_keys, SORT_STRING);
        sort($actual_run_keys, SORT_STRING);
        if ($expected_run_keys !== $actual_run_keys
            || !in_array((string) $payload['latest_run']['status'], array('RUNNING', 'SUCCEEDED', 'FAILED'), true)
            || (null !== $payload['latest_run']['completed_at'] && null === self::iso8601_or_null($payload['latest_run']['completed_at']))
            || (null !== $payload['latest_run']['error_code'] && self::safe_error_code($payload['latest_run']['error_code']) !== $payload['latest_run']['error_code'])
        ) {
            return false;
        }
        return true;
    }

    private static function cursor_or_empty($value) {
        $value = (string) $value;
        return preg_match('/^(?:0|[1-9][0-9]{0,18})$/D', $value) ? $value : '';
    }

    private static function safe_error_code($value) {
        $value = (string) $value;
        return $value === '' || preg_match('/^[A-Za-z0-9_]{1,80}$/D', $value) ? $value : 'unknown_error';
    }

    private static function mysql_utc_or_null($value) {
        if (!is_string($value) || '' === $value || false === strtotime($value . ' UTC')) {
            return null;
        }
        return gmdate('c', (int) strtotime($value . ' UTC'));
    }

    private static function iso8601_or_null($value) {
        if (!is_string($value) || '' === $value || false === strtotime($value)) {
            return null;
        }
        return gmdate('c', (int) strtotime($value));
    }

    private static function timestamp_or_null($value) {
        return is_int($value) && $value > 0 ? gmdate('c', $value) : null;
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
        if (false === $scheduled || (int) $scheduled > (int) $timestamp) {
            wp_schedule_single_event((int) $timestamp, self::CRON_HOOK);
        }
    }
}
