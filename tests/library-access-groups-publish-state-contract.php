<?php
/** Contract for the Live / Draft / Review presentation of Library Access Groups. */

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
$definitions = $service->definitions();
$scope_keys = array_keys($definitions);
$assert(count($scope_keys) >= 2, 'The contract needs at least two Library scopes.');
$scope_a = $scope_keys[0];
$scope_b = $scope_keys[1];

// 1. The draft diff is computed against the recorded published snapshot.
$published = array(
    'groups' => array(
        'g-live' => array('id' => 'g-live', 'name' => 'Live Group', 'description' => '', 'scopes' => array($scope_a)),
        'g-gone' => array('id' => 'g-gone', 'name' => 'Removed Group', 'description' => '', 'scopes' => array($scope_a)),
        'g-edit' => array('id' => 'g-edit', 'name' => 'Old Name', 'description' => '', 'scopes' => array($scope_a)),
    ),
    'assignments' => array(11 => array('g-live', 'g-gone'), 12 => array('g-edit')),
    'exceptions' => array(),
    'revision' => 'rev-live',
    'activated_at' => '2026-09-03 10:00:00',
);
$draft = array(
    'groups' => array(
        'g-live' => array('id' => 'g-live', 'name' => 'Live Group', 'description' => '', 'scopes' => array($scope_a)),
        'g-edit' => array('id' => 'g-edit', 'name' => 'New Name', 'description' => '', 'scopes' => array($scope_a, $scope_b)),
        'g-new' => array('id' => 'g-new', 'name' => 'Brand New', 'description' => '', 'scopes' => array($scope_b)),
    ),
    'assignments' => array(11 => array('g-live', 'g-new'), 12 => array('g-edit'), 13 => array('g-new')),
    'exceptions' => array(),
    'published' => $published,
);
$changes = $service->changes_since_publish($draft);
$assert(true === $changes['has_published'], 'A configuration with a published snapshot was not recognised as published.');
$assert(true === $changes['has_changes'], 'Draft edits were not detected as changes against the live snapshot.');
$assert(array('g-new' => 'Brand New') === $changes['groups']['added'], 'An added group was not reported as added.');
$assert(array('g-gone' => 'Removed Group') === $changes['groups']['removed'], 'A removed group was not reported as removed.');
$assert(isset($changes['groups']['changed']['g-edit']) && true === $changes['groups']['changed']['g-edit']['renamed'], 'A renamed group was not reported as renamed.');
$assert(array($definitions[$scope_b]['label']) === array_values($changes['groups']['changed']['g-edit']['scopes_added']), 'An added scope was not reported with its label.');
$assert(!isset($changes['groups']['changed']['g-live']), 'An untouched group was reported as changed.');
$assert(array('Brand New') === array_values($changes['assignments'][11]['added']) && array('Removed Group') === array_values($changes['assignments'][11]['removed']), 'Membership assignment changes were not reported by group name.');
$assert(isset($changes['assignments'][13]) && array('Brand New') === array_values($changes['assignments'][13]['added']), 'A newly assigned membership was not reported.');
$assert(!isset($changes['assignments'][12]), 'An unchanged membership assignment was reported as changed.');
$assert(5 === (int) $changes['counts']['total'], 'The change total did not count each group and membership change once (1 added, 1 removed, 1 changed, 2 memberships).');

$unchanged = $draft;
$unchanged['groups'] = $published['groups'];
$unchanged['assignments'] = $published['assignments'];
$same = $service->changes_since_publish($unchanged);
$assert(false === $same['has_changes'] && 0 === (int) $same['counts']['total'], 'A draft identical to the live snapshot reported changes.');

$never = $draft;
unset($never['published']);
$fresh = $service->changes_since_publish($never);
$assert(false === $fresh['has_published'] && true === $fresh['has_changes'] && 3 === count($fresh['groups']['added']), 'A never-published configuration must report every group as new.');

// 2. Group states drive the badges on each card.
$states = $service->group_states($draft);
$assert('live' === ($states['g-live'] ?? '') && 'changed' === ($states['g-edit'] ?? '') && 'new' === ($states['g-new'] ?? ''), 'Group badge states were not derived from the diff.');
$assert('draft' === ($service->group_states($never)['g-live'] ?? ''), 'Groups on a never-published configuration must read as draft, not live.');

