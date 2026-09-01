<?php

/**
 * Contract: per-brand feature enablement. Optional site-specific features
 * (accountability_modal, cookie_consent) default ON (TSOL) and a brand
 * disables them via option or the TSOL_LIBRARY_FEATURE_* constant, so a brand
 * like Liberty runs the library core only.
 *
 * Run: wp eval-file tests/library-feature-enablement-contract.php --skip-themes
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('This contract must run under WP-CLI (wp eval-file).');
}

$failures = array();
$assert = function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

delete_option('tsol_library_feature_accountability_modal');
delete_option('tsol_library_feature_cookie_consent');

// Unset ⇒ enabled (preserves TSOL).
$assert(true === TSOL_Library_Brand::feature_enabled('accountability_modal'), 'Unset feature should default enabled.');
$assert(true === TSOL_Library_Brand::feature_enabled('cookie_consent'), 'Unset feature should default enabled.');

// Option '0' disables (a Liberty core-only install).
update_option('tsol_library_feature_accountability_modal', '0');
update_option('tsol_library_feature_cookie_consent', '0');
$assert(false === TSOL_Library_Brand::feature_enabled('accountability_modal'), 'Option "0" should disable the feature.');
$assert(false === TSOL_Library_Brand::feature_enabled('cookie_consent'), 'Option "0" should disable the feature.');

// Filter overrides.
add_filter('tsol_library_feature_cookie_consent', '__return_true');
$assert(true === TSOL_Library_Brand::feature_enabled('cookie_consent'), 'Filter should override a disabling option.');

// Core features are not gated by this mechanism (sanity: unknown key defaults enabled).
delete_option('tsol_library_feature_accountability_modal');
$assert(true === TSOL_Library_Brand::feature_enabled('accountability_modal'), 'Re-enabling by deleting the option should restore default.');

delete_option('tsol_library_feature_cookie_consent');

if (!empty($failures)) {
    WP_CLI::error("Feature enablement contract failed:\n - " . implode("\n - ", $failures));
}
WP_CLI::success('Feature enablement contract passed.');
