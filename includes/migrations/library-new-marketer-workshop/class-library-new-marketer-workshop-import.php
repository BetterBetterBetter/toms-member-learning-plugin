<?php
/**
 * Additive, guarded import for the legacy New Marketer Workshop page.
 *
 * The legacy page and its custom-URI MemberPress rule remain untouched. The
 * importer creates one TSOL-owned Course, 52 TSOL-owned lessons, and one
 * native MemberPress Course rule with an exact copy of the legacy conditions.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_New_Marketer_Workshop_Import {

    const VERSION = '20260812.1';
    const WORKING_HOST = 'tomschooloflife.test';
    const SOURCE_POST_ID = 101105;
    const SOURCE_RULE_ID = 100171;
    const SOURCE_URI = '/bonuses/the-new-marketer-workshop/';
    const SOURCE_FINGERPRINT = '0bd7b7f0b297cb320bc80b50dca8bf40de508df9a5a5fce2106ef7ec4f3e602b';
    const SOURCE_RULE_FINGERPRINT = 'c57e90ebc48083e6afef4abd5be9b35be144ef1b421c5446e718f6dd55577d91';
    const EXPECTED_LESSONS = 52;
    const EXPECTED_CONDITIONS = 28;
    const STRUCTURE_VERSION = '20260813.1';
    const LEGACY_STRUCTURE_VERSION = '20260812.1-flat';
    const EDITORIAL_VERSION = '20260820.1';
    const ARTWORK_VERSION = '20260820.3';
    const LEGACY_ARTWORK_VERSION = '20260820.2';
    const SPEAKER_VERSION = '20260820.2';
    const LEGACY_SPEAKER_VERSION = '20260820.1';
    const THUMBNAIL_ASSET = 'assets/images/library/the-new-marketer-workshop-cover.png';
    const THUMBNAIL_REFERENCE_URL = 'https://tomschooloflife.com/wp-content/uploads/2023/01/ziX6cQWE.png';
    const THUMBNAIL_SOURCE_SHA256 = 'def3ed87612bff6bec7923a91cdbba8333904eb84611d75cdee384f4081aad31';
    const SPEAKER_HEADSHOT_ASSET = 'assets/images/library/charles-terrence-harper.png';
    const SPEAKER_HEADSHOT_REFERENCE_URL = 'https://theplrshow.com/wp-content/uploads/2024/05/Charles.png';
    const SPEAKER_HEADSHOT_SOURCE_SHA256 = '7294429055f0fb8061868f74927c92fa9a3ffcdf5085b56032194d4f2dfdcd0c';

    const STATE_OPTION = 'tsol_library_new_marketer_workshop_import_state';
    const LOCK_OPTION = 'tsol_library_new_marketer_workshop_import_lock';
    const APPLY_CONFIRMATION = 'import-new-marketer-workshop-with-exact-legacy-access';
    const RESTRUCTURE_CONFIRMATION = 'split-new-marketer-workshop-into-seven-sections';
    const EDITORIAL_CONFIRMATION = 'apply-canonical-new-marketer-workshop-titles-slugs-and-thumbnail';
    const ROLLBACK_CONFIRMATION = 'remove-unchanged-new-marketer-workshop-import';

    const META_IMPORT_VERSION = '_tsol_library_new_marketer_import_version';
    const META_IMPORT_KEY = '_tsol_library_new_marketer_import_key';
    const POLICY_KEY = 'course:new-marketer-workshop';

    public function preview() {
        $source = $this->source_spec();
        $rule = $this->source_rule_spec();
        return array(
            'schema_version' => self::VERSION,
            'source_post_id' => self::SOURCE_POST_ID,
            'source_rule_id' => self::SOURCE_RULE_ID,
            'source_fingerprint' => $source['fingerprint'],
            'source_rule_fingerprint' => $rule['fingerprint'],
            'course' => array(
                'title' => 'The New Marketer Workshop',
                'slug' => 'the-new-marketer-workshop',
                'sections' => count(self::sections()),
                'section_summary' => array_map(static function ($section) {
                    return array(
                        'title' => (string) $section['title'],
                        'lessons' => (int) $section['end'] - (int) $section['start'] + 1,
                    );
                }, self::sections()),
                'lessons' => count($source['lessons']),
                'course_collections' => 0,
            ),
            'native_memberpress_rule' => array(
                'type' => 'single_' . TSOL_Library_Content_Model::COURSE_POST_TYPE,
                'condition_count' => count($rule['conditions']),
                'source_rule_ids' => array(self::SOURCE_RULE_ID),
                'permission_changes' => 0,
            ),
            'legacy_page_mutations' => 0,
            'legacy_rule_mutations' => 0,
            'target_status' => 'publish',
        );
    }

    public function status() {
        $state = $this->state();
        return array(
            'schema_version' => self::VERSION,
            'phase' => empty($state) ? 'not_started' : (string) ($state['phase'] ?? 'unknown'),
            'created_posts' => count((array) ($state['created_post_ids'] ?? array())),
            'created_rules' => empty($state['created_rule_id']) ? 0 : 1,
            'structure_version' => (string) ($state['structure_version'] ?? self::LEGACY_STRUCTURE_VERSION),
            'editorial_version' => (string) ($state['editorial_version'] ?? ''),
            'artwork_version' => (string) ($state['artwork_version'] ?? ''),
            'speaker_version' => (string) ($state['speaker_version'] ?? ''),
            'targets' => $this->target_counts(),
        );
    }

    public function apply() {
        $this->assert_environment();
        return $this->with_lock(function () {
            $source = $this->source_spec();
            $source_rule = $this->source_rule_spec();
            $state = $this->state();
            $this->assert_state_compatible($state);

            if (!empty($state) && 'applied' === (string) ($state['phase'] ?? '')) {
                return $this->verify();
            }
            if (!empty($this->target_ids()) || !empty($this->owned_rule_ids())) {
                throw new RuntimeException('New Marketer Workshop targets exist outside an applied importer state.');
            }

            $state = array(
                'schema_version' => self::VERSION,
                'phase' => 'applying',
                'source_fingerprint' => $source['fingerprint'],
                'source_rule_fingerprint' => $source_rule['fingerprint'],
                'created_post_ids' => array(),
                'created_rule_id' => 0,
                'started_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            );
            $this->save_state($state);

            try {
                $course_id = $this->create_course($source, $state);
                foreach ($source['lessons'] as $position => $lesson) {
                    $this->create_lesson($source, $lesson, $position + 1, $course_id, $state);
                }
                $rule_id = $this->create_rule($source_rule, $course_id);
                $state['created_rule_id'] = $rule_id;
                $state['course_id'] = $course_id;
                $state['updated_at'] = gmdate('c');
                $this->save_state($state);

                $this->assert_targets($source, $course_id, 'draft');
                $this->assert_native_rule($source_rule, $course_id, $rule_id, 'draft');

                $this->publish_post($rule_id);
                foreach ($this->lesson_ids($course_id) as $lesson_id) {
                    $this->publish_post($lesson_id);
                }
                // Publish the Course last so the catalogue never exposes an
                // unprotected or incomplete parent curriculum.
                $this->publish_post($course_id);
                $this->clear_memberpress_rule_cache();

                $state['phase'] = 'applied';
                $state['structure_version'] = self::STRUCTURE_VERSION;
                $state['target_fingerprint'] = $this->target_fingerprint($state['created_post_ids']);
                $state['applied_at'] = gmdate('c');
                $state['updated_at'] = gmdate('c');
                $this->save_state($state);
            } catch (Throwable $exception) {
                $state['phase'] = 'failed';
                $state['failure'] = $exception->getMessage();
                $state['updated_at'] = gmdate('c');
                $this->save_state($state);
                throw $exception;
            }

            return $this->verify();
        });
    }

    public function verify() {
        $this->assert_environment();
        $source = $this->source_spec();
        $source_rule = $this->source_rule_spec();
        $state = $this->state();
        $this->assert_state_compatible($state);
        if ('applied' !== (string) ($state['phase'] ?? '')) {
            throw new RuntimeException('The New Marketer Workshop import is not applied.');
        }
        if (self::STRUCTURE_VERSION !== (string) ($state['structure_version'] ?? self::LEGACY_STRUCTURE_VERSION)) {
            throw new RuntimeException('The New Marketer Workshop needs the guarded seven-section structure migration.');
        }

        $course_id = (int) ($state['course_id'] ?? 0);
        $rule_id = (int) ($state['created_rule_id'] ?? 0);
        $editorial_version = (string) ($state['editorial_version'] ?? '');
        $artwork_version = (string) ($state['artwork_version'] ?? '');
        $speaker_version = (string) ($state['speaker_version'] ?? '');
        if (!in_array($editorial_version, array('', self::EDITORIAL_VERSION), true)) {
            throw new RuntimeException('The stored workshop editorial state belongs to an unknown version.');
        }
        if (!in_array($artwork_version, array('', self::LEGACY_ARTWORK_VERSION, self::ARTWORK_VERSION), true)) {
            throw new RuntimeException('The stored workshop artwork state belongs to an unknown version.');
        }
        if (!in_array($speaker_version, array('', self::LEGACY_SPEAKER_VERSION, self::SPEAKER_VERSION), true)) {
            throw new RuntimeException('The stored workshop speaker state belongs to an unknown version.');
        }
        $this->assert_targets($source, $course_id, 'publish', null, '' === $editorial_version ? 'ignore' : 'canonical');
        if (self::ARTWORK_VERSION === $artwork_version) {
            $this->assert_canonical_thumbnail($course_id);
        }
        if (self::SPEAKER_VERSION === $speaker_version) {
            $this->assert_canonical_speaker($course_id, (int) ($state['created_speaker_id'] ?? 0));
        }
        $this->assert_native_rule($source_rule, $course_id, $rule_id, 'publish');

        $target_ids = $this->target_ids();
        if (count($target_ids) !== self::EXPECTED_LESSONS + 1
            || $this->target_fingerprint($target_ids) !== (string) ($state['target_fingerprint'] ?? '')
        ) {
            throw new RuntimeException('An imported New Marketer Workshop target changed after publication.');
        }

        $matrix = $this->access_matrix($source_rule['conditions'], $course_id, $target_ids);
        if ($matrix['allow_to_deny'] > 0 || $matrix['deny_to_allow'] > 0) {
            throw new RuntimeException('The native New Marketer Workshop rule is not exactly access-equivalent to the legacy rule.');
        }

        return array(
            'schema_version' => self::VERSION,
            'phase' => 'applied',
            'structure_version' => self::STRUCTURE_VERSION,
            'editorial_version' => $editorial_version,
            'artwork_version' => $artwork_version,
            'speaker_version' => $speaker_version,
            'speaker_id' => (int) ($state['created_speaker_id'] ?? 0),
            'source_fingerprint' => $source['fingerprint'],
            'source_rule_fingerprint' => $source_rule['fingerprint'],
            'normalized' => $this->target_counts(),
            'authorization_post_id' => $course_id,
            'native_rule_count' => 1,
            'native_rule_condition_count' => count($source_rule['conditions']),
            'access_matrix' => $matrix,
            'legacy_page_unchanged' => true,
            'legacy_rule_unchanged' => true,
            'permission_changes' => 0,
            'identities_emitted' => 0,
        );
    }

    public function editorialize() {
        $this->assert_environment();
        return $this->with_lock(function () {
            $source = $this->source_spec();
            $source_rule = $this->source_rule_spec();
            $state = $this->state();
            $this->assert_state_compatible($state);
            if ('applied' !== (string) ($state['phase'] ?? '')) {
                throw new RuntimeException('The New Marketer Workshop import is not applied.');
            }
            if (self::STRUCTURE_VERSION !== (string) ($state['structure_version'] ?? self::LEGACY_STRUCTURE_VERSION)) {
                throw new RuntimeException('The New Marketer Workshop needs the guarded seven-section structure migration first.');
            }

            $editorial_version = (string) ($state['editorial_version'] ?? '');
            $artwork_version = (string) ($state['artwork_version'] ?? '');
            $speaker_version = (string) ($state['speaker_version'] ?? '');
            if (self::EDITORIAL_VERSION === $editorial_version
                && self::ARTWORK_VERSION === $artwork_version
                && self::SPEAKER_VERSION === $speaker_version
            ) {
                return $this->verify();
            }
            if ('' !== $editorial_version) {
                if (self::EDITORIAL_VERSION !== $editorial_version) {
                    throw new RuntimeException('The stored workshop editorial state belongs to an unknown version.');
                }
            }
            if (!in_array($artwork_version, array('', self::LEGACY_ARTWORK_VERSION, self::ARTWORK_VERSION), true)) {
                throw new RuntimeException('The stored workshop artwork state belongs to an unknown version.');
            }
            if (!in_array($speaker_version, array('', self::LEGACY_SPEAKER_VERSION, self::SPEAKER_VERSION), true)) {
                throw new RuntimeException('The stored workshop speaker state belongs to an unknown version.');
            }

            $course_id = (int) ($state['course_id'] ?? 0);
            $rule_id = (int) ($state['created_rule_id'] ?? 0);
            // Titles and slugs are the explicit scope of this forward editorial
            // migration. Guard every structural, media, provenance, and access
            // invariant before superseding any earlier title-only edits.
            $this->assert_targets(
                $source,
                $course_id,
                'publish',
                null,
                '' === $editorial_version ? 'ignore' : 'canonical'
            );
            $this->assert_native_rule($source_rule, $course_id, $rule_id, 'publish');

            $lesson_ids = $this->lesson_ids($course_id);
            $snapshots = array();
            $thumbnail_id = 0;
            $previous_thumbnail_id = (int) get_post_thumbnail_id($course_id);
            $speaker_id = 0;
            $speaker_headshot_id = 0;
            $previous_speaker_headshot_id = 0;
            $previous_speaker_profile_version = $speaker_version;
            $speaker_created = false;
            $previous_speaker_ids = TSOL_Library_Content_Model::direct_speaker_ids($course_id);
            $needs_artwork = in_array($artwork_version, array('', self::LEGACY_ARTWORK_VERSION), true);
            $needs_speaker = in_array($speaker_version, array('', self::LEGACY_SPEAKER_VERSION), true);
            try {
                if ('' === $editorial_version) {
                    foreach ($lesson_ids as $index => $lesson_id) {
                        $lesson = get_post($lesson_id);
                        $snapshots[$lesson_id] = array(
                            'post_title' => (string) $lesson->post_title,
                            'post_name' => (string) $lesson->post_name,
                        );
                        $title = self::canonical_titles()[$index];
                        $updated = wp_update_post(array(
                            'ID' => $lesson_id,
                            'post_title' => $title,
                            'post_name' => sanitize_title($title),
                        ), true);
                        if (is_wp_error($updated)) {
                            throw new RuntimeException($updated->get_error_message());
                        }
                    }
                }

                if ($needs_artwork) {
                    $thumbnail_id = $this->create_canonical_thumbnail($course_id);
                }
                if ($needs_speaker) {
                    if ('' === $speaker_version) {
                        $speaker = $this->create_canonical_speaker($course_id);
                        $speaker_created = true;
                    } else {
                        $speaker = $this->refresh_canonical_speaker_headshot(
                            $course_id,
                            (int) ($state['created_speaker_id'] ?? 0)
                        );
                        $previous_speaker_headshot_id = (int) $speaker['previous_headshot_id'];
                    }
                    $speaker_id = (int) $speaker['speaker_id'];
                    $speaker_headshot_id = (int) $speaker['headshot_id'];
                }
                $state['editorial_version'] = self::EDITORIAL_VERSION;
                $state['artwork_version'] = self::ARTWORK_VERSION;
                $state['speaker_version'] = self::SPEAKER_VERSION;
                if ($thumbnail_id > 0) {
                    $state['created_thumbnail_id'] = $thumbnail_id;
                }
                if ($speaker_id > 0) {
                    $state['created_speaker_id'] = $speaker_id;
                    $state['created_speaker_headshot_id'] = $speaker_headshot_id;
                }
                $state['target_fingerprint'] = $this->target_fingerprint($state['created_post_ids']);
                $state['editorialized_at'] = gmdate('c');
                $state['updated_at'] = gmdate('c');
                $this->save_state($state);
                if ($thumbnail_id > 0
                    && $previous_thumbnail_id > 0
                    && $previous_thumbnail_id !== $thumbnail_id
                    && get_post($previous_thumbnail_id) instanceof WP_Post
                ) {
                    wp_delete_attachment($previous_thumbnail_id, true);
                }
                if ($speaker_headshot_id > 0
                    && $previous_speaker_headshot_id > 0
                    && $previous_speaker_headshot_id !== $speaker_headshot_id
                    && get_post($previous_speaker_headshot_id) instanceof WP_Post
                ) {
                    wp_delete_attachment($previous_speaker_headshot_id, true);
                }
            } catch (Throwable $exception) {
                foreach ($snapshots as $lesson_id => $snapshot) {
                    wp_update_post(array(
                        'ID' => (int) $lesson_id,
                        'post_title' => (string) $snapshot['post_title'],
                        'post_name' => (string) $snapshot['post_name'],
                    ));
                }
                if ($thumbnail_id > 0) {
                    if ($previous_thumbnail_id > 0) {
                        set_post_thumbnail($course_id, $previous_thumbnail_id);
                    } else {
                        delete_post_thumbnail($course_id);
                    }
                    wp_delete_attachment($thumbnail_id, true);
                }
                if ($speaker_id > 0) {
                    if ($speaker_created) {
                        delete_post_meta($course_id, TSOL_Library_Content_Model::META_SPEAKER_IDS);
                        foreach ($previous_speaker_ids as $previous_speaker_id) {
                            add_post_meta($course_id, TSOL_Library_Content_Model::META_SPEAKER_IDS, (int) $previous_speaker_id, false);
                        }
                        if ($speaker_headshot_id > 0 && get_post($speaker_headshot_id) instanceof WP_Post) {
                            wp_delete_attachment($speaker_headshot_id, true);
                        }
                        if (get_post($speaker_id) instanceof WP_Post) {
                            wp_delete_post($speaker_id, true);
                        }
                    } else {
                        if ($previous_speaker_headshot_id > 0) {
                            set_post_thumbnail($speaker_id, $previous_speaker_headshot_id);
                        }
                        update_post_meta($speaker_id, '_tsol_library_new_marketer_speaker_version', $previous_speaker_profile_version);
                        if ($speaker_headshot_id > 0 && get_post($speaker_headshot_id) instanceof WP_Post) {
                            wp_delete_attachment($speaker_headshot_id, true);
                        }
                    }
                }
                throw $exception;
            }

            return $this->verify();
        });
    }

    public function restructure() {
        $this->assert_environment();
        return $this->with_lock(function () {
            $source = $this->source_spec();
            $source_rule = $this->source_rule_spec();
            $state = $this->state();
            $this->assert_state_compatible($state);
            if ('applied' !== (string) ($state['phase'] ?? '')) {
                throw new RuntimeException('The New Marketer Workshop import is not applied.');
            }

            $structure_version = (string) ($state['structure_version'] ?? self::LEGACY_STRUCTURE_VERSION);
            if (self::STRUCTURE_VERSION === $structure_version) {
                return $this->verify();
            }
            if (self::LEGACY_STRUCTURE_VERSION !== $structure_version) {
                throw new RuntimeException('The stored workshop structure belongs to an unknown version.');
            }

            $course_id = (int) ($state['course_id'] ?? 0);
            $rule_id = (int) ($state['created_rule_id'] ?? 0);
            // Validate every importer-owned title, media reference, relationship,
            // provenance field, and permission before changing presentation.
            // The historical all-meta fingerprint is deliberately not used here:
            // registered editorial fields were added after the original import,
            // which changes that serialization even when their values are empty.
            $this->assert_targets($source, $course_id, 'publish', self::legacy_sections());
            $this->assert_native_rule($source_rule, $course_id, $rule_id, 'publish');

            $lesson_ids = $this->lesson_ids($course_id);
            $groups = array();
            foreach (self::sections() as $section) {
                $groups[] = array(
                    'key' => (string) $section['key'],
                    'title' => (string) $section['title'],
                    'items' => array_slice(
                        $lesson_ids,
                        (int) $section['start'] - 1,
                        (int) $section['end'] - (int) $section['start'] + 1
                    ),
                );
            }

            $snapshot = TSOL_Library_Structure::snapshot($course_id);
            if (is_wp_error($snapshot)) {
                throw new RuntimeException($snapshot->get_error_message());
            }
            $result = TSOL_Library_Structure::save_display_structure(
                $course_id,
                array('groups' => $groups),
                (string) $snapshot['revision']
            );
            if (is_wp_error($result)) {
                throw new RuntimeException($result->get_error_message());
            }

            $state['structure_version'] = self::STRUCTURE_VERSION;
            $state['target_fingerprint'] = $this->target_fingerprint($state['created_post_ids']);
            $state['restructured_at'] = gmdate('c');
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);

            return $this->verify();
        });
    }

    public function rollback() {
        $this->assert_environment();
        return $this->with_lock(function () {
            $source = $this->source_spec();
            $source_rule = $this->source_rule_spec();
            $state = $this->state();
            $this->assert_state_compatible($state);
            if (!in_array((string) ($state['phase'] ?? ''), array('applied', 'failed', 'rolling_back'), true)) {
                throw new RuntimeException('There is no New Marketer Workshop import to roll back.');
            }

            $target_ids = array_values(array_unique(array_map('intval', (array) ($state['created_post_ids'] ?? array()))));
            $existing_ids = array_values(array_filter($target_ids, static function ($post_id) {
                return get_post($post_id) instanceof WP_Post;
            }));
            if (!empty($existing_ids)
                && !empty($state['target_fingerprint'])
                && $this->target_fingerprint($existing_ids) !== (string) $state['target_fingerprint']
            ) {
                throw new RuntimeException('An imported target was edited; rollback stopped for manual review.');
            }

            $course_id = (int) ($state['course_id'] ?? 0);
            $rule_id = (int) ($state['created_rule_id'] ?? 0);
            if ($rule_id > 0 && get_post($rule_id) instanceof WP_Post) {
                $this->assert_native_rule($source_rule, $course_id, $rule_id, get_post_status($rule_id));
            }

            $state['phase'] = 'rolling_back';
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);

            if ($rule_id > 0 && get_post($rule_id) instanceof WP_Post) {
                global $wpdb;
                $wpdb->delete($wpdb->prefix . 'mepr_rule_access_conditions', array('rule_id' => $rule_id), array('%d'));
                if (!wp_delete_post($rule_id, true)) {
                    throw new RuntimeException('Could not delete the importer-owned MemberPress rule.');
                }
            }
            foreach (array_reverse($existing_ids) as $post_id) {
                if (!wp_delete_post($post_id, true)) {
                    throw new RuntimeException(sprintf('Could not delete importer-owned post %d.', $post_id));
                }
            }
            $thumbnail_id = (int) ($state['created_thumbnail_id'] ?? 0);
            if ($thumbnail_id > 0 && get_post($thumbnail_id) instanceof WP_Post && !wp_delete_attachment($thumbnail_id, true)) {
                throw new RuntimeException('Could not delete the importer-owned workshop thumbnail.');
            }
            $speaker_headshot_id = (int) ($state['created_speaker_headshot_id'] ?? 0);
            if ($speaker_headshot_id > 0
                && get_post($speaker_headshot_id) instanceof WP_Post
                && !wp_delete_attachment($speaker_headshot_id, true)
            ) {
                throw new RuntimeException('Could not delete the importer-owned workshop speaker headshot.');
            }
            $speaker_id = (int) ($state['created_speaker_id'] ?? 0);
            if ($speaker_id > 0 && get_post($speaker_id) instanceof WP_Post && !wp_delete_post($speaker_id, true)) {
                throw new RuntimeException('Could not delete the importer-owned workshop speaker profile.');
            }
            $this->clear_memberpress_rule_cache();

            // Re-read both fingerprints after deletion to prove rollback did
            // not touch the original page or the original URI rule.
            if ($source['fingerprint'] !== $this->source_spec()['fingerprint']
                || $source_rule['fingerprint'] !== $this->source_rule_spec()['fingerprint']
            ) {
                throw new RuntimeException('Legacy source authority changed during rollback.');
            }

            $state['phase'] = 'rolled_back';
            $state['created_post_ids'] = array();
            $state['created_rule_id'] = 0;
            $state['created_thumbnail_id'] = 0;
            $state['created_speaker_id'] = 0;
            $state['created_speaker_headshot_id'] = 0;
            $state['course_id'] = 0;
            $state['rolled_back_at'] = gmdate('c');
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);

            return array(
                'schema_version' => self::VERSION,
                'phase' => 'rolled_back',
                'removed_posts' => count($existing_ids),
                'removed_rules' => $rule_id > 0 ? 1 : 0,
                'legacy_page_unchanged' => true,
                'legacy_rule_unchanged' => true,
            );
        });
    }

    private function source_spec() {
        $source = get_post(self::SOURCE_POST_ID);
        if (!$source instanceof WP_Post
            || 'page' !== $source->post_type
            || 'publish' !== $source->post_status
            || 'The New Marketer Workshop' !== (string) $source->post_title
        ) {
            throw new RuntimeException('The locked New Marketer Workshop legacy page is unavailable or changed.');
        }

        preg_match_all(
            '~<h1\b[^>]*>(.*?)</h1>\s*(https?://(?:www\.)?vimeo\.com/[^\s<]+)~isu',
            (string) $source->post_content,
            $matches,
            PREG_SET_ORDER
        );
        $lessons = array();
        $provider_ids = array();
        foreach ($matches as $position => $match) {
            $title = html_entity_decode(wp_strip_all_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $title = preg_replace('/^\s*\d+\s*\.\s*/u', '', $title);
            $title = preg_replace('/\s+/u', ' ', trim($title));
            // Keep the byte-exact source title above for the locked source
            // fingerprint, while matching WordPress's post-title storage for
            // display (which removes trailing Unicode spacing).
            $display_title = preg_replace('/^(?:\s|\x{00A0})+|(?:\s|\x{00A0})+$/u', '', $title);
            $url = rtrim(html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'), '.,;:)]}');
            $asset = TSOL_Library_Media_Normalizer::from_url($url);
            if (is_wp_error($asset) || 'vimeo' !== (string) ($asset['provider'] ?? '')) {
                throw new RuntimeException(sprintf('Lesson %d does not contain one valid Vimeo source.', $position + 1));
            }
            $asset['key'] = 'asset-1';
            $asset['label'] = '';
            $asset['position'] = 1;
            $asset['preview'] = false;
            $asset['duration_seconds'] = 0;
            $lessons[] = array(
                'title' => $title,
                'display_title' => $display_title,
                'provider_id' => (string) $asset['provider_id'],
                'privacy_hash' => (string) $asset['privacy_hash'],
                'url' => $url,
                'asset' => $asset,
            );
            $provider_ids[] = (string) $asset['provider_id'];
        }

        if (self::EXPECTED_LESSONS !== count($lessons)
            || self::EXPECTED_LESSONS !== count(array_unique($provider_ids))
        ) {
            throw new RuntimeException('The New Marketer Workshop must contain exactly 52 unique Vimeo lessons.');
        }

        $post_row = array(
            'ID' => (int) $source->ID,
            'post_type' => (string) $source->post_type,
            'post_status' => (string) $source->post_status,
            'post_title' => (string) $source->post_title,
            'post_name' => (string) $source->post_name,
            'post_parent' => (int) $source->post_parent,
            'post_modified_gmt' => (string) $source->post_modified_gmt,
            'post_content' => (string) $source->post_content,
        );
        $fingerprint_lessons = array_map(static function ($lesson) {
            return array(
                'title' => $lesson['title'],
                'provider_id' => $lesson['provider_id'],
                'privacy_hash' => $lesson['privacy_hash'],
                'url' => $lesson['url'],
            );
        }, $lessons);
        $fingerprint = hash('sha256', serialize(array('post' => $post_row, 'lessons' => $fingerprint_lessons)));
        if (self::SOURCE_FINGERPRINT !== $fingerprint) {
            throw new RuntimeException('The New Marketer Workshop source fingerprint changed; import stopped for review.');
        }

        return array(
            'post' => $source,
            'lessons' => $lessons,
            'fingerprint' => $fingerprint,
        );
    }

    private function source_rule_spec() {
        $rules = MeprRule::get_rules(self::SOURCE_URI);
        $rule_ids = array_values(array_unique(array_map(static function ($rule) {
            return isset($rule->ID) ? (int) $rule->ID : 0;
        }, $rules)));
        sort($rule_ids, SORT_NUMERIC);
        if (array(self::SOURCE_RULE_ID) !== $rule_ids) {
            throw new RuntimeException('The workshop URI no longer resolves to exactly the locked MemberPress rule.');
        }

        $rows = array();
        $conditions = array();
        foreach ($rule_ids as $rule_id) {
            $rule = new MeprRule($rule_id);
            if (!empty($rule->drip_enabled) || !empty($rule->expires_enabled)) {
                throw new RuntimeException('The workshop rule uses timing and cannot be copied by this migration.');
            }
            $rule_conditions = array();
            foreach ($rule->access_conditions() as $condition) {
                $row = array(
                    'access_type' => (string) $condition->access_type,
                    'access_operator' => (string) $condition->access_operator,
                    'access_condition' => (string) $condition->access_condition,
                );
                if ('membership' !== $row['access_type'] || 'is' !== $row['access_operator']) {
                    throw new RuntimeException('The workshop rule contains a condition outside the locked membership-is policy.');
                }
                $key = $this->condition_key($row);
                $conditions[$key] = $row;
                $rule_conditions[] = $row;
            }
            usort($rule_conditions, static function ($left, $right) {
                return strcmp(serialize($left), serialize($right));
            });
            $rows[] = array(
                'ID' => $rule_id,
                'post_status' => get_post_status($rule_id),
                'mepr_type' => (string) $rule->mepr_type,
                'mepr_content' => (string) $rule->mepr_content,
                'mepr_regexp' => (bool) $rule->mepr_regexp,
                'drip_enabled' => (bool) $rule->drip_enabled,
                'expires_enabled' => (bool) $rule->expires_enabled,
                'conditions' => $rule_conditions,
            );
        }
        ksort($conditions, SORT_STRING);
        $fingerprint = hash('sha256', serialize($rows));
        if (self::SOURCE_RULE_FINGERPRINT !== $fingerprint || self::EXPECTED_CONDITIONS !== count($conditions)) {
            throw new RuntimeException('The workshop MemberPress permission fingerprint changed; import stopped for review.');
        }

        return array(
            'conditions' => $conditions,
            'fingerprint' => $fingerprint,
            'condition_fingerprint' => $this->condition_fingerprint($conditions),
        );
    }

    private static function sections() {
        return array(
            array(
                'key' => 'course-new-marketer-goals-offers-market',
                'title' => 'Goals, Offers & Your Market',
                'position' => 1,
                'start' => 1,
                'end' => 4,
            ),
            array(
                'key' => 'course-new-marketer-marketing-platform',
                'title' => 'Build Your Marketing Platform',
                'position' => 2,
                'start' => 5,
                'end' => 12,
            ),
            array(
                'key' => 'course-new-marketer-offers-content-monetization',
                'title' => 'Offers, Content & Monetization',
                'position' => 3,
                'start' => 13,
                'end' => 19,
            ),
            array(
                'key' => 'course-new-marketer-community-affiliates-authority',
                'title' => 'Community, Affiliates & Authority',
                'position' => 4,
                'start' => 20,
                'end' => 24,
            ),
            array(
                'key' => 'course-new-marketer-product-marketing-systems',
                'title' => 'Product & Marketing Systems',
                'position' => 5,
                'start' => 25,
                'end' => 31,
            ),
            array(
                'key' => 'course-new-marketer-audience-traffic-brand',
                'title' => 'Audience, Traffic & Brand Growth',
                'position' => 6,
                'start' => 32,
                'end' => 41,
            ),
            array(
                'key' => 'course-new-marketer-scale-automate-implementation',
                'title' => 'Scale, Automate & Put It Into Practice',
                'position' => 7,
                'start' => 42,
                'end' => 52,
            ),
        );
    }

    private static function legacy_sections() {
        return array(array(
            'key' => 'course-new-marketer-workshop-lessons',
            'title' => 'Lessons',
            'position' => 1,
            'start' => 1,
            'end' => self::EXPECTED_LESSONS,
        ));
    }

    private static function section_registry($sections) {
        return array_map(static function ($section) {
            return array(
                'key' => (string) $section['key'],
                'title' => (string) $section['title'],
                'position' => (int) $section['position'],
            );
        }, $sections);
    }

    private static function section_for_lesson($position, $sections) {
        $position = (int) $position;
        foreach ($sections as $section) {
            if ($position >= (int) $section['start'] && $position <= (int) $section['end']) {
                return $section;
            }
        }
        return null;
    }

    private function create_course($source, &$state) {
        $source_post = $source['post'];
        $course_key = 'new-marketer-workshop-course';
        $course_id = $this->create_post(array(
            'post_type' => TSOL_Library_Content_Model::COURSE_POST_TYPE,
            'post_status' => 'draft',
            'post_title' => 'The New Marketer Workshop',
            'post_name' => 'the-new-marketer-workshop',
            'post_content' => '',
            'post_excerpt' => '',
            'post_author' => (int) $source_post->post_author,
            'post_date' => (string) $source_post->post_date,
            'post_date_gmt' => (string) $source_post->post_date_gmt,
        ), $this->base_meta($source_post, $course_key, 'course', $source['fingerprint']), $state);

        update_post_meta($course_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, $course_id);
        update_post_meta($course_id, TSOL_Library_Content_Model::META_COURSE_SECTIONS, self::section_registry(self::sections()));
        return $course_id;
    }

    private function create_lesson($source, $lesson, $position, $course_id, &$state) {
        $source_post = $source['post'];
        $key = sprintf('new-marketer-workshop-lesson-%02d-%s', $position, $lesson['provider_id']);
        $meta = $this->base_meta(
            $source_post,
            $key,
            'lesson',
            hash('sha256', serialize(array($lesson['title'], $lesson['asset'])))
        );
        $section = self::section_for_lesson($position, self::sections());
        if (null === $section) {
            throw new RuntimeException(sprintf('Workshop lesson %d has no curriculum section.', $position));
        }
        $meta[TSOL_Library_Content_Model::META_POSITION] = $position - (int) $section['start'] + 1;
        $meta[TSOL_Library_Content_Model::META_MEDIA_ASSETS] = array($lesson['asset']);
        $meta[TSOL_Library_Content_Model::META_COURSE_ID] = $course_id;
        $meta[TSOL_Library_Content_Model::META_SECTION_KEY] = (string) $section['key'];
        $meta[TSOL_Library_Content_Model::META_SECTION_TITLE] = (string) $section['title'];
        $meta[TSOL_Library_Content_Model::META_SECTION_POSITION] = (int) $section['position'];
        $meta[TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID] = $course_id;

        $this->create_post(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            'post_status' => 'draft',
            'post_title' => (string) $lesson['display_title'],
            'post_name' => sprintf('lesson-%02d-%s', $position, sanitize_title($lesson['display_title'])),
            'post_content' => '',
            'post_excerpt' => '',
            'post_author' => (int) $source_post->post_author,
            'post_date' => (string) $source_post->post_date,
            'post_date_gmt' => (string) $source_post->post_date_gmt,
        ), $meta, $state);
    }

    private function create_post($post_data, $meta, &$state) {
        $post_id = wp_insert_post(wp_slash($post_data), true);
        if (is_wp_error($post_id)) {
            throw new RuntimeException($post_id->get_error_message());
        }
        $post_id = (int) $post_id;
        $state['created_post_ids'][] = $post_id;
        $state['updated_at'] = gmdate('c');
        $this->save_state($state);
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, $key, $value);
        }
        return $post_id;
    }

    private function base_meta(WP_Post $source, $migration_key, $content_type, $fingerprint) {
        return array(
            TSOL_Library_Content_Model::META_INCLUDE => true,
            TSOL_Library_Content_Model::META_CONTENT_TYPE => $content_type,
            TSOL_Library_Content_Model::META_POSITION => 0,
            TSOL_Library_Content_Model::META_FEATURED => false,
            TSOL_Library_Content_Model::META_SPEAKER_MODE => 'course' === $content_type
                ? TSOL_Library_Content_Model::SPEAKER_MODE_DIRECT
                : TSOL_Library_Content_Model::SPEAKER_MODE_INHERIT,
            TSOL_Library_Content_Model::META_MEDIA_ASSETS => array(),
            TSOL_Library_Content_Model::META_RESOURCES => array(),
            TSOL_Library_Content_Model::META_MIGRATION_KEY => $migration_key,
            TSOL_Library_Content_Model::META_MIGRATION_VERSION => self::VERSION,
            TSOL_Library_Content_Model::META_UUID => $this->deterministic_uuid($migration_key),
            TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID => (int) $source->ID,
            TSOL_Library_Content_Model::META_LEGACY_SOURCE_TYPE => (string) $source->post_type,
            TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID => 0,
            TSOL_Library_Content_Model::META_SOURCE_MODIFIED_GMT => (string) $source->post_modified_gmt,
            TSOL_Library_Content_Model::META_CONTENT_FINGERPRINT => $fingerprint,
            TSOL_Library_Content_Model::META_COURSE_ID => 0,
            TSOL_Library_Content_Model::META_SERIES_ID => 0,
            TSOL_Library_Content_Model::META_SECTION_KEY => '',
            TSOL_Library_Content_Model::META_SECTION_TITLE => '',
            TSOL_Library_Content_Model::META_SECTION_POSITION => 0,
            self::META_IMPORT_VERSION => self::VERSION,
            self::META_IMPORT_KEY => $migration_key,
        );
    }

    private function create_rule($source_rule, $course_id) {
        $rule = new MeprRule();
        $rule->post_title = 'TSOL Library — The New Marketer Workshop';
        $rule->post_status = 'draft';
        $rule->mepr_type = 'single_' . TSOL_Library_Content_Model::COURSE_POST_TYPE;
        $rule->mepr_content = (string) $course_id;
        $rule->auto_gen_title = false;
        $rule_id = (int) $rule->store();
        if ($rule_id <= 0) {
            throw new RuntimeException('Could not create the native New Marketer Workshop rule.');
        }
        update_post_meta($rule_id, TSOL_Library_Access_Rules_Migration::META_VERSION, self::VERSION);
        update_post_meta($rule_id, TSOL_Library_Access_Rules_Migration::META_POLICY_KEY, self::POLICY_KEY);
        update_post_meta($rule_id, TSOL_Library_Access_Rules_Migration::META_SOURCE_RULE_IDS, array(self::SOURCE_RULE_ID));
        update_post_meta($rule_id, TSOL_Library_Access_Rules_Migration::META_CONDITION_FINGERPRINT, $source_rule['condition_fingerprint']);

        foreach ($source_rule['conditions'] as $condition) {
            $model = new MeprRuleAccessCondition();
            $model->rule_id = $rule_id;
            $model->access_type = $condition['access_type'];
            $model->access_operator = $condition['access_operator'];
            $model->access_condition = $condition['access_condition'];
            if ((int) $model->store() <= 0) {
                throw new RuntimeException('Could not copy one of the workshop MemberPress conditions.');
            }
        }
        return $rule_id;
    }

    private function assert_targets($source, $course_id, $expected_status, $sections = null, $title_mode = 'source') {
        $sections = is_array($sections) ? $sections : self::sections();
        $course = get_post($course_id);
        if (!$course instanceof WP_Post
            || TSOL_Library_Content_Model::COURSE_POST_TYPE !== $course->post_type
            || $expected_status !== $course->post_status
            || 'The New Marketer Workshop' !== $course->post_title
            || 'the-new-marketer-workshop' !== $course->post_name
        ) {
            throw new RuntimeException('The imported New Marketer Workshop Course is missing or changed.');
        }
        $this->assert_owned_target($course_id, 'new-marketer-workshop-course', $course_id);
        if (!empty(wp_get_object_terms($course_id, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, array('fields' => 'ids')))) {
            throw new RuntimeException('The New Marketer Workshop was unexpectedly assigned to a Course Collection.');
        }

        $registry = TSOL_Library_Content_Model::sanitize_structure_registry(
            get_post_meta($course_id, TSOL_Library_Content_Model::META_COURSE_SECTIONS, true)
        );
        $expected_registry = self::section_registry($sections);
        if (maybe_serialize($expected_registry) !== maybe_serialize($registry)) {
            throw new RuntimeException('The New Marketer Workshop section registry changed.');
        }

        $lesson_ids = $this->lesson_ids($course_id);
        if (self::EXPECTED_LESSONS !== count($lesson_ids)) {
            throw new RuntimeException('The New Marketer Workshop no longer contains exactly 52 lessons.');
        }
        $provider_ids = array();
        foreach ($lesson_ids as $index => $lesson_id) {
            $lesson = get_post($lesson_id);
            $expected = $source['lessons'][$index];
            $position = $index + 1;
            $section = self::section_for_lesson($position, $sections);
            $key = sprintf('new-marketer-workshop-lesson-%02d-%s', $position, $expected['provider_id']);
            $expected_title = 'canonical' === $title_mode
                ? self::canonical_titles()[$index]
                : (string) $expected['display_title'];
            $expected_slug = 'canonical' === $title_mode
                ? sanitize_title($expected_title)
                : sprintf('lesson-%02d-%s', $position, sanitize_title($expected_title));
            if (!$lesson instanceof WP_Post
                || TSOL_Library_Content_Model::ITEM_POST_TYPE !== $lesson->post_type
                || $expected_status !== $lesson->post_status
                || ('ignore' !== $title_mode && $expected_title !== (string) $lesson->post_title)
                || ('ignore' !== $title_mode && $expected_slug !== (string) $lesson->post_name)
                || null === $section
                || $position - (int) $section['start'] + 1 !== (int) get_post_meta($lesson_id, TSOL_Library_Content_Model::META_POSITION, true)
            ) {
                throw new RuntimeException(sprintf('Imported workshop lesson %d is missing or changed.', $position));
            }
            $this->assert_owned_target($lesson_id, $key, $course_id);
            if ($course_id !== (int) get_post_meta($lesson_id, TSOL_Library_Content_Model::META_COURSE_ID, true)
                || (string) $section['key'] !== (string) get_post_meta($lesson_id, TSOL_Library_Content_Model::META_SECTION_KEY, true)
                || (string) $section['title'] !== (string) get_post_meta($lesson_id, TSOL_Library_Content_Model::META_SECTION_TITLE, true)
                || (int) $section['position'] !== (int) get_post_meta($lesson_id, TSOL_Library_Content_Model::META_SECTION_POSITION, true)
            ) {
                throw new RuntimeException(sprintf('Imported workshop lesson %d lost its curriculum relationship.', $position));
            }
            $assets = get_post_meta($lesson_id, TSOL_Library_Content_Model::META_MEDIA_ASSETS, true);
            if (!is_array($assets) || 1 !== count($assets)) {
                throw new RuntimeException(sprintf('Imported workshop lesson %d does not have exactly one media asset.', $position));
            }
            $asset = TSOL_Library_Media_Normalizer::normalize_asset($assets[0], 1);
            if (is_wp_error($asset)
                || 'vimeo' !== (string) $asset['provider']
                || (string) $expected['provider_id'] !== (string) $asset['provider_id']
                || (string) $expected['privacy_hash'] !== (string) $asset['privacy_hash']
            ) {
                throw new RuntimeException(sprintf('Imported workshop lesson %d media changed.', $position));
            }
            $provider_ids[] = (string) $asset['provider_id'];
        }
        if (self::EXPECTED_LESSONS !== count(array_unique($provider_ids))) {
            throw new RuntimeException('The imported workshop contains a duplicate Vimeo source.');
        }
    }

    private static function canonical_titles() {
        return array(
            'Quantifying Your Information Marketing Goals',
            'What Constitutes a Good Offer',
            'How to Solve Your Most Pressing Marketing Problems',
            'Decide on a Niche Market',
            'Build a Massive Network',
            'Become a Media Mogul',
            'Get Into Production Mode',
            'Build a Learning Center',
            'Build a Community Around Your Ideas',
            'Build an Affiliate Army',
            'Position Yourself as an Educator in Your Niche',
            'Start a Weekly Workshop',
            'Developing USPs — Marketing Frameworks',
            'Creating an Offer Series — Product Snowball Week',
            'Plant Your Flag — Content Factory Week',
            'Chaos Theory — Building a Massive Network',
            'Audio Mastery',
            'Prioritize Minimum Monetization',
            'Structure Your Learning Center Offer',
            'Online Community Deep Dive — Build a Community Around Your Ideas',
            'Raise and Build an Affiliate Army',
            'Define and Promote Your Core Ideas',
            'Position Yourself as an Educator — What to Do About Competition',
            'Do a Weekly Workshop — Start With the Offer',
            'Build a Product Snowball',
            'Marketing Framework — How to Solve Any Marketing Problem',
            'Unique Information Products — Weekly Training — Content Factory',
            'Thinking Inside the Box — Build a Massive Network',
            'Keep Your Prospects Close and Your Customers Closer — Become a Media Mogul',
            'Creating a Personal USP — Build a Learning Center',
            'Intentional Buyer Focus — Get Into Production Mode',
            'Paid Community Masterclass — Build a Community Around Your Ideas',
            'Free Offers and Viral Applications — Build an Affiliate Army',
            'Core Ideas Summit — Define and Promote Your Core Ideas',
            'Interview List Holders — Position Yourself as an Educator',
            'Repurposing the Workshop for Traffic — Do a Weekly Workshop',
            'Investing in R and D — Build a Product Snowball',
            'Brand Level Strategy — Develop a Strategic Framework',
            'Journey and Teach — Create a Content Factory',
            'Do Affiliate Promotions — Build a Massive Network',
            'Choose the Right Social Network for Traffic — Become a Media Mogul',
            'The Truth About Information Marketing',
            'The Learning Center Platform — Build a Learning Center',
            'LinkedIn Groups — Build a Community Around Your Ideas',
            'Rules for Your Affiliate Program',
            'Create a Video Diary — Define and Promote Your Core Ideas',
            'Wrap-Up: First Steps — Position Yourself as an Educator',
            'Wrap-Up: Traffic for Your Workshop — Do a Weekly Workshop',
            'The Developmental Funnel — Build a Product Snowball',
            'Automate Marketing Fundamentals — Strategic Marketing',
            'Weave in Your Personal Story and Personality',
            'Network from a Position of Strength — Build a Massive Network',
        );
    }

    private function create_canonical_thumbnail($course_id) {
        $attachment_id = $this->sideload_bundled_asset(
            self::THUMBNAIL_ASSET,
            self::THUMBNAIL_SOURCE_SHA256,
            'the-new-marketer-workshop.png',
            $course_id,
            'The New Marketer Workshop'
        );
        update_post_meta($attachment_id, '_wp_attachment_image_alt', 'The New Marketer Workshop course artwork');
        update_post_meta($attachment_id, '_tsol_library_canonical_reference_url', self::THUMBNAIL_REFERENCE_URL);
        update_post_meta($attachment_id, '_tsol_library_canonical_asset_sha256', self::THUMBNAIL_SOURCE_SHA256);
        if (!set_post_thumbnail($course_id, $attachment_id)) {
            wp_delete_attachment($attachment_id, true);
            throw new RuntimeException('Could not attach the canonical workshop artwork to the Course.');
        }
        return (int) $attachment_id;
    }

    private function create_canonical_speaker($course_id) {
        if (!empty(TSOL_Library_Content_Model::direct_speaker_ids($course_id))) {
            throw new RuntimeException('The workshop Course already has a direct speaker; speaker migration stopped for review.');
        }
        if (get_page_by_path('charles-terrence-harper', OBJECT, TSOL_Library_Content_Model::SPEAKER_POST_TYPE) instanceof WP_Post) {
            throw new RuntimeException('A Charles Terrence Harper speaker slug already exists outside this migration.');
        }

        $speaker_id = wp_insert_post(wp_slash(array(
            'post_type' => TSOL_Library_Content_Model::SPEAKER_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Charles Terrence Harper',
            'post_name' => 'charles-terrence-harper',
            'post_excerpt' => 'Charles Terrence Harper is a digital-marketing trainer and instructional content creator with more than a decade of experience creating practical, step-by-step video training.',
            'post_content' => '<p>Charles Terrence Harper is a creator and technical trainer at The PLR Show and GainMindshare. Since 2011, he has created instructional video content for marketers and entrepreneurs, including work produced behind the scenes for other publishers. The PLR Show credits him with developing more than 300 instructional training courses.</p><p>His published credentials include a BS in Economics from the Wharton School of the University of Pennsylvania, an MBA from Syracuse University, and postgraduate study in Instructional Technology at Duquesne University. His publisher biography also lists doctoral study in Organizational Leadership at Eastern University.</p><p>Charles has taught marketing to small businesses and business-opportunity audiences. He has also served as an adjunct instructor at Eastern University and taught online for colleges and universities.</p>',
        )), true);
        if (is_wp_error($speaker_id)) {
            throw new RuntimeException($speaker_id->get_error_message());
        }
        $speaker_id = (int) $speaker_id;
        update_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_UUID, $this->deterministic_uuid('speaker-charles-terrence-harper'));
        update_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_JOB_TITLE, 'Creator and Technical Trainer');
        update_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_ORGANIZATION, 'The PLR Show / GainMindshare');
        update_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_WEBSITE_URL, 'https://theplrshow.com/');
        update_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_SOCIAL_LINKS, array(
            array('platform' => 'linkedin', 'url' => 'https://www.linkedin.com/in/gainmindshare'),
        ));
        update_post_meta($speaker_id, '_tsol_library_new_marketer_speaker_version', self::SPEAKER_VERSION);

        try {
            $headshot_id = $this->sideload_bundled_asset(
                self::SPEAKER_HEADSHOT_ASSET,
                self::SPEAKER_HEADSHOT_SOURCE_SHA256,
                'charles-terrence-harper.png',
                $speaker_id,
                'Charles Terrence Harper'
            );
            update_post_meta($headshot_id, '_wp_attachment_image_alt', 'Charles Terrence Harper');
            update_post_meta($headshot_id, '_tsol_library_canonical_reference_url', self::SPEAKER_HEADSHOT_REFERENCE_URL);
            update_post_meta($headshot_id, '_tsol_library_canonical_asset_sha256', self::SPEAKER_HEADSHOT_SOURCE_SHA256);
            if (!TSOL_Library_Content_Model::ensure_speaker_image_size($headshot_id)) {
                throw new RuntimeException('Could not prepare the square Charles Terrence Harper headshot.');
            }
            if (!set_post_thumbnail($speaker_id, $headshot_id)) {
                throw new RuntimeException('Could not attach the Charles Terrence Harper headshot.');
            }
            add_post_meta($course_id, TSOL_Library_Content_Model::META_SPEAKER_IDS, $speaker_id, false);
        } catch (Throwable $exception) {
            if (!empty($headshot_id) && get_post($headshot_id) instanceof WP_Post) {
                wp_delete_attachment($headshot_id, true);
            }
            wp_delete_post($speaker_id, true);
            throw $exception;
        }

        return array('speaker_id' => $speaker_id, 'headshot_id' => (int) $headshot_id);
    }

    private function refresh_canonical_speaker_headshot($course_id, $speaker_id) {
        $speaker = get_post($speaker_id);
        if (!$speaker instanceof WP_Post
            || TSOL_Library_Content_Model::SPEAKER_POST_TYPE !== $speaker->post_type
            || array($speaker_id) !== TSOL_Library_Content_Model::direct_speaker_ids($course_id)
            || self::LEGACY_SPEAKER_VERSION !== (string) get_post_meta($speaker_id, '_tsol_library_new_marketer_speaker_version', true)
        ) {
            throw new RuntimeException('The existing workshop speaker profile cannot be safely refreshed.');
        }

        $previous_headshot_id = (int) get_post_thumbnail_id($speaker_id);
        $headshot_id = $this->sideload_bundled_asset(
            self::SPEAKER_HEADSHOT_ASSET,
            self::SPEAKER_HEADSHOT_SOURCE_SHA256,
            'charles-terrence-harper.png',
            $speaker_id,
            'Charles Terrence Harper'
        );
        update_post_meta($headshot_id, '_wp_attachment_image_alt', 'Charles Terrence Harper');
        update_post_meta($headshot_id, '_tsol_library_canonical_reference_url', self::SPEAKER_HEADSHOT_REFERENCE_URL);
        update_post_meta($headshot_id, '_tsol_library_canonical_asset_sha256', self::SPEAKER_HEADSHOT_SOURCE_SHA256);
        if (!TSOL_Library_Content_Model::ensure_speaker_image_size($headshot_id)
            || !set_post_thumbnail($speaker_id, $headshot_id)
        ) {
            wp_delete_attachment($headshot_id, true);
            throw new RuntimeException('Could not attach the opaque Charles Terrence Harper headshot.');
        }
        update_post_meta($speaker_id, '_tsol_library_new_marketer_speaker_version', self::SPEAKER_VERSION);

        return array(
            'speaker_id' => $speaker_id,
            'headshot_id' => (int) $headshot_id,
            'previous_headshot_id' => $previous_headshot_id,
        );
    }

    private function sideload_bundled_asset($relative_path, $expected_sha256, $filename, $parent_id, $description) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $source = trailingslashit(dirname(__DIR__, 3)) . ltrim((string) $relative_path, '/');
        if (!is_file($source) || !hash_equals((string) $expected_sha256, (string) hash_file('sha256', $source))) {
            throw new RuntimeException(sprintf('Bundled migration asset %s is missing or changed.', basename($source)));
        }
        $temporary = wp_tempnam((string) $filename);
        if (!is_string($temporary) || '' === $temporary || !copy($source, $temporary)) {
            throw new RuntimeException(sprintf('Could not prepare bundled migration asset %s.', basename($source)));
        }
        $attachment_id = media_handle_sideload(array(
            'name' => (string) $filename,
            'tmp_name' => $temporary,
        ), (int) $parent_id, (string) $description);
        if (is_wp_error($attachment_id)) {
            @unlink($temporary);
            throw new RuntimeException($attachment_id->get_error_message());
        }
        return (int) $attachment_id;
    }

    private function assert_canonical_thumbnail($course_id) {
        $thumbnail_id = (int) get_post_thumbnail_id($course_id);
        if ($thumbnail_id <= 0
            || 'image/png' !== (string) get_post_mime_type($thumbnail_id)
            || self::THUMBNAIL_REFERENCE_URL !== (string) get_post_meta($thumbnail_id, '_tsol_library_canonical_reference_url', true)
            || self::THUMBNAIL_SOURCE_SHA256 !== (string) get_post_meta($thumbnail_id, '_tsol_library_canonical_asset_sha256', true)
        ) {
            throw new RuntimeException('The workshop Course canonical thumbnail is missing or changed.');
        }
    }

    private function assert_canonical_speaker($course_id, $speaker_id) {
        $speaker = get_post($speaker_id);
        $speaker_ids = TSOL_Library_Content_Model::direct_speaker_ids($course_id);
        $headshot_id = $speaker instanceof WP_Post ? (int) get_post_thumbnail_id($speaker_id) : 0;
        if (!$speaker instanceof WP_Post
            || TSOL_Library_Content_Model::SPEAKER_POST_TYPE !== $speaker->post_type
            || 'publish' !== $speaker->post_status
            || 'Charles Terrence Harper' !== $speaker->post_title
            || 'charles-terrence-harper' !== $speaker->post_name
            || array($speaker_id) !== $speaker_ids
            || 'Creator and Technical Trainer' !== (string) get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_JOB_TITLE, true)
            || 'The PLR Show / GainMindshare' !== (string) get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_ORGANIZATION, true)
            || 'https://theplrshow.com/' !== (string) get_post_meta($speaker_id, TSOL_Library_Content_Model::SPEAKER_META_WEBSITE_URL, true)
            || self::SPEAKER_VERSION !== (string) get_post_meta($speaker_id, '_tsol_library_new_marketer_speaker_version', true)
            || $headshot_id <= 0
            || self::SPEAKER_HEADSHOT_REFERENCE_URL !== (string) get_post_meta($headshot_id, '_tsol_library_canonical_reference_url', true)
            || self::SPEAKER_HEADSHOT_SOURCE_SHA256 !== (string) get_post_meta($headshot_id, '_tsol_library_canonical_asset_sha256', true)
        ) {
            throw new RuntimeException('The workshop canonical Charles Terrence Harper speaker profile is missing or changed.');
        }
    }

    private function assert_owned_target($post_id, $migration_key, $authorization_id) {
        if (self::VERSION !== (string) get_post_meta($post_id, self::META_IMPORT_VERSION, true)
            || $migration_key !== (string) get_post_meta($post_id, self::META_IMPORT_KEY, true)
            || $migration_key !== (string) get_post_meta($post_id, TSOL_Library_Content_Model::META_MIGRATION_KEY, true)
            || self::VERSION !== (string) get_post_meta($post_id, TSOL_Library_Content_Model::META_MIGRATION_VERSION, true)
            || self::SOURCE_POST_ID !== (int) get_post_meta($post_id, TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID, true)
            || $authorization_id !== (int) get_post_meta($post_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true)
        ) {
            throw new RuntimeException(sprintf('Importer-owned target %d lost its provenance or authorization metadata.', $post_id));
        }
    }

    private function assert_native_rule($source_rule, $course_id, $rule_id, $expected_status) {
        $post = get_post($rule_id);
        if (!$post instanceof WP_Post
            || MeprRule::$cpt !== $post->post_type
            || $expected_status !== $post->post_status
            || self::VERSION !== (string) get_post_meta($rule_id, TSOL_Library_Access_Rules_Migration::META_VERSION, true)
            || self::POLICY_KEY !== (string) get_post_meta($rule_id, TSOL_Library_Access_Rules_Migration::META_POLICY_KEY, true)
            || array(self::SOURCE_RULE_ID) !== array_map('intval', (array) get_post_meta($rule_id, TSOL_Library_Access_Rules_Migration::META_SOURCE_RULE_IDS, true))
        ) {
            throw new RuntimeException('The importer-owned workshop MemberPress rule is missing or changed.');
        }
        $rule = new MeprRule($rule_id);
        if ('single_' . TSOL_Library_Content_Model::COURSE_POST_TYPE !== (string) $rule->mepr_type
            || (string) $course_id !== (string) $rule->mepr_content
        ) {
            throw new RuntimeException('The workshop MemberPress rule target changed.');
        }
        $actual = array();
        foreach ($rule->access_conditions() as $condition) {
            $row = array(
                'access_type' => (string) $condition->access_type,
                'access_operator' => (string) $condition->access_operator,
                'access_condition' => (string) $condition->access_condition,
            );
            $actual[$this->condition_key($row)] = $row;
        }
        ksort($actual, SORT_STRING);
        if ($source_rule['condition_fingerprint'] !== $this->condition_fingerprint($actual)
            || self::EXPECTED_CONDITIONS !== count($actual)
        ) {
            throw new RuntimeException('The workshop MemberPress rule conditions are not an exact legacy copy.');
        }
    }

    private function access_matrix($source_conditions, $course_id, $target_ids) {
        global $wpdb;
        $rule_id = (int) $this->state()['created_rule_id'];
        $native_rule = new MeprRule($rule_id);
        $native_conditions = array();
        foreach ($native_rule->access_conditions() as $condition) {
            $row = array(
                'access_type' => (string) $condition->access_type,
                'access_operator' => (string) $condition->access_operator,
                'access_condition' => (string) $condition->access_condition,
            );
            $native_conditions[$this->condition_key($row)] = $row;
        }
        ksort($native_conditions, SORT_STRING);

        $transaction_user_ids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->prefix}mepr_transactions WHERE user_id > 0");
        $subscription_user_ids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->prefix}mepr_subscriptions WHERE user_id > 0");
        $memberpress_lookup = array_fill_keys(array_map('intval', array_merge($transaction_user_ids, $subscription_user_ids)), true);
        $user_ids = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->users} ORDER BY ID"));
        $transitions = array('allow_to_allow' => 0, 'allow_to_deny' => 0, 'deny_to_allow' => 0, 'deny_to_deny' => 0);
        $samples = array('administrator' => 0, 'wordpress_only_non_admin' => 0, 'allowed_member' => 0, 'denied_member' => 0);
        $administrator_count = 0;
        $wordpress_only_count = 0;

        foreach ($user_ids as $user_id) {
            $is_admin = user_can($user_id, 'manage_options');
            $wp_user = get_user_by('id', $user_id);
            $member = $is_admin ? null : new MeprUser($user_id);
            $context = array(
                'is_admin' => $is_admin,
                'memberships' => $member ? array_map('intval', (array) $member->active_product_subscriptions()) : array(),
            );
            $legacy_allowed = $this->conditions_allow($source_conditions, $context);
            $native_allowed = $this->conditions_allow($native_conditions, $context);
            $transition = ($legacy_allowed ? 'allow' : 'deny') . '_to_' . ($native_allowed ? 'allow' : 'deny');
            $transitions[$transition] += count($target_ids);

            $is_wp_only = !$is_admin && !isset($memberpress_lookup[$user_id]);
            if ($is_admin) {
                $administrator_count++;
                if (0 === $samples['administrator']) {
                    $samples['administrator'] = $user_id;
                }
            } elseif ($is_wp_only) {
                $wordpress_only_count++;
                if ($native_allowed) {
                    throw new RuntimeException('The copied workshop policy exposed content to a WordPress-only non-administrator.');
                }
                if (0 === $samples['wordpress_only_non_admin']) {
                    $samples['wordpress_only_non_admin'] = $user_id;
                }
            } elseif ($native_allowed && 0 === $samples['allowed_member']) {
                $samples['allowed_member'] = $user_id;
            } elseif (!$native_allowed && 0 === $samples['denied_member']) {
                $samples['denied_member'] = $user_id;
            }
            unset($wp_user);
        }

        if ($administrator_count <= 0 || $wordpress_only_count <= 0 || in_array(0, $samples, true)) {
            throw new RuntimeException('The workshop access matrix could not exercise every required user category.');
        }

        $runtime_checks = 0;
        foreach ($samples as $category => $user_id) {
            $member = 'administrator' === $category ? null : new MeprUser($user_id);
            $expected = 'administrator' === $category || $this->conditions_allow($source_conditions, array(
                'is_admin' => false,
                'memberships' => $member ? array_map('intval', (array) $member->active_product_subscriptions()) : array(),
            ));
            foreach ($target_ids as $target_id) {
                $decision = TSOL_Library_Auth_Entitlements::for_content($user_id, $target_id);
                if (is_wp_error($decision)
                    || (bool) $decision['can_access'] !== $expected
                    || (int) $decision['authorization_post_id'] !== $course_id
                    || empty($decision['is_protected'])
                ) {
                    throw new RuntimeException(sprintf('Runtime authorization disagreed with the copied workshop policy for the %s sample.', $category));
                }
                $runtime_checks++;
            }
        }

        return array_merge(array(
            'users_checked' => count($user_ids),
            'targets_checked' => count($target_ids),
            'decisions_checked' => count($user_ids) * count($target_ids),
            'administrators_checked' => $administrator_count,
            'wordpress_only_non_admins_checked' => $wordpress_only_count,
            'runtime_sample_categories_checked' => count($samples),
            'runtime_decisions_checked' => $runtime_checks,
        ), $transitions);
    }

    private function conditions_allow($conditions, $context) {
        if (!empty($context['is_admin'])) {
            return true;
        }
        foreach ($conditions as $condition) {
            if ('membership' === $condition['access_type']
                && in_array((int) $condition['access_condition'], (array) $context['memberships'], true)
            ) {
                return true;
            }
        }
        return false;
    }

    private function publish_post($post_id) {
        $updated = wp_update_post(array('ID' => (int) $post_id, 'post_status' => 'publish'), true);
        if (is_wp_error($updated)) {
            throw new RuntimeException($updated->get_error_message());
        }
    }

    private function lesson_ids($course_id) {
        $ids = array_map('intval', get_posts(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
            'meta_query' => array(
                'relation' => 'AND',
                array('key' => self::META_IMPORT_VERSION, 'value' => self::VERSION),
                array('key' => TSOL_Library_Content_Model::META_COURSE_ID, 'value' => (int) $course_id, 'type' => 'NUMERIC'),
            ),
        )));
        usort($ids, static function ($left, $right) {
            return strnatcasecmp(
                (string) get_post_meta((int) $left, self::META_IMPORT_KEY, true),
                (string) get_post_meta((int) $right, self::META_IMPORT_KEY, true)
            );
        });
        return $ids;
    }

    private function target_ids() {
        $ids = array_map('intval', get_posts(array(
            'post_type' => array(TSOL_Library_Content_Model::COURSE_POST_TYPE, TSOL_Library_Content_Model::ITEM_POST_TYPE),
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
            'meta_key' => self::META_IMPORT_VERSION,
            'meta_value' => self::VERSION,
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function owned_rule_ids() {
        if (!class_exists('MeprRule')) {
            return array();
        }
        return array_map('intval', get_posts(array(
            'post_type' => MeprRule::$cpt,
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_key' => TSOL_Library_Access_Rules_Migration::META_VERSION,
            'meta_value' => self::VERSION,
        )));
    }

    private function target_counts() {
        $counts = array('courses' => 0, 'lessons' => 0, 'total' => 0);
        foreach ($this->target_ids() as $post_id) {
            if (TSOL_Library_Content_Model::COURSE_POST_TYPE === get_post_type($post_id)) {
                $counts['courses']++;
            } else {
                $counts['lessons']++;
            }
            $counts['total']++;
        }
        return $counts;
    }

    private function target_fingerprint($post_ids) {
        $rows = array();
        foreach (array_values(array_unique(array_map('intval', $post_ids))) as $post_id) {
            $post = get_post($post_id);
            if (!$post instanceof WP_Post) {
                continue;
            }
            $meta = array();
            foreach (array_merge(TSOL_Library_Content_Model::metadata_keys(), array(self::META_IMPORT_VERSION, self::META_IMPORT_KEY, '_thumbnail_id')) as $key) {
                $meta[$key] = get_post_meta($post_id, $key, false);
            }
            ksort($meta, SORT_STRING);
            $terms = wp_get_object_terms($post_id, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, array('fields' => 'ids'));
            $terms = is_wp_error($terms) ? array() : array_map('intval', $terms);
            sort($terms, SORT_NUMERIC);
            $rows[$post_id] = array(
                'post_type' => (string) $post->post_type,
                'post_status' => (string) $post->post_status,
                'post_title' => (string) $post->post_title,
                'post_name' => (string) $post->post_name,
                'post_content' => (string) $post->post_content,
                'post_excerpt' => (string) $post->post_excerpt,
                'post_author' => (int) $post->post_author,
                'post_date' => (string) $post->post_date,
                'post_date_gmt' => (string) $post->post_date_gmt,
                'meta' => $meta,
                'course_collections' => $terms,
            );
        }
        ksort($rows, SORT_NUMERIC);
        return hash('sha256', serialize($rows));
    }

    private function deterministic_uuid($key) {
        $hex = substr(hash('sha256', self::VERSION . '|' . $key), 0, 32);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-4' . substr($hex, 13, 3)
            . '-a' . substr($hex, 17, 3) . '-' . substr($hex, 20, 12);
    }

    private function condition_key($condition) {
        return implode('|', array(
            (string) $condition['access_type'],
            (string) $condition['access_operator'],
            (string) $condition['access_condition'],
        ));
    }

    private function condition_fingerprint($conditions) {
        $keys = array_keys($conditions);
        sort($keys, SORT_STRING);
        return hash('sha256', serialize($keys));
    }

    private function state() {
        $state = get_option(self::STATE_OPTION, array());
        return is_array($state) ? $state : array();
    }

    private function save_state($state) {
        update_option(self::STATE_OPTION, $state, false);
    }

    private function assert_state_compatible($state) {
        if (!empty($state) && self::VERSION !== (string) ($state['schema_version'] ?? '')) {
            throw new RuntimeException('The stored workshop importer state belongs to another schema version.');
        }
        if (!empty($state['source_fingerprint']) && self::SOURCE_FINGERPRINT !== (string) $state['source_fingerprint']) {
            throw new RuntimeException('The stored workshop importer state belongs to another source fingerprint.');
        }
        if (!empty($state['source_rule_fingerprint']) && self::SOURCE_RULE_FINGERPRINT !== (string) $state['source_rule_fingerprint']) {
            throw new RuntimeException('The stored workshop importer state belongs to another permission fingerprint.');
        }
    }

    private function assert_environment() {
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (self::WORKING_HOST !== $host) {
            throw new RuntimeException(sprintf('Workshop import writes are allowed only on %s.', self::WORKING_HOST));
        }
        if (!class_exists('MeprRule') || !class_exists('MeprRuleAccessCondition') || !class_exists('MeprUser')) {
            throw new RuntimeException('MemberPress is unavailable; workshop import fails closed.');
        }
        if (!defined('MEPR_VERSION') || '1.12.11' !== (string) MEPR_VERSION) {
            throw new RuntimeException('The workshop access adapter is verified only against MemberPress 1.12.11.');
        }
        foreach (array(TSOL_Library_Content_Model::COURSE_POST_TYPE, TSOL_Library_Content_Model::ITEM_POST_TYPE) as $post_type) {
            if (!post_type_exists($post_type)) {
                throw new RuntimeException(sprintf('Required TSOL Library post type %s is unavailable.', $post_type));
            }
        }
        if (!in_array('single_' . TSOL_Library_Content_Model::COURSE_POST_TYPE, array_keys(MeprRule::get_types()), true)) {
            throw new RuntimeException('MemberPress cannot target an individual TSOL Library Course.');
        }
    }

    private function clear_memberpress_rule_cache() {
        MeprRule::$all_rules = null;
        delete_transient('mepr_all_models_for_class_meprrule');
    }

    private function with_lock($callback) {
        if (!add_option(self::LOCK_OPTION, time(), '', 'no')) {
            throw new RuntimeException('Another workshop import process holds the lock.');
        }
        try {
            return call_user_func($callback);
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }
}
