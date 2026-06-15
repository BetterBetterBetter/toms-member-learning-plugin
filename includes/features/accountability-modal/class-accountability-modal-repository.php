<?php
/**
 * Accountability modal data access.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Accountability_Modal_Repository {

    private $joinable_calls = null;

    public function get_joinable_calls() {
        if ($this->joinable_calls !== null) {
            return $this->joinable_calls;
        }

        global $wpdb;

        $groups_table = $wpdb->prefix . 'groups_group';
        $parent_group_id = (int) apply_filters('tsol_site_accountability_parent_group_id', 2);

        if (!$this->table_exists($groups_table)) {
            $this->joinable_calls = array();
            return $this->joinable_calls;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT
                    p.ID AS event_id,
                    p.post_title AS event_title,
                    CAST(eg.meta_value AS UNSIGNED) AS group_id,
                    g.name AS group_name,
                    g.description AS group_description,
                    MAX(
                        CASE
                            WHEN LOWER(TRIM(COALESCE(gf.meta_value, ''))) IN ('1', 'true', 'yes', 'on')
                            THEN 1
                            ELSE 0
                        END
                    ) AS is_waitlist
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} eg
                    ON eg.post_id = p.ID
                    AND eg.meta_key = 'event_group'
                INNER JOIN {$groups_table} g
                    ON g.group_id = CAST(eg.meta_value AS UNSIGNED)
                    AND g.parent_id = %d
                LEFT JOIN {$wpdb->postmeta} gf
                    ON gf.post_id = p.ID
                    AND gf.meta_key = 'group_full'
                WHERE p.post_type = 'mec-events'
                    AND p.post_status = 'publish'
                GROUP BY p.ID, p.post_title, group_id, g.name, g.description
                HAVING is_waitlist = 0
                ORDER BY p.post_title ASC",
                $parent_group_id
            )
        );

        $calls = array();

        foreach ((array) $rows as $row) {
            $event_id = (int) $row->event_id;
            $group_id = (int) $row->group_id;

            if (!$event_id || !$group_id) {
                continue;
            }

            $event_title = trim((string) $row->event_title);
            $group_name = trim((string) $row->group_name);
            $description = trim((string) $row->group_description);
            $facilitator = $this->get_event_facilitator_name($event_id);

            $calls[] = array(
                'event_id' => $event_id,
                'group_id' => $group_id,
                'event_title' => $event_title,
                'group_name' => $group_name,
                'group_description' => $description,
                'facilitator' => $facilitator,
                'label' => $event_title !== '' ? $event_title : $group_name,
            );
        }

        /**
         * Filters joinable accountability calls shown in the modal intake form.
         *
         * @param array $calls Joinable call rows.
         */
        $this->joinable_calls = array_values((array) apply_filters('tsol_site_accountability_modal_joinable_calls', $calls));

        return $this->joinable_calls;
    }

    public function get_joinable_call_map() {
        $map = array();

        foreach ($this->get_joinable_calls() as $call) {
            $map[(int) $call['event_id']] = $call;
        }

        return $map;
    }

    public function get_candidate_groups_for_calls($event_ids) {
        $event_ids = array_values(array_unique(array_filter(array_map('absint', (array) $event_ids))));
        $joinable_map = $this->get_joinable_call_map();
        $groups = array();

        foreach ($event_ids as $event_id) {
            if (!isset($joinable_map[$event_id])) {
                continue;
            }

            $call = $joinable_map[$event_id];
            $group_id = (int) $call['group_id'];

            if (!$group_id || isset($groups[$group_id])) {
                continue;
            }

            $groups[$group_id] = $this->prepare_group_candidate($call);
        }

        return array_values($groups);
    }

    public function get_all_joinable_groups() {
        $groups = array();

        foreach ($this->get_joinable_calls() as $call) {
            $group_id = (int) $call['group_id'];

            if (!$group_id || isset($groups[$group_id])) {
                continue;
            }

            $groups[$group_id] = $this->prepare_group_candidate($call);
        }

        return array_values($groups);
    }

    public function get_accountability_groups() {
        global $wpdb;

        $groups_table = $wpdb->prefix . 'groups_group';
        $parent_group_id = (int) apply_filters('tsol_site_accountability_parent_group_id', 2);

        if (!$this->table_exists($groups_table)) {
            return array();
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT group_id, name, description
                FROM {$groups_table}
                WHERE parent_id = %d
                ORDER BY name ASC",
                $parent_group_id
            )
        );

        $groups = array();

        foreach ((array) $rows as $row) {
            $groups[] = array(
                'group_id' => (int) $row->group_id,
                'group_name' => trim((string) $row->name),
                'description' => trim((string) $row->description),
            );
        }

        return $groups;
    }

    public function get_group_bio($group_id, $name, $description, $event_title = '', $facilitator = '') {
        $override = TSOL_Accountability_Modal_Settings::get_group_bio_override($group_id);
        $base = $override !== '' ? $override : trim((string) $description);

        if ($base === '') {
            $base = trim((string) $name);
        }

        $details = array();

        if (trim((string) $facilitator) !== '') {
            $details[] = sprintf(
                /* translators: %s: Facilitator name. */
                __('Facilitator: %s', 'tomschooloflife-plugin'),
                trim((string) $facilitator)
            );
        }

        if (trim((string) $event_title) !== '') {
            $details[] = sprintf(
                /* translators: %s: Event title. */
                __('Call: %s', 'tomschooloflife-plugin'),
                trim((string) $event_title)
            );
        }

        $bio = trim($base . ($details ? "\n" . implode("\n", $details) : ''));

        /**
         * Filters the bio text used for accountability group matching.
         *
         * @param string $bio         Bio text.
         * @param int    $group_id    itthinx group ID.
         * @param string $name        Group name.
         * @param string $description Group description.
         * @param string $event_title Linked event title.
         * @param string $facilitator Facilitator term name.
         */
        return (string) apply_filters('tsol_site_accountability_group_bio', $bio, (int) $group_id, (string) $name, (string) $description, (string) $event_title, (string) $facilitator);
    }

    public function get_event_facilitator_name($event_id) {
        $organizer_id = get_post_meta((int) $event_id, 'mec_organizer_id', true);
        $organizer_id = maybe_unserialize($organizer_id);

        if (is_array($organizer_id)) {
            $organizer_id = reset($organizer_id);
        }

        $organizer_id = absint($organizer_id);

        if (!$organizer_id) {
            return '';
        }

        $term = get_term($organizer_id);

        if (!$term || is_wp_error($term) || empty($term->name)) {
            return '';
        }

        return trim((string) $term->name);
    }

    public function engine_is_active() {
        return class_exists('Groups_User_Group') && class_exists('AccountabilityGroupsHandler');
    }

    public function join_user_to_group($user_id, $group_id) {
        $user_id = (int) $user_id;
        $group_id = (int) $group_id;

        if (!$this->engine_is_active()) {
            return new WP_Error(
                'tsol_accountability_engine_inactive',
                __('Accountability group joining is not available right now.', 'tomschooloflife-plugin')
            );
        }

        if (!$user_id || !$group_id || !$this->group_is_accountability_child($group_id)) {
            return new WP_Error(
                'tsol_accountability_invalid_group',
                __('That accountability group is not available.', 'tomschooloflife-plugin')
            );
        }

        $existing_group_ids = $this->get_user_accountability_group_ids($user_id);
        $already_only_member = count($existing_group_ids) === 1 && (int) $existing_group_ids[0] === $group_id;

        if ($already_only_member) {
            return true;
        }

        foreach ($existing_group_ids as $existing_group_id) {
            $deleted = Groups_User_Group::delete($user_id, (int) $existing_group_id);

            if ($deleted === false) {
                return new WP_Error(
                    'tsol_accountability_group_transfer_failed',
                    __('We could not move you out of your previous accountability group.', 'tomschooloflife-plugin')
                );
            }
        }

        $created = Groups_User_Group::create(array(
            'user_id' => $user_id,
            'group_id' => $group_id,
        ));

        if ($created === false) {
            return new WP_Error(
                'tsol_accountability_group_join_failed',
                __('We could not join that accountability group.', 'tomschooloflife-plugin')
            );
        }

        return true;
    }

    public function group_is_accountability_child($group_id) {
        global $wpdb;

        $group_id = (int) $group_id;
        $groups_table = $wpdb->prefix . 'groups_group';
        $parent_group_id = (int) apply_filters('tsol_site_accountability_parent_group_id', 2);

        if (!$group_id || !$this->table_exists($groups_table)) {
            return false;
        }

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1
                FROM {$groups_table}
                WHERE group_id = %d
                    AND parent_id = %d
                LIMIT 1",
                $group_id,
                $parent_group_id
            )
        );

        return $result === '1';
    }

    public function user_has_accountability_group($user_id) {
        $user_id = (int) $user_id;

        if (!$user_id) {
            return false;
        }

        global $wpdb;

        $groups_table = $wpdb->prefix . 'groups_group';
        $user_group_table = $wpdb->prefix . 'groups_user_group';
        $parent_group_id = (int) apply_filters('tsol_site_accountability_parent_group_id', 2);

        if (!$this->table_exists($groups_table) || !$this->table_exists($user_group_table)) {
            return true;
        }

        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT 1
                FROM {$user_group_table} ug
                INNER JOIN {$groups_table} g ON g.group_id = ug.group_id
                WHERE ug.user_id = %d
                    AND g.parent_id = %d
                LIMIT 1",
                $user_id,
                $parent_group_id
            )
        );

        return $result === '1';
    }

    public function get_submissions($limit = 100) {
        $users = get_users(array(
            'meta_key' => TSOL_Accountability_Modal_Submission_Handler::META_SUBMITTED_AT,
            'number' => absint($limit),
            'fields' => 'all',
        ));

        $submissions = array();

        foreach ($users as $user) {
            $selected_calls = get_user_meta($user->ID, TSOL_Accountability_Modal_Submission_Handler::META_SELECTED_CALLS, true);
            $answers = get_user_meta($user->ID, TSOL_Accountability_Modal_Submission_Handler::META_ANSWERS, true);

            $submissions[] = array(
                'user' => $user,
                'professional_goals' => (string) get_user_meta($user->ID, TSOL_Accountability_Modal_Submission_Handler::META_PROFESSIONAL_GOALS, true),
                'personal_goals' => (string) get_user_meta($user->ID, TSOL_Accountability_Modal_Submission_Handler::META_PERSONAL_GOALS, true),
                'tsol_reason' => (string) get_user_meta($user->ID, TSOL_Accountability_Modal_Submission_Handler::META_TSOL_REASON, true),
                'occupation' => (string) get_user_meta($user->ID, TSOL_Accountability_Modal_Submission_Handler::META_OCCUPATION, true),
                'selected_calls' => is_array($selected_calls) ? $selected_calls : array(),
                'answers' => is_array($answers) ? $answers : array(),
                'submitted_at' => (string) get_user_meta($user->ID, TSOL_Accountability_Modal_Submission_Handler::META_SUBMITTED_AT, true),
                'joined_group_id' => (int) get_user_meta($user->ID, TSOL_Accountability_Modal_Submission_Handler::META_JOINED_GROUP_ID, true),
                'joined_event_id' => (int) get_user_meta($user->ID, TSOL_Accountability_Modal_Submission_Handler::META_JOINED_EVENT_ID, true),
                'joined_at' => (string) get_user_meta($user->ID, TSOL_Accountability_Modal_Submission_Handler::META_JOINED_AT, true),
                'requested_group_id' => (int) get_user_meta($user->ID, TSOL_Accountability_Modal_Submission_Handler::META_REQUESTED_GROUP_ID, true),
                'requested_event_id' => (int) get_user_meta($user->ID, TSOL_Accountability_Modal_Submission_Handler::META_REQUESTED_EVENT_ID, true),
                'requested_at' => (string) get_user_meta($user->ID, TSOL_Accountability_Modal_Submission_Handler::META_REQUESTED_AT, true),
            );
        }

        usort($submissions, function($left, $right) {
            return strcmp($right['submitted_at'], $left['submitted_at']);
        });

        return $submissions;
    }

    public function delete_submission($user_id) {
        $user_id = (int) $user_id;

        if (!$user_id || !get_user_by('id', $user_id)) {
            return false;
        }

        $had_submission = false;

        foreach ($this->get_submission_meta_keys() as $meta_key) {
            if (metadata_exists('user', $user_id, $meta_key)) {
                $had_submission = true;
            }

            delete_user_meta($user_id, $meta_key);
        }

        return $had_submission;
    }

    private function get_submission_meta_keys() {
        return array(
            TSOL_Accountability_Modal_Submission_Handler::META_COMPLETED,
            TSOL_Accountability_Modal_Submission_Handler::META_PROFESSIONAL_GOALS,
            TSOL_Accountability_Modal_Submission_Handler::META_PERSONAL_GOALS,
            TSOL_Accountability_Modal_Submission_Handler::META_TSOL_REASON,
            TSOL_Accountability_Modal_Submission_Handler::META_OCCUPATION,
            TSOL_Accountability_Modal_Submission_Handler::META_SELECTED_CALLS,
            TSOL_Accountability_Modal_Submission_Handler::META_ANSWERS,
            TSOL_Accountability_Modal_Submission_Handler::META_SUBMITTED_AT,
            TSOL_Accountability_Modal_Submission_Handler::META_JOINED_GROUP_ID,
            TSOL_Accountability_Modal_Submission_Handler::META_JOINED_EVENT_ID,
            TSOL_Accountability_Modal_Submission_Handler::META_JOINED_AT,
            TSOL_Accountability_Modal_Submission_Handler::META_REQUESTED_GROUP_ID,
            TSOL_Accountability_Modal_Submission_Handler::META_REQUESTED_EVENT_ID,
            TSOL_Accountability_Modal_Submission_Handler::META_REQUESTED_AT,
        );
    }

    private function prepare_group_candidate($call) {
        $group_id = (int) $call['group_id'];
        $group_name = isset($call['group_name']) ? (string) $call['group_name'] : '';
        $event_title = isset($call['event_title']) ? (string) $call['event_title'] : '';
        $description = isset($call['group_description']) ? (string) $call['group_description'] : '';
        $facilitator = isset($call['facilitator']) ? (string) $call['facilitator'] : '';

        return array(
            'group_id' => $group_id,
            'group_name' => $group_name,
            'event_id' => (int) $call['event_id'],
            'event_title' => $event_title,
            'label' => isset($call['label']) ? (string) $call['label'] : ($event_title !== '' ? $event_title : $group_name),
            'facilitator' => $facilitator,
            'bio' => $this->get_group_bio($group_id, $group_name, $description, $event_title, $facilitator),
        );
    }

    private function get_user_accountability_group_ids($user_id) {
        global $wpdb;

        $user_id = (int) $user_id;
        $groups_table = $wpdb->prefix . 'groups_group';
        $user_group_table = $wpdb->prefix . 'groups_user_group';
        $parent_group_id = (int) apply_filters('tsol_site_accountability_parent_group_id', 2);

        if (!$user_id || !$this->table_exists($groups_table) || !$this->table_exists($user_group_table)) {
            return array();
        }

        $group_ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT ug.group_id
                FROM {$user_group_table} ug
                INNER JOIN {$groups_table} g ON g.group_id = ug.group_id
                WHERE ug.user_id = %d
                    AND g.parent_id = %d",
                $user_id,
                $parent_group_id
            )
        );

        return array_values(array_unique(array_filter(array_map('absint', (array) $group_ids))));
    }

    private function table_exists($table_name) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
    }
}
