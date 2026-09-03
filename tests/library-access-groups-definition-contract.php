<?php
/** Contract for creating a new reusable Library Access Group in draft state. */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$service = new MemberLibrary_Access_Groups();
$before_configuration = $service->configuration();
if (!$service->is_bootstrapped()) {
    WP_CLI::error('Access Groups must be bootstrapped before running this contract.');
}

$raw_groups = array_values($service->groups());
$raw_groups[] = array(
    'id' => '',
    'name' => 'Contract Test Complete Library',
    'description' => 'Temporary draft-only contract fixture.',
    'scopes' => array('library:all'),
);

try {
    $before_preview = $service->preview();
    $service->save_groups($raw_groups, $before_configuration['revision']);
    $after_preview = $service->preview();
    $after_groups = $service->groups();
    $created = array_filter($after_groups, static function ($group) {
        return 'Contract Test Complete Library' === (string) ($group['name'] ?? '');
    });

    if (1 !== count($created)) {
        throw new RuntimeException('The new Access Group was not created exactly once.');
    }
    $created_group = reset($created);
    if (array('library:all') !== (array) ($created_group['scopes'] ?? array())) {
        throw new RuntimeException('The new Access Group did not preserve its Library scope.');
    }
    if ((int) $before_preview['compiled_rule_count'] !== (int) $after_preview['compiled_rule_count']
        || (int) $before_preview['compiled_condition_count'] !== (int) $after_preview['compiled_condition_count']
    ) {
        throw new RuntimeException('An unassigned Access Group changed the compiled MemberPress policy.');
    }

    WP_CLI::line(wp_json_encode(array(
        'scope' => 'tsol-library-access-groups-definition',
        'status' => 'passed',
        'group_count' => count($after_groups),
        'compiled_rules' => (int) $after_preview['compiled_rule_count'],
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} finally {
    update_option(MemberLibrary_Access_Groups::OPTION_NAME, $before_configuration, false);
}

// Access Groups must not fail closed on a brand-specific collection term.
$precondition = new ReflectionMethod(MemberLibrary_Access_Groups::class, 'assert_memberpress');
try {
    $precondition->invoke(new MemberLibrary_Access_Groups());
} catch (Throwable $exception) {
    if (false !== strpos($exception->getMessage(), 'Masterclasses')) {
        throw new RuntimeException('Access Groups still fail closed on the TSOL-only Masterclasses collection.');
    }
    throw $exception;
}
$expand = new ReflectionMethod(MemberLibrary_Access_Groups::class, 'expand_group_keys');
$scope_service = new MemberLibrary_Access_Groups();
$scope_definitions = $scope_service->definitions();
foreach ((array) $expand->invoke($scope_service, array('library:all'), $scope_definitions) as $expanded_key) {
    if (!isset($scope_definitions[$expanded_key])) {
        throw new RuntimeException('The Entire Library scope expands to an undefined access scope: ' . $expanded_key);
    }
}

WP_CLI::success('A new unassigned Access Group remains a non-destructive draft.');
