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
    const EVENT = 'catalogue.changes.available';
    const AUDIENCE = 'tsol-library-catalogue';
    const ENDPOINT_PATH = '/api/internal/catalogue/wake';
    const LOCK_SECONDS = 60;

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
    }

    public static function register_hooks() {
        add_action('tsol_library_catalogue_change_recorded', array(__CLASS__, 'queue_change'), 10, 1);
        add_action('shutdown', array(__CLASS__, 'flush_queued_change'), 20);
        add_action(self::CRON_HOOK, array(__CLASS__, 'deliver_pending'));
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
        $inserted = $wpdb->insert(
            self::table(),
            array(
                'delivery_id' => wp_generate_uuid4(),
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

        if ((!defined('WP_CLI') || !WP_CLI) && !wp_doing_cron() && function_exists('spawn_cron')) {
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
        ));
        self::schedule_next_pending();
    }

    private static function send($delivery_id, $cursor, $timestamp) {
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
            'user-agent' => 'TSOL-Library-Catalogue-Webhook/' . TSOL_SITE_PLUGIN_VERSION,
        ));
        if (is_wp_error($response)) {
            return array('success' => false, 'status_code' => 0, 'error_code' => 'transport_failed');
        }

        $status_code = (int) wp_remote_retrieve_response_code($response);
        return array(
            'success' => $status_code >= 200 && $status_code < 300,
            'status_code' => $status_code,
            'error_code' => $status_code >= 200 && $status_code < 300 ? '' : 'http_error',
        );
    }

    private static function endpoint_url() {
        $app_url = TSOL_Library_Auth_Settings::app_url();
        $url = $app_url === '' ? '' : $app_url . self::ENDPOINT_PATH;
        return (string) apply_filters('tsol_library_catalogue_webhook_url', $url);
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
