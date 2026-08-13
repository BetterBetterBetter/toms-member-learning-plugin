<?php
/**
 * Guarded clone-only importer from legacy content into TSOL-owned Library CPTs.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Catalogue_Import {

    const VERSION = '20260809.4';
    const WORKING_HOST = 'tomschooloflife.test';
    const STATE_OPTION = 'tsol_library_catalogue_import_state';
    const LOCK_OPTION = 'tsol_library_catalogue_import_lock';
    const APPLY_CONFIRMATION = 'import-legacy-content-into-tsol-library-drafts';
    const ROLLBACK_CONFIRMATION = 'remove-tsol-library-import-drafts';

    public function preview() {
        $manifest = $this->manifest();
        return array(
            'schema_version' => self::VERSION,
            'source_schema_version' => TSOL_Library_Normalization_Spec::VERSION,
            'source_fingerprint' => $manifest['source_fingerprint'],
            'target_post_types' => array(
                'courses' => TSOL_Library_Content_Model::COURSE_POST_TYPE,
                'content' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            ),
            'expected' => array(
                'courses' => count($manifest['courses']),
                'content' => (int) $manifest['actual_counts']['lessons'] + (int) $manifest['actual_counts']['library_items'],
                'course_lessons' => (int) $manifest['actual_counts']['lessons'],
                'standalone_content' => (int) $manifest['actual_counts']['library_items'],
                'sections' => (int) $manifest['actual_counts']['sections'],
                // The legacy mixed-content collection taxonomy is retired by
                // the separately reversible structure migration.
                'collections' => 0,
            ),
            'target_status' => 'draft',
            'legacy_mutations' => 0,
            'memberpress_course_mutations' => 0,
            'memberpress_rule_mutations' => 0,
        );
    }

    public function status() {
        $state = $this->state();
        return array(
            'schema_version' => self::VERSION,
            'phase' => isset($state['phase']) ? (string) $state['phase'] : 'not_started',
            'created_posts' => count((array) ($state['created_post_ids'] ?? array())),
            'created_terms' => count((array) ($state['created_term_ids'] ?? array())),
            'targets' => $this->target_counts(),
        );
    }

    public function apply() {
        $this->assert_environment();
        return $this->with_lock(function () {
            $manifest = $this->manifest();
            $state = $this->state();
            $this->assert_state($state, $manifest);

            if (!empty($state) && 'applied' === (string) $state['phase']) {
                return $this->verify();
            }
            if (!empty($this->target_ids())) {
                throw new RuntimeException('TSOL Library import targets already exist outside an applied importer state.');
            }

            $state = array(
                'schema_version' => self::VERSION,
                'source_fingerprint' => (string) $manifest['source_fingerprint'],
                'phase' => 'applying',
                'created_post_ids' => array(),
                'created_term_ids' => array(),
                'authority_fingerprint' => $this->authority_fingerprint(),
                'legacy_fingerprint' => $this->legacy_fingerprint($manifest),
                'legacy_fingerprint_ignored_meta' => array('_edit_lock'),
                'started_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            );
            $this->save_state($state);

            try {
                foreach ($manifest['courses'] as $course) {
                    $this->create_course($course, $state);
                }
                foreach ($manifest['library_items'] as $item) {
                    $this->create_standalone_item($item, $state);
                }
            } catch (Throwable $exception) {
                $state['phase'] = 'failed';
                $state['failure'] = $exception->getMessage();
                $state['updated_at'] = gmdate('c');
                $this->save_state($state);
                throw $exception;
            }

            $state['phase'] = 'applied';
            $state['applied_at'] = gmdate('c');
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);
            return $this->verify();
        });
    }

    public function verify() {
        $this->assert_environment();
        $manifest = $this->manifest();
        $state = $this->state();
        $this->assert_state($state, $manifest);
        if (empty($state) || 'applied' !== (string) $state['phase']) {
            throw new RuntimeException('The TSOL Library catalogue import is not applied.');
        }

        $expected = $this->preview()['expected'];
        $counts = $this->target_counts();
        if ((int) $expected['courses'] !== (int) $counts['courses']
            || (int) $expected['content'] !== (int) $counts['content']
            || (int) $expected['course_lessons'] !== (int) $counts['course_lessons']
            || (int) $expected['standalone_content'] !== (int) $counts['standalone_content']
            || (int) $expected['collections'] !== (int) $counts['collections']
        ) {
            throw new RuntimeException('The imported TSOL Library target counts do not match the locked manifest.');
        }

        $mappings = $this->expected_mappings($manifest);
        $delegations = 0;
        $target_statuses = array();
        $authorization_mode = $this->authorization_mode();
        foreach ($mappings as $mapping) {
            $target_id = $this->target_id((string) $mapping['migration_key']);
            $target = get_post($target_id);
            if (!$target instanceof WP_Post || in_array($target->post_status, array('trash', 'auto-draft'), true)) {
                throw new RuntimeException(sprintf('Imported target %s is missing or no longer reviewable.', $mapping['migration_key']));
            }
            $target_statuses[$target->post_status] = (int) ($target_statuses[$target->post_status] ?? 0) + 1;
            if ((string) get_post_meta($target_id, TSOL_Library_Content_Model::META_MIGRATION_VERSION, true) !== self::VERSION) {
                throw new RuntimeException(sprintf('Imported target %s lost its ownership marker.', $mapping['migration_key']));
            }
            $legacy_source_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID, true);
            if ($legacy_source_id !== (int) $mapping['source_id']) {
                throw new RuntimeException(sprintf('Imported target %s lost its locked legacy source.', $mapping['migration_key']));
            }
            $authorization_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true);
            $expected_authorization_id = 'tsol_native' === $authorization_mode
                ? $this->native_authorization_id($target_id)
                : (int) $mapping['source_id'];
            if ($authorization_id !== $expected_authorization_id) {
                throw new RuntimeException(sprintf('Imported target %s has an authorization pointer inconsistent with the access-migration phase.', $mapping['migration_key']));
            }
            $expected_rules = array_map('intval', $mapping['access_rule_ids']);
            sort($expected_rules, SORT_NUMERIC);
            if ($expected_rules !== $this->rule_ids(get_post((int) $mapping['source_id']))) {
                throw new RuntimeException(sprintf('Imported target %s no longer has its original MemberPress authorization baseline.', $mapping['migration_key']));
            }
            $delegations++;
        }

        foreach ($manifest['courses'] as $course) {
            $course_id = $this->target_id((string) $course['migration_key']);
            $expected_registry = array();
            foreach ($course['sections'] as $section) {
                $expected_registry[] = array(
                    'key' => sanitize_key('course-' . $course['key'] . '-' . $section['key']),
                    'title' => (string) $section['title'],
                    'position' => (int) $section['position'],
                );
            }
            $expected_registry = TSOL_Library_Content_Model::sanitize_structure_registry($expected_registry);
            $actual_registry = TSOL_Library_Content_Model::sanitize_structure_registry(
                get_post_meta($course_id, TSOL_Library_Content_Model::META_COURSE_SECTIONS, true)
            );
            if (maybe_serialize($expected_registry) !== maybe_serialize($actual_registry)) {
                throw new RuntimeException(sprintf('Imported Course %s no longer matches its guarded section registry.', $course['title']));
            }
        }

        if ((string) $state['authority_fingerprint'] !== $this->authority_fingerprint()) {
            throw new RuntimeException('MemberPress rules, products, or access conditions changed during the import.');
        }
        // The manifest has already re-established the locked canonical source
        // fingerprint. Keep the older all-meta fingerprint as an advisory
        // signal because WordPress can refresh editor/oEmbed cache metadata
        // without changing canonical content. Rollback remains stricter below.
        $legacy_advisory_metadata_unchanged = (string) $state['legacy_fingerprint'] === $this->legacy_fingerprint($manifest);

        return array(
            'schema_version' => self::VERSION,
            'phase' => 'applied',
            'source_fingerprint' => $manifest['source_fingerprint'],
            'normalized' => $counts,
            'authorization_mappings' => count($mappings),
            'authorization_delegations_equivalent' => $delegations,
            'legacy_authorization_mappings_preserved' => $delegations,
            'authorization_mode' => $authorization_mode,
            'target_status' => 1 === count($target_statuses) ? (string) array_key_first($target_statuses) : 'mixed',
            'target_statuses' => $target_statuses,
            'automatic_rollback_available' => empty(array_diff(array_keys($target_statuses), array('draft'))),
            'legacy_unchanged' => true,
            'legacy_advisory_metadata_unchanged' => $legacy_advisory_metadata_unchanged,
            'memberpress_courses_unchanged' => true,
            'memberpress_rule_mutations' => 0,
        );
    }

    public function rollback() {
        $this->assert_environment();
        return $this->with_lock(function () {
            $manifest = $this->manifest();
            $state = $this->state();
            $this->assert_state($state, $manifest);
            if (empty($state) || !in_array((string) $state['phase'], array('applied', 'failed', 'rolling_back'), true)) {
                throw new RuntimeException('There is no applied, failed, or interrupted TSOL Library catalogue import to roll back.');
            }
            if ((string) $state['authority_fingerprint'] !== $this->authority_fingerprint()
                || (string) $state['legacy_fingerprint'] !== $this->legacy_fingerprint($manifest)
            ) {
                throw new RuntimeException('Legacy content or MemberPress authority changed; rollback stopped for review.');
            }

            $post_ids = array_values(array_unique(array_map('intval', (array) $state['created_post_ids'])));
            $existing_post_ids = array();
            foreach ($post_ids as $post_id) {
                $post = get_post($post_id);
                if (!$post instanceof WP_Post) {
                    continue;
                }
                if ('draft' !== $post->post_status
                    || self::VERSION !== (string) get_post_meta($post_id, TSOL_Library_Content_Model::META_MIGRATION_VERSION, true)
                ) {
                    throw new RuntimeException(sprintf('Importer-owned post %d was edited or published; rollback stopped.', $post_id));
                }
                $existing_post_ids[] = $post_id;
            }

            if ('rolling_back' !== (string) $state['phase']
                && !empty($existing_post_ids)
                && count($existing_post_ids) !== count($post_ids)
            ) {
                throw new RuntimeException('Only some importer-owned posts are missing; rollback stopped for review.');
            }
            if ('rolling_back' !== (string) $state['phase'] && empty($existing_post_ids) && !empty($this->target_ids())) {
                throw new RuntimeException('Importer targets exist outside the recorded rollback set; rollback stopped for review.');
            }

            $state['phase'] = 'rolling_back';
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);

            // WordPress only clears taxonomy relationships for taxonomies that
            // are registered when a post is deleted. Register the retired
            // import taxonomy before deleting targets so rollback is complete
            // and safely resumable after any late cleanup failure.
            $this->ensure_retired_collection_taxonomy();
            foreach ($existing_post_ids as $post_id) {
                if (!wp_delete_post($post_id, true)) {
                    throw new RuntimeException(sprintf('Could not delete importer-owned post %d.', $post_id));
                }
            }
            $this->delete_retired_collections($manifest);

            $state['phase'] = 'rolled_back';
            $state['created_post_ids'] = array();
            $state['created_term_ids'] = array();
            $state['rolled_back_at'] = gmdate('c');
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);
            return array(
                'schema_version' => self::VERSION,
                'phase' => 'rolled_back',
                'removed_posts' => count($post_ids),
                'remaining' => $this->target_counts(),
                'legacy_unchanged' => true,
                'memberpress_rule_mutations' => 0,
            );
        });
    }

    private function create_course($course, &$state) {
        $source = $this->course_source($course);
        $synthetic = empty($course['source_course_id']);
        $course_id = $this->create_post(array(
            'post_type' => TSOL_Library_Content_Model::COURSE_POST_TYPE,
            'post_status' => 'draft',
            'post_title' => (string) $course['title'],
            'post_name' => (string) $course['slug'],
            'post_content' => $synthetic ? '' : (string) $source->post_content,
            'post_excerpt' => $synthetic ? '' : (string) $source->post_excerpt,
            'post_author' => (int) $source->post_author,
            'post_date' => (string) $source->post_date,
            'post_date_gmt' => (string) $source->post_date_gmt,
        ), $this->base_meta($course, $source, (int) $source->ID, 'course'), $state);
        $this->copy_thumbnail($source->ID, $course_id);

        $section_registry = array();
        foreach ($course['sections'] as $section) {
            $section_registry[] = array(
                'key' => sanitize_key('course-' . $course['key'] . '-' . $section['key']),
                'title' => (string) $section['title'],
                'position' => (int) $section['position'],
            );
        }
        update_post_meta(
            $course_id,
            TSOL_Library_Content_Model::META_COURSE_SECTIONS,
            TSOL_Library_Content_Model::sanitize_structure_registry($section_registry)
        );

        foreach ($course['sections'] as $section) {
            foreach ($section['lessons'] as $lesson) {
                $lesson_source = get_post((int) $lesson['source_id']);
                if (!$lesson_source instanceof WP_Post) {
                    throw new RuntimeException(sprintf('Legacy lesson source %d is missing.', $lesson['source_id']));
                }
                $meta = $this->base_meta($lesson, $lesson_source, (int) $lesson['source_id'], 'lesson');
                $meta[TSOL_Library_Content_Model::META_COURSE_ID] = $course_id;
                $meta[TSOL_Library_Content_Model::META_SECTION_KEY] = sanitize_key('course-' . $course['key'] . '-' . $section['key']);
                $meta[TSOL_Library_Content_Model::META_SECTION_TITLE] = (string) $section['title'];
                $meta[TSOL_Library_Content_Model::META_SECTION_POSITION] = (int) $section['position'];
                $meta[TSOL_Library_Content_Model::META_POSITION] = (int) $lesson['position'];
                $meta[TSOL_Library_Content_Model::META_MEDIA_ASSETS] = TSOL_Library_Media_Normalizer::extract_from_content($lesson_source->post_content);
                $meta[TSOL_Library_Content_Model::META_RESOURCES] = TSOL_Library_Resource_Normalizer::extract_from_content($lesson_source->post_content);
                $lesson_id = $this->create_post(array(
                    'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
                    'post_status' => 'draft',
                    'post_title' => (string) $lesson['title'],
                    'post_name' => (string) $lesson['slug'],
                    'post_content' => (string) $lesson_source->post_content,
                    'post_excerpt' => (string) $lesson_source->post_excerpt,
                    'post_author' => (int) $lesson_source->post_author,
                    'post_date' => (string) $lesson_source->post_date,
                    'post_date_gmt' => (string) $lesson_source->post_date_gmt,
                ), $meta, $state);
                $this->copy_thumbnail($lesson_source->ID, $lesson_id);
            }
        }
    }

    private function create_standalone_item($item, &$state) {
        $source = get_post((int) $item['source_id']);
        if (!$source instanceof WP_Post) {
            throw new RuntimeException(sprintf('Legacy content source %d is missing.', $item['source_id']));
        }
        $content_type_map = array(
            'numbered_session' => 'session',
            'live_event' => 'live_event',
            'unconference_2025' => 'recording',
            'orientation' => 'orientation',
            'limitless_book_club' => 'book_club',
            'member_call' => 'member_call',
        );
        $content_type = isset($content_type_map[$item['kind']]) ? $content_type_map[$item['kind']] : 'recording';
        $meta = $this->base_meta($item, $source, (int) $item['source_id'], $content_type);
        $meta[TSOL_Library_Content_Model::META_POSITION] = (int) $item['position'];
        $meta[TSOL_Library_Content_Model::META_MEDIA_ASSETS] = TSOL_Library_Media_Normalizer::extract_from_content($source->post_content);
        $meta[TSOL_Library_Content_Model::META_RESOURCES] = TSOL_Library_Resource_Normalizer::extract_from_content($source->post_content);
        $item_id = $this->create_post(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            'post_status' => 'draft',
            'post_title' => (string) $item['title'],
            'post_name' => (string) $item['slug'],
            'post_content' => (string) $source->post_content,
            'post_excerpt' => (string) $source->post_excerpt,
            'post_author' => (int) $source->post_author,
            'post_date' => (string) $source->post_date,
            'post_date_gmt' => (string) $source->post_date_gmt,
        ), $meta, $state);
        $this->copy_thumbnail($source->ID, $item_id);
    }

    private function create_post($post_data, $meta, &$state) {
        $post_id = wp_insert_post(wp_slash($post_data), true);
        if (is_wp_error($post_id)) {
            throw new RuntimeException($post_id->get_error_message());
        }
        $post_id = (int) $post_id;
        $state['created_post_ids'][] = $post_id;
        $this->save_state($state);
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }
        return $post_id;
    }

    private function base_meta($entry, WP_Post $source, $authorization_id, $content_type) {
        return array(
            TSOL_Library_Content_Model::META_INCLUDE => true,
            TSOL_Library_Content_Model::META_CONTENT_TYPE => (string) $content_type,
            TSOL_Library_Content_Model::META_POSITION => 0,
            TSOL_Library_Content_Model::META_FEATURED => false,
            TSOL_Library_Content_Model::META_SPEAKER_MODE => 'course' === $content_type
                ? TSOL_Library_Content_Model::SPEAKER_MODE_DIRECT
                : TSOL_Library_Content_Model::SPEAKER_MODE_INHERIT,
            TSOL_Library_Content_Model::META_MEDIA_ASSETS => array(),
            TSOL_Library_Content_Model::META_RESOURCES => array(),
            TSOL_Library_Content_Model::META_MIGRATION_KEY => (string) $entry['migration_key'],
            TSOL_Library_Content_Model::META_MIGRATION_VERSION => self::VERSION,
            TSOL_Library_Content_Model::META_UUID => $this->deterministic_uuid((string) $entry['migration_key']),
            TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID => (int) $source->ID,
            TSOL_Library_Content_Model::META_LEGACY_SOURCE_TYPE => (string) $source->post_type,
            TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID => (int) $authorization_id,
            TSOL_Library_Content_Model::META_SOURCE_MODIFIED_GMT => (string) $source->post_modified_gmt,
            TSOL_Library_Content_Model::META_CONTENT_FINGERPRINT => (string) $entry['source_fingerprint'],
            TSOL_Library_Content_Model::META_COURSE_ID => 0,
            TSOL_Library_Content_Model::META_SECTION_KEY => '',
            TSOL_Library_Content_Model::META_SECTION_TITLE => '',
            TSOL_Library_Content_Model::META_SECTION_POSITION => 0,
        );
    }

    private function expected_mappings($manifest) {
        $mappings = array();
        foreach ($manifest['courses'] as $course) {
            $source = $this->course_source($course);
            $mappings[] = array(
                'migration_key' => (string) $course['migration_key'],
                'source_id' => (int) $source->ID,
                'access_rule_ids' => $course['access_rule_ids'],
            );
            foreach ($course['sections'] as $section) {
                foreach ($section['lessons'] as $lesson) {
                    $mappings[] = array(
                        'migration_key' => (string) $lesson['migration_key'],
                        'source_id' => (int) $lesson['source_id'],
                        'access_rule_ids' => $lesson['access_rule_ids'],
                    );
                }
            }
        }
        foreach ($manifest['library_items'] as $item) {
            $mappings[] = array(
                'migration_key' => (string) $item['migration_key'],
                'source_id' => (int) $item['source_id'],
                'access_rule_ids' => $item['access_rule_ids'],
            );
        }
        return $mappings;
    }

    private function course_source($course) {
        if (!empty($course['source_course_id'])) {
            $source = get_post((int) $course['source_course_id']);
            if ($source instanceof WP_Post) {
                return $source;
            }
        }
        foreach ($course['sections'] as $section) {
            foreach ($section['lessons'] as $lesson) {
                $source = get_post((int) $lesson['source_id']);
                if ($source instanceof WP_Post) {
                    return $source;
                }
            }
        }
        throw new RuntimeException(sprintf('Course %s has no legacy source.', $course['key']));
    }

    private function copy_thumbnail($source_id, $target_id) {
        $thumbnail_id = (int) get_post_thumbnail_id((int) $source_id);
        if ($thumbnail_id > 0) {
            set_post_thumbnail((int) $target_id, $thumbnail_id);
        }
    }

    private function deterministic_uuid($key) {
        $hex = substr(hash('sha256', self::VERSION . '|' . $key), 0, 32);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    private function target_counts() {
        $ids = $this->target_ids();
        $counts = array('courses' => 0, 'content' => 0, 'course_lessons' => 0, 'standalone_content' => 0, 'collections' => 0);
        foreach ($ids as $post_id) {
            if (TSOL_Library_Content_Model::COURSE_POST_TYPE === get_post_type($post_id)) {
                $counts['courses']++;
            } else {
                $counts['content']++;
                if ((int) get_post_meta($post_id, TSOL_Library_Content_Model::META_COURSE_ID, true) > 0) {
                    $counts['course_lessons']++;
                } else {
                    $counts['standalone_content']++;
                }
            }
        }
        return $counts;
    }

    private function delete_retired_collections($manifest) {
        $taxonomy = 'tsol_collection';
        $this->ensure_retired_collection_taxonomy();

        $collections = array_reverse((array) $manifest['collections']);
        foreach ($collections as $collection) {
            $term = get_term_by('slug', (string) $collection['slug'], $taxonomy);
            if (!$term instanceof WP_Term) {
                continue;
            }
            $used = get_objects_in_term((int) $term->term_id, $taxonomy);
            $existing_objects = is_wp_error($used) ? array() : array_values(array_filter(array_map('intval', $used), static function ($post_id) {
                return get_post($post_id) instanceof WP_Post;
            }));
            if (is_wp_error($used) || !empty($existing_objects)) {
                throw new RuntimeException(sprintf('Retired collection %s remains in use.', $collection['slug']));
            }
            $deleted = wp_delete_term((int) $term->term_id, $taxonomy);
            if (is_wp_error($deleted) || false === $deleted) {
                throw new RuntimeException(sprintf('Could not delete retired collection %s.', $collection['slug']));
            }
        }
    }

    private function ensure_retired_collection_taxonomy() {
        $taxonomy = 'tsol_collection';
        if (!taxonomy_exists($taxonomy)) {
            register_taxonomy($taxonomy, TSOL_Library_Content_Model::post_types(), array(
                'public' => false,
                'show_ui' => false,
                'rewrite' => false,
            ));
        }
    }

    private function target_ids() {
        return array_map('intval', get_posts(array(
            'post_type' => TSOL_Library_Content_Model::post_types(),
            'post_status' => array_values(get_post_stati()),
            'numberposts' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_key' => TSOL_Library_Content_Model::META_MIGRATION_VERSION,
            'meta_value' => self::VERSION,
        )));
    }

    private function target_id($migration_key) {
        $ids = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::post_types(),
            'post_status' => array_values(get_post_stati()),
            'numberposts' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_key' => TSOL_Library_Content_Model::META_MIGRATION_KEY,
            'meta_value' => $migration_key,
        ));
        if (1 !== count($ids)) {
            throw new RuntimeException(sprintf('Migration key %s does not resolve to exactly one target.', $migration_key));
        }
        return (int) $ids[0];
    }

    private function rule_ids($post) {
        if (!$post instanceof WP_Post || !class_exists('MeprRule')) {
            return array();
        }
        $ids = array_map(static function ($rule) {
            return isset($rule->ID) ? (int) $rule->ID : 0;
        }, MeprRule::get_rules($post));
        $ids = array_values(array_unique(array_filter($ids)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function authorization_mode() {
        $state = get_option('tsol_library_access_rules_migration_state', array());
        return is_array($state) && 'activated' === (string) ($state['phase'] ?? '')
            ? 'tsol_native'
            : 'legacy_delegation';
    }

    private function native_authorization_id($target_id) {
        $target_id = (int) $target_id;
        if (TSOL_Library_Content_Model::ITEM_POST_TYPE !== get_post_type($target_id)) {
            return $target_id;
        }
        $course_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_COURSE_ID, true);
        if ($course_id > 0) {
            return $course_id;
        }
        $series_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_SERIES_ID, true);
        return $series_id > 0 ? $series_id : $target_id;
    }

    private function authority_fingerprint() {
        global $wpdb;
        $rules = $wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_type = 'memberpressrule' ORDER BY ID", ARRAY_A);
        $products = $wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_type = 'memberpressproduct' ORDER BY ID", ARRAY_A);
        $conditions = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}mepr_rule_access_conditions ORDER BY id", ARRAY_A);

        // A separately guarded migration may stage TSOL-native rules as
        // inactive drafts. They are additive target authority, not a mutation
        // of the legacy authority this importer locked before cloning.
        $owned_rule_ids = array_map('intval', get_posts(array(
            'post_type' => 'memberpressrule',
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_key' => '_tsol_library_access_rule_version',
        )));
        if (!empty($owned_rule_ids)) {
            $owned_lookup = array_fill_keys($owned_rule_ids, true);
            $rules = array_values(array_filter($rules, static function ($row) use ($owned_lookup) {
                return !isset($owned_lookup[(int) $row['ID']]);
            }));
            $conditions = array_values(array_filter($conditions, static function ($row) use ($owned_lookup) {
                return !isset($owned_lookup[(int) $row['rule_id']]);
            }));
        }
        return hash('sha256', serialize(array($rules, $products, $conditions)));
    }

    private function legacy_fingerprint($manifest) {
        $rows = array();
        foreach ($this->expected_mappings($manifest) as $mapping) {
            $source_id = (int) $mapping['source_id'];
            if (isset($rows[$source_id])) {
                continue;
            }
            $post = get_post($source_id, ARRAY_A);
            $meta = get_post_meta($source_id);
            // Opening a WordPress editor refreshes this advisory heartbeat value.
            // It is not source content and must not invalidate a read-only review.
            unset($meta['_edit_lock']);
            ksort($meta, SORT_STRING);
            $rows[$source_id] = array('post' => $post, 'meta' => $meta);
        }
        ksort($rows, SORT_NUMERIC);
        return hash('sha256', serialize($rows));
    }

    private function manifest() {
        return (new TSOL_Library_Normalization_Manifest())->build();
    }

    private function state() {
        $state = get_option(self::STATE_OPTION, array());
        return is_array($state) ? $state : array();
    }

    private function save_state($state) {
        update_option(self::STATE_OPTION, $state, false);
    }

    private function assert_state($state, $manifest) {
        if (empty($state)) {
            return;
        }
        if (self::VERSION !== (string) ($state['schema_version'] ?? '')) {
            throw new RuntimeException('The stored importer state belongs to another schema version.');
        }
        if ((string) $manifest['source_fingerprint'] !== (string) ($state['source_fingerprint'] ?? '')) {
            throw new RuntimeException('The stored importer state belongs to another legacy source fingerprint.');
        }
    }

    private function assert_environment() {
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (self::WORKING_HOST !== $host) {
            throw new RuntimeException(sprintf('Importer writes are allowed only on %s.', self::WORKING_HOST));
        }
        foreach (TSOL_Library_Content_Model::post_types() as $post_type) {
            if (!post_type_exists($post_type)) {
                throw new RuntimeException(sprintf('Required TSOL Library post type %s is unavailable.', $post_type));
            }
        }
        if (!class_exists('MeprRule')) {
            throw new RuntimeException('MemberPress is unavailable; the importer fails closed.');
        }
    }

    private function with_lock($callback) {
        $now = time();
        if (!add_option(self::LOCK_OPTION, $now, '', 'no')) {
            throw new RuntimeException('Another TSOL Library import process holds the lock.');
        }
        try {
            return call_user_func($callback);
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }
}
