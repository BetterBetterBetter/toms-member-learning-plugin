<?php
/**
 * Publication-readiness contract for the normalized TSOL Library catalogue.
 *
 * Hard failures block local publication. Editorial enrichment that is safe to
 * defer is reported as aggregate warnings without exposing protected URLs.
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

$types = array(
    TSOL_Library_Content_Model::COURSE_POST_TYPE,
    TSOL_Library_Content_Model::SERIES_POST_TYPE,
    TSOL_Library_Content_Model::ITEM_POST_TYPE,
);
$ids = array_map('intval', get_posts(array(
    'post_type' => $types,
    'post_status' => array_values(get_post_stati()),
    'posts_per_page' => -1,
    'fields' => 'ids',
    'suppress_filters' => true,
    'meta_query' => array(array(
        'key' => TSOL_Library_Content_Model::META_MIGRATION_KEY,
        'compare' => 'EXISTS',
    )),
)));

$counts = array_fill_keys($types, 0);
$uuids = array();
$slugs = array();
$course_item_counts = array();
$series_item_counts = array();
$course_items = 0;
$series_items = 0;
$media_assets = 0;
$resource_count = 0;
$provider_counts = array();
$manual_records = 0;
$coming_soon_items = 0;
$warnings = array(
    'empty_excerpts' => 0,
    'empty_descriptions' => 0,
    'missing_cover_art' => 0,
    'zero_duration_assets' => 0,
    'records_without_topics' => 0,
    'records_without_speakers' => 0,
);

foreach ($ids as $id) {
    $post = get_post($id);
    $assert($post instanceof WP_Post, sprintf('Library record %d is missing.', $id));
    if (!$post instanceof WP_Post) {
        continue;
    }
    $counts[$post->post_type]++;
    $assert(!in_array($post->post_status, array('trash', 'auto-draft'), true), sprintf('Library record %d is not reviewable.', $id));
    $assert('' !== trim(wp_strip_all_tags((string) $post->post_title)), sprintf('Library record %d has no title.', $id));
    $assert('' !== (string) $post->post_name, sprintf('Library record %d has no slug.', $id));
    $migration_key = (string) get_post_meta($id, TSOL_Library_Content_Model::META_MIGRATION_KEY, true);
    $assert('' !== $migration_key, sprintf('Library record %d has no stable editorial identity.', $id));
    if (0 === strpos($migration_key, 'manual-')) {
        $manual_records++;
        $assert(0 === (int) get_post_meta($id, TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID, true), sprintf('WordPress-native Library record %d claims false legacy provenance.', $id));
    } else {
        $assert((int) get_post_meta($id, TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID, true) > 0, sprintf('Imported Library record %d has no legacy provenance.', $id));
    }

    $uuid = (string) get_post_meta($id, TSOL_Library_Content_Model::META_UUID, true);
    $assert((bool) wp_is_uuid($uuid), sprintf('Library record %d has an invalid UUID.', $id));
    $assert(!isset($uuids[$uuid]), sprintf('Library record %d duplicates a UUID.', $id));
    $uuids[$uuid] = $id;
    $slug_key = $post->post_type . ':' . $post->post_name;
    $assert(!isset($slugs[$slug_key]), sprintf('Library record %d duplicates a slug in its content type.', $id));
    $slugs[$slug_key] = $id;

    $authorization_id = (int) get_post_meta($id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true);
    $authorization_post = get_post($authorization_id);
    $assert($authorization_post instanceof WP_Post, sprintf('Library record %d has no authorization source.', $id));
    if ($authorization_post instanceof WP_Post) {
        $assert(!empty(MeprRule::get_rules($authorization_post)), sprintf('Library record %d has no effective published MemberPress rule.', $id));
    }

    if ('' === trim((string) $post->post_excerpt)) {
        $warnings['empty_excerpts']++;
    }
    if ('' === trim(wp_strip_all_tags((string) $post->post_content))) {
        $warnings['empty_descriptions']++;
    }
    if (!has_post_thumbnail($id)) {
        $warnings['missing_cover_art']++;
    }
    $topics = wp_get_object_terms($id, TSOL_Library_Content_Model::TOPIC_TAXONOMY, array('fields' => 'ids'));
    if (is_wp_error($topics) || empty($topics)) {
        $warnings['records_without_topics']++;
    }
    $speaker_context = TSOL_Library_Content_Model::effective_speaker_context($id);
    if (empty($speaker_context['speaker_ids'])) {
        $warnings['records_without_speakers']++;
    }

    if (TSOL_Library_Content_Model::ITEM_POST_TYPE !== $post->post_type) {
        continue;
    }

    $course_id = (int) get_post_meta($id, TSOL_Library_Content_Model::META_COURSE_ID, true);
    $series_id = (int) get_post_meta($id, TSOL_Library_Content_Model::META_SERIES_ID, true);
    $assert(($course_id > 0) xor ($series_id > 0), sprintf('Library content %d must belong to exactly one Course or Series.', $id));
    if ($course_id > 0) {
        $assert(TSOL_Library_Content_Model::COURSE_POST_TYPE === get_post_type($course_id), sprintf('Library content %d has an invalid Course.', $id));
        $assert('' !== (string) get_post_meta($id, TSOL_Library_Content_Model::META_SECTION_KEY, true), sprintf('Course lesson %d has no section.', $id));
        $assert((int) get_post_meta($id, TSOL_Library_Content_Model::META_POSITION, true) > 0, sprintf('Course lesson %d has no position.', $id));
        $course_items++;
        $course_item_counts[$course_id] = ($course_item_counts[$course_id] ?? 0) + 1;
    }
    if ($series_id > 0) {
        $assert(TSOL_Library_Content_Model::SERIES_POST_TYPE === get_post_type($series_id), sprintf('Library content %d has an invalid Series.', $id));
        $assert('' !== (string) get_post_meta($id, TSOL_Library_Content_Model::META_SERIES_GROUP_KEY, true), sprintf('Series item %d has no group.', $id));
        $assert((int) get_post_meta($id, TSOL_Library_Content_Model::META_POSITION, true) > 0, sprintf('Series item %d has no position.', $id));
        $series_items++;
        $series_item_counts[$series_id] = ($series_item_counts[$series_id] ?? 0) + 1;
    }

    $availability = TSOL_Library_Content_Model::availability($id);
    $release_at_gmt = TSOL_Library_Content_Model::release_at_gmt($id);
    $assert(in_array($availability, array(TSOL_Library_Content_Model::AVAILABILITY_AVAILABLE, TSOL_Library_Content_Model::AVAILABILITY_COMING_SOON), true), sprintf('Library content %d has an invalid availability state.', $id));
    if (TSOL_Library_Content_Model::AVAILABILITY_COMING_SOON === $availability) {
        $coming_soon_items++;
    } else {
        $assert('' === $release_at_gmt, sprintf('Available Library content %d retained a coming-soon release time.', $id));
    }

    $assets = get_post_meta($id, TSOL_Library_Content_Model::META_MEDIA_ASSETS, true);
    $assets = is_array($assets) ? $assets : array();
    if (TSOL_Library_Content_Model::AVAILABILITY_AVAILABLE === $availability) {
        $assert(!empty($assets), sprintf('Available Library content %d has no playable media.', $id));
    }
    foreach ($assets as $index => $asset) {
        $normalized = TSOL_Library_Media_Normalizer::normalize_asset($asset, $index + 1);
        $assert(!is_wp_error($normalized), sprintf('Library content %d has invalid media.', $id));
        if (is_wp_error($normalized)) {
            continue;
        }
        $media_assets++;
        $provider = (string) $normalized['provider'];
        $provider_counts[$provider] = ($provider_counts[$provider] ?? 0) + 1;
        if (empty($normalized['duration_seconds'])) {
            $warnings['zero_duration_assets']++;
        }
    }

    $resources = get_post_meta($id, TSOL_Library_Content_Model::META_RESOURCES, true);
    $resources = is_array($resources) ? $resources : array();
    foreach ($resources as $resource) {
        $resource_count++;
        $assert('' !== trim((string) ($resource['label'] ?? '')), sprintf('Library content %d has an unlabeled resource.', $id));
        $assert(
            !empty($resource['url']) || !empty($resource['attachment_id']),
            sprintf('Library content %d has a resource without a destination.', $id)
        );
    }
}

$assert(7 === $counts[TSOL_Library_Content_Model::COURSE_POST_TYPE], 'The catalogue does not contain seven Courses.');
$assert(6 === $counts[TSOL_Library_Content_Model::SERIES_POST_TYPE], 'The catalogue does not contain six Series.');
$assert(196 === $counts[TSOL_Library_Content_Model::ITEM_POST_TYPE], 'The catalogue does not contain 196 reviewable Content records.');
$assert(75 === $course_items, 'The catalogue does not contain 75 Course lessons.');
$assert(121 === $series_items, 'The catalogue does not contain 121 Series items.');
$assert(2 === $manual_records, 'The catalogue does not contain the two approved WordPress-native Medicine Cabinet lessons.');
$assert(2 === $coming_soon_items, 'The catalogue does not contain the two approved coming-soon lessons.');
$assert(199 === $media_assets, 'The catalogue does not contain the expected 199 media assets.');
$assert(30 <= $resource_count, 'The catalogue lost one or more of the 30 locked imported resources.');
$assert(7 === count(array_filter($course_item_counts)), 'A Course has no curriculum.');
$assert(6 === count(array_filter($series_item_counts)), 'A Series has no content.');
ksort($provider_counts, SORT_STRING);
$assert(array('vimeo' => 195, 'wordpress' => 1, 'youtube' => 3) === $provider_counts, 'The media provider inventory changed.');

$published_speaker_ids = get_posts(array(
    'post_type' => TSOL_Library_Content_Model::SPEAKER_POST_TYPE,
    'post_status' => 'publish',
    'posts_per_page' => -1,
    'fields' => 'ids',
    'suppress_filters' => true,
));
foreach (array_map('intval', $published_speaker_ids) as $speaker_id) {
    $assert('' !== trim((string) get_the_title($speaker_id)), sprintf('Speaker %d has no name.', $speaker_id));
    $assert('' !== trim(wp_strip_all_tags((string) get_post_field('post_content', $speaker_id))), sprintf('Speaker %d has no About content.', $speaker_id));
    $assert(get_post_thumbnail_id($speaker_id) > 0, sprintf('Speaker %d has no headshot.', $speaker_id));
}

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}

WP_CLI::line(wp_json_encode(array(
    'scope' => 'tsol-library-editorial-readiness',
    'hard_gates' => 'passed',
    'records_checked' => count($ids),
    'courses' => $counts[TSOL_Library_Content_Model::COURSE_POST_TYPE],
    'series' => $counts[TSOL_Library_Content_Model::SERIES_POST_TYPE],
    'course_lessons' => $course_items,
    'series_items' => $series_items,
    'wordpress_native_records' => $manual_records,
    'coming_soon_items' => $coming_soon_items,
    'media_assets' => $media_assets,
    'media_providers' => $provider_counts,
    'resources' => $resource_count,
    'published_speakers_checked' => count($published_speaker_ids),
    'deferred_enrichment' => $warnings,
    'protected_urls_emitted' => 0,
    'member_identities_emitted' => 0,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
WP_CLI::success('TSOL Library hard publication-readiness gates passed.');
