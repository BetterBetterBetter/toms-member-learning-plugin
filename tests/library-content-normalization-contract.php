<?php
/**
 * Read-only contract for the TSOL Library content model and migration manifest.
 *
 * Run against either WordPress copy:
 * wp eval-file /absolute/path/to/library-content-normalization-contract.php --skip-themes
 */

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, "Run this contract with WP-CLI.\n");
    exit(1);
}

global $wpdb;

$plugin_root = dirname(__DIR__);
$class_files = array(
    'TSOL_Library_Media_Normalizer' => $plugin_root . '/includes/features/library-content/class-library-media-normalizer.php',
    'TSOL_Library_Resource_Normalizer' => $plugin_root . '/includes/features/library-content/class-library-resource-normalizer.php',
    'TSOL_Library_Content_Model' => $plugin_root . '/includes/features/library-content/class-library-content-model.php',
    'TSOL_Library_Normalization_Spec' => $plugin_root . '/includes/migrations/library-content-normalization/class-library-normalization-spec.php',
    'TSOL_Library_Normalization_Manifest' => $plugin_root . '/includes/migrations/library-content-normalization/class-library-normalization-manifest.php',
);

foreach ($class_files as $class_name => $class_file) {
    if (!class_exists($class_name, false)) {
        require_once $class_file;
    }
}

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$database_counts = static function () use ($wpdb) {
    return array(
        'posts' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"),
        'postmeta' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}"),
        'terms' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->terms}"),
        'term_taxonomy' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_taxonomy}"),
        'term_relationships' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->term_relationships}"),
        'options' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}"),
    );
};

TSOL_Library_Content_Model::register();
$post_type = get_post_type_object(TSOL_Library_Content_Model::ITEM_POST_TYPE);
$assert($post_type instanceof WP_Post_Type, 'Library Item post type is not registered.');
if ($post_type instanceof WP_Post_Type) {
    $assert(false === $post_type->public, 'Library Item post type must not be public.');
    $assert(false === $post_type->publicly_queryable, 'Library Item post type must not be publicly queryable.');
    $assert(false === $post_type->show_in_rest, 'Library Item post type must not use the generic REST API.');
    $assert(true === $post_type->show_ui, 'Library Item post type must remain editable in wp-admin.');
}

foreach (array(
    TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY,
    TSOL_Library_Content_Model::TOPIC_TAXONOMY,
) as $taxonomy_name) {
    $taxonomy = get_taxonomy($taxonomy_name);
    $assert($taxonomy instanceof WP_Taxonomy, sprintf('%s taxonomy is not registered.', $taxonomy_name));
    if ($taxonomy instanceof WP_Taxonomy) {
        if (TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY === $taxonomy_name) {
            $assert(true === $taxonomy->public, 'Collections must be discoverable as a MemberPress rule target.');
            $assert(false === $taxonomy->publicly_queryable, 'Collections must not have a WordPress frontend.');
        } else {
            $assert(false === $taxonomy->public, sprintf('%s taxonomy must not be public.', $taxonomy_name));
        }
        $assert(false === $taxonomy->show_in_rest, sprintf('%s taxonomy must not use the generic REST API.', $taxonomy_name));
    }
}

$assert(!taxonomy_exists('tsol_speaker'), 'The retired Speaker taxonomy is still registered.');
$speaker_post_type = get_post_type_object(TSOL_Library_Content_Model::SPEAKER_POST_TYPE);
$assert($speaker_post_type instanceof WP_Post_Type, 'Library Speaker post type is not registered.');
if ($speaker_post_type instanceof WP_Post_Type) {
    $assert(false === $speaker_post_type->public, 'Library Speakers must not be public.');
    $assert(false === $speaker_post_type->publicly_queryable, 'Library Speakers must not have a WordPress frontend.');
    $assert(false === $speaker_post_type->show_in_rest, 'Library Speakers must not use the generic REST API.');
    $assert(true === $speaker_post_type->show_ui, 'Library Speakers must remain editable in wp-admin.');
}

