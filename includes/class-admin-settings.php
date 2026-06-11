<?php
/**
 * Admin Settings Class
 * Handles WordPress admin interface for Tom's School Of Life site settings.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Site_Admin_Settings {

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

        register_setting($this->options_group, 'tsol_site_plugin_enabled', array(
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => '1',
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

    public function enabled_callback() {
        $value = get_option('tsol_site_plugin_enabled', '1');

        echo '<label>';
        echo '<input type="checkbox" name="tsol_site_plugin_enabled" value="1" ' . checked('1', $value, false) . '>';
        echo ' ' . esc_html__('Load Tom\'s School Of Life site-specific hooks.', 'tomschooloflife-plugin');
        echo '</label>';
    }

    public function sanitize_checkbox($value) {
        return $value === '1' ? '1' : '0';
    }
}
