<?php
/**
 * Builds and validates the read-only TSOL Library normalization manifest.
 *
 * Manifest construction is deliberately read-only. The migration module does
 * not expose a write path during the discovery and contract-testing phase.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Normalization_Manifest {

    private $errors = array();
    private $warnings = array();
    private $source_fingerprints = array();

    public function build() {
        $this->assert_dependencies();

        $archive_posts = get_posts(array(
            'post_type' => 'mpcs-course',
            'post_status' => 'publish',
            'numberposts' => -1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
        ));

        $items = $this->build_library_items($archive_posts);
        $courses = $this->build_courses();
        $collections = TSOL_Library_Normalization_Spec::collection_terms();
        $expected = TSOL_Library_Normalization_Spec::expected_counts();

        $actual = array(
            'source_archive_posts' => count($archive_posts),
            'source_masterclass_roots' => 5,
            'source_masterclass_lessons' => $this->count_course_lessons($courses) - count(TSOL_Library_Normalization_Spec::freedom_sources()),
            'courses' => count($courses),
            'sections' => $this->count_course_sections($courses),
            'lessons' => $this->count_course_lessons($courses),
            'library_items' => count($items),
            'playable_pages' => count($items) + $this->count_course_lessons($courses),
            'collection_roots' => count(array_filter($collections, static function ($term) {
                return empty($term['parent']);
            })),
            'collection_terms' => count($collections),
            'numbered_sessions' => $this->count_items_by_kind($items, 'numbered_session'),
            'live_events' => $this->count_items_by_kind($items, 'live_event'),
            'unconference_2025' => $this->count_items_by_kind($items, 'unconference_2025'),
            'orientations' => $this->count_items_by_kind($items, 'orientation'),
            'limitless_book_club' => $this->count_items_by_kind($items, 'limitless_book_club'),
            'member_calls' => $this->count_items_by_kind($items, 'member_call'),
        );

        foreach ($expected as $key => $expected_value) {
            if (!array_key_exists($key, $actual) || (int) $actual[$key] !== (int) $expected_value) {
                $this->errors[] = sprintf(
                    'Count mismatch for %s: expected %d, found %d.',
                    $key,
                    $expected_value,
                    isset($actual[$key]) ? $actual[$key] : 0
                );
            }
        }

        $source_ids = array_merge(
            array_column($items, 'source_id'),
            $this->course_source_ids($courses)
        );
        if (count($source_ids) !== count(array_unique($source_ids))) {
            $this->errors[] = 'A legacy source post is mapped more than once.';
        }

        sort($this->source_fingerprints, SORT_STRING);
        $source_fingerprint = hash('sha256', wp_json_encode($this->source_fingerprints));
        if (TSOL_Library_Normalization_Spec::SOURCE_FINGERPRINT !== $source_fingerprint) {
            $this->errors[] = sprintf(
                'Source fingerprint changed: expected %s, found %s.',
                TSOL_Library_Normalization_Spec::SOURCE_FINGERPRINT,
                $source_fingerprint
            );
        }
        $media_summary = $this->summarize_media($items, $courses);
        $expected_media_summary = TSOL_Library_Normalization_Spec::expected_media_summary();
        if ($expected_media_summary !== $media_summary) {
            $this->errors[] = sprintf(
                'Media summary changed: expected %s, found %s.',
                wp_json_encode($expected_media_summary),
                wp_json_encode($media_summary)
            );
        }
        $resource_summary = $this->summarize_resources($items, $courses);
        $expected_resource_summary = TSOL_Library_Normalization_Spec::expected_resource_summary();
        if ($expected_resource_summary !== $resource_summary) {
            $this->errors[] = sprintf(
                'Resource summary changed: expected %s, found %s.',
                wp_json_encode($expected_resource_summary),
                wp_json_encode($resource_summary)
            );
        }

        $this->warnings[] = 'Topic and speaker assignments remain unpopulated until editorial review.';
        $this->warnings[] = 'The five masterclass section titles remain the approved temporary label “Sessions”.';
        $this->warnings[] = 'Write mode is restricted to guarded draft-only rehearsal scopes on the exact working local host.';

        if (!empty($this->errors)) {
            throw new RuntimeException(implode("\n", array_values(array_unique($this->errors))));
        }

        return array(
            'schema_version' => TSOL_Library_Normalization_Spec::VERSION,
            'mode' => 'dry-run',
            'generated_at' => gmdate('c'),
            'source_fingerprint' => $source_fingerprint,
            'source_entry_fingerprints' => $this->source_fingerprints,
            'expected_counts' => $expected,
            'actual_counts' => $actual,
            'expected_media_summary' => $expected_media_summary,
            'media_summary' => $media_summary,
            'expected_resource_summary' => $expected_resource_summary,
            'resource_summary' => $resource_summary,
            'collections' => $collections,
            'courses' => $courses,
            'library_items' => $items,
            'warnings' => array_values(array_unique($this->warnings)),
            'writes' => array(
                'posts' => 0,
                'terms' => 0,
                'post_meta' => 0,
                'memberpress_rules' => 0,
            ),
        );
    }

    private function build_library_items($archive_posts) {
        $items = array();
        $freedom_ids = TSOL_Library_Normalization_Spec::freedom_sources();
        $special_sources = TSOL_Library_Normalization_Spec::archive_special_sources();
        $special_lookup = array();
        foreach ($special_sources as $collection => $source_ids) {
            foreach ($source_ids as $position => $source_id) {
                $special_lookup[(int) $source_id] = array(
                    'collection' => $collection,
                    'position' => $position + 1,
                );
            }
        }

        $live_event_positions = array();
        $session_numbers = array();

        foreach ($archive_posts as $post) {
            $this->assert_rule_signature($post, array(TSOL_Library_Normalization_Spec::ARCHIVE_RULE_ID));

            if (in_array((int) $post->ID, $freedom_ids, true)) {
                continue;
            }

            $this->remember_fingerprint($post);

            $classification = null;
            if (preg_match('/^Session\s+(\d+):/u', html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5), $matches)) {
                $session_number = (int) $matches[1];
                if (isset($session_numbers[$session_number])) {
                    $this->errors[] = sprintf('Duplicate numbered session %d.', $session_number);
                }
                $session_numbers[$session_number] = (int) $post->ID;
                $year = (int) mysql2date('Y', $post->post_date, false);
                $classification = array(
                    'kind' => 'numbered_session',
                    'collection' => 'tsol-sessions-' . $year,
                    'position' => $session_number,
                    'current' => false,
                );
            } elseif (0 === strpos($post->post_title, 'Live Event:')) {
                $year = (int) mysql2date('Y', $post->post_date, false);
                $collection = 'live-events-' . $year;
                $live_event_positions[$collection] = isset($live_event_positions[$collection]) ? $live_event_positions[$collection] + 1 : 1;
                $classification = array(
                    'kind' => 'live_event',
                    'collection' => $collection,
                    'position' => $live_event_positions[$collection],
                    'current' => false,
                );
            } elseif (isset($special_lookup[(int) $post->ID])) {
                $special = $special_lookup[(int) $post->ID];
                $kind = str_replace('-', '_', $special['collection']);
                if ('new_member_orientation' === $kind) {
                    $kind = 'orientation';
                } elseif ('member_calls' === $kind) {
                    $kind = 'member_call';
                }
                $classification = array(
                    'kind' => $kind,
                    'collection' => $special['collection'],
                    'position' => $special['position'],
                    'current' => 103832 === (int) $post->ID,
                );
            }

            if (null === $classification) {
                $this->errors[] = sprintf('Unclassified archive source post %d.', $post->ID);
                continue;
            }

            $items[] = array_merge(array(
                'migration_key' => 'library-item-' . (int) $post->ID,
                'source_id' => (int) $post->ID,
                'source_type' => (string) $post->post_type,
                'authorization_post_id' => (int) $post->ID,
                'access_rule_ids' => array(TSOL_Library_Normalization_Spec::ARCHIVE_RULE_ID),
                'target_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
                'title' => html_entity_decode($post->post_title, ENT_QUOTES | ENT_HTML5),
                'slug' => (string) $post->post_name,
                'status' => 'draft',
                'source_modified_gmt' => (string) $post->post_modified_gmt,
                'source_fingerprint' => $this->post_fingerprint($post),
                'media' => $this->post_media_summary($post),
                'resources' => $this->post_resource_summary($post),
            ), $classification);
        }

        ksort($session_numbers, SORT_NUMERIC);
        if (array_keys($session_numbers) !== range(1, 96)) {
            $this->errors[] = 'Numbered sessions are not the complete range 1 through 96.';
        }

        usort($items, static function ($left, $right) {
            $collection = strcmp($left['collection'], $right['collection']);
            if (0 !== $collection) {
                return $collection;
            }
            $position = $left['position'] <=> $right['position'];
            return 0 !== $position ? $position : ($left['source_id'] <=> $right['source_id']);
        });

        return $items;
    }

    private function build_courses() {
        $courses = array();

        foreach (TSOL_Library_Normalization_Spec::courses() as $course_spec) {
            $source_course = null;
            if (!empty($course_spec['source_course_id'])) {
                $source_course = $this->assert_source_post($course_spec['source_course_id'], 'page', 'publish');
                $this->assert_rule_signature($source_course, $course_spec['course_rule_ids']);
                $this->remember_fingerprint($source_course);
            }

            $course = array(
                'migration_key' => 'course-' . $course_spec['key'],
                'key' => $course_spec['key'],
                'title' => $course_spec['title'],
                'slug' => $course_spec['slug'],
                'target_type' => 'mpcs-course',
                'status' => 'draft',
                'source_course_id' => (int) $course_spec['source_course_id'],
                'collection' => $course_spec['collection'],
                'access_rule_ids' => $course_spec['course_rule_ids'],
                'source_fingerprint' => $source_course ? $this->post_fingerprint($source_course) : '',
                'sections' => array(),
            );

            $published_child_ids = array();
            if ($source_course) {
                $published_child_ids = get_posts(array(
                    'post_type' => 'page',
                    'post_parent' => $source_course->ID,
                    'post_status' => 'publish',
                    'numberposts' => -1,
                    'fields' => 'ids',
                    'orderby' => 'ID',
                    'order' => 'ASC',
                    'suppress_filters' => true,
                ));
                $published_child_ids = array_map('intval', $published_child_ids);
                sort($published_child_ids, SORT_NUMERIC);
            }

            $specified_child_ids = array();
            foreach ($course_spec['sections'] as $section_position => $section_spec) {
                $section = array(
                    'migration_key' => 'section-' . $course_spec['key'] . '-' . $section_spec['key'],
                    'key' => $section_spec['key'],
                    'title' => $section_spec['title'],
                    'position' => $section_position + 1,
                    'lessons' => array(),
                );

                foreach ($section_spec['lessons'] as $lesson_position => $lesson_spec) {
                    $source_lesson = $this->assert_source_post($lesson_spec['source_id'], $source_course ? 'page' : 'mpcs-course', 'publish');
                    if ($source_course && (int) $source_lesson->post_parent !== (int) $source_course->ID) {
                        $this->errors[] = sprintf('Masterclass lesson %d no longer belongs to source course %d.', $source_lesson->ID, $source_course->ID);
                    }
                    $this->assert_rule_signature($source_lesson, $course_spec['lesson_rule_ids']);
                    $this->remember_fingerprint($source_lesson);
                    $specified_child_ids[] = (int) $source_lesson->ID;

                    $section['lessons'][] = array(
                        'migration_key' => 'lesson-' . (int) $source_lesson->ID,
                        'source_id' => (int) $source_lesson->ID,
                        'source_type' => (string) $source_lesson->post_type,
                        'authorization_post_id' => (int) $source_lesson->ID,
                        'access_rule_ids' => $course_spec['lesson_rule_ids'],
                        'target_type' => 'mpcs-lesson',
                        'title' => $lesson_spec['title'],
                        'slug' => $course_spec['slug'] . '-' . sanitize_title($lesson_spec['title']),
                        'position' => $lesson_position + 1,
                        'status' => 'draft',
                        'source_modified_gmt' => (string) $source_lesson->post_modified_gmt,
                        'source_fingerprint' => $this->post_fingerprint($source_lesson),
                        'media' => $this->post_media_summary($source_lesson),
                        'resources' => $this->post_resource_summary($source_lesson),
                    );
                }

                $course['sections'][] = $section;
            }

            if ($source_course) {
                sort($specified_child_ids, SORT_NUMERIC);
                if ($published_child_ids !== $specified_child_ids) {
                    $this->errors[] = sprintf('Published lesson children changed for masterclass source %d.', $source_course->ID);
                }
            } else {
                $course['source_fingerprint'] = hash('sha256', wp_json_encode(array_map(static function ($section) {
                    return array_column($section['lessons'], 'source_fingerprint');
                }, $course['sections'])));
            }

            $courses[] = $course;
        }

        return $courses;
    }

    private function assert_dependencies() {
        if (!class_exists('MeprRule')) {
            throw new RuntimeException('MemberPress is unavailable; normalization must fail closed.');
        }
        if (!post_type_exists('mpcs-course') || !post_type_exists('mpcs-lesson')) {
            throw new RuntimeException('MemberPress Courses post types are unavailable.');
        }

        $archive_rule = get_post(TSOL_Library_Normalization_Spec::ARCHIVE_RULE_ID);
        if (!$archive_rule || 'publish' !== $archive_rule->post_status || 'memberpressrule' !== $archive_rule->post_type) {
            throw new RuntimeException('The expected archive MemberPress rule is unavailable.');
        }

        $actual_conditions = array();
        $rule = new MeprRule(TSOL_Library_Normalization_Spec::ARCHIVE_RULE_ID);
        foreach ($rule->access_conditions() as $condition) {
            $actual_conditions[] = implode(':', array(
                (string) $condition->access_type,
                (string) $condition->access_operator,
                (string) $condition->access_condition,
            ));
        }

        $expected_conditions = array_map(static function ($membership_id) {
            return 'membership:is:' . (int) $membership_id;
        }, TSOL_Library_Normalization_Spec::archive_membership_ids());
        sort($actual_conditions, SORT_STRING);
        sort($expected_conditions, SORT_STRING);
        if ($expected_conditions !== $actual_conditions) {
            throw new RuntimeException('Archive rule membership conditions changed from the approved 26-product signature.');
        }
    }

    private function assert_source_post($post_id, $post_type, $post_status) {
        $post = get_post((int) $post_id);
        if (!$post) {
            throw new RuntimeException(sprintf('Missing source post %d.', $post_id));
        }
        if ($post_type !== $post->post_type || $post_status !== $post->post_status) {
            $this->errors[] = sprintf(
                'Source post %d expected %s/%s but found %s/%s.',
                $post_id,
                $post_type,
                $post_status,
                $post->post_type,
                $post->post_status
            );
        }
        return $post;
    }

    private function assert_rule_signature($post, $expected_rule_ids) {
        $actual_rule_ids = array_map(static function ($rule) {
            return (int) $rule->ID;
        }, MeprRule::get_rules($post));
        $expected_rule_ids = array_map('intval', $expected_rule_ids);
        sort($actual_rule_ids, SORT_NUMERIC);
        sort($expected_rule_ids, SORT_NUMERIC);
        if ($actual_rule_ids !== $expected_rule_ids) {
            $this->errors[] = sprintf(
                'MemberPress rule signature changed for source post %d: expected [%s], found [%s].',
                $post->ID,
                implode(',', $expected_rule_ids),
                implode(',', $actual_rule_ids)
            );
        }
    }

    private function remember_fingerprint($post) {
        $this->source_fingerprints[] = (int) $post->ID . ':' . $this->post_fingerprint($post);
    }

    private function post_fingerprint($post) {
        $meta = get_post_meta($post->ID);
        $stable_meta = array();
        foreach ($meta as $key => $values) {
            if (preg_match('/^(_edit_|_oembed_|_elementor_css$|_elementor_element_cache$)/', (string) $key)) {
                continue;
            }
            $stable_meta[$key] = $this->canonicalize_value(array_map(static function ($value) {
                return maybe_unserialize($value);
            }, $values));
        }
        ksort($stable_meta, SORT_STRING);

        $payload = array(
            'id' => (int) $post->ID,
            'type' => (string) $post->post_type,
            'status' => (string) $post->post_status,
            'parent' => (int) $post->post_parent,
            'title' => $this->canonicalize_value((string) $post->post_title),
            'slug' => $this->canonicalize_value((string) $post->post_name),
            'content' => $this->canonicalize_value((string) $post->post_content),
            'excerpt' => $this->canonicalize_value((string) $post->post_excerpt),
            'password' => $this->canonicalize_value((string) $post->post_password),
            'date_gmt' => (string) $post->post_date_gmt,
            'modified_gmt' => (string) $post->post_modified_gmt,
            'meta' => $stable_meta,
        );

        return hash('sha256', serialize($payload));
    }

    private function post_media_summary($post) {
        $assets = TSOL_Library_Media_Normalizer::extract_from_content($post->post_content);
        if (empty($assets)) {
            $this->errors[] = sprintf('No supported media asset was found in playable source post %d.', $post->ID);
        }

        $providers = array();
        $private_references = 0;
        $safe_assets = array();
        foreach ($assets as $asset) {
            $provider = (string) $asset['provider'];
            $providers[$provider] = isset($providers[$provider]) ? $providers[$provider] + 1 : 1;
            if (!empty($asset['privacy_hash'])) {
                $private_references++;
            }
            $safe_assets[] = array(
                'provider' => $provider,
                'kind' => (string) $asset['kind'],
                'has_provider_id' => '' !== (string) $asset['provider_id'],
                'has_privacy_hash' => '' !== (string) $asset['privacy_hash'],
                'has_attachment_id' => !empty($asset['attachment_id']),
            );
        }
        ksort($providers, SORT_STRING);

        return array(
            'asset_count' => count($assets),
            'providers' => $providers,
            'private_reference_count' => $private_references,
            'fingerprint' => $this->media_fingerprint($assets),
            'safe_assets' => $safe_assets,
        );
    }

    private function media_fingerprint($assets) {
        $identities = array_map(function ($asset) {
            return array(
                'kind' => (string) $asset['kind'],
                'provider' => (string) $asset['provider'],
                'provider_id' => (string) $asset['provider_id'],
                'privacy_hash' => (string) $asset['privacy_hash'],
                'attachment_id' => (int) $asset['attachment_id'],
                'external_source_url' => 'external' === $asset['provider']
                    ? $this->canonicalize_value((string) $asset['source_url'])
                    : '',
            );
        }, $assets);

        return hash('sha256', serialize($identities));
    }

    private function post_resource_summary($post) {
        $resources = TSOL_Library_Resource_Normalizer::extract_from_content($post->post_content);
        $types = array();
        foreach ($resources as $resource) {
            $path = (string) wp_parse_url($resource['url'], PHP_URL_PATH);
            $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
            $types[$extension] = isset($types[$extension]) ? $types[$extension] + 1 : 1;
        }
        ksort($types, SORT_STRING);

        return array(
            'resource_count' => count($resources),
            'types' => $types,
            'fingerprint' => hash('sha256', serialize(array_map(static function ($resource) {
                return array(
                    'url' => (string) $resource['url'],
                    'attachment_id' => (int) $resource['attachment_id'],
                    'position' => (int) $resource['position'],
                );
            }, $resources))),
        );
    }

    private function canonicalize_value($value) {
        if (is_string($value)) {
            $origins = array_values(array_unique(array_filter(array(
                untrailingslashit(home_url()),
                untrailingslashit(site_url()),
            ))));
            usort($origins, static function ($left, $right) {
                return strlen($right) <=> strlen($left);
            });

            foreach ($origins as $origin) {
                $value = str_replace($origin, '{{wordpress_origin}}', $value);
                $value = str_replace(str_replace('/', '\\/', $origin), '{{wordpress_origin}}', $value);
            }
            return $value;
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $is_list = array() === $value || array_keys($value) === range(0, count($value) - 1);
        if (!$is_list) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalize_value($item);
        }
        return $value;
    }

    private function summarize_media($items, $courses) {
        $summary = array(
            'playable_pages' => 0,
            'media_assets' => 0,
            'pages_with_multiple_assets' => 0,
            'private_reference_count' => 0,
            'providers' => array(),
        );

        $media_rows = array_column($items, 'media');
        foreach ($courses as $course) {
            foreach ($course['sections'] as $section) {
                foreach ($section['lessons'] as $lesson) {
                    $media_rows[] = $lesson['media'];
                }
            }
        }

        foreach ($media_rows as $media) {
            $summary['playable_pages']++;
            $summary['media_assets'] += (int) $media['asset_count'];
            $summary['private_reference_count'] += (int) $media['private_reference_count'];
            if ((int) $media['asset_count'] > 1) {
                $summary['pages_with_multiple_assets']++;
            }
            foreach ($media['providers'] as $provider => $count) {
                $summary['providers'][$provider] = isset($summary['providers'][$provider]) ? $summary['providers'][$provider] + $count : $count;
            }
        }
        ksort($summary['providers'], SORT_STRING);

        return $summary;
    }

    private function summarize_resources($items, $courses) {
        $summary = array(
            'pages_with_resources' => 0,
            'resources' => 0,
            'types' => array(),
        );

        $resource_rows = array_column($items, 'resources');
        foreach ($courses as $course) {
            foreach ($course['sections'] as $section) {
                foreach ($section['lessons'] as $lesson) {
                    $resource_rows[] = $lesson['resources'];
                }
            }
        }

        foreach ($resource_rows as $resources) {
            $count = (int) $resources['resource_count'];
            if ($count > 0) {
                $summary['pages_with_resources']++;
                $summary['resources'] += $count;
            }
            foreach ($resources['types'] as $type => $type_count) {
                $summary['types'][$type] = isset($summary['types'][$type])
                    ? $summary['types'][$type] + (int) $type_count
                    : (int) $type_count;
            }
        }
        ksort($summary['types'], SORT_STRING);

        return $summary;
    }

    private function count_course_sections($courses) {
        return array_sum(array_map(static function ($course) {
            return count($course['sections']);
        }, $courses));
    }

    private function count_course_lessons($courses) {
        $count = 0;
        foreach ($courses as $course) {
            foreach ($course['sections'] as $section) {
                $count += count($section['lessons']);
            }
        }
        return $count;
    }

    private function count_items_by_kind($items, $kind) {
        return count(array_filter($items, static function ($item) use ($kind) {
            return $kind === $item['kind'];
        }));
    }

    private function course_source_ids($courses) {
        $ids = array();
        foreach ($courses as $course) {
            if (!empty($course['source_course_id'])) {
                $ids[] = (int) $course['source_course_id'];
            }
            foreach ($course['sections'] as $section) {
                foreach ($section['lessons'] as $lesson) {
                    $ids[] = (int) $lesson['source_id'];
                }
            }
        }
        return $ids;
    }
}
