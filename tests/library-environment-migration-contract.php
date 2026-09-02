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

$assert(class_exists('MemberLibrary_Environment_Migration'), 'The Library migration service is not loaded.');
$assert(class_exists('MemberLibrary_Environment_Migration_Admin'), 'The Library migration admin is not loaded.');
(new MemberLibrary_Environment_Migration_Admin())->init();
$assert(false !== has_action('admin_post_tsol_library_migration_export'), 'The migration export action is not registered.');
$assert(false !== has_action('admin_post_tsol_library_migration_preview'), 'The migration preview action is not registered.');
$assert(false !== has_action('admin_post_tsol_library_migration_apply'), 'The migration apply action is not registered.');
$assert(false !== has_action('admin_post_tsol_library_migration_rollback'), 'The migration rollback action is not registered.');
$assert(false !== has_action('wp_ajax_tsol_library_migration_upload_chunk'), 'The chunked ZIP upload action is not registered.');
$assert(false !== has_action('wp_ajax_tsol_library_migration_prepare_attachments'), 'The resumable attachment preparation action is not registered.');
$assert(class_exists('ZipArchive'), 'The PHP Zip extension required for complete migration packages is unavailable.');

$migration = new MemberLibrary_Environment_Migration();
$package = $migration->build_package();
$json = $migration->encode($package);
$decoded = $migration->decode($json);
$preview = $migration->preview($decoded);
$post_count = count((array) ($package['data']['posts'] ?? array()));
$records_by_uuid = array();
foreach ((array) ($package['data']['posts'] ?? array()) as $record) {
    $records_by_uuid[(string) ($record['uuid'] ?? '')] = $record;
}

$assert('wordpress-library-only' === (string) ($package['manifest']['scope'] ?? ''), 'The package is not explicitly limited to WordPress Library data.');
$assert(MemberLibrary_Environment_Migration::SCHEMA_VERSION === (int) ($package['manifest']['schema_version'] ?? 0), 'The package does not use the current migration schema.');
$assert($post_count > 0, 'The package contains no WordPress Library records.');
$assert($post_count === (int) ($package['manifest']['counts']['posts'] ?? -1), 'The package post manifest count is incorrect.');
$assert(0 === (int) $preview['creates'], 'A self-preview would create unexpected Library records.');
$assert(0 === (int) $preview['updates'], 'A self-preview would update unexpected Library records.');
$assert(0 === (int) $preview['adoptions'], 'A self-preview would adopt unexpected Library identities.');
$assert($post_count === (int) $preview['unchanged'], 'A self-preview did not match every Library record by UUID.');
$assert(empty($preview['errors']), 'A self-preview contains blocking conflicts.');
$assert(empty($preview['missing_attachments']), 'A self-preview could not resolve its own WordPress attachments.');

$attachment_is_bundled = new ReflectionMethod(MemberLibrary_Environment_Migration::class, 'attachment_is_bundled');
$assert(true === $attachment_is_bundled->invoke($migration, array()), 'Legacy migration attachments no longer default to bundled.');
$assert(true === $attachment_is_bundled->invoke($migration, array('bundled' => true)), 'Bundled migration attachments are not recognized.');
$assert(false === $attachment_is_bundled->invoke($migration, array('bundled' => false)), 'Referenced migration videos are still treated as bundled files.');

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

$purchase_meta_keys = array(
    MemberLibrary_Content_Model::META_SALE_PRICE,
    MemberLibrary_Content_Model::META_PURCHASE_URL,
    MemberLibrary_Content_Model::META_PURCHASE_BUTTON_LABEL,
);
foreach (array(MemberLibrary_Content_Model::COURSE_POST_TYPE, MemberLibrary_Content_Model::SERIES_POST_TYPE) as $purchase_post_type) {
    $assert(
        array() === array_diff($purchase_meta_keys, MemberLibrary_Content_Model::metadata_keys_for_post_type($purchase_post_type)),
        'A Course or Series purchase-offer key is outside the portable metadata contract.'
    );
}
$assert(
    array() === array_values(array_intersect($purchase_meta_keys, MemberLibrary_Content_Model::metadata_keys_for_post_type(MemberLibrary_Content_Model::ITEM_POST_TYPE))),
    'Purchase-offer keys unexpectedly remain in the Content metadata contract.'
);

