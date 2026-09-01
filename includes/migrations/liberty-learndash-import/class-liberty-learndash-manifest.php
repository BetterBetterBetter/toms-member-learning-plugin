<?php
/** Read-only, evidence-locked LearnDash source manifest for Liberty Classroom. */

if (!defined('ABSPATH')) {
    exit;
}

class Liberty_Classroom_LearnDash_Manifest {

    const EXPECTED_COURSES = 39;
    const EXPECTED_LESSONS = 1227;
    const EXPECTED_SPEAKERS = 14;

    public function build() {
        $this->assert_dependencies();
        $collections = $this->collection_specs();
        $collection_by_course = $this->collection_index($collections);
        $source_courses = get_posts(array(
            'post_type' => 'sfwd-courses',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'orderby' => array('menu_order' => 'ASC', 'title' => 'ASC', 'ID' => 'ASC'),
            'suppress_filters' => true,
        ));
        if (self::EXPECTED_COURSES !== count($source_courses)) {
            throw new RuntimeException(sprintf('Expected %d published LearnDash courses; found %d.', self::EXPECTED_COURSES, count($source_courses)));
        }

        $courses = array();
        $speaker_terms = array();
        $live_lesson_ids = array();
        $media = array('items_with_video' => 0, 'items_with_audio' => 0, 'video_primary' => 0, 'audio_primary' => 0, 'resource_only' => 0, 'resources' => 0);
        foreach ($source_courses as $course_index => $course) {
            if (!isset($collection_by_course[$course->post_name])) {
                throw new RuntimeException(sprintf('Published course %s is missing from the locked Collection map.', $course->post_title));
            }
            $speaker_slugs = $this->course_speakers($course, $speaker_terms);
            $lessons = array();
            $step_ids = array_values(array_map('intval', learndash_get_course_steps($course->ID, array('sfwd-lessons'))));
            foreach ($step_ids as $lesson_index => $lesson_id) {
                $lesson = get_post($lesson_id);
                if (!$lesson instanceof WP_Post || 'sfwd-lessons' !== $lesson->post_type || 'publish' !== $lesson->post_status) {
                    throw new RuntimeException(sprintf('Course %s contains an unavailable lesson %d.', $course->post_title, $lesson_id));
                }
                if (isset($live_lesson_ids[$lesson_id])) {
                    throw new RuntimeException(sprintf('Lesson %d appears in more than one published course tree.', $lesson_id));
                }
                $live_lesson_ids[$lesson_id] = true;
                $assets = $this->ordered_media_assets($lesson->post_content);
                $resources = MemberLibrary_Content_Model::sanitize_resources(
                    MemberLibrary_Resource_Normalizer::extract_from_content($lesson->post_content)
                );
                $resource_only = empty($assets);
                if ($resource_only && (!in_array($lesson_id, array(25008, 25011), true) || empty($resources))) {
                    throw new RuntimeException(sprintf('Lesson %d has neither supported media nor an approved download-only resource.', $lesson_id));
                }
                $kinds = array_column($assets, 'kind');
                $media['items_with_video'] += in_array('video', $kinds, true) ? 1 : 0;
                $media['items_with_audio'] += in_array('audio', $kinds, true) ? 1 : 0;
                if ($resource_only) {
                    $media['resource_only']++;
                } else {
                    $media[(string) $assets[0]['kind'] . '_primary']++;
                }
                $media['resources'] += count($resources);
                $entry = $this->post_entry($lesson, 'liberty-learndash-lesson-' . $lesson_id);
                $entry['position'] = $lesson_index + 1;
                $entry['media_assets'] = $assets;
                $entry['resources'] = $resources;
                $entry['resource_only'] = $resource_only;
                $entry['source_fingerprint'] = $this->fingerprint($entry);
                $lessons[] = $entry;
            }

            $entry = $this->post_entry($course, 'liberty-learndash-course-' . (int) $course->ID);
            $entry['position'] = $course_index + 1;
            $entry['source_menu_order'] = (int) $course->menu_order;
            $entry['speaker_slugs'] = $speaker_slugs;
            $entry['collection_slug'] = $collection_by_course[$course->post_name];
            $entry['lessons'] = $lessons;
            $entry['source_fingerprint'] = $this->fingerprint($entry);
            $courses[] = $entry;
        }

        if (self::EXPECTED_LESSONS !== count($live_lesson_ids)) {
            throw new RuntimeException(sprintf('Expected %d live lessons; found %d.', self::EXPECTED_LESSONS, count($live_lesson_ids)));
        }
        if (self::EXPECTED_SPEAKERS !== count($speaker_terms)) {
            throw new RuntimeException(sprintf('Expected %d used Speaker tags; found %d.', self::EXPECTED_SPEAKERS, count($speaker_terms)));
        }
        if (count($collection_by_course) !== count($courses)) {
            throw new RuntimeException('The Collection map does not cover exactly the published courses.');
        }

        ksort($speaker_terms, SORT_STRING);
        $all_lessons = array_map('intval', get_posts(array(
            'post_type' => 'sfwd-lessons',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        )));
        $excluded = array_values(array_diff($all_lessons, array_map('intval', array_keys($live_lesson_ids))));
        sort($excluded, SORT_NUMERIC);

        $manifest = array(
            'courses' => $courses,
            'lesson_count' => count($live_lesson_ids),
            'speakers' => $speaker_terms,
            'collections' => $collections,
            'access' => $this->access_manifest($courses),
            'media_summary' => $media,
            'excluded_lessons' => array('count' => count($excluded), 'ids' => $excluded),
        );
        $manifest['source_fingerprint'] = hash('sha256', serialize($manifest));
        return $manifest;
    }

