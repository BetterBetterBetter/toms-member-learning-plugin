<?php
/** Contract that publication blocks when a live Library rule is unmanaged. */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$service = new MemberLibrary_Access_Groups();
$before_configuration = $service->configuration();
$before_stage = get_option(MemberLibrary_Access_Groups::STAGE_OPTION, null);
if (!$service->is_bootstrapped() || !empty($before_stage)) {
    WP_CLI::error('Run this contract against a bootstrapped draft with no staged rules.');
}

$source_rule_ids = array_values(array_map('intval', (array) ($before_configuration['source_rule_ids'] ?? array())));
if (count($source_rule_ids) < 2) {
    WP_CLI::error('The ownership-boundary fixture could not resolve multiple source rules.');
}

$test_configuration = $before_configuration;
$unmanaged_rule_id = (int) array_pop($test_configuration['source_rule_ids']);
update_option(MemberLibrary_Access_Groups::OPTION_NAME, $test_configuration, false);

try {
    $preview = $service->preview();
    if (!in_array($unmanaged_rule_id, (array) $preview['unmanaged_rule_ids'], true)) {
        throw new RuntimeException('The published rule removed from ownership was not detected.');
    }

    $blocked = false;
    try {
        $service->stage();
    } catch (RuntimeException $exception) {
        $blocked = false !== strpos($exception->getMessage(), 'outside Access Groups');
    }
    if (!$blocked) {
        throw new RuntimeException('Staging was not blocked by the unmanaged Library rule.');
    }
    if (!empty(get_option(MemberLibrary_Access_Groups::STAGE_OPTION, array()))) {
        throw new RuntimeException('A failed ownership check left staged rule state behind.');
    }

    WP_CLI::line(wp_json_encode(array(
        'scope' => 'tsol-library-access-groups-ownership-boundary',
        'status' => 'passed',
        'detected_rule_id' => $unmanaged_rule_id,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} finally {
    update_option(MemberLibrary_Access_Groups::OPTION_NAME, $before_configuration, false);
    if (null === $before_stage) {
        delete_option(MemberLibrary_Access_Groups::STAGE_OPTION);
    } else {
        update_option(MemberLibrary_Access_Groups::STAGE_OPTION, $before_stage, false);
    }
}

WP_CLI::success('Unmanaged published Library rules block Access Groups publication safely.');
