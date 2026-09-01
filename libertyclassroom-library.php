<?php
/**
 * Plugin Name: Liberty Classroom Library
 * Description: WordPress editorial, access, and authentication bridge for the Liberty Classroom library.
 * Version: 0.1.0
 * Author: Thrice Agency
 * License: GPL v2 or later
 * Text Domain: libertyclassroom-library
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

// Prevent direct access.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants.
define('TSOL_SITE_PLUGIN_VERSION', '0.1.0');
define('TSOL_SITE_PLUGIN_FILE', __FILE__);
define('TSOL_SITE_PLUGIN_BASENAME', plugin_basename(__FILE__));
define('TSOL_SITE_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('TSOL_SITE_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files immediately to ensure classes are available for all hooks.
require_once TSOL_SITE_PLUGIN_DIR . 'includes/contracts/interface-feature.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/class-dependencies.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/class-admin-settings.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/class-gemini-client.php';
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
require_once TSOL_SITE_PLUGIN_DIR . 'includes/migrations/liberty-learndash-import/class-liberty-learndash-manifest.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/migrations/liberty-learndash-import/class-liberty-learndash-import.php';
require_once TSOL_SITE_PLUGIN_DIR . 'includes/migrations/liberty-learndash-import/class-liberty-learndash-import-cli.php';

require_once TSOL_SITE_PLUGIN_DIR . 'includes/class-plugin.php';

// Initialize the plugin.
LibertyClassroomLibraryPlugin::get_instance();

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command(Liberty_Classroom_LearnDash_Import_CLI::COMMAND, new Liberty_Classroom_LearnDash_Import_CLI());
}

// Activation and deactivation hooks.
register_activation_hook(__FILE__, array('LibertyClassroomLibraryPlugin', 'activate'));
register_deactivation_hook(__FILE__, array('LibertyClassroomLibraryPlugin', 'deactivate'));