    private function post_entry(WP_Post $post, $migration_key) {
        return array(
            'source_id' => (int) $post->ID,
            'source_type' => (string) $post->post_type,
            'migration_key' => sanitize_key($migration_key),
            'title' => (string) $post->post_title,
            'slug' => (string) $post->post_name,
            'post_content' => (string) $post->post_content,
            'post_excerpt' => (string) $post->post_excerpt,
            'post_author' => (int) $post->post_author,
            'post_date' => (string) $post->post_date,
            'post_date_gmt' => (string) $post->post_date_gmt,
            'post_modified_gmt' => (string) $post->post_modified_gmt,
            'thumbnail_id' => (int) get_post_thumbnail_id($post->ID),
        );
    }

    private function course_speakers(WP_Post $course, &$speaker_terms) {
        $terms = wp_get_post_terms($course->ID, 'ld_course_tag');
        if (is_wp_error($terms) || empty($terms)) {
            throw new RuntimeException(sprintf('Course %s has no Speaker tag.', $course->post_title));
        }
        $speaker_slugs = array();
        foreach ($terms as $term) {
            if (!in_array($term->slug, $this->speaker_slugs(), true)) {
                throw new RuntimeException(sprintf('Course tag %s is not an approved Liberty Speaker.', $term->slug));
            }
            $speaker_slugs[] = $term->slug;
            $speaker_terms[$term->slug] = array(
                'source_id' => (int) $term->term_id,
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'migration_key' => 'liberty-speaker-' . sanitize_key($term->slug),
            );
        }
        return array_values(array_unique($speaker_slugs));
    }

    private function ordered_media_assets($content) {
        $assets = MemberLibrary_Content_Model::sanitize_media_assets(
            MemberLibrary_Media_Normalizer::extract_from_content($content)
        );
        usort($assets, static function ($left, $right) {
            $left_weight = 'video' === (string) ($left['kind'] ?? '') ? 0 : 1;
            $right_weight = 'video' === (string) ($right['kind'] ?? '') ? 0 : 1;
            return $left_weight === $right_weight
                ? ((int) $left['position'] <=> (int) $right['position'])
                : ($left_weight <=> $right_weight);
        });
        foreach ($assets as $index => &$asset) {
            $asset['key'] = 'asset-' . ($index + 1);
            $asset['position'] = $index + 1;
            if ('' === (string) ($asset['label'] ?? '')) {
                $asset['label'] = 'video' === $asset['kind'] ? 'Video' : 'Audio';
            }
        }
        unset($asset);
        return $assets;
    }

