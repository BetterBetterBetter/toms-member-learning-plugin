<?php
/**
 * Accountability modal settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Accountability_Modal_Settings {

    public const DISPLAY_OPTION = 'tsol_accountability_modal_display_rules';
    public const CONTENT_OPTION = 'tsol_accountability_modal_content';
    public const GROUP_BIOS_OPTION = 'tsol_accountability_modal_group_bios';
    public const GEMINI_API_KEY_OPTION = 'tsol_site_gemini_api_key';
    public const GEMINI_MODEL_OPTION = 'tsol_site_gemini_model';
    public const DEFAULT_GEMINI_MODEL = 'gemini-2.5-flash';
    public const DISPLAY_GROUP = 'tsol_accountability_modal_display_rules';
    public const CONTENT_GROUP = 'tsol_accountability_modal_content';
    public const GROUP_BIOS_GROUP = 'tsol_accountability_modal_group_bios';

    public static function register_settings() {
        register_setting(self::DISPLAY_GROUP, self::DISPLAY_OPTION, array(
            'sanitize_callback' => array(__CLASS__, 'sanitize_display_rules'),
            'default' => self::default_display_rules(),
        ));

        register_setting(self::CONTENT_GROUP, self::CONTENT_OPTION, array(
            'sanitize_callback' => array(__CLASS__, 'sanitize_content'),
            'default' => self::default_content(),
        ));

        register_setting(self::GROUP_BIOS_GROUP, self::GROUP_BIOS_OPTION, array(
            'sanitize_callback' => array(__CLASS__, 'sanitize_group_bios'),
            'default' => array(),
        ));
    }

    public static function get_display_rules() {
        $stored = get_option(self::DISPLAY_OPTION, null);
        $rules = is_array($stored) ? $stored : self::default_display_rules();
        $rules = wp_parse_args($rules, self::default_display_rules());
        $rules['page_ids'] = self::sanitize_page_ids($rules['page_ids']);
        $rules['scroll_threshold'] = max(0, absint($rules['scroll_threshold']));
        $rules['draft_ttl_days'] = max(1, absint($rules['draft_ttl_days']));
        $rules['launcher_position'] = self::sanitize_launcher_position($rules['launcher_position']);
        $rules['fit_threshold'] = self::sanitize_fit_threshold($rules['fit_threshold']);

        return $rules;
    }

    public static function get_content() {
        $stored = get_option(self::CONTENT_OPTION, null);
        $content = is_array($stored) ? $stored : self::default_content();
        $content = wp_parse_args($content, self::default_content());

        $content['questions'] = self::sanitize_questions(isset($content['questions']) ? $content['questions'] : self::default_questions());

        return $content;
    }

    public static function get_target_page_ids() {
        $rules = self::get_display_rules();

        return $rules['page_ids'];
    }

    public static function default_display_rules() {
        return array(
            'enabled' => '1',
            'page_ids' => self::default_page_ids(),
            'scroll_threshold' => 320,
            'hide_if_current_member' => '1',
            'hide_after_submission' => '1',
            'show_admin_bar_button' => '1',
            'save_draft_answers' => '1',
            'draft_ttl_days' => 7,
            'show_resume_launcher' => '1',
            'launcher_position' => 'bottom_right',
            'animate_resume_launcher' => '1',
            'ai_matching_enabled' => '1',
            'fit_threshold' => '0.5',
        );
    }

    public static function default_content() {
        return array(
            'header_eyebrow' => 'Quick fit check',
            'header_title' => 'Let\'s find the right accountability group for you',
            'header_intro' => 'A few honest answers help us understand your goals, your week, and which calls you could actually make.',
            'progress_label' => 'Question',
            'progress_of_label' => 'of',
            'professional_eyebrow' => 'First, the work side.',
            'professional_title' => 'What are you aiming for professionally right now?',
            'professional_helper' => 'Business, career, projects, money, skills, habits. A sentence or two is enough.',
            'professional_label' => 'My professional goals are...',
            'personal_eyebrow' => 'Now the life side.',
            'personal_title' => 'What would make life feel better outside work?',
            'personal_helper' => 'Health, discipline, faith, family, focus, friendships, time, energy. Say it plainly.',
            'personal_label' => 'My personal goals are...',
            'reason_eyebrow' => 'A little context.',
            'reason_title' => 'What made you say yes to TSoL?',
            'reason_helper' => 'The real reason is the useful one. No polished answer needed.',
            'reason_label' => 'I signed up because...',
            'occupation_eyebrow' => 'Your normal week.',
            'occupation_title' => 'What does your day-to-day look like?',
            'occupation_helper' => 'This helps us understand your schedule and season of life.',
            'occupation_label' => 'What do you do for a living?',
            'occupation_placeholder' => 'Stay at home parent, retired, full time employment',
            'occupation_quick_picks' => "Full time employment\nSelf-employed\nStay at home parent\nRetired",
            'availability_eyebrow' => 'Last thing.',
            'availability_title' => 'Which weekly calls could you realistically attend?',
            'availability_helper' => 'Choose every time that could work. We will only show calls that are open to join.',
            'availability_empty' => 'No joinable accountability calls are available right now.',
            'questions' => self::default_questions(),
            'back_button' => 'Back',
            'continue_button' => 'Continue',
            'submit_button' => 'Submit',
            'success_message' => 'Thanks. Your accountability group intake has been submitted.',
        );
    }

    public static function default_questions() {
        return array(
            array(
                'key' => 'professional_goals',
                'enabled' => '1',
                'required' => '1',
                'type' => 'textarea',
                'eyebrow' => 'First, the work side.',
                'title' => 'What are you aiming for professionally right now?',
                'helper' => 'Business, career, projects, money, skills, habits. A sentence or two is enough.',
                'label' => 'My professional goals are...',
                'placeholder' => '',
                'options' => '',
                'min' => '',
                'max' => '',
                'step' => '',
            ),
            array(
                'key' => 'personal_goals',
                'enabled' => '1',
                'required' => '1',
                'type' => 'textarea',
                'eyebrow' => 'Now the life side.',
                'title' => 'What would make life feel better outside work?',
                'helper' => 'Health, discipline, faith, family, focus, friendships, time, energy. Say it plainly.',
                'label' => 'My personal goals are...',
                'placeholder' => '',
                'options' => '',
                'min' => '',
                'max' => '',
                'step' => '',
            ),
            array(
                'key' => 'tsol_reason',
                'enabled' => '1',
                'required' => '1',
                'type' => 'textarea',
                'eyebrow' => 'A little context.',
                'title' => 'What made you say yes to TSoL?',
                'helper' => 'The real reason is the useful one. No polished answer needed.',
                'label' => 'I signed up because...',
                'placeholder' => '',
                'options' => '',
                'min' => '',
                'max' => '',
                'step' => '',
            ),
            array(
                'key' => 'occupation',
                'enabled' => '1',
                'required' => '1',
                'type' => 'text',
                'eyebrow' => 'Your normal week.',
                'title' => 'What does your day-to-day look like?',
                'helper' => 'This helps us understand your schedule and season of life.',
                'label' => 'What do you do for a living?',
                'placeholder' => 'Stay at home parent, retired, full time employment',
                'options' => "Full time employment\nSelf-employed\nStay at home parent\nRetired",
                'min' => '',
                'max' => '',
                'step' => '',
            ),
            array(
                'key' => 'available_calls',
                'enabled' => '1',
                'required' => '1',
                'type' => 'accountability_calls',
                'eyebrow' => 'Last thing.',
                'title' => 'Which weekly calls could you realistically attend?',
                'helper' => 'Choose every time that could work. We will only show calls that are open to join.',
                'label' => 'Check all calls you can attend weekly',
                'placeholder' => '',
                'options' => '',
                'min' => '',
                'max' => '',
                'step' => '',
            ),
        );
    }

    public static function get_question_types() {
        return array(
            'text' => __('Text field', 'tomschooloflife-plugin'),
            'textarea' => __('Text area', 'tomschooloflife-plugin'),
            'number' => __('Number', 'tomschooloflife-plugin'),
            'range' => __('Number slider', 'tomschooloflife-plugin'),
            'select' => __('Select dropdown', 'tomschooloflife-plugin'),
            'checkbox' => __('Checkbox select', 'tomschooloflife-plugin'),
            'radio' => __('Radio select', 'tomschooloflife-plugin'),
            'accountability_calls' => __('Joinable accountability calls', 'tomschooloflife-plugin'),
        );
    }

    public static function get_enabled_questions($content = null) {
        $content = is_array($content) ? $content : self::get_content();
        $questions = isset($content['questions']) ? self::sanitize_questions($content['questions']) : self::default_questions();

        return array_values(array_filter($questions, function($question) {
            return isset($question['enabled']) && $question['enabled'] === '1';
        }));
    }

    public static function get_question_options($question) {
        $raw_options = isset($question['options']) ? $question['options'] : '';
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw_options);
        $options = array();
        $used_values = array();

        foreach ((array) $lines as $line) {
            $label = trim((string) $line);

            if ($label === '') {
                continue;
            }

            $value = sanitize_title($label);

            if ($value === '') {
                $value = 'option';
            }

            $base_value = $value;
            $suffix = 2;

            while (isset($used_values[$value])) {
                $value = $base_value . '-' . $suffix;
                $suffix++;
            }

            $used_values[$value] = true;

            $options[] = array(
                'value' => $value,
                'label' => $label,
            );
        }

        return $options;
    }

    public static function question_type_uses_options($type) {
        return in_array($type, array('text', 'textarea', 'select', 'checkbox', 'radio'), true);
    }

    public static function question_type_uses_numeric_settings($type) {
        return in_array($type, array('number', 'range'), true);
    }

    public static function get_launcher_positions() {
        return array(
            'bottom_right' => __('Bottom right', 'tomschooloflife-plugin'),
            'bottom_left' => __('Bottom left', 'tomschooloflife-plugin'),
            'top_right' => __('Top right', 'tomschooloflife-plugin'),
            'top_left' => __('Top left', 'tomschooloflife-plugin'),
        );
    }

    public static function get_group_bio_overrides() {
        $stored = get_option(self::GROUP_BIOS_OPTION, array());

        return self::sanitize_group_bios(is_array($stored) ? $stored : array());
    }

    public static function get_group_bio_override($group_id) {
        $overrides = self::get_group_bio_overrides();
        $group_id = (int) $group_id;

        return isset($overrides[$group_id]) ? $overrides[$group_id] : '';
    }

    public static function ai_matching_enabled() {
        $rules = self::get_display_rules();

        return isset($rules['ai_matching_enabled']) && $rules['ai_matching_enabled'] === '1';
    }

    public static function get_fit_threshold() {
        $rules = self::get_display_rules();

        return (float) $rules['fit_threshold'];
    }

    public static function get_gemini_api_key() {
        return trim((string) get_option(self::GEMINI_API_KEY_OPTION, ''));
    }

    public static function get_gemini_model() {
        $model = trim((string) get_option(self::GEMINI_MODEL_OPTION, self::DEFAULT_GEMINI_MODEL));

        return $model !== '' ? $model : self::DEFAULT_GEMINI_MODEL;
    }

    public static function sanitize_gemini_model($value) {
        if (!is_scalar($value)) {
            return self::DEFAULT_GEMINI_MODEL;
        }
        $value = sanitize_text_field((string) wp_unslash($value));
        $options = self::get_gemini_model_options();

        return isset($options[$value]) ? $value : self::DEFAULT_GEMINI_MODEL;
    }

    public static function get_gemini_model_options() {
        $options = array(
            'gemini-2.5-flash' => __('Gemini 2.5 Flash', 'tomschooloflife-plugin'),
            'gemini-2.5-pro' => __('Gemini 2.5 Pro', 'tomschooloflife-plugin'),
            'gemini-2.5-flash-lite' => __('Gemini 2.5 Flash-Lite', 'tomschooloflife-plugin'),
        );

        /**
         * Filters the Gemini model choices retained from the original shared
         * TSOL settings screen.
         *
         * @param array $options Model value => label map.
         */
        $options = (array) apply_filters('tsol_site_gemini_model_options', $options);

        /**
         * Filters the Gemini model choices for Accountability Modal matching.
         *
         * @param array $options Model value => label map.
         */
        return (array) apply_filters('tsol_site_accountability_gemini_model_options', $options);
    }

    public static function sanitize_display_rules($value) {
        $value = is_array($value) ? $value : array();
        $current = get_option(self::DISPLAY_OPTION, self::default_display_rules());
        $current = is_array($current) ? wp_parse_args($current, self::default_display_rules()) : self::default_display_rules();

        return array(
            'enabled' => self::sanitize_checkbox(isset($value['enabled']) ? $value['enabled'] : '0'),
            'page_ids' => self::sanitize_page_ids(isset($value['page_ids']) ? $value['page_ids'] : array()),
            'scroll_threshold' => max(0, absint(isset($value['scroll_threshold']) ? $value['scroll_threshold'] : 0)),
            'hide_if_current_member' => self::sanitize_checkbox(isset($value['hide_if_current_member']) ? $value['hide_if_current_member'] : '0'),
            'hide_after_submission' => self::sanitize_checkbox(isset($value['hide_after_submission']) ? $value['hide_after_submission'] : '0'),
            'show_admin_bar_button' => self::sanitize_checkbox(isset($value['show_admin_bar_button']) ? $value['show_admin_bar_button'] : '0'),
            'save_draft_answers' => self::sanitize_checkbox(isset($value['save_draft_answers']) ? $value['save_draft_answers'] : '0'),
            'draft_ttl_days' => max(1, absint(isset($value['draft_ttl_days']) ? $value['draft_ttl_days'] : 7)),
            'show_resume_launcher' => self::sanitize_checkbox(isset($value['show_resume_launcher']) ? $value['show_resume_launcher'] : '0'),
            'launcher_position' => self::sanitize_launcher_position(isset($value['launcher_position']) ? wp_unslash($value['launcher_position']) : 'bottom_right'),
            'animate_resume_launcher' => self::sanitize_checkbox(isset($value['animate_resume_launcher']) ? $value['animate_resume_launcher'] : '0'),
            'ai_matching_enabled' => self::sanitize_checkbox(array_key_exists('ai_matching_enabled', $value) ? $value['ai_matching_enabled'] : $current['ai_matching_enabled']),
            'fit_threshold' => self::sanitize_fit_threshold(array_key_exists('fit_threshold', $value) ? wp_unslash($value['fit_threshold']) : $current['fit_threshold']),
        );
    }

    public static function sanitize_content($value) {
        $value = is_array($value) ? $value : array();
        $defaults = self::default_content();
        $sanitized = array();

        foreach ($defaults as $key => $default) {
            if ($key === 'questions') {
                continue;
            }

            $raw_value = isset($value[$key]) ? wp_unslash($value[$key]) : $default;
            $sanitized[$key] = $key === 'occupation_quick_picks'
                ? self::sanitize_multiline_text($raw_value)
                : sanitize_text_field($raw_value);
        }

        $sanitized['questions'] = self::sanitize_questions(isset($value['questions']) ? $value['questions'] : self::default_questions());

        return $sanitized;
    }

    public static function sanitize_group_bios($value) {
        $value = is_array($value) ? $value : array();
        $bios = array();

        foreach ($value as $group_id => $bio) {
            if (is_array($bio)) {
                continue;
            }

            $group_id = absint($group_id);
            $bio = self::sanitize_multiline_text(wp_unslash($bio));

            if (!$group_id || $bio === '') {
                continue;
            }

            $bios[$group_id] = $bio;
        }

        return $bios;
    }

    public static function get_quick_picks($content = null) {
        $content = is_array($content) ? $content : self::get_content();
        $raw_quick_picks = isset($content['occupation_quick_picks']) ? $content['occupation_quick_picks'] : '';
        $quick_picks = preg_split('/\r\n|\r|\n/', $raw_quick_picks);
        $quick_picks = array_map('trim', (array) $quick_picks);
        $quick_picks = array_filter($quick_picks);

        return array_values(array_unique($quick_picks));
    }

    public static function sanitize_questions($questions) {
        $questions = is_array($questions) ? $questions : array();
        $allowed_types = array_keys(self::get_question_types());
        $sanitized = array();
        $used_keys = array();
        $index = 1;

        foreach ($questions as $question) {
            if (!is_array($question)) {
                continue;
            }

            $raw_key = isset($question['key']) ? wp_unslash($question['key']) : '';

            if ($raw_key === '') {
                $raw_key = isset($question['title']) ? wp_unslash($question['title']) : '';
            }

            if ($raw_key === '') {
                $raw_key = isset($question['label']) ? wp_unslash($question['label']) : '';
            }

            $key = self::sanitize_question_key($raw_key);

            if ($key === '') {
                $key = 'question_' . $index;
            }

            $base_key = $key;
            $suffix = 2;

            while (isset($used_keys[$key])) {
                $key = $base_key . '_' . $suffix;
                $suffix++;
            }

            $used_keys[$key] = true;

            $type = isset($question['type']) ? sanitize_key(wp_unslash($question['type'])) : 'text';

            if (!in_array($type, $allowed_types, true)) {
                $type = 'text';
            }

            $sanitized[] = array(
                'key' => $key,
                'enabled' => self::sanitize_checkbox(isset($question['enabled']) ? $question['enabled'] : '0'),
                'required' => self::sanitize_checkbox(isset($question['required']) ? $question['required'] : '0'),
                'type' => $type,
                'eyebrow' => sanitize_text_field(isset($question['eyebrow']) ? wp_unslash($question['eyebrow']) : ''),
                'title' => sanitize_text_field(isset($question['title']) ? wp_unslash($question['title']) : ''),
                'helper' => sanitize_text_field(isset($question['helper']) ? wp_unslash($question['helper']) : ''),
                'label' => sanitize_text_field(isset($question['label']) ? wp_unslash($question['label']) : ''),
                'placeholder' => sanitize_text_field(isset($question['placeholder']) ? wp_unslash($question['placeholder']) : ''),
                'options' => self::sanitize_multiline_text(isset($question['options']) ? wp_unslash($question['options']) : ''),
                'min' => self::sanitize_numeric_setting(isset($question['min']) ? wp_unslash($question['min']) : ''),
                'max' => self::sanitize_numeric_setting(isset($question['max']) ? wp_unslash($question['max']) : ''),
                'step' => self::sanitize_numeric_setting(isset($question['step']) ? wp_unslash($question['step']) : ''),
            );

            $index++;

            if ($index > 40) {
                break;
            }
        }

        return $sanitized ? $sanitized : self::default_questions();
    }

    public static function default_page_ids() {
        $page = get_page_by_path('accountability');

        return $page ? array((int) $page->ID) : array();
    }

    private static function sanitize_page_ids($page_ids) {
        $page_ids = is_array($page_ids) ? $page_ids : array($page_ids);
        $page_ids = array_map('absint', $page_ids);
        $page_ids = array_filter($page_ids);

        return array_values(array_unique($page_ids));
    }

    private static function sanitize_checkbox($value) {
        return $value === '1' ? '1' : '0';
    }

    private static function sanitize_launcher_position($value) {
        $value = sanitize_key((string) $value);

        return array_key_exists($value, self::get_launcher_positions()) ? $value : 'bottom_right';
    }

    private static function sanitize_question_key($value) {
        $key = str_replace('-', '_', sanitize_title((string) $value));
        $key = sanitize_key($key);

        return trim($key, '_');
    }

    private static function sanitize_multiline_text($value) {
        $lines = preg_split('/\r\n|\r|\n/', (string) $value);
        $lines = array_map('sanitize_text_field', (array) $lines);
        $lines = array_filter(array_map('trim', $lines));

        return implode("\n", $lines);
    }

    private static function sanitize_numeric_setting($value) {
        $value = trim((string) $value);

        if ($value === '' || !is_numeric($value)) {
            return '';
        }

        return (string) (0 + $value);
    }

    private static function sanitize_fit_threshold($value) {
        $value = trim((string) $value);

        if ($value === '' || !is_numeric($value)) {
            return '0.5';
        }

        $value = max(0, min(1, (float) $value));

        return (string) $value;
    }
}
