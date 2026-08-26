<?php
/** Contract for assigning a reusable Library Access Group to a membership. */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$service = new TSOL_Library_Access_Groups();
$before_configuration = $service->configuration();
if (!$service->is_bootstrapped()) {
    WP_CLI::error('Access Groups must be bootstrapped before running this contract.');
}

$preview = $service->preview();
$unassigned_membership_id = (int) reset($preview['unassigned_membership_ids']);
$group_id = (string) array_key_first($service->groups());
if ($unassigned_membership_id <= 0 || '' === $group_id) {
    WP_CLI::error('The membership-assignment fixture could not be resolved.');
}

try {
    $result = $service->save_membership_assignments(
        $unassigned_membership_id,
        array($group_id),
        $before_configuration['revision']
    );
    $after_configuration = $service->configuration();
    $assigned = $service->membership_group_ids($unassigned_membership_id);

    if (array($group_id) !== $assigned) {
        throw new RuntimeException('The Access Group was not assigned to the membership.');
    }
    if ((string) $before_configuration['revision'] === (string) $after_configuration['revision']) {
        throw new RuntimeException('The membership assignment did not create a fresh draft revision.');
    }
    if ('draft' !== (string) ($result['status'] ?? '')) {
        throw new RuntimeException('The membership assignment was not kept in draft state.');
    }

    WP_CLI::line(wp_json_encode(array(
        'scope' => 'tsol-library-access-groups-membership-assignment',
        'status' => 'passed',
        'membership_id' => $unassigned_membership_id,
        'group_id' => $group_id,
    ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
} finally {
    update_option(TSOL_Library_Access_Groups::OPTION_NAME, $before_configuration, false);
}

WP_CLI::success('A MemberPress membership can receive a reusable Library Access Group without changing live rules.');