    private function access_manifest($courses) {
        $published_course_ids = array_map(static function ($course) {
            return (int) $course['source_id'];
        }, $courses);
        sort($published_course_ids, SORT_NUMERIC);
        $source_groups = get_posts(array(
            'post_type' => 'groups',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'suppress_filters' => true,
        ));
        $products = get_posts(array(
            'post_type' => 'memberpressproduct',
            'post_status' => array('publish', 'draft'),
            'posts_per_page' => -1,
            'suppress_filters' => true,
        ));
        $groups = array();
        foreach (array(
            'basic' => array('name' => 'Basic', 'expected' => 35),
            'basic-plus' => array('name' => 'Basic Plus', 'expected' => 36),
            'master' => array('name' => 'Master', 'expected' => 39),
        ) as $group_id => $spec) {
            $matches = array_values(array_filter($source_groups, static function ($group) use ($spec) {
                return $group->post_title === $spec['name'];
            }));
            if (1 !== count($matches)) {
                throw new RuntimeException(sprintf('Expected exactly one LearnDash group named %s.', $spec['name']));
            }
            $source_group_id = (int) $matches[0]->ID;
            $source_course_ids = array_values(array_unique(array_map('intval', learndash_group_enrolled_courses($source_group_id))));
            sort($source_course_ids, SORT_NUMERIC);
            if ($spec['expected'] !== count($source_course_ids) || !empty(array_diff($source_course_ids, $published_course_ids))) {
                throw new RuntimeException(sprintf('LearnDash group %s does not match its locked %d-Course matrix.', $spec['name'], $spec['expected']));
            }
            $matching_products = array_values(array_filter($products, static function ($product) use ($source_group_id) {
                $assigned = array_map('intval', (array) get_post_meta($product->ID, '_learndash_memberpress_groups', true));
                return 'publish' === $product->post_status && in_array($source_group_id, $assigned, true);
            }));
            if (1 !== count($matching_products)) {
                throw new RuntimeException(sprintf('LearnDash group %s must map to exactly one published MemberPress product.', $spec['name']));
            }
            $groups[$group_id] = array(
                'id' => $group_id,
                'name' => $spec['name'],
                'description' => sprintf('Migrated from the Liberty Classroom %s LearnDash access tier.', $spec['name']),
                'source_group_id' => $source_group_id,
                'source_course_ids' => $source_course_ids,
                'membership_id' => (int) $matching_products[0]->ID,
                'membership_slug' => (string) $matching_products[0]->post_name,
            );
        }
        $assigned_ids = array_map(static function ($group) {
            return (int) $group['membership_id'];
        }, $groups);
        $unassigned = array();
        foreach ($products as $product) {
            if (!in_array((int) $product->ID, $assigned_ids, true)) {
                $unassigned[] = array(
                    'id' => (int) $product->ID,
                    'title' => (string) $product->post_title,
                    'status' => (string) $product->post_status,
                    'slug' => (string) $product->post_name,
                );
            }
        }
        return array('groups' => $groups, 'unassigned_memberships' => $unassigned);
    }

    private function collection_index($collections) {
        $index = array();
        foreach ($collections as $collection_slug => $collection) {
            foreach ($collection['course_slugs'] as $course_slug) {
                if (isset($index[$course_slug])) {
                    throw new RuntimeException(sprintf('Course slug %s appears in more than one Collection.', $course_slug));
                }
                $index[$course_slug] = $collection_slug;
            }
        }
        return $index;
    }

    private function fingerprint($entry) {
        unset($entry['source_fingerprint']);
        return hash('sha256', serialize($entry));
    }

