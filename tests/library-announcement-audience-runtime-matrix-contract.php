<?php
/**
 * Full local Phase 1 truth/access matrix for announcement audiences.
 *
 * The contract emits aggregate counts only. It never prints identities,
 * membership IDs, titles, rules, or per-user decisions and performs no writes.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

global $wpdb;

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};
$user_ids = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->users} ORDER BY ID ASC"));
$user_count = count($user_ids);
$decisions = 0;
$administrator_decisions = 0;
$content_targets = 0;
$membership_segments = 0;

$candidate_map = static function ($definition) use ($assert, $user_count) {
    $after_user_id = 0;
    $candidates = array();
    $scanned = 0;
    do {
        $page = MemberLibrary_Announcement_Audience_Resolver::page($definition, $after_user_id, 200);
        $assert(!is_wp_error($page), 'A resolver page failed during the full matrix.');
        if (is_wp_error($page)) {
            return array();
        }
        $scanned += (int) $page['scannedCount'];
        foreach ($page['candidates'] as $candidate) {
            $user_id = (int) $candidate['wordpressUserId'];
            $assert(!isset($candidates[$user_id]), 'A WordPress user appeared in multiple resolver pages.');
            $candidates[$user_id] = $candidate;
        }
        $after_user_id = (int) $page['nextAfterUserId'];
        $has_more = !empty($page['hasMore']);
    } while ($has_more);
    $assert($user_count === $scanned, 'The resolver did not scan the complete current WordPress population.');
    return $candidates;
};

$all_linked_definition = array(
    'schemaVersion' => 1,
    'groups' => array(array('all' => array(array('type' => 'AUTHENTICATED_SCHOOL_USER')))),
    'exclude' => array(),
);
$all_candidates = $candidate_map($all_linked_definition);
$assert($user_count === count($all_candidates), 'The School-account condition did not leave every WordPress identity for School-side linking.');
foreach ($user_ids as $user_id) {
    $decisions++;
    $assert(isset($all_candidates[$user_id]) && array('g0') === $all_candidates[$user_id]['groups'], 'The School-account pass-through truth table drifted.');
    $expected_admin = (bool) user_can($user_id, 'manage_options');
    $actual_admin = isset($all_candidates[$user_id]) && !empty($all_candidates[$user_id]['administrator']);
    $assert($expected_admin === $actual_admin, 'The aggregate administrator marker drifted.');
}

$relationship_definition = array(
    'schemaVersion' => 1,
    'groups' => array(array('all' => array(array(
        'type' => 'ACTIVE_RELATIONSHIP',
        'contentUuid' => 'ef10c886-11ca-498a-ac6c-408a624132bc',
        'targetType' => 'course',
    )))),
    'exclude' => array(),
);
$relationship_candidates = $candidate_map($relationship_definition);
$assert($user_count === count($relationship_candidates), 'The relationship condition was incorrectly evaluated by WordPress instead of the School.');
$decisions += $user_count;

$content_posts = get_posts(array(
    'post_type' => array(MemberLibrary_Content_Model::COURSE_POST_TYPE, MemberLibrary_Content_Model::SERIES_POST_TYPE),
    'post_status' => 'publish',
    'posts_per_page' => -1,
    'orderby' => 'ID',
    'order' => 'ASC',
    'no_found_rows' => true,
    'suppress_filters' => true,
));
foreach ($content_posts as $content_post) {
    $uuid = strtolower((string) get_post_meta($content_post->ID, MemberLibrary_Content_Model::META_UUID, true));
    $assert((bool) preg_match('/^[0-9a-f-]{36}$/', $uuid), 'A published Course or Series is missing its audience UUID.');
    if (!preg_match('/^[0-9a-f-]{36}$/', $uuid)) {
        continue;
    }
    $definition = array(
        'schemaVersion' => 1,
        'groups' => array(array('all' => array(array('type' => 'CAN_ACCESS_CONTENT', 'contentUuid' => $uuid)))),
        'exclude' => array(),
    );
    $candidates = $candidate_map($definition);
    foreach ($user_ids as $user_id) {
        $canonical = MemberLibrary_Auth_Entitlements::for_content($user_id, (int) $content_post->ID);
        $assert(!is_wp_error($canonical), 'Canonical content access was unavailable during the audience matrix.');
        if (is_wp_error($canonical)) {
            continue;
        }
        $expected = !empty($canonical['can_access']);
        $actual = isset($candidates[$user_id]);
        $assert($expected === $actual, 'An audience content-access decision differed from the canonical entitlement decision.');
        if ($expected && user_can($user_id, 'manage_options')) {
            $administrator_decisions++;
            $assert(!empty($candidates[$user_id]['administrator']), 'A matching administrator lost the aggregate administrator marker.');
        }
        $decisions++;
    }
    $content_targets++;
}

$membership_ids = array_map('intval', get_posts(array(
    'post_type' => 'memberpressproduct',
    'post_status' => 'publish',
    'posts_per_page' => -1,
    'orderby' => 'ID',
    'order' => 'ASC',
    'fields' => 'ids',
    'no_found_rows' => true,
    'suppress_filters' => true,
)));
foreach (array_chunk($membership_ids, MemberLibrary_Announcement_Audience_Contract::MAX_MEMBERSHIPS) as $segment) {
    if (empty($segment)) {
        continue;
    }
    $definition = array(
        'schemaVersion' => 1,
        'groups' => array(array('all' => array(array('type' => 'ACTIVE_MEMBERSHIP', 'membershipIds' => $segment)))),
        'exclude' => array(),
    );
    $candidates = $candidate_map($definition);
    foreach ($user_ids as $user_id) {
        $active = array_map('intval', (array) (new MeprUser($user_id))->active_product_subscriptions());
        $expected = !empty(array_intersect($segment, $active));
        $assert($expected === isset($candidates[$user_id]), 'An active-membership audience decision differed from MemberPress.');
        $decisions++;
    }
    $membership_segments++;
}

$unpublished_membership_ids = array_map('intval', get_posts(array(
    'post_type' => 'memberpressproduct',
    'post_status' => array('draft', 'pending', 'private', 'future', 'draft-revision'),
    'posts_per_page' => -1,
    'orderby' => 'ID',
    'order' => 'ASC',
    'fields' => 'ids',
    'no_found_rows' => true,
    'suppress_filters' => true,
)));
if (!empty($unpublished_membership_ids)) {
    $unpublished_definition = array(
        'schemaVersion' => 1,
        'groups' => array(array('all' => array(array(
            'type' => 'ACTIVE_MEMBERSHIP',
            'membershipIds' => array($unpublished_membership_ids[0]),
        )))),
        'exclude' => array(),
    );
    $assert(
        is_wp_error(MemberLibrary_Announcement_Audience_Resolver::page($unpublished_definition, 0, 3)),
        'An unpublished MemberPress membership remained eligible for announcement targeting.'
    );
}

$specific_ids = array_slice($user_ids, 0, min(MemberLibrary_Announcement_Audience_Contract::MAX_SPECIFIC_USERS, $user_count));
$excluded_ids = array_slice($specific_ids, 0, min(10, count($specific_ids)));
if (!empty($specific_ids)) {
    $definition = array(
        'schemaVersion' => 1,
        'groups' => array(array('all' => array(array('type' => 'SPECIFIC_USERS', 'wordpressUserIds' => $specific_ids)))),
        'exclude' => empty($excluded_ids) ? array() : array(array('type' => 'SPECIFIC_USERS', 'wordpressUserIds' => $excluded_ids)),
    );
    $candidates = $candidate_map($definition);
    foreach ($user_ids as $user_id) {
        $expected = in_array($user_id, $specific_ids, true);
        $assert($expected === isset($candidates[$user_id]), 'The bounded specific-user truth table drifted.');
        if ($expected) {
            $assert(
                in_array($user_id, $excluded_ids, true) === !empty($candidates[$user_id]['excluded']),
                'A global specific-user exclusion did not win.'
            );
        }
        $decisions++;
    }
}

if (!empty($failures)) {
    $unique_failures = array_values(array_unique($failures));
    foreach (array_slice($unique_failures, 0, 20) as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error(sprintf(
        'TSOL announcement audience runtime matrix failed with %d aggregate assertion failures.',
        count($failures)
    ));
}

WP_CLI::success(sprintf(
    'TSOL announcement audience runtime matrix passed: %d users, %d published targets, %d membership segments, %d decisions, %d matching administrator decisions, zero mismatches.',
    $user_count,
    $content_targets,
    $membership_segments,
    $decisions,
    $administrator_decisions
));
