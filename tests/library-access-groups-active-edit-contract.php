<?php
/** Contract for editing a published Access Groups configuration. */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$service = new MemberLibrary_Access_Groups();
$before_configuration = get_option(MemberLibrary_Access_Groups::OPTION_NAME);
$before_stage = get_option(MemberLibrary_Access_Groups::STAGE_OPTION, null);

if (!is_array($before_configuration) || !$service->is_bootstrapped()) {
    WP_CLI::error('Access Groups must be bootstrapped before running this contract.');
}

$source_rules = (array) ($before_configuration['source_rules'] ?? array());
if (empty($source_rules)) {
    $migration_state = get_option('tsol_library_access_rules_migration_state', array());
    foreach ((array) ($migration_state['rule_ids_by_policy'] ?? array()) as $policy_key => $rule_id) {
        $rule_id = (int) $rule_id;
        if ($rule_id > 0 && 'publish' === get_post_status($rule_id)) {
            $source_rules[(string) $policy_key] = $rule_id;
        }
    }
}
if (empty($source_rules)) {
    WP_CLI::error('The active Access Groups source-rule map could not be resolved.');
}
ksort($source_rules, SORT_STRING);

$synthetic_active_stage = array(
    'schema_version' => MemberLibrary_Access_Groups::SCHEMA_VERSION,
    'phase' => 'active',
    'revision' => (string) $before_configuration['revision'],
    'source_rule_ids' => array_values(array_map('intval', $source_rules)),
    'created_rule_ids' => array_values(array_map('intval', $source_rules)),
    'rule_ids_by_policy' => $source_rules,
    'activated_at' => gmdate('Y-m-d H:i:s'),
);

update_option(MemberLibrary_Access_Groups::STAGE_OPTION, $synthetic_active_stage, false);

try {
    $result = $service->save_groups(
        $before_configuration['groups'],
        $before_configuration['revision']
    );
    $after_configuration = $service->configuration();

    if (null !== get_option(MemberLibrary_Access_Groups::STAGE_OPTION, null)) {
        throw new RuntimeException('Saving did not clear the prior active stage.');
    }
    if ('draft' !== (string) ($after_configuration['status'] ?? '')) {
        throw new RuntimeException('Saving did not create a new draft.');
    }
    if ((string) $before_configuration['revision'] === (string) $after_configuration['revision']) {
        throw new RuntimeException('Saving did not create a fresh revision.');
    }
    if ($source_rules !== (array) ($after_configuration['source_rules'] ?? array())) {
        throw new RuntimeException('The published rules were not promoted to the next draft baseline.');
    }
    if ($before_configuration['groups'] !== $after_configuration['groups']) {
        throw new RuntimeException('Saving changed the Access Group definitions.');
    }
    if (empty($after_configuration['history'])) {
        throw new RuntimeException('The superseded baseline was not added to history.');
    }

    WP_CLI::line(wp_json_encode(array(
        'scope' => 'tsol-library-access-groups-active-edit',
        'status' => 'passed',
        'new_revision' => (string) $result['revision'],
        'history_entries' => count((array) $after_configuration['history']),
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} finally {
    update_option(MemberLibrary_Access_Groups::OPTION_NAME, $before_configuration, false);
    if (null === $before_stage) {
        delete_option(MemberLibrary_Access_Groups::STAGE_OPTION);
    } else {
        update_option(MemberLibrary_Access_Groups::STAGE_OPTION, $before_stage, false);
    }
}

WP_CLI::success('An active Access Groups configuration can become the safe baseline for its next draft.');
