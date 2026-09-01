<?php
/**
 * Upsert the local Media Source Demo Series.
 *
 * Run with: wp eval-file /absolute/path/to/create-media-source-demo.php --skip-themes
 */

if (!defined('ABSPATH')) {
    exit;
}

$marker = 'media-source-demo-series';
$existing = get_posts(array(
    'post_type' => MemberLibrary_Content_Model::SERIES_POST_TYPE,
    'post_status' => array_values(get_post_stati()),
    'numberposts' => 1,
    'meta_key' => MemberLibrary_Content_Model::META_MIGRATION_KEY,
    'meta_value' => $marker,
));
$series_id = $existing ? (int) $existing[0]->ID : wp_insert_post(array(
    'post_type' => MemberLibrary_Content_Model::SERIES_POST_TYPE,
    'post_status' => 'publish',
    'post_title' => 'Media Source Demo Series',
    'post_name' => 'media-source-demo-series',
    'post_excerpt' => 'One Library player, demonstrated with four different video sources.',
    'post_content' => '<p>Compare the same Library controls, progress, notes, and navigation across Vimeo, YouTube, WordPress Media Library, and a direct video URL.</p>',
), true);
if (is_wp_error($series_id)) {
    throw new Exception($series_id->get_error_message());
}
wp_update_post(array('ID' => $series_id, 'post_status' => 'publish'));
if (!get_post_meta($series_id, MemberLibrary_Content_Model::META_UUID, true)) {
    update_post_meta($series_id, MemberLibrary_Content_Model::META_UUID, wp_generate_uuid4());
}
update_post_meta($series_id, MemberLibrary_Content_Model::META_MIGRATION_KEY, $marker);
update_post_meta($series_id, MemberLibrary_Content_Model::META_MIGRATION_VERSION, '2026-08-23');
update_post_meta($series_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, $series_id);
update_post_meta($series_id, MemberLibrary_Content_Model::META_CONTENT_FINGERPRINT, hash('sha256', $marker));
update_post_meta($series_id, MemberLibrary_Content_Model::META_SERIES_ITEM_LABEL, 'episode');
update_post_meta($series_id, MemberLibrary_Content_Model::META_SERIES_ITEM_LABEL_PLURAL, 'episodes');
update_post_meta($series_id, MemberLibrary_Content_Model::META_SERIES_SORT, 'asc');
update_post_meta($series_id, MemberLibrary_Content_Model::META_SERIES_GROUPS, array(
    array('key' => 'source-demos', 'title' => 'Source demos', 'position' => 1),
));

$video_attachments = get_posts(array(
    'post_type' => 'attachment',
    'post_status' => 'inherit',
    'post_mime_type' => 'video',
    'numberposts' => 1,
    'fields' => 'ids',
));
if (!$video_attachments) {
    throw new Exception('The demo requires one video in the WordPress Media Library.');
}
$attachment_id = (int) $video_attachments[0];
$attachment_url = (string) wp_get_attachment_url($attachment_id);
$sources = array(
    array('slug' => 'media-source-demo-vimeo', 'title' => 'Episode 1: Vimeo', 'url' => 'https://player.vimeo.com/video/1172662943?h=d71537737e'),
    array('slug' => 'media-source-demo-youtube', 'title' => 'Episode 2: YouTube', 'url' => 'https://www.youtube.com/watch?v=M7lc1UVf-VE'),
    array('slug' => 'media-source-demo-wordpress', 'title' => 'Episode 3: WordPress Media Library', 'url' => $attachment_url),
    array('slug' => 'media-source-demo-direct', 'title' => 'Episode 4: Direct video URL', 'url' => 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4'),
);
$episodes = array();
foreach ($sources as $index => $source) {
    $posts = get_posts(array(
        'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
        'post_status' => array_values(get_post_stati()),
        'numberposts' => 1,
        'meta_key' => MemberLibrary_Content_Model::META_MIGRATION_KEY,
        'meta_value' => $source['slug'],
    ));
    $episode_id = $posts ? (int) $posts[0]->ID : wp_insert_post(array(
        'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
        'post_status' => 'publish',
        'post_title' => $source['title'],
        'post_name' => $source['slug'],
        'post_excerpt' => 'The complete Library playback experience using this episode source.',
    ), true);
    if (is_wp_error($episode_id)) {
        throw new Exception($episode_id->get_error_message());
    }
    wp_update_post(array('ID' => $episode_id, 'post_status' => 'publish'));
    $asset = MemberLibrary_Media_Normalizer::normalize_asset(array(
        'source_url' => $source['url'],
        'key' => 'primary-video',
        'position' => 1,
    ), 1);
    if (is_wp_error($asset)) {
        throw new Exception($asset->get_error_message());
    }
    if (!get_post_meta($episode_id, MemberLibrary_Content_Model::META_UUID, true)) {
        update_post_meta($episode_id, MemberLibrary_Content_Model::META_UUID, wp_generate_uuid4());
    }
    update_post_meta($episode_id, MemberLibrary_Content_Model::META_MIGRATION_KEY, $source['slug']);
    update_post_meta($episode_id, MemberLibrary_Content_Model::META_MIGRATION_VERSION, '2026-08-23');
    update_post_meta($episode_id, MemberLibrary_Content_Model::META_CONTENT_FINGERPRINT, hash('sha256', $source['slug'] . ':' . $source['url']));
    update_post_meta($episode_id, MemberLibrary_Content_Model::META_CONTENT_TYPE, 'episode');
    update_post_meta($episode_id, MemberLibrary_Content_Model::META_AVAILABILITY, MemberLibrary_Content_Model::AVAILABILITY_AVAILABLE);
    update_post_meta($episode_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, $series_id);
    update_post_meta($episode_id, MemberLibrary_Content_Model::META_SERIES_ID, $series_id);
    update_post_meta($episode_id, MemberLibrary_Content_Model::META_SERIES_GROUP_KEY, 'source-demos');
    update_post_meta($episode_id, MemberLibrary_Content_Model::META_SERIES_GROUP_TITLE, 'Source demos');
    update_post_meta($episode_id, MemberLibrary_Content_Model::META_SERIES_GROUP_POSITION, 1);
    update_post_meta($episode_id, MemberLibrary_Content_Model::META_POSITION, $index + 1);
    update_post_meta($episode_id, MemberLibrary_Content_Model::META_MEDIA_ASSETS, array($asset));
    do_action('tsol_library_content_changed', $episode_id);
    $episodes[] = array(
        'postId' => $episode_id,
        'title' => $source['title'],
        'contentUuid' => (string) get_post_meta($episode_id, MemberLibrary_Content_Model::META_UUID, true),
        'provider' => (string) $asset['provider'],
    );
}
do_action('tsol_library_content_changed', $series_id);
echo wp_json_encode(array(
    'seriesId' => $series_id,
    'seriesUuid' => (string) get_post_meta($series_id, MemberLibrary_Content_Model::META_UUID, true),
    'slug' => 'media-source-demo-series',
    'episodes' => $episodes,
));
