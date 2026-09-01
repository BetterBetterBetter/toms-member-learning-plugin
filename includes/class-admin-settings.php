<?php
/**
 * Admin Settings Class
 * Displays the Liberty Classroom Library integration status.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Site_Admin_Settings {

    public function init() {
        // Feature-specific settings are registered by the Library modules.
    }

    public function display_page() {
        ?>
        <div class="wrap tsol-site-plugin-admin">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php settings_errors(); ?>

            <div class="tsol-site-status-card">
                <h2><?php esc_html_e('Library integration status', 'libertyclassroom-library'); ?></h2>
                <dl>
                    <dt><?php esc_html_e('Plugin version', 'libertyclassroom-library'); ?></dt>
                    <dd><?php echo esc_html(TSOL_SITE_PLUGIN_VERSION); ?></dd>

                    <dt><?php esc_html_e('Access Platform SSO', 'libertyclassroom-library'); ?></dt>
                    <dd>
                        <?php if (LibertyClassroomLibraryPlugin::is_access_sso_available()) : ?>
                            <span class="tsol-site-status tsol-site-status--ok"><?php esc_html_e('Active', 'libertyclassroom-library'); ?></span>
                        <?php else : ?>
                            <span class="tsol-site-status tsol-site-status--error"><?php esc_html_e('Missing', 'libertyclassroom-library'); ?></span>
                        <?php endif; ?>
                    </dd>

                    <dt><?php esc_html_e('Home URL', 'libertyclassroom-library'); ?></dt>
                    <dd><code><?php echo esc_html(home_url()); ?></code></dd>

                </dl>
            </div>

        </div>
        <?php
    }

}