foreach (TSOL_Library_Content_Model::post_types() as $metadata_post_type) {
    $registered_meta = get_registered_meta_keys('post', $metadata_post_type);
    foreach (TSOL_Library_Content_Model::metadata_keys_for_post_type($metadata_post_type) as $metadata_key) {
        $assert(isset($registered_meta[$metadata_key]), sprintf('%s is not registered for %s.', $metadata_key, $metadata_post_type));
        if (isset($registered_meta[$metadata_key])) {
            $assert(false === $registered_meta[$metadata_key]['show_in_rest'], sprintf('%s must not use generic REST output.', $metadata_key));
        }
    }
}

$vimeo = TSOL_Library_Media_Normalizer::from_url('https://vimeo.com/123456789/abcdef12');
$assert(!is_wp_error($vimeo), 'Vimeo URL was rejected.');
if (!is_wp_error($vimeo)) {
    $assert('vimeo' === $vimeo['provider'], 'Vimeo provider was not inferred.');
    $assert('123456789' === $vimeo['provider_id'], 'Vimeo ID was not inferred.');
    $assert('abcdef12' === $vimeo['privacy_hash'], 'Vimeo privacy hash was not inferred.');
}

$private_vimeo = TSOL_Library_Media_Normalizer::from_url('https://player.vimeo.com/video/987654321?h=private_123');
$assert(!is_wp_error($private_vimeo), 'Private player Vimeo URL was rejected.');
if (!is_wp_error($private_vimeo)) {
    $assert('987654321' === $private_vimeo['provider_id'], 'Player Vimeo ID was not inferred.');
    $assert('private_123' === $private_vimeo['privacy_hash'], 'Player Vimeo privacy hash was not inferred.');
}

$youtube = TSOL_Library_Media_Normalizer::from_url('https://youtu.be/AbCdEf123_-');
$assert(!is_wp_error($youtube) && 'youtube' === $youtube['provider'], 'YouTube provider was not inferred.');
$assert(!is_wp_error($youtube) && 'AbCdEf123_-' === $youtube['provider_id'], 'YouTube ID was not inferred.');

$direct = TSOL_Library_Media_Normalizer::from_url('https://media.example.test/video.mp4');
$assert(!is_wp_error($direct) && 'external' === $direct['provider'], 'Direct video URL was not inferred.');
$assert(!is_wp_error($direct) && hash('sha256', 'https://media.example.test/video.mp4') === $direct['provider_id'], 'Direct video URL did not receive a stable provider ID.');
$assert(!is_wp_error($direct) && 'https://media.example.test/video.mp4' === $direct['source_url'], 'Direct video source URL was not retained.');

$deduplicated = TSOL_Library_Media_Normalizer::extract_from_content(
    '<iframe src="https://player.vimeo.com/video/123456789?h=abcdef12"></iframe>'
    . '<a href="https://player.vimeo.com/video/123456789?h=abcdef12">Duplicate</a>'
);
$assert(1 === count($deduplicated), 'Repeated media URLs were not deduplicated.');

$escaped_embed = TSOL_Library_Media_Normalizer::extract_from_content(
    '{"url":"https:\\/\\/player.vimeo.com\\/video\\/123456789?h=abcdef12"}'
);
$assert(1 === count($escaped_embed), 'JSON-escaped media URL was not extracted.');

$audio = TSOL_Library_Media_Normalizer::from_url('https://media.example.test/audio.mp3');
$assert(!is_wp_error($audio) && 'audio' === $audio['kind'], 'Direct audio URL kind was not inferred.');

$unsupported = TSOL_Library_Media_Normalizer::from_url('https://example.test/not-media');
$assert(is_wp_error($unsupported), 'Unsupported URL was accepted as media.');

$document_attachment_ids = get_posts(array(
    'post_type' => 'attachment',
    'post_status' => 'inherit',
    'post_mime_type' => 'application/pdf',
    'numberposts' => 1,
    'fields' => 'ids',
));
$assert(!empty($document_attachment_ids), 'No PDF attachment is available for the media/resource boundary contract.');
if (!empty($document_attachment_ids)) {
    $document_url = wp_get_attachment_url((int) $document_attachment_ids[0]);
    $document_as_media = TSOL_Library_Media_Normalizer::from_url($document_url);
    $assert(is_wp_error($document_as_media), 'A WordPress document attachment was incorrectly accepted as playable media.');
}

