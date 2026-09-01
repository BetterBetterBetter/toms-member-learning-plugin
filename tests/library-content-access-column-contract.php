<?php
/**
 * Read-only contract for TSOL-only MemberPress access presentation.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

$failures = array();
$original_user_id = get_current_user_id();
$original_screen = get_current_screen();
$memberpress_posts_priority = has_action('manage_posts_custom_column', 'MeprAppCtrl::custom_columns');
$memberpress_pages_priority = has_action('manage_pages_custom_column', 'MeprAppCtrl::custom_columns');
$presenter = null;

$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$rule_snapshot = static function ($post_id) {
    $rules = class_exists('MeprRule') ? MeprRule::get_rules(get_post((int) $post_id)) : array();
    $snapshot = array();
    foreach ($rules as $rule) {
        $conditions = array_map(static function ($condition) {
            return array(
                'type' => (string) $condition->access_type,
                'operator' => (string) $condition->access_operator,
                'condition' => (string) $condition->access_condition,
            );
        }, (array) $rule->access_conditions());
        $snapshot[(int) $rule->ID] = $conditions;
    }
    ksort($snapshot, SORT_NUMERIC);
    return $snapshot;
};

try {
    $assert(class_exists('MemberLibrary_Content_Access_Column'), 'Library access presenter class is unavailable.');
    $assert(class_exists('MeprRule'), 'MemberPress rule model is unavailable.');

    $administrator_ids = get_users(array(
        'role' => 'administrator',
        'fields' => 'ids',
        'number' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ));
    if (empty($administrator_ids)) {
        throw new RuntimeException('No administrator is available for the access-column contract.');
    }
    wp_set_current_user((int) $administrator_ids[0]);

    $legacy_ids = get_posts(array(
        'post_type' => 'mpcs-course',
        'post_status' => array_values(get_post_stati()),
        'numberposts' => -1,
        'fields' => 'ids',
        'suppress_filters' => true,
    ));
    $reviewable_statuses = array_values(array_diff(get_post_stati(), array('trash', 'auto-draft')));
    $library_course_ids = get_posts(array(
        'post_type' => MemberLibrary_Content_Model::COURSE_POST_TYPE,
        'post_status' => $reviewable_statuses,
        'numberposts' => -1,
        'fields' => 'ids',
        'suppress_filters' => true,
    ));
    $library_item_ids = get_posts(array(
        'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
        'post_status' => $reviewable_statuses,
        'numberposts' => -1,
        'fields' => 'ids',
        'suppress_filters' => true,
    ));
    $assert(124 === count($legacy_ids), 'The native MemberPress Course inventory is not the locked 124 sources.');
    $discarded_library_ids = get_posts(array(
        'post_type' => MemberLibrary_Content_Model::post_types(),
        'post_status' => array('trash', 'auto-draft'),
        'numberposts' => -1,
        'fields' => 'ids',
        'suppress_filters' => true,
        'meta_query' => array(array(
            'key' => MemberLibrary_Content_Model::META_MIGRATION_KEY,
            'compare' => 'EXISTS',
        )),
    ));
    $assert(7 === count($library_course_ids), 'The TSOL Library Course inventory is not seven reviewable records.');
    $assert(196 === count($library_item_ids), 'The TSOL Library Content inventory is not 196 reviewable records.');
    $assert(empty($discarded_library_ids), 'A TSOL Library record is trashed or auto-drafted.');

    $target_id = 0;
    $source_id = 0;
    foreach (array_merge($library_course_ids, $library_item_ids) as $candidate_id) {
        $candidate_source_id = (int) get_post_meta($candidate_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, true);
        if ($candidate_source_id > 0 && !empty(MeprRule::get_rules(get_post($candidate_source_id)))) {
            $target_id = (int) $candidate_id;
            $source_id = $candidate_source_id;
            break;
        }
    }
    if ($target_id <= 0 || $source_id <= 0) {
        throw new RuntimeException('A protected imported Library target could not be found.');
    }

    $target_snapshot = get_post($target_id, ARRAY_A);
    $target_meta_snapshot = get_post_meta($target_id);
    $source_snapshot = get_post($source_id, ARRAY_A);
    $source_meta_snapshot = get_post_meta($source_id);
    $rules_snapshot = $rule_snapshot($source_id);

    $presenter = new MemberLibrary_Content_Access_Column();
    $presenter->init();
    $assert(false !== has_filter('manage_edit-' . MemberLibrary_Content_Model::COURSE_POST_TYPE . '_columns', array($presenter, 'add_column')), 'Library Courses did not receive the compact access column.');
    $assert(false !== has_filter('manage_edit-' . MemberLibrary_Content_Model::ITEM_POST_TYPE . '_columns', array($presenter, 'add_column')), 'Library Content did not receive the compact access column.');
    $assert(false === has_filter('manage_edit-mpcs-course_columns', array($presenter, 'add_column')), 'TSOL registered an access column on native MemberPress Courses.');
    $assert(false === has_action('manage_mpcs-course_posts_custom_column', array($presenter, 'render_column')), 'TSOL registered a renderer on native MemberPress Courses.');

    set_current_screen('edit-mpcs-course');
    $native_screen = get_current_screen();
    $presenter->suppress_memberpress_renderer_on_library_lists($native_screen);
    $assert($memberpress_posts_priority === has_action('manage_posts_custom_column', 'MeprAppCtrl::custom_columns'), 'TSOL changed the MemberPress renderer on its native Courses list.');
    $assert($memberpress_pages_priority === has_action('manage_pages_custom_column', 'MeprAppCtrl::custom_columns'), 'TSOL changed the MemberPress page renderer on the native Courses list.');

    set_current_screen('edit-' . MemberLibrary_Content_Model::COURSE_POST_TYPE);
    $library_screen = get_current_screen();
    $presenter->suppress_memberpress_renderer_on_library_lists($library_screen);
    $assert(false === has_action('manage_posts_custom_column', 'MeprAppCtrl::custom_columns'), 'MemberPress verbose renderer was not suppressed on the TSOL-only list.');

    $columns = $presenter->add_column(array('title' => 'Title'));
    $assert(isset($columns[MemberLibrary_Content_Access_Column::COLUMN]), 'The TSOL list did not receive a MemberPress access heading.');

    $target_summary = $presenter->access_summary($target_id);
    $source_summary = $presenter->access_summary($source_id);
    $assert(!is_wp_error($target_summary) && !is_wp_error($source_summary), 'Effective MemberPress access could not be summarized.');
    if (!is_wp_error($target_summary) && !is_wp_error($source_summary)) {
        $assert($target_summary['public'] === $source_summary['public'], 'Delegated target and source disagree on public/protected state.');
        $assert($target_summary['membership_count'] === $source_summary['membership_count'], 'Delegated target and source disagree on membership count.');
        $assert($target_summary['other_condition_count'] === $source_summary['other_condition_count'], 'Delegated target and source disagree on other conditions.');
        $assert(array_column($target_summary['rules'], 'id') === array_column($source_summary['rules'], 'id'), 'Delegated target and source disagree on providing rules.');
    }

    ob_start();
    $presenter->render_column(MemberLibrary_Content_Access_Column::COLUMN, $target_id);
    $cell_html = ob_get_clean();
    $assert(false !== strpos($cell_html, 'aria-haspopup="dialog"'), 'Protected TSOL access cell is not an accessible details button.');
    $assert(false === strpos($cell_html, '>Public<'), 'MemberPress verbose Public output leaked into the TSOL access cell.');

    ob_start();
    $presenter->render_modal();
    $modal_html = ob_get_clean();
    $assert(false !== strpos($modal_html, '<dialog'), 'The TSOL access details dialog was not rendered.');
    $assert(false !== strpos($modal_html, 'Providing MemberPress rules'), 'The access dialog omitted its live MemberPress rule sources.');

    $presenter->enqueue_assets('edit.php');
    $assert(wp_style_is('tsol-library-content-access-column', 'enqueued'), 'The TSOL access stylesheet was not scoped to its list.');
    $assert(wp_script_is('tsol-library-content-access-column', 'enqueued'), 'The TSOL access dialog script was not scoped to its list.');

    $assert($target_snapshot === get_post($target_id, ARRAY_A), 'The access view changed a TSOL target post.');
    $assert($target_meta_snapshot === get_post_meta($target_id), 'The access view changed TSOL target metadata.');
    $assert($source_snapshot === get_post($source_id, ARRAY_A), 'The access view changed a legacy source post.');
    $assert($source_meta_snapshot === get_post_meta($source_id), 'The access view changed legacy source metadata.');
    $assert($rules_snapshot === $rule_snapshot($source_id), 'The access view changed MemberPress authority.');
} finally {
    if ($presenter instanceof MemberLibrary_Content_Access_Column) {
        foreach (MemberLibrary_Content_Model::post_types() as $post_type) {
            remove_filter('manage_edit-' . $post_type . '_columns', array($presenter, 'add_column'));
            remove_action('manage_' . $post_type . '_posts_custom_column', array($presenter, 'render_column'), 10);
        }
        remove_action('admin_enqueue_scripts', array($presenter, 'enqueue_assets'));
        remove_action('current_screen', array($presenter, 'suppress_memberpress_renderer_on_library_lists'), 20);
        remove_action('admin_footer', array($presenter, 'render_modal'));
    }
    if (false !== $memberpress_posts_priority && false === has_action('manage_posts_custom_column', 'MeprAppCtrl::custom_columns')) {
        add_action('manage_posts_custom_column', 'MeprAppCtrl::custom_columns', (int) $memberpress_posts_priority, 2);
    }
    if (false !== $memberpress_pages_priority && false === has_action('manage_pages_custom_column', 'MeprAppCtrl::custom_columns')) {
        add_action('manage_pages_custom_column', 'MeprAppCtrl::custom_columns', (int) $memberpress_pages_priority, 2);
    }
    if ($original_screen instanceof WP_Screen) {
        set_current_screen($original_screen);
    }
    wp_set_current_user($original_user_id);
}

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}

WP_CLI::success('TSOL Library access-column contract passed; native MemberPress and Page screens remained untouched.');
