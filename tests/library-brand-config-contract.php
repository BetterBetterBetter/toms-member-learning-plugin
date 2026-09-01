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

    // The core ships NO brand's artwork or wording. An unconfigured install
    // must read as a generic member library — no project name is allowed to
    // appear anywhere a person can see it.
    $assert(
        '' === MemberLibrary_Brand::logo_url(),
        'Default logo_url must be empty; the core ships no brand artwork.'
    );
    $assert(
        'tsol-library' === MemberLibrary_Brand::client_id_default(),
        'Default client id should be tsol-library when unconfigured (frozen machine identifier, not branding).'
    );
    $assert(
        "'self'" === MemberLibrary_Brand::image_csp_src(),
        "CSP img-src must fall back to 'self' when no brand logo is configured."
    );
    $assert(
        'Library' === MemberLibrary_Brand::library_menu_label(),
        'Default menu label must be the universal "Library".'
    );
    $assert(
        'Member Library' === MemberLibrary_Brand::name(),
        'Default brand name must be the universal "Member Library".'
    );
    foreach (array(
        MemberLibrary_Brand::name(),
        MemberLibrary_Brand::library_menu_label(),
        MemberLibrary_Brand::app_name(),
        MemberLibrary_Brand::logo_url(),
    ) as $unconfigured_value) {
        $assert(
            !preg_match('/TSOL|Tom Woods|School of Life|Liberty/i', (string) $unconfigured_value),
            'No brand default may name a specific project: ' . $unconfigured_value
        );
    }

    // Option override wins over default.
    update_option('tsol_library_brand_logo_url', 'https://cdn.example.test/liberty-logo.svg');
    $assert(
        'https://cdn.example.test' === MemberLibrary_Brand::image_csp_src(),
        'CSP img-src must follow the configured logo host (the Liberty regression).'
    );
    $assert(
        'https://cdn.example.test/liberty-logo.svg' === MemberLibrary_Brand::logo_url(),
        'Option override should win over the default logo.'
    );

    // Explicit CSP override wins over the derived host.
    update_option('tsol_library_brand_image_csp_src', "https://a.test https://b.test");
    $assert(
        'https://a.test https://b.test' === MemberLibrary_Brand::image_csp_src(),
        'Explicit image_csp_src option should override the derived host.'
    );
} else {
    // Constant-configured install: the resolved value must equal the constant,
    // and (the Liberty regression) the CSP host must NOT be the TSOL default.
    if (defined('TSOL_LIBRARY_BRAND_LOGO_URL')) {
        $assert(
            TSOL_LIBRARY_BRAND_LOGO_URL === MemberLibrary_Brand::logo_url(),
            'Constant TSOL_LIBRARY_BRAND_LOGO_URL must win.'
        );
        $assert(
            'https://tomschooloflife.com' !== MemberLibrary_Brand::image_csp_src(),
            'A brand with its own logo must NOT emit the TSOL CSP host (Liberty regression).'
        );
    }
    if (defined('TSOL_LIBRARY_BRAND_CLIENT_ID')) {
        $assert(
            TSOL_LIBRARY_BRAND_CLIENT_ID === MemberLibrary_Brand::client_id_default(),
            'Constant TSOL_LIBRARY_BRAND_CLIENT_ID must win.'
        );
    }
}

// Filter hook is honored.
add_filter('tsol_library_brand_name', function () {
    return 'Filtered Brand';
});
$assert('Filtered Brand' === MemberLibrary_Brand::name(), 'Brand value filter should apply.');

// Cleanup.
delete_option('tsol_library_brand_logo_url');
delete_option('tsol_library_brand_image_csp_src');

if (!empty($failures)) {
    WP_CLI::error("Brand config contract failed:\n - " . implode("\n - ", $failures));
}
WP_CLI::success('Brand config contract passed.');
