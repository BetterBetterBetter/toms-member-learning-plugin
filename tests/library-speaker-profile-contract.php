<?php
/**
 * Disposable contract for private Library Speaker profiles and relationships.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

global $wpdb;

$failures = array();
$created_speaker_id = 0;
$created_content_id = 0;
$created_attachment_id = 0;
$original_user_id = get_current_user_id();
$original_post = $_POST;

$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

try {
    TSOL_Library_Content_Model::register();
    TSOL_Library_Content_Changes::maybe_install();
    $assert(!taxonomy_exists('tsol_speaker'), 'The retired Speaker taxonomy is still registered.');

    $speaker_type = get_post_type_object(TSOL_Library_Content_Model::SPEAKER_POST_TYPE);
    $assert($speaker_type instanceof WP_Post_Type, 'The Library Speaker post type is not registered.');
    if ($speaker_type instanceof WP_Post_Type) {
        $assert(false === $speaker_type->public && false === $speaker_type->publicly_queryable, 'Speaker profiles unexpectedly have a WordPress frontend.');
        $assert(true === $speaker_type->show_ui && false === $speaker_type->show_in_rest, 'Speaker profiles do not have the required private wp-admin configuration.');
    }
    $assert(post_type_supports(TSOL_Library_Content_Model::SPEAKER_POST_TYPE, 'editor'), 'Speaker About does not use the native WordPress editor.');
    $assert(post_type_supports(TSOL_Library_Content_Model::SPEAKER_POST_TYPE, 'excerpt'), 'Speaker Short bio does not use the native WordPress excerpt.');
    $assert(post_type_supports(TSOL_Library_Content_Model::SPEAKER_POST_TYPE, 'thumbnail'), 'Speaker Headshot does not use the native Featured Image workflow.');
    $assert(!post_type_supports(TSOL_Library_Content_Model::SPEAKER_POST_TYPE, 'author'), 'Speaker profiles unexpectedly expose WordPress authorship.');
    $assert(!in_array(TSOL_Library_Content_Model::SPEAKER_POST_TYPE, TSOL_Library_Content_Model::post_types(), true), 'Speaker profiles are exposed as MemberPress authorization targets.');

    $administrator_ids = get_users(array(
        'role' => 'administrator',
        'fields' => 'ids',
        'number' => 1,
        'orderby' => 'ID',
        'order' => 'ASC',
    ));
    if (empty($administrator_ids)) {
        throw new RuntimeException('No administrator is available for the Speaker profile contract.');
    }
    wp_set_current_user((int) $administrator_ids[0]);

    $upload = wp_upload_bits(
        'tsol-speaker-profile-contract.png',
        null,
        base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
    );
    if (!empty($upload['error'])) {
        throw new RuntimeException((string) $upload['error']);
    }
    $attachment = wp_insert_attachment(array(
        'post_mime_type' => 'image/png',
        'post_title' => 'TSOL Speaker profile contract headshot',
        'post_status' => 'inherit',
        'guid' => (string) $upload['url'],
    ), (string) $upload['file'], 0, true);
    if (is_wp_error($attachment)) {
        throw new RuntimeException($attachment->get_error_message());
    }
    $created_attachment_id = (int) $attachment;
    require_once ABSPATH . 'wp-admin/includes/image.php';
    wp_update_attachment_metadata(
        $created_attachment_id,
        wp_generate_attachment_metadata($created_attachment_id, (string) $upload['file'])
    );
    update_post_meta($created_attachment_id, '_wp_attachment_image_alt', 'Contract speaker headshot');

    $speaker = wp_insert_post(array(
        'post_type' => TSOL_Library_Content_Model::SPEAKER_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => 'TSOL Speaker profile contract fixture',
        'post_name' => 'tsol-speaker-profile-contract-' . strtolower(wp_generate_password(8, false, false)),
        'post_content' => '<h1 class="pasted-title" style="color: red">Speaker biography</h1><p data-pasted="yes">A concise public biography for the disposable <strong>contract speaker</strong>.</p><style>.leak{color:red}</style>',
        'post_excerpt' => '<p style="color: red">A short <strong>course-page</strong> biography.</p><style>.leak{color:red}</style>',
    ), true);
    if (is_wp_error($speaker)) {
        throw new RuntimeException($speaker->get_error_message());
    }
    $created_speaker_id = (int) $speaker;
    set_post_thumbnail($created_speaker_id, $created_attachment_id);

    $speaker_admin = new TSOL_Library_Speaker_Admin();
    $_POST = array(
        TSOL_Library_Speaker_Admin::NONCE_NAME => wp_create_nonce(TSOL_Library_Speaker_Admin::NONCE_ACTION),
        TSOL_Library_Speaker_Admin::PAYLOAD_NAME => array(
            'job_title' => 'Research Director',
            'organization' => 'Example Institute',
            'website_url' => 'https://example.test/speaker',
            'social_links' => array(
                array('platform' => 'linkedin', 'url' => 'https://www.linkedin.com/in/example'),
                array('platform' => 'youtube', 'url' => 'https://www.youtube.com/@example'),
            ),
        ),
    );
    $speaker_admin->save_post($created_speaker_id, get_post($created_speaker_id), false);

    $assert('' !== (string) get_post_meta($created_speaker_id, TSOL_Library_Content_Model::SPEAKER_META_UUID, true), 'Speaker immutable UUID was not created.');
    $assert('Research Director' === get_post_meta($created_speaker_id, TSOL_Library_Content_Model::SPEAKER_META_JOB_TITLE, true), 'Speaker job title was not saved.');
    $assert('Example Institute' === get_post_meta($created_speaker_id, TSOL_Library_Content_Model::SPEAKER_META_ORGANIZATION, true), 'Speaker organisation was not saved.');
    $assert('https://example.test/speaker' === get_post_meta($created_speaker_id, TSOL_Library_Content_Model::SPEAKER_META_WEBSITE_URL, true), 'Speaker website was not saved.');
    $saved_links = get_post_meta($created_speaker_id, TSOL_Library_Content_Model::SPEAKER_META_SOCIAL_LINKS, true);
    $assert(is_array($saved_links) && 2 === count($saved_links), 'Repeatable Speaker social links were not saved.');
    $assert($created_attachment_id === (int) get_post_thumbnail_id($created_speaker_id), 'Speaker Headshot was not stored as the native Featured Image.');
    $assert(TSOL_Library_Content_Model::ensure_speaker_image_size($created_attachment_id), 'WordPress could not prepare the square Speaker headshot rendition.');
    $assert('A short course-page biography.' === (string) get_post_field('post_excerpt', $created_speaker_id), 'Speaker Short bio was not stored as sanitized plain text.');

    ob_start();
    $speaker_admin->render_about_heading(get_post($created_speaker_id));
    $speaker_admin->render_details_meta_box(get_post($created_speaker_id));
    $speaker_admin->render_publication_guidance(get_post($created_speaker_id));
    $profile_html = ob_get_clean();
    foreach (array('About', 'Short bio', 'course instructor sections', 'If left blank, the Library creates a shortened summary from About.', 'Job title', 'Organisation / company', 'Website', 'Social links', 'data-speaker-social-template', 'Publishing makes this speaker available in the Library catalogue.') as $expected_copy) {
        $assert(false !== strpos($profile_html, $expected_copy), 'Speaker editor omitted: ' . $expected_copy);
    }
    foreach (array('Add only public profiles. Links must use HTTP or HTTPS.', 'The title is the speaker’s full name.') as $removed_copy) {
        $assert(false === strpos($profile_html, $removed_copy), 'Speaker editor retained redundant guidance: ' . $removed_copy);
    }
    $assert('Full name' === $speaker_admin->filter_title_placeholder('Add title', get_post($created_speaker_id)), 'Speaker title prompt is not labelled Full name.');
    $non_speaker_post = clone get_post($created_speaker_id);
    $non_speaker_post->post_type = TSOL_Library_Content_Model::ITEM_POST_TYPE;
    $assert('Add title' === $speaker_admin->filter_title_placeholder('Add title', $non_speaker_post), 'Speaker title prompt leaked to another post type.');
    $thumbnail_html = $speaker_admin->filter_thumbnail_html('', $created_speaker_id, $created_attachment_id);
    $assert(false !== strpos($thumbnail_html, 'position the required square crop'), 'Speaker Headshot help does not explain the required interactive crop.');
    $assert(false !== strpos($thumbnail_html, 'WordPress keeps the original image'), 'Speaker Headshot help does not explain original-image retention.');
    $assert(false === strpos($thumbnail_html, 'Edit or crop image'), 'Speaker Headshot still exposes the superseded attachment-editor workaround.');

    $columns = $speaker_admin->filter_columns(array('cb' => 'Select', 'title' => 'Name', 'date' => 'Date'));
    $assert(isset($columns[TSOL_Library_Speaker_Admin::IMAGE_COLUMN]), 'Speaker list omitted the Headshot column.');
    $assert(isset($columns[TSOL_Library_Speaker_Admin::ROLE_COLUMN]), 'Speaker list omitted the Role column.');
    $assert(isset($columns[TSOL_Library_Speaker_Admin::CONTENT_COLUMN]), 'Speaker list omitted the Content count column.');
    ob_start();
    $speaker_admin->render_column(TSOL_Library_Speaker_Admin::ROLE_COLUMN, $created_speaker_id);
    $role_column = ob_get_clean();
    $assert(false !== strpos($role_column, 'Research Director') && false !== strpos($role_column, 'Example Institute'), 'Speaker list Role column omitted saved profile data.');
    $assert(false !== strpos($role_column, 'tsol-speaker-list-role__job-title'), 'Speaker list Role column omitted the distinct job-title line.');
    $assert(false !== strpos($role_column, 'tsol-speaker-list-role__organization'), 'Speaker list Role column omitted the distinct organisation line.');

    $content = wp_insert_post(array(
        'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
        'post_status' => 'draft',
        'post_title' => 'TSOL Speaker catalogue contract fixture',
        'post_name' => 'tsol-speaker-catalogue-contract-fixture',
    ), true);
    if (is_wp_error($content)) {
        throw new RuntimeException($content->get_error_message());
    }
    $created_content_id = (int) $content;
    update_post_meta($created_content_id, TSOL_Library_Content_Model::META_CONTENT_TYPE, 'recording');
    update_post_meta($created_content_id, TSOL_Library_Content_Model::META_MIGRATION_KEY, 'speaker-profile-contract-fixture');
    update_post_meta($created_content_id, TSOL_Library_Content_Model::META_MIGRATION_VERSION, 'contract');
    update_post_meta($created_content_id, TSOL_Library_Content_Model::META_UUID, wp_generate_uuid4());
    update_post_meta($created_content_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, $created_content_id);
    update_post_meta($created_content_id, TSOL_Library_Content_Model::META_SPEAKER_MODE, TSOL_Library_Content_Model::SPEAKER_MODE_DIRECT);
    add_post_meta($created_content_id, TSOL_Library_Content_Model::META_SPEAKER_IDS, $created_speaker_id, false);

    $record = TSOL_Library_Content_Catalogue::record($created_content_id);
    $assert(!is_wp_error($record), 'Catalogue rejected the disposable Speaker content record.');
    $catalogue_speaker = !is_wp_error($record) ? ($record['speakers'][0] ?? array()) : array();
    $assert('direct' === ($record['speaker_source'] ?? ''), 'Catalogue omitted the direct Speaker attribution source.');
    $assert($created_speaker_id === (int) ($catalogue_speaker['wordpress_id'] ?? 0), 'Catalogue omitted the Speaker post identity.');
    $assert(!array_key_exists('taxonomy', $catalogue_speaker), 'Catalogue still models a Speaker profile as a taxonomy term.');
    $assert('Research Director' === ($catalogue_speaker['job_title'] ?? ''), 'Catalogue omitted the Speaker job title.');
    $assert('Example Institute' === ($catalogue_speaker['organization'] ?? ''), 'Catalogue omitted the Speaker organisation.');
    $assert('A short course-page biography.' === ($catalogue_speaker['short_bio'] ?? ''), 'Catalogue omitted the editorial Speaker Short bio.');
    $assert(false !== strpos((string) ($catalogue_speaker['about'] ?? ''), '<strong>contract speaker</strong>'), 'Catalogue did not preserve safe WYSIWYG formatting in Speaker About.');
    $assert(false !== strpos((string) ($catalogue_speaker['about'] ?? ''), '<h2>Speaker biography</h2>'), 'Catalogue did not normalize a pasted Speaker H1 to a section-level heading.');
    $assert(false === strpos((string) ($catalogue_speaker['about'] ?? ''), '<style'), 'Catalogue exposed pasted Speaker style markup.');
    $assert(false === strpos((string) ($catalogue_speaker['about'] ?? ''), 'class='), 'Catalogue exposed a pasted Speaker class attribute.');
    $assert(false === strpos((string) ($catalogue_speaker['about'] ?? ''), 'style='), 'Catalogue exposed a pasted Speaker style attribute.');
    $assert('https://example.test/speaker' === ($catalogue_speaker['website_url'] ?? ''), 'Catalogue omitted the Speaker website.');
    $assert(2 === count($catalogue_speaker['social_links'] ?? array()), 'Catalogue omitted repeatable Speaker social links.');
    $assert($created_attachment_id === (int) ($catalogue_speaker['image']['wordpress_id'] ?? 0), 'Catalogue omitted the Speaker headshot identity.');
    $assert('Contract speaker headshot' === ($catalogue_speaker['image']['alt'] ?? ''), 'Catalogue omitted the Headshot alternative text.');
    wp_update_post(array('ID' => $created_speaker_id, 'post_excerpt' => ''));
    $fallback_record = TSOL_Library_Content_Catalogue::record($created_content_id);
    $assert(
        'Speaker biography A concise public biography for the disposable contract speaker.' === ($fallback_record['speakers'][0]['short_bio'] ?? ''),
        'Catalogue did not create a plain-text Short bio fallback from Speaker About.'
    );
    wp_update_post(array('ID' => $created_speaker_id, 'post_excerpt' => 'A short course-page biography.'));

    $assert('20260821.2' === TSOL_Library_Content_Catalogue::SCHEMA_VERSION, 'Speaker profile catalogue schema version is not explicit.');

    wp_update_post(array('ID' => $created_speaker_id, 'post_status' => 'draft'));
    $draft_speaker_record = TSOL_Library_Content_Catalogue::record($created_content_id);
    $assert(empty($draft_speaker_record['speakers']), 'A draft Speaker profile leaked into catalogue output.');
    wp_update_post(array('ID' => $created_speaker_id, 'post_status' => 'publish'));

    $cursor_before = TSOL_Library_Content_Changes::current_cursor();
    update_post_meta($created_speaker_id, TSOL_Library_Content_Model::SPEAKER_META_JOB_TITLE, 'Updated Research Director');
    $changes = TSOL_Library_Content_Catalogue::changes($cursor_before, 100);
    $matching_changes = array_filter($changes['changes'], static function ($change) use ($created_content_id) {
        return (int) $change['post_id'] === $created_content_id
            && 'upsert' === $change['action']
            && 'Updated Research Director' === ($change['item']['speakers'][0]['job_title'] ?? '');
    });
    $assert(!empty($matching_changes), 'Updating a Speaker profile did not enqueue related content for catalogue synchronization.');

    $_POST[TSOL_Library_Speaker_Admin::PAYLOAD_NAME]['website_url'] = 'ftp://example.test/profile';
    $speaker_admin->save_post($created_speaker_id, get_post($created_speaker_id), true);
    $notice_key = TSOL_Library_Speaker_Admin::NOTICE_PREFIX . get_current_user_id() . '_' . $created_speaker_id;
    $notice = get_transient($notice_key);
    $assert(is_array($notice) && !empty($notice), 'Invalid Speaker URLs did not create an administrator validation notice.');
    $assert('' === (string) get_post_meta($created_speaker_id, TSOL_Library_Content_Model::SPEAKER_META_WEBSITE_URL, true), 'Invalid Speaker website URL was stored.');
} finally {
    $_POST = $original_post;
    if ($created_speaker_id > 0) {
        delete_transient(TSOL_Library_Speaker_Admin::NOTICE_PREFIX . get_current_user_id() . '_' . $created_speaker_id);
    }
    if ($created_content_id > 0) {
        wp_delete_post($created_content_id, true);
        $wpdb->delete(TSOL_Library_Content_Changes::table(), array('post_id' => $created_content_id), array('%d'));
    }
    if ($created_speaker_id > 0) {
        wp_delete_post($created_speaker_id, true);
    }
    if ($created_attachment_id > 0) {
        wp_delete_attachment($created_attachment_id, true);
    }
    wp_set_current_user($original_user_id);
}

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}

WP_CLI::success('Private TSOL Library Speaker profile contract passed; disposable fixtures were removed.');
