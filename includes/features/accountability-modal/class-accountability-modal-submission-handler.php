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

    private $repository;

    public function __construct(TSOL_Accountability_Modal_Repository $repository) {
        $this->repository = $repository;
    }

    public function init() {
        add_action('wp_ajax_tsol_accountability_modal_submit', array($this, 'handle_submission'));
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

        $this->store_submission($user_id, $submission);

        $content = TSOL_Accountability_Modal_Settings::get_content();

        wp_send_json_success(array(
            'message' => $content['success_message'],
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

    private function store_submission($user_id, $submission) {
        update_user_meta($user_id, self::META_ANSWERS, $submission['answers']);
        update_user_meta($user_id, self::META_PROFESSIONAL_GOALS, $submission['professional_goals']);
        update_user_meta($user_id, self::META_PERSONAL_GOALS, $submission['personal_goals']);
        update_user_meta($user_id, self::META_TSOL_REASON, $submission['tsol_reason']);
        update_user_meta($user_id, self::META_OCCUPATION, $submission['occupation']);
        update_user_meta($user_id, self::META_SELECTED_CALLS, $submission['selected_calls']);
        update_user_meta($user_id, self::META_SUBMITTED_AT, current_time('mysql'));
        update_user_meta($user_id, self::META_COMPLETED, '1');
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
