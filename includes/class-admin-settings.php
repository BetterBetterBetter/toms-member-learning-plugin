<?php
/**
 * Admin Settings Class
 * Handles WordPress admin interface for Tom's School Of Life site settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Site_Admin_Settings {

    public const GEMINI_API_KEY_OPTION = 'tsol_site_gemini_api_key';
    public const GEMINI_MODEL_OPTION = 'tsol_site_gemini_model';

    private $options_group = 'tsol_site_plugin_settings';
    private $page_slug = 'tomschooloflife-plugin';

    public function init() {
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function register_settings() {
        add_settings_section(
            'tsol_site_general',
            __('General Settings', 'tomschooloflife-plugin'),
            array($this, 'general_section_callback'),
            $this->page_slug
        );

        add_settings_field(
            'tsol_site_plugin_enabled',
            __('Enable Site Features', 'tomschooloflife-plugin'),
            array($this, 'enabled_callback'),
            $this->page_slug,
            'tsol_site_general'
        );

        add_settings_section(
            'tsol_site_integrations',
            __('Integrations', 'tomschooloflife-plugin'),
            array($this, 'integrations_section_callback'),
            $this->page_slug
        );

        add_settings_field(
            self::GEMINI_API_KEY_OPTION,
            __('Gemini API Key', 'tomschooloflife-plugin'),
            array($this, 'gemini_api_key_callback'),
            $this->page_slug,
            'tsol_site_integrations'
        );

        add_settings_field(
            self::GEMINI_MODEL_OPTION,
            __('Gemini Model', 'tomschooloflife-plugin'),
            array($this, 'gemini_model_callback'),
            $this->page_slug,
            'tsol_site_integrations'
        );

        register_setting($this->options_group, 'tsol_site_plugin_enabled', array(
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => '1',
        ));

        register_setting($this->options_group, self::GEMINI_API_KEY_OPTION, array(
            'sanitize_callback' => array($this, 'sanitize_gemini_api_key'),
            'default' => '',
        ));

        register_setting($this->options_group, self::GEMINI_MODEL_OPTION, array(
            'sanitize_callback' => array($this, 'sanitize_gemini_model'),
            'default' => 'gemini-2.5-flash',
        ));
    }

    public function display_page() {
        ?>
        <div class="wrap tsol-site-plugin-admin">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php settings_errors(); ?>

            <div class="tsol-site-status-card">
                <h2><?php esc_html_e('Site Plugin Status', 'tomschooloflife-plugin'); ?></h2>
                <dl>
                    <dt><?php esc_html_e('Plugin version', 'tomschooloflife-plugin'); ?></dt>
                    <dd><?php echo esc_html(TSOL_SITE_PLUGIN_VERSION); ?></dd>

                    <dt><?php esc_html_e('Access Platform SSO', 'tomschooloflife-plugin'); ?></dt>
                    <dd>
                        <?php if (TomsSchoolOfLifePlugin::is_access_sso_available()) : ?>
                            <span class="tsol-site-status tsol-site-status--ok"><?php esc_html_e('Active', 'tomschooloflife-plugin'); ?></span>
                        <?php else : ?>
                            <span class="tsol-site-status tsol-site-status--error"><?php esc_html_e('Missing', 'tomschooloflife-plugin'); ?></span>
                        <?php endif; ?>
                    </dd>

                    <dt><?php esc_html_e('Home URL', 'tomschooloflife-plugin'); ?></dt>
                    <dd><code><?php echo esc_html(home_url()); ?></code></dd>

                    <dt><?php esc_html_e('Gemini API Key', 'tomschooloflife-plugin'); ?></dt>
                    <dd>
                        <?php if (self::get_gemini_api_key()) : ?>
                            <span class="tsol-site-status tsol-site-status--ok"><?php esc_html_e('Configured', 'tomschooloflife-plugin'); ?></span>
                        <?php else : ?>
                            <span class="tsol-site-status tsol-site-status--warning"><?php esc_html_e('Not configured', 'tomschooloflife-plugin'); ?></span>
                        <?php endif; ?>
                    </dd>
                </dl>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields($this->options_group);
                do_settings_sections($this->page_slug);
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function general_section_callback() {
        echo '<p>' . esc_html__('Use this plugin for site-specific features that should not live in the shared Access Platform SSO plugin.', 'tomschooloflife-plugin') . '</p>';
    }

    public function integrations_section_callback() {
        echo '<p>' . esc_html__('Store credentials for external services used by TSOL site features.', 'tomschooloflife-plugin') . '</p>';
    }

    public function enabled_callback() {
        $value = get_option('tsol_site_plugin_enabled', '1');

        echo '<label>';
        echo '<input type="checkbox" name="tsol_site_plugin_enabled" value="1" ' . checked('1', $value, false) . '>';
        echo ' ' . esc_html__('Load Tom\'s School Of Life site-specific hooks.', 'tomschooloflife-plugin');
        echo '</label>';
    }

    public function gemini_api_key_callback() {
        $has_key = self::get_gemini_api_key() !== '';

        echo '<input type="password" name="' . esc_attr(self::GEMINI_API_KEY_OPTION) . '" value="" class="regular-text" autocomplete="off" placeholder="' . esc_attr($has_key ? __('Leave blank to keep the saved key', 'tomschooloflife-plugin') : __('Paste Gemini API key', 'tomschooloflife-plugin')) . '">';

        if ($has_key) {
            echo '<p class="description">' . esc_html__('A Gemini API key is saved. Enter a new key to replace it, or leave this field blank to keep the current key.', 'tomschooloflife-plugin') . '</p>';
            echo '<label>';
            echo '<input type="checkbox" name="tsol_site_clear_gemini_api_key" value="1">';
            echo ' ' . esc_html__('Clear the saved Gemini API key', 'tomschooloflife-plugin');
            echo '</label>';
            return;
        }

        echo '<p class="description">' . esc_html__('The key is stored in WordPress options and is not printed back into this field after saving.', 'tomschooloflife-plugin') . '</p>';
    }

    public function gemini_model_callback() {
        $model = self::get_gemini_model();

        echo '<select name="' . esc_attr(self::GEMINI_MODEL_OPTION) . '">';
        foreach ($this->get_gemini_model_options() as $value => $label) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($value, $model, false) . '>' . esc_html($label) . '</option>';
        }
        echo '</select>';
        echo '<p class="description">' . esc_html__('Used by AI-assisted matching. The default is Gemini 2.5 Flash.', 'tomschooloflife-plugin') . '</p>';
    }

    public function sanitize_checkbox($value) {
        return $value === '1' ? '1' : '0';
    }

    public function sanitize_gemini_api_key($value) {
        if (isset($_POST['tsol_site_clear_gemini_api_key']) && wp_unslash($_POST['tsol_site_clear_gemini_api_key']) === '1') {
            return '';
        }

        $value = trim((string) wp_unslash($value));

        if ($value === '') {
            return self::get_gemini_api_key();
        }

        return sanitize_text_field($value);
    }

    public function sanitize_gemini_model($value) {
        $value = sanitize_text_field((string) wp_unslash($value));
        $options = $this->get_gemini_model_options();

        return isset($options[$value]) ? $value : 'gemini-2.5-flash';
    }

    public static function get_gemini_api_key() {
        return trim((string) get_option(self::GEMINI_API_KEY_OPTION, ''));
    }

    public static function get_gemini_model() {
        $model = trim((string) get_option(self::GEMINI_MODEL_OPTION, 'gemini-2.5-flash'));

        return $model !== '' ? $model : 'gemini-2.5-flash';
    }

    private function get_gemini_model_options() {
        $options = array(
            'gemini-2.5-flash' => __('Gemini 2.5 Flash', 'tomschooloflife-plugin'),
            'gemini-2.5-pro' => __('Gemini 2.5 Pro', 'tomschooloflife-plugin'),
            'gemini-2.5-flash-lite' => __('Gemini 2.5 Flash-Lite', 'tomschooloflife-plugin'),
        );

        /**
         * Filters the Gemini model choices shown in the TSOL settings page.
         *
         * @param array $options Model value => label map.
         */
        return (array) apply_filters('tsol_site_gemini_model_options', $options);
    }
}
