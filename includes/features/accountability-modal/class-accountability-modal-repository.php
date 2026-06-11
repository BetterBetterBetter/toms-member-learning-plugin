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
                GROUP BY p.ID, p.post_title, group_id, g.name
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

            $calls[] = array(
                'event_id' => $event_id,
                'group_id' => $group_id,
                'event_title' => $event_title,
                'group_name' => $group_name,
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
            'meta_key' => TSOL_Accountability_Modal_Submission_Handler::META_COMPLETED,
            'meta_value' => '1',
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
        );
    }

    private function table_exists($table_name) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;
    }
}