    private function assert_dependencies() {
        if (!function_exists('learndash_get_course_steps') || !function_exists('learndash_group_enrolled_courses')) {
            throw new RuntimeException('LearnDash is unavailable; the Liberty migration fails closed.');
        }
        if (!class_exists('MeprRule') || !post_type_exists('memberpressproduct')) {
            throw new RuntimeException('MemberPress is unavailable; the Liberty migration fails closed.');
        }
    }

    private function speaker_slugs() {
        return array(
            'bradley-birzer', 'brion-mcclanahan', 'dedra-mcdonald-birzer', 'elizabeth-kantor',
            'g-p-manish', 'gerard-casey', 'hunt-tooley', 'jason-jewell', 'jeffrey-m-herbener',
            'jonathan-bean', 'kevin-r-c-gutzman', 'michael-rectenwald', 'robert-murphy', 'tom-woods',
        );
    }

    private function collection_specs() {
        return array(
            'economics' => array(
                'name' => 'Economics',
                'description' => 'Economic history, Austrian economics, economic thought, and critiques of influential systems.',
                'course_slugs' => array(
                    'american-economic-history-part-i', 'american-economic-history-part-ii', 'american-economic-history-part-iii',
                    'austrian-economics-step-by-step',
                    'history-of-economic-thought-part-i-classical-economics-and-the-marginal-revolution',
                    'history-of-economic-thought-part-ii-20th-century-economics',
                    'john-maynard-keynes-his-system-and-its-fallacies', 'whats-wrong-with-textbook-economics',
                ),
            ),
            'american-history-government' => array(
                'name' => 'American History & Government',
                'description' => 'The American founding, Constitution, presidency, territorial growth, and national history.',
                'course_slugs' => array(
                    'how-alexander-hamilton-screwed-up-america', 'introduction-to-government',
                    'the-10-worst-and-10-best-presidents', 'the-american-revolution-a-constitutional-conflict',
                    'the-early-republic-1807-1820', 'the-thomas-jefferson-nobody-knows',
                    'trails-west-how-freedom-settled-the-west', 'u-s-constitutional-history',
                    'u-s-history-to-1877', 'u-s-history-since-1877',
                ),
            ),
            'western-civilization-world-history' => array(
                'name' => 'Western Civilization & World History',
                'description' => 'Civilizations, nations, institutions, and historical developments across the Western tradition and beyond.',
                'course_slugs' => array(
                    'colonial-latin-american-history', 'crimes-of-communism', 'history-of-england',
                    'the-history-and-heritage-of-western-and-american-civilization',
                    'western-civilization-to-1492', 'western-civilization-from-1493',
                    'western-civilization-to-1500', 'western-civilization-since-1500',
                ),
            ),
            'political-thought-ideas' => array(
                'name' => 'Political Thought & Ideas',
                'description' => 'Political philosophy, logic, intellectual movements, and competing ideas about liberty and society.',
                'course_slugs' => array(
                    'a-history-of-free-thought', 'critical-theory-cultural-studies-and-postmodern-theory',
                    'freedoms-progress-the-history-of-political-thought-part-i', 'freedoms-progress-the-history',
                    'introduction-to-logic', 'the-great-reset', 'the-history-of-conservatism-and-libertarianism',
                ),
            ),
            'literature-myth-science-fiction' => array(
                'name' => 'Literature, Myth & Science Fiction',
                'description' => 'Literature and imaginative traditions examined through Western civilization and liberty.',
                'course_slugs' => array(
                    'introduction-to-american-literature-our-best-short-stories',
                    'libertarianism-and-science-fiction-the-golden-age-from-bradbury-to-roddenberry',
                    'little-houses-of-liberty-laura-ingalls-wilders-literary-genius',
                    'mythology-and-western-civilization-from-plato-to-tolkien',
                    'science-fiction-liberty-and-dystopia-part-i', 'science-fiction-liberty-and-dystopia-part-ii',
                ),
            ),
        );
    }
}
