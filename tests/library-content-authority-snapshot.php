<?php
/**
 * Privacy-safe, read-only authority and normalization footprint snapshot.
 *
 * Run against the working site and the untouched control through WP-CLI.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this snapshot through WP-CLI.');
}

global $wpdb;

$rule_rows = $wpdb->get_results(
    "SELECT ID, post_status, post_title, post_name, post_parent, post_modified_gmt FROM {$wpdb->posts} WHERE post_type = 'memberpressrule' ORDER BY ID",
    ARRAY_A
);
$condition_rows = $wpdb->get_results(
    "SELECT * FROM {$wpdb->prefix}mepr_rule_access_conditions ORDER BY id",
    ARRAY_A
);
$version_rows = $wpdb->get_results($wpdb->prepare(
    "SELECT meta_value AS version, COUNT(DISTINCT post_id) AS posts FROM {$wpdb->postmeta} WHERE meta_key = %s GROUP BY meta_value ORDER BY meta_value",
    '_tsol_library_migration_version'
), ARRAY_A);

$report = array(
    'users' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
    'rules' => count($rule_rows),
    'products' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'memberpressproduct'"),
    'conditions' => count($condition_rows),
    'transactions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mepr_transactions"),
    'subscriptions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}mepr_subscriptions"),
    'rule_fingerprint' => hash('sha256', serialize(array($rule_rows, $condition_rows))),
    'library_items' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'tsol_library_item'"),
    'migration_versions' => array_map(static function ($row) {
        return array(
            'version' => (string) $row['version'],
            'posts' => (int) $row['posts'],
        );
    }, $version_rows),
);

WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
