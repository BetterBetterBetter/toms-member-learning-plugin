<?php
/** Contract for WordPress-only Library environment migration packages. */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(class_exists('TSOL_Library_Environment_Migration'), 'The Library migration service is not loaded.');
$assert(class_exists('TSOL_Library_Environment_Migration_Admin'), 'The Library migration admin is not loaded.');
(new TSOL_Library_Environment_Migration_Admin())->init();
$assert(false !== has_action('admin_post_tsol_library_migration_export'), 'The migration export action is not registered.');
$assert(false !== has_action('admin_post_tsol_library_migration_preview'), 'The migration preview action is not registered.');
$assert(false !== has_action('admin_post_tsol_library_migration_apply'), 'The migration apply action is not registered.');
$assert(false !== has_action('admin_post_tsol_library_migration_rollback'), 'The migration rollback action is not registered.');
$assert(false !== has_action('wp_ajax_tsol_library_migration_upload_chunk'), 'The chunked ZIP upload action is not registered.');
$assert(class_exists('ZipArchive'), 'The PHP Zip extension required for complete migration packages is unavailable.');

$migration = new TSOL_Library_Environment_Migration();
$package = $migration->build_package();
$json = $migration->encode($package);
$decoded = $migration->decode($json);
$preview = $migration->preview($decoded);
$post_count = count((array) ($package['data']['posts'] ?? array()));

$assert('wordpress-library-only' === (string) ($package['manifest']['scope'] ?? ''), 'The package is not explicitly limited to WordPress Library data.');
$assert($post_count > 0, 'The package contains no WordPress Library records.');
$assert($post_count === (int) ($package['manifest']['counts']['posts'] ?? -1), 'The package post manifest count is incorrect.');
$assert(0 === (int) $preview['creates'], 'A self-preview would create unexpected Library records.');
$assert(0 === (int) $preview['updates'], 'A self-preview would update unexpected Library records.');
$assert(0 === (int) $preview['adoptions'], 'A self-preview would adopt unexpected Library identities.');
$assert($post_count === (int) $preview['unchanged'], 'A self-preview did not match every Library record by UUID.');
$assert(empty($preview['errors']), 'A self-preview contains blocking conflicts.');
$assert(empty($preview['missing_attachments']), 'A self-preview could not resolve its own WordPress attachments.');

foreach (array(
    'TSOL_LIBRARY_CLIENT_SECRET',
    'tsol_library_auth_client_secret',
    'better_auth',
    'watch_progress',
    'video_notes',
    'learning_relationship',
    'memberpress_transaction',
) as $forbidden) {
    $assert(false === stripos($json, $forbidden), 'The package contains excluded app, secret, or transaction data: ' . $forbidden);
}

foreach ((array) ($package['data']['access_groups']['assignments'] ?? array()) as $membership_slug => $group_ids) {
    $assert(!is_numeric($membership_slug), 'A portable membership assignment contains a source-site numeric ID.');
    $assert(sanitize_title((string) $membership_slug) === (string) $membership_slug, 'A membership assignment is not addressed by stable slug.');
    $assert(!empty($group_ids), 'A portable membership assignment has no Access Groups.');
}

foreach ((array) ($package['data']['posts'] ?? array()) as $record) {
    $assert(!empty($record['uuid']), 'A Library record is missing its stable UUID.');
    $assert(!isset($record['ID']), 'A Library record exposes a source WordPress post ID.');
    foreach ((array) ($record['speaker_uuids'] ?? array()) as $speaker_uuid) {
        $assert(!is_numeric($speaker_uuid), 'A Speaker relationship contains a source WordPress post ID.');
    }
}

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}

WP_CLI::line(wp_json_encode(array(
    'scope' => (string) $package['manifest']['scope'],
    'posts' => $post_count,
    'terms' => count((array) ($package['data']['terms'] ?? array())),
    'groups' => count((array) ($package['data']['access_groups']['groups'] ?? array())),
    'membership_assignments' => count((array) ($package['data']['access_groups']['assignments'] ?? array())),
    'bytes' => strlen($json),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
WP_CLI::success('The portable package contains only WordPress-owned TSOL Library data and round-trips unchanged.');