$resources = TSOL_Library_Resource_Normalizer::extract_from_content(
    '<a href="https://files.example.test/session-workbook.pdf">Workbook</a>'
    . '<a href="https://files.example.test/session-workbook.pdf">Duplicate</a>'
    . '<a href="https://files.example.test/slides.pptx">Slides</a>'
    . '<h3>Mary\'s Newsletter Sign up: <a href="https://liberty-intl.org/contact/">https://liberty-intl.org/contact/</a></h3>'
    . '<p>Email <a href="mailto:mary@example.test">Mary</a></p>'
    . '<iframe src="https://tracking.example.test/embed"></iframe>'
);
$assert(4 === count($resources), 'Linked resources were not inferred and deduplicated.');
if (4 === count($resources)) {
    $assert('download' === $resources[0]['type'], 'Resource type was not inferred as a download.');
    $assert('Workbook' === $resources[0]['label'], 'Descriptive resource anchor text was not preserved.');
    $assert('link' === $resources[2]['type'], 'A non-file HTTP link was not inferred as a link resource.');
    $assert("Mary's Newsletter Sign up" === $resources[2]['label'], 'A URL-only anchor did not inherit its descriptive surrounding text.');
    $assert('mailto:mary@example.test' === $resources[3]['url'], 'A linked email address was not retained as a resource.');
    $assert(1 === (int) $resources[0]['position'] && 4 === (int) $resources[3]['position'], 'Resource ordering was not stable.');
}

$before = $database_counts();
$manifest = null;
try {
    $manifest = (new TSOL_Library_Normalization_Manifest())->build();
} catch (Throwable $exception) {
    $failures[] = $exception->getMessage();
}
$after = $database_counts();

$assert($before === $after, 'Manifest construction changed WordPress database row counts.');

if (is_array($manifest)) {
    $assert($manifest['expected_counts'] === $manifest['actual_counts'], 'Expected and actual manifest counts differ.');
    $assert($manifest['expected_media_summary'] === $manifest['media_summary'], 'Expected and actual media summaries differ.');
    $assert($manifest['expected_resource_summary'] === $manifest['resource_summary'], 'Expected and actual resource summaries differ.');
    $assert(142 === (int) $manifest['media_summary']['playable_pages'], 'Media scan did not cover 142 playable pages.');
    $assert(147 === (int) $manifest['media_summary']['media_assets'], 'Media scan did not find the expected 147 playable assets.');
    $assert(40 === (int) $manifest['resource_summary']['resources'], 'Resource scan did not find all 40 user-facing links and downloads.');
    $assert(0 === array_sum($manifest['writes']), 'Dry-run manifest reported a write.');
    $assert(1 === preg_match('/^[a-f0-9]{64}$/', $manifest['source_fingerprint']), 'Source fingerprint is not a SHA-256 value.');
    $assert(147 === count($manifest['source_entry_fingerprints']), 'Source fingerprint does not cover exactly 147 unique records.');
    $assert(121 === count($manifest['library_items']), 'Manifest does not contain 121 standalone Library Items.');
    $assert(6 === count($manifest['courses']), 'Manifest does not contain six courses.');
}

$normalized_item_count = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s AND pm.meta_value = %s WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft')",
    TSOL_Library_Content_Model::META_MIGRATION_VERSION,
    '20260809.4',
    TSOL_Library_Content_Model::ITEM_POST_TYPE
));
$assert(in_array($normalized_item_count, array(0, 142), true), 'Normalized Library Content count is neither the clean baseline nor the complete rehearsal target.');

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}

$report = array(
    'schema_version' => $manifest['schema_version'],
    'source_fingerprint' => $manifest['source_fingerprint'],
    'source_entries' => count($manifest['source_entry_fingerprints']),
    'actual_counts' => $manifest['actual_counts'],
    'expected_media_summary' => $manifest['expected_media_summary'],
    'media_summary' => $manifest['media_summary'],
    'expected_resource_summary' => $manifest['expected_resource_summary'],
    'resource_summary' => $manifest['resource_summary'],
    'database_rows_unchanged' => $before === $after,
    'normalized_library_items' => $normalized_item_count,
    'writes' => $manifest['writes'],
);

WP_CLI::line(wp_json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
WP_CLI::success('TSOL Library content normalization contract passed without database writes.');
