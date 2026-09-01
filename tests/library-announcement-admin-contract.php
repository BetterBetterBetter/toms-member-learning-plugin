<?php
/**
 * Phase 2 private announcement draft/editor contract.
 *
 * Creates one disposable draft only. It does not send, schedule, publish,
 * resolve recipients, change memberships, or create School notification data.
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
$previous_user_id = get_current_user_id();
$announcement_id = 0;

add_filter('tsol_library_announcement_admin_editor_enabled', '__return_true');
add_filter('tsol_library_announcement_audience_preview_enabled', '__return_true');
add_filter('tsol_library_announcement_publish_schedule_enabled', '__return_false');
add_filter('tsol_library_announcement_self_test_enabled', '__return_false');

MemberLibrary_Announcement_Model::register();
MemberLibrary_Announcement_Model::install_capabilities();
$admin_ui = new MemberLibrary_Announcement_Admin();
$admin_ui->init();

try {
    $post_type = get_post_type_object(MemberLibrary_Announcement_Model::POST_TYPE);
    $assert($post_type instanceof WP_Post_Type, 'The announcement post type is not registered.');
    if ($post_type instanceof WP_Post_Type) {
        $assert(false === $post_type->public, 'Announcements must remain private.');
        $assert(false === $post_type->publicly_queryable, 'Announcements must not be publicly queryable.');
        $assert(false === $post_type->show_in_rest, 'Announcements must not be available through the public REST post API.');
        $assert(false === $post_type->can_export, 'Announcements must not be bulk-exportable from WordPress.');
        $assert('tsol-library' === $post_type->show_in_menu, 'Announcements are not grouped under the TSOL Library menu.');
    }

    $editor_role = get_role('editor');
    $administrator_role = get_role('administrator');
    $assert($editor_role instanceof WP_Role && $editor_role->has_cap(MemberLibrary_Announcement_Model::CAP_EDIT), 'Editors cannot create announcement drafts.');
    foreach (array(
        MemberLibrary_Announcement_Model::CAP_PUBLISH,
        MemberLibrary_Announcement_Model::CAP_SCHEDULE,
        MemberLibrary_Announcement_Model::CAP_MANAGE_AUDIENCE,
        MemberLibrary_Announcement_Model::CAP_VIEW_DELIVERY,
    ) as $capability) {
        $assert($editor_role instanceof WP_Role && !$editor_role->has_cap($capability), 'Editors received the administrator capability ' . $capability . '.');
        $assert($administrator_role instanceof WP_Role && $administrator_role->has_cap($capability), 'Administrators are missing ' . $capability . '.');
    }

    $administrator_ids = get_users(array('role' => 'administrator', 'number' => 1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC'));
    $editor_ids = get_users(array('role' => 'editor', 'number' => 1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC'));
    $assert(!empty($administrator_ids), 'A local administrator fixture is required.');
    $assert(!empty($editor_ids), 'A local editor fixture is required.');
    if (empty($administrator_ids) || empty($editor_ids)) {
        throw new RuntimeException('Required local role fixtures are unavailable.');
    }
    wp_set_current_user((int) $administrator_ids[0]);

    $destinations = get_posts(array(
        'post_type' => array(MemberLibrary_Content_Model::COURSE_POST_TYPE, MemberLibrary_Content_Model::SERIES_POST_TYPE),
        'post_status' => 'publish',
        'numberposts' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'suppress_filters' => true,
    ));
    $course_destinations = get_posts(array(
        'post_type' => MemberLibrary_Content_Model::COURSE_POST_TYPE,
        'post_status' => 'publish',
        'numberposts' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'suppress_filters' => true,
    ));
    $series_destinations = get_posts(array(
        'post_type' => MemberLibrary_Content_Model::SERIES_POST_TYPE,
        'post_status' => 'publish',
        'numberposts' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'suppress_filters' => true,
    ));
    $memberships = get_posts(array(
        'post_type' => 'memberpressproduct',
        'post_status' => 'publish',
        'numberposts' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
        'suppress_filters' => true,
    ));
    $candidate_user_ids = get_users(array('number' => 3, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC'));
    $assert(!empty($destinations), 'A published TSOL Course or Series fixture is required.');
    $assert(!empty($course_destinations), 'A published TSOL Course fixture is required.');
    $assert(!empty($series_destinations), 'A published TSOL Series fixture is required.');
    $assert(!empty($memberships), 'A published MemberPress membership fixture is required.');
    $assert(count($candidate_user_ids) >= 2, 'At least two local user fixtures are required.');
    if (empty($destinations) || empty($course_destinations) || empty($series_destinations) || empty($memberships) || count($candidate_user_ids) < 2) {
        throw new RuntimeException('Required local audience fixtures are unavailable.');
    }

    $matrix_destinations = array(
        'general' => 0,
        'course' => (int) $course_destinations[0]->ID,
        'series' => (int) $series_destinations[0]->ID,
    );
    $matrix_presets = array(
        MemberLibrary_Announcement_Audience_Builder::PRESET_ALL_LINKED => 'AUTHENTICATED_SCHOOL_USER',
        MemberLibrary_Announcement_Audience_Builder::PRESET_CONTENT_ACCESS => 'CAN_ACCESS_CONTENT',
        MemberLibrary_Announcement_Audience_Builder::PRESET_RELATIONSHIP => 'ACTIVE_RELATIONSHIP',
        MemberLibrary_Announcement_Audience_Builder::PRESET_MEMBERSHIP => 'ACTIVE_MEMBERSHIP',
        MemberLibrary_Announcement_Audience_Builder::PRESET_SPECIFIC_USERS => 'SPECIFIC_USERS',
    );
    foreach ($matrix_destinations as $destination_type => $destination_id) {
        foreach ($matrix_presets as $matrix_preset => $expected_condition) {
            $payload = array(
                'destination_id' => $destination_id,
                'audience_preset' => $matrix_preset,
                'membership_ids' => array($memberships[0]->ID),
                'specific_user_ids' => array($candidate_user_ids[0]),
                'exclude_user_ids' => array($candidate_user_ids[1]),
            );
            $matrix_result = MemberLibrary_Announcement_Audience_Builder::build($payload);
            $requires_destination = in_array($matrix_preset, array(
                MemberLibrary_Announcement_Audience_Builder::PRESET_CONTENT_ACCESS,
                MemberLibrary_Announcement_Audience_Builder::PRESET_RELATIONSHIP,
            ), true);
            if ('general' === $destination_type && $requires_destination) {
                $assert(is_wp_error($matrix_result), sprintf('The invalid %s + %s combination was accepted.', $destination_type, $matrix_preset));
                continue;
            }
            $assert(!is_wp_error($matrix_result), sprintf('The valid %s + %s combination was rejected.', $destination_type, $matrix_preset));
            if (is_wp_error($matrix_result)) {
                continue;
            }
            $types = wp_list_pluck($matrix_result['definition']['groups'][0]['all'], 'type');
            $assert(in_array($expected_condition, $types, true), sprintf('The %s + %s combination omitted %s.', $destination_type, $matrix_preset, $expected_condition));
            if ('general' !== $destination_type) {
                $matrix_destination = MemberLibrary_Announcement_Audience_Builder::destination($destination_id);
                $assert(in_array('CAN_ACCESS_CONTENT', $types, true), sprintf('The protected %s + %s combination omitted live access.', $destination_type, $matrix_preset));
                foreach ($matrix_result['definition']['groups'][0]['all'] as $condition) {
                    if ('CAN_ACCESS_CONTENT' === $condition['type']) {
                        $assert($matrix_destination['uuid'] === $condition['contentUuid'], sprintf('The %s + %s combination checks the wrong content identity.', $destination_type, $matrix_preset));
                    }
                }
            }
            $assert(array((int) $candidate_user_ids[1]) === MemberLibrary_Announcement_Audience_Builder::exclusion_ids($matrix_result['definition']), sprintf('The %s + %s combination lost its exclusion.', $destination_type, $matrix_preset));
        }
    }

    $assert(is_wp_error(MemberLibrary_Announcement_Audience_Builder::build(array(
        'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_MEMBERSHIP,
        'destination_id' => 0,
        'membership_ids' => array(),
    ))), 'The membership preset accepted no membership.');
    $assert(is_wp_error(MemberLibrary_Announcement_Audience_Builder::build(array(
        'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_MEMBERSHIP,
        'destination_id' => 0,
        'membership_ids' => range(1, MemberLibrary_Announcement_Audience_Contract::MAX_MEMBERSHIPS + 1),
    ))), 'The membership preset accepted more than 20 memberships.');
    $assert(is_wp_error(MemberLibrary_Announcement_Audience_Builder::build(array(
        'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_MEMBERSHIP,
        'destination_id' => 0,
        'membership_ids' => array(PHP_INT_MAX),
    ))), 'The membership preset accepted an unavailable membership.');
    $assert(is_wp_error(MemberLibrary_Announcement_Audience_Builder::build(array(
        'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_SPECIFIC_USERS,
        'destination_id' => 0,
        'specific_user_ids' => array(),
    ))), 'The specific-user preset accepted no user.');
    $assert(is_wp_error(MemberLibrary_Announcement_Audience_Builder::build(array(
        'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_SPECIFIC_USERS,
        'destination_id' => 0,
        'specific_user_ids' => range(1, MemberLibrary_Announcement_Audience_Contract::MAX_SPECIFIC_USERS + 1),
    ))), 'The specific-user preset accepted more than 100 users.');
    $assert(is_wp_error(MemberLibrary_Announcement_Audience_Builder::build(array(
        'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_SPECIFIC_USERS,
        'destination_id' => 0,
        'specific_user_ids' => array(PHP_INT_MAX),
    ))), 'The specific-user preset accepted an unavailable user.');
    $assert(is_wp_error(MemberLibrary_Announcement_Audience_Builder::build(array(
        'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_ALL_LINKED,
        'destination_id' => 0,
        'exclude_user_ids' => range(1, MemberLibrary_Announcement_Audience_Contract::MAX_SPECIFIC_USERS + 1),
    ))), 'The audience accepted more than 100 excluded users.');

    $destination = MemberLibrary_Announcement_Audience_Builder::destination($destinations[0]->ID);
    $assert(!is_wp_error($destination), 'A published TSOL destination was rejected.');
    $all_linked = MemberLibrary_Announcement_Audience_Builder::build(array(
        'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_ALL_LINKED,
        'destination_id' => $destinations[0]->ID,
    ));
    $assert(!is_wp_error($all_linked), 'The protected all-linked preset was rejected.');
    if (!is_wp_error($all_linked)) {
        $conditions = $all_linked['definition']['groups'][0]['all'];
        $types = wp_list_pluck($conditions, 'type');
        $assert(in_array('AUTHENTICATED_SCHOOL_USER', $types, true), 'The all-linked identity condition is missing.');
        $assert(in_array('CAN_ACCESS_CONTENT', $types, true), 'A protected destination did not lock live content access into its audience.');
        foreach ($conditions as $condition) {
            if ('CAN_ACCESS_CONTENT' === $condition['type']) {
                $assert($destination['uuid'] === $condition['contentUuid'], 'The locked access condition targets the wrong content UUID.');
            }
        }
    }

    $relationship = MemberLibrary_Announcement_Audience_Builder::build(array(
        'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_RELATIONSHIP,
        'destination_id' => $destinations[0]->ID,
    ));
    $assert(!is_wp_error($relationship), 'The Course/Series relationship preset was rejected.');
    if (!is_wp_error($relationship)) {
        $types = wp_list_pluck($relationship['definition']['groups'][0]['all'], 'type');
        $assert(in_array('CAN_ACCESS_CONTENT', $types, true) && in_array('ACTIVE_RELATIONSHIP', $types, true), 'Relationship targeting omitted its live access invariant.');
    }

    $membership = MemberLibrary_Announcement_Audience_Builder::build(array(
        'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_MEMBERSHIP,
        'destination_id' => 0,
        'membership_ids' => array($memberships[0]->ID, $memberships[0]->ID),
    ));
    $assert(!is_wp_error($membership), 'The active-membership preset was rejected.');
    if (!is_wp_error($membership)) {
        $ids = MemberLibrary_Announcement_Audience_Builder::ids_for_condition($membership['definition'], 'ACTIVE_MEMBERSHIP', 'membershipIds');
        $assert(array((int) $memberships[0]->ID) === $ids, 'Membership IDs were not normalized and deduplicated.');
        $assert(false !== strpos($membership['summary'], '1 selected active membership'), 'The membership summary uses the unnormalized selection count.');
    }

    $specific = MemberLibrary_Announcement_Audience_Builder::build(array(
        'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_SPECIFIC_USERS,
        'destination_id' => 0,
        'specific_user_ids' => array($candidate_user_ids[1], $candidate_user_ids[0], $candidate_user_ids[0]),
        'exclude_user_ids' => array($candidate_user_ids[1]),
    ));
    $assert(!is_wp_error($specific), 'The bounded specific-user preset was rejected.');
    if (!is_wp_error($specific)) {
        $ids = MemberLibrary_Announcement_Audience_Builder::ids_for_condition($specific['definition'], 'SPECIFIC_USERS', 'wordpressUserIds');
        $assert(array_map('intval', array($candidate_user_ids[0], $candidate_user_ids[1])) === $ids, 'Specific users were not sorted and deduplicated.');
        $assert(array((int) $candidate_user_ids[1]) === MemberLibrary_Announcement_Audience_Builder::exclusion_ids($specific['definition']), 'Specific-user exclusions were not retained.');
        $assert(false !== strpos($specific['summary'], '2 specifically selected users'), 'The specific-user summary uses the unnormalized selection count.');
    }
    $assert(is_wp_error(MemberLibrary_Announcement_Audience_Builder::build(array(
        'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_CONTENT_ACCESS,
        'destination_id' => 0,
    ))), 'The content-access preset accepted a missing destination.');
    $assert(is_wp_error(MemberLibrary_Announcement_Audience_Builder::destination(PHP_INT_MAX)), 'A missing destination was accepted.');

    $sanitized = MemberLibrary_Announcement_Model::sanitize_body('<script>private()</script><h1>Heading</h1><p>Hello <a href="https://example.test">link</a></p><ul><li>Item</li></ul>');
    $assert(false === stripos($sanitized, 'script') && false === stripos($sanitized, '<a'), 'Unsafe announcement markup survived sanitization.');
    $assert(false !== stripos($sanitized, '<h2>Heading</h2>') && false !== stripos($sanitized, '<li>Item</li>'), 'Approved semantic announcement markup was not preserved.');

    $announcement_id = wp_insert_post(array(
        'post_type' => MemberLibrary_Announcement_Model::POST_TYPE,
        'post_status' => 'draft',
        'post_title' => 'Synthetic announcement draft',
        'post_content' => '<p>Original detail</p>',
        'post_author' => get_current_user_id(),
    ), true);
    $assert(!is_wp_error($announcement_id), 'The disposable announcement draft could not be created.');
    if (is_wp_error($announcement_id)) {
        throw new RuntimeException('Disposable draft creation failed.');
    }
    $announcement_id = (int) $announcement_id;

    $long_title = str_repeat('x', MemberLibrary_Announcement_Model::MAX_SUBJECT_LENGTH + 10);
    $filtered = MemberLibrary_Announcement_Model::filter_post_data(array(
        'post_type' => MemberLibrary_Announcement_Model::POST_TYPE,
        'post_status' => 'publish',
        'post_title' => $long_title,
        'post_content' => str_repeat('y', MemberLibrary_Announcement_Model::MAX_BODY_LENGTH + 1),
    ), array('ID' => $announcement_id));
    $assert('draft' === $filtered['post_status'], 'A forged publish status bypassed the draft-only flag.');
    $assert(MemberLibrary_Announcement_Model::MAX_SUBJECT_LENGTH === MemberLibrary_Announcement_Model::text_length(wp_unslash($filtered['post_title'])), 'The subject length limit is not enforced.');
    $assert(false !== strpos(wp_unslash($filtered['post_content']), 'Original detail'), 'An oversized body replaced the previous safe body.');

    $updated = wp_update_post(array('ID' => $announcement_id, 'post_status' => 'publish'), true);
    $assert(!is_wp_error($updated) && 'draft' === get_post_status($announcement_id), 'WordPress persisted a publish request while publication was disabled.');

    $previous_post = $_POST;
    $_POST = array(
        MemberLibrary_Announcement_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_Announcement_Admin::NONCE_ACTION),
        MemberLibrary_Announcement_Admin::PAYLOAD_NAME => array(
            'summary' => 'A safe synthetic summary.',
            'destination_id' => $destinations[0]->ID,
            'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_RELATIONSHIP,
            'expiry_local' => '',
        ),
    );
    $admin_ui->save_post($announcement_id, get_post($announcement_id), true);
    $_POST = $previous_post;
    $saved_hash = (string) get_post_meta($announcement_id, MemberLibrary_Announcement_Model::META_AUDIENCE_HASH, true);
    $assert((bool) preg_match('/^[a-f0-9]{64}$/', $saved_hash), 'The guided editor did not persist a normalized audience hash.');
    $assert('A safe synthetic summary.' === get_post_meta($announcement_id, MemberLibrary_Announcement_Model::META_SUMMARY, true), 'The bounded summary was not saved.');
    $assert($destination['uuid'] === get_post_meta($announcement_id, MemberLibrary_Announcement_Model::META_DESTINATION_UUID, true), 'The canonical destination UUID was not saved.');
    $administrator_expiry = gmdate('Y-m-d H:i:s', time() + DAY_IN_SECONDS);
    update_post_meta($announcement_id, MemberLibrary_Announcement_Model::META_EXPIRY_GMT, $administrator_expiry);

    wp_set_current_user((int) $editor_ids[0]);
    $previous_post = $_POST;
    $_POST = array(
        MemberLibrary_Announcement_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_Announcement_Admin::NONCE_ACTION),
        MemberLibrary_Announcement_Admin::PAYLOAD_NAME => array(
            'summary' => 'Editor-updated copy.',
            'destination_id' => 0,
            'audience_preset' => MemberLibrary_Announcement_Audience_Builder::PRESET_ALL_LINKED,
            'expiry_local' => wp_date('Y-m-d\TH:i', time() + (2 * DAY_IN_SECONDS), wp_timezone()),
        ),
    );
    $admin_ui->save_post($announcement_id, get_post($announcement_id), true);
    $_POST = $previous_post;
    $assert($saved_hash === get_post_meta($announcement_id, MemberLibrary_Announcement_Model::META_AUDIENCE_HASH, true), 'An editor without the audience capability changed the audience.');
    $assert('Editor-updated copy.' === get_post_meta($announcement_id, MemberLibrary_Announcement_Model::META_SUMMARY, true), 'An editor could not revise permitted draft copy.');
    $assert($administrator_expiry === get_post_meta($announcement_id, MemberLibrary_Announcement_Model::META_EXPIRY_GMT, true), 'An editor without delivery authority changed the visibility expiry.');

    wp_set_current_user((int) $administrator_ids[0]);
    $preview = array(
        'counts' => array(
            'eligibleAdministrators' => 1,
            'relationshipSuppressed' => 0,
            'excluded' => 0,
            'unlinked' => 2,
            'eligible' => 3,
            'linkedCandidates' => 3,
            'wordpressCandidates' => 5,
            'scannedWordpressUsers' => 5,
        ),
        'pages' => 1,
        'generatedAt' => gmdate('c'),
        'definitionHash' => $saved_hash,
        'status' => 'ready',
    );
    $assert(!is_wp_error(MemberLibrary_Announcement_Preview::validate_result($preview, $saved_hash)), 'A valid aggregate preview was rejected because its JSON key order changed.');
    update_post_meta($announcement_id, MemberLibrary_Announcement_Model::META_PREVIEW, $preview);
    $preview['unexpected'] = true;
    $assert(is_wp_error(MemberLibrary_Announcement_Preview::validate_result($preview, $saved_hash)), 'An aggregate preview with an unknown field was accepted.');
    unset($preview['unexpected']);
    $assert(is_wp_error(MemberLibrary_Announcement_Preview::validate_result($preview, str_repeat('0', 64))), 'An aggregate preview with the wrong audience hash was accepted.');

    wp_set_current_user((int) $editor_ids[0]);
    $editor_columns = $admin_ui->list_columns(array('cb' => 'Select', 'title' => 'Title', 'author' => 'Author', 'date' => 'Date'));
    $assert(!isset($editor_columns['tsol_announcement_recipients'], $editor_columns['tsol_announcement_health']), 'An editor without the delivery capability received delivery-report columns.');
    ob_start();
    $admin_ui->render_review(get_post($announcement_id));
    $editor_review = ob_get_clean();
    $assert(false !== strpos($editor_review, 'administrator will complete') && false === strpos($editor_review, 'linked School accounts currently eligible'), 'An editor without the delivery capability received aggregate audience results.');
    wp_set_current_user((int) $administrator_ids[0]);

    for ($index = 0; $index < MemberLibrary_Announcement_Audit::MAX_ENTRIES + 5; $index++) {
        MemberLibrary_Announcement_Audit::record($announcement_id, 'draft_updated', array(
            'definitionHash' => $saved_hash,
            'email' => 'must-not-be-stored@example.test',
        ));
    }
    $audit = MemberLibrary_Announcement_Audit::entries($announcement_id);
    $assert(MemberLibrary_Announcement_Audit::MAX_ENTRIES === count($audit), 'The editorial audit exceeded its bounded retention.');
    $assert(false === strpos(wp_json_encode($audit), '@example.test'), 'The editorial audit stored identity data from an unapproved context field.');

    $revision_keys = MemberLibrary_Announcement_Model::revision_meta_keys(array());
    $assert(in_array(MemberLibrary_Announcement_Model::META_AUDIENCE, $revision_keys, true), 'Audience metadata is not revisioned.');
    $assert(in_array(MemberLibrary_Announcement_Model::META_DESTINATION_UUID, $revision_keys, true), 'Destination metadata is not revisioned.');

    ob_start();
    $admin_ui->render_save(get_post($announcement_id));
    $save_html = ob_get_clean();
    $assert(false !== strpos($save_html, 'Save draft') && false === strpos($save_html, '>Publish<'), 'The custom save panel exposes publication instead of draft-only authoring.');
    ob_start();
    $admin_ui->render_review(get_post($announcement_id));
    $review_html = ob_get_clean();
    $assert(false !== strpos($review_html, 'Send test to me') && false !== strpos($review_html, 'disabled'), 'Unavailable self-test delivery is not visibly disabled.');

    $assert(false === MemberLibrary_Announcement_Flags::publish_enabled(), 'The publish/schedule flag is not fail-closed.');
    $assert(false === MemberLibrary_Announcement_Flags::self_test_enabled(), 'The self-test delivery flag is not fail-closed.');
} finally {
    $_POST = isset($previous_post) ? $previous_post : $_POST;
    if ($announcement_id > 0) {
        wp_delete_post($announcement_id, true);
    }
    wp_set_current_user($previous_user_id);
    remove_filter('tsol_library_announcement_admin_editor_enabled', '__return_true');
    remove_filter('tsol_library_announcement_audience_preview_enabled', '__return_true');
    remove_filter('tsol_library_announcement_publish_schedule_enabled', '__return_false');
    remove_filter('tsol_library_announcement_self_test_enabled', '__return_false');
}

if (!empty($failures)) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error('TSOL announcement draft/admin contract checks failed.');
}

WP_CLI::success('TSOL announcement draft/admin contract checks passed.');
