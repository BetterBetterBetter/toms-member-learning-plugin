<?php
/**
 * Versioned, deterministic source-to-target normalization specification.
 *
 * This class belongs to the removable migration module. It is not required by
 * the Library frontend or its permanent WordPress content model.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Normalization_Spec {

    const VERSION = '20260826.1';
    const SOURCE_FINGERPRINT = '5c90b0e458ed980ac8b01a664e9c5490007841391b3fdd1ef3d20e28a2e60638';
    const ARCHIVE_RULE_ID = 99915;
    const MASTERCLASS_HUB_ID = 103647;
    const PILOT_KEY = 'against-the-machine-masterclass';

    public static function expected_counts() {
        return array(
            'source_archive_posts' => 124,
            'source_masterclass_roots' => 5,
            'source_masterclass_lessons' => 20,
            'courses' => 6,
            'sections' => 7,
            'lessons' => 23,
            'library_items' => 121,
            'playable_pages' => 144,
            'collection_roots' => 7,
            'collection_terms' => 15,
            'numbered_sessions' => 96,
            'live_events' => 18,
            'unconference_2025' => 3,
            'orientations' => 2,
            'limitless_book_club' => 1,
            'member_calls' => 1,
        );
    }

    public static function expected_media_summary() {
        return array(
            'playable_pages' => 144,
            'media_assets' => 149,
            'pages_with_multiple_assets' => 3,
            'private_reference_count' => 145,
            'providers' => array(
                'vimeo' => 145,
                'wordpress' => 1,
                'youtube' => 3,
            ),
        );
    }

    public static function expected_resource_summary() {
        return array(
            'pages_with_resources' => 31,
            'resources' => 40,
            'types' => array(
                'download' => 30,
                'link' => 10,
            ),
        );
    }

    public static function freedom_sources() {
        return array(103836, 103867, 103891);
    }

    public static function archive_membership_ids() {
        return array(
            99907,
            100048,
            100371,
            100372,
            100373,
            101221,
            101933,
            102090,
            102091,
            102173,
            102285,
            102298,
            102321,
            102382,
            102384,
            102385,
            102412,
            102453,
            102465,
            102569,
            102591,
            102637,
            102686,
            102688,
            103506,
            103599,
        );
    }

    public static function archive_special_sources() {
        return array(
            'unconference-2025' => array(103550, 103552, 103475),
            'new-member-orientation' => array(103832, 102616),
            'limitless-book-club' => array(103259),
            'member-calls' => array(103665),
        );
    }

    public static function collection_terms() {
        return array(
            array('slug' => 'masterclasses', 'name' => 'Masterclasses', 'parent' => ''),
            array('slug' => 'tsol-sessions', 'name' => 'TSOL Sessions', 'parent' => ''),
            array('slug' => 'tsol-sessions-2022', 'name' => '2022', 'parent' => 'tsol-sessions'),
            array('slug' => 'tsol-sessions-2023', 'name' => '2023', 'parent' => 'tsol-sessions'),
            array('slug' => 'tsol-sessions-2024', 'name' => '2024', 'parent' => 'tsol-sessions'),
            array('slug' => 'tsol-sessions-2025', 'name' => '2025', 'parent' => 'tsol-sessions'),
            array('slug' => 'tsol-sessions-2026', 'name' => '2026', 'parent' => 'tsol-sessions'),
            array('slug' => 'live-events', 'name' => 'Live Events', 'parent' => ''),
            array('slug' => 'live-events-2022', 'name' => 'Live Event 2022', 'parent' => 'live-events'),
            array('slug' => 'live-events-2023', 'name' => 'Live Event 2023', 'parent' => 'live-events'),
            array('slug' => 'live-events-2024', 'name' => 'Live Event 2024', 'parent' => 'live-events'),
            array('slug' => 'unconference-2025', 'name' => 'Unconference 2025', 'parent' => ''),
            array('slug' => 'new-member-orientation', 'name' => 'New Member Orientation', 'parent' => ''),
            array('slug' => 'limitless-book-club', 'name' => 'Limitless Book Club', 'parent' => ''),
            array('slug' => 'member-calls', 'name' => 'Member Calls', 'parent' => ''),
        );
    }

    public static function courses() {
        return array(
            array(
                'key' => 'tax-strategy-intensive',
                'title' => 'Tax Strategy Intensive',
                'slug' => 'tax-strategy-intensive',
                'source_course_id' => 103668,
                'collection' => 'masterclasses',
                'course_rule_ids' => array(103656, 103785),
                'lesson_rule_ids' => array(103656, 103703),
                'sections' => array(
                    array(
                        'key' => 'sessions',
                        'title' => 'Sessions',
                        'lessons' => array(
                            array('source_id' => 103666, 'title' => 'Session 1'),
                            array('source_id' => 103678, 'title' => 'Session 2'),
                            array('source_id' => 103683, 'title' => 'Session 3'),
                            array('source_id' => 103687, 'title' => 'Session 4'),
                        ),
                    ),
                ),
            ),
            array(
                'key' => 'the-ai-advantage',
                'title' => 'The AI Advantage',
                'slug' => 'the-ai-advantage',
                'source_course_id' => 103694,
                'collection' => 'masterclasses',
                'course_rule_ids' => array(103656, 103784),
                'lesson_rule_ids' => array(103656, 103695),
                'sections' => array(
                    array(
                        'key' => 'sessions',
                        'title' => 'Sessions',
                        'lessons' => array(
                            array('source_id' => 103707, 'title' => 'Session 1'),
                            array('source_id' => 103736, 'title' => 'Session 2'),
                            array('source_id' => 103750, 'title' => 'Session 3'),
                            array('source_id' => 103756, 'title' => 'Session 4'),
                        ),
                    ),
                ),
            ),
            array(
                'key' => 'social-media-masterclass',
                'title' => 'Social Media',
                'slug' => 'social-media-masterclass',
                'source_course_id' => 103774,
                'collection' => 'masterclasses',
                'course_rule_ids' => array(103656),
                'lesson_rule_ids' => array(103656, 103781),
                'sections' => array(
                    array(
                        'key' => 'sessions',
                        'title' => 'Sessions',
                        'lessons' => array(
                            array('source_id' => 103790, 'title' => 'Session 1'),
                            array('source_id' => 103793, 'title' => 'Session 2'),
                            array('source_id' => 103799, 'title' => 'Session 3'),
                            array('source_id' => 103805, 'title' => 'Session 4'),
                        ),
                    ),
                ),
            ),
            array(
                'key' => 'against-the-machine-masterclass',
                'title' => 'Against the Machine',
                'slug' => 'against-the-machine-masterclass',
                'source_course_id' => 103823,
                'collection' => 'masterclasses',
                'course_rule_ids' => array(103656, 103813),
                'lesson_rule_ids' => array(103656, 103814),
                'sections' => array(
                    array(
                        'key' => 'sessions',
                        'title' => 'Sessions',
                        'lessons' => array(
                            array('source_id' => 103825, 'title' => 'Session 1'),
                            array('source_id' => 103837, 'title' => 'Session 2'),
                            array('source_id' => 103849, 'title' => 'Session 3'),
                            array('source_id' => 103851, 'title' => 'Session 4'),
                        ),
                    ),
                ),
            ),
            array(
                'key' => 'the-100-medicine-cabinet',
                'title' => 'The $100 Medicine Cabinet',
                'slug' => 'the-100-medicine-cabinet',
                'source_course_id' => 103879,
                'collection' => 'masterclasses',
                'course_rule_ids' => array(103656, 103884),
                'lesson_rule_ids' => array(103656, 103881),
                'sections' => array(
                    array(
                        'key' => 'sessions',
                        'title' => 'Sessions',
                        'lessons' => array(
                            array('source_id' => 103898, 'title' => 'Session 1'),
                            array('source_id' => 103900, 'title' => 'Session 2'),
                            array('source_id' => 103907, 'title' => 'Session 3'),
                            array('source_id' => 103917, 'title' => 'Session 4'),
                        ),
                    ),
                ),
            ),
            array(
                'key' => 'freedom-os',
                'title' => 'Freedom OS',
                'slug' => 'freedom-os',
                'source_course_id' => 0,
                'collection' => '',
                'course_rule_ids' => array(self::ARCHIVE_RULE_ID),
                'lesson_rule_ids' => array(self::ARCHIVE_RULE_ID),
                'sections' => array(
                    array(
                        'key' => 'switching-to-linux',
                        'title' => 'Switching to Linux',
                        'lessons' => array(
                            array('source_id' => 103836, 'title' => 'How to Switch to Linux — Part 1'),
                            array('source_id' => 103867, 'title' => 'How to Switch to Linux — Part 2'),
                        ),
                    ),
                    array(
                        'key' => 'self-hosting',
                        'title' => 'Self Hosting',
                        'lessons' => array(
                            array('source_id' => 103891, 'title' => 'Self Hosting'),
                        ),
                    ),
                ),
            ),
        );
    }
}
