<?php
/**
 * Plugin Name: Member Library Platform
 * Plugin URI: https://github.com/BetterBetterBetter/toms-member-learning-plugin
 * Description: Canonical member-library platform plugin (multi-brand core + per-install brand config). Serves Tom's School of Life and Liberty Classroom.
 * Version: 0.6.0
 * Author: Thrice Agency
 * License: GPL v2 or later
 * Text Domain: tomschooloflife-plugin
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * Update URI: https://github.com/BetterBetterBetter/toms-member-learning-plugin
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants.
define('TSOL_SITE_PLUGIN_VERSION', '0.6.0');
define('TSOL_SITE_PLUGIN_FILE', __FILE__);
define('TSOL_SITE_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('TSOL_SITE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TSOL_SITE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('TSOL_SITE_PLUGIN_REPOSITORY_URL', 'https://github.com/BetterBetterBetter/toms-member-learning-plugin');

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
require_once TSOL_SITE_PLUGIN_DIR . 'includes/class-brand.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/class-dependencies.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-auth/class-library-auth-settings.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-auth/class-library-auth-repository.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-auth/class-library-auth-security.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-auth/class-library-auth-entitlements.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-auth/class-library-auth-revocation.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-auth/class-library-account-security.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-notifications/class-library-announcement-audience-contract.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-notifications/class-library-announcement-audience-resolver.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-notifications/class-library-announcement-flags.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-notifications/class-library-announcement-model.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-notifications/class-library-announcement-audience-builder.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-notifications/class-library-announcement-audit.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-notifications/class-library-announcement-preview.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-notifications/class-library-announcement-admin.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-notifications/class-library-announcements.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-auth/class-library-auth.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-media-normalizer.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-resource-normalizer.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-content-model.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-content-html-sanitizer.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-homepage-curation.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-content-catalogue.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-catalogue-webhook.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-content-changes.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-catalogue-sync-status.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-admin-navigation.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-url-admin.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-content-admin.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-content-transcripts.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-collection-admin.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-speaker-admin.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-content-access-column.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-access-groups.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-access-groups-admin.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-environment-migration.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-environment-migration-admin.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/features/library-content/class-library-content.php';

// Brand-specific one-shot import sources (CLI only). TSOL's legacy data
// migrations live in the separate TSOL companion plugin; only the active
// Liberty LearnDash importer ships in the shared library core. It is
// self-guarded to write only when home_url() host is libertyclassroom.test.
if (defined('WP_CLI') && WP_CLI) {
    require_once TSOL_SITE_PLUGIN_DIR . 'includes/migrations/liberty-learndash-import/class-liberty-learndash-manifest.php';
    require_once TSOL_SITE_PLUGIN_DIR . 'includes/migrations/liberty-learndash-import/class-liberty-learndash-import.php';
    require_once TSOL_SITE_PLUGIN_DIR . 'includes/migrations/liberty-learndash-import/class-liberty-learndash-import-cli.php';
    WP_CLI::add_command(Liberty_Classroom_LearnDash_Import_CLI::COMMAND, 'Liberty_Classroom_LearnDash_Import_CLI');
}

require_once TSOL_SITE_PLUGIN_DIR . 'includes/class-plugin.php';

// Initialize the plugin.
TomsSchoolOfLifePlugin::get_instance();

// Activation and deactivation hooks.
register_activation_hook(__FILE__, array('TomsSchoolOfLifePlugin', 'activate'));
register_deactivation_hook(__FILE__, array('TomsSchoolOfLifePlugin', 'deactivate'));
