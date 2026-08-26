<?php
/** Guarded local publication rehearsal for normalized TSOL Library records. */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Publication_Rehearsal {

    const VERSION = '20260826.1';
    const WORKING_HOST = 'tomschooloflife.test';
    const STATE_OPTION = 'tsol_library_publication_rehearsal_state';
    const LOCK_OPTION = 'tsol_library_publication_rehearsal_lock';
    const PUBLISH_CONFIRMATION = 'publish-local-tsol-library-rehearsal';
    const RESTORE_CONFIRMATION = 'restore-local-tsol-library-statuses';

    public function preview() {
        $ids = $this->target_ids();
        $this->assert_readiness($ids);
        return array(
            'schema_version' => self::VERSION,
            'phase' => $this->phase(),
            'environment' => (string) wp_parse_url(home_url('/'), PHP_URL_HOST),
            'targets' => count($ids),
            'statuses' => $this->status_counts($ids),
            'hard_readiness_gates' => 'passed',
            'memberpress_mutations' => 0,
            'legacy_mutations' => 0,
        );
    }

    public function status() {
        $state = $this->state();
        return array(
            'schema_version' => self::VERSION,
            'phase' => $this->phase(),
            'targets' => count((array) ($state['target_ids'] ?? array())),
            'published_at' => $state['published_at'] ?? null,
            'restored_at' => $state['restored_at'] ?? null,
        );
    }

    public function publish() {
        $this->assert_environment();
        return $this->with_lock(function () {
            $existing = $this->state();
            if ('published' === (string) ($existing['phase'] ?? '')) {
                return $this->verify();
            }
            if (!empty($existing) && !in_array((string) ($existing['phase'] ?? ''), array('restored'), true)) {
                throw new RuntimeException('The local publication rehearsal is already in progress or failed; inspect its state before retrying.');
            }

            $ids = $this->target_ids();
            $this->assert_readiness($ids);
            $access_state = get_option(TSOL_Library_Access_Rules_Migration::STATE_OPTION, array());
            if (!is_array($access_state) || 'staged' !== (string) ($access_state['phase'] ?? '')) {
                throw new RuntimeException('TSOL-native MemberPress rules must be safely staged before local publication.');
            }

            $previous_statuses = array();
            foreach ($ids as $id) {
                $previous_statuses[$id] = (string) get_post_status($id);
            }
            $state = array(
                'schema_version' => self::VERSION,
                'phase' => 'publishing',
                'target_ids' => $ids,
                'previous_statuses' => $previous_statuses,
                'structure_fingerprint' => $this->structure_fingerprint($ids),
                'updated_ids' => array(),
                'started_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            );
            $this->save_state($state);

            try {
                foreach ($ids as $id) {
                    if ('publish' !== get_post_status($id)) {
                        $updated = wp_update_post(array('ID' => $id, 'post_status' => 'publish'), true);
                        if (is_wp_error($updated)) {
                            throw new RuntimeException($updated->get_error_message());
                        }
                    }
                    $state['updated_ids'][] = $id;
                    $state['updated_at'] = gmdate('c');
                    $this->save_state($state);
                }
            } catch (Throwable $exception) {
                $this->restore_statuses($state['previous_statuses'], $state['updated_ids']);
                $state['phase'] = 'failed';
                $state['failure'] = $exception->getMessage();
                $state['updated_at'] = gmdate('c');
                $this->save_state($state);
                throw $exception;
            }

            $state['phase'] = 'published';
            $state['published_at'] = gmdate('c');
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);
            return $this->verify();
        });
    }

    public function verify() {
        $this->assert_environment();
        $state = $this->state();
        if (empty($state) || self::VERSION !== (string) ($state['schema_version'] ?? '')) {
            throw new RuntimeException('The local publication rehearsal has not started.');
        }
        $ids = array_values(array_map('intval', (array) ($state['target_ids'] ?? array())));
        if (156 !== count($ids) || $ids !== $this->target_ids()) {
            throw new RuntimeException('The normalized publication target inventory changed.');
        }
        if ((string) ($state['structure_fingerprint'] ?? '') !== $this->structure_fingerprint($ids)) {
            throw new RuntimeException('The normalized Library structure changed during the publication rehearsal.');
        }
        $phase = (string) ($state['phase'] ?? 'unknown');
        if ('published' === $phase) {
            foreach ($ids as $id) {
                if ('publish' !== get_post_status($id)) {
                    throw new RuntimeException(sprintf('Library target %d is not published.', $id));
                }
            }
            $this->assert_readiness($ids);
        } elseif ('restored' === $phase) {
            foreach ((array) $state['previous_statuses'] as $id => $status) {
                if ((string) $status !== (string) get_post_status((int) $id)) {
                    throw new RuntimeException(sprintf('Library target %d did not restore its prior status.', (int) $id));
                }
            }
        } else {
            throw new RuntimeException('The publication rehearsal is not in a verifiable terminal phase.');
        }

        return array(
            'schema_version' => self::VERSION,
            'phase' => $phase,
            'targets_verified' => count($ids),
            'statuses' => $this->status_counts($ids),
            'legacy_mutations' => 0,
            'memberpress_mutations' => 0,
        );
    }

    public function restore() {
        $this->assert_environment();
        return $this->with_lock(function () {
            $state = $this->state();
            if ('restored' === (string) ($state['phase'] ?? '')) {
                return $this->verify();
            }
            if ('published' !== (string) ($state['phase'] ?? '')) {
                throw new RuntimeException('Only a completed local publication rehearsal can restore statuses.');
            }
            $access_state = get_option(TSOL_Library_Access_Rules_Migration::STATE_OPTION, array());
            if (is_array($access_state) && 'activated' === (string) ($access_state['phase'] ?? '')) {
                throw new RuntimeException('Roll TSOL-native access back to legacy delegation before restoring draft statuses.');
            }
            $ids = array_values(array_map('intval', (array) $state['target_ids']));
            if ((string) $state['structure_fingerprint'] !== $this->structure_fingerprint($ids)) {
                throw new RuntimeException('The normalized Library structure changed; status restoration stopped.');
            }
            $this->restore_statuses((array) $state['previous_statuses'], $ids);
            $state['phase'] = 'restored';
            $state['restored_at'] = gmdate('c');
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);
            return $this->verify();
        });
    }

    private function assert_readiness($ids) {
        if (156 !== count($ids)) {
            throw new RuntimeException('Publication requires exactly 156 normalized Course, Series, and Content records.');
        }
        $counts = array(
            TSOL_Library_Content_Model::COURSE_POST_TYPE => 0,
            TSOL_Library_Content_Model::SERIES_POST_TYPE => 0,
            TSOL_Library_Content_Model::ITEM_POST_TYPE => 0,
        );
        $course_items = 0;
        $series_items = 0;
        foreach ($ids as $id) {
            $post = get_post($id);
            if (!$post instanceof WP_Post || !isset($counts[$post->post_type])) {
                throw new RuntimeException(sprintf('Publication target %d is invalid.', $id));
            }
            $counts[$post->post_type]++;
            if (in_array($post->post_status, array('trash', 'auto-draft'), true)
                || '' === trim(wp_strip_all_tags((string) $post->post_title))
                || '' === (string) get_post_meta($id, TSOL_Library_Content_Model::META_UUID, true)
            ) {
                throw new RuntimeException(sprintf('Publication target %d is editorially incomplete.', $id));
            }
            $authorization_id = (int) get_post_meta($id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true);
            $authorization = get_post($authorization_id);
            if (!$authorization instanceof WP_Post || empty(MeprRule::get_rules($authorization))) {
                throw new RuntimeException(sprintf('Publication target %d has no effective published MemberPress rule.', $id));
            }
            if (TSOL_Library_Content_Model::ITEM_POST_TYPE !== $post->post_type) {
                continue;
            }
            $course_id = (int) get_post_meta($id, TSOL_Library_Content_Model::META_COURSE_ID, true);
            $series_id = (int) get_post_meta($id, TSOL_Library_Content_Model::META_SERIES_ID, true);
            if (!(($course_id > 0) xor ($series_id > 0))) {
                throw new RuntimeException(sprintf('Publication target %d must belong to exactly one Course or Series.', $id));
            }
            $course_items += $course_id > 0 ? 1 : 0;
            $series_items += $series_id > 0 ? 1 : 0;
            $assets = get_post_meta($id, TSOL_Library_Content_Model::META_MEDIA_ASSETS, true);
            if (!is_array($assets) || empty($assets)) {
                throw new RuntimeException(sprintf('Publication target %d has no playable media.', $id));
            }
            foreach ($assets as $index => $asset) {
                if (is_wp_error(TSOL_Library_Media_Normalizer::normalize_asset($asset, $index + 1))) {
                    throw new RuntimeException(sprintf('Publication target %d has invalid media.', $id));
                }
            }
        }
        if (array(6, 6, 144) !== array_values($counts) || 23 !== $course_items || 121 !== $series_items) {
            throw new RuntimeException('The Course, Series, lesson, or Series-item inventory changed.');
        }
    }

    private function target_ids() {
        $ids = array_map('intval', get_posts(array(
            'post_type' => array(
                TSOL_Library_Content_Model::COURSE_POST_TYPE,
                TSOL_Library_Content_Model::SERIES_POST_TYPE,
                TSOL_Library_Content_Model::ITEM_POST_TYPE,
            ),
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            // Keep this historical rehearsal locked to its 156 normalized
            // targets. Additive imports have their own publication verifier.
            'meta_query' => array(
                'relation' => 'AND',
                array(
                    'key' => TSOL_Library_Content_Model::META_MIGRATION_KEY,
                    'compare' => 'EXISTS',
                ),
                array(
                    'key' => '_tsol_library_new_marketer_import_version',
                    'compare' => 'NOT EXISTS',
                ),
            ),
        )));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    private function structure_fingerprint($ids) {
        $rows = array();
        foreach ($ids as $id) {
            $rows[$id] = array(
                'post_type' => get_post_type($id),
                'uuid' => (string) get_post_meta($id, TSOL_Library_Content_Model::META_UUID, true),
                'migration_key' => (string) get_post_meta($id, TSOL_Library_Content_Model::META_MIGRATION_KEY, true),
                'course_id' => (int) get_post_meta($id, TSOL_Library_Content_Model::META_COURSE_ID, true),
                'series_id' => (int) get_post_meta($id, TSOL_Library_Content_Model::META_SERIES_ID, true),
            );
        }
        return hash('sha256', serialize($rows));
    }

    private function status_counts($ids) {
        $counts = array();
        foreach ($ids as $id) {
            $status = (string) get_post_status($id);
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        ksort($counts, SORT_STRING);
        return $counts;
    }

    private function restore_statuses($statuses, $ids) {
        foreach (array_reverse(array_values(array_map('intval', $ids))) as $id) {
            if (!isset($statuses[$id])) {
                continue;
            }
            $updated = wp_update_post(array('ID' => $id, 'post_status' => (string) $statuses[$id]), true);
            if (is_wp_error($updated)) {
                throw new RuntimeException($updated->get_error_message());
            }
        }
    }

    private function with_lock($callback) {
        $token = wp_generate_uuid4();
        if (!add_option(self::LOCK_OPTION, array('token' => $token, 'created_at' => time()), '', false)) {
            throw new RuntimeException('Another TSOL Library publication rehearsal is running.');
        }
        try {
            return call_user_func($callback);
        } finally {
            $lock = get_option(self::LOCK_OPTION, array());
            if (is_array($lock) && $token === (string) ($lock['token'] ?? '')) {
                delete_option(self::LOCK_OPTION);
            }
        }
    }

    private function assert_environment() {
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (self::WORKING_HOST !== $host) {
            throw new RuntimeException(sprintf('Publication rehearsal writes are allowed only on %s.', self::WORKING_HOST));
        }
    }

    private function state() {
        $state = get_option(self::STATE_OPTION, array());
        return is_array($state) ? $state : array();
    }

    private function phase() {
        $state = $this->state();
        return empty($state) ? 'not_started' : (string) ($state['phase'] ?? 'unknown');
    }

    private function save_state($state) {
        update_option(self::STATE_OPTION, $state, false);
    }
}
