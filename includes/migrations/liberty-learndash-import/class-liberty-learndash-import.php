<?php
/** Guarded, rerunnable creation of Liberty's plugin-owned Library drafts. */

if (!defined('ABSPATH')) {
    exit;
}

class Liberty_Classroom_LearnDash_Import {

    const VERSION = '20260830.1';
    const WORKING_HOST = 'libertyclassroom.test';
    const STATE_OPTION = 'liberty_library_learndash_import_state';
    const LOCK_OPTION = 'liberty_library_learndash_import_lock';
    const APPLY_CONFIRMATION = 'create-liberty-library-drafts-from-learndash';
    const PUBLISH_CONFIRMATION = 'publish-verified-liberty-library-import';
    const ROLLBACK_CONFIRMATION = 'remove-untouched-liberty-library-import-drafts';

    public function preview() {
        $manifest = $this->manifest();
        return array(
            'schema_version' => self::VERSION,
            'source_fingerprint' => $manifest['source_fingerprint'],
            'target_status' => 'draft',
            'expected' => array(
                'courses' => count($manifest['courses']),
                'content' => (int) $manifest['lesson_count'],
                'speakers' => count($manifest['speakers']),
                'series' => 0,
                'collections' => count($manifest['collections']),
                'access_groups' => count($manifest['access']['groups']),
            ),
            'media' => $manifest['media_summary'],
            'access' => array(
                'group_course_counts' => array_map(static function ($group) {
                    return count($group['source_course_ids']);
                }, $manifest['access']['groups']),
                'membership_assignments' => array_map(static function ($group) {
                    return $group['membership_slug'];
                }, $manifest['access']['groups']),
                'unassigned_memberships' => $manifest['access']['unassigned_memberships'],
            ),
            'excluded_published_lessons' => $manifest['excluded_lessons'],
            'source_mutations' => 0,
            'memberpress_mutations' => 0,
        );
    }

    public function status() {
        $state = $this->state();
        return array(
            'schema_version' => self::VERSION,
            'phase' => (string) ($state['phase'] ?? 'not_started'),
            'created_posts' => count((array) ($state['created_post_ids'] ?? array())),
            'created_terms' => count((array) ($state['created_term_ids'] ?? array())),
            'targets' => $this->target_counts(),
        );
    }

