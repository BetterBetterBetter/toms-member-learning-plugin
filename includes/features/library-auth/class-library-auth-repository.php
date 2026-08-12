<?php
/**
 * One-use authorization code storage.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Auth_Repository {

    public const SCHEMA_VERSION = '3';

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'tsol_library_auth_codes';
    }

    public static function messages_table() {
        global $wpdb;
        return $wpdb->prefix . 'tsol_library_auth_messages';
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        $charset = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            code_hash char(64) NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            client_id varchar(191) NOT NULL,
            redirect_uri varchar(500) NOT NULL,
            code_challenge varchar(128) NOT NULL,
            expires_at datetime NOT NULL,
            consumed_at datetime NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY code_hash (code_hash),
            KEY expires_at (expires_at),
            KEY consumed_at (consumed_at)
        ) {$charset};");

        $messages_table = self::messages_table();
        dbDelta("CREATE TABLE {$messages_table} (
            jti char(36) NOT NULL,
            event varchar(64) NOT NULL,
            expires_at datetime NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (jti),
            KEY expires_at (expires_at)
        ) {$charset};");
    }

    public static function create($user_id, $client_id, $redirect_uri, $challenge) {
        global $wpdb;

        try {
            $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch (Throwable $exception) {
            return new WP_Error('code_generation_failed', __('Could not create the sign-in code.', 'tomschooloflife-plugin'));
        }

        $now = time();
        $ok = $wpdb->insert(self::table(), array(
            'code_hash' => hash('sha256', $code),
            'user_id' => (int) $user_id,
            'client_id' => (string) $client_id,
            'redirect_uri' => (string) $redirect_uri,
            'code_challenge' => (string) $challenge,
            'expires_at' => gmdate('Y-m-d H:i:s', $now + 60),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ), array('%s', '%d', '%s', '%s', '%s', '%s', '%s'));

        return $ok ? $code : new WP_Error('code_storage_failed', __('Could not create the sign-in code.', 'tomschooloflife-plugin'));
    }

    public static function consume($code, $client_id, $redirect_uri, $verifier) {
        global $wpdb;
        $table = self::table();
        $hash = hash('sha256', (string) $code);
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE code_hash = %s LIMIT 1", $hash));
        if (!$row || $row->consumed_at !== null || strtotime($row->expires_at . ' UTC') < time()) {
            return new WP_Error('invalid_grant', __('The authorization code is invalid, expired, or already used.', 'tomschooloflife-plugin'));
        }

        $expected = rtrim(strtr(base64_encode(hash('sha256', (string) $verifier, true)), '+/', '-_'), '=');
        if (!hash_equals((string) $row->client_id, (string) $client_id) || !hash_equals((string) $row->redirect_uri, (string) $redirect_uri) || !hash_equals((string) $row->code_challenge, $expected)) {
            return new WP_Error('invalid_grant', __('The authorization code could not be verified.', 'tomschooloflife-plugin'));
        }

        $now = gmdate('Y-m-d H:i:s');
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET consumed_at = %s WHERE id = %d AND consumed_at IS NULL AND expires_at >= %s",
            $now,
            (int) $row->id,
            $now
        ));

        return $updated === 1 ? (int) $row->user_id : new WP_Error('invalid_grant', __('The authorization code is invalid, expired, or already used.', 'tomschooloflife-plugin'));
    }

    public static function cleanup() {
        global $wpdb;
        $table = self::table();
        $cutoff = gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS);
        $codes_deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE expires_at < %s", $cutoff));
        $messages_table = self::messages_table();
        $messages_deleted = $wpdb->query($wpdb->prepare("DELETE FROM {$messages_table} WHERE expires_at < %s", $cutoff));
        if (false === $codes_deleted || false === $messages_deleted) {
            return false;
        }
        return (int) $codes_deleted + (int) $messages_deleted;
    }

    public static function consume_message($jti, $event, $expires_at) {
        global $wpdb;

        if (!wp_is_uuid((string) $jti, 4) || !preg_match('/^[a-z0-9._-]{3,64}$/', (string) $event)) {
            return new WP_Error('invalid_message', __('The authentication message is invalid.', 'tomschooloflife-plugin'));
        }

        $now = time();
        $expires_at = (int) $expires_at;
        if ($expires_at < $now || $expires_at > $now + 10 * MINUTE_IN_SECONDS) {
            return new WP_Error('invalid_message', __('The authentication message is invalid or expired.', 'tomschooloflife-plugin'));
        }

        $inserted = $wpdb->insert(self::messages_table(), array(
            'jti' => strtolower((string) $jti),
            'event' => sanitize_key((string) $event),
            'expires_at' => gmdate('Y-m-d H:i:s', $expires_at),
            'created_at' => gmdate('Y-m-d H:i:s', $now),
        ), array('%s', '%s', '%s', '%s'));

        return false === $inserted
            ? new WP_Error('message_replay', __('The authentication message was already used.', 'tomschooloflife-plugin'))
            : true;
    }
}
