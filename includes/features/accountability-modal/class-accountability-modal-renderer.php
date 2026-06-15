<?php
/**
 * Accountability modal markup.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Accountability_Modal_Renderer {

    private const LAUNCHER_ICON_ATTACHMENT_ID = 100146;

    public function render($joinable_calls, $auto_open, $content = null, $display_rules = null) {
        $content = is_array($content) ? wp_parse_args($content, TSOL_Accountability_Modal_Settings::default_content()) : TSOL_Accountability_Modal_Settings::get_content();
        $display_rules = is_array($display_rules) ? wp_parse_args($display_rules, TSOL_Accountability_Modal_Settings::default_display_rules()) : TSOL_Accountability_Modal_Settings::get_display_rules();
        $launcher_class = 'tsol-accountability-modal-launcher tsol-accountability-modal-launcher--' . sanitize_html_class($display_rules['launcher_position']);
        $questions = TSOL_Accountability_Modal_Settings::get_enabled_questions($content);
        $total_steps = max(1, count($questions));

        ?>
        <div class="tsol-accountability-modal" data-tsol-accountability-modal data-tsol-accountability-modal-auto-open="<?php echo esc_attr($auto_open ? '1' : '0'); ?>" hidden>
            <div class="tsol-accountability-modal__backdrop" data-tsol-accountability-modal-close></div>
            <div class="tsol-accountability-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="tsol-accountability-modal-title" aria-describedby="tsol-accountability-modal-intro" tabindex="-1">
                <button type="button" class="tsol-accountability-modal__close" aria-label="<?php esc_attr_e('Close', 'tomschooloflife-plugin'); ?>" data-tsol-accountability-modal-close>
                    <span aria-hidden="true">&times;</span>
                </button>

                <div class="tsol-accountability-modal__header">
                    <?php $this->render_brand_mark(); ?>
                    <p class="tsol-accountability-modal__eyebrow"><?php echo esc_html($content['header_eyebrow']); ?></p>
                    <h2 id="tsol-accountability-modal-title"><?php echo esc_html($content['header_title']); ?></h2>
                    <p id="tsol-accountability-modal-intro" class="tsol-accountability-modal__intro"><?php echo esc_html($content['header_intro']); ?></p>
                </div>

                <div class="tsol-accountability-modal__progress" aria-label="<?php esc_attr_e('Form progress', 'tomschooloflife-plugin'); ?>">
                    <div class="tsol-accountability-modal__progress-copy" aria-live="polite">
                        <?php echo esc_html($content['progress_label']); ?>
                        <span data-tsol-accountability-modal-current-step>1</span>
                        <?php echo esc_html($content['progress_of_label']); ?>
                        <span data-tsol-accountability-modal-total-steps><?php echo esc_html((string) $total_steps); ?></span>
                    </div>
                    <div class="tsol-accountability-modal__progress-line" role="progressbar" aria-label="<?php esc_attr_e('Form progress', 'tomschooloflife-plugin'); ?>" aria-valuemin="1" aria-valuemax="<?php echo esc_attr((string) $total_steps); ?>" aria-valuenow="1" data-tsol-accountability-modal-progress-meter>
                        <span data-tsol-accountability-modal-progress-bar></span>
                    </div>
                </div>

                <form class="tsol-accountability-modal__form" data-tsol-accountability-modal-form novalidate>
                    <?php foreach ($questions as $index => $question) : ?>
                        <?php $this->render_question_step($question, $index, $joinable_calls, $content, $index !== 0); ?>
                    <?php endforeach; ?>

                    <section class="tsol-accountability-modal__results" data-tsol-accountability-modal-results hidden aria-live="polite" tabindex="-1"></section>

                    <div class="tsol-accountability-modal__status" data-tsol-accountability-modal-status aria-live="polite"></div>

                    <div class="tsol-accountability-modal__actions">
                        <button type="button" class="tsol-accountability-modal__button tsol-accountability-modal__button--secondary" data-tsol-accountability-modal-back hidden>
                            <?php echo esc_html($content['back_button']); ?>
                        </button>
                        <button type="button" class="tsol-accountability-modal__button tsol-accountability-modal__button--primary" data-tsol-accountability-modal-next>
                            <?php echo esc_html($content['continue_button']); ?>
                        </button>
                        <button type="submit" class="tsol-accountability-modal__button tsol-accountability-modal__button--primary" data-tsol-accountability-modal-submit hidden>
                            <?php echo esc_html($content['submit_button']); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php if ($auto_open) : ?>
            <button
                type="button"
                class="<?php echo esc_attr($launcher_class); ?>"
                data-tsol-accountability-modal-launcher
                data-tsol-launcher-dock-item
                data-tsol-launcher-dock-position="<?php echo esc_attr($display_rules['launcher_position']); ?>"
                data-tsol-launcher-dock-priority="20"
                hidden
                aria-label="<?php esc_attr_e('Finish accountability group form', 'tomschooloflife-plugin'); ?>"
                title="<?php esc_attr_e('Finish accountability group form', 'tomschooloflife-plugin'); ?>"
            >
                <span class="tsol-accountability-modal-launcher__bubble" data-tsol-accountability-modal-launcher-bubble hidden aria-hidden="true"></span>
                <?php $this->render_launcher_icon(); ?>
            </button>
        <?php endif; ?>
        <?php
    }

    private function render_launcher_icon() {
        $icon_url = $this->get_launcher_icon_url();

        if (!$icon_url) {
            echo '<span class="tsol-accountability-modal-launcher__icon tsol-accountability-modal-launcher__icon--fallback" aria-hidden="true">T</span>';
            return;
        }

        ?>
        <span class="tsol-accountability-modal-launcher__icon" style="<?php echo esc_attr("--tsol-launcher-icon: url('" . esc_url_raw($icon_url) . "');"); ?>" aria-hidden="true"></span>
        <?php
    }

    private function get_launcher_icon_url() {
        $attachment_id = (int) apply_filters('tsol_site_accountability_launcher_icon_attachment_id', self::LAUNCHER_ICON_ATTACHMENT_ID);
        $icon_url = $attachment_id ? wp_get_attachment_image_url($attachment_id, 'full') : '';

        if (!$icon_url) {
            $icon_url = get_site_icon_url(512);
        }

        return (string) apply_filters('tsol_site_accountability_launcher_icon_url', $icon_url);
    }

    private function render_question_step($question, $index, $joinable_calls, $content, $is_hidden) {
        $type = isset($question['type']) ? $question['type'] : 'text';
        $heading_class = $type === 'accountability_calls'
            ? 'tsol-accountability-modal__step-heading tsol-accountability-modal__step-heading--split'
            : 'tsol-accountability-modal__step-heading';

        ?>
        <section class="tsol-accountability-modal__step" data-tsol-accountability-modal-step="<?php echo esc_attr((string) $index); ?>" <?php echo $is_hidden ? 'hidden' : ''; ?>>
            <div class="<?php echo esc_attr($heading_class); ?>">
                <div>
                    <?php if (!empty($question['eyebrow'])) : ?>
                        <p><?php echo esc_html($question['eyebrow']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($question['title'])) : ?>
                        <h3><?php echo esc_html($question['title']); ?></h3>
                    <?php endif; ?>
                    <?php if (!empty($question['helper'])) : ?>
                        <span><?php echo esc_html($question['helper']); ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($type === 'accountability_calls') : ?>
                    <span class="tsol-accountability-modal__selected-count" data-tsol-accountability-modal-call-count>
                        <?php esc_html_e('0 selected', 'tomschooloflife-plugin'); ?>
                    </span>
                <?php endif; ?>
            </div>

            <?php $this->render_question_field($question, $joinable_calls, $content); ?>
        </section>
        <?php
    }

    private function render_question_field($question, $joinable_calls, $content) {
        $type = isset($question['type']) ? $question['type'] : 'text';

        if ($type === 'textarea') {
            $this->render_textarea_field($question);
            return;
        }

        if ($type === 'number') {
            $this->render_number_field($question);
            return;
        }

        if ($type === 'range') {
            $this->render_range_field($question);
            return;
        }

        if ($type === 'select') {
            $this->render_select_field($question);
            return;
        }

        if ($type === 'checkbox' || $type === 'radio') {
            $this->render_choice_field($question, $type);
            return;
        }

        if ($type === 'accountability_calls') {
            $this->render_accountability_calls_field($question, $joinable_calls, $content);
            return;
        }

        $this->render_text_field($question);
    }

    private function render_text_field($question) {
        $field_id = $this->get_field_id($question);
        $options = TSOL_Accountability_Modal_Settings::get_question_options($question);

        ?>
        <div class="tsol-accountability-modal__field">
            <?php $this->render_label($field_id, $question); ?>
            <input id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($this->get_answer_name($question)); ?>" type="text" placeholder="<?php echo esc_attr($question['placeholder']); ?>" <?php echo $this->is_required($question) ? 'required' : ''; ?>>
            <?php $this->render_quick_picks($field_id, $options); ?>
        </div>
        <?php
    }

    private function render_textarea_field($question) {
        $field_id = $this->get_field_id($question);
        $options = TSOL_Accountability_Modal_Settings::get_question_options($question);

        ?>
        <div class="tsol-accountability-modal__field">
            <?php $this->render_label($field_id, $question); ?>
            <textarea id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($this->get_answer_name($question)); ?>" rows="4" placeholder="<?php echo esc_attr($question['placeholder']); ?>" <?php echo $this->is_required($question) ? 'required' : ''; ?>></textarea>
            <?php $this->render_quick_picks($field_id, $options); ?>
        </div>
        <?php
    }

    private function render_number_field($question) {
        $field_id = $this->get_field_id($question);

        ?>
        <div class="tsol-accountability-modal__field">
            <?php $this->render_label($field_id, $question); ?>
            <input id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($this->get_answer_name($question)); ?>" type="number" placeholder="<?php echo esc_attr($question['placeholder']); ?>" <?php $this->render_numeric_attributes($question); ?> <?php echo $this->is_required($question) ? 'required' : ''; ?>>
        </div>
        <?php
    }

    private function render_range_field($question) {
        $field_id = $this->get_field_id($question);
        $min = $question['min'] !== '' ? $question['min'] : '0';
        $max = $question['max'] !== '' ? $question['max'] : '10';
        $default = is_numeric($min) && is_numeric($max) ? (string) round(((float) $min + (float) $max) / 2, 2) : $min;

        ?>
        <div class="tsol-accountability-modal__field">
            <?php $this->render_label($field_id, $question); ?>
            <div class="tsol-accountability-modal__range">
                <input id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($this->get_answer_name($question)); ?>" type="range" value="<?php echo esc_attr($default); ?>" <?php $this->render_numeric_attributes($question, $min, $max); ?> <?php echo $this->is_required($question) ? 'required' : ''; ?> data-tsol-accountability-range>
                <output for="<?php echo esc_attr($field_id); ?>" data-tsol-accountability-range-value><?php echo esc_html($default); ?></output>
            </div>
        </div>
        <?php
    }

    private function render_select_field($question) {
        $field_id = $this->get_field_id($question);
        $options = TSOL_Accountability_Modal_Settings::get_question_options($question);

        ?>
        <div class="tsol-accountability-modal__field">
            <?php $this->render_label($field_id, $question); ?>
            <select id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($this->get_answer_name($question)); ?>" <?php echo $this->is_required($question) ? 'required' : ''; ?>>
                <option value=""><?php echo esc_html($question['placeholder'] ? $question['placeholder'] : __('Choose one', 'tomschooloflife-plugin')); ?></option>
                <?php foreach ($options as $option) : ?>
                    <option value="<?php echo esc_attr($option['value']); ?>"><?php echo esc_html($option['label']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }

    private function render_choice_field($question, $type) {
        $field_id = $this->get_field_id($question);
        $options = TSOL_Accountability_Modal_Settings::get_question_options($question);
        $name = $this->get_answer_name($question) . ($type === 'checkbox' ? '[]' : '');
        $required_attrs = $this->is_required($question) ? 'data-tsol-accountability-required-options' : '';

        ?>
        <fieldset class="tsol-accountability-modal__field" <?php echo $required_attrs; ?>>
            <legend><?php echo esc_html($this->get_field_label($question)); ?></legend>
            <div class="tsol-accountability-modal__choice-list">
                <?php foreach ($options as $option_index => $option) : ?>
                    <?php $this->render_choice_option($question, $type, $name, $option, $field_id . '-' . $option_index); ?>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php
    }

    private function render_accountability_calls_field($question, $joinable_calls, $content) {
        $required_attrs = $this->is_required($question) ? 'data-tsol-accountability-required-options' : '';

        ?>
        <fieldset class="tsol-accountability-modal__field tsol-accountability-modal__calls" data-tsol-accountability-modal-calls <?php echo $required_attrs; ?>>
            <legend class="screen-reader-text"><?php echo esc_html($this->get_field_label($question)); ?></legend>
            <?php if ($joinable_calls) : ?>
                <div class="tsol-accountability-modal__call-list">
                    <?php foreach ($joinable_calls as $call) : ?>
                        <?php $this->render_call_option($call); ?>
                    <?php endforeach; ?>
                </div>
            <?php else : ?>
                <p class="tsol-accountability-modal__empty"><?php echo esc_html($content['availability_empty']); ?></p>
            <?php endif; ?>
        </fieldset>
        <?php
    }

    private function render_choice_option($question, $type, $name, $option, $input_id) {
        ?>
        <label class="tsol-accountability-modal__choice" for="<?php echo esc_attr($input_id); ?>" data-tsol-accountability-modal-choice-option>
            <input id="<?php echo esc_attr($input_id); ?>" name="<?php echo esc_attr($name); ?>" type="<?php echo esc_attr($type); ?>" value="<?php echo esc_attr($option['value']); ?>">
            <span class="tsol-accountability-modal__choice-check" aria-hidden="true"></span>
            <span><?php echo esc_html($option['label']); ?></span>
        </label>
        <?php
    }

    private function render_quick_picks($field_id, $options) {
        if (!$options) {
            return;
        }

        ?>
        <div class="tsol-accountability-modal__quick-picks" aria-label="<?php esc_attr_e('Common answers', 'tomschooloflife-plugin'); ?>">
            <?php foreach ($options as $option) : ?>
                <button type="button" data-tsol-accountability-modal-fill="<?php echo esc_attr($option['label']); ?>" data-tsol-accountability-modal-fill-target="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($option['label']); ?></button>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_label($field_id, $question) {
        ?>
        <label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($this->get_field_label($question)); ?></label>
        <?php
    }

    private function render_numeric_attributes($question, $fallback_min = null, $fallback_max = null) {
        $attributes = array('min', 'max', 'step');

        foreach ($attributes as $attribute) {
            $value = isset($question[$attribute]) ? $question[$attribute] : '';

            if ($attribute === 'min' && $value === '' && $fallback_min !== null) {
                $value = $fallback_min;
            }

            if ($attribute === 'max' && $value === '' && $fallback_max !== null) {
                $value = $fallback_max;
            }

            if ($value === '') {
                continue;
            }

            echo ' ' . esc_attr($attribute) . '="' . esc_attr($value) . '"';
        }
    }

    private function get_field_id($question) {
        return 'tsol-accountability-question-' . sanitize_key($question['key']);
    }

    private function get_answer_name($question) {
        return 'answers[' . sanitize_key($question['key']) . ']';
    }

    private function get_field_label($question) {
        return !empty($question['label']) ? $question['label'] : $question['title'];
    }

    private function is_required($question) {
        return isset($question['required']) && $question['required'] === '1';
    }

    private function render_brand_mark() {
        if (!function_exists('has_custom_logo') || !has_custom_logo()) {
            return;
        }

        $custom_logo_id = get_theme_mod('custom_logo');
        $logo_url = wp_get_attachment_image_url($custom_logo_id, 'full');

        if (!$logo_url) {
            return;
        }

        ?>
        <img class="tsol-accountability-modal__logo" src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr(get_bloginfo('name')); ?>">
        <?php
    }

    private function render_call_option($call) {
        $event_id = (int) $call['event_id'];
        $input_id = 'tsol-accountability-call-' . $event_id;
        ?>
        <label class="tsol-accountability-modal__call" for="<?php echo esc_attr($input_id); ?>" data-tsol-accountability-modal-call-option>
            <input id="<?php echo esc_attr($input_id); ?>" name="available_calls[]" type="checkbox" value="<?php echo esc_attr($event_id); ?>">
            <span class="tsol-accountability-modal__call-check" aria-hidden="true"></span>
            <span class="tsol-accountability-modal__call-copy">
                <strong><?php echo esc_html($call['label']); ?></strong>
                <?php if (!empty($call['group_name']) && $call['group_name'] !== $call['label']) : ?>
                    <small><?php echo esc_html($call['group_name']); ?></small>
                <?php endif; ?>
            </span>
        </label>
        <?php
    }
}
