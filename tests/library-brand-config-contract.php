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

    // Defaults are deliberately brand-neutral when nothing is configured.
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
    $default_auth_theme = MemberLibrary_Brand::auth_theme();
    $assert(
        '#111827' === $default_auth_theme['background']
            && '#1e40af' === $default_auth_theme['button'],
        'The auth interstitial must use the neutral default theme.'
    );
    $assert(
        220 === MemberLibrary_Brand::auth_logo_max_width(),
        'The auth logo must have a neutral, configurable maximum width.'
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

    // Auth theme options accept safe hex colors and reject arbitrary CSS.
    update_option('tsol_library_brand_auth_button', '#123abc');
    $assert(
        '#123abc' === MemberLibrary_Brand::auth_theme()['button'],
        'A valid auth theme color option should override the neutral default.'
    );
    update_option('tsol_library_brand_auth_button', 'red; background-image:url(https://example.test)');
    $assert(
        '#1e40af' === MemberLibrary_Brand::auth_theme()['button'],
        'An invalid auth theme color must fall back to the neutral default.'
    );
    update_option('tsol_library_brand_auth_logo_max_width', '900');
    $assert(
        480 === MemberLibrary_Brand::auth_logo_max_width(),
        'The configured auth logo width must be clamped to its safe maximum.'
    );
    update_option('tsol_library_brand_auth_logo_max_width', 'not-a-width');
    $assert(
        220 === MemberLibrary_Brand::auth_logo_max_width(),
        'An invalid auth logo width must fall back to the neutral default.'
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
delete_option('tsol_library_brand_auth_button');
delete_option('tsol_library_brand_auth_logo_max_width');

// The rendered fallback page must remain brand-neutral and must not emit a
// broken image when an installation has no configured logo.
$auth_source = file_get_contents(MEMBER_LIBRARY_PLUGIN_DIR . 'includes/features/library-auth/class-library-auth.php');
$access_source = file_get_contents(MEMBER_LIBRARY_PLUGIN_DIR . 'includes/features/library-content/class-library-content-access-column.php');
$assert(false !== strpos($auth_source, "if ('' !== \$logo_url)"), 'The auth page must render its logo only when a URL is configured.');
$assert(false === strpos($auth_source, 'width="190" height="51"'), 'The auth page must not assume the TSOL logo aspect ratio.');
foreach (array('#06182b', '#0a2540', '#1a3a52', '#65d5ee', '#dc3545', '#c82333') as $legacy_color) {
    $assert(false === stripos($auth_source, $legacy_color), 'The auth page still contains a legacy brand color: ' . $legacy_color);
}
$assert(false === strpos($access_source, 'this TSOL view'), 'User-facing admin copy must not call the shared UI a TSOL view.');

$legacy_assets = glob(MEMBER_LIBRARY_PLUGIN_DIR . 'assets/images/library/*');
$assert(empty($legacy_assets), 'The shared release still contains legacy brand content images.');

// Scan literal translated UI copy across production PHP. Frozen
// TSOL_LIBRARY_* constant names are technical instructions, not decoration.
$translation_functions = array('__', '_e', 'esc_html__', 'esc_html_e', 'esc_attr__', 'esc_attr_e');
$source_iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(MEMBER_LIBRARY_PLUGIN_DIR . 'includes', FilesystemIterator::SKIP_DOTS)
);
foreach ($source_iterator as $source_file) {
    if ('php' !== strtolower($source_file->getExtension())) {
        continue;
    }
    $tokens = token_get_all(file_get_contents($source_file->getPathname()));
    $token_count = count($tokens);
    for ($index = 0; $index < $token_count; $index++) {
        if (!is_array($tokens[$index]) || T_STRING !== $tokens[$index][0] || !in_array($tokens[$index][1], $translation_functions, true)) {
            continue;
        }
        $argument_index = $index + 1;
        while ($argument_index < $token_count && is_array($tokens[$argument_index]) && T_WHITESPACE === $tokens[$argument_index][0]) {
            $argument_index++;
        }
        if ($argument_index >= $token_count || '(' !== $tokens[$argument_index]) {
            continue;
        }
        $argument_index++;
        while ($argument_index < $token_count && is_array($tokens[$argument_index]) && T_WHITESPACE === $tokens[$argument_index][0]) {
            $argument_index++;
        }
        if ($argument_index >= $token_count || !is_array($tokens[$argument_index]) || T_CONSTANT_ENCAPSED_STRING !== $tokens[$argument_index][0]) {
            continue;
        }
        $display_copy = preg_replace('/TSOL_LIBRARY_[A-Z0-9_]+/', '', $tokens[$argument_index][1]);
        $assert(
            !preg_match('/TSOL|Tom Woods|School of Life/i', $display_copy),
            'Project-specific branding remains in translated UI copy: ' . $source_file->getPathname() . ':' . $tokens[$argument_index][2]
        );
    }
}

if (!empty($failures)) {
    WP_CLI::error("Brand config contract failed:\n - " . implode("\n - ", $failures));
}
WP_CLI::success('Brand config contract passed.');