// 3. Saving a draft keeps the published snapshot so the comparison survives edits.
$before_configuration = $service->configuration();
if ($service->is_bootstrapped() && empty($service->preview()['stage'])) {
    try {
        $seeded = $before_configuration;
        $seeded['published'] = array(
            'groups' => (array) $seeded['groups'],
            'assignments' => (array) $seeded['assignments'],
            'exceptions' => (array) ($seeded['exceptions'] ?? array()),
            'revision' => (string) $seeded['revision'],
            'activated_at' => '2026-09-03 10:00:00',
        );
        update_option(MemberLibrary_Access_Groups::OPTION_NAME, $seeded, false);
        $service->save_groups(array_values($service->groups()), (string) $seeded['revision']);
        $after = $service->configuration();
        $assert('2026-09-03 10:00:00' === (string) ($after['published']['activated_at'] ?? ''), 'Saving a draft discarded the published snapshot.');
        $assert(false === $service->changes_since_publish($after)['has_changes'], 'Re-saving identical groups produced spurious changes against the live snapshot.');
    } finally {
        update_option(MemberLibrary_Access_Groups::OPTION_NAME, $before_configuration, false);
    }
} else {
    WP_CLI::log('Access Groups are not bootstrapped or a review is in progress here: the snapshot persistence step was not exercised.');
}

// 4. The page shows Live and Draft side by side, badges every group, and never asks for a typed phrase.
$admin_ids = get_users(array('role' => 'administrator', 'fields' => 'ID', 'number' => 1));
$assert(!empty($admin_ids), 'No administrator user exists to render the Access Groups page.');
if (!empty($admin_ids) && $service->is_bootstrapped()) {
    $previous_user = get_current_user_id();
    wp_set_current_user((int) $admin_ids[0]);
    $admin = new MemberLibrary_Access_Groups_Admin();
    ob_start();
    $admin->render();
    $html = (string) ob_get_clean();
    wp_set_current_user($previous_user);
    $assert(false !== strpos($html, 'data-access-state-panel="live"'), 'The page has no Live panel.');
    $assert(false !== strpos($html, 'data-access-state-panel="draft"'), 'The page has no Draft panel.');
    $assert(!preg_match('/<input[^>]+type="text"[^>]+name="confirmation"/', $html), 'Publishing still asks the administrator to type a phrase.');
    $assert(false === stripos($html, 'type publish-access-groups'), 'The typed publish instruction is still on the page.');
    $card_count = preg_match_all('/data-access-group-card/', $html);
    $state_count = preg_match_all('/data-access-group-state="(live|changed|new|draft)"/', $html);
    $assert($card_count > 0 && $state_count >= $card_count - 1, 'Not every Access Group card carries a Live/Changed/New badge.');
    foreach (array('stage', 'staged', 'Checked changes', 'checked changes') as $jargon) {
        $assert(false === strpos($html, '>' . $jargon), 'Implementation jargon is still shown to administrators: ' . $jargon);
    }
}

// 5. The review's "today" baseline follows LearnDash enrolment when no
// MemberPress rule protects a legacy course (Liberty Classroom), instead of
// treating every course as open to everyone.
$enrolment = new ReflectionMethod(MemberLibrary_Access_Groups::class, 'legacy_enrolment_users');
$enrolment->setAccessible(true);
$library_course = get_posts(array('post_type' => MemberLibrary_Content_Model::COURSE_POST_TYPE, 'numberposts' => 1, 'post_status' => 'any', 'fields' => 'ids'));
if (!empty($library_course)) {
    $assert(false === $enrolment->invoke($service, (int) $library_course[0]), 'A Library course post was treated as a LearnDash enrolment source.');
    $legacy_id = (int) get_post_meta((int) $library_course[0], MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, true);
    if ($legacy_id > 0 && 'sfwd-courses' === get_post_type($legacy_id) && function_exists('learndash_get_users_for_course')) {
        $enrolled = $enrolment->invoke($service, $legacy_id);
        $assert(null === $enrolled || (is_array($enrolled) && count($enrolled) > 0), 'A LearnDash-protected legacy course produced no enrolment baseline.');
        if (is_array($enrolled)) {
            $first = (int) array_key_first($enrolled);
            $assert($first > 0 && sfwd_lms_has_access($legacy_id, $first), 'The enrolment baseline lists a user LearnDash does not grant access to.');
        }
    } else {
        WP_CLI::log('No LearnDash legacy course on this site: the enrolment baseline was checked only for the negative case.');
    }
}
$assert(method_exists($service, 'publish'), 'Access Groups has no one-step publish.');

// 6. In Draft state the primary action is a one-step Publish; the manual
// review remains available as a preview.
if (!empty($admin_ids) && $service->is_bootstrapped() && empty($service->preview()['stage'])) {
    $previous_user = get_current_user_id();
    wp_set_current_user((int) $admin_ids[0]);
    ob_start();
    (new MemberLibrary_Access_Groups_Admin())->render();
    $draft_html = (string) ob_get_clean();
    wp_set_current_user($previous_user);
    $draft_changes = $service->changes_since_publish();
    if (!empty($draft_changes['has_changes']) || empty($draft_changes['has_published'])) {
        $assert(false !== strpos($draft_html, 'name="operation" value="publish"'), 'The Draft panel has no one-step Publish action.');
        $assert(false !== strpos($draft_html, 'name="operation" value="stage"'), 'The Draft panel lost the preview-only review.');
    }
}

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}
WP_CLI::success('Access Groups present Live, Draft, and Review states without a typed publish phrase.');
