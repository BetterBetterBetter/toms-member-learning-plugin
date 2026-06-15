<?php
/**
 * Accountability modal submission handling.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Accountability_Modal_Submission_Handler {

    public const NONCE_ACTION = 'tsol_accountability_modal';

    public const META_COMPLETED = 'tsol_accountability_intake_completed';
    public const META_PROFESSIONAL_GOALS = 'tsol_accountability_intake_professional_goals';
    public const META_PERSONAL_GOALS = 'tsol_accountability_intake_personal_goals';
    public const META_TSOL_REASON = 'tsol_accountability_intake_tsol_reason';
    public const META_OCCUPATION = 'tsol_accountability_intake_occupation';
    public const META_SELECTED_CALLS = 'tsol_accountability_intake_available_calls';
    public const META_ANSWERS = 'tsol_accountability_intake_answers';
    public const META_SUBMITTED_AT = 'tsol_accountability_intake_submitted_at';
    public const META_JOINED_GROUP_ID = 'tsol_accountability_intake_joined_group_id';
    public const META_JOINED_EVENT_ID = 'tsol_accountability_intake_joined_event_id';
    public const META_JOINED_AT = 'tsol_accountability_intake_joined_at';
    public const META_REQUESTED_GROUP_ID = 'tsol_accountability_intake_requested_group_id';
    public const META_REQUESTED_EVENT_ID = 'tsol_accountability_intake_requested_event_id';
    public const META_REQUESTED_AT = 'tsol_accountability_intake_requested_at';

    private $repository;
    private $matcher;

    public function __construct(TSOL_Accountability_Modal_Repository $repository, ?TSOL_Accountability_Modal_Matcher $matcher = null) {
        $this->repository = $repository;
        $this->matcher = $matcher ? $matcher : new TSOL_Accountability_Modal_Matcher($repository, new TSOL_Gemini_Client());
    }

    public function init() {
        add_action('wp_ajax_tsol_accountability_modal_submit', array($this, 'handle_submission'));
        add_action('wp_ajax_tsol_accountability_modal_recommend', array($this, 'handle_recommendation'));
        add_action('wp_ajax_tsol_accountability_modal_join', array($this, 'handle_join'));
    }

    public function user_completed_intake($user_id) {
        $user_id = (int) $user_id;

        if (!$user_id) {
            return false;
        }

        return get_user_meta($user_id, self::META_COMPLETED, true) === '1';
    }

    public function handle_submission() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('You need to be logged in to submit this form.', 'tomschooloflife-plugin'),
            ), 401);
        }

        $user_id = get_current_user_id();

        if ($this->repository->user_has_accountability_group($user_id)) {
            wp_send_json_error(array(
                'message' => __('You are already in an accountability group.', 'tomschooloflife-plugin'),
            ), 400);
        }

        $submission = $this->prepare_submission();

        if (is_wp_error($submission)) {
            wp_send_json_error(array(
                'message' => $submission->get_error_message(),
            ), 400);
        }

        $this->store_submission($user_id, $submission, true);

        $content = TSOL_Accountability_Modal_Settings::get_content();

        wp_send_json_success(array(
            'message' => $content['success_message'],
        ));
    }

    public function handle_recommendation() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('You need to be logged in to submit this form.', 'tomschooloflife-plugin'),
            ), 401);
        }

        $user_id = get_current_user_id();

        if ($this->repository->user_has_accountability_group($user_id)) {
            wp_send_json_error(array(
                'message' => __('You are already in an accountability group.', 'tomschooloflife-plugin'),
            ), 400);
        }

        $submission = $this->prepare_submission();

        if (is_wp_error($submission)) {
            wp_send_json_error(array(
                'message' => $submission->get_error_message(),
            ), 400);
        }

        $this->store_submission($user_id, $submission, false);

        $selected_event_ids = wp_list_pluck($submission['selected_calls'], 'event_id');

        wp_send_json_success($this->matcher->recommend($submission, $selected_event_ids));
    }

    public function handle_join() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!is_user_logged_in()) {
            wp_send_json_error(array(
                'message' => __('You need to be logged in to join a group.', 'tomschooloflife-plugin'),
            ), 401);
        }

        $user_id = get_current_user_id();
        $event_id = isset($_POST['event_id']) ? absint(wp_unslash($_POST['event_id'])) : 0;
        $group_id = isset($_POST['group_id']) ? absint(wp_unslash($_POST['group_id'])) : 0;
        $joinable_call_map = $this->repository->get_joinable_call_map();

        if (!$event_id || !isset($joinable_call_map[$event_id])) {
            wp_send_json_error($this->get_unavailable_join_payload(), 409);
        }

        $call = $joinable_call_map[$event_id];
        $server_group_id = (int) $call['group_id'];

        if ($group_id && $group_id !== $server_group_id) {
            wp_send_json_error(array(
                'message' => __('That group choice could not be verified.', 'tomschooloflife-plugin'),
            ), 400);
        }

        $group_id = $server_group_id;

        if (!$this->repository->group_is_accountability_child($group_id)) {
            wp_send_json_error(array(
                'message' => __('That accountability group is not available.', 'tomschooloflife-plugin'),
            ), 400);
        }

        if (!$this->repository->engine_is_active()) {
            $this->store_requested_choice($user_id, $group_id, $event_id, true);

            wp_send_json_success(array(
                'manual' => true,
                'message' => __('Your request was received. A coach will place you in a group.', 'tomschooloflife-plugin'),
                'label' => isset($call['label']) ? $call['label'] : '',
            ));
        }

        $joined = $this->repository->join_user_to_group($user_id, $group_id);

        if (is_wp_error($joined)) {
            wp_send_json_error(array(
                'message' => $joined->get_error_message(),
            ), 500);
        }

        $this->store_joined_choice($user_id, $group_id, $event_id);

        /**
         * Fires after a user joins an accountability group through the modal.
         *
         * @param int $user_id  User ID.
         * @param int $group_id itthinx group ID.
         * @param int $event_id MEC event ID.
         */
        do_action('tsol_site_accountability_user_joined_group', $user_id, $group_id, $event_id);

        wp_send_json_success(array(
            'message' => __('You have joined your accountability group.', 'tomschooloflife-plugin'),
            'label' => isset($call['label']) ? $call['label'] : '',
        ));
    }

    private function prepare_submission() {
        $content = TSOL_Accountability_Modal_Settings::get_content();
        $questions = TSOL_Accountability_Modal_Settings::get_enabled_questions($content);
        $answers = array();
        $legacy_answers = array(
            'professional_goals' => '',
            'personal_goals' => '',
            'tsol_reason' => '',
            'occupation' => '',
        );
        $selected_calls = array();

        foreach ($questions as $question) {
            $key = isset($question['key']) ? sanitize_key($question['key']) : '';

            if ($key === '') {
                continue;
            }

            if ($question['type'] === 'accountability_calls') {
                $selected_calls = $this->get_valid_selected_calls($this->question_is_required($question));

                if (is_wp_error($selected_calls)) {
                    return $selected_calls;
                }

                $answers[$key] = array(
                    'label' => $this->get_question_label($question),
                    'type' => $question['type'],
                    'value' => $selected_calls,
                    'display_value' => implode(', ', wp_list_pluck($selected_calls, 'label')),
                );

                continue;
            }

            $answer = $this->prepare_question_answer($question);

            if (is_wp_error($answer)) {
                return $answer;
            }

            $answers[$key] = array(
                'label' => $this->get_question_label($question),
                'type' => $question['type'],
                'value' => $answer['value'],
                'display_value' => $answer['display_value'],
            );

            if (array_key_exists($key, $legacy_answers)) {
                $legacy_answers[$key] = is_array($answer['display_value'])
                    ? implode(', ', $answer['display_value'])
                    : (string) $answer['display_value'];
            }
        }

        return array(
            'answers' => $answers,
            'professional_goals' => $legacy_answers['professional_goals'],
            'personal_goals' => $legacy_answers['personal_goals'],
            'tsol_reason' => $legacy_answers['tsol_reason'],
            'occupation' => $legacy_answers['occupation'],
            'selected_calls' => $selected_calls,
        );
    }

    private function prepare_question_answer($question) {
        $key = sanitize_key($question['key']);
        $type = isset($question['type']) ? $question['type'] : 'text';
        $answers = isset($_POST['answers']) && is_array($_POST['answers']) ? wp_unslash($_POST['answers']) : array();
        $raw_value = isset($answers[$key]) ? $answers[$key] : '';

        if ($type === 'checkbox') {
            return $this->prepare_choice_answer($question, (array) $raw_value, true);
        }

        if ($type === 'select' || $type === 'radio') {
            return $this->prepare_choice_answer($question, $raw_value, false);
        }

        if ($type === 'number' || $type === 'range') {
            return $this->prepare_numeric_answer($question, $raw_value);
        }

        $value = $type === 'textarea'
            ? sanitize_textarea_field($raw_value)
            : sanitize_text_field($raw_value);

        if ($this->question_is_required($question) && trim($value) === '') {
            return $this->required_field_error($question);
        }

        return array(
            'value' => $value,
            'display_value' => $value,
        );
    }

    private function prepare_choice_answer($question, $raw_value, $allows_multiple) {
        $options = TSOL_Accountability_Modal_Settings::get_question_options($question);
        $option_map = array();

        foreach ($options as $option) {
            $option_map[$option['value']] = $option['label'];
        }

        if (!$option_map && $this->question_is_required($question)) {
            return new WP_Error(
                'tsol_accountability_modal_missing_options',
                sprintf(
                    /* translators: %s: Question label. */
                    __('The question "%s" needs answer choices before it can be submitted.', 'tomschooloflife-plugin'),
                    $this->get_question_label($question)
                )
            );
        }

        if (!$option_map) {
            return array(
                'value' => $allows_multiple ? array() : '',
                'display_value' => $allows_multiple ? array() : '',
            );
        }

        if ($allows_multiple) {
            $values = array_values(array_unique(array_filter(array_map('sanitize_text_field', (array) $raw_value))));

            if ($this->question_is_required($question) && !$values) {
                return $this->required_field_error($question);
            }

            $labels = array();

            foreach ($values as $value) {
                if (!isset($option_map[$value])) {
                    return $this->invalid_choice_error($question);
                }

                $labels[] = $option_map[$value];
            }

            return array(
                'value' => $values,
                'display_value' => $labels,
            );
        }

        $value = sanitize_text_field((string) $raw_value);

        if ($this->question_is_required($question) && $value === '') {
            return $this->required_field_error($question);
        }

        if ($value !== '' && !isset($option_map[$value])) {
            return $this->invalid_choice_error($question);
        }

        return array(
            'value' => $value,
            'display_value' => $value !== '' ? $option_map[$value] : '',
        );
    }

    private function prepare_numeric_answer($question, $raw_value) {
        $value = sanitize_text_field((string) $raw_value);

        if ($this->question_is_required($question) && $value === '') {
            return $this->required_field_error($question);
        }

        if ($value === '') {
            return array(
                'value' => '',
                'display_value' => '',
            );
        }

        if (!is_numeric($value)) {
            return new WP_Error(
                'tsol_accountability_modal_numeric_field',
                sprintf(
                    /* translators: %s: Question label. */
                    __('"%s" needs to be a number.', 'tomschooloflife-plugin'),
                    $this->get_question_label($question)
                )
            );
        }

        $number = 0 + $value;

        if ($question['min'] !== '' && $number < (float) $question['min']) {
            return $this->numeric_range_error($question);
        }

        if ($question['max'] !== '' && $number > (float) $question['max']) {
            return $this->numeric_range_error($question);
        }

        return array(
            'value' => (string) $number,
            'display_value' => (string) $number,
        );
    }

    private function get_valid_selected_calls($required) {
        $selected_call_ids = isset($_POST['available_calls']) ? (array) wp_unslash($_POST['available_calls']) : array();
        $selected_call_ids = array_values(array_unique(array_filter(array_map('absint', $selected_call_ids))));
        $joinable_call_map = $this->repository->get_joinable_call_map();
        $selected_calls = array();

        foreach ($selected_call_ids as $event_id) {
            if (!isset($joinable_call_map[$event_id])) {
                return new WP_Error(
                    'tsol_accountability_modal_unavailable_call',
                    __('One of the selected calls is no longer available.', 'tomschooloflife-plugin')
                );
            }

            $selected_calls[] = $joinable_call_map[$event_id];
        }

        if ($required && $joinable_call_map && !$selected_calls) {
            return new WP_Error(
                'tsol_accountability_modal_call_required',
                __('Please choose at least one call you can attend weekly.', 'tomschooloflife-plugin')
            );
        }

        return $selected_calls;
    }

    private function store_submission($user_id, $submission, $completed = false) {
        update_user_meta($user_id, self::META_ANSWERS, $submission['answers']);
        update_user_meta($user_id, self::META_PROFESSIONAL_GOALS, $submission['professional_goals']);
        update_user_meta($user_id, self::META_PERSONAL_GOALS, $submission['personal_goals']);
        update_user_meta($user_id, self::META_TSOL_REASON, $submission['tsol_reason']);
        update_user_meta($user_id, self::META_OCCUPATION, $submission['occupation']);
        update_user_meta($user_id, self::META_SELECTED_CALLS, $submission['selected_calls']);
        update_user_meta($user_id, self::META_SUBMITTED_AT, current_time('mysql'));

        if ($completed) {
            update_user_meta($user_id, self::META_COMPLETED, '1');
        } else {
            delete_user_meta($user_id, self::META_COMPLETED);
        }
    }

    private function store_joined_choice($user_id, $group_id, $event_id) {
        update_user_meta($user_id, self::META_JOINED_GROUP_ID, (int) $group_id);
        update_user_meta($user_id, self::META_JOINED_EVENT_ID, (int) $event_id);
        update_user_meta($user_id, self::META_JOINED_AT, current_time('mysql'));
        update_user_meta($user_id, self::META_COMPLETED, '1');
        delete_user_meta($user_id, self::META_REQUESTED_GROUP_ID);
        delete_user_meta($user_id, self::META_REQUESTED_EVENT_ID);
        delete_user_meta($user_id, self::META_REQUESTED_AT);
    }

    private function store_requested_choice($user_id, $group_id, $event_id, $completed = false) {
        update_user_meta($user_id, self::META_REQUESTED_GROUP_ID, (int) $group_id);
        update_user_meta($user_id, self::META_REQUESTED_EVENT_ID, (int) $event_id);
        update_user_meta($user_id, self::META_REQUESTED_AT, current_time('mysql'));

        if ($completed) {
            update_user_meta($user_id, self::META_COMPLETED, '1');
        }
    }

    private function get_unavailable_join_payload() {
        return array(
            'code' => 'group_unavailable',
            'message' => __('That group just filled up. Please choose another open group.', 'tomschooloflife-plugin'),
            'recommendations' => array(
                'mode' => 'show_all',
                'has_strong_fit' => false,
                'recommendations' => array(),
                'all_groups' => $this->format_join_payload_groups($this->repository->get_all_joinable_groups()),
            ),
        );
    }

    private function format_join_payload_groups($groups) {
        $formatted = array();

        foreach ((array) $groups as $group) {
            $formatted[] = array(
                'group_id' => (int) $group['group_id'],
                'event_id' => (int) $group['event_id'],
                'label' => (string) $group['label'],
                'group_name' => (string) $group['group_name'],
                'facilitator' => isset($group['facilitator']) ? (string) $group['facilitator'] : '',
                'reason' => '',
                'fit_score' => null,
            );
        }

        return $formatted;
    }

    private function question_is_required($question) {
        return isset($question['required']) && $question['required'] === '1';
    }

    private function get_question_label($question) {
        if (!empty($question['label'])) {
            return $question['label'];
        }

        if (!empty($question['title'])) {
            return $question['title'];
        }

        return __('This question', 'tomschooloflife-plugin');
    }

    private function required_field_error($question) {
        return new WP_Error(
            'tsol_accountability_modal_required_fields',
            sprintf(
                /* translators: %s: Question label. */
                __('Please answer "%s" before submitting.', 'tomschooloflife-plugin'),
                $this->get_question_label($question)
            )
        );
    }

    private function invalid_choice_error($question) {
        return new WP_Error(
            'tsol_accountability_modal_invalid_choice',
            sprintf(
                /* translators: %s: Question label. */
                __('One of the answers for "%s" is not available.', 'tomschooloflife-plugin'),
                $this->get_question_label($question)
            )
        );
    }

    private function numeric_range_error($question) {
        return new WP_Error(
            'tsol_accountability_modal_numeric_range',
            sprintf(
                /* translators: %s: Question label. */
                __('"%s" is outside the allowed range.', 'tomschooloflife-plugin'),
                $this->get_question_label($question)
            )
        );
    }
}
