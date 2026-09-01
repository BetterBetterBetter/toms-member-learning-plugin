<?php
/** Contract for the locked, read-only Liberty LearnDash migration manifest. */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

// Liberty-only contract: the LearnDash import source exists only on the
// Liberty site. Skip cleanly where LearnDash is not installed (e.g. TSOL).
if (!defined('LEARNDASH_VERSION') && !class_exists('SFWD_LMS')) {
    WP_CLI::success('Skipped: LearnDash is not active (Liberty-only import contract).');
    return;
}

$preview = (new Liberty_Classroom_LearnDash_Import())->preview();
$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert('draft' === ($preview['target_status'] ?? ''), 'Migration targets are not draft-first.');
$assert(39 === (int) ($preview['expected']['courses'] ?? 0), 'The locked 39-course inventory changed.');
$assert(1227 === (int) ($preview['expected']['content'] ?? 0), 'The locked 1,227-lesson inventory changed.');
$assert(14 === (int) ($preview['expected']['speakers'] ?? 0), 'The locked 14-speaker inventory changed.');
$assert(0 === (int) ($preview['expected']['series'] ?? -1), 'The migration unexpectedly creates Series.');
$assert(5 === (int) ($preview['expected']['collections'] ?? 0), 'The five editorial Collections changed.');
$assert(3 === (int) ($preview['expected']['access_groups'] ?? 0), 'The three access tiers changed.');
$assert(1225 === (int) ($preview['media']['items_with_video'] ?? 0), 'The video inventory changed.');
$assert(1223 === (int) ($preview['media']['items_with_audio'] ?? 0), 'The normalized audio inventory changed.');
$assert(2 === (int) ($preview['media']['resource_only'] ?? 0), 'The two download-only lessons changed.');
$assert(35 === (int) ($preview['access']['group_course_counts']['basic'] ?? 0), 'Basic no longer maps to 35 courses.');
$assert(36 === (int) ($preview['access']['group_course_counts']['basic-plus'] ?? 0), 'Basic Plus no longer maps to 36 courses.');
$assert(39 === (int) ($preview['access']['group_course_counts']['master'] ?? 0), 'Master no longer maps to all 39 courses.');
$assert(93 === (int) ($preview['excluded_published_lessons']['count'] ?? 0), 'The excluded orphan/legacy lesson inventory changed.');
$assert(0 === (int) ($preview['source_mutations'] ?? -1), 'The preview permits LearnDash mutations.');
$assert(0 === (int) ($preview['memberpress_mutations'] ?? -1), 'The preview permits MemberPress mutations.');

if ($failures) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}

WP_CLI::line(wp_json_encode($preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
WP_CLI::success('Liberty LearnDash migration manifest is locked and non-destructive.');
