<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-install brand configuration — the plugin's equivalent of the app's
 * brand.json. One canonical plugin core serves multiple brands; the only
 * brand-specific values live here, each resolved as:
 *
 *   PHP constant override  →  wp_option  →  default (current TSOL value)
 *
 * Defaults preserve the historical Tom's School of Life behavior exactly, so
 * an install that sets nothing is unchanged. Liberty (and future brands) set
 * the constants in wp-config.php or the options via the admin.
 *
 * NOTE: machine identifiers (REST namespace tsol-library/v1, tsol_* options/
 * hooks/meta, CPTs, the text domain 'tomschooloflife-plugin') are a frozen
 * cross-brand compatibility contract and are deliberately NOT brand config.
 * See docs/plans/plugin-consolidation-plan.md invariants.
 */
class TSOL_Library_Brand {

    /**
     * Full display name, e.g. for the auth interstitial logo alt text.
     */
    public static function name() {
        return self::get('TSOL_LIBRARY_BRAND_NAME', 'tsol_library_brand_name', 'The Tom Woods School of Life');
    }

    /**
     * Short top-level admin menu label.
     */
    public static function menu_label() {
        return self::get('TSOL_LIBRARY_BRAND_MENU_LABEL', 'tsol_library_brand_menu_label', 'TSOL');
    }

    /**
     * Library admin menu / heading label.
     */
    public static function library_menu_label() {
        return self::get('TSOL_LIBRARY_BRAND_LIBRARY_MENU_LABEL', 'tsol_library_brand_library_menu_label', 'TSOL Library');
    }

    /**
     * Logo shown on the auth interstitial / error page.
     */
    public static function logo_url() {
        return self::get(
            'TSOL_LIBRARY_BRAND_LOGO_URL',
            'tsol_library_brand_logo_url',
            'https://tomschooloflife.com/wp-content/uploads/2020/04/THE-TOM-WOODS-SCHOOL-OF-LIFE-logo.svg'
        );
    }

    /**
     * The `img-src` origin(s) allowed in the auth interstitial CSP. Defaults to
     * the scheme://host of the logo so a brand only has to set the logo URL.
     */
    public static function image_csp_src() {
        $explicit = self::get('TSOL_LIBRARY_BRAND_IMAGE_CSP_SRC', 'tsol_library_brand_image_csp_src', '');
        if ('' !== $explicit) {
            return $explicit;
        }
        $parts = wp_parse_url(self::logo_url());
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            return $parts['scheme'] . '://' . $parts['host'];
        }
        return "'self'";
    }

    /**
     * Default OAuth client id (TSOL_LIBRARY_CLIENT_ID constant still overrides
     * at the auth layer; this is only the fallback default).
     */
    public static function client_id_default() {
        return self::get('TSOL_LIBRARY_BRAND_CLIENT_ID', 'tsol_library_brand_client_id', 'tsol-library');
    }

    /**
     * Privacy policy URL (cookie-consent default).
     */
    public static function privacy_url() {
        return self::get('TSOL_LIBRARY_BRAND_PRIVACY_URL', 'tsol_library_brand_privacy_url', 'https://access.tomwoods.com/privacy');
    }

    /**
     * Terms URL (cookie-consent default).
     */
    public static function terms_url() {
        return self::get('TSOL_LIBRARY_BRAND_TERMS_URL', 'tsol_library_brand_terms_url', 'https://access.tomwoods.com/terms');
    }

    /**
     * Whether an optional feature is enabled for this brand. Core features
     * (library-auth, library-content, library-notifications) are always on;
     * the site-specific ones (accountability_modal, cookie_consent) default
     * ON to preserve TSOL, and a brand disables them via constant or option:
     *
     *   define('TSOL_LIBRARY_FEATURE_ACCOUNTABILITY_MODAL', false);  // wp-config
     *   update_option('tsol_library_feature_accountability_modal', '0');
     *
     * @param string $feature One of: accountability_modal, cookie_consent.
     */
    public static function feature_enabled($feature) {
        $constant = 'TSOL_LIBRARY_FEATURE_' . strtoupper($feature);
        if (defined($constant)) {
            $enabled = (bool) constant($constant);
        } else {
            // Missing option ('') means unset → default enabled.
            $stored = get_option('tsol_library_feature_' . $feature, '');
            $enabled = ('' === (string) $stored) ? true : ('0' !== (string) $stored && false !== $stored);
        }
        /**
         * Filter per-feature enablement. Filter name is the option key.
         */
        return (bool) apply_filters('tsol_library_feature_' . $feature, $enabled);
    }

    /**
     * Constant override → option → default, with a filter for extensibility.
     */
    private static function get($constant, $option, $default) {
        $value = $default;
        if (defined($constant) && '' !== (string) constant($constant)) {
            $value = (string) constant($constant);
        } else {
            $stored = get_option($option, '');
            if ('' !== (string) $stored) {
                $value = (string) $stored;
            }
        }
        /**
         * Filter any resolved brand value. Filter name is the option key.
         */
        return (string) apply_filters($option, $value);
    }
}
