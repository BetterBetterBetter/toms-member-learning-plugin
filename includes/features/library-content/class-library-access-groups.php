<?php
/**
 * Draft-first, plugin-owned access-group configuration for MemberPress.
 *
 * The configuration is not consulted at runtime. MemberPress remains the
 * authority; a separately staged/published compiler turns groups into native
 * MemberPress Rules.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Access_Groups {

    const SCHEMA_VERSION = 2;
    const OPTION_NAME = 'tsol_library_access_groups_draft';
    const STAGE_OPTION = 'tsol_library_access_groups_stage';
    const LOCK_OPTION = 'tsol_library_access_groups_lock';
    const META_OWNER = '_tsol_library_access_group_owner';
    const OWNER_VALUE = 'tsol-library-access-groups';
    const META_POLICY_KEY = '_tsol_library_access_group_policy_key';
    const META_REVISION = '_tsol_library_access_group_revision';
    const ACTIVATE_CONFIRMATION = 'publish-access-groups';

    public function configuration() {
        $configuration = get_option(self::OPTION_NAME, array());
        return is_array($configuration) ? $configuration : array();
    }

    public function is_bootstrapped() {
        $configuration = $this->configuration();
        return self::SCHEMA_VERSION === (int) ($configuration['schema_version'] ?? 0)
            && isset($configuration['groups'], $configuration['assignments'], $configuration['exceptions']);
    }

    public function maybe_upgrade() {
        $configuration = $this->configuration();
        if (1 !== (int) ($configuration['schema_version'] ?? 0)
            || !isset($configuration['assignments'], $configuration['exceptions'])
        ) {
            return false;
        }
        if (!empty($this->stage_state())) {
            return false;
        }

        list($groups, $assignments) = $this->groups_from_scope_assignments($configuration['assignments']);
        $configuration['schema_version'] = self::SCHEMA_VERSION;
        $configuration['groups'] = $groups;
        $configuration['assignments'] = $assignments;
        $configuration['previous_revision'] = (string) ($configuration['revision'] ?? '');
        $configuration['revision'] = wp_generate_uuid4();
        $configuration['status'] = 'draft';
        $configuration['upgraded_at'] = gmdate('Y-m-d H:i:s');
        $configuration['updated_at'] = $configuration['upgraded_at'];
        update_option(self::OPTION_NAME, $configuration, false);
        return true;
    }

    public function bootstrap() {
        $this->assert_memberpress();
        if ($this->is_bootstrapped()) {
            throw new RuntimeException('Access Groups have already been bootstrapped. The existing draft was not overwritten.');
        }
        $existing = $this->configuration();
        if ((int) ($existing['schema_version'] ?? 0) > 0) {
            throw new RuntimeException('An earlier Access Groups configuration must be upgraded or reconciled before importing again.');
        }

        $source_rules = $this->source_rules();
        if (empty($source_rules)) {
            throw new RuntimeException('No activated TSOL-native MemberPress rules were found to bootstrap.');
        }

        $scope_assignments = array();
        $exceptions = array();
        foreach ($source_rules as $policy_key => $rule_id) {
            $group_key = $this->source_policy_group_key($policy_key);
            if ('' === $group_key || !isset($this->definitions()[$group_key])) {
                throw new RuntimeException(sprintf('The existing access policy %s cannot be represented by an Access Group.', $policy_key));
            }
            foreach ($this->rule_conditions($rule_id) as $condition) {
                if ('membership' === $condition['access_type']) {
                    $membership_id = (int) $condition['access_condition'];
                    if ($membership_id > 0) {
                        $scope_assignments[$membership_id][] = $group_key;
                    }
                    continue;
                }
                $exceptions[$group_key][$this->condition_key($condition)] = $condition;
            }
        }

        $scope_assignments = $this->compact_assignments($scope_assignments);
        list($groups, $assignments) = $this->groups_from_scope_assignments($scope_assignments);
        foreach ($exceptions as &$conditions) {
            ksort($conditions, SORT_STRING);
            $conditions = array_values($conditions);
        }
        unset($conditions);
        ksort($exceptions, SORT_STRING);

        $now = gmdate('Y-m-d H:i:s');
        $configuration = array(
            'schema_version' => self::SCHEMA_VERSION,
            'revision' => wp_generate_uuid4(),
            'status' => 'draft',
            'groups' => $groups,
            'assignments' => $assignments,
            'exceptions' => $exceptions,
            'source_rules' => $source_rules,
            'source_rule_ids' => array_values(array_map('intval', $source_rules)),
            'source_fingerprint' => $this->rules_fingerprint($source_rules),
            'bootstrapped_at' => $now,
            'updated_at' => $now,
        );
        update_option(self::OPTION_NAME, $configuration, false);
        return $this->summary($configuration);
    }

    public function save_groups($raw_groups, $expected_revision) {
        $configuration = $this->editable_configuration($expected_revision);
        $groups = $this->sanitize_groups($raw_groups);
        $valid_group_ids = array_fill_keys(array_keys($groups), true);
        $assignments = array();
        foreach ((array) $configuration['assignments'] as $membership_id => $group_ids) {
            $kept = array_values(array_filter(array_map('strval', (array) $group_ids), static function ($group_id) use ($valid_group_ids) {
                return isset($valid_group_ids[$group_id]);
            }));
            if (!empty($kept)) {
                sort($kept, SORT_STRING);
                $assignments[(int) $membership_id] = array_values(array_unique($kept));
            }
        }
        $configuration['groups'] = $groups;
        $configuration['assignments'] = $assignments;
        return $this->persist_draft($configuration);
    }

    public function save_membership_assignments($membership_id, $raw_group_ids, $expected_revision = '') {
        $membership_id = absint($membership_id);
        if ($membership_id <= 0 || 'memberpressproduct' !== get_post_type($membership_id)) {
            throw new RuntimeException('A valid MemberPress membership is required.');
        }
        $configuration = $this->editable_configuration($expected_revision);
        $group_ids = array();
        foreach ((array) $raw_group_ids as $group_id) {
            $group_id = sanitize_key(wp_unslash((string) $group_id));
            if (isset($configuration['groups'][$group_id])) {
                $group_ids[$group_id] = true;
            }
        }
        if (empty($group_ids)) {
            unset($configuration['assignments'][$membership_id]);
        } else {
            $group_ids = array_keys($group_ids);
            sort($group_ids, SORT_STRING);
            $configuration['assignments'][$membership_id] = $group_ids;
            ksort($configuration['assignments'], SORT_NUMERIC);
        }
        return $this->persist_draft($configuration);
    }

    /**
     * Import a portable, draft-only Access Groups configuration.
     *
     * Memberships are addressed by slug and Library scopes already use the
     * immutable content UUIDs. Generated MemberPress rules, stage state, and
     * source-site IDs are deliberately never accepted here.
     */
    public function import_portable_configuration($raw_groups, $assignments_by_slug, $raw_exceptions, $transition_authorization_ids = array()) {
        $this->assert_memberpress();
        if (!empty($this->stage_state())) {
            throw new RuntimeException('Roll back or finish the current Access Groups stage before importing a migration package.');
        }

        $groups = $this->sanitize_groups(array_values((array) $raw_groups));
        $membership_ids = array();
        foreach ($this->memberships() as $membership) {
            $slug = sanitize_title((string) $membership->post_name);
            if ('' === $slug || isset($membership_ids[$slug])) {
                throw new RuntimeException('MemberPress membership slugs must be unique before importing Access Groups.');
            }
            $membership_ids[$slug] = (int) $membership->ID;
        }

        $assignments = array();
        foreach ((array) $assignments_by_slug as $membership_slug => $group_ids) {
            $membership_slug = sanitize_title((string) $membership_slug);
            if (!isset($membership_ids[$membership_slug])) {
                throw new RuntimeException(sprintf('The MemberPress membership “%s” is missing in this environment.', $membership_slug));
            }
            $valid_group_ids = array();
            foreach ((array) $group_ids as $group_id) {
                $group_id = sanitize_key((string) $group_id);
                if (!isset($groups[$group_id])) {
                    throw new RuntimeException(sprintf('Membership “%s” references an unknown Access Group.', $membership_slug));
                }
                $valid_group_ids[$group_id] = true;
            }
            if (!empty($valid_group_ids)) {
                $assignments[$membership_ids[$membership_slug]] = array_keys($valid_group_ids);
                sort($assignments[$membership_ids[$membership_slug]], SORT_STRING);
            }
        }
        ksort($assignments, SORT_NUMERIC);

        $definitions = $this->definitions();
        $exceptions = array();
        foreach ((array) $raw_exceptions as $scope_key => $conditions) {
            $scope_key = sanitize_text_field((string) $scope_key);
            if (!isset($definitions[$scope_key])) {
                throw new RuntimeException(sprintf('Access exceptions reference an unknown Library scope “%s”.', $scope_key));
            }
            foreach ((array) $conditions as $condition) {
                if ('membership_slug' === (string) ($condition['access_type'] ?? '')) {
                    $membership_slug = sanitize_title((string) ($condition['access_condition'] ?? ''));
                    if (!isset($membership_ids[$membership_slug])) {
                        throw new RuntimeException(sprintf('Access exceptions reference missing MemberPress membership “%s”.', $membership_slug));
                    }
                    $condition['access_type'] = 'membership';
                    $condition['access_condition'] = (string) $membership_ids[$membership_slug];
                }
                $condition = $this->normalize_condition($condition);
                $exceptions[$scope_key][$this->condition_key($condition)] = $condition;
            }
            if (isset($exceptions[$scope_key])) {
                ksort($exceptions[$scope_key], SORT_STRING);
                $exceptions[$scope_key] = array_values($exceptions[$scope_key]);
            }
        }
        ksort($exceptions, SORT_STRING);

        $transition = array();
        foreach ((array) $transition_authorization_ids as $target_id => $authorization_id) {
            $target_id = absint($target_id);
            $authorization_id = absint($authorization_id);
            if (!in_array(get_post_type($target_id), TSOL_Library_Content_Model::post_types(), true)
                || !$authorization_id || !get_post($authorization_id)
            ) {
                throw new RuntimeException('The legacy authorization transition contains a missing source or Library target.');
            }
            $transition[$target_id] = $authorization_id;
        }
        ksort($transition, SORT_NUMERIC);

        $now = gmdate('Y-m-d H:i:s');
        // Production may already have the plugin-owned native Library rules
        // created by an earlier catalogue migration. They are the live
        // baseline that this imported draft will compare against and replace.
        $source_rules = $this->source_rules();
        $configuration = array(
            'schema_version' => self::SCHEMA_VERSION,
            'revision' => wp_generate_uuid4(),
            'status' => 'draft',
            'groups' => $groups,
            'assignments' => $assignments,
            'exceptions' => $exceptions,
            'source_rules' => $source_rules,
            'source_rule_ids' => array_values(array_map('intval', $source_rules)),
            'source_fingerprint' => $this->rules_fingerprint($source_rules),
            'transition_authorization_ids' => $transition,
            'environment_migration' => true,
            'imported_at' => $now,
            'updated_at' => $now,
        );
        update_option(self::OPTION_NAME, $configuration, false);
        return $this->summary($configuration);
    }

    /**
     * Bring separately shipped, plugin-owned Library rules into the draft.
     *
     * This is deliberately narrower than importing arbitrary MemberPress
     * rules: only published rules carrying TSOL's locked access-policy key are
     * eligible. Live rule status is not changed here.
     */
    public function reconcile_owned_rules($expected_revision) {
        $configuration = $this->editable_configuration($expected_revision);
        // Older schema-v2 drafts can have the baseline ID list without the
        // keyed map. Materialize the complete baseline before appending a
        // separately shipped rule so none of the original ownership is lost.
        $configuration['source_rules'] = $this->configured_source_rules($configuration);
        $candidates = $this->reconcilable_rules($configuration);
        if (empty($candidates)) {
            throw new RuntimeException('There are no plugin-owned Library rules to bring into Access Groups.');
        }

        foreach ($candidates as $policy_key => $rule_id) {
            $scope_key = $this->source_policy_group_key($policy_key, $rule_id);
            if ('' === $scope_key || !isset($this->definitions()[$scope_key])) {
                throw new RuntimeException(sprintf('The existing access policy %s cannot be represented by an Access Group.', $policy_key));
            }
            $group_id = $this->group_for_single_scope($configuration['groups'], $scope_key);
            if ('' === $group_id) {
                $group_id = 'access-' . substr(hash('sha256', $scope_key), 0, 12);
                $configuration['groups'][$group_id] = array(
                    'id' => $group_id,
                    'name' => $this->imported_group_name(array($scope_key), count($configuration['groups']) + 1),
                    'description' => __('Imported from an existing plugin-owned Library rule.', 'tomschooloflife-plugin'),
                    'scopes' => array($scope_key),
                );
            }
            foreach ($this->rule_conditions($rule_id) as $condition) {
                if ('membership' === $condition['access_type']) {
                    $membership_id = (int) $condition['access_condition'];
                    if ($membership_id > 0) {
                        $configuration['assignments'][$membership_id][] = $group_id;
                        $configuration['assignments'][$membership_id] = array_values(array_unique($configuration['assignments'][$membership_id]));
                        sort($configuration['assignments'][$membership_id], SORT_STRING);
                    }
                } else {
                    $configuration['exceptions'][$scope_key][$this->condition_key($condition)] = $condition;
                }
            }
            $configuration['source_rules'][$policy_key] = (int) $rule_id;
        }
        uasort($configuration['groups'], static function ($left, $right) {
            return strcasecmp((string) $left['name'], (string) $right['name']);
        });
        ksort($configuration['assignments'], SORT_NUMERIC);
        ksort($configuration['source_rules'], SORT_STRING);
        $configuration['source_rule_ids'] = array_values(array_map('intval', $configuration['source_rules']));
        $configuration['source_fingerprint'] = $this->rules_fingerprint($configuration['source_rules']);
        return $this->persist_draft($configuration);
    }

    private function editable_configuration($expected_revision = '') {
        $configuration = $this->configuration();
        if (!$this->is_bootstrapped()) {
            throw new RuntimeException('Import the current MemberPress access before editing Access Groups.');
        }
        if ('' === (string) $expected_revision
            || !hash_equals((string) $configuration['revision'], (string) $expected_revision)
        ) {
            throw new RuntimeException('The Access Groups draft changed in another browser. Reload before saving.');
        }
        $stage = $this->stage_state();
        if (!empty($stage) && 'active' !== (string) ($stage['phase'] ?? '')) {
            throw new RuntimeException('Checked access changes exist. Discard or clear them before editing the setup.');
        }
        if ('active' === (string) ($stage['phase'] ?? '')) {
            // Promote the active generated rules to the next revision's safe
            // baseline. They remain published while the administrator edits
            // and stages a replacement.
            $configuration['history'] = array_slice(array_merge((array) ($configuration['history'] ?? array()), array(array(
                'revision' => (string) ($configuration['revision'] ?? ''),
                'rule_ids' => array_values(array_map('intval', (array) ($configuration['source_rule_ids'] ?? array()))),
                'superseded_at' => gmdate('Y-m-d H:i:s'),
            ))), -10);
            $configuration['source_rules'] = (array) ($stage['rule_ids_by_policy'] ?? array());
            $configuration['source_rule_ids'] = array_values(array_map('intval', (array) ($stage['created_rule_ids'] ?? array())));
            $configuration['source_fingerprint'] = $this->rules_fingerprint($configuration['source_rules']);
            unset($configuration['transition_authorization_ids'], $configuration['environment_migration']);
            delete_option(self::STAGE_OPTION);
        }
        return $configuration;
    }

    private function persist_draft($configuration) {
        $configuration['revision'] = wp_generate_uuid4();
        $configuration['status'] = 'draft';
        $configuration['updated_at'] = gmdate('Y-m-d H:i:s');
        update_option(self::OPTION_NAME, $configuration, false);
        return $this->summary($configuration);
    }

    public function groups() {
        $configuration = $this->configuration();
        return is_array($configuration['groups'] ?? null) ? $configuration['groups'] : array();
    }

    public function membership_group_ids($membership_id) {
        $configuration = $this->configuration();
        return array_values(array_map('strval', (array) ($configuration['assignments'][(int) $membership_id] ?? array())));
    }

    public function definitions() {
        $definitions = array(
            'library:all' => array(
                'key' => 'library:all',
                'label' => __('Entire Library', 'tomschooloflife-plugin'),
                'description' => __('Every current Course, Masterclass, and Series.', 'tomschooloflife-plugin'),
                'kind' => 'library',
                'target_id' => 0,
            ),
            'collection:masterclasses' => array(
                'key' => 'collection:masterclasses',
                'label' => __('All Masterclasses', 'tomschooloflife-plugin'),
                'description' => __('Every Course in the Masterclasses collection, including future additions.', 'tomschooloflife-plugin'),
                'kind' => 'collection',
                'target_id' => $this->masterclasses_term_id(),
            ),
            'series:all' => array(
                'key' => 'series:all',
                'label' => __('All Series', 'tomschooloflife-plugin'),
                'description' => __('Every current and future Library Series.', 'tomschooloflife-plugin'),
                'kind' => 'all_series',
                'target_id' => 0,
            ),
        );

        foreach ($this->library_posts(TSOL_Library_Content_Model::COURSE_POST_TYPE) as $post) {
            $key = $this->post_group_key('course', $post->ID);
            $definitions[$key] = array(
                'key' => $key,
                'label' => sprintf(__('Course: %s', 'tomschooloflife-plugin'), $post->post_title),
                'description' => __('This Course and all of its Library content.', 'tomschooloflife-plugin'),
                'kind' => 'course',
                'target_id' => (int) $post->ID,
            );
        }
        foreach ($this->library_posts(TSOL_Library_Content_Model::SERIES_POST_TYPE) as $post) {
            $key = $this->post_group_key('series', $post->ID);
            $definitions[$key] = array(
                'key' => $key,
                'label' => sprintf(__('Series: %s', 'tomschooloflife-plugin'), $post->post_title),
                'description' => __('This Series and all of its Library content.', 'tomschooloflife-plugin'),
                'kind' => 'series',
                'target_id' => (int) $post->ID,
            );
        }

        // Brands without a Masterclasses collection should not expose or
        // require that TSOL-specific scope. Course-level scopes remain fully
        // available for their access groups.
        if ($this->masterclasses_term_id() <= 0) {
            unset($definitions['collection:masterclasses']);
        }

        return $definitions;
    }

    /**
     * Resolve the permanent MemberPress authorization target after a legacy
     * catalogue transition. Child Content follows its Course or Series so one
     * native rule protects the complete parent curriculum.
     */
    public function native_authorization_post_id($target_id) {
        $target_id = absint($target_id);
        if (TSOL_Library_Content_Model::ITEM_POST_TYPE !== get_post_type($target_id)) {
            return $target_id;
        }

        $course_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_COURSE_ID, true);
        if ($course_id > 0 && TSOL_Library_Content_Model::COURSE_POST_TYPE === get_post_type($course_id)) {
            return $course_id;
        }

        $series_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_SERIES_ID, true);
        if ($series_id > 0 && TSOL_Library_Content_Model::SERIES_POST_TYPE === get_post_type($series_id)) {
            return $series_id;
        }

        return $target_id;
    }

    public function memberships() {
        return get_posts(array(
            'post_type' => 'memberpressproduct',
            'post_status' => array_values(get_post_stati(array('internal' => false))),
            'posts_per_page' => -1,
            'orderby' => array('title' => 'ASC', 'ID' => 'ASC'),
            'suppress_filters' => true,
        ));
    }

    public function preview() {
        $configuration = $this->configuration();
        if (!$this->is_bootstrapped()) {
            return array('bootstrapped' => false, 'memberships' => count($this->memberships()));
        }
        $compiled = $this->compile($configuration);
        $membership_ids = array_map(static function ($post) {
            return (int) $post->ID;
        }, $this->memberships());
        $assigned_ids = array_map('intval', array_keys((array) $configuration['assignments']));
        $unmanaged = $this->unmanaged_effective_rules($configuration);
        $reconcilable = $this->reconcilable_rules($configuration);
        return array(
            'bootstrapped' => true,
            'revision' => (string) $configuration['revision'],
            'memberships' => count($membership_ids),
            'assigned_memberships' => count(array_intersect($membership_ids, $assigned_ids)),
            'unassigned_membership_ids' => array_values(array_diff($membership_ids, $assigned_ids)),
            'group_count' => count((array) $configuration['groups']),
            'compiled_rule_count' => count($compiled),
            'compiled_condition_count' => array_sum(array_map(static function ($spec) {
                return count($spec['conditions']);
            }, $compiled)),
            'preserved_exception_count' => array_sum(array_map('count', (array) $configuration['exceptions'])),
            'unmanaged_rule_ids' => array_values(array_map('intval', array_keys($unmanaged))),
            'reconcilable_rule_ids' => array_values(array_map('intval', $reconcilable)),
            'stage' => $this->stage_state(),
        );
    }

    public function stage() {
        $this->assert_memberpress();
        return $this->with_lock(function () {
            if (!empty($this->stage_state())) {
                throw new RuntimeException('An Access Groups rule set is already staged.');
            }
            $configuration = $this->configuration();
            if (!$this->is_bootstrapped()) {
                throw new RuntimeException('Bootstrap Access Groups before staging rules.');
            }
            $unmanaged = $this->unmanaged_effective_rules($configuration);
            if (!empty($unmanaged) && empty($configuration['environment_migration'])) {
                throw new RuntimeException(sprintf(
                    _n('%d published MemberPress rule affecting the Library is outside Access Groups. Reconcile it before checking changes.', '%d published MemberPress rules affecting the Library are outside Access Groups. Reconcile them before checking changes.', count($unmanaged), 'tomschooloflife-plugin'),
                    count($unmanaged)
                ));
            }
            $current_source_rules = $this->configured_source_rules($configuration);
            if ((string) ($configuration['source_fingerprint'] ?? '') !== $this->rules_fingerprint($current_source_rules)) {
                throw new RuntimeException('The active TSOL MemberPress rules changed after Access Groups were imported. Reconciliation is required before staging.');
            }
            $compiled = $this->compile($configuration);
            $state = array(
                'schema_version' => self::SCHEMA_VERSION,
                'phase' => 'staging',
                'revision' => (string) $configuration['revision'],
                'source_rule_ids' => array_values(array_map('intval', (array) $configuration['source_rule_ids'])),
                'created_rule_ids' => array(),
                'rule_ids_by_policy' => array(),
                'started_at' => gmdate('Y-m-d H:i:s'),
            );
            update_option(self::STAGE_OPTION, $state, false);
            try {
                foreach ($compiled as $policy_key => $spec) {
                    $rule_id = $this->create_rule($policy_key, $spec, (string) $configuration['revision']);
                    $state['created_rule_ids'][] = $rule_id;
                    $state['rule_ids_by_policy'][$policy_key] = $rule_id;
                    update_option(self::STAGE_OPTION, $state, false);
                }
            } catch (Throwable $exception) {
                $state['phase'] = 'failed';
                $state['error'] = $exception->getMessage();
                update_option(self::STAGE_OPTION, $state, false);
                throw $exception;
            }
            $state['phase'] = 'staged';
            $state['staged_at'] = gmdate('Y-m-d H:i:s');
            update_option(self::STAGE_OPTION, $state, false);
            $this->clear_rule_cache();
            $verification = $this->verify_stage();
            $state = $this->stage_state();
            $state['verification'] = $verification;
            update_option(self::STAGE_OPTION, $state, false);
            return $verification;
        });
    }

    public function verify_stage() {
        $this->assert_memberpress();
        $state = $this->stage_state();
        if (!in_array((string) ($state['phase'] ?? ''), array('staged', 'active'), true)) {
            throw new RuntimeException('No complete Access Groups rule set is staged.');
        }
        $configuration = $this->configuration();
        if ((string) ($state['revision'] ?? '') !== (string) ($configuration['revision'] ?? '')) {
            throw new RuntimeException('The staged rules do not match the current Access Groups draft.');
        }
        $compiled = $this->compile($configuration);
        $expected_status = 'active' === (string) $state['phase'] ? 'publish' : 'draft';
        foreach ($compiled as $policy_key => $spec) {
            $rule_id = (int) ($state['rule_ids_by_policy'][$policy_key] ?? 0);
            $post = get_post($rule_id);
            if (!$post instanceof WP_Post || $expected_status !== $post->post_status) {
                throw new RuntimeException(sprintf('The staged rule %s is missing or has the wrong status.', $policy_key));
            }
            if ($this->condition_fingerprint($this->rule_conditions($rule_id)) !== $this->condition_fingerprint($spec['conditions'])) {
                throw new RuntimeException(sprintf('The staged rule %s no longer matches its Access Group.', $policy_key));
            }
        }
        $matrix = $this->access_matrix($compiled);
        return array(
            'phase' => (string) $state['phase'],
            'revision' => (string) $state['revision'],
            'rules_verified' => count($compiled),
            'matrix' => $matrix,
        );
    }

    public function activate($confirmation) {
        if (self::ACTIVATE_CONFIRMATION !== (string) $confirmation) {
            throw new RuntimeException('Enter the exact Access Groups publish confirmation before activation.');
        }
        return $this->with_lock(function () {
            $verification = $this->verify_stage();
            if ('staged' !== $verification['phase']) {
                throw new RuntimeException('The Access Groups rule set is already active.');
            }
            if ((int) $verification['matrix']['allow_to_deny'] > 0) {
                throw new RuntimeException('Activation is blocked because the staged groups would remove access from a current user.');
            }
            $state = $this->stage_state();
            $configuration = $this->configuration();
            foreach ((array) ($configuration['transition_authorization_ids'] ?? array()) as $target_id => $authorization_id) {
                if ((int) $authorization_id !== (int) get_post_meta((int) $target_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true)) {
                    throw new RuntimeException('A legacy authorization reference changed after the access comparison. Activation stopped.');
                }
            }
            foreach ((array) $state['source_rule_ids'] as $rule_id) {
                if ('publish' === get_post_status((int) $rule_id)) {
                    wp_update_post(array('ID' => (int) $rule_id, 'post_status' => 'draft'));
                }
            }
            foreach ((array) $state['created_rule_ids'] as $rule_id) {
                wp_update_post(array('ID' => (int) $rule_id, 'post_status' => 'publish'));
            }
            foreach ((array) ($configuration['transition_authorization_ids'] ?? array()) as $target_id => $authorization_id) {
                $target_id = (int) $target_id;
                update_post_meta(
                    $target_id,
                    TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID,
                    $this->native_authorization_post_id($target_id)
                );
            }
            $state['phase'] = 'active';
            $state['activated_at'] = gmdate('Y-m-d H:i:s');
            update_option(self::STAGE_OPTION, $state, false);
            $configuration['status'] = 'active';
            $configuration['activated_at'] = $state['activated_at'];
            update_option(self::OPTION_NAME, $configuration, false);
            $this->clear_rule_cache();
            return $this->verify_stage();
        });
    }

    public function rollback() {
        return $this->with_lock(function () {
            $state = $this->stage_state();
            if (empty($state)) {
                throw new RuntimeException('There is no Access Groups stage to roll back.');
            }
            if ('active' === (string) ($state['phase'] ?? '')) {
                foreach ((array) $state['source_rule_ids'] as $rule_id) {
                    if (get_post((int) $rule_id) instanceof WP_Post) {
                        wp_update_post(array('ID' => (int) $rule_id, 'post_status' => 'publish'));
                    }
                }
                $configuration = $this->configuration();
                foreach ((array) ($configuration['transition_authorization_ids'] ?? array()) as $target_id => $authorization_id) {
                    if (get_post((int) $target_id) instanceof WP_Post && get_post((int) $authorization_id) instanceof WP_Post) {
                        update_post_meta((int) $target_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, (int) $authorization_id);
                    }
                }
            }
            foreach ((array) $state['created_rule_ids'] as $rule_id) {
                $rule_id = (int) $rule_id;
                if (self::OWNER_VALUE === (string) get_post_meta($rule_id, self::META_OWNER, true)) {
                    MeprRuleAccessCondition::delete_all_by_rule($rule_id);
                    wp_delete_post($rule_id, true);
                }
            }
            delete_option(self::STAGE_OPTION);
            $configuration = $this->configuration();
            if (!empty($configuration)) {
                $configuration['status'] = 'draft';
                unset($configuration['activated_at']);
                update_option(self::OPTION_NAME, $configuration, false);
            }
            $this->clear_rule_cache();
            return array('phase' => 'draft', 'removed_rule_count' => count((array) ($state['created_rule_ids'] ?? array())));
        });
    }

    public function summary($configuration = null) {
        $configuration = is_array($configuration) ? $configuration : $this->configuration();
        return array(
            'schema_version' => (int) ($configuration['schema_version'] ?? 0),
            'status' => (string) ($configuration['status'] ?? 'not_bootstrapped'),
            'revision' => (string) ($configuration['revision'] ?? ''),
            'assigned_memberships' => count((array) ($configuration['assignments'] ?? array())),
            'preserved_exception_count' => array_sum(array_map('count', (array) ($configuration['exceptions'] ?? array()))),
        );
    }

    private function compile($configuration) {
        $definitions = $this->definitions();
        $policies = array();
        foreach ((array) $configuration['assignments'] as $membership_id => $group_ids) {
            $condition = $this->normalize_condition(array(
                'access_type' => 'membership',
                'access_operator' => 'is',
                'access_condition' => (string) absint($membership_id),
            ));
            foreach ((array) $group_ids as $group_id) {
                $group_id = sanitize_key((string) $group_id);
                if (!isset($configuration['groups'][$group_id])) {
                    continue;
                }
                foreach ($this->expand_group_keys((array) $configuration['groups'][$group_id]['scopes'], $definitions) as $policy_key) {
                    $policies[$policy_key]['conditions'][$this->condition_key($condition)] = $condition;
                }
            }
        }
        foreach ((array) $configuration['exceptions'] as $group_key => $conditions) {
            foreach ($this->expand_group_keys(array($group_key), $definitions) as $policy_key) {
                foreach ((array) $conditions as $condition) {
                    $condition = $this->normalize_condition($condition);
                    if ('membership' !== $condition['access_type']) {
                        $policies[$policy_key]['conditions'][$this->condition_key($condition)] = $condition;
                    }
                }
            }
        }

        foreach ($policies as $policy_key => &$policy) {
            if (!isset($definitions[$policy_key])) {
                throw new RuntimeException(sprintf('Unknown compiled Access Group %s.', $policy_key));
            }
            $definition = $definitions[$policy_key];
            $policy = array_merge($this->rule_target($definition), $policy);
            ksort($policy['conditions'], SORT_STRING);
        }
        unset($policy);
        ksort($policies, SORT_STRING);
        return $policies;
    }

    private function expand_group_keys($group_keys, $definitions) {
        $expanded = array();
        foreach ($group_keys as $group_key) {
            $group_key = sanitize_text_field((string) $group_key);
            if (!isset($definitions[$group_key])) {
                continue;
            }
            if ('library:all' !== $group_key) {
                $expanded[$group_key] = true;
                continue;
            }
            $expanded['collection:masterclasses'] = true;
            $expanded['series:all'] = true;
            foreach ($definitions as $key => $definition) {
                if ('course' === $definition['kind'] && !$this->course_is_masterclass((int) $definition['target_id'])) {
                    $expanded[$key] = true;
                }
            }
        }
        return array_keys($expanded);
    }

    private function rule_target($definition) {
        if ('collection' === $definition['kind']) {
            return array(
                'title' => __('TSOL Access Groups — All Masterclasses', 'tomschooloflife-plugin'),
                'type' => 'tax_' . TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY . '||cpt_' . TSOL_Library_Content_Model::COURSE_POST_TYPE,
                'content' => (string) $definition['target_id'],
            );
        }
        if ('all_series' === $definition['kind']) {
            return array(
                'title' => __('TSOL Access Groups — All Series', 'tomschooloflife-plugin'),
                'type' => 'all_' . TSOL_Library_Content_Model::SERIES_POST_TYPE,
                'content' => '',
            );
        }
        $noun = 'course' === $definition['kind'] ? __('Course', 'tomschooloflife-plugin') : __('Series', 'tomschooloflife-plugin');
        $post_type = 'course' === $definition['kind'] ? TSOL_Library_Content_Model::COURSE_POST_TYPE : TSOL_Library_Content_Model::SERIES_POST_TYPE;
        return array(
            'title' => sprintf(__('TSOL Access Groups — %1$s: %2$s', 'tomschooloflife-plugin'), $noun, get_the_title((int) $definition['target_id'])),
            'type' => 'single_' . $post_type,
            'content' => (string) $definition['target_id'],
        );
    }

    private function compact_assignments($assignments) {
        $definitions = $this->definitions();
        $required = array('collection:masterclasses', 'series:all');
        foreach ($definitions as $key => $definition) {
            if ('course' === $definition['kind'] && !$this->course_is_masterclass((int) $definition['target_id'])) {
                $required[] = $key;
            }
        }
        foreach ($assignments as $membership_id => $group_keys) {
            $group_keys = array_values(array_unique(array_filter(array_map('strval', (array) $group_keys))));
            if (empty(array_diff($required, $group_keys))) {
                $group_keys = array_values(array_diff($group_keys, $required));
                $group_keys[] = 'library:all';
            }
            sort($group_keys, SORT_STRING);
            $assignments[(int) $membership_id] = $group_keys;
        }
        ksort($assignments, SORT_NUMERIC);
        return $assignments;
    }

    private function group_for_single_scope($groups, $scope_key) {
        foreach ((array) $groups as $group_id => $group) {
            $scopes = array_values(array_map('strval', (array) ($group['scopes'] ?? array())));
            sort($scopes, SORT_STRING);
            if (array((string) $scope_key) === $scopes) {
                return (string) $group_id;
            }
        }
        return '';
    }

    private function reconcilable_rules($configuration) {
        $managed_ids = array_fill_keys(array_map('intval', (array) ($configuration['source_rule_ids'] ?? array())), true);
        $rules = array();
        $rule_ids = get_posts(array(
            'post_type' => MeprRule::$cpt,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_query' => array(array('key' => '_tsol_library_access_policy_key', 'compare' => 'EXISTS')),
        ));
        foreach ($rule_ids as $rule_id) {
            $rule_id = (int) $rule_id;
            if (isset($managed_ids[$rule_id])) {
                continue;
            }
            $policy_key = (string) get_post_meta($rule_id, '_tsol_library_access_policy_key', true);
            if ('' !== $policy_key && '' !== $this->source_policy_group_key($policy_key, $rule_id)) {
                $rules[$policy_key] = $rule_id;
            }
        }
        ksort($rules, SORT_STRING);
        return $rules;
    }

    private function unmanaged_effective_rules($configuration) {
        $managed_ids = array_fill_keys(array_map('intval', (array) ($configuration['source_rule_ids'] ?? array())), true);
        $rules = array();
        foreach ($this->library_targets() as $target_id) {
            $authorization_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true);
            $authorization_id = $authorization_id > 0 ? $authorization_id : $target_id;
            $is_transition_source = isset($configuration['transition_authorization_ids'][$target_id])
                && (int) $configuration['transition_authorization_ids'][$target_id] === $authorization_id;
            $post = get_post($authorization_id);
            if (!$post instanceof WP_Post) {
                continue;
            }
            foreach ((array) MeprRule::get_rules($post) as $rule) {
                $rule_id = (int) $rule->ID;
                if ('publish' === get_post_status($rule_id)
                    && !isset($managed_ids[$rule_id])
                    && self::OWNER_VALUE !== (string) get_post_meta($rule_id, self::META_OWNER, true)
                    && !$is_transition_source
                ) {
                    $rules[$rule_id] = (string) get_the_title($rule_id);
                }
            }
        }
        ksort($rules, SORT_NUMERIC);
        return $rules;
    }

    private function groups_from_scope_assignments($scope_assignments) {
        $groups = array();
        $assignments = array();
        foreach ((array) $scope_assignments as $membership_id => $scope_keys) {
            $scope_keys = $this->compact_scope_keys($scope_keys);
            if (empty($scope_keys)) {
                continue;
            }
            $signature = implode('|', $scope_keys);
            $group_id = 'access-' . substr(hash('sha256', $signature), 0, 12);
            if (!isset($groups[$group_id])) {
                $groups[$group_id] = array(
                    'id' => $group_id,
                    'name' => $this->imported_group_name($scope_keys, count($groups) + 1),
                    'description' => __('Imported from the current MemberPress Library access policy.', 'tomschooloflife-plugin'),
                    'scopes' => $scope_keys,
                );
            }
            $assignments[(int) $membership_id] = array($group_id);
        }
        uasort($groups, static function ($left, $right) {
            return strcasecmp((string) $left['name'], (string) $right['name']);
        });
        ksort($assignments, SORT_NUMERIC);
        return array($groups, $assignments);
    }

    private function imported_group_name($scope_keys, $ordinal) {
        $definitions = $this->definitions();
        if (array('library:all') === $scope_keys) {
            return __('Complete Library', 'tomschooloflife-plugin');
        }
        if (array('collection:masterclasses') === $scope_keys) {
            return __('Masterclasses', 'tomschooloflife-plugin');
        }
        if (array('series:all') === $scope_keys) {
            return __('All Series', 'tomschooloflife-plugin');
        }
        if (1 === count($scope_keys) && isset($definitions[$scope_keys[0]])) {
            return preg_replace('/^(Course|Series):\s*/', '', (string) $definitions[$scope_keys[0]]['label']);
        }
        $core_scopes = array('collection:masterclasses', 'series:all');
        foreach ($definitions as $key => $definition) {
            if ('course' === $definition['kind'] && !$this->course_is_masterclass((int) $definition['target_id'])) {
                $core_scopes[] = $key;
            }
        }
        sort($core_scopes, SORT_STRING);
        if ($core_scopes === $scope_keys) {
            return __('Complete Library', 'tomschooloflife-plugin');
        }
        // The production policy intentionally excludes newer separately sold
        // courses, so its historical all-access package is not called
        // "Complete Library".
        if (in_array('collection:masterclasses', $scope_keys, true)
            && in_array('series:all', $scope_keys, true)
        ) {
            return __('School of Life Core Library', 'tomschooloflife-plugin');
        }
        return sprintf(__('Imported Library Access %d', 'tomschooloflife-plugin'), (int) $ordinal);
    }

    private function sanitize_groups($raw_groups) {
        $definitions = $this->definitions();
        $groups = array();
        $names = array();
        foreach ((array) $raw_groups as $raw_group) {
            if (!is_array($raw_group)) {
                continue;
            }
            if (!empty($raw_group['remove'])) {
                continue;
            }
            $name = sanitize_text_field(wp_unslash((string) ($raw_group['name'] ?? '')));
            if ('' === $name) {
                continue;
            }
            $name_key = strtolower($name);
            if (isset($names[$name_key])) {
                throw new RuntimeException(sprintf('Access Group names must be unique. “%s” is duplicated.', $name));
            }
            $names[$name_key] = true;
            $group_id = sanitize_key(wp_unslash((string) ($raw_group['id'] ?? '')));
            if ('' === $group_id || isset($groups[$group_id])) {
                $group_id = 'access-' . wp_generate_uuid4();
            }
            $scopes = array();
            foreach ((array) ($raw_group['scopes'] ?? array()) as $scope_key) {
                $scope_key = sanitize_text_field(wp_unslash((string) $scope_key));
                if (isset($definitions[$scope_key])) {
                    $scopes[$scope_key] = true;
                }
            }
            if (empty($scopes)) {
                throw new RuntimeException(sprintf('Access Group “%s” must unlock at least one Library area.', $name));
            }
            $scopes = $this->compact_scope_keys(array_keys($scopes));
            $groups[$group_id] = array(
                'id' => $group_id,
                'name' => $name,
                'description' => sanitize_textarea_field(wp_unslash((string) ($raw_group['description'] ?? ''))),
                'scopes' => $scopes,
            );
        }
        uasort($groups, static function ($left, $right) {
            return strcasecmp((string) $left['name'], (string) $right['name']);
        });
        return $groups;
    }

    private function compact_scope_keys($scope_keys) {
        $definitions = $this->definitions();
        $scope_keys = array_values(array_unique(array_map('strval', (array) $scope_keys)));
        if (in_array('library:all', $scope_keys, true)) {
            return array('library:all');
        }
        if (in_array('collection:masterclasses', $scope_keys, true)) {
            $scope_keys = array_values(array_filter($scope_keys, function ($scope_key) use ($definitions) {
                return !isset($definitions[$scope_key])
                    || 'course' !== $definitions[$scope_key]['kind']
                    || !$this->course_is_masterclass((int) $definitions[$scope_key]['target_id']);
            }));
        }
        if (in_array('series:all', $scope_keys, true)) {
            $scope_keys = array_values(array_filter($scope_keys, static function ($scope_key) use ($definitions) {
                return !isset($definitions[$scope_key]) || 'series' !== $definitions[$scope_key]['kind'];
            }));
        }
        sort($scope_keys, SORT_STRING);
        return $scope_keys;
    }

    private function source_rules() {
        $state = get_option('tsol_library_access_rules_migration_state', array());
        $rules = array();
        foreach ((array) ($state['rule_ids_by_policy'] ?? array()) as $policy_key => $rule_id) {
            $rule_id = (int) $rule_id;
            if ($rule_id > 0 && 'publish' === get_post_status($rule_id)) {
                $rules[(string) $policy_key] = $rule_id;
            }
        }
        $rule_ids = get_posts(array(
            'post_type' => MeprRule::$cpt,
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
            'meta_query' => array(array('key' => '_tsol_library_access_policy_key', 'compare' => 'EXISTS')),
        ));
        foreach ($rule_ids as $rule_id) {
            $policy_key = (string) get_post_meta((int) $rule_id, '_tsol_library_access_policy_key', true);
            if ('' !== $policy_key && !isset($rules[$policy_key])) {
                $rules[$policy_key] = (int) $rule_id;
            }
        }
        ksort($rules, SORT_STRING);
        return $rules;
    }

    private function configured_source_rules($configuration) {
        $rules = array();
        foreach ((array) ($configuration['source_rules'] ?? array()) as $policy_key => $rule_id) {
            $rule_id = (int) $rule_id;
            if ($rule_id > 0 && 'publish' === get_post_status($rule_id)) {
                $rules[(string) $policy_key] = $rule_id;
            }
        }
        if (empty($rules)) {
            // Backward compatibility for drafts created before the keyed
            // source map was persisted alongside the source ID list.
            $rules = $this->source_rules();
        }
        $configured_ids = array_values(array_map('intval', (array) ($configuration['source_rule_ids'] ?? array())));
        $active_ids = array_values(array_map('intval', $rules));
        sort($configured_ids, SORT_NUMERIC);
        sort($active_ids, SORT_NUMERIC);
        if ($active_ids !== $configured_ids) {
            throw new RuntimeException('One or more active baseline MemberPress rules are missing or unpublished.');
        }
        ksort($rules, SORT_STRING);
        return $rules;
    }

    private function source_policy_group_key($policy_key, $rule_id = 0) {
        if (in_array($policy_key, array('collection:masterclasses', 'series:all'), true)) {
            return $policy_key;
        }
        if (preg_match('/^(course|series):(\d+)$/', (string) $policy_key, $matches)) {
            return $this->post_group_key($matches[1], (int) $matches[2]);
        }
        if ((int) $rule_id > 0) {
            $rule = new MeprRule((int) $rule_id);
            $type = (string) $rule->mepr_type;
            $content_id = (int) $rule->mepr_content;
            if ('single_' . TSOL_Library_Content_Model::COURSE_POST_TYPE === $type
                && TSOL_Library_Content_Model::COURSE_POST_TYPE === get_post_type($content_id)
            ) {
                return $this->post_group_key('course', $content_id);
            }
            if ('single_' . TSOL_Library_Content_Model::SERIES_POST_TYPE === $type
                && TSOL_Library_Content_Model::SERIES_POST_TYPE === get_post_type($content_id)
            ) {
                return $this->post_group_key('series', $content_id);
            }
        }
        return '';
    }

    private function post_group_key($kind, $post_id) {
        $uuid = sanitize_key((string) get_post_meta((int) $post_id, TSOL_Library_Content_Model::META_UUID, true));
        return sanitize_key((string) $kind) . ':' . ('' !== $uuid ? $uuid : (string) (int) $post_id);
    }

    private function library_posts($post_type) {
        return get_posts(array(
            'post_type' => $post_type,
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'orderby' => array('title' => 'ASC', 'ID' => 'ASC'),
            'suppress_filters' => true,
        ));
    }

    private function masterclasses_term_id() {
        $term = get_term_by('slug', 'masterclasses', TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY);
        return $term instanceof WP_Term ? (int) $term->term_id : 0;
    }

    private function course_is_masterclass($course_id) {
        $term_id = $this->masterclasses_term_id();
        return $term_id > 0 && has_term($term_id, TSOL_Library_Content_Model::COURSE_COLLECTION_TAXONOMY, (int) $course_id);
    }

    private function rule_conditions($rule_id) {
        $rule = new MeprRule((int) $rule_id);
        if (!empty($rule->drip_enabled) || !empty($rule->expires_enabled)) {
            throw new RuntimeException(sprintf('MemberPress rule %d uses timing and cannot be managed by Access Groups.', $rule_id));
        }
        $conditions = array();
        foreach ($rule->access_conditions() as $condition) {
            $row = $this->normalize_condition(array(
                'access_type' => $condition->access_type,
                'access_operator' => $condition->access_operator,
                'access_condition' => $condition->access_condition,
            ));
            $conditions[$this->condition_key($row)] = $row;
        }
        ksort($conditions, SORT_STRING);
        return $conditions;
    }

    private function normalize_condition($condition) {
        $row = array(
            'access_type' => sanitize_key((string) ($condition['access_type'] ?? '')),
            'access_operator' => sanitize_key((string) ($condition['access_operator'] ?? '')),
            'access_condition' => sanitize_text_field((string) ($condition['access_condition'] ?? '')),
        );
        if (!in_array($row['access_type'], array('membership', 'member', 'role', 'capability'), true)
            || 'is' !== $row['access_operator'] || '' === $row['access_condition']
        ) {
            throw new RuntimeException('An unsupported MemberPress access condition was found.');
        }
        return $row;
    }

    private function condition_key($condition) {
        return implode('|', array($condition['access_type'], $condition['access_operator'], $condition['access_condition']));
    }

    private function condition_fingerprint($conditions) {
        $keys = array();
        foreach ($conditions as $condition) {
            $keys[] = $this->condition_key(is_array($condition) ? $condition : (array) $condition);
        }
        sort($keys, SORT_STRING);
        return hash('sha256', serialize($keys));
    }

    private function rules_fingerprint($rules) {
        $snapshot = array();
        foreach ($rules as $policy_key => $rule_id) {
            $snapshot[$policy_key] = array('id' => (int) $rule_id, 'conditions' => $this->rule_conditions($rule_id));
        }
        return hash('sha256', serialize($snapshot));
    }

    private function create_rule($policy_key, $spec, $revision) {
        $rule = new MeprRule();
        $rule->post_title = (string) $spec['title'];
        $rule->post_status = 'draft';
        $rule->mepr_type = (string) $spec['type'];
        $rule->mepr_content = (string) $spec['content'];
        $rule->auto_gen_title = false;
        $rule_id = (int) $rule->store();
        if ($rule_id <= 0) {
            throw new RuntimeException(sprintf('Could not create the staged rule %s.', $policy_key));
        }
        update_post_meta($rule_id, self::META_OWNER, self::OWNER_VALUE);
        update_post_meta($rule_id, self::META_POLICY_KEY, (string) $policy_key);
        update_post_meta($rule_id, self::META_REVISION, (string) $revision);
        foreach ($spec['conditions'] as $condition) {
            $model = new MeprRuleAccessCondition();
            $model->rule_id = $rule_id;
            $model->access_type = $condition['access_type'];
            $model->access_operator = $condition['access_operator'];
            $model->access_condition = $condition['access_condition'];
            if ((int) $model->store() <= 0) {
                throw new RuntimeException(sprintf('Could not add a condition to the staged rule %s.', $policy_key));
            }
        }
        return $rule_id;
    }

    private function access_matrix($compiled) {
        global $wpdb;
        $target_pairs = $this->target_condition_pairs($compiled);
        $users = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->users} ORDER BY ID"));
        $summary = array('allow_to_allow' => 0, 'allow_to_deny' => 0, 'deny_to_allow' => 0, 'deny_to_deny' => 0);
        foreach ($users as $user_id) {
            $wp_user = get_user_by('id', $user_id);
            $is_admin = user_can($user_id, 'manage_options');
            $member = $is_admin ? null : new MeprUser($user_id);
            $context = array(
                'is_admin' => $is_admin,
                'login' => $wp_user ? (string) $wp_user->user_login : '',
                'roles' => $wp_user ? (array) $wp_user->roles : array(),
                'capabilities' => $wp_user ? array_keys(array_filter((array) $wp_user->allcaps)) : array(),
                'memberships' => $member ? array_map('intval', (array) $member->active_product_subscriptions()) : array(),
            );
            foreach ($target_pairs as $pair) {
                $old = $this->conditions_allow($pair['old'], $context);
                $new = $this->conditions_allow($pair['new'], $context);
                $summary[($old ? 'allow' : 'deny') . '_to_' . ($new ? 'allow' : 'deny')]++;
            }
        }
        return array_merge(array(
            'users_checked' => count($users),
            'targets_checked' => count($target_pairs),
            'decisions_checked' => count($users) * count($target_pairs),
        ), $summary);
    }

    private function target_condition_pairs($compiled) {
        $configuration = $this->configuration();
        $replaced_rule_ids = array_fill_keys(array_map('intval', (array) ($configuration['source_rule_ids'] ?? array())), true);
        $pairs = array();
        foreach ($this->library_targets() as $target_id) {
            $authorization_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_AUTHORIZATION_POST_ID, true);
            $authorization_id = $authorization_id > 0 ? $authorization_id : $target_id;
            $is_transition_source = isset($configuration['transition_authorization_ids'][$target_id])
                && (int) $configuration['transition_authorization_ids'][$target_id] === $authorization_id;
            $old = array();
            $new = array();
            foreach ((array) MeprRule::get_rules(get_post($authorization_id)) as $rule) {
                if ('publish' !== get_post_status((int) $rule->ID)) {
                    continue;
                }
                foreach ($this->rule_conditions((int) $rule->ID) as $key => $condition) {
                    $old[$key] = $condition;
                    // Access Groups replace only the imported TSOL-native rule
                    // set. Independently managed MemberPress rules continue to
                    // apply and must be part of the proposed policy too.
                    if (!$is_transition_source && !isset($replaced_rule_ids[(int) $rule->ID])) {
                        $new[$key] = $condition;
                    }
                }
            }
            foreach ($this->target_policy_keys($target_id) as $policy_key) {
                foreach ((array) ($compiled[$policy_key]['conditions'] ?? array()) as $key => $condition) {
                    $new[$key] = $condition;
                }
            }
            $pairs[] = array('old' => $old, 'new' => $new);
        }
        return $pairs;
    }

    private function target_policy_keys($target_id) {
        $post_type = get_post_type((int) $target_id);
        if (TSOL_Library_Content_Model::ITEM_POST_TYPE === $post_type) {
            $course_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_COURSE_ID, true);
            $series_id = (int) get_post_meta($target_id, TSOL_Library_Content_Model::META_SERIES_ID, true);
            return $course_id > 0 ? $this->target_policy_keys($course_id) : $this->target_policy_keys($series_id);
        }
        if (TSOL_Library_Content_Model::COURSE_POST_TYPE === $post_type) {
            $keys = array($this->post_group_key('course', $target_id));
            if ($this->course_is_masterclass($target_id)) {
                array_unshift($keys, 'collection:masterclasses');
            }
            return $keys;
        }
        if (TSOL_Library_Content_Model::SERIES_POST_TYPE === $post_type) {
            return array('series:all', $this->post_group_key('series', $target_id));
        }
        return array();
    }

    private function library_targets() {
        return array_map('intval', get_posts(array(
            'post_type' => TSOL_Library_Content_Model::post_types(),
            'post_status' => array_values(get_post_stati()),
            'posts_per_page' => -1,
            'fields' => 'ids',
            'suppress_filters' => true,
        )));
    }

    private function conditions_allow($conditions, $context) {
        if (!empty($context['is_admin']) || empty($conditions)) {
            return true;
        }
        foreach ($conditions as $condition) {
            $type = (string) $condition['access_type'];
            $value = (string) $condition['access_condition'];
            if ('membership' === $type && in_array((int) $value, $context['memberships'], true)) {
                return true;
            }
            if ('member' === $type && $value === $context['login']) {
                return true;
            }
            if ('role' === $type && in_array($value, $context['roles'], true)) {
                return true;
            }
            if ('capability' === $type && in_array($value, $context['capabilities'], true)) {
                return true;
            }
        }
        return false;
    }

    private function stage_state() {
        $state = get_option(self::STAGE_OPTION, array());
        return is_array($state) ? $state : array();
    }

    private function assert_memberpress() {
        if (!class_exists('MeprRule') || !class_exists('MeprRuleAccessCondition') || !class_exists('MeprUser')) {
            throw new RuntimeException('MemberPress is unavailable; Access Groups fail closed.');
        }
        if ($this->masterclasses_term_id() <= 0) {
            throw new RuntimeException('The Masterclasses collection is unavailable.');
        }
    }

    private function clear_rule_cache() {
        MeprRule::$all_rules = null;
        delete_transient('mepr_all_models_for_class_meprrule');
    }

    private function with_lock($callback) {
        if (!add_option(self::LOCK_OPTION, time(), '', 'no')) {
            throw new RuntimeException('Another Access Groups operation is already running.');
        }
        try {
            return call_user_func($callback);
        } finally {
            delete_option(self::LOCK_OPTION);
        }
    }
}
