<?php
/**
 * Guarded local-only migration from mixed collections into Collections
 * and first-class ordered Series.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Series_Import {

    const VERSION = '20260810.3';
    const WORKING_HOST = 'tomschooloflife.test';
    const STATE_OPTION = 'tsol_library_series_import_state';
    const LOCK_OPTION = 'tsol_library_series_import_lock';
    const APPLY_CONFIRMATION = 'group-normalized-library-items-into-series';
    const ROLLBACK_CONFIRMATION = 'remove-normalized-series-structure';
    const RETIRED_COLLECTION_TAXONOMY = 'tsol_collection';
    const MASTERCLASSES_SLUG = 'masterclasses';

    public function preview() {
        $series_entries = $this->series_entries();
        return array(
            'schema_version' => self::VERSION,
            'series' => count($series_entries),
            'episodes' => array_sum(array_map('count', $series_entries)),
            'series_summary' => $this->series_summary($series_entries),
            'course_collections' => array('Masterclasses' => 5),
            'retired_mixed_collections' => 15,
            'clean_titles' => true,
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
            'series_ids' => array_map('intval', (array) ($state['series_ids'] ?? array())),
            'episodes' => count((array) ($state['episodes'] ?? array())),
        );
    }

    public function apply() {
        $this->assert_environment();
        return $this->with_lock(function () {
            $catalogue_report = (new TSOL_Library_Catalogue_Import())->verify();
            $manifest = $this->manifest();
            $series_entries = $this->series_entries($manifest);
            $state = $this->state();
            $this->assert_state($state);
            if (!empty($state) && self::VERSION === (string) ($state['schema_version'] ?? '') && 'applied' === (string) $state['phase']) {
                return $this->verify();
            }
            foreach (array_keys($series_entries) as $migration_key) {
                if ($this->series_id($migration_key) > 0) {
                    throw new RuntimeException(sprintf('Series %s exists outside an applied structure migration state.', $migration_key));
                }
            }

            $episode_rows = array();
            foreach ($series_entries as $migration_key => $entries) {
                foreach ($entries as $entry) {
                    $post = $this->target_for_entry($entry);
                    $this->assert_unmodified_episode($post, $entry);
                    $episode_rows[] = array(
                        'series_key' => $migration_key,
                        'entry' => $entry,
                        'post' => $post,
                        'previous_title' => (string) $post->post_title,
                        'previous_meta' => $this->capture_episode_meta($post->ID),
                    );
                }
            }

            $state = array(
                'schema_version' => self::VERSION,
                'phase' => 'applying',
                'source_fingerprint' => (string) $catalogue_report['source_fingerprint'],
                'series_ids' => array(),
                'episodes' => array(),
                'course_collection' => $this->capture_course_collection($manifest),
                'retired_collections' => $this->capture_retired_collections($manifest),
                'started_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            );
            $this->save_state($state);

            try {
                $this->apply_course_collection($manifest, $state);
                foreach ($series_entries as $migration_key => $entries) {
                    $definition = $this->definitions()[$migration_key];
                    $source = get_post((int) $entries[0]['source_id']);
                    if (!$source instanceof WP_Post) {
                        throw new RuntimeException(sprintf('Series %s has no authorization source.', $definition['title']));
                    }
                    $latest_source = $this->latest_source($entries);
                    $series_id = wp_insert_post(wp_slash(array(
                        'post_type' => TSOL_Library_Content_Model::SERIES_POST_TYPE,
                        'post_status' => 'draft',
                        'post_title' => (string) $definition['title'],
                        'post_name' => (string) $definition['slug'],
                        'post_content' => '',
                        'post_excerpt' => (string) $definition['excerpt'],
                        'post_author' => (int) $source->post_author,
                        'post_date' => (string) $latest_source->post_date,
                        'post_date_gmt' => (string) $latest_source->post_date_gmt,
                    )), true);
                    if (is_wp_error($series_id)) {
                        throw new RuntimeException($series_id->get_error_message());
                    }
                    $series_id = (int) $series_id;
                    $state['series_ids'][$migration_key] = $series_id;
                    $this->save_state($state);
                    foreach ($this->series_meta($definition, $entries, $source) as $key => $value) {
                        update_post_meta($series_id, $key, $value);
                    }
                    update_post_meta(
                        $series_id,
                        TSOL_Library_Content_Model::META_SERIES_GROUPS,
                        $this->series_group_registry($entries)
                    );
                }

                foreach ($episode_rows as $row) {
                    $entry = $row['entry'];
                    $post = $row['post'];
                    $series_id = (int) $state['series_ids'][$row['series_key']];
                    $clean_title = $this->clean_title((string) $post->post_title, (string) $entry['kind']);
                    if ((string) $post->post_title !== $clean_title) {
                        $updated = wp_update_post(wp_slash(array('ID' => (int) $post->ID, 'post_title' => $clean_title)), true);
                        if (is_wp_error($updated)) {
                            throw new RuntimeException($updated->get_error_message());
                        }
                    }
                    update_post_meta($post->ID, TSOL_Library_Content_Model::META_POSITION, (int) $entry['series_position']);
                    update_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_ID, $series_id);
                    update_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_GROUP_KEY, (string) $entry['series_group_key']);
                    update_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_GROUP_TITLE, (string) $entry['series_group_title']);
                    update_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_GROUP_POSITION, (int) $entry['series_group_position']);
                    $state['episodes'][(string) $post->ID] = array(
                        'series_key' => (string) $row['series_key'],
                        'previous_title' => (string) $row['previous_title'],
                        'clean_title' => $clean_title,
                        'previous_meta' => $row['previous_meta'],
                    );
                    $state['updated_at'] = gmdate('c');
                    $this->save_state($state);
                }
                $this->delete_retired_collections((array) $state['retired_collections']);
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
        $catalogue_report = (new TSOL_Library_Catalogue_Import())->verify();
        $manifest = $this->manifest();
        $series_entries = $this->series_entries($manifest);
        $state = $this->state();
        $this->assert_state($state);
        if (empty($state) || self::VERSION !== (string) ($state['schema_version'] ?? '') || 'applied' !== (string) $state['phase']) {
            throw new RuntimeException('The TSOL Library structure migration is not applied.');
        }
        if ((string) $state['source_fingerprint'] !== (string) $catalogue_report['source_fingerprint']) {
            throw new RuntimeException('The locked legacy source fingerprint changed after the structure migration.');
        }

        $authorization_mode = (string) ($catalogue_report['authorization_mode'] ?? 'legacy_delegation');
        $reviewable_statuses = array('publish', 'draft', 'pending', 'private', 'future');
        $target_statuses = array();

        $series_ids = array();
        $groups = array();
        foreach ($series_entries as $migration_key => $entries) {
            $definition = $this->definitions()[$migration_key];
            $series_id = (int) ($state['series_ids'][$migration_key] ?? 0);
            $series = get_post($series_id);
            if (!$series instanceof WP_Post
                || TSOL_Library_Content_Model::SERIES_POST_TYPE !== $series->post_type
                || !in_array((string) $series->post_status, $reviewable_statuses, true)
                || self::VERSION !== (string) get_post_meta($series_id, TSOL_Library_Content_Model::META_MIGRATION_VERSION, true)
                || $migration_key !== (string) get_post_meta($series_id, TSOL_Library_Content_Model::META_MIGRATION_KEY, true)
                || (string) $definition['title'] !== (string) $series->post_title
                || (string) $definition['sort'] !== (string) get_post_meta($series_id, TSOL_Library_Content_Model::META_SERIES_SORT, true)
                || (bool) $definition['ongoing'] !== (bool) get_post_meta($series_id, TSOL_Library_Content_Model::META_SERIES_ONGOING, true)
                || ('tsol_native' === $authorization_mode ? $series_id : (int) get_post_meta($series_id, TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID, true))
                    !== (int) get_post_meta($series_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true)
            ) {
                throw new RuntimeException(sprintf('Importer-owned Series %s is missing or changed.', $definition['title']));
            }
            $target_statuses[(string) $series->post_status] = ($target_statuses[(string) $series->post_status] ?? 0) + 1;
            $expected_registry = $this->series_group_registry($entries);
            $actual_registry = TSOL_Library_Content_Model::sanitize_structure_registry(
                get_post_meta($series_id, TSOL_Library_Content_Model::META_SERIES_GROUPS, true)
            );
            if (maybe_serialize($expected_registry) !== maybe_serialize($actual_registry)) {
                throw new RuntimeException(sprintf('Importer-owned Series %s no longer matches its guarded group registry.', $definition['title']));
            }
            $series_ids[$migration_key] = $series_id;
            $groups[$migration_key] = array();
            foreach ($entries as $entry) {
                $post = $this->target_for_entry($entry);
                $episode_state = $state['episodes'][(string) $post->ID] ?? null;
                if (!is_array($episode_state)
                    || !in_array((string) $post->post_status, $reviewable_statuses, true)
                    || $series_id !== (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_ID, true)
                    || (string) $entry['series_group_key'] !== (string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_GROUP_KEY, true)
                    || (string) $entry['series_group_title'] !== (string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_GROUP_TITLE, true)
                    || (int) $entry['series_group_position'] !== (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_GROUP_POSITION, true)
                    || (int) $entry['series_position'] !== (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_POSITION, true)
                    || (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_COURSE_ID, true) > 0
                    || (string) $post->post_title !== (string) $episode_state['clean_title']
                    || (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true)
                        !== ('tsol_native' === $authorization_mode ? $series_id : (int) $entry['source_id'])
                ) {
                    throw new RuntimeException(sprintf('Series episode %s no longer matches its guarded structure.', $entry['migration_key']));
                }
                $target_statuses[(string) $post->post_status] = ($target_statuses[(string) $post->post_status] ?? 0) + 1;
                $group_key = (string) $entry['series_group_key'];
                $groups[$migration_key][$group_key] = isset($groups[$migration_key][$group_key]) ? $groups[$migration_key][$group_key] + 1 : 1;
            }
        }

        $this->verify_course_collection($manifest);
        $this->ensure_retired_collection_taxonomy();
        $retired_terms = get_terms(array('taxonomy' => self::RETIRED_COLLECTION_TAXONOMY, 'hide_empty' => false, 'fields' => 'ids'));
        if (is_wp_error($retired_terms) || !empty($retired_terms)) {
            throw new RuntimeException('Retired mixed-content Collections remain after the structure migration.');
        }

        ksort($target_statuses, SORT_STRING);
        $target_status = 1 === count($target_statuses) ? (string) array_key_first($target_statuses) : 'mixed';

        return array(
            'schema_version' => self::VERSION,
            'phase' => 'applied',
            'target_status' => $target_status,
            'target_statuses' => $target_statuses,
            'authorization_mode' => $authorization_mode,
            'series_ids' => $series_ids,
            'series' => count($series_entries),
            'episodes' => array_sum(array_map('count', $series_entries)),
            'series_summary' => $this->series_summary($series_entries),
            'groups' => $groups,
            'course_collections' => array('masterclasses' => 5),
            'retired_mixed_collections' => 0,
            'clean_titles' => true,
            'authorization_delegations_equivalent' => (int) $catalogue_report['authorization_delegations_equivalent'],
            'legacy_unchanged' => true,
            'memberpress_courses_unchanged' => true,
            'memberpress_rule_mutations' => 0,
        );
    }

    public function rollback() {
        $this->assert_environment();
        return $this->with_lock(function () {
            (new TSOL_Library_Catalogue_Import())->verify();
            $state = $this->state();
            $this->assert_state($state);
            if (empty($state) || self::VERSION !== (string) ($state['schema_version'] ?? '') || !in_array((string) $state['phase'], array('applied', 'failed'), true)) {
                throw new RuntimeException('There is no applied or failed TSOL Library structure migration to roll back.');
            }
            foreach ((array) $state['episodes'] as $post_id => $episode_state) {
                $post = get_post((int) $post_id);
                if (!$post instanceof WP_Post || 'draft' !== $post->post_status || (string) $post->post_title !== (string) $episode_state['clean_title']) {
                    throw new RuntimeException(sprintf('Series episode %d changed; rollback stopped for review.', (int) $post_id));
                }
            }
            foreach ((array) $state['series_ids'] as $migration_key => $series_id) {
                $series = get_post((int) $series_id);
                if (!$series instanceof WP_Post
                    || 'draft' !== $series->post_status
                    || self::VERSION !== (string) get_post_meta((int) $series_id, TSOL_Library_Content_Model::META_MIGRATION_VERSION, true)
                    || (string) $migration_key !== (string) get_post_meta((int) $series_id, TSOL_Library_Content_Model::META_MIGRATION_KEY, true)
                ) {
                    throw new RuntimeException(sprintf('Importer-owned Series %s changed; rollback stopped for review.', $migration_key));
                }
            }

            $restored_titles = 0;
            foreach ((array) $state['episodes'] as $post_id => $episode_state) {
                if ((string) $episode_state['previous_title'] !== (string) $episode_state['clean_title']) {
                    $restored_titles++;
                }
                $updated = wp_update_post(wp_slash(array('ID' => (int) $post_id, 'post_title' => (string) $episode_state['previous_title'])), true);
                if (is_wp_error($updated)) {
                    throw new RuntimeException($updated->get_error_message());
                }
                $this->restore_episode_meta((int) $post_id, (array) $episode_state['previous_meta']);
            }
            foreach ((array) $state['series_ids'] as $series_id) {
                if (!wp_delete_post((int) $series_id, true)) {
                    throw new RuntimeException(sprintf('Importer-owned Series %d could not be removed.', (int) $series_id));
                }
            }
            $this->restore_course_collection((array) $state['course_collection']);
            $this->restore_retired_collections((array) $state['retired_collections']);

            $removed_series = count((array) $state['series_ids']);
            $state['phase'] = 'rolled_back';
            $state['series_ids'] = array();
            $state['episodes'] = array();
            $state['rolled_back_at'] = gmdate('c');
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);
            return array(
                'schema_version' => self::VERSION,
                'phase' => 'rolled_back',
                'removed_series' => $removed_series,
                'restored_titles' => $restored_titles,
                'restored_mixed_collections' => count((array) $state['retired_collections']),
                'legacy_unchanged' => true,
                'memberpress_rule_mutations' => 0,
            );
        });
    }

    private function definitions() {
        return array(
            'series-sessions' => array(
                'title' => 'Sessions', 'slug' => 'sessions', 'kind' => 'numbered_session',
                'excerpt' => 'The continuing TSOL member webinar series.', 'label' => 'session', 'label_plural' => 'sessions',
                'sort' => 'desc', 'ongoing' => true,
            ),
            'series-live-events' => array(
                'title' => 'Live Events', 'slug' => 'live-events', 'kind' => 'live_event',
                'excerpt' => 'Recordings from TSOL live events.', 'label' => 'talk', 'label_plural' => 'talks',
                'sort' => 'desc', 'ongoing' => true,
            ),
            'series-unconference-2025' => array(
                'title' => 'Unconference 2025', 'slug' => 'unconference-2025', 'kind' => 'unconference_2025',
                'excerpt' => 'Sessions recorded at the 2025 TSOL Unconference.', 'label' => 'session', 'label_plural' => 'sessions',
                'sort' => 'asc', 'ongoing' => false,
            ),
            'series-new-member-orientation' => array(
                'title' => 'New Member Orientation', 'slug' => 'new-member-orientation', 'kind' => 'orientation',
                'excerpt' => 'TSOL orientation recordings for new members.', 'label' => 'version', 'label_plural' => 'versions',
                'sort' => 'desc', 'ongoing' => true,
            ),
            'series-limitless-book-club' => array(
                'title' => 'Limitless Book Club', 'slug' => 'limitless-book-club', 'kind' => 'limitless_book_club',
                'excerpt' => 'TSOL Limitless book club sessions.', 'label' => 'session', 'label_plural' => 'sessions',
                'sort' => 'desc', 'ongoing' => true,
            ),
            'series-member-calls' => array(
                'title' => 'Member Calls', 'slug' => 'member-calls', 'kind' => 'member_call',
                'excerpt' => 'Special calls and discussions for TSOL members.', 'label' => 'call', 'label_plural' => 'calls',
                'sort' => 'desc', 'ongoing' => true,
            ),
        );
    }

    private function series_entries($manifest = null) {
        $manifest = is_array($manifest) ? $manifest : $this->manifest();
        $grouped = array_fill_keys(array_keys($this->definitions()), array());
        $kind_to_key = array();
        foreach ($this->definitions() as $key => $definition) {
            $kind_to_key[(string) $definition['kind']] = $key;
        }
        foreach ((array) $manifest['library_items'] as $entry) {
            $kind = (string) $entry['kind'];
            if (!isset($kind_to_key[$kind])) {
                throw new RuntimeException(sprintf('Normalized item %s is not assigned to a Series.', $entry['migration_key']));
            }
            $grouped[$kind_to_key[$kind]][] = $entry;
        }

        $expected_counts = array(
            'series-sessions' => 96,
            'series-live-events' => 18,
            'series-unconference-2025' => 3,
            'series-new-member-orientation' => 2,
            'series-limitless-book-club' => 1,
            'series-member-calls' => 1,
        );
        foreach ($grouped as $key => &$entries) {
            usort($entries, function ($left, $right) use ($key) {
                if ('series-sessions' === $key) {
                    return (int) $left['position'] <=> (int) $right['position'];
                }
                $left_source = get_post((int) $left['source_id']);
                $right_source = get_post((int) $right['source_id']);
                $date_comparison = strcmp((string) $left_source->post_date_gmt, (string) $right_source->post_date_gmt);
                return 0 !== $date_comparison ? $date_comparison : ((int) $left['source_id'] <=> (int) $right['source_id']);
            });
            if ((int) $expected_counts[$key] !== count($entries)) {
                throw new RuntimeException(sprintf('Series %s expected %d items and found %d.', $key, $expected_counts[$key], count($entries)));
            }
            foreach ($entries as $index => &$entry) {
                $entry['series_position'] = $index + 1;
                $group = $this->entry_group($key, $entry);
                $entry['series_group_key'] = $group['key'];
                $entry['series_group_title'] = $group['title'];
                $entry['series_group_position'] = $group['position'];
            }
            unset($entry);
        }
        unset($entries);
        return $grouped;
    }

    private function entry_group($series_key, $entry) {
        if (in_array($series_key, array('series-sessions', 'series-live-events'), true)) {
            if (!preg_match('/(20\d{2})$/', (string) $entry['collection'], $matches)) {
                throw new RuntimeException(sprintf('Entry %s has no locked year group.', $entry['migration_key']));
            }
            $year = (int) $matches[1];
            return array('key' => 'year-' . $year, 'title' => (string) $year, 'position' => $year);
        }
        $groups = array(
            'series-unconference-2025' => array('key' => 'event', 'title' => 'Unconference 2025', 'position' => 1),
            'series-new-member-orientation' => array('key' => 'versions', 'title' => 'Versions', 'position' => 1),
            'series-limitless-book-club' => array('key' => 'sessions', 'title' => 'Sessions', 'position' => 1),
            'series-member-calls' => array('key' => 'calls', 'title' => 'Calls', 'position' => 1),
        );
        return $groups[$series_key];
    }

    private function series_meta($definition, $entries, WP_Post $source) {
        $source_modified = '';
        $fingerprints = array();
        foreach ($entries as $entry) {
            $entry_source = get_post((int) $entry['source_id']);
            if (!$entry_source instanceof WP_Post) {
                throw new RuntimeException(sprintf('Legacy source %d is missing.', (int) $entry['source_id']));
            }
            $source_modified = max($source_modified, (string) $entry_source->post_modified_gmt);
            $fingerprints[] = (int) $entry['source_id'] . ':' . (string) $entry['source_fingerprint'];
        }
        $migration_key = 'series-' . (string) $definition['slug'];
        return array(
            TSOL_Library_Content_Model::META_INCLUDE => true,
            TSOL_Library_Content_Model::META_CONTENT_TYPE => 'series',
            TSOL_Library_Content_Model::META_POSITION => 0,
            TSOL_Library_Content_Model::META_FEATURED => false,
            TSOL_Library_Content_Model::META_MEDIA_ASSETS => array(),
            TSOL_Library_Content_Model::META_RESOURCES => array(),
            TSOL_Library_Content_Model::META_MIGRATION_KEY => $migration_key,
            TSOL_Library_Content_Model::META_MIGRATION_VERSION => self::VERSION,
            TSOL_Library_Content_Model::META_UUID => $this->deterministic_uuid($migration_key),
            TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID => (int) $source->ID,
            TSOL_Library_Content_Model::META_LEGACY_SOURCE_TYPE => (string) $source->post_type,
            TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID => (int) $source->ID,
            TSOL_Library_Content_Model::META_SOURCE_MODIFIED_GMT => $source_modified,
            TSOL_Library_Content_Model::META_CONTENT_FINGERPRINT => hash('sha256', wp_json_encode($fingerprints)),
            TSOL_Library_Content_Model::META_COURSE_ID => 0,
            TSOL_Library_Content_Model::META_SERIES_ID => 0,
            TSOL_Library_Content_Model::META_SERIES_ITEM_LABEL => (string) $definition['label'],
            TSOL_Library_Content_Model::META_SERIES_ITEM_LABEL_PLURAL => (string) $definition['label_plural'],
            TSOL_Library_Content_Model::META_SERIES_SORT => (string) $definition['sort'],
            TSOL_Library_Content_Model::META_SERIES_ONGOING => (bool) $definition['ongoing'],
        );
    }

    private function capture_course_collection($manifest) {
        $course_ids = $this->course_ids($manifest);
        $previous = array();
        foreach ($course_ids as $course_id) {
            $terms = wp_get_post_terms($course_id, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, array('fields' => 'slugs'));
            if (is_wp_error($terms)) {
                throw new RuntimeException($terms->get_error_message());
            }
            $previous[(string) $course_id] = array_values(array_map('strval', $terms));
        }
        $existing = get_term_by('slug', self::MASTERCLASSES_SLUG, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY);
        return array(
            'created_term' => !$existing instanceof WP_Term,
            'term_id' => $existing instanceof WP_Term ? (int) $existing->term_id : 0,
            'previous' => $previous,
        );
    }

    private function apply_course_collection($manifest, &$state) {
        $collection_state = (array) $state['course_collection'];
        $term_id = (int) ($collection_state['term_id'] ?? 0);
        if ($term_id <= 0) {
            $created = wp_insert_term('Masterclasses', TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, array(
                'slug' => self::MASTERCLASSES_SLUG,
                'description' => 'In-depth TSOL masterclass courses.',
            ));
            if (is_wp_error($created)) {
                throw new RuntimeException($created->get_error_message());
            }
            $term_id = (int) $created['term_id'];
            $state['course_collection']['term_id'] = $term_id;
            $this->save_state($state);
        }
        foreach ((array) $manifest['courses'] as $course) {
            $course_id = $this->target_id((string) $course['migration_key'], TSOL_Library_Content_Model::COURSE_POST_TYPE);
            $term_ids = 'masterclasses' === (string) $course['collection'] ? array($term_id) : array();
            $assigned = wp_set_object_terms($course_id, $term_ids, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, false);
            if (is_wp_error($assigned)) {
                throw new RuntimeException($assigned->get_error_message());
            }
        }
    }

    private function verify_course_collection($manifest) {
        $term = get_term_by('slug', self::MASTERCLASSES_SLUG, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY);
        if (!$term instanceof WP_Term) {
            throw new RuntimeException('The Masterclasses Collection is missing.');
        }
        $masterclasses = 0;
        foreach ((array) $manifest['courses'] as $course) {
            $course_id = $this->target_id((string) $course['migration_key'], TSOL_Library_Content_Model::COURSE_POST_TYPE);
            $slugs = wp_get_post_terms($course_id, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, array('fields' => 'slugs'));
            if (is_wp_error($slugs)) {
                throw new RuntimeException($slugs->get_error_message());
            }
            $expected = 'masterclasses' === (string) $course['collection'];
            if ($expected !== in_array(self::MASTERCLASSES_SLUG, $slugs, true)) {
                throw new RuntimeException(sprintf('Course %s has the wrong Collection.', $course['migration_key']));
            }
            $masterclasses += $expected ? 1 : 0;
        }
        if (5 !== $masterclasses) {
            throw new RuntimeException('The Masterclasses Collection must contain exactly five imported courses.');
        }
    }

    private function restore_course_collection($collection_state) {
        foreach ((array) ($collection_state['previous'] ?? array()) as $course_id => $slugs) {
            $restored = wp_set_object_terms((int) $course_id, array_values(array_map('strval', (array) $slugs)), TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, false);
            if (is_wp_error($restored)) {
                throw new RuntimeException($restored->get_error_message());
            }
        }
        if (!empty($collection_state['created_term'])) {
            $term = get_term_by('slug', self::MASTERCLASSES_SLUG, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY);
            if ($term instanceof WP_Term) {
                $deleted = wp_delete_term((int) $term->term_id, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY);
                if (is_wp_error($deleted) || false === $deleted) {
                    throw new RuntimeException('The migration-owned Masterclasses Collection could not be removed.');
                }
            }
        }
    }

    private function capture_retired_collections($manifest) {
        $this->ensure_retired_collection_taxonomy();
        $terms = get_terms(array('taxonomy' => self::RETIRED_COLLECTION_TAXONOMY, 'hide_empty' => false));
        if (is_wp_error($terms)) {
            throw new RuntimeException($terms->get_error_message());
        }
        if (empty($terms)) {
            return array();
        }
        $expected_slugs = array_values(array_map('strval', array_column((array) $manifest['collections'], 'slug')));
        sort($expected_slugs, SORT_STRING);
        $actual_slugs = array_values(array_map(static function ($term) { return (string) $term->slug; }, $terms));
        sort($actual_slugs, SORT_STRING);
        if ($expected_slugs !== $actual_slugs) {
            throw new RuntimeException('The retired mixed-content Collections differ from the locked manifest.');
        }
        $captured = array();
        foreach ($terms as $term) {
            $parent = $term->parent > 0 ? get_term((int) $term->parent, self::RETIRED_COLLECTION_TAXONOMY) : null;
            $objects = get_objects_in_term((int) $term->term_id, self::RETIRED_COLLECTION_TAXONOMY);
            if (is_wp_error($objects)) {
                throw new RuntimeException($objects->get_error_message());
            }
            $captured[(string) $term->slug] = array(
                'name' => (string) $term->name,
                'slug' => (string) $term->slug,
                'description' => (string) $term->description,
                'parent_slug' => $parent instanceof WP_Term ? (string) $parent->slug : '',
                'object_ids' => array_values(array_map('intval', $objects)),
            );
        }
        return $captured;
    }

    private function delete_retired_collections($captured) {
        $this->ensure_retired_collection_taxonomy();
        uasort($captured, static function ($left, $right) {
            return ('' !== (string) $left['parent_slug'] ? 0 : 1) <=> ('' !== (string) $right['parent_slug'] ? 0 : 1);
        });
        foreach ($captured as $row) {
            $term = get_term_by('slug', (string) $row['slug'], self::RETIRED_COLLECTION_TAXONOMY);
            if (!$term instanceof WP_Term) {
                continue;
            }
            $deleted = wp_delete_term((int) $term->term_id, self::RETIRED_COLLECTION_TAXONOMY);
            if (is_wp_error($deleted) || false === $deleted) {
                throw new RuntimeException(sprintf('Retired Collection %s could not be removed.', $row['slug']));
            }
        }
    }

    private function restore_retired_collections($captured) {
        $this->ensure_retired_collection_taxonomy();
        uasort($captured, static function ($left, $right) {
            return ('' === (string) $left['parent_slug'] ? 0 : 1) <=> ('' === (string) $right['parent_slug'] ? 0 : 1);
        });
        foreach ($captured as $row) {
            $parent_id = 0;
            if ('' !== (string) $row['parent_slug']) {
                $parent = get_term_by('slug', (string) $row['parent_slug'], self::RETIRED_COLLECTION_TAXONOMY);
                if (!$parent instanceof WP_Term) {
                    throw new RuntimeException(sprintf('Retired Collection parent %s could not be restored.', $row['parent_slug']));
                }
                $parent_id = (int) $parent->term_id;
            }
            $term = get_term_by('slug', (string) $row['slug'], self::RETIRED_COLLECTION_TAXONOMY);
            if (!$term instanceof WP_Term) {
                $inserted = wp_insert_term((string) $row['name'], self::RETIRED_COLLECTION_TAXONOMY, array(
                    'slug' => (string) $row['slug'],
                    'description' => (string) $row['description'],
                    'parent' => $parent_id,
                ));
                if (is_wp_error($inserted)) {
                    throw new RuntimeException($inserted->get_error_message());
                }
                $term = get_term((int) $inserted['term_id'], self::RETIRED_COLLECTION_TAXONOMY);
            }
            foreach ((array) $row['object_ids'] as $object_id) {
                if (get_post((int) $object_id) instanceof WP_Post) {
                    $assigned = wp_set_object_terms((int) $object_id, array((int) $term->term_id), self::RETIRED_COLLECTION_TAXONOMY, true);
                    if (is_wp_error($assigned)) {
                        throw new RuntimeException($assigned->get_error_message());
                    }
                }
            }
        }
    }

    private function ensure_retired_collection_taxonomy() {
        if (!taxonomy_exists(self::RETIRED_COLLECTION_TAXONOMY)) {
            register_taxonomy(self::RETIRED_COLLECTION_TAXONOMY, TSOL_Library_Content_Model::post_types(), array(
                'public' => false, 'show_ui' => false, 'show_in_rest' => false, 'hierarchical' => true, 'rewrite' => false,
            ));
        }
    }

    private function series_summary($series_entries) {
        $summary = array();
        foreach ($series_entries as $key => $entries) {
            $summary[$this->definitions()[$key]['title']] = count($entries);
        }
        return $summary;
    }

    private function course_ids($manifest) {
        $ids = array();
        foreach ((array) $manifest['courses'] as $course) {
            $ids[] = $this->target_id((string) $course['migration_key'], TSOL_Library_Content_Model::COURSE_POST_TYPE);
        }
        return $ids;
    }

    private function target_for_entry($entry) {
        $id = $this->target_id((string) $entry['migration_key'], TSOL_Library_Content_Model::ITEM_POST_TYPE);
        return get_post($id);
    }

    private function target_id($migration_key, $post_type) {
        $posts = get_posts(array(
            'post_type' => $post_type,
            'post_status' => array('draft', 'publish', 'private', 'pending', 'future'),
            'numberposts' => 2,
            'fields' => 'ids',
            'meta_key' => TSOL_Library_Content_Model::META_MIGRATION_KEY,
            'meta_value' => $migration_key,
            'suppress_filters' => true,
        ));
        if (1 !== count($posts)) {
            throw new RuntimeException(sprintf('Expected exactly one normalized target for %s.', $migration_key));
        }
        return (int) $posts[0];
    }

    private function assert_unmodified_episode(WP_Post $post, $entry) {
        if ('draft' !== $post->post_status
            || TSOL_Library_Catalogue_Import::VERSION !== (string) get_post_meta($post->ID, TSOL_Library_Content_Model::META_MIGRATION_VERSION, true)
            || (string) $post->post_title !== (string) $entry['title']
            || (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true) !== (int) $entry['source_id']
            || (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_COURSE_ID, true) > 0
            || (int) get_post_meta($post->ID, TSOL_Library_Content_Model::META_SERIES_ID, true) > 0
        ) {
            throw new RuntimeException(sprintf('Normalized item %s changed before the Series migration.', $entry['migration_key']));
        }
    }

    private function capture_episode_meta($post_id) {
        $captured = array();
        foreach ($this->episode_meta_keys() as $key) {
            $captured[$key] = array('exists' => metadata_exists('post', $post_id, $key), 'value' => get_post_meta($post_id, $key, true));
        }
        return $captured;
    }

    private function restore_episode_meta($post_id, $captured) {
        foreach ($this->episode_meta_keys() as $key) {
            if (!empty($captured[$key]['exists'])) {
                update_post_meta($post_id, $key, $captured[$key]['value']);
            } else {
                delete_post_meta($post_id, $key);
            }
        }
    }

    private function episode_meta_keys() {
        return array(
            TSOL_Library_Content_Model::META_POSITION,
            TSOL_Library_Content_Model::META_SERIES_ID,
            TSOL_Library_Content_Model::META_SERIES_GROUP_KEY,
            TSOL_Library_Content_Model::META_SERIES_GROUP_TITLE,
            TSOL_Library_Content_Model::META_SERIES_GROUP_POSITION,
        );
    }

    private function series_group_registry($entries) {
        $groups = array();
        foreach ((array) $entries as $entry) {
            $key = sanitize_key((string) ($entry['series_group_key'] ?? ''));
            if ('' === $key || isset($groups[$key])) {
                continue;
            }
            $groups[$key] = array(
                'key' => $key,
                'title' => sanitize_text_field((string) ($entry['series_group_title'] ?? '')),
                'position' => max(1, (int) ($entry['series_group_position'] ?? 1)),
            );
        }
        return TSOL_Library_Content_Model::sanitize_structure_registry(array_values($groups));
    }

    private function clean_title($title, $kind) {
        $patterns = array(
            'numbered_session' => '/^Session\s+\d+\s*:\s*/iu',
            'live_event' => '/^Live\s+Event\s*:\s*/iu',
            'unconference_2025' => '/^Unconference\s+2025\s*:\s*/iu',
        );
        $clean = isset($patterns[$kind]) ? trim((string) preg_replace($patterns[$kind], '', (string) $title)) : trim((string) $title);
        return '' !== $clean ? $clean : trim((string) $title);
    }

    private function latest_source($entries) {
        $sources = array_map(static function ($entry) { return get_post((int) $entry['source_id']); }, $entries);
        usort($sources, static function ($left, $right) { return strcmp((string) $right->post_date_gmt, (string) $left->post_date_gmt); });
        return $sources[0];
    }

    private function deterministic_uuid($key) {
        $hex = substr(hash('sha256', self::VERSION . '|' . $key), 0, 32);
        $hex[12] = '4';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    private function series_id($migration_key) {
        $posts = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::SERIES_POST_TYPE,
            'post_status' => array('draft', 'publish', 'private', 'pending', 'future'),
            'numberposts' => 2,
            'fields' => 'ids',
            'meta_key' => TSOL_Library_Content_Model::META_MIGRATION_KEY,
            'meta_value' => $migration_key,
            'suppress_filters' => true,
        ));
        return 1 === count($posts) ? (int) $posts[0] : 0;
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

    private function assert_state($state) {
        if (empty($state) || self::VERSION === (string) ($state['schema_version'] ?? '')) {
            return;
        }
        $is_clean_rollback = 'rolled_back' === (string) ($state['phase'] ?? '')
            && empty($state['series_id'])
            && empty($state['series_ids'])
            && empty($state['episode_ids'])
            && empty($state['episodes']);
        if (!$is_clean_rollback) {
            throw new RuntimeException('The stored Series migration state belongs to another active schema version.');
        }
    }

    private function assert_environment() {
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (self::WORKING_HOST !== $host) {
            throw new RuntimeException(sprintf('The guarded structure migration only runs on %s; current host is %s.', self::WORKING_HOST, $host));
        }
    }

    private function with_lock($callback) {
        if (!add_option(self::LOCK_OPTION, time(), '', 'no')) {
            throw new RuntimeException('Another TSOL Library structure migration operation is already running.');
        }
        try {
            return call_user_func($callback);
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }
}