foreach ((array) ($package['data']['terms'] ?? array()) as $term) {
    if (MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY === (string) ($term['taxonomy'] ?? '')) {
        $assert(array_key_exists('appearance', (array) ($term['meta'] ?? array())), 'A portable Collection omitted its optional appearance.');
    }
}

$inherited_authority_count = 0;
foreach ((array) ($package['data']['posts'] ?? array()) as $record) {
    $meta = (array) ($record['meta'] ?? array());
    foreach (array(MemberLibrary_Content_Model::META_COURSE_ID, MemberLibrary_Content_Model::META_SERIES_ID) as $key) {
        $parent_uuid = (string) ($meta[$key]['__post_uuid'] ?? '');
        $parent_authority = (array) ($records_by_uuid[$parent_uuid]['legacy_authorization'] ?? array());
        if ('' !== $parent_uuid && !empty($parent_authority)) {
            $inherited_authority_count++;
            $assert($parent_authority === (array) ($record['legacy_authorization'] ?? array()), 'A child record did not inherit its parent’s portable authorization source.');
        }
    }
}
$assert($inherited_authority_count > 0, 'The migration fixture contains no inherited portable authorization relationships.');

$legacy_resolver = new ReflectionMethod(MemberLibrary_Environment_Migration::class, 'package_legacy_authorization_ref');
$compatibility_checked = false;
foreach ((array) ($package['data']['posts'] ?? array()) as $record) {
    $meta = (array) ($record['meta'] ?? array());
    $has_parent = isset($meta[MemberLibrary_Content_Model::META_COURSE_ID]['__post_uuid'])
        || isset($meta[MemberLibrary_Content_Model::META_SERIES_ID]['__post_uuid']);
    if (!$has_parent || empty($record['legacy_authorization'])) {
        continue;
    }
    $expected = $record['legacy_authorization'];
    $legacy_record = $record;
    $legacy_record['legacy_authorization'] = array();
    $recovered = $legacy_resolver->invoke($migration, $legacy_record, $records_by_uuid);
    $assert($expected === $recovered, 'An existing pre-0.4.4 ZIP cannot recover a child record authorization source from its parent UUID.');
    $compatibility_checked = true;
    break;
}
$assert($compatibility_checked, 'The migration fixture contains no parent-authorized record for backward-compatibility testing.');

$with_lock = new ReflectionMethod(MemberLibrary_Environment_Migration::class, 'with_lock');
delete_option(MemberLibrary_Environment_Migration::LOCK_OPTION);
add_option(
    MemberLibrary_Environment_Migration::LOCK_OPTION,
    time() - MemberLibrary_Environment_Migration::LOCK_TTL - 1,
    '',
    'no'
);
$stale_lock_result = $with_lock->invoke($migration, static function () {
    return 'stale-lock-recovered';
});
$assert('stale-lock-recovered' === $stale_lock_result, 'An interrupted migration lock was not recovered after its safety window.');
$assert(false === get_option(MemberLibrary_Environment_Migration::LOCK_OPTION, false), 'The recovered migration lock was not released.');

add_option(MemberLibrary_Environment_Migration::LOCK_OPTION, time(), '', 'no');
$active_lock_blocked = false;
try {
    $with_lock->invoke($migration, static function () {
        return 'must-not-run';
    });
} catch (Throwable $exception) {
    $active_lock_blocked = false !== strpos($exception->getMessage(), 'Another Library migration operation is running.');
}
delete_option(MemberLibrary_Environment_Migration::LOCK_OPTION);
$assert($active_lock_blocked, 'An active migration lock did not retain exclusive access.');

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
WP_CLI::success('The portable package contains only WordPress-owned Library data and round-trips unchanged.');
