<?php
/**
 * Contract for the TSOL Library wp-admin editor.
 *
 * This test creates only uniquely named disposable posts and removes them in a
 * finally block. It must run against the working site, never the control clone.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

global $wp_meta_boxes;

$failures = array();
$created_post_ids = array();
$created_term_ids = array();
$created_rule_ids = array();
$original_user_id = get_current_user_id();
$original_screen = get_current_screen();
$original_post = $_POST;
$original_get = $_GET;
$original_meta_boxes = $wp_meta_boxes;

$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$has_meta_box = static function ($post_type, $box_id) use (&$wp_meta_boxes) {
    if (empty($wp_meta_boxes[$post_type]) || !is_array($wp_meta_boxes[$post_type])) {
        return false;
    }
    foreach ($wp_meta_boxes[$post_type] as $context) {
        foreach ((array) $context as $priority) {
            if (isset($priority[$box_id]) && is_array($priority[$box_id])) {
                return true;
            }
        }
    }
    return false;
};

$get_meta_box = static function ($post_type, $box_id) use (&$wp_meta_boxes) {
    if (empty($wp_meta_boxes[$post_type]) || !is_array($wp_meta_boxes[$post_type])) {
        return null;
    }
    foreach ($wp_meta_boxes[$post_type] as $context) {
        foreach ((array) $context as $priority) {
            if (isset($priority[$box_id]) && is_array($priority[$box_id])) {
                return $priority[$box_id];
            }
        }
    }
    return null;
};

try {
    $assert(class_exists('MemberLibrary_Content_Admin'), 'Library content admin class is unavailable.');
    MemberLibrary_Content_Model::register();

    $administrator_ids = get_users(array(
        'role' => 'administrator',
        'fields' => 'ids',
        'number' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ));
    if (empty($administrator_ids)) {
        throw new RuntimeException('No administrator is available for the editor capability contract.');
    }
    wp_set_current_user((int) $administrator_ids[0]);

    $editor = new MemberLibrary_Content_Admin();
    $memberpress_course_post = new WP_Post((object) array(
        'ID' => 999999,
        'post_type' => 'mpcs-course',
    ));
    $library_course_post = new WP_Post((object) array(
        'ID' => 999998,
        'post_type' => MemberLibrary_Content_Model::COURSE_POST_TYPE,
    ));
    $library_series_post = new WP_Post((object) array(
        'ID' => 999997,
        'post_type' => MemberLibrary_Content_Model::SERIES_POST_TYPE,
    ));
    $assert(!method_exists($editor, 'use_memberpress_course_editor'), 'TSOL still contains a MemberPress editor override.');
    $assert(!MemberLibrary_Content_Admin::supports_post_type($memberpress_course_post->post_type), 'The TSOL editor still supports native MemberPress Courses.');
    $assert(MemberLibrary_Content_Admin::supports_post_type($library_course_post->post_type), 'The TSOL editor does not support Library Courses.');
    $assert(MemberLibrary_Content_Admin::supports_post_type($library_series_post->post_type), 'The TSOL editor does not support Library Series.');

    $assert(class_exists('LeadpagesWP\\Admin\\TinyMCE\\LeadboxTinyMCE'), 'The LeadPages TinyMCE integration is unavailable for the editor-isolation contract.');
    if (class_exists('LeadpagesWP\\Admin\\TinyMCE\\LeadboxTinyMCE')) {
        $leadbox_tinymce = new LeadpagesWP\Admin\TinyMCE\LeadboxTinyMCE();
        $leadbox_callbacks = array(
            'admin_head-post.php' => array($leadbox_tinymce, 'tinymceVars'),
            'admin_head-post-new.php' => array($leadbox_tinymce, 'tinymceVars'),
            'mce_external_plugins' => array($leadbox_tinymce, 'leadboxesAddButtons'),
            'mce_buttons' => array($leadbox_tinymce, 'leadboxesRegisterButtons'),
        );

        set_current_screen('mpcs-course');
        foreach ($leadbox_callbacks as $hook_name => $callback) {
            add_filter($hook_name, $callback, 998);
        }
        $editor->isolate_private_editor_integrations();
        foreach ($leadbox_callbacks as $hook_name => $callback) {
            $assert(998 === has_filter($hook_name, $callback), 'TSOL removed a LeadPages editor callback from the native MemberPress Course editor.');
            remove_filter($hook_name, $callback, 998);
        }

        set_current_screen(MemberLibrary_Content_Model::ITEM_POST_TYPE);
        foreach ($leadbox_callbacks as $hook_name => $callback) {
            add_filter($hook_name, $callback, 998);
        }
        $editor->isolate_private_editor_integrations();
        foreach ($leadbox_callbacks as $hook_name => $callback) {
            $assert(false === has_filter($hook_name, $callback), 'A LeadPages editor callback leaked onto the private TSOL Library editor.');
        }
    }
    foreach (array_merge(MemberLibrary_Content_Model::post_types(), array(MemberLibrary_Content_Model::SPEAKER_POST_TYPE)) as $library_post_type) {
        $assert(!post_type_supports($library_post_type, 'author'), sprintf('Library post type %s still exposes WordPress authorship.', $library_post_type));
    }
    $list_columns = $editor->add_course_column(array(
        'cb' => '<input type="checkbox" />',
        'title' => 'Title',
        'date' => 'Date',
    ));
    $column_keys = array_keys($list_columns);
    $assert(isset($list_columns[MemberLibrary_Content_Admin::COURSE_COLUMN]), 'The Library Content list did not receive a Course column.');
    $assert(
        array_search(MemberLibrary_Content_Admin::COURSE_COLUMN, $column_keys, true) === array_search('title', $column_keys, true) + 1,
        'The Course column was not placed immediately after Title.'
    );
    $list_columns = $editor->add_series_column($list_columns);
    $assert(isset($list_columns[MemberLibrary_Content_Admin::SERIES_COLUMN]), 'The Library Content list did not receive a Series column.');
    $list_columns = $editor->add_availability_column($list_columns);
    $assert(isset($list_columns[MemberLibrary_Content_Admin::AVAILABILITY_COLUMN]), 'The Library Content list did not receive an Availability column.');
    $list_columns = $editor->add_speakers_column($list_columns);
    $assert(isset($list_columns[MemberLibrary_Content_Admin::SPEAKERS_COLUMN]), 'The Library Content list did not receive a Speakers column.');
    $parent_columns = $editor->add_content_count_column(array('cb' => 'Select', 'title' => 'Title', 'date' => 'Date'));
    $assert(isset($parent_columns[MemberLibrary_Content_Admin::CONTENT_COUNT_COLUMN]), 'Course and Series lists did not receive a Content count column.');
    $assert(
        MemberLibrary_Content_Admin::CONTENT_COUNT_COLUMN === array_key_last($parent_columns),
        'The Content count column is not the final parent-list column.'
    );
    $taxonomy_columns = $editor->shorten_taxonomy_column_labels(array(
        'taxonomy-' . MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY => 'Collections',
        'taxonomy-' . MemberLibrary_Content_Model::TOPIC_TAXONOMY => 'Library Topics',
    ));
    $assert('Collections' === $taxonomy_columns['taxonomy-' . MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY], 'The Collections list heading is incorrect.');
    $assert('Topics' === $taxonomy_columns['taxonomy-' . MemberLibrary_Content_Model::TOPIC_TAXONOMY], 'The Topics list heading still repeats Library.');

    $library_hidden = $editor->default_hidden_columns(array(), (object) array(
        'base' => 'edit',
        'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
    ));
    $assert(!in_array('author', $library_hidden, true), 'The obsolete Author column is still being managed as a Library screen option.');
    $assert(in_array('date', $library_hidden, true), 'Date is not hidden by default on the Library Content list.');
    $native_hidden = $editor->default_hidden_columns(array(), (object) array(
        'base' => 'edit',
        'post_type' => 'mpcs-course',
    ));
    $assert(!in_array('author', $native_hidden, true) && !in_array('date', $native_hidden, true), 'TSOL default-hidden columns leaked onto native MemberPress Courses.');
    $fixture_id = wp_insert_post(array(
        'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
        'post_status' => 'draft',
        'post_title' => 'TSOL Library editor contract fixture',
        'post_content' => '',
    ), true);
    if (is_wp_error($fixture_id)) {
        throw new RuntimeException($fixture_id->get_error_message());
    }
    $fixture_id = (int) $fixture_id;
    $created_post_ids[] = $fixture_id;

    $speaker_fixture_id = wp_insert_post(array(
        'post_type' => MemberLibrary_Content_Model::SPEAKER_POST_TYPE,
        'post_status' => 'draft',
        'post_title' => 'Zulu TSOL Library speaker relationship contract fixture',
    ), true);
    if (is_wp_error($speaker_fixture_id)) {
        throw new RuntimeException($speaker_fixture_id->get_error_message());
    }
    $speaker_fixture_id = (int) $speaker_fixture_id;
    $created_post_ids[] = $speaker_fixture_id;
    update_post_meta($speaker_fixture_id, MemberLibrary_Content_Model::SPEAKER_META_JOB_TITLE, 'Contract researcher');
    update_post_meta($speaker_fixture_id, MemberLibrary_Content_Model::SPEAKER_META_ORGANIZATION, 'TSOL Contract Institute');

    $second_speaker_fixture_id = wp_insert_post(array(
        'post_type' => MemberLibrary_Content_Model::SPEAKER_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => 'Alpha TSOL Library speaker relationship contract fixture',
    ), true);
    if (is_wp_error($second_speaker_fixture_id)) {
        throw new RuntimeException($second_speaker_fixture_id->get_error_message());
    }
    $second_speaker_fixture_id = (int) $second_speaker_fixture_id;
    $created_post_ids[] = $second_speaker_fixture_id;

    $_POST = array(
        MemberLibrary_Content_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_Content_Admin::NONCE_ACTION),
        MemberLibrary_Content_Admin::PAYLOAD_NAME => array(
            // These retired raw fields are deliberately submitted to prove
            // that editor classification and homepage order cannot be forged.
            'content_type' => 'webinar',
            'position' => '7',
            'featured' => '1',
            'speaker_mode' => MemberLibrary_Content_Model::SPEAKER_MODE_DIRECT,
            'speaker_ids' => array((string) $speaker_fixture_id, (string) $second_speaker_fixture_id),
            'media_assets' => array(
                array(
                    'source_url' => 'https://vimeo.com/123456789/abcdef12',
                    'label' => 'Primary session',
                    'duration_seconds' => '3600',
                    'preview' => '1',
                ),
                array(
                    'source_url' => 'https://youtu.be/AbCdEf123_-',
                    'label' => 'Follow-up',
                    'duration_seconds' => '600',
                ),
            ),
            'resources' => array(
                array(
                    'type' => 'download',
                    'label' => 'Worksheet',
                    'url' => 'https://example.test/worksheet.pdf',
                    'attachment_id' => '0',
                ),
            ),
        ),
    );
    $editor->save_post($fixture_id, get_post($fixture_id));

    $assert(!metadata_exists('post', $fixture_id, MemberLibrary_Content_Model::META_INCLUDE), 'A manual Library save created the retired inclusion flag.');
    $assert('recording' === get_post_meta($fixture_id, MemberLibrary_Content_Model::META_CONTENT_TYPE, true), 'Standalone Content classification was not derived.');
    $assert(0 === (int) get_post_meta($fixture_id, MemberLibrary_Content_Model::META_POSITION, true), 'Structure-managed position accepted a retired raw position value.');
    $assert(!metadata_exists('post', $fixture_id, MemberLibrary_Content_Model::META_FEATURED), 'A manual Library save created the retired Featured flag.');
    $assert(0 === strpos((string) get_post_meta($fixture_id, MemberLibrary_Content_Model::META_MIGRATION_KEY, true), 'manual-tsol_library_item-'), 'A manual Library save did not create a stable projection key.');
    $assert(64 === strlen((string) get_post_meta($fixture_id, MemberLibrary_Content_Model::META_CONTENT_FINGERPRINT, true)), 'A manual Library save did not create a stable content fingerprint.');
    $assert(!metadata_exists('post', $fixture_id, '_tsol_library_current'), 'A manual Library save created the retired version-state metadata.');
    $assert(
        array($speaker_fixture_id, $second_speaker_fixture_id) === array_map('intval', get_post_meta($fixture_id, MemberLibrary_Content_Model::META_SPEAKER_IDS, false)),
        'Speaker relationships were not saved as ordered Speaker post IDs.'
    );
    $assert(
        MemberLibrary_Content_Model::SPEAKER_MODE_DIRECT === get_post_meta($fixture_id, MemberLibrary_Content_Model::META_SPEAKER_MODE, true),
        'A direct Content Speaker choice did not persist its attribution mode.'
    );
    $assert($fixture_id === (int) get_post_meta($fixture_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, true), 'Authorization post ID was not derived from the canonical post.');

    $media = get_post_meta($fixture_id, MemberLibrary_Content_Model::META_MEDIA_ASSETS, true);
    $assert(is_array($media) && 2 === count($media), 'Repeatable media assets were not saved.');
    if (is_array($media) && 2 === count($media)) {
        $assert('vimeo' === $media[0]['provider'], 'Vimeo provider was not inferred during save.');
        $assert('123456789' === $media[0]['provider_id'], 'Vimeo ID was not inferred during save.');
        $assert('abcdef12' === $media[0]['privacy_hash'], 'Vimeo privacy hash was not inferred during save.');
        $assert(1 === (int) $media[0]['position'] && 2 === (int) $media[1]['position'], 'Media order was not made explicit.');
        $assert('youtube' === $media[1]['provider'], 'YouTube provider was not inferred during save.');
    }

    $resources = get_post_meta($fixture_id, MemberLibrary_Content_Model::META_RESOURCES, true);
    $assert(is_array($resources) && 1 === count($resources), 'Library resource was not saved.');
    if (is_array($resources) && 1 === count($resources)) {
        $assert('download' === $resources[0]['type'], 'Resource type was not saved.');
        $assert('Worksheet' === $resources[0]['label'], 'Resource label was not saved.');
    }

    ob_start();
    $editor->render_course_column(MemberLibrary_Content_Admin::COURSE_COLUMN, $fixture_id);
    $standalone_column = ob_get_clean();
    $assert(false !== strpos($standalone_column, '&#8212;'), 'Unassigned Library Content did not use the standard empty-column mark.');
    $assert(false !== strpos($standalone_column, 'No course'), 'The empty Course value is missing accessible text.');

    $course_fixture_id = wp_insert_post(array(
        'post_type' => MemberLibrary_Content_Model::COURSE_POST_TYPE,
        'post_status' => 'draft',
        'post_title' => 'TSOL Library course-column contract fixture',
        'post_content' => '',
    ), true);
    if (is_wp_error($course_fixture_id)) {
        throw new RuntimeException($course_fixture_id->get_error_message());
    }
    $course_fixture_id = (int) $course_fixture_id;
    $created_post_ids[] = $course_fixture_id;
    add_post_meta($course_fixture_id, MemberLibrary_Content_Model::META_SPEAKER_IDS, $speaker_fixture_id, false);
    add_post_meta($course_fixture_id, MemberLibrary_Content_Model::META_SPEAKER_IDS, $second_speaker_fixture_id, false);
    $_POST = array(
        MemberLibrary_Content_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_Content_Admin::NONCE_ACTION),
        MemberLibrary_Content_Admin::PAYLOAD_NAME => array(
            'course_learning_outcomes_present' => '1',
            'learning_outcomes' => array(
                array('text' => 'Build an independent training plan.'),
                array('text' => ''),
                array('text' => '<strong>Evaluate</strong> a gym programme.'),
                array('text' => 'Build an independent training plan.'),
            ),
            'speaker_ids' => array((string) $speaker_fixture_id, (string) $second_speaker_fixture_id),
        ),
    );
    $editor->save_post($course_fixture_id, get_post($course_fixture_id));
    $course_learning_outcomes = get_post_meta(
        $course_fixture_id,
        MemberLibrary_Content_Model::META_COURSE_LEARNING_OUTCOMES,
        true
    );
    $assert(
        array('Build an independent training plan.', 'Evaluate a gym programme.') === $course_learning_outcomes,
        'Course learning outcomes were not cleaned, de-duplicated, and saved in editorial order.'
    );
    $_POST = array(
        MemberLibrary_Content_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_Content_Admin::NONCE_ACTION),
        MemberLibrary_Content_Admin::PAYLOAD_NAME => array(
            'speaker_ids' => array((string) $speaker_fixture_id, (string) $second_speaker_fixture_id),
        ),
    );
    $editor->save_post($course_fixture_id, get_post($course_fixture_id));
    $assert(
        $course_learning_outcomes === get_post_meta(
            $course_fixture_id,
            MemberLibrary_Content_Model::META_COURSE_LEARNING_OUTCOMES,
            true
        ),
        'A Course save from a stale or partial form cleared the learning outcomes.'
    );
    update_post_meta($fixture_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, $fixture_id);
    $_POST = array(
        MemberLibrary_Content_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_Content_Admin::NONCE_ACTION),
        MemberLibrary_Content_Admin::PAYLOAD_NAME => array(
            'content_type' => 'webinar',
            'course_id' => (string) $course_fixture_id,
            'section_title' => 'Course content',
            'media_assets' => array(array('source_url' => 'https://vimeo.com/123456789/abcdef12')),
            'resources' => array(),
        ),
    );
    $editor->save_post($fixture_id, get_post($fixture_id));
    $assert($course_fixture_id === (int) get_post_meta($fixture_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, true), 'A new Content item did not update its authorization source when assigned to a Course.');
    $assert(
        MemberLibrary_Content_Model::SPEAKER_MODE_INHERIT === get_post_meta($fixture_id, MemberLibrary_Content_Model::META_SPEAKER_MODE, true),
        'A new parented lesson did not default to inherited Speaker mode.'
    );
    $assert(
        array() === get_post_meta($fixture_id, MemberLibrary_Content_Model::META_SPEAKER_IDS, false),
        'Inherited Speaker mode copied the parent relationship onto the lesson.'
    );
    $inherited_context = MemberLibrary_Content_Model::effective_speaker_context($fixture_id);
    $assert('course' === ($inherited_context['source'] ?? ''), 'A lesson did not identify its Course as the effective Speaker source.');
    $assert($course_fixture_id === (int) ($inherited_context['parent_id'] ?? 0), 'A lesson did not preserve its effective Speaker parent identity.');
    $assert(
        array($speaker_fixture_id, $second_speaker_fixture_id) === array_map('intval', $inherited_context['speaker_ids'] ?? array()),
        'A lesson did not inherit the parent Course Speaker order.'
    );

    ob_start();
    $editor->render_speakers_meta_box(get_post($fixture_id));
    $inherited_picker_html = ob_get_clean();
    $assert(false !== strpos($inherited_picker_html, 'Speaker source'), 'The Content editor omitted the Speaker source choice.');
    $assert(false !== strpos($inherited_picker_html, 'Inherit from parent'), 'The Content editor omitted inherited Speaker mode.');
    $assert(false !== strpos($inherited_picker_html, 'Choose speakers for this content'), 'The Content editor omitted direct Speaker override mode.');
    $assert(false !== strpos($inherited_picker_html, 'No presenter'), 'The Content editor omitted explicit no-presenter mode.');
    $assert(false !== strpos($inherited_picker_html, 'Inherited from Course'), 'The inherited Speaker panel did not identify the Course source.');
    $assert(false !== strpos($inherited_picker_html, 'TSOL Library course-column contract fixture'), 'The inherited Speaker panel omitted the parent Course link.');
    $assert(false !== strpos($inherited_picker_html, 'Zulu TSOL Library speaker relationship contract fixture'), 'The inherited Speaker panel omitted an effective Speaker card.');

    ob_start();
    $editor->render_speakers_column(MemberLibrary_Content_Admin::SPEAKERS_COLUMN, $fixture_id);
    $inherited_column_html = ob_get_clean();
    $assert(false !== strpos($inherited_column_html, 'Alpha TSOL Library speaker relationship contract fixture'), 'The Content list omitted an inherited Speaker.');
    $assert(false !== strpos($inherited_column_html, '>Inherited</span>'), 'The Content list did not distinguish inherited Speakers from direct assignments.');

    $speaker_admin = new MemberLibrary_Speaker_Admin();
    ob_start();
    $speaker_admin->render_column(MemberLibrary_Speaker_Admin::CONTENT_COLUMN, $speaker_fixture_id);
    $speaker_content_count = trim(ob_get_clean());
    $assert('2' === $speaker_content_count, 'The Speaker list Content count did not include the parent and its inheriting lesson.');

    $change_cursor = MemberLibrary_Content_Changes::current_cursor();
    update_post_meta($speaker_fixture_id, MemberLibrary_Content_Model::SPEAKER_META_JOB_TITLE, 'Inherited contract researcher');
    $speaker_changes = MemberLibrary_Content_Changes::after($change_cursor, 100);
    $assert(
        count(array_filter($speaker_changes, static function ($change) use ($fixture_id) {
            return (int) $change['post_id'] === $fixture_id && 'upsert' === (string) $change['action'];
        })) > 0,
        'Updating a parent Course Speaker did not enqueue its inheriting lesson.'
    );
    update_post_meta($speaker_fixture_id, MemberLibrary_Content_Model::SPEAKER_META_JOB_TITLE, 'Contract researcher');

    $collection_fixture = wp_insert_term(
        'TSOL Library course-collection contract fixture',
        MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY,
        array('slug' => 'tsol-library-course-collection-contract-' . strtolower(wp_generate_password(8, false, false)))
    );
    if (is_wp_error($collection_fixture)) {
        throw new RuntimeException($collection_fixture->get_error_message());
    }
    $collection_fixture_id = (int) $collection_fixture['term_id'];
    $created_term_ids[] = $collection_fixture_id;
    wp_set_object_terms($course_fixture_id, array($collection_fixture_id), MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY);
    $assert(has_term($collection_fixture_id, MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY, $course_fixture_id), 'Collection was not assigned to the Library Course.');
    $assert(!is_object_in_taxonomy(MemberLibrary_Content_Model::ITEM_POST_TYPE, MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY), 'Collections leaked onto Library Content items.');

    ob_start();
    $editor->render_course_column(MemberLibrary_Content_Admin::COURSE_COLUMN, $fixture_id);
    $lesson_column = ob_get_clean();
    $assert(false !== strpos($lesson_column, 'TSOL Library course-column contract fixture'), 'A course lesson did not show its Course title.');
    $assert(false !== strpos($lesson_column, 'post.php?post=' . $course_fixture_id), 'The Course column did not link to the Library Course editor.');

    ob_start();
    $editor->render_content_count_column(MemberLibrary_Content_Admin::CONTENT_COUNT_COLUMN, $course_fixture_id);
    $course_content_count = ob_get_clean();
    $assert(false !== strpos($course_content_count, '>1</a>'), 'The Course list Content column did not count its lesson.');
    $assert(false !== strpos($course_content_count, 'tsol_content_scope=course'), 'The Course Content count does not link to the course-lesson scope.');
    $assert(false !== strpos($course_content_count, 'tsol_library_parent=' . $course_fixture_id), 'The Course Content count does not filter by its exact parent.');

    $series_fixture_id = wp_insert_post(array(
        'post_type' => MemberLibrary_Content_Model::SERIES_POST_TYPE,
        'post_status' => 'draft',
        'post_title' => 'TSOL Library series-count contract fixture',
    ), true);
    if (is_wp_error($series_fixture_id)) {
        throw new RuntimeException($series_fixture_id->get_error_message());
    }
    $series_fixture_id = (int) $series_fixture_id;
    $created_post_ids[] = $series_fixture_id;
    delete_post_meta($fixture_id, MemberLibrary_Content_Model::META_COURSE_ID);
    update_post_meta($fixture_id, MemberLibrary_Content_Model::META_SERIES_ID, $series_fixture_id);
    ob_start();
    $editor->render_content_count_column(MemberLibrary_Content_Admin::CONTENT_COUNT_COLUMN, $series_fixture_id);
    $series_content_count = ob_get_clean();
    $assert(false !== strpos($series_content_count, '>1</a>'), 'The Series list Content column did not count its item.');
    $assert(false !== strpos($series_content_count, 'tsol_content_scope=series'), 'The Series Content count does not link to the Series scope.');
    $assert(false !== strpos($series_content_count, 'tsol_library_parent=' . $series_fixture_id), 'The Series Content count does not filter by its exact parent.');

    $_GET = array();
    ob_start();
    $editor->render_content_scope_filter(MemberLibrary_Content_Model::ITEM_POST_TYPE, 'top');
    $scope_filter_html = ob_get_clean();
    $assert(false !== strpos($scope_filter_html, 'Standalone content'), 'The default standalone Content scope is missing.');
    $assert(false !== strpos($scope_filter_html, 'Course lessons'), 'The Course lessons Content scope is missing.');
    $assert(false !== strpos($scope_filter_html, 'Series episodes'), 'The Series episodes Content scope is missing.');
    $assert(false !== strpos($scope_filter_html, 'All content'), 'The All content scope is missing.');
    $assert(1 === preg_match('/value=["\']all["\'][^>]*selected=/', $scope_filter_html), 'All content is not the default Content scope.');

    $wp_meta_boxes = array();
    $editor->add_meta_boxes(MemberLibrary_Content_Model::ITEM_POST_TYPE, get_post($fixture_id));
    $assert(!$has_meta_box(MemberLibrary_Content_Model::ITEM_POST_TYPE, 'tsol-library-provenance'), 'A manually created Library Item displayed the Legacy import source box.');

    $wp_meta_boxes = array();
    foreach (MemberLibrary_Content_Model::post_types() as $library_post_type) {
        add_meta_box('leadbox-select', 'Page Specific Pop-up', '__return_empty_string', $library_post_type, 'side', 'low');
    }
    update_post_meta($fixture_id, MemberLibrary_Content_Model::META_LEGACY_SOURCE_ID, $fixture_id);
    $editor->add_meta_boxes(MemberLibrary_Content_Model::ITEM_POST_TYPE, get_post($fixture_id));
    $assert(!$has_meta_box(MemberLibrary_Content_Model::ITEM_POST_TYPE, 'tsol-library-provenance'), 'A partial provenance marker displayed the Legacy import source box.');

    $wp_meta_boxes = array();
    foreach (MemberLibrary_Content_Model::post_types() as $library_post_type) {
        add_meta_box('leadbox-select', 'Page Specific Pop-up', '__return_empty_string', $library_post_type, 'side', 'low');
    }
    update_post_meta($fixture_id, MemberLibrary_Content_Model::META_LEGACY_SOURCE_TYPE, 'contract-fixture');
    update_post_meta($fixture_id, MemberLibrary_Content_Model::META_MIGRATION_VERSION, 'contract');
    $editor->add_meta_boxes(MemberLibrary_Content_Model::ITEM_POST_TYPE, get_post($fixture_id));
    $assert($has_meta_box(MemberLibrary_Content_Model::ITEM_POST_TYPE, 'tsol-library-placement'), 'Library Item placement box was not registered.');
    $assert(!$has_meta_box(MemberLibrary_Content_Model::ITEM_POST_TYPE, 'tsol-library-details'), 'Library Item retained the generic details box.');
    $assert($has_meta_box(MemberLibrary_Content_Model::ITEM_POST_TYPE, 'tsol-library-media'), 'Library Item media box was not registered.');
    $assert($has_meta_box(MemberLibrary_Content_Model::ITEM_POST_TYPE, 'tsol-library-resources'), 'Library Item resources box was not registered.');
    $assert($has_meta_box(MemberLibrary_Content_Model::ITEM_POST_TYPE, 'tsol-library-protection'), 'Library Item access box was not registered.');
    $assert($has_meta_box(MemberLibrary_Content_Model::ITEM_POST_TYPE, 'tsol-library-speakers'), 'Library Item Speakers box was not registered.');
    $assert(!$has_meta_box(MemberLibrary_Content_Model::ITEM_POST_TYPE, 'leadbox-select'), 'LeadPages Page Specific Pop-up leaked onto the private Library Item editor.');
    $provenance_box = $get_meta_box(MemberLibrary_Content_Model::ITEM_POST_TYPE, 'tsol-library-provenance');
    $assert(is_array($provenance_box), 'Imported Library Item provenance box was not registered.');
    $assert(is_array($provenance_box) && 'Legacy import source' === (string) $provenance_box['title'], 'Imported provenance does not use the administrator-facing Legacy import source label.');
    $assert(in_array('closed', $editor->collapse_provenance_box(array('')), true), 'Legacy import source is not collapsed by default.');

    $editor->add_meta_boxes(MemberLibrary_Content_Model::COURSE_POST_TYPE, $library_course_post);
    $assert(!$has_meta_box(MemberLibrary_Content_Model::COURSE_POST_TYPE, 'tsol-library-details'), 'Library Course retained the generic details box.');
    $assert(!$has_meta_box(MemberLibrary_Content_Model::COURSE_POST_TYPE, 'tsol-library-placement'), 'Library Course displayed item placement controls.');
    $assert($has_meta_box(MemberLibrary_Content_Model::COURSE_POST_TYPE, 'tsol-library-course-page-content'), 'Library Course landing-page fields were not registered.');
    $assert($has_meta_box(MemberLibrary_Content_Model::COURSE_POST_TYPE, 'tsol-library-curriculum'), 'Library Course curriculum box was not registered.');
    $assert($has_meta_box(MemberLibrary_Content_Model::COURSE_POST_TYPE, 'tsol-library-protection'), 'Library Course access box was not registered.');
    $assert($has_meta_box(MemberLibrary_Content_Model::COURSE_POST_TYPE, 'tsol-library-ai-assistant'), 'Library Course AI assistant box was not registered.');
    $assert($has_meta_box(MemberLibrary_Content_Model::COURSE_POST_TYPE, 'tsol-library-speakers'), 'Library Course Speakers box was not registered.');
    $assert(!$has_meta_box(MemberLibrary_Content_Model::COURSE_POST_TYPE, 'leadbox-select'), 'LeadPages Page Specific Pop-up leaked onto the private Library Course editor.');
    $assert(!$has_meta_box(MemberLibrary_Content_Model::COURSE_POST_TYPE, 'tsol-library-media'), 'Library Course duplicated lesson media controls.');
    $assert(!$has_meta_box(MemberLibrary_Content_Model::COURSE_POST_TYPE, 'tsol-library-resources'), 'Library Course duplicated lesson resource controls.');

    $editor->add_meta_boxes(MemberLibrary_Content_Model::SERIES_POST_TYPE, $library_series_post);
    $assert($has_meta_box(MemberLibrary_Content_Model::SERIES_POST_TYPE, 'tsol-library-series-settings'), 'Library Series settings box was not registered.');
    $assert(!$has_meta_box(MemberLibrary_Content_Model::SERIES_POST_TYPE, 'tsol-library-details'), 'Library Series retained the generic details box.');
    $assert($has_meta_box(MemberLibrary_Content_Model::SERIES_POST_TYPE, 'tsol-library-series-episodes'), 'Library Series episodes box was not registered.');
    $assert($has_meta_box(MemberLibrary_Content_Model::SERIES_POST_TYPE, 'tsol-library-protection'), 'Library Series access box was not registered.');
    $assert($has_meta_box(MemberLibrary_Content_Model::SERIES_POST_TYPE, 'tsol-library-ai-assistant'), 'Library Series AI assistant box was not registered.');
    $assert($has_meta_box(MemberLibrary_Content_Model::SERIES_POST_TYPE, 'tsol-library-speakers'), 'Library Series Speakers box was not registered.');
    $assert(!$has_meta_box(MemberLibrary_Content_Model::SERIES_POST_TYPE, 'leadbox-select'), 'LeadPages Page Specific Pop-up leaked onto the private Library Series editor.');
    $assert(!$has_meta_box(MemberLibrary_Content_Model::SERIES_POST_TYPE, 'tsol-library-media'), 'Library Series duplicated episode media controls.');
    $assert(!$has_meta_box(MemberLibrary_Content_Model::SERIES_POST_TYPE, 'tsol-library-resources'), 'Library Series duplicated episode resource controls.');
    $assert(!$has_meta_box(MemberLibrary_Content_Model::SERIES_POST_TYPE, 'tsol-library-course-page-content'), 'Public Course landing-page fields leaked onto a Library Series.');

    $editor->add_meta_boxes('mpcs-course', $memberpress_course_post);
    $assert(!$has_meta_box('mpcs-course', 'tsol-library-details'), 'TSOL details leaked onto the native MemberPress Course editor.');
    $assert(!$has_meta_box('mpcs-course', 'tsol-library-curriculum'), 'TSOL curriculum leaked onto the native MemberPress Course editor.');
    $assert(!$has_meta_box('mpcs-course', 'tsol-library-course-page-content'), 'TSOL Course landing-page fields leaked onto a native MemberPress Course.');
    $assert(!$has_meta_box('mpcs-course', 'tsol-library-protection'), 'TSOL access UI leaked onto the native MemberPress Course editor.');
    $assert(!$has_meta_box('mpcs-course', 'tsol-library-ai-assistant'), 'TSOL AI assistant UI leaked onto the native MemberPress Course editor.');
    $assert(!$has_meta_box('mpcs-course', 'tsol-library-speakers'), 'TSOL Speaker relationships leaked onto the native MemberPress Course editor.');

    ob_start();
    $editor->render_course_page_content_meta_box(get_post($course_fixture_id));
    $course_page_content_html = ob_get_clean();
    $assert(false === strpos($course_page_content_html, 'native Course body supplies the public About this course copy'), 'The removed public-body notice remains in the Course landing-page editor.');
    $assert(false === strpos($course_page_content_html, 'member-only downloads and links to a lesson'), 'The removed protected-resource notice remains in the Course landing-page editor.');
    $assert(false === strpos($course_page_content_html, 'tsol_library[course_public_description]'), 'The retired duplicate public About field remains in the Course landing-page editor.');
    $assert(false === strpos($course_page_content_html, 'wp-editor-wrap'), 'The Course landing-page panel still renders a duplicate WYSIWYG editor.');
    $assert(false !== strpos($course_page_content_html, 'tsol_library[course_learning_outcomes_present]'), 'The Course learning-outcomes editor omitted its stale-form safety marker.');
    $assert(false !== strpos($course_page_content_html, 'tsol_library[learning_outcomes][0][title]'), 'The Course landing-page editor omitted editable learning-outcome titles.');
    $assert(false !== strpos($course_page_content_html, 'tsol_library[learning_outcomes][0][description]'), 'The Course landing-page editor omitted editable learning-outcome supporting text.');
    $assert(false !== strpos($course_page_content_html, 'data-outcome-handle'), 'The Course landing-page editor omitted its functional outcome-order handle.');
    $assert(false !== strpos($course_page_content_html, 'Build an independent training plan.'), 'The Course landing-page editor did not render a stored learning outcome.');

    $structured_outcomes = MemberLibrary_Content_Model::sanitize_course_learning_outcomes(array(
        array(
            'title' => 'Choose the right weight',
            'description' => 'Calculate a safe starting weight.',
        ),
        array(
            'title' => '',
            'description' => 'Use the supporting text as a title when the title is blank.',
        ),
    ));
    $assert(
        array(
            'Choose the right weight — Calculate a safe starting weight.',
            'Use the supporting text as a title when the title is blank.',
        ) === $structured_outcomes,
        'Structured learning-outcome fields did not retain the existing plain-text catalogue contract.'
    );

    ob_start();
    $editor->render_details_meta_box(get_post($fixture_id));
    $details_html = ob_get_clean();
    $assert(false === strpos($details_html, 'id="tsol-library-overview-reviewed"'), 'The obsolete per-record Description review control is still present.');
    $assert(false === strpos($details_html, 'Include in the TSOL Library'), 'The retired editable Library inclusion gate remains in Library details.');
    $assert(false === strpos($details_html, 'This controls how the item appears in the Library catalogue'), 'The redundant catalogue/access introduction remains in Library details.');
    $assert(false === strpos($details_html, 'Library text content'), 'The redundant Library text content heading remains in Library details.');
    $assert(false === strpos($details_html, 'The Excerpt is synchronized as the short introduction'), 'The redundant Excerpt/Description helper remains in Library details.');
    $assert(false === strpos($details_html, 'Content type'), 'The Content editor still asks administrators to classify a structurally derived record type.');
    $assert(false === strpos($details_html, '>Featured<'), 'The Content editor still exposes the retired per-record Featured checkbox.');
    $assert(false === strpos($details_html, 'id="tsol-library-position"'), 'The Content editor still exposes a raw item position field.');
    $assert(false === strpos($details_html, 'id="tsol-library-section-title"'), 'The Content editor still exposes a raw section-title field.');
    $assert(false === strpos($details_html, 'id="tsol-library-section-position"'), 'The Content editor still exposes a raw section-position field.');
    $assert(false === strpos($details_html, 'id="tsol-library-series-group-title"'), 'The Content editor still exposes a raw Series-group title field.');
    $assert(false === strpos($details_html, 'id="tsol-library-series-group-position"'), 'The Content editor still exposes a raw Series-group position field.');
    $assert(false !== strpos($details_html, 'id="tsol-library-placement-type"'), 'The Content editor omitted the single placement-type control.');
    $assert(false !== strpos($details_html, 'id="tsol-library-section-key"'), 'The Content editor omitted the parent-owned Course section selector.');
    $assert(false !== strpos($details_html, 'id="tsol-library-series-group-key"'), 'The Content editor omitted the parent-owned Series group selector.');
    $assert(false !== strpos($details_html, 'Open parent structure builder'), 'The Content editor omitted its parent Structure Builder link.');

    $_POST = array(
        MemberLibrary_Content_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_Content_Admin::NONCE_ACTION),
        MemberLibrary_Content_Admin::PAYLOAD_NAME => array(
            'content_type' => 'webinar',
            'speaker_mode' => MemberLibrary_Content_Model::SPEAKER_MODE_DIRECT,
            'speaker_ids' => array((string) $speaker_fixture_id, (string) $second_speaker_fixture_id),
            'media_assets' => array(array('source_url' => 'https://vimeo.com/123456789/abcdef12')),
            'resources' => array(),
        ),
    );
    $editor->save_post($fixture_id, get_post($fixture_id));

    ob_start();
    $editor->render_speakers_meta_box(get_post($fixture_id));
    $speaker_picker_html = ob_get_clean();
    $first_speaker_position = strpos($speaker_picker_html, 'value="' . $speaker_fixture_id . '"');
    $second_speaker_position = strpos($speaker_picker_html, 'value="' . $second_speaker_fixture_id . '"');
    $assert(false !== strpos($speaker_picker_html, 'data-speaker-picker'), 'The visual Speaker picker wrapper is missing.');
    $assert(false !== strpos($speaker_picker_html, 'data-speaker-search'), 'The searchable Speaker combobox is missing.');
    $assert(false !== strpos($speaker_picker_html, 'role="listbox"'), 'The Speaker search results are missing listbox semantics.');
    $assert(false !== strpos($speaker_picker_html, 'data-speaker-selected'), 'The visual selected-Speakers list is missing.');
    $assert(false !== strpos($speaker_picker_html, 'name="tsol_library[speaker_ids][]"'), 'The native multiple-select fallback no longer submits Speaker IDs.');
    $assert(false !== strpos($speaker_picker_html, 'data-speaker-job-title="Contract researcher"'), 'Speaker role context is missing from the picker data.');
    $assert(false !== strpos($speaker_picker_html, 'data-speaker-organization="TSOL Contract Institute"'), 'Speaker organisation context is missing from the picker data.');
    $assert(false === strpos($speaker_picker_html, 'Speakers are catalogue information only'), 'The redundant catalogue/access helper remains in the Speaker picker.');
    $assert(
        false !== $first_speaker_position && false !== $second_speaker_position && $first_speaker_position < $second_speaker_position,
        'The Speaker picker did not preserve the saved relationship order ahead of alphabetical search options.'
    );

    ob_start();
    $editor->render_media_meta_box(get_post($fixture_id));
    $media_html = ob_get_clean();
    $assert(false !== strpos($media_html, 'Vimeo'), 'Saved provider confirmation is missing from the media editor.');
    $assert(false !== strpos($media_html, 'Private Vimeo reference detected'), 'Private Vimeo confirmation is missing from the media editor.');
    $assert(false !== strpos($media_html, 'data-media-template'), 'Repeatable media template is missing.');
    $assert(false !== strpos($media_html, 'data-media-provider'), 'The media source selector is missing.');
    $assert(false !== strpos($media_html, 'WordPress Media Library'), 'The WordPress source option is missing.');
    $assert(false !== strpos($media_html, 'Direct video or audio URL'), 'The direct media source option is missing.');
    $assert(false !== strpos($media_html, 'data-media-test'), 'The on-demand playback test is missing.');
    $assert(false !== strpos($media_html, 'This replaces the current media identity.'), 'The replacement warning is missing.');
    $assert(false === strpos($media_html, 'Duration in seconds'), 'The retired manual media duration control is still visible.');
    $assert(false === strpos($media_html, 'Allow as preview media'), 'The retired per-asset preview control is still visible.');
    $assert(false !== strpos($media_html, 'id="tsol-library-availability"'), 'The media editor omitted its availability control.');
    $assert(false !== strpos($media_html, 'type="date"'), 'The media editor omitted its optional date-only release control.');
    $assert(false === strpos($media_html, 'type="datetime-local"'), 'The media editor still asks administrators for a release time.');
    $assert(false !== strpos($media_html, 'data-library-transcript-editor'), 'The transcript upload is not integrated with the media editor.');
    $assert(false !== strpos($media_html, 'Primary video transcript'), 'The media editor does not identify which source owns the transcript.');
    $assert(false !== strpos($media_html, 'name="' . MemberLibrary_Content_Transcripts::FILE_NAME . '"'), 'The media editor omitted the WebVTT upload control.');

    ob_start();
    $editor->render_protection_meta_box(get_post($fixture_id));
    $protection_html = ob_get_clean();
    $assert(false !== strpos($protection_html, 'No MemberPress rule applies'), 'Unprotected MemberPress state was not explained.');
    $assert(false === strpos($protection_html, 'type="checkbox"'), 'Access summary rendered a second permission checklist.');

    $_POST = array(
        MemberLibrary_Content_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_Content_Admin::NONCE_ACTION),
        MemberLibrary_Content_Admin::PAYLOAD_NAME => array(
            'content_type' => 'webinar',
            'series_id' => (string) $series_fixture_id,
            'speaker_mode' => MemberLibrary_Content_Model::SPEAKER_MODE_NONE,
            'media_assets' => array(array('source_url' => 'https://vimeo.com/123456789/abcdef12')),
            'resources' => array(),
        ),
    );
    $editor->save_post($fixture_id, get_post($fixture_id));
    $no_presenter_context = MemberLibrary_Content_Model::effective_speaker_context($fixture_id);
    $assert('none' === ($no_presenter_context['source'] ?? ''), 'Explicit no-presenter mode still exposed a parent Speaker source.');
    $assert($series_fixture_id === (int) ($no_presenter_context['parent_id'] ?? 0), 'Explicit no-presenter mode lost its Series parent context.');
    $assert(array() === ($no_presenter_context['speaker_ids'] ?? null), 'Explicit no-presenter mode still exposed Speakers.');
    $assert(array() === get_post_meta($fixture_id, MemberLibrary_Content_Model::META_SPEAKER_IDS, false), 'Explicit no-presenter mode retained direct Speaker relationships.');

    $invalid_id = wp_insert_post(array(
        'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => 'TSOL Library invalid publish contract fixture',
        'post_content' => '',
    ), true);
    if (is_wp_error($invalid_id)) {
        throw new RuntimeException($invalid_id->get_error_message());
    }
    $invalid_id = (int) $invalid_id;
    $created_post_ids[] = $invalid_id;

    $_POST = array(
        MemberLibrary_Content_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_Content_Admin::NONCE_ACTION),
        MemberLibrary_Content_Admin::PAYLOAD_NAME => array(
            'content_type' => 'recording',
            'media_assets' => array(array('source_url' => '')),
            'resources' => array(),
        ),
    );
    $editor->save_post($invalid_id, get_post($invalid_id));
    $assert('draft' === get_post_status($invalid_id), 'Incomplete published Library Item was not forced back to draft.');

    $rule = new MeprRule();
    $rule->post_title = 'TSOL Library availability contract rule';
    $rule->post_status = 'publish';
    $rule->mepr_type = 'single_' . MemberLibrary_Content_Model::COURSE_POST_TYPE;
    $rule->mepr_content = (string) $course_fixture_id;
    $rule->auto_gen_title = false;
    $rule_id = (int) $rule->store();
    if ($rule_id <= 0) {
        throw new RuntimeException('Could not create the disposable availability rule.');
    }
    $created_rule_ids[] = $rule_id;
    $condition = new MeprRuleAccessCondition();
    $condition->rule_id = $rule_id;
    $condition->access_type = 'role';
    $condition->access_operator = 'is';
    $condition->access_condition = 'subscriber';
    if ((int) $condition->store() <= 0) {
        throw new RuntimeException('Could not create the disposable availability rule condition.');
    }
    wp_update_post(array('ID' => $rule_id, 'post_status' => 'publish'));
    MeprRule::$all_rules = null;
    delete_transient('mepr_all_models_for_class_meprrule');

    $coming_soon_id = wp_insert_post(array(
        'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => 'TSOL Library coming-soon contract fixture',
        'post_content' => '',
    ), true);
    if (is_wp_error($coming_soon_id)) {
        throw new RuntimeException($coming_soon_id->get_error_message());
    }
    $coming_soon_id = (int) $coming_soon_id;
    $created_post_ids[] = $coming_soon_id;
    $_POST = array(
        MemberLibrary_Content_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_Content_Admin::NONCE_ACTION),
        MemberLibrary_Content_Admin::PAYLOAD_NAME => array(
            'availability' => MemberLibrary_Content_Model::AVAILABILITY_COMING_SOON,
            'release_date_local' => '2026-08-18',
            'placement_type' => 'course',
            'course_id' => (string) $course_fixture_id,
            'section_title' => 'Course content',
            'media_assets' => array(array('source_url' => '')),
            'resources' => array(),
        ),
    );
    $editor->save_post($coming_soon_id, get_post($coming_soon_id));
    $assert('publish' === get_post_status($coming_soon_id), 'A protected coming-soon Content item without media was forced back to draft.');
    $assert(MemberLibrary_Content_Model::AVAILABILITY_COMING_SOON === MemberLibrary_Content_Model::availability($coming_soon_id), 'Coming-soon availability was not saved.');
    $assert('2026-08-18 04:00:00' === MemberLibrary_Content_Model::release_at_gmt($coming_soon_id), 'The Eastern release date was not stored canonically in UTC.');
    $assert(array() === get_post_meta($coming_soon_id, MemberLibrary_Content_Model::META_MEDIA_ASSETS, true), 'A coming-soon fixture unexpectedly gained playable media.');
    $coming_soon_record = MemberLibrary_Content_Catalogue::record($coming_soon_id);
    $assert(!is_wp_error($coming_soon_record) && 'coming_soon' === ($coming_soon_record['availability'] ?? ''), 'The catalogue omitted coming-soon availability.');
    $assert(!is_wp_error($coming_soon_record) && '2026-08-18T04:00:00+00:00' === ($coming_soon_record['release_at'] ?? ''), 'The catalogue omitted the canonical release date.');

    $unprotected_course_id = wp_insert_post(array(
        'post_type' => MemberLibrary_Content_Model::COURSE_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => 'TSOL Library unprotected publish contract fixture',
    ), true);
    if (is_wp_error($unprotected_course_id)) {
        throw new RuntimeException($unprotected_course_id->get_error_message());
    }
    $unprotected_course_id = (int) $unprotected_course_id;
    $created_post_ids[] = $unprotected_course_id;
    $_POST = array(
        MemberLibrary_Content_Admin::NONCE_NAME => wp_create_nonce(MemberLibrary_Content_Admin::NONCE_ACTION),
        MemberLibrary_Content_Admin::PAYLOAD_NAME => array(
            'content_type' => 'course',
            'media_assets' => array(),
            'resources' => array(),
        ),
    );
    $editor->save_post($unprotected_course_id, get_post($unprotected_course_id));
    $assert('draft' === get_post_status($unprotected_course_id), 'A Course without a published MemberPress rule was allowed to publish.');
} finally {
    $_POST = $original_post;
    $_GET = $original_get;
    $wp_meta_boxes = $original_meta_boxes;
    foreach ($created_post_ids as $created_post_id) {
        delete_transient(MemberLibrary_Content_Admin::NOTICE_PREFIX . get_current_user_id() . '_' . $created_post_id);
        wp_delete_post($created_post_id, true);
    }
    foreach ($created_term_ids as $created_term_id) {
        wp_delete_term($created_term_id, MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY);
    }
    global $wpdb;
    foreach ($created_rule_ids as $created_rule_id) {
        $wpdb->delete($wpdb->prefix . 'mepr_rule_access_conditions', array('rule_id' => (int) $created_rule_id), array('%d'));
        wp_delete_post((int) $created_rule_id, true);
    }
    MeprRule::$all_rules = null;
    delete_transient('mepr_all_models_for_class_meprrule');
    if ($original_screen instanceof WP_Screen) {
        set_current_screen($original_screen);
    }
    wp_set_current_user($original_user_id);
}

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}

WP_CLI::success('TSOL Library content admin contract passed; disposable fixtures were removed.');
