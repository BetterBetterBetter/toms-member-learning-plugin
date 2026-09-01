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

// The option/default path is only assertable when a FEATURE CONSTANT is not
// forcing the value (constants are immutable within a request). On a
// constant-configured install (e.g. Liberty, which pins both features off) we
// assert the constant wins instead.
if (!defined('TSOL_LIBRARY_FEATURE_ACCOUNTABILITY_MODAL')
    && !defined('TSOL_LIBRARY_FEATURE_COOKIE_CONSENT')) {

    delete_option('tsol_library_feature_accountability_modal');
    delete_option('tsol_library_feature_cookie_consent');

    // Unset ⇒ enabled (preserves TSOL).
    $assert(true === TSOL_Library_Brand::feature_enabled('accountability_modal'), 'Unset feature should default enabled.');
    $assert(true === TSOL_Library_Brand::feature_enabled('cookie_consent'), 'Unset feature should default enabled.');

    // Option '0' disables (a Liberty core-only install without constants).
    update_option('tsol_library_feature_accountability_modal', '0');
    update_option('tsol_library_feature_cookie_consent', '0');
    $assert(false === TSOL_Library_Brand::feature_enabled('accountability_modal'), 'Option "0" should disable the feature.');
    $assert(false === TSOL_Library_Brand::feature_enabled('cookie_consent'), 'Option "0" should disable the feature.');

    // Filter overrides.
    add_filter('tsol_library_feature_cookie_consent', '__return_true');
    $assert(true === TSOL_Library_Brand::feature_enabled('cookie_consent'), 'Filter should override a disabling option.');

    // Re-enabling by deleting the option should restore the default.
    delete_option('tsol_library_feature_accountability_modal');
    $assert(true === TSOL_Library_Brand::feature_enabled('accountability_modal'), 'Deleting the option should restore default enabled.');

    delete_option('tsol_library_feature_cookie_consent');
} else {
    // Constant-configured install: the constant must win over any option.
    if (defined('TSOL_LIBRARY_FEATURE_ACCOUNTABILITY_MODAL')) {
        update_option('tsol_library_feature_accountability_modal', '1');
        $assert(
            ((bool) TSOL_LIBRARY_FEATURE_ACCOUNTABILITY_MODAL) === TSOL_Library_Brand::feature_enabled('accountability_modal'),
            'Constant TSOL_LIBRARY_FEATURE_ACCOUNTABILITY_MODAL must win over the option.'
        );
        delete_option('tsol_library_feature_accountability_modal');
    }
    if (defined('TSOL_LIBRARY_FEATURE_COOKIE_CONSENT')) {
        $assert(
            ((bool) TSOL_LIBRARY_FEATURE_COOKIE_CONSENT) === TSOL_Library_Brand::feature_enabled('cookie_consent'),
            'Constant TSOL_LIBRARY_FEATURE_COOKIE_CONSENT must win.'
        );
    }
}

if (!empty($failures)) {
    WP_CLI::error("Feature enablement contract failed:\n - " . implode("\n - ", $failures));
}
WP_CLI::success('Feature enablement contract passed.');
