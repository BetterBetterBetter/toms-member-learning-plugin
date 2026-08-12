<?php
/**
 * Guarded translation of legacy MemberPress access into TSOL-native policies.
 *
 * Staging creates inactive draft rules only. Legacy rules and authorization
 * pointers remain authoritative until a separately confirmed activation.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Access_Rules_Migration {

    const VERSION = '20260810.1';
    const WORKING_HOST = 'tomschooloflife.test';
    const STATE_OPTION = 'tsol_library_access_rules_migration_state';
    const LOCK_OPTION = 'tsol_library_access_rules_migration_lock';
    const STAGE_CONFIRMATION = 'stage-tsol-library-memberpress-rules';
    const ACTIVATE_CONFIRMATION = 'activate-tsol-library-memberpress-rules';
    const ROLLBACK_CONFIRMATION = 'rollback-tsol-library-memberpress-rules';
    const DIFFERENCE_APPROVAL = 'approve-course-root-inheritance-corrections';

    const META_VERSION = '_tsol_library_access_rule_version';
    const META_POLICY_KEY = '_tsol_library_access_policy_key';
    const META_SOURCE_RULE_IDS = '_tsol_library_access_source_rule_ids';
    const META_CONDITION_FINGERPRINT = '_tsol_library_access_condition_fingerprint';

    public function preview() {
        $plan = $this->plan();
        $public_rules = array();
        foreach ($plan['rules'] as $policy_key => $spec) {
            $public_rules[] = $this->public_rule_spec($spec, $policy_key);
        }
        return array(
            'schema_version' => self::VERSION,
            'phase' => $this->phase(),
            'targets' => $plan['counts'],
            'native_rules' => $public_rules,
            'native_rule_count' => count($plan['rules']),
            'legacy_rule_count' => count($plan['legacy_rule_ids']),
            'legacy_rule_mutations' => 0,
            'authorization_pointer_mutations_while_staged' => 0,
            'policy_differences_requiring_approval' => $plan['policy_differences'],
            'activation_requirements' => array(
                'all_tsol_targets_published' => true,
                'difference_approval' => self::DIFFERENCE_APPROVAL,
                'complete_user_matrix' => true,
            ),
        );
    }

    public function status() {
        $state = $this->state();
        return array(
            'schema_version' => self::VERSION,
            'phase' => $this->phase(),
            'created_rule_count' => count((array) ($state['created_rule_ids'] ?? array())),
            'created_rule_ids' => array_values(array_map('intval', (array) ($state['created_rule_ids'] ?? array()))),
            'legacy_rule_mutations' => 0,
            'authorization_mode' => 'activated' === $this->phase() ? 'tsol_native' : 'legacy_delegation',
        );
    }

    public function stage() {
        $this->assert_environment();
        return $this->with_lock(function () {
            $state = $this->state();
            if (!empty($state) && in_array((string) ($state['phase'] ?? ''), array('staged', 'activated'), true)) {
                return $this->verify();
            }

            $plan = $this->plan();
            $this->assert_no_owned_rules();
            $state = array(
                'schema_version' => self::VERSION,
                'phase' => 'staging',
                'plan_fingerprint' => $plan['fingerprint'],
                'legacy_authority_fingerprint' => $this->legacy_authority_fingerprint(),
                'structure_fingerprint' => $this->structure_fingerprint($plan['target_ids']),
                'previous_authorization' => $plan['baseline_authorization'],
                'created_rule_ids' => array(),
                'rule_ids_by_policy' => array(),
                'started_at' => gmdate('c'),
                'updated_at' => gmdate('c'),
            );
            $this->save_state($state);

            try {
                foreach ($plan['rules'] as $policy_key => $spec) {
                    $rule_id = $this->create_rule($policy_key, $spec);
                    $state['created_rule_ids'][] = $rule_id;
                    $state['rule_ids_by_policy'][$policy_key] = $rule_id;
                    $state['updated_at'] = gmdate('c');
                    $this->save_state($state);
                }
            } catch (Throwable $exception) {
                $state['phase'] = 'failed';
                $state['failure'] = $exception->getMessage();
                $state['updated_at'] = gmdate('c');
                $this->save_state($state);
                throw $exception;
            }

            $this->clear_memberpress_rule_cache();
            $state['phase'] = 'staged';
            $state['staged_at'] = gmdate('c');
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);
            return $this->verify();
        });
    }

    public function verify() {
        $this->assert_environment();
        $state = $this->state();
        $this->assert_state($state);
        $plan = $this->plan((array) $state['previous_authorization']);

        if ((string) $state['plan_fingerprint'] !== (string) $plan['fingerprint']) {
            throw new RuntimeException('The staged access plan no longer matches the TSOL Library structure or legacy policies.');
        }
        if ((string) $state['structure_fingerprint'] !== $this->structure_fingerprint($plan['target_ids'])) {
            throw new RuntimeException('Course, Series, Collection, or content relationships changed after access rules were staged.');
        }
        if ((string) $state['legacy_authority_fingerprint'] !== $this->legacy_authority_fingerprint()) {
            throw new RuntimeException('A legacy MemberPress rule, product, or access condition changed after staging.');
        }

        $expected_status = 'activated' === (string) $state['phase'] ? 'publish' : 'draft';
        foreach ($plan['rules'] as $policy_key => $spec) {
            $rule_id = (int) ($state['rule_ids_by_policy'][$policy_key] ?? 0);
            $this->assert_rule($rule_id, $policy_key, $spec, $expected_status);
        }
        if (count($plan['rules']) !== count((array) $state['created_rule_ids'])) {
            throw new RuntimeException('The staged MemberPress rule count changed.');
        }

        $pointer_mode = $this->verify_authorization_pointers($plan, (string) $state['phase']);
        $matrix = $this->access_matrix($plan);
        if ($matrix['allow_to_deny'] > 0) {
            throw new RuntimeException('The proposed native rules would remove access from at least one current member.');
        }
        if (!empty($matrix['unexpected_policy_differences'])) {
            throw new RuntimeException('The proposed native rules contain an unregistered policy difference.');
        }

        return array(
            'schema_version' => self::VERSION,
            'phase' => (string) $state['phase'],
            'native_rules_verified' => count($plan['rules']),
            'native_rule_status' => $expected_status,
            'legacy_rules_unchanged' => true,
            'legacy_rule_mutations' => 0,
            'authorization_mode' => $pointer_mode,
            'targets_checked' => count($plan['target_ids']),
            'matrix' => $matrix,
            'activation_blocked_until_targets_are_published' => !$this->all_targets_published($plan['target_ids']),
            'activation_difference_approval' => self::DIFFERENCE_APPROVAL,
            'identities_emitted' => 0,
        );
    }

    public function activate($difference_approval) {
        $this->assert_environment();
        return $this->with_lock(function () use ($difference_approval) {
            $state = $this->state();
            $this->assert_state($state, array('staged'));
            if (self::DIFFERENCE_APPROVAL !== (string) $difference_approval) {
                throw new RuntimeException('The exact approved-difference confirmation is required.');
            }

            $verification = $this->verify();
            if (!empty($verification['matrix']['allow_to_deny'])) {
                throw new RuntimeException('Activation stopped because the candidate policy removes member access.');
            }
            $plan = $this->plan((array) $state['previous_authorization']);
            if (!$this->all_targets_published($plan['target_ids'])) {
                throw new RuntimeException('Activation stopped: every TSOL Course, Series, and Content record must be published first.');
            }

            $state['phase'] = 'activating';
            $state['difference_approval'] = self::DIFFERENCE_APPROVAL;
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);

            foreach ((array) $state['created_rule_ids'] as $rule_id) {
                $updated = wp_update_post(array('ID' => (int) $rule_id, 'post_status' => 'publish'), true);
                if (is_wp_error($updated)) {
                    throw new RuntimeException($updated->get_error_message());
                }
            }
            foreach ($plan['target_ids'] as $target_id) {
                update_post_meta(
                    $target_id,
                    TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID,
                    $this->native_authorization_id($target_id)
                );
            }
            $this->clear_memberpress_rule_cache();

            $state['phase'] = 'activated';
            $state['activated_at'] = gmdate('c');
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);
            return $this->verify();
        });
    }

    public function rollback() {
        $this->assert_environment();
        return $this->with_lock(function () {
            $state = $this->state();
            $this->assert_state($state, array('staged', 'activated', 'failed', 'staging', 'activating'));
            $plan = $this->plan((array) $state['previous_authorization']);

            if (in_array((string) $state['phase'], array('activated', 'activating'), true)) {
                foreach ((array) $state['previous_authorization'] as $target_id => $authorization_id) {
                    update_post_meta(
                        (int) $target_id,
                        TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID,
                        (int) $authorization_id
                    );
                }
            }

            $removed = 0;
            foreach (array_reverse((array) $state['created_rule_ids']) as $rule_id) {
                $rule_id = (int) $rule_id;
                $post = get_post($rule_id);
                if (!$post instanceof WP_Post) {
                    continue;
                }
                if (self::VERSION !== (string) get_post_meta($rule_id, self::META_VERSION, true)) {
                    throw new RuntimeException(sprintf('Rule %d lost its TSOL ownership marker; rollback stopped.', $rule_id));
                }
                MeprRuleAccessCondition::delete_all_by_rule($rule_id);
                if (!wp_delete_post($rule_id, true)) {
                    throw new RuntimeException(sprintf('Could not remove staged TSOL rule %d.', $rule_id));
                }
                $removed++;
            }
            $this->clear_memberpress_rule_cache();

            $state['phase'] = 'rolled_back';
            $state['created_rule_ids'] = array();
            $state['rule_ids_by_policy'] = array();
            $state['rolled_back_at'] = gmdate('c');
            $state['updated_at'] = gmdate('c');
            $this->save_state($state);

            return array(
                'schema_version' => self::VERSION,
                'phase' => 'rolled_back',
                'removed_rules' => $removed,
                'authorization_mode' => 'legacy_delegation',
                'legacy_rules_unchanged' => (string) $state['legacy_authority_fingerprint'] === $this->legacy_authority_fingerprint(),
                'targets_checked' => count($plan['target_ids']),
            );
        });
    }

    private function plan($baseline_authorization = array()) {
        $targets = $this->targets();
        $baseline = array();
        foreach ($targets['all'] as $target_id) {
            $authorization_id = isset($baseline_authorization[$target_id])
                ? (int) $baseline_authorization[$target_id]
                : (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true);
            if ($authorization_id <= 0 || !$this->is_legacy_source($authorization_id)) {
                throw new RuntimeException(sprintf('Target %d does not have a valid legacy authorization source.', $target_id));
            }
            $baseline[$target_id] = $authorization_id;
        }

        $rules = array();
        $mapping = array();
        $course_policies = array();
        foreach ($targets['courses'] as $course_id) {
            $entry_ids = array_merge(array($course_id), $this->children_for_course($course_id, $targets['content']));
            $conditions = array();
            $source_rule_ids = array();
            foreach ($entry_ids as $entry_id) {
                $source = $this->legacy_policy($baseline[$entry_id]);
                $conditions = $this->condition_union($conditions, $source['conditions']);
                $source_rule_ids = array_merge($source_rule_ids, $source['rule_ids']);
            }
            $course_policies[$course_id] = array(
                'entry_ids' => $entry_ids,
                'conditions' => $conditions,
                'source_rule_ids' => $this->unique_ints($source_rule_ids),
            );
        }

        $collection = get_term_by('slug', 'masterclasses', TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY);
        if (!$collection instanceof WP_Term) {
            throw new RuntimeException('The Masterclasses Collection is missing.');
        }
        $collection_course_ids = array_values(array_intersect(
            $targets['courses'],
            array_map('intval', get_objects_in_term((int) $collection->term_id, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY))
        ));
        sort($collection_course_ids, SORT_NUMERIC);
        if (5 !== count($collection_course_ids)) {
            throw new RuntimeException('The Masterclasses Collection must contain exactly five normalized Courses.');
        }

        $common = null;
        foreach ($collection_course_ids as $course_id) {
            $common = null === $common
                ? $course_policies[$course_id]['conditions']
                : $this->condition_intersection($common, $course_policies[$course_id]['conditions']);
        }
        $collection_sources = array();
        foreach ($collection_course_ids as $course_id) {
            $collection_sources = array_merge($collection_sources, $course_policies[$course_id]['source_rule_ids']);
        }
        $collection_key = 'collection:masterclasses';
        $rules[$collection_key] = $this->rule_spec(
            __('TSOL Library — Masterclasses Collection', 'tomschooloflife-plugin'),
            'tax_' . TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY . '||cpt_' . TSOL_Library_Content_Model::COURSE_POST_TYPE,
            (string) $collection->term_id,
            $common,
            $collection_sources
        );

        foreach ($course_policies as $course_id => $policy) {
            $is_collection_course = in_array((int) $course_id, $collection_course_ids, true);
            $conditions = $is_collection_course
                ? $this->condition_difference($policy['conditions'], $common)
                : $policy['conditions'];
            $policy_keys = $is_collection_course ? array($collection_key) : array();
            if (!empty($conditions)) {
                $key = 'course:' . $course_id;
                $suffix = $is_collection_course ? __(' — standalone access', 'tomschooloflife-plugin') : '';
                $rules[$key] = $this->rule_spec(
                    sprintf(__('TSOL Library — %s%s', 'tomschooloflife-plugin'), get_the_title($course_id), $suffix),
                    'single_' . TSOL_Library_Content_Model::COURSE_POST_TYPE,
                    (string) $course_id,
                    $conditions,
                    $policy['source_rule_ids']
                );
                $policy_keys[] = $key;
            }
            foreach ($policy['entry_ids'] as $entry_id) {
                $mapping[$entry_id] = $policy_keys;
            }
        }

        $series_conditions = null;
        $series_source_rules = array();
        foreach ($targets['series'] as $series_id) {
            $entry_ids = array_merge(array($series_id), $this->children_for_series($series_id, $targets['content']));
            $policy = array();
            foreach ($entry_ids as $entry_id) {
                $source = $this->legacy_policy($baseline[$entry_id]);
                $policy = $this->condition_union($policy, $source['conditions']);
                $series_source_rules = array_merge($series_source_rules, $source['rule_ids']);
            }
            if (null === $series_conditions) {
                $series_conditions = $policy;
            } elseif ($this->condition_fingerprint($series_conditions) !== $this->condition_fingerprint($policy)) {
                throw new RuntimeException(sprintf('Series %s has a distinct legacy access policy and cannot use the shared Series rule.', get_the_title($series_id)));
            }
            foreach ($entry_ids as $entry_id) {
                $mapping[$entry_id] = array('series:all');
            }
        }
        $rules['series:all'] = $this->rule_spec(
            __('TSOL Library — All Series', 'tomschooloflife-plugin'),
            'all_' . TSOL_Library_Content_Model::SERIES_POST_TYPE,
            '',
            $series_conditions,
            $series_source_rules
        );

        foreach ($targets['content'] as $content_id) {
            if (!isset($mapping[$content_id])) {
                throw new RuntimeException(sprintf('Content item %d is not assigned to a Course or Series.', $content_id));
            }
        }
        ksort($rules, SORT_STRING);
        ksort($mapping, SORT_NUMERIC);

        $policy_differences = $this->policy_differences($targets, $baseline, $rules, $mapping);
        $unexpected = array_diff(array_keys($policy_differences), array(
            'ai-advantage-course-root-inherits-lesson-access',
            'social-media-course-root-inherits-lesson-access',
        ));
        if (!empty($unexpected)) {
            throw new RuntimeException('An unexpected parent-level policy difference was found: ' . implode(', ', $unexpected));
        }

        $legacy_rule_ids = array();
        foreach ($baseline as $authorization_id) {
            $legacy_rule_ids = array_merge($legacy_rule_ids, $this->legacy_policy($authorization_id)['rule_ids']);
        }
        $fingerprint_data = array('rules' => array(), 'mapping' => $mapping, 'baseline' => $baseline);
        foreach ($rules as $key => $spec) {
            $fingerprint_data['rules'][$key] = array(
                'type' => $spec['type'],
                'content' => $spec['content'],
                'conditions' => array_keys($spec['conditions']),
            );
        }

        return array(
            'fingerprint' => hash('sha256', serialize($fingerprint_data)),
            'rules' => $rules,
            'mapping' => $mapping,
            'baseline_authorization' => $baseline,
            'target_ids' => $targets['all'],
            'legacy_rule_ids' => $this->unique_ints($legacy_rule_ids),
            'policy_differences' => array_values($policy_differences),
            'counts' => array(
                'courses' => count($targets['courses']),
                'series' => count($targets['series']),
                'content' => count($targets['content']),
                'total' => count($targets['all']),
                'collection_courses' => count($collection_course_ids),
            ),
        );
    }

    private function targets() {
        $query = array(
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
            'suppress_filters' => true,
            // This migration's persisted plan is the locked 20260810.1
            // rehearsal inventory. Later additive imports own and verify
            // their access rules independently and must not silently expand
            // this historical migration's scope.
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
        );
        $query['post_type'] = TSOL_Library_Content_Model::COURSE_POST_TYPE;
        $courses = array_map('intval', get_posts($query));
        $query['post_type'] = TSOL_Library_Content_Model::SERIES_POST_TYPE;
        $series = array_map('intval', get_posts($query));
        $query['post_type'] = TSOL_Library_Content_Model::ITEM_POST_TYPE;
        $content = array_map('intval', get_posts($query));
        $all = array_values(array_unique(array_merge($courses, $series, $content)));
        sort($all, SORT_NUMERIC);
        if (6 !== count($courses) || 6 !== count($series) || 142 !== count($content) || 154 !== count($all)) {
            throw new RuntimeException('The TSOL access plan requires the locked six-Course, six-Series, 142-item rehearsal inventory.');
        }
        return compact('courses', 'series', 'content', 'all');
    }

    private function children_for_course($course_id, $content_ids) {
        return array_values(array_filter($content_ids, static function ($content_id) use ($course_id) {
            return (int) get_post_meta($content_id, TSOL_Library_Content_Model::META_COURSE_ID, true) === (int) $course_id;
        }));
    }

    private function children_for_series($series_id, $content_ids) {
        return array_values(array_filter($content_ids, static function ($content_id) use ($series_id) {
            return (int) get_post_meta($content_id, TSOL_Library_Content_Model::META_SERIES_ID, true) === (int) $series_id;
        }));
    }

    private function legacy_policy($authorization_id) {
        $post = get_post((int) $authorization_id);
        if (!$post instanceof WP_Post) {
            throw new RuntimeException(sprintf('Legacy authorization source %d is missing.', $authorization_id));
        }
        $conditions = array();
        $rule_ids = array();
        foreach (MeprRule::get_rules($post) as $rule) {
            if (!empty($rule->drip_enabled) || !empty($rule->expires_enabled)) {
                throw new RuntimeException(sprintf('Legacy rule %d uses timing and requires a separate migration policy.', $rule->ID));
            }
            $rule_ids[] = (int) $rule->ID;
            foreach ($rule->access_conditions() as $condition) {
                $row = array(
                    'access_type' => (string) $condition->access_type,
                    'access_operator' => (string) $condition->access_operator,
                    'access_condition' => (string) $condition->access_condition,
                );
                if (!in_array($row['access_type'], array('membership', 'member', 'role', 'capability'), true)
                    || 'is' !== $row['access_operator']
                ) {
                    throw new RuntimeException(sprintf('Legacy rule %d contains an unsupported access condition.', $rule->ID));
                }
                $conditions[$this->condition_key($row)] = $row;
            }
        }
        if (empty($conditions)) {
            throw new RuntimeException(sprintf('Legacy authorization source %d is not protected by a supported rule.', $authorization_id));
        }
        ksort($conditions, SORT_STRING);
        return array('conditions' => $conditions, 'rule_ids' => $this->unique_ints($rule_ids));
    }

    private function rule_spec($title, $type, $content, $conditions, $source_rule_ids) {
        ksort($conditions, SORT_STRING);
        return array(
            'title' => $title,
            'type' => $type,
            'content' => (string) $content,
            'conditions' => $conditions,
            'source_rule_ids' => $this->unique_ints($source_rule_ids),
            'condition_fingerprint' => $this->condition_fingerprint($conditions),
        );
    }

    private function create_rule($policy_key, $spec) {
        $rule = new MeprRule();
        $rule->post_title = (string) $spec['title'];
        $rule->post_status = 'draft';
        $rule->mepr_type = (string) $spec['type'];
        $rule->mepr_content = (string) $spec['content'];
        $rule->auto_gen_title = false;
        $rule_id = (int) $rule->store();
        if ($rule_id <= 0) {
            throw new RuntimeException(sprintf('Could not stage access policy %s.', $policy_key));
        }
        update_post_meta($rule_id, self::META_VERSION, self::VERSION);
        update_post_meta($rule_id, self::META_POLICY_KEY, (string) $policy_key);
        update_post_meta($rule_id, self::META_SOURCE_RULE_IDS, $spec['source_rule_ids']);
        update_post_meta($rule_id, self::META_CONDITION_FINGERPRINT, $spec['condition_fingerprint']);
        foreach ($spec['conditions'] as $condition) {
            $model = new MeprRuleAccessCondition();
            $model->rule_id = $rule_id;
            $model->access_type = $condition['access_type'];
            $model->access_operator = $condition['access_operator'];
            $model->access_condition = $condition['access_condition'];
            if ((int) $model->store() <= 0) {
                throw new RuntimeException(sprintf('Could not copy an access condition into policy %s.', $policy_key));
            }
        }
        return $rule_id;
    }

    private function assert_rule($rule_id, $policy_key, $spec, $expected_status) {
        $post = get_post($rule_id);
        if (!$post instanceof WP_Post || MeprRule::$cpt !== $post->post_type || $expected_status !== $post->post_status) {
            throw new RuntimeException(sprintf('TSOL access policy %s is missing or has the wrong status.', $policy_key));
        }
        if (self::VERSION !== (string) get_post_meta($rule_id, self::META_VERSION, true)
            || (string) $policy_key !== (string) get_post_meta($rule_id, self::META_POLICY_KEY, true)
        ) {
            throw new RuntimeException(sprintf('TSOL access policy %s lost its ownership metadata.', $policy_key));
        }
        $rule = new MeprRule($rule_id);
        if ((string) $rule->mepr_type !== (string) $spec['type'] || (string) $rule->mepr_content !== (string) $spec['content']) {
            throw new RuntimeException(sprintf('TSOL access policy %s changed its MemberPress target.', $policy_key));
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
        if ($this->condition_fingerprint($actual) !== (string) $spec['condition_fingerprint']) {
            throw new RuntimeException(sprintf('TSOL access policy %s changed its access conditions.', $policy_key));
        }
    }

    private function policy_differences($targets, $baseline, $rules, $mapping) {
        $differences = array();
        foreach ($targets['all'] as $target_id) {
            $legacy = $this->legacy_policy($baseline[$target_id])['conditions'];
            $native = $this->mapped_conditions($mapping[$target_id], $rules);
            if ($this->condition_fingerprint($legacy) === $this->condition_fingerprint($native)) {
                continue;
            }
            $title = (string) get_the_title($target_id);
            $post_type = (string) get_post_type($target_id);
            $key = sanitize_key($title) . '-policy-change';
            if (TSOL_Library_Content_Model::COURSE_POST_TYPE === $post_type && 'Social Media' === $title) {
                $key = 'social-media-course-root-inherits-lesson-access';
            } elseif (TSOL_Library_Content_Model::COURSE_POST_TYPE === $post_type && 'The AI Advantage' === $title) {
                $key = 'ai-advantage-course-root-inherits-lesson-access';
            }
            $differences[$key] = array(
                'key' => $key,
                'target_id' => (int) $target_id,
                'target_type' => $post_type,
                'target_title' => $title,
                'conditions_added' => count($this->condition_difference($native, $legacy)),
                'conditions_removed' => count($this->condition_difference($legacy, $native)),
                'reason' => __('The modern Course grants one parent-level policy to the Course and every lesson.', 'tomschooloflife-plugin'),
            );
        }
        ksort($differences, SORT_STRING);
        return $differences;
    }

    private function access_matrix($plan) {
        global $wpdb;
        $groups = array();
        $difference_keys = array();
        foreach ($plan['policy_differences'] as $difference) {
            $difference_keys[(int) $difference['target_id']] = (string) $difference['key'];
        }
        foreach ($plan['target_ids'] as $target_id) {
            $legacy = $this->legacy_policy($plan['baseline_authorization'][$target_id])['conditions'];
            $native = $this->mapped_conditions($plan['mapping'][$target_id], $plan['rules']);
            $group_key = $this->condition_fingerprint($legacy) . ':' . $this->condition_fingerprint($native);
            if (!isset($groups[$group_key])) {
                $groups[$group_key] = array(
                    'legacy' => $legacy,
                    'native' => $native,
                    'target_ids' => array(),
                    'allow_to_allow' => 0,
                    'allow_to_deny' => 0,
                    'deny_to_allow' => 0,
                    'deny_to_deny' => 0,
                );
            }
            $groups[$group_key]['target_ids'][] = (int) $target_id;
        }

        $user_ids = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->users} ORDER BY ID"));
        foreach ($user_ids as $user_id) {
            $is_admin = user_can($user_id, 'manage_options');
            $wp_user = get_user_by('id', $user_id);
            $member = $is_admin ? null : new MeprUser($user_id);
            $context = array(
                'is_admin' => $is_admin,
                'login' => $wp_user ? (string) $wp_user->user_login : '',
                'roles' => $wp_user ? (array) $wp_user->roles : array(),
                'capabilities' => $wp_user ? array_keys(array_filter((array) $wp_user->allcaps)) : array(),
                'memberships' => $member ? array_map('intval', (array) $member->active_product_subscriptions()) : array(),
            );
            foreach ($groups as &$group) {
                $legacy_allowed = $this->conditions_allow($group['legacy'], $context);
                $native_allowed = $this->conditions_allow($group['native'], $context);
                $transition = ($legacy_allowed ? 'allow' : 'deny') . '_to_' . ($native_allowed ? 'allow' : 'deny');
                $group[$transition]++;
            }
            unset($group);
        }

        $summary = array('allow_to_allow' => 0, 'allow_to_deny' => 0, 'deny_to_allow' => 0, 'deny_to_deny' => 0);
        $transition_groups = array();
        $unexpected = array();
        foreach ($groups as $group) {
            $target_count = count($group['target_ids']);
            foreach ($summary as $key => $_value) {
                $summary[$key] += (int) $group[$key] * $target_count;
            }
            if ($group['allow_to_deny'] > 0 || $group['deny_to_allow'] > 0) {
                $keys = array_values(array_unique(array_filter(array_map(static function ($target_id) use ($difference_keys) {
                    return $difference_keys[$target_id] ?? '';
                }, $group['target_ids']))));
                $transition_groups[] = array(
                    'target_count' => $target_count,
                    'difference_keys' => $keys,
                    'allow_to_deny_per_target' => (int) $group['allow_to_deny'],
                    'deny_to_allow_per_target' => (int) $group['deny_to_allow'],
                );
                if (empty($keys)) {
                    $unexpected[] = 'unregistered-' . $this->condition_fingerprint($group['native']);
                }
            }
        }

        return array_merge(array(
            'users_checked' => count($user_ids),
            'targets_checked' => count($plan['target_ids']),
            'decisions_checked' => count($user_ids) * count($plan['target_ids']),
            'policy_pairs_checked' => count($groups),
            'transition_groups' => $transition_groups,
            'unexpected_policy_differences' => array_values(array_unique($unexpected)),
        ), $summary);
    }

    private function conditions_allow($conditions, $context) {
        if (!empty($context['is_admin'])) {
            return true;
        }
        foreach ($conditions as $condition) {
            if ('membership' === $condition['access_type']
                && in_array((int) $condition['access_condition'], $context['memberships'], true)
            ) {
                return true;
            }
            if ('member' === $condition['access_type'] && (string) $condition['access_condition'] === (string) $context['login']) {
                return true;
            }
            if ('role' === $condition['access_type'] && in_array((string) $condition['access_condition'], $context['roles'], true)) {
                return true;
            }
            if ('capability' === $condition['access_type'] && in_array((string) $condition['access_condition'], $context['capabilities'], true)) {
                return true;
            }
        }
        return false;
    }

    private function mapped_conditions($policy_keys, $rules) {
        $conditions = array();
        foreach ((array) $policy_keys as $policy_key) {
            if (!isset($rules[$policy_key])) {
                throw new RuntimeException(sprintf('Unknown access policy %s.', $policy_key));
            }
            $conditions = $this->condition_union($conditions, $rules[$policy_key]['conditions']);
        }
        return $conditions;
    }

    private function native_authorization_id($target_id) {
        if (TSOL_Library_Content_Model::ITEM_POST_TYPE !== get_post_type($target_id)) {
            return (int) $target_id;
        }
        $course_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_COURSE_ID, true);
        if ($course_id > 0) {
            return $course_id;
        }
        $series_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_SERIES_ID, true);
        return $series_id > 0 ? $series_id : (int) $target_id;
    }

    private function verify_authorization_pointers($plan, $phase) {
        foreach ($plan['target_ids'] as $target_id) {
            $actual = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true);
            $expected = 'activated' === $phase
                ? $this->native_authorization_id($target_id)
                : (int) $plan['baseline_authorization'][$target_id];
            if ($actual !== $expected) {
                throw new RuntimeException(sprintf('Authorization pointer %d changed outside the access migration.', $target_id));
            }
        }
        return 'activated' === $phase ? 'tsol_native' : 'legacy_delegation';
    }

    private function structure_fingerprint($target_ids) {
        $rows = array();
        foreach ($target_ids as $target_id) {
            $terms = wp_get_object_terms($target_id, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, array('fields' => 'ids'));
            $terms = is_wp_error($terms) ? array() : array_map('intval', $terms);
            sort($terms, SORT_NUMERIC);
            $rows[$target_id] = array(
                'post_type' => get_post_type($target_id),
                'course_id' => (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_COURSE_ID, true),
                'series_id' => (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_SERIES_ID, true),
                'migration_key' => (string) get_post_meta($target_id, TSOL_Library_Content_Model::META_MIGRATION_KEY, true),
                'collection_ids' => $terms,
            );
        }
        ksort($rows, SORT_NUMERIC);
        return hash('sha256', serialize($rows));
    }

    private function legacy_authority_fingerprint() {
        global $wpdb;
        // Every TSOL-owned access rule is additive native authority. Exclude
        // all versions here so a separately guarded later import cannot make
        // the locked legacy-authority fingerprint appear to have changed.
        // owned_rule_ids() deliberately remains scoped to this migration's
        // version for state and rollback ownership.
        $owned = array_map('intval', get_posts(array(
            'post_type' => MeprRule::$cpt,
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_key' => self::META_VERSION,
        )));
        $where = "post_type = 'memberpressrule'";
        if (!empty($owned)) {
            $where .= ' AND ID NOT IN (' . implode(',', array_map('intval', $owned)) . ')';
        }
        $rules = $wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE {$where} ORDER BY ID", ARRAY_A);
        $rule_ids = array_map('intval', wp_list_pluck($rules, 'ID'));
        $rule_meta = empty($rule_ids) ? array() : $wpdb->get_results(
            "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN (" . implode(',', $rule_ids) . ') ORDER BY meta_id',
            ARRAY_A
        );
        $condition_where = empty($owned) ? '1=1' : 'rule_id NOT IN (' . implode(',', array_map('intval', $owned)) . ')';
        $conditions = $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}mepr_rule_access_conditions WHERE {$condition_where} ORDER BY id",
            ARRAY_A
        );
        $products = $wpdb->get_results("SELECT * FROM {$wpdb->posts} WHERE post_type = 'memberpressproduct' ORDER BY ID", ARRAY_A);
        $product_ids = array_map('intval', wp_list_pluck($products, 'ID'));
        $product_meta = empty($product_ids) ? array() : $wpdb->get_results(
            "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN (" . implode(',', $product_ids) . ') ORDER BY meta_id',
            ARRAY_A
        );
        return hash('sha256', serialize(array($rules, $rule_meta, $conditions, $products, $product_meta)));
    }

    private function public_rule_spec($spec, $policy_key) {
        return array(
            'policy_key' => (string) $policy_key,
            'title' => (string) $spec['title'],
            'memberpress_type' => (string) $spec['type'],
            'target_id' => '' === (string) $spec['content'] ? null : (int) $spec['content'],
            'access_condition_count' => count($spec['conditions']),
            'source_rule_ids' => $spec['source_rule_ids'],
            'condition_fingerprint' => $spec['condition_fingerprint'],
        );
    }

    private function condition_key($condition) {
        return implode('|', array(
            (string) $condition['access_type'],
            (string) $condition['access_operator'],
            (string) $condition['access_condition'],
        ));
    }

    private function condition_union($left, $right) {
        $merged = array_merge($left, $right);
        ksort($merged, SORT_STRING);
        return $merged;
    }

    private function condition_intersection($left, $right) {
        $result = array_intersect_key($left, $right);
        ksort($result, SORT_STRING);
        return $result;
    }

    private function condition_difference($left, $right) {
        $result = array_diff_key($left, $right);
        ksort($result, SORT_STRING);
        return $result;
    }

    private function condition_fingerprint($conditions) {
        $keys = array_keys($conditions);
        sort($keys, SORT_STRING);
        return hash('sha256', serialize($keys));
    }

    private function unique_ints($values) {
        $values = array_values(array_unique(array_filter(array_map('intval', $values))));
        sort($values, SORT_NUMERIC);
        return $values;
    }

    private function is_legacy_source($post_id) {
        return !in_array(get_post_type($post_id), TSOL_Library_Content_Model::post_types(), true);
    }

    private function all_targets_published($target_ids) {
        foreach ($target_ids as $target_id) {
            if ('publish' !== get_post_status($target_id)) {
                return false;
            }
        }
        return true;
    }

    private function owned_rule_ids() {
        return array_map('intval', get_posts(array(
            'post_type' => MeprRule::$cpt,
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_key' => self::META_VERSION,
            'meta_value' => self::VERSION,
        )));
    }

    private function assert_no_owned_rules() {
        if (!empty($this->owned_rule_ids())) {
            throw new RuntimeException('TSOL-owned MemberPress rules exist outside the recorded migration state.');
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

    private function assert_state($state, $allowed_phases = array('staged', 'activated')) {
        if (empty($state) || self::VERSION !== (string) ($state['schema_version'] ?? '')) {
            throw new RuntimeException('The TSOL Library access migration has not been staged for this schema version.');
        }
        if (!in_array((string) ($state['phase'] ?? ''), $allowed_phases, true)) {
            throw new RuntimeException('The TSOL Library access migration is not in an allowed phase for this operation.');
        }
    }

    private function assert_environment() {
        $host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        if (self::WORKING_HOST !== $host) {
            throw new RuntimeException(sprintf('Access-rule migration writes are allowed only on %s.', self::WORKING_HOST));
        }
        if (!class_exists('MeprRule') || !class_exists('MeprRuleAccessCondition')) {
            throw new RuntimeException('MemberPress is unavailable; access migration fails closed.');
        }
        if (!defined('MEPR_VERSION') || '1.12.11' !== (string) MEPR_VERSION) {
            throw new RuntimeException('The access adapter is verified only against MemberPress 1.12.11; review is required after a MemberPress update.');
        }
        foreach (TSOL_Library_Content_Model::post_types() as $post_type) {
            if (!post_type_exists($post_type)) {
                throw new RuntimeException(sprintf('Required TSOL post type %s is unavailable.', $post_type));
            }
        }
        $required_rule_types = array(
            'single_' . TSOL_Library_Content_Model::COURSE_POST_TYPE,
            'all_' . TSOL_Library_Content_Model::SERIES_POST_TYPE,
            'tax_' . TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY . '||cpt_' . TSOL_Library_Content_Model::COURSE_POST_TYPE,
        );
        $available_rule_types = array_keys(MeprRule::get_types());
        foreach ($required_rule_types as $rule_type) {
            if (!in_array($rule_type, $available_rule_types, true)) {
                throw new RuntimeException(sprintf('MemberPress rule target %s is unavailable.', $rule_type));
            }
        }
    }

    private function clear_memberpress_rule_cache() {
        MeprRule::$all_rules = null;
        delete_transient('mepr_all_models_for_class_meprrule');
    }

    private function with_lock($callback) {
        if (!add_option(self::LOCK_OPTION, time(), '', 'no')) {
            throw new RuntimeException('Another TSOL Library access migration process holds the lock.');
        }
        try {
            return call_user_func($callback);
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }
}