    public function apply() {
        $this->assert_write_environment();
        return $this->with_lock(function () {
            $manifest = $this->manifest();
            $state = $this->state();
            $this->assert_state($state, $manifest);
            if (in_array((string) ($state['phase'] ?? ''), array('applied', 'published'), true)) {
                return $this->verify();
            }
            if (!empty($this->all_library_post_ids())) {
                throw new RuntimeException('Library records already exist outside this migration; no content was changed.');
            }
            if (null !== get_option(MemberLibrary_Access_Groups::OPTION_NAME, null)
                || null !== get_option(MemberLibrary_Access_Groups::STAGE_OPTION, null)
            ) {
                throw new RuntimeException('Access Groups already contain configuration or a staged rule set; no content was changed.');
            }

            $state = array(
                'schema_version' => self::VERSION,
                'source_fingerprint' => $manifest['source_fingerprint'],
                'phase' => 'applying',
                'created_post_ids' => array(),
                'created_term_ids' => array(),
                'started_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            );
            $this->save_state($state);

            try {
                $term_ids = $this->create_collections($manifest, $state);
                $speaker_ids = $this->create_speakers($manifest, $state);
                $course_ids = array();
                $transition = array();
                foreach ($manifest['courses'] as $course) {
                    $course_id = $this->create_course($course, $speaker_ids, $term_ids, $state);
                    $course_ids[(int) $course['source_id']] = $course_id;
                    $transition[$course_id] = (int) $course['source_id'];
                    foreach ($course['lessons'] as $lesson) {
                        $item_id = $this->create_lesson($lesson, $course, $course_id, $state);
                        $transition[$item_id] = (int) $course['source_id'];
                    }
                }
                $this->create_access_groups($manifest, $course_ids, $transition);
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
        $this->assert_write_environment();
        $manifest = $this->manifest();
        $state = $this->state();
        $this->assert_state($state, $manifest);
        $phase = (string) ($state['phase'] ?? '');
        if (!in_array($phase, array('applied', 'published'), true)) {
            throw new RuntimeException('The Liberty LearnDash migration is not applied or published.');
        }
        $target_status = 'published' === $phase ? 'publish' : 'draft';
        $counts = $this->target_counts();
        if (Liberty_Classroom_LearnDash_Manifest::EXPECTED_COURSES !== $counts['courses']
            || Liberty_Classroom_LearnDash_Manifest::EXPECTED_LESSONS !== $counts['content']
            || Liberty_Classroom_LearnDash_Manifest::EXPECTED_SPEAKERS !== $counts['speakers']
            || 0 !== $counts['series']
        ) {
            throw new RuntimeException('The imported target counts do not match the locked manifest.');
        }
        foreach ($this->migration_post_ids() as $post_id) {
            if ($target_status !== get_post_status($post_id)) {
                throw new RuntimeException(sprintf('Imported target %d has an unexpected publication status.', $post_id));
            }
        }

        $media = array('video_primary' => 0, 'audio_primary' => 0, 'items_with_audio' => 0);
        foreach ($manifest['courses'] as $course) {
            $course_id = $this->target_id($course['migration_key'], MemberLibrary_Content_Model::COURSE_POST_TYPE);
            $this->verify_target($course_id, $course, $target_status);
            if ((int) get_post_thumbnail_id($course_id) !== (int) $course['thumbnail_id']) {
                throw new RuntimeException(sprintf('Course %s no longer has the source artwork.', $course['title']));
            }
            if ((int) get_post_meta($course_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, true) !== (int) $course['source_id']) {
                throw new RuntimeException(sprintf('Course %s lost its transition authorization.', $course['title']));
            }
            if (count(MemberLibrary_Content_Model::direct_speaker_ids($course_id)) !== count($course['speaker_slugs'])) {
                throw new RuntimeException(sprintf('Course %s has the wrong Speaker count.', $course['title']));
            }
            $items = get_posts(array(
                'post_type' => MemberLibrary_Content_Model::ITEM_POST_TYPE,
                'post_status' => $target_status,
                'posts_per_page' => -1,
                'fields' => 'ids',
                'meta_key' => MemberLibrary_Content_Model::META_COURSE_ID,
                'meta_value' => $course_id,
                'suppress_filters' => true,
            ));
            usort($items, static function ($left_id, $right_id) {
                return (int) get_post_meta($left_id, MemberLibrary_Content_Model::META_POSITION, true)
                    <=> (int) get_post_meta($right_id, MemberLibrary_Content_Model::META_POSITION, true);
            });
            if (count($items) !== count($course['lessons'])) {
                throw new RuntimeException(sprintf('Course %s has the wrong lesson count.', $course['title']));
            }
            foreach ($course['lessons'] as $index => $lesson) {
                $item_id = (int) $items[$index];
                $this->verify_target($item_id, $lesson, $target_status);
                if ((int) get_post_meta($item_id, MemberLibrary_Content_Model::META_POSITION, true) !== (int) $lesson['position']
                    || (int) get_post_meta($item_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, true) !== (int) $course['source_id']
                ) {
                    throw new RuntimeException(sprintf('Lesson %s lost its order or Course authorization.', $lesson['title']));
                }
                $assets = MemberLibrary_Content_Model::sanitize_media_assets(
                    get_post_meta($item_id, MemberLibrary_Content_Model::META_MEDIA_ASSETS, true)
                );
                if (empty($assets) && empty($lesson['resource_only'])) {
                    throw new RuntimeException(sprintf('Lesson %s has no normalized media.', $lesson['title']));
                }
                if (!empty($assets)) {
                    $media[(string) $assets[0]['kind'] . '_primary']++;
                    $media['items_with_audio'] += !empty(array_filter($assets, static function ($asset) {
                        return 'audio' === (string) ($asset['kind'] ?? '');
                    })) ? 1 : 0;
                }
                if (preg_match('/<(?:video|audio)\b/i', (string) get_post_field('post_content', $item_id))) {
                    throw new RuntimeException(sprintf('Lesson %s retained duplicate legacy player markup.', $lesson['title']));
                }
                $catalogue_record = MemberLibrary_Content_Catalogue::record($item_id);
                if (is_wp_error($catalogue_record)
                    || '' === trim((string) ($catalogue_record['course']['section']['title'] ?? ''))
                ) {
                    throw new RuntimeException(sprintf('Lesson %s has an invalid catalogue section.', $lesson['title']));
                }
            }
        }

        $access = new MemberLibrary_Access_Groups();
        if (!$access->is_bootstrapped()) {
            throw new RuntimeException('The imported Access Groups draft is missing.');
        }
        $groups = $access->groups();
        $configuration = $access->configuration();
        $membership_ids = array();
        foreach ($access->memberships() as $membership) {
            $membership_ids[sanitize_title((string) $membership->post_name)] = (int) $membership->ID;
        }
        foreach ($manifest['access']['groups'] as $group_id => $group) {
            if (!isset($groups[$group_id]) || count($groups[$group_id]['scopes']) !== count($group['source_course_ids'])) {
                throw new RuntimeException(sprintf('Access Group %s does not match its LearnDash matrix.', $group['name']));
            }
            $membership_id = (int) ($membership_ids[$group['membership_slug']] ?? 0);
            if (!$membership_id || array($group_id) !== array_values((array) ($configuration['assignments'][$membership_id] ?? array()))) {
                throw new RuntimeException(sprintf('Access Group %s is not assigned to its exact MemberPress membership.', $group['name']));
            }
        }
        if (count((array) ($configuration['assignments'] ?? array())) !== count($manifest['access']['groups'])) {
            throw new RuntimeException('The imported Access Groups draft contains unexpected membership assignments.');
        }
        return array(
            'schema_version' => self::VERSION,
            'phase' => $phase,
            'source_fingerprint' => $manifest['source_fingerprint'],
            'targets' => $counts,
            'collections' => count($manifest['collections']),
            'access_group_course_counts' => array_map(static function ($group) {
                return count($group['source_course_ids']);
            }, $manifest['access']['groups']),
            'media' => $media,
            'target_status' => $target_status,
            'source_unchanged' => true,
            'access_groups_status' => 'draft',
            'memberpress_rules_created' => 0,
        );
    }

    public function publish() {
        $this->assert_write_environment();
        return $this->with_lock(function () {
            $manifest = $this->manifest();
            $state = $this->state();
            $this->assert_state($state, $manifest);
            if ('published' === (string) ($state['phase'] ?? '')) {
                return $this->verify();
            }
            if ('applied' !== (string) ($state['phase'] ?? '')) {
                throw new RuntimeException('Only a fully verified draft migration can be published.');
            }
            $this->verify();

            $post_ids = $this->migration_post_ids();
            usort($post_ids, static function ($left_id, $right_id) {
                $priority = array(
                    MemberLibrary_Content_Model::SPEAKER_POST_TYPE => 0,
                    MemberLibrary_Content_Model::COURSE_POST_TYPE => 1,
                    MemberLibrary_Content_Model::SERIES_POST_TYPE => 2,
                    MemberLibrary_Content_Model::ITEM_POST_TYPE => 3,
                );
                $left_priority = (int) ($priority[get_post_type($left_id)] ?? 99);
                $right_priority = (int) ($priority[get_post_type($right_id)] ?? 99);
                return $left_priority === $right_priority ? ($left_id <=> $right_id) : ($left_priority <=> $right_priority);
            });
            $state['phase'] = 'publishing';
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);
            foreach ($post_ids as $post_id) {
                $updated = wp_update_post(array('ID' => (int) $post_id, 'post_status' => 'publish'), true);
                if (is_wp_error($updated)) {
                    $state['phase'] = 'publish_failed';
                    $state['failure'] = $updated->get_error_message();
                    $state['updated_at'] = gmdate('c');
                    $this->save_state($state);
                    throw new RuntimeException($updated->get_error_message());
                }
            }
            $state['phase'] = 'published';
            $state['published_at'] = gmdate('c');
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);
            return $this->verify();
        });
    }

    public function rollback() {
        $this->assert_write_environment();
        return $this->with_lock(function () {
            $manifest = $this->manifest();
            $state = $this->state();
            $this->assert_state($state, $manifest);
            if (!in_array((string) ($state['phase'] ?? ''), array('applied', 'failed', 'rolling_back'), true)) {
                throw new RuntimeException('There is no applied or failed Liberty migration to roll back.');
            }
            $post_ids = array_values(array_unique(array_map('intval', (array) ($state['created_post_ids'] ?? array()))));
            foreach ($post_ids as $post_id) {
                $post = get_post($post_id);
                if ($post instanceof WP_Post && ('draft' !== $post->post_status
                    || self::VERSION !== (string) get_post_meta($post_id, MemberLibrary_Content_Model::META_MIGRATION_VERSION, true))) {
                    throw new RuntimeException(sprintf('Target %d was edited or published; rollback stopped.', $post_id));
                }
            }
            $state['phase'] = 'rolling_back';
            $this->save_state($state);
            foreach (array_reverse($post_ids) as $post_id) {
                if (get_post($post_id) instanceof WP_Post && !wp_delete_post($post_id, true)) {
                    throw new RuntimeException(sprintf('Could not remove target %d.', $post_id));
                }
            }
            foreach (array_reverse(array_map('intval', (array) ($state['created_term_ids'] ?? array()))) as $term_id) {
                $term = get_term($term_id, MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY);
                if ($term instanceof WP_Term) {
                    $deleted = wp_delete_term($term_id, MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY);
                    if (is_wp_error($deleted) || false === $deleted) {
                        throw new RuntimeException(sprintf('Could not remove Collection %d.', $term_id));
                    }
                }
            }
            delete_option(MemberLibrary_Access_Groups::OPTION_NAME);
            delete_option(MemberLibrary_Access_Groups::STAGE_OPTION);
            $state['phase'] = 'rolled_back';
            $state['created_post_ids'] = array();
            $state['created_term_ids'] = array();
            $state['rolled_back_at'] = gmdate('c');
            $this->save_state($state);
            return array('phase' => 'rolled_back', 'removed_posts' => count($post_ids), 'targets' => $this->target_counts(), 'source_unchanged' => true);
        });
    }

    private function create_collections($manifest, &$state) {
        $term_ids = array();
        foreach ($manifest['collections'] as $slug => $collection) {
            if (get_term_by('slug', $slug, MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY) instanceof WP_Term) {
                throw new RuntimeException(sprintf('Collection %s already exists outside this migration.', $slug));
            }
            $created = wp_insert_term($collection['name'], MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY, array(
                'slug' => $slug,
                'description' => $collection['description'],
            ));
            if (is_wp_error($created)) {
                throw new RuntimeException($created->get_error_message());
            }
            $term_ids[$slug] = (int) $created['term_id'];
            $state['created_term_ids'][] = (int) $created['term_id'];
            $this->save_state($state);
        }
        return $term_ids;
    }

    private function create_speakers($manifest, &$state) {
        $ids = array();
        foreach ($manifest['speakers'] as $slug => $speaker) {
            $ids[$slug] = $this->create_post(array(
                'post_type' => MemberLibrary_Content_Model::SPEAKER_POST_TYPE,
                'post_status' => 'draft',
                'post_title' => $speaker['name'],
                'post_name' => $speaker['slug'],
                'post_content' => '',
                'post_excerpt' => '',
            ), array(
                MemberLibrary_Content_Model::META_MIGRATION_KEY => $speaker['migration_key'],
                MemberLibrary_Content_Model::META_MIGRATION_VERSION => self::VERSION,
                MemberLibrary_Content_Model::META_LEGACY_SOURCE_ID => (int) $speaker['source_id'],
                MemberLibrary_Content_Model::META_LEGACY_SOURCE_TYPE => 'ld_course_tag',
                MemberLibrary_Content_Model::SPEAKER_META_UUID => $this->uuid($speaker['migration_key']),
            ), $state);
        }
        return $ids;
    }

    private function create_course($course, $speaker_ids, $term_ids, &$state) {
        $meta = $this->base_meta($course, (int) $course['source_id'], 'course');
        $meta[MemberLibrary_Content_Model::META_POSITION] = (int) $course['position'];
        $meta[MemberLibrary_Content_Model::META_SPEAKER_MODE] = MemberLibrary_Content_Model::SPEAKER_MODE_DIRECT;
        $meta[MemberLibrary_Content_Model::META_COURSE_SECTIONS] = array();
        $meta[MemberLibrary_Content_Model::META_AI_ASSISTANT_ENABLED] = false;
        $course_id = $this->create_post($this->post_data($course, MemberLibrary_Content_Model::COURSE_POST_TYPE), $meta, $state);
        $this->copy_thumbnail($course['thumbnail_id'], $course_id);
        foreach ($course['speaker_slugs'] as $speaker_slug) {
            add_post_meta($course_id, MemberLibrary_Content_Model::META_SPEAKER_IDS, (int) $speaker_ids[$speaker_slug], false);
        }
        $term_id = (int) ($term_ids[$course['collection_slug']] ?? 0);
        $assigned = wp_set_object_terms($course_id, array($term_id), MemberLibrary_Content_Model::COURSE_COLLECTION_TAXONOMY, false);
        if (!$term_id || is_wp_error($assigned)) {
            throw new RuntimeException(sprintf('Could not assign Course %s to its Collection.', $course['title']));
        }
        return $course_id;
    }

    private function create_lesson($lesson, $course, $course_id, &$state) {
        $meta = $this->base_meta($lesson, (int) $course['source_id'], 'lesson');
        $meta[MemberLibrary_Content_Model::META_POSITION] = (int) $lesson['position'];
        $meta[MemberLibrary_Content_Model::META_COURSE_ID] = (int) $course_id;
        $meta[MemberLibrary_Content_Model::META_SPEAKER_MODE] = MemberLibrary_Content_Model::SPEAKER_MODE_INHERIT;
        $meta[MemberLibrary_Content_Model::META_MEDIA_ASSETS] = $lesson['media_assets'];
        $meta[MemberLibrary_Content_Model::META_RESOURCES] = $lesson['resources'];
        $meta[MemberLibrary_Content_Model::META_AVAILABILITY] = MemberLibrary_Content_Model::AVAILABILITY_AVAILABLE;
        $item_id = $this->create_post($this->post_data($lesson, MemberLibrary_Content_Model::ITEM_POST_TYPE), $meta, $state);
        $this->copy_thumbnail($lesson['thumbnail_id'], $item_id);
        return $item_id;
    }

    private function create_access_groups($manifest, $course_ids, $transition) {
        $groups = array();
        $assignments = array();
        foreach ($manifest['access']['groups'] as $group_id => $group) {
            $scopes = array();
            foreach ($group['source_course_ids'] as $source_id) {
                $target_id = (int) ($course_ids[(int) $source_id] ?? 0);
                $uuid = (string) get_post_meta($target_id, MemberLibrary_Content_Model::META_UUID, true);
                if (!$target_id || '' === $uuid) {
                    throw new RuntimeException(sprintf('Access Group %s references a missing migrated Course.', $group['name']));
                }
                $scopes[] = 'course:' . sanitize_key($uuid);
            }
            sort($scopes, SORT_STRING);
            $groups[] = array('id' => $group_id, 'name' => $group['name'], 'description' => $group['description'], 'scopes' => $scopes);
            $assignments[$group['membership_slug']] = array($group_id);
        }
        (new MemberLibrary_Access_Groups())->import_portable_configuration($groups, $assignments, array(), $transition);
    }

    private function base_meta($entry, $authorization_id, $content_type) {
        return array(
            MemberLibrary_Content_Model::META_INCLUDE => true,
            MemberLibrary_Content_Model::META_CONTENT_TYPE => $content_type,
            MemberLibrary_Content_Model::META_POSITION => 0,
            MemberLibrary_Content_Model::META_FEATURED => false,
            MemberLibrary_Content_Model::META_MEDIA_ASSETS => array(),
            MemberLibrary_Content_Model::META_RESOURCES => array(),
            MemberLibrary_Content_Model::META_MIGRATION_KEY => $entry['migration_key'],
            MemberLibrary_Content_Model::META_MIGRATION_VERSION => self::VERSION,
            MemberLibrary_Content_Model::META_UUID => $this->uuid($entry['migration_key']),
            MemberLibrary_Content_Model::META_LEGACY_SOURCE_ID => (int) $entry['source_id'],
            MemberLibrary_Content_Model::META_LEGACY_SOURCE_TYPE => $entry['source_type'],
            MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID => $authorization_id,
            MemberLibrary_Content_Model::META_SOURCE_MODIFIED_GMT => $entry['post_modified_gmt'],
            MemberLibrary_Content_Model::META_CONTENT_FINGERPRINT => $entry['source_fingerprint'],
            MemberLibrary_Content_Model::META_COURSE_ID => 0,
            MemberLibrary_Content_Model::META_SERIES_ID => 0,
            MemberLibrary_Content_Model::META_SECTION_KEY => '',
            MemberLibrary_Content_Model::META_SECTION_TITLE => '',
            MemberLibrary_Content_Model::META_SECTION_POSITION => 0,
        );
    }

    private function post_data($entry, $post_type) {
        return array(
            'post_type' => $post_type,
            'post_status' => 'draft',
            'post_title' => $entry['title'],
            'post_name' => $entry['slug'],
            'post_content' => $entry['post_content'],
            'post_excerpt' => $entry['post_excerpt'],
            'post_author' => (int) $entry['post_author'],
            'post_date' => $entry['post_date'],
            'post_date_gmt' => $entry['post_date_gmt'],
        );
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

    private function verify_target($target_id, $entry, $target_status) {
        $post = get_post($target_id);
        if (!$post instanceof WP_Post || (string) $target_status !== $post->post_status
            || self::VERSION !== (string) get_post_meta($target_id, MemberLibrary_Content_Model::META_MIGRATION_VERSION, true)
            || (int) $entry['source_id'] !== (int) get_post_meta($target_id, MemberLibrary_Content_Model::META_LEGACY_SOURCE_ID, true)
            || (string) $entry['source_fingerprint'] !== (string) get_post_meta($target_id, MemberLibrary_Content_Model::META_CONTENT_FINGERPRINT, true)
        ) {
            throw new RuntimeException(sprintf('Target %s lost its source identity.', $entry['migration_key']));
        }
    }

    private function copy_thumbnail($thumbnail_id, $target_id) {
        if ((int) $thumbnail_id > 0 && !set_post_thumbnail((int) $target_id, (int) $thumbnail_id)) {
            throw new RuntimeException(sprintf('Could not copy attachment %d to target %d.', $thumbnail_id, $target_id));
        }
    }

    private function uuid($key) {
        $hex = substr(hash('sha256', 'liberty-classroom-library|' . $key), 0, 32);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    private function target_id($migration_key, $post_type) {
        $ids = get_posts(array(
            'post_type' => $post_type,
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => MemberLibrary_Content_Model::META_MIGRATION_KEY,
            'meta_value' => $migration_key,
            'suppress_filters' => true,
        ));
        if (1 !== count($ids)) {
            throw new RuntimeException(sprintf('Migration key %s does not resolve to exactly one target.', $migration_key));
        }
        return (int) $ids[0];
    }

    private function target_counts() {
        $counts = array('courses' => 0, 'content' => 0, 'speakers' => 0, 'series' => 0);
        foreach ($this->migration_post_ids() as $post_id) {
            $type = get_post_type($post_id);
            if (MemberLibrary_Content_Model::COURSE_POST_TYPE === $type) {
                $counts['courses']++;
            } elseif (MemberLibrary_Content_Model::ITEM_POST_TYPE === $type) {
                $counts['content']++;
            } elseif (MemberLibrary_Content_Model::SPEAKER_POST_TYPE === $type) {
                $counts['speakers']++;
            } elseif (MemberLibrary_Content_Model::SERIES_POST_TYPE === $type) {
                $counts['series']++;
            }
        }
        return $counts;
    }

    private function migration_post_ids() {
        return array_map('intval', get_posts(array(
            'post_type' => array_merge(MemberLibrary_Content_Model::post_types(), array(MemberLibrary_Content_Model::SPEAKER_POST_TYPE)),
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => MemberLibrary_Content_Model::META_MIGRATION_VERSION,
            'meta_value' => self::VERSION,
            'suppress_filters' => true,
        )));
    }

    private function all_library_post_ids() {
        return array_map('intval', get_posts(array(
            'post_type' => array_merge(MemberLibrary_Content_Model::post_types(), array(MemberLibrary_Content_Model::SPEAKER_POST_TYPE)),
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        )));
    }

    private function manifest() {
        return (new Liberty_Classroom_LearnDash_Manifest())->build();
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
        if (self::VERSION !== (string) ($state['schema_version'] ?? '')
            || (string) $manifest['source_fingerprint'] !== (string) ($state['source_fingerprint'] ?? '')
        ) {
            throw new RuntimeException('The stored migration state belongs to another version or changed LearnDash source.');
        }
    }

    private function assert_write_environment() {
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (self::WORKING_HOST !== $host) {
            throw new RuntimeException(sprintf('Migration writes are allowed only on %s.', self::WORKING_HOST));
        }
        foreach (array_merge(MemberLibrary_Content_Model::post_types(), array(MemberLibrary_Content_Model::SPEAKER_POST_TYPE)) as $post_type) {
            if (!post_type_exists($post_type)) {
                throw new RuntimeException(sprintf('Required Library post type %s is unavailable.', $post_type));
            }
        }
    }

    private function with_lock($callback) {
        if (!add_option(self::LOCK_OPTION, time(), '', 'no')) {
            throw new RuntimeException('Another Liberty migration process holds the lock.');
        }
        try {
            return call_user_func($callback);
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }
}
