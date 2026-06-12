<?php
/**
 * Cookie consent admin screens.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Cookie_Consent_Admin {

    public const PAGE_SLUG = 'tsol-cookie-consent';

    public function init() {
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function register_settings() {
        TSOL_Cookie_Consent_Settings::register_settings();
    }

    public function display_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview';
        $allowed_tabs = array('overview', 'banner', 'behavior', 'categories', 'scripts');

        if (!in_array($tab, $allowed_tabs, true)) {
            $tab = 'overview';
        }

        $settings = TSOL_Cookie_Consent_Settings::get_settings();

        ?>
        <div class="wrap tsol-site-plugin-admin tsol-cookie-admin">
            <h1><?php esc_html_e('Cookie Consent', 'tomschooloflife-plugin'); ?></h1>
            <?php settings_errors(); ?>
            <?php $this->render_tabs($tab); ?>

            <div class="tsol-site-panel">
                <?php if ($tab === 'overview') : ?>
                    <?php $this->render_overview_tab($settings); ?>
                <?php else : ?>
                    <form method="post" action="options.php">
                        <?php settings_fields(TSOL_Cookie_Consent_Settings::OPTION_GROUP); ?>

                        <?php
                        if ($tab === 'banner') {
                            $this->render_banner_tab($settings);
                        } elseif ($tab === 'behavior') {
                            $this->render_behavior_tab($settings);
                        } elseif ($tab === 'categories') {
                            $this->render_categories_tab($settings);
                        } elseif ($tab === 'scripts') {
                            $this->render_scripts_tab($settings);
                        }
                        ?>

                        <?php submit_button(__('Save cookie consent settings', 'tomschooloflife-plugin')); ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function render_tabs($active_tab) {
        $tabs = array(
            'overview' => __('Overview', 'tomschooloflife-plugin'),
            'banner' => __('Banner', 'tomschooloflife-plugin'),
            'behavior' => __('Behavior', 'tomschooloflife-plugin'),
            'categories' => __('Categories', 'tomschooloflife-plugin'),
            'scripts' => __('Scripts', 'tomschooloflife-plugin'),
        );

        echo '<nav class="nav-tab-wrapper tsol-site-tabs" aria-label="' . esc_attr__('Cookie consent settings', 'tomschooloflife-plugin') . '">';

        foreach ($tabs as $tab => $label) {
            $classes = 'nav-tab';

            if ($tab === $active_tab) {
                $classes .= ' nav-tab-active';
            }

            printf(
                '<a class="%1$s" href="%2$s">%3$s</a>',
                esc_attr($classes),
                esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab)),
                esc_html($label)
            );
        }

        echo '</nav>';
    }

    private function render_overview_tab($settings) {
        $script_payload = TSOL_Cookie_Consent_Settings::get_script_payload($settings);
        $managed_script_count = count($script_payload['analytics']['urls']) + count($script_payload['analytics']['inline']) + count($script_payload['marketing']['urls']) + count($script_payload['marketing']['inline']);

        ?>
        <div class="tsol-cookie-hero">
            <div>
                <p><?php esc_html_e('Consent management', 'tomschooloflife-plugin'); ?></p>
                <h2><?php esc_html_e('A branded privacy layer for Tom\'s School of Life', 'tomschooloflife-plugin'); ?></h2>
                <span><?php esc_html_e('This controls the public banner, preference center, Google Consent Mode defaults, and any scripts added to the managed script buckets.', 'tomschooloflife-plugin'); ?></span>
            </div>
            <a class="button button-primary" href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=banner')); ?>">
                <?php esc_html_e('Edit banner', 'tomschooloflife-plugin'); ?>
            </a>
        </div>

        <div class="tsol-site-stat-grid">
            <?php $this->render_stat_card(__('Feature', 'tomschooloflife-plugin'), $settings['enabled'] === '1' ? __('Enabled', 'tomschooloflife-plugin') : __('Off', 'tomschooloflife-plugin'), $settings['enabled'] === '1' ? 'ok' : 'warning'); ?>
            <?php $this->render_stat_card(__('Consent Mode', 'tomschooloflife-plugin'), $settings['google_consent_mode'] === '1' ? __('Enabled', 'tomschooloflife-plugin') : __('Off', 'tomschooloflife-plugin'), $settings['google_consent_mode'] === '1' ? 'ok' : 'warning'); ?>
            <?php $this->render_stat_card(__('Consent version', 'tomschooloflife-plugin'), $settings['consent_version']); ?>
            <?php $this->render_stat_card(__('Managed scripts', 'tomschooloflife-plugin'), (string) $managed_script_count); ?>
        </div>

        <div class="tsol-site-admin-grid">
            <section class="tsol-site-card">
                <h2><?php esc_html_e('Launch checklist', 'tomschooloflife-plugin'); ?></h2>
                <ul class="tsol-site-check-list">
                    <li><?php esc_html_e('Banner and preference center are enabled.', 'tomschooloflife-plugin'); ?></li>
                    <li><?php esc_html_e('Google Consent Mode defaults are printed early in the page head.', 'tomschooloflife-plugin'); ?></li>
                    <li><?php esc_html_e('Privacy Policy and Terms links point to Access Hub legal pages by default.', 'tomschooloflife-plugin'); ?></li>
                    <li><?php esc_html_e('Analytics and marketing script buckets are available for consent-controlled loading.', 'tomschooloflife-plugin'); ?></li>
                </ul>
            </section>

            <section class="tsol-site-card tsol-cookie-warning-card">
                <h2><?php esc_html_e('Important implementation note', 'tomschooloflife-plugin'); ?></h2>
                <p><?php esc_html_e('This feature can control scripts added in the Scripts tab and it can send Consent Mode defaults to Google. It cannot stop non-essential snippets that another plugin or theme prints before consent unless those snippets are moved here or configured inside GTM to obey consent.', 'tomschooloflife-plugin'); ?></p>
                <p><?php esc_html_e('Known tracking to audit on TSoL: GTM, Google Ads, Google Analytics, DoubleClick, Vimeo embeds, WooCommerce attribution/sourcebuster, and Kissmetrics-style scripts.', 'tomschooloflife-plugin'); ?></p>
            </section>
        </div>

        <div class="tsol-cookie-preview" aria-label="<?php esc_attr_e('Cookie banner preview', 'tomschooloflife-plugin'); ?>">
            <div class="tsol-cookie-preview__mark"><?php echo TSOL_Cookie_Consent_Settings::get_cookie_icon_svg('tsol-cookie-preview__icon'); ?></div>
            <div>
                <p><?php echo esc_html($settings['banner_eyebrow']); ?></p>
                <h3><?php echo esc_html($settings['banner_title']); ?></h3>
                <span><?php echo esc_html($settings['banner_intro']); ?></span>
            </div>
            <div class="tsol-cookie-preview__actions">
                <span><?php echo esc_html($settings['manage_label']); ?></span>
                <strong><?php echo esc_html($settings['accept_all_label']); ?></strong>
            </div>
        </div>
        <?php
    }

    private function render_banner_tab($settings) {
        ?>
        <div class="tsol-cookie-settings-grid">
            <section class="tsol-site-card">
                <h2><?php esc_html_e('Banner copy', 'tomschooloflife-plugin'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <?php $this->render_text_row('banner_eyebrow', __('Eyebrow', 'tomschooloflife-plugin'), $settings['banner_eyebrow']); ?>
                        <?php $this->render_text_row('banner_title', __('Title', 'tomschooloflife-plugin'), $settings['banner_title']); ?>
                        <?php $this->render_textarea_row('banner_intro', __('Intro', 'tomschooloflife-plugin'), $settings['banner_intro'], 4); ?>
                        <?php $this->render_text_row('preferences_title', __('Preferences title', 'tomschooloflife-plugin'), $settings['preferences_title']); ?>
                        <?php $this->render_textarea_row('preferences_intro', __('Preferences intro', 'tomschooloflife-plugin'), $settings['preferences_intro'], 3); ?>
                    </tbody>
                </table>
            </section>

            <section class="tsol-site-card">
                <h2><?php esc_html_e('Actions and legal links', 'tomschooloflife-plugin'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <?php $this->render_text_row('accept_all_label', __('Accept all button', 'tomschooloflife-plugin'), $settings['accept_all_label']); ?>
                        <?php $this->render_text_row('reject_all_label', __('Reject optional button', 'tomschooloflife-plugin'), $settings['reject_all_label']); ?>
                        <?php $this->render_text_row('manage_label', __('Manage button', 'tomschooloflife-plugin'), $settings['manage_label']); ?>
                        <?php $this->render_text_row('save_label', __('Save button', 'tomschooloflife-plugin'), $settings['save_label']); ?>
                        <?php $this->render_text_row('settings_label', __('Floating button label', 'tomschooloflife-plugin'), $settings['settings_label']); ?>
                        <?php $this->render_text_row('privacy_url', __('Privacy Policy URL', 'tomschooloflife-plugin'), $settings['privacy_url'], 'url'); ?>
                        <?php $this->render_text_row('terms_url', __('Terms URL', 'tomschooloflife-plugin'), $settings['terms_url'], 'url'); ?>
                    </tbody>
                </table>
            </section>
        </div>
        <?php
    }

    private function render_behavior_tab($settings) {
        ?>
        <div class="tsol-cookie-settings-grid">
            <section class="tsol-site-card">
                <h2><?php esc_html_e('Display behavior', 'tomschooloflife-plugin'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <?php $this->render_checkbox_row('enabled', __('Enable cookie consent', 'tomschooloflife-plugin'), $settings['enabled'], __('Turns the entire cookie consent feature on or off.', 'tomschooloflife-plugin')); ?>
                        <?php $this->render_checkbox_row('banner_enabled', __('Show banner to undecided visitors', 'tomschooloflife-plugin'), $settings['banner_enabled'], __('If off, the preference center can still be opened from the floating button/admin bar.', 'tomschooloflife-plugin')); ?>
                        <?php $this->render_select_row('banner_position', __('Banner placement', 'tomschooloflife-plugin'), $settings['banner_position'], TSOL_Cookie_Consent_Settings::get_banner_positions()); ?>
                        <?php $this->render_checkbox_row('show_reopen_button', __('Show floating settings button', 'tomschooloflife-plugin'), $settings['show_reopen_button'], __('Lets visitors reopen their cookie preferences after saving a choice.', 'tomschooloflife-plugin')); ?>
                        <?php $this->render_select_row('reopen_position', __('Floating button placement', 'tomschooloflife-plugin'), $settings['reopen_position'], TSOL_Cookie_Consent_Settings::get_reopen_positions()); ?>
                        <?php $this->render_checkbox_row('show_admin_bar_button', __('Show admin bar preview button', 'tomschooloflife-plugin'), $settings['show_admin_bar_button'], __('Lets admins open the preference center from the frontend admin bar.', 'tomschooloflife-plugin')); ?>
                    </tbody>
                </table>
            </section>

            <section class="tsol-site-card">
                <h2><?php esc_html_e('Consent rules', 'tomschooloflife-plugin'); ?></h2>
                <table class="form-table" role="presentation">
                    <tbody>
                        <?php $this->render_text_row('consent_version', __('Consent version', 'tomschooloflife-plugin'), $settings['consent_version']); ?>
                        <?php $this->render_number_row('cookie_lifetime_days', __('Remember choice for', 'tomschooloflife-plugin'), $settings['cookie_lifetime_days'], 30, 730, __('days', 'tomschooloflife-plugin')); ?>
                        <?php $this->render_checkbox_row('respect_gpc', __('Respect Global Privacy Control', 'tomschooloflife-plugin'), $settings['respect_gpc'], __('When a browser sends GPC, marketing cookies stay off even if the visitor accepts all.', 'tomschooloflife-plugin')); ?>
                        <?php $this->render_checkbox_row('google_consent_mode', __('Enable Google Consent Mode v2', 'tomschooloflife-plugin'), $settings['google_consent_mode'], __('Prints denied defaults before GTM and updates Google consent after the user chooses.', 'tomschooloflife-plugin')); ?>
                        <?php $this->render_text_row('gtm_container_id', __('GTM container ID', 'tomschooloflife-plugin'), $settings['gtm_container_id']); ?>
                        <?php $this->render_text_row('google_ads_id', __('Google Ads ID', 'tomschooloflife-plugin'), $settings['google_ads_id']); ?>
                    </tbody>
                </table>
            </section>
        </div>
        <?php
    }

    private function render_categories_tab($settings) {
        ?>
        <div class="tsol-cookie-category-editor">
            <?php $this->render_category_editor('necessary', __('Essential cookies', 'tomschooloflife-plugin'), $settings, false); ?>
            <?php $this->render_category_editor('analytics', __('Analytics cookies', 'tomschooloflife-plugin'), $settings, true); ?>
            <?php $this->render_category_editor('marketing', __('Marketing cookies', 'tomschooloflife-plugin'), $settings, true); ?>
        </div>
        <?php
    }

    private function render_scripts_tab($settings) {
        ?>
        <div class="tsol-cookie-script-intro">
            <h2><?php esc_html_e('Consent-controlled scripts', 'tomschooloflife-plugin'); ?></h2>
            <p><?php esc_html_e('Use this tab for scripts that should not load until a visitor accepts analytics or marketing cookies. Put external script URLs one per line. Put inline JavaScript without opening or closing script tags. Separate multiple inline blocks with a line containing only three dashes.', 'tomschooloflife-plugin'); ?></p>
        </div>

        <div class="tsol-cookie-script-grid">
            <section class="tsol-site-card">
                <h2><?php esc_html_e('Analytics scripts', 'tomschooloflife-plugin'); ?></h2>
                <?php $this->render_textarea_field('analytics_script_urls', __('External script URLs', 'tomschooloflife-plugin'), $settings['analytics_script_urls'], 6, 'code'); ?>
                <?php $this->render_textarea_field('analytics_inline_scripts', __('Inline JavaScript', 'tomschooloflife-plugin'), $settings['analytics_inline_scripts'], 10, 'code'); ?>
            </section>

            <section class="tsol-site-card">
                <h2><?php esc_html_e('Marketing scripts', 'tomschooloflife-plugin'); ?></h2>
                <?php $this->render_textarea_field('marketing_script_urls', __('External script URLs', 'tomschooloflife-plugin'), $settings['marketing_script_urls'], 6, 'code'); ?>
                <?php $this->render_textarea_field('marketing_inline_scripts', __('Inline JavaScript', 'tomschooloflife-plugin'), $settings['marketing_inline_scripts'], 10, 'code'); ?>
            </section>
        </div>

        <section class="tsol-site-card tsol-cookie-warning-card">
            <h2><?php esc_html_e('What to move here', 'tomschooloflife-plugin'); ?></h2>
            <p><?php esc_html_e('Move direct Google Analytics, Google Ads, retargeting, heatmap, and other non-essential snippets here unless they are already managed inside GTM with consent-aware triggers.', 'tomschooloflife-plugin'); ?></p>
            <p><?php esc_html_e('Do not move required login, checkout, security, or Access authentication scripts into optional categories.', 'tomschooloflife-plugin'); ?></p>
        </section>
        <?php
    }

    private function render_category_editor($key, $heading, $settings, $can_disable) {
        $label_key = $key . '_label';
        $description_key = $key . '_description';
        $enabled_key = $key . '_enabled';

        ?>
        <section class="tsol-site-card tsol-cookie-category-card">
            <div class="tsol-cookie-category-card__header">
                <h2><?php echo esc_html($heading); ?></h2>
                <?php if ($can_disable) : ?>
                    <label>
                        <input type="hidden" name="<?php echo esc_attr(TSOL_Cookie_Consent_Settings::OPTION . '[' . $enabled_key . ']'); ?>" value="0">
                        <input type="checkbox" name="<?php echo esc_attr(TSOL_Cookie_Consent_Settings::OPTION . '[' . $enabled_key . ']'); ?>" value="1" <?php checked('1', $settings[$enabled_key]); ?>>
                        <?php esc_html_e('Enabled', 'tomschooloflife-plugin'); ?>
                    </label>
                <?php else : ?>
                    <span class="tsol-site-status tsol-site-status--ok"><?php esc_html_e('Always on', 'tomschooloflife-plugin'); ?></span>
                <?php endif; ?>
            </div>

            <?php $this->render_textarea_field($description_key, __('Visitor-facing description', 'tomschooloflife-plugin'), $settings[$description_key], 4); ?>
            <?php $this->render_text_field($label_key, __('Display label', 'tomschooloflife-plugin'), $settings[$label_key]); ?>
        </section>
        <?php
    }

    private function render_stat_card($label, $value, $status = '') {
        $classes = 'tsol-site-stat-card';

        if ($status) {
            $classes .= ' tsol-cookie-stat-card--' . sanitize_html_class($status);
        }

        printf(
            '<div class="%1$s"><span>%2$s</span><strong>%3$s</strong></div>',
            esc_attr($classes),
            esc_html($label),
            esc_html($value)
        );
    }

    private function render_checkbox_row($key, $label, $value, $description = '') {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td>
                <label>
                    <input type="hidden" name="<?php echo esc_attr(TSOL_Cookie_Consent_Settings::OPTION . '[' . $key . ']'); ?>" value="0">
                    <input type="checkbox" name="<?php echo esc_attr(TSOL_Cookie_Consent_Settings::OPTION . '[' . $key . ']'); ?>" value="1" <?php checked('1', $value); ?>>
                    <?php esc_html_e('Enabled', 'tomschooloflife-plugin'); ?>
                </label>
                <?php if ($description) : ?>
                    <p class="description"><?php echo esc_html($description); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function render_text_row($key, $label, $value, $type = 'text') {
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr('tsol-cookie-' . $key); ?>"><?php echo esc_html($label); ?></label></th>
            <td><?php $this->render_text_input($key, $value, $type); ?></td>
        </tr>
        <?php
    }

    private function render_textarea_row($key, $label, $value, $rows = 4) {
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr('tsol-cookie-' . $key); ?>"><?php echo esc_html($label); ?></label></th>
            <td><?php $this->render_textarea_input($key, $value, $rows); ?></td>
        </tr>
        <?php
    }

    private function render_select_row($key, $label, $value, $options) {
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr('tsol-cookie-' . $key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <select id="<?php echo esc_attr('tsol-cookie-' . $key); ?>" name="<?php echo esc_attr(TSOL_Cookie_Consent_Settings::OPTION . '[' . $key . ']'); ?>">
                    <?php foreach ($options as $option_value => $option_label) : ?>
                        <option value="<?php echo esc_attr($option_value); ?>" <?php selected($option_value, $value); ?>><?php echo esc_html($option_label); ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
        </tr>
        <?php
    }

    private function render_number_row($key, $label, $value, $min, $max, $suffix = '') {
        ?>
        <tr>
            <th scope="row"><label for="<?php echo esc_attr('tsol-cookie-' . $key); ?>"><?php echo esc_html($label); ?></label></th>
            <td>
                <input id="<?php echo esc_attr('tsol-cookie-' . $key); ?>" type="number" min="<?php echo esc_attr((string) $min); ?>" max="<?php echo esc_attr((string) $max); ?>" step="1" name="<?php echo esc_attr(TSOL_Cookie_Consent_Settings::OPTION . '[' . $key . ']'); ?>" value="<?php echo esc_attr((string) $value); ?>" class="small-text">
                <?php if ($suffix) : ?>
                    <span><?php echo esc_html($suffix); ?></span>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function render_text_field($key, $label, $value) {
        echo '<div class="tsol-cookie-field">';
        echo '<label for="' . esc_attr('tsol-cookie-' . $key) . '">' . esc_html($label) . '</label>';
        $this->render_text_input($key, $value);
        echo '</div>';
    }

    private function render_textarea_field($key, $label, $value, $rows = 4, $class = '') {
        echo '<div class="tsol-cookie-field">';
        echo '<label for="' . esc_attr('tsol-cookie-' . $key) . '">' . esc_html($label) . '</label>';
        $this->render_textarea_input($key, $value, $rows, $class);
        echo '</div>';
    }

    private function render_text_input($key, $value, $type = 'text') {
        printf(
            '<input id="%1$s" type="%2$s" class="regular-text" name="%3$s" value="%4$s">',
            esc_attr('tsol-cookie-' . $key),
            esc_attr($type),
            esc_attr(TSOL_Cookie_Consent_Settings::OPTION . '[' . $key . ']'),
            esc_attr($value)
        );
    }

    private function render_textarea_input($key, $value, $rows = 4, $class = '') {
        printf(
            '<textarea id="%1$s" class="large-text %2$s" rows="%3$s" name="%4$s">%5$s</textarea>',
            esc_attr('tsol-cookie-' . $key),
            esc_attr($class),
            esc_attr((string) $rows),
            esc_attr(TSOL_Cookie_Consent_Settings::OPTION . '[' . $key . ']'),
            esc_textarea($value)
        );
    }
}
