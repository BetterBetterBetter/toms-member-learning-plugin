<?php
/** Contract for the draft-first Library Access Groups feature. */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$service = new MemberLibrary_Access_Groups();
$configuration = $service->configuration();
$preview = $service->preview();
$definitions = $service->definitions();
$groups = $service->groups();
$memberships = $service->memberships();

$assert($service->is_bootstrapped(), 'Access Groups were not bootstrapped.');
$assert(MemberLibrary_Access_Groups::SCHEMA_VERSION === (int) ($configuration['schema_version'] ?? 0), 'The Access Groups schema version changed.');
$assert(isset($definitions['library:all']), 'The Entire Library group is missing.');
$assert(isset($definitions['collection:masterclasses']), 'The All Masterclasses group is missing.');
$assert(isset($definitions['series:all']), 'The All Series group is missing.');
$assert(8 === count($groups), 'The complete current policy was not consolidated into eight administrator-facing Access Groups.');
foreach ($groups as $group_id => $group) {
    $assert($group_id === (string) ($group['id'] ?? ''), 'An Access Group lost its stable ID.');
    $assert('' !== (string) ($group['name'] ?? ''), 'An Access Group has no administrator-facing name.');
    $assert(!empty($group['scopes']), 'An Access Group has no Library access scope.');
    foreach ((array) ($group['scopes'] ?? array()) as $scope_key) {
        $assert(isset($definitions[$scope_key]), 'An Access Group references an unknown Library access scope.');
    }
}
foreach ((array) ($configuration['assignments'] ?? array()) as $group_ids) {
    foreach ((array) $group_ids as $group_id) {
        $assert(isset($groups[$group_id]), 'A membership references an unknown Access Group.');
    }
}
$assert(45 === count($memberships), 'The current MemberPress membership inventory changed.');
$assert(39 === (int) $preview['assigned_memberships'], 'The current rule import did not assign all 39 Library memberships.');
$assert(6 === count((array) $preview['unassigned_membership_ids']), 'The six memberships without current Library access were not flagged as unassigned.');
$assert(9 === (int) $preview['compiled_rule_count'], 'The imported groups did not compile to nine rules.');
$assert(119 === (int) $preview['compiled_condition_count'], 'The imported groups did not preserve all 119 access conditions.');
$assert(1 === (int) $preview['preserved_exception_count'], 'The non-membership access exception was not preserved.');
$assert(empty($preview['unmanaged_rule_ids']), 'A published Library rule remains outside Access Groups.');

$state = (array) ($preview['stage'] ?? array());
if (in_array((string) ($state['phase'] ?? ''), array('staged', 'active'), true)) {
    $verification = $service->verify_stage();
    $assert(9 === (int) $verification['rules_verified'], 'The complete compiled rule set was not verified.');
    $assert(0 === (int) $verification['matrix']['allow_to_deny'], 'The grouped rules would remove current access.');
    $assert(0 === (int) $verification['matrix']['deny_to_allow'], 'The grouped rules would add unintended current access.');
    $assert(count($memberships) > 0 && (int) $verification['matrix']['decisions_checked'] > 0, 'The current-user access matrix was not executed.');
}

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}

WP_CLI::line(wp_json_encode(array(
    'scope' => 'tsol-library-access-groups',
    'status' => (string) ($configuration['status'] ?? 'unknown'),
    'access_groups' => count($groups),
    'available_scopes' => count($definitions),
    'memberships' => count($memberships),
    'assigned_memberships' => (int) $preview['assigned_memberships'],
    'unassigned_memberships' => count((array) $preview['unassigned_membership_ids']),
    'compiled_rules' => (int) $preview['compiled_rule_count'],
    'compiled_conditions' => (int) $preview['compiled_condition_count'],
    'preserved_exceptions' => (int) $preview['preserved_exception_count'],
    'stage_phase' => (string) ($state['phase'] ?? 'draft'),
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
WP_CLI::success('Library Access Groups preserve the imported MemberPress policy.');
