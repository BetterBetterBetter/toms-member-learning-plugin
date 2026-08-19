<?php
/**
 * Phase 1 bounded announcement-audience contract and read-only resolver checks.
 *
 * This contract prints aggregate results only and never creates announcements,
 * recipient rows, memberships, users, rules, or School records.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$course_uuid = 'ef10c886-11ca-498a-ac6c-408a624132bc';
$series_uuid = '8171be85-c669-491a-aeef-0834cd5e1093';
$raw = array(
    'schemaVersion' => 1,
    'groups' => array(
        array('all' => array(
            array('type' => 'SPECIFIC_USERS', 'wordpressUserIds' => array(9, 3, 9)),
            array('type' => 'AUTHENTICATED_SCHOOL_USER'),
        )),
        array('all' => array(
            array('type' => 'ACTIVE_MEMBERSHIP', 'membershipIds' => array(42, 8, 42)),
            array('type' => 'CAN_ACCESS_CONTENT', 'contentUuid' => strtoupper($course_uuid)),
        )),
        array('all' => array(
            array('type' => 'ACTIVE_RELATIONSHIP', 'contentUuid' => $series_uuid, 'targetType' => 'series'),
        )),
    ),
    'exclude' => array(array('type' => 'SPECIFIC_USERS', 'wordpressUserIds' => array(11, 7, 11))),
);

$normalized = TSOL_Library_Announcement_Audience_Contract::normalize($raw);
$assert(!is_wp_error($normalized), 'The valid bounded audience did not normalize.');
if (!is_wp_error($normalized)) {
    $assert(
        $normalized['exclude'] === array(array('type' => 'SPECIFIC_USERS', 'wordpressUserIds' => array(7, 11))),
        'Specific-user exclusions were not sorted and deduplicated.'
    );
    $hash = TSOL_Library_Announcement_Audience_Contract::hash($normalized);
    $assert((bool) preg_match('/^[a-f0-9]{64}$/', $hash), 'The normalized definition hash is not SHA-256.');
    $assert($hash === TSOL_Library_Announcement_Audience_Contract::hash($raw), 'Equivalent input did not produce the same hash.');
    $explanation = TSOL_Library_Announcement_Audience_Contract::explain($normalized);
    $assert(!is_wp_error($explanation), 'The normalized definition could not be explained.');
    $assert(isset($explanation['groups'][0]['token']) && 'g0' === $explanation['groups'][0]['token'], 'Group tokens are not deterministic.');
}

$cross_language_fixture = array(
    'schemaVersion' => 1,
    'groups' => array(
        array('all' => array(
            array('type' => 'AUTHENTICATED_SCHOOL_USER'),
            array('type' => 'SPECIFIC_USERS', 'wordpressUserIds' => array(9, 3, 9)),
        )),
        array('all' => array(
            array('type' => 'ACTIVE_MEMBERSHIP', 'membershipIds' => array(42, 8, 42)),
            array('type' => 'CAN_ACCESS_CONTENT', 'contentUuid' => strtoupper($course_uuid)),
        )),
    ),
    'exclude' => array(array('type' => 'SPECIFIC_USERS', 'wordpressUserIds' => array(11, 7, 11))),
);
$assert(
    'e7041a339c79d27169a39a7b65353e4a47b12156fc152e3842dd6589cb3da8c8'
        === TSOL_Library_Announcement_Audience_Contract::hash($cross_language_fixture),
    'The PHP audience hash drifted from the TypeScript fixture.'
);

$invalid = array(
    array('schemaVersion' => 2, 'groups' => array(array('all' => array(array('type' => 'AUTHENTICATED_SCHOOL_USER')))), 'exclude' => array()),
    array('schemaVersion' => 1, 'groups' => array(), 'exclude' => array()),
    array('schemaVersion' => 1, 'groups' => array(array('any' => array(array('type' => 'AUTHENTICATED_SCHOOL_USER')))), 'exclude' => array()),
    array('schemaVersion' => 1, 'groups' => array(array('all' => array(array('type' => 'WORDPRESS_ROLE', 'roles' => array('subscriber'))))), 'exclude' => array()),
    array('schemaVersion' => 1, 'groups' => array(array('all' => array(array('type' => 'CAN_ACCESS_CONTENT', 'contentUuid' => 'not-a-uuid')))), 'exclude' => array()),
    array('schemaVersion' => 1, 'groups' => array(array('all' => array(
        array('type' => 'CAN_ACCESS_CONTENT', 'contentUuid' => $course_uuid),
        array('type' => 'CAN_ACCESS_CONTENT', 'contentUuid' => $series_uuid),
    ))), 'exclude' => array()),
    array('schemaVersion' => 1, 'groups' => array(array('all' => array(array('type' => 'SPECIFIC_USERS', 'wordpressUserIds' => range(1, 101))))), 'exclude' => array()),
    array('schemaVersion' => 1, 'groups' => array(array('all' => array(array('type' => 'ACTIVE_MEMBERSHIP', 'membershipIds' => range(1, 21))))), 'exclude' => array()),
    array('schemaVersion' => 1, 'groups' => array(array('all' => array(array('type' => 'AUTHENTICATED_SCHOOL_USER', 'extra' => true)))), 'exclude' => array()),
    array('schemaVersion' => 1, 'groups' => array(array('all' => array(array('type' => 'AUTHENTICATED_SCHOOL_USER')))), 'exclude' => array(array('type' => 'ACTIVE_MEMBERSHIP', 'membershipIds' => array(1)))),
);
foreach ($invalid as $index => $definition) {
    $assert(is_wp_error(TSOL_Library_Announcement_Audience_Contract::normalize($definition)), 'Unsafe definition ' . $index . ' was accepted.');
}

$all_linked = array(
    'schemaVersion' => 1,
    'groups' => array(array('all' => array(array('type' => 'AUTHENTICATED_SCHOOL_USER')))),
    'exclude' => array(),
);
$page = TSOL_Library_Announcement_Audience_Resolver::page($all_linked, 0, 3);
$assert(!is_wp_error($page), 'The read-only resolver could not scan a bounded user page.');
if (!is_wp_error($page)) {
    $assert(1 === $page['schemaVersion'], 'The resolver response schema is not version 1.');
    $assert(3 === $page['scannedCount'], 'The resolver did not honor the bounded scan size.');
    $assert(3 === count($page['candidates']), 'The all-linked preset did not return every WordPress candidate in the scan page.');
    $assert($page['nextAfterUserId'] > 0, 'The resolver did not advance its cursor.');
    foreach ($page['candidates'] as $candidate) {
        $assert(array('g0') === $candidate['groups'], 'The resolver leaked condition details instead of an opaque group token.');
        $assert(is_bool($candidate['excluded']) && is_bool($candidate['administrator']), 'Resolver booleans were not canonical.');
    }
}

do_action('rest_api_init');
$routes = rest_get_server()->get_routes();
$route = '/tsol-library/v1/announcement-audience/candidates';
$assert(isset($routes[$route]), 'The server-only audience preview route is not registered.');

$request = new WP_REST_Request('POST', $route);
$request->set_header('origin', home_url('/'));
$request->set_body_params(array('definition' => $all_linked, 'afterUserId' => 0, 'perPage' => 3));
$response = rest_do_request($request);
$assert(403 === $response->get_status(), 'A browser-shaped unauthenticated request reached the audience resolver.');

if (!empty($failures)) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error('TSOL announcement audience contract checks failed.');
}

WP_CLI::success('TSOL announcement audience contract checks passed.');
