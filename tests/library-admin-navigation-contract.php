<?php
/** Contract for the Library admin menu order and the status Dashboard. */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$navigation = new MemberLibrary_Admin_Navigation();

// 1. The Library submenu is ordered by purpose (content, curation, access,
// system) regardless of which class registered each item or when.
global $submenu;
$previous_submenu = $submenu;
$item = static function ($slug) {
    return array($slug, 'edit_pages', $slug, $slug);
};
$submenu[MemberLibrary_Admin_Navigation::MENU_SLUG] = array(
    $item(MemberLibrary_Environment_Migration_Admin::PAGE_SLUG),
    $item('edit-tags.php?taxonomy=' . MemberLibrary_Content_Model::TOPIC_TAXONOMY . '&post_type=' . MemberLibrary_Content_Model::ITEM_POST_TYPE),
    $item(MemberLibrary_Access_Groups_Admin::PAGE_SLUG),
    $item('edit.php?post_type=' . MemberLibrary_Content_Model::SPEAKER_POST_TYPE),
    $item(MemberLibrary_Admin_Navigation::SETTINGS_SLUG),
    $item('edit.php?post_type=' . MemberLibrary_Content_Model::COURSE_POST_TYPE),
    $item(MemberLibrary_Homepage_Curation::PAGE_SLUG),
    $item('some-unknown-plugin-page'),
    $item(MemberLibrary_Admin_Navigation::MENU_SLUG),
    $item('edit.php?post_type=' . MemberLibrary_Content_Model::ITEM_POST_TYPE),
    $item('edit-tags.php?taxonomy=' . MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY . '&post_type=' . MemberLibrary_Content_Model::COURSE_POST_TYPE),
    $item('edit.php?post_type=' . MemberLibrary_Content_Model::SERIES_POST_TYPE),
);
$navigation->order_submenu();
$ordered = array_map(static function ($entry) {
    return (string) $entry[2];
}, (array) $submenu[MemberLibrary_Admin_Navigation::MENU_SLUG]);
$submenu = $previous_submenu;
$expected = array(
    MemberLibrary_Admin_Navigation::MENU_SLUG,
    'edit.php?post_type=' . MemberLibrary_Content_Model::COURSE_POST_TYPE,
    'edit.php?post_type=' . MemberLibrary_Content_Model::SERIES_POST_TYPE,
    'edit.php?post_type=' . MemberLibrary_Content_Model::ITEM_POST_TYPE,
    'edit.php?post_type=' . MemberLibrary_Content_Model::SPEAKER_POST_TYPE,
    'edit-tags.php?taxonomy=' . MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY . '&post_type=' . MemberLibrary_Content_Model::COURSE_POST_TYPE,
    'edit-tags.php?taxonomy=' . MemberLibrary_Content_Model::TOPIC_TAXONOMY . '&post_type=' . MemberLibrary_Content_Model::ITEM_POST_TYPE,
    MemberLibrary_Homepage_Curation::PAGE_SLUG,
    MemberLibrary_Access_Groups_Admin::PAGE_SLUG,
    MemberLibrary_Admin_Navigation::SETTINGS_SLUG,
    MemberLibrary_Environment_Migration_Admin::PAGE_SLUG,
    'some-unknown-plugin-page',
);
$assert($expected === $ordered, 'The Library submenu is not ordered by purpose: ' . implode(' | ', $ordered));

// 2. The sync summary is a fast, local-only reading with a bounded status.
$summary = MemberLibrary_Catalogue_Sync_Status::summary();
$assert(in_array((string) ($summary['status'] ?? ''), array('good', 'recommended', 'critical'), true), 'The catalogue sync summary has no bounded status.');
$assert('' !== (string) ($summary['message'] ?? ''), 'The catalogue sync summary has no message.');

// 3. The Dashboard is a status hub: one card per subsystem, each with a state.
$admin_ids = get_users(array('role' => 'administrator', 'fields' => 'ID', 'number' => 1));
$assert(!empty($admin_ids), 'No administrator user exists to render the Dashboard.');
if (!empty($admin_ids)) {
    $previous_user = get_current_user_id();
    wp_set_current_user((int) $admin_ids[0]);
    ob_start();
    $navigation->render_dashboard();
    $html = (string) ob_get_clean();
    wp_set_current_user($previous_user);
    foreach (array('catalogue', 'access', 'connection', 'homepage', 'migration') as $card) {
        $assert(false !== strpos($html, 'data-dashboard-card="' . $card . '"'), 'The Dashboard has no ' . $card . ' card.');
    }
    $assert(preg_match_all('/data-dashboard-state="(live|draft|review|attention|off|ok)"/', $html) >= 5, 'Dashboard cards do not each declare a state.');
    $assert(false !== strpos($html, 'data-dashboard-recent'), 'The Dashboard does not list recently edited Library items.');
    foreach (array('staged', 'Checks passed', 'bootstrapped', 'outbox', 'cursor') as $jargon) {
        $assert(false === stripos($html, $jargon), 'Implementation jargon is shown on the Dashboard: ' . $jargon);
    }
}

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}
WP_CLI::success('The Library menu is ordered by purpose and the Dashboard reports each subsystem state.');
