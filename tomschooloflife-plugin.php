<?php
/**
 * Plugin Name: Tom's School Of Life Plugin
 * Plugin URI: https://github.com/BetterBetterBetter/tomschooloflife-plugin
 * Description: Site-specific functionality for Tom's School Of Life.
 * Version: 0.1.4
 * Author: Thrice Agency
 * License: GPL v2 or later
 * Text Domain: tomschooloflife-plugin
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Update URI: https://github.com/BetterBetterBetter/tomschooloflife-plugin
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants.
define('TSOL_SITE_PLUGIN_VERSION', '0.1.4');
define('TSOL_SITE_PLUGIN_FILE', __FILE__);
define('TSOL_SITE_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('TSOL_SITE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TSOL_SITE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TSOL_SITE_PLUGIN_REPOSITORY_URL', 'https://github.com/BetterBetterBetter/tomschooloflife-plugin');

// Plugin Update Checker - GitHub integration.
if (file_exists(TSOL_SITE_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php')) {
    require_once TSOL_SITE_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

    $tsol_site_update_checker = YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
        TSOL_SITE_PLUGIN_REPOSITORY_URL,
        __FILE__,
        'tomschooloflife-plugin'
    );

    // Enable GitHub releases for better versioning and release asset downloads.
    $tsol_site_update_checker->getVcsApi()->enableReleaseAssets();
}

// Include required files immediately to ensure classes are available for all hooks.
require_once TSOL_SITE_PLUGIN_DIR . 'includes/contracts/interface-feature.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/class-dependencies.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/accountability-modal/class-accountability-modal-settings.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/accountability-modal/class-accountability-modal-repository.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/accountability-modal/class-accountability-modal-admin.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/accountability-modal/class-accountability-modal-renderer.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/accountability-modal/class-accountability-modal-submission-handler.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/accountability-modal/class-accountability-modal.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/cookie-consent/class-cookie-consent-settings.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/cookie-consent/class-cookie-consent-admin.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/cookie-consent/class-cookie-consent.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/class-plugin.php';

// Initialize the plugin.
TomsSchoolOfLifePlugin::get_instance();

// Activation and deactivation hooks.
register_activation_hook(__FILE__, array('TomsSchoolOfLifePlugin', 'activate'));
register_deactivation_hook(__FILE__, array('TomsSchoolOfLifePlugin', 'deactivate'));
