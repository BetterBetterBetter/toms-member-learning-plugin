<?php

/**
 * Contract: brand configuration resolves constant → option → default, and the
 * auth interstitial logo/CSP derive from brand config (regression guard for
 * the Liberty fork bug where the auth error page served the Tom Woods logo
 * with a tomschooloflife.com-only CSP regardless of brand).
 *
 * Run: wp eval-file tests/library-brand-config-contract.php --skip-themes
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

// The default and option paths are only assertable when a brand CONSTANT is
// not overriding them (constants are immutable within a request). On a
// constant-configured install (e.g. Liberty) we instead assert the constant
// override wins — see the constant branch below.
if (!defined('TSOL_LIBRARY_BRAND_LOGO_URL')
    && !defined('TSOL_LIBRARY_BRAND_IMAGE_CSP_SRC')
    && !defined('TSOL_LIBRARY_BRAND_CLIENT_ID')
    && !defined('TSOL_LIBRARY_BRAND_NAME')) {

    // Defaults preserve historical TSOL behavior when nothing is configured.
    delete_option('tsol_library_brand_logo_url');
    delete_option('tsol_library_brand_image_csp_src');
    delete_option('tsol_library_brand_client_id');
    delete_option('tsol_library_brand_name');

    $assert(
        false !== strpos(TSOL_Library_Brand::logo_url(), 'tomschooloflife.com'),
        'Default logo_url should preserve the TSOL logo when unconfigured.'
    );
    $assert(
        'tsol-library' === TSOL_Library_Brand::client_id_default(),
        'Default client id should be tsol-library when unconfigured.'
    );
    $assert(
        'https://tomschooloflife.com' === TSOL_Library_Brand::image_csp_src(),
        'CSP img-src should derive from the default logo host.'
    );

    // Option override wins over default.
    update_option('tsol_library_brand_logo_url', 'https://cdn.example.test/liberty-logo.svg');
    $assert(
        'https://cdn.example.test' === TSOL_Library_Brand::image_csp_src(),
        'CSP img-src must follow the configured logo host (the Liberty regression).'
    );
    $assert(
        'https://cdn.example.test/liberty-logo.svg' === TSOL_Library_Brand::logo_url(),
        'Option override should win over the default logo.'
    );

    // Explicit CSP override wins over the derived host.
    update_option('tsol_library_brand_image_csp_src', "https://a.test https://b.test");
    $assert(
        'https://a.test https://b.test' === TSOL_Library_Brand::image_csp_src(),
        'Explicit image_csp_src option should override the derived host.'
    );
} else {
    // Constant-configured install: the resolved value must equal the constant,
    // and (the Liberty regression) the CSP host must NOT be the TSOL default.
    if (defined('TSOL_LIBRARY_BRAND_LOGO_URL')) {
        $assert(
            TSOL_LIBRARY_BRAND_LOGO_URL === TSOL_Library_Brand::logo_url(),
            'Constant TSOL_LIBRARY_BRAND_LOGO_URL must win.'
        );
        $assert(
            'https://tomschooloflife.com' !== TSOL_Library_Brand::image_csp_src(),
            'A brand with its own logo must NOT emit the TSOL CSP host (Liberty regression).'
        );
    }
    if (defined('TSOL_LIBRARY_BRAND_CLIENT_ID')) {
        $assert(
            TSOL_LIBRARY_BRAND_CLIENT_ID === TSOL_Library_Brand::client_id_default(),
            'Constant TSOL_LIBRARY_BRAND_CLIENT_ID must win.'
        );
    }
}

// Filter hook is honored.
add_filter('tsol_library_brand_name', function () {
    return 'Filtered Brand';
});
$assert('Filtered Brand' === TSOL_Library_Brand::name(), 'Brand value filter should apply.');

// Cleanup.
delete_option('tsol_library_brand_logo_url');
delete_option('tsol_library_brand_image_csp_src');

if (!empty($failures)) {
    WP_CLI::error("Brand config contract failed:\n - " . implode("\n - ", $failures));
}
WP_CLI::success('Brand config contract passed.');
