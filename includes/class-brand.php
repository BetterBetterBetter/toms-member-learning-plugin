<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Per-install brand configuration — the plugin's equivalent of the app's
 * brand.json. One canonical plugin core serves multiple brands; the only
 * brand-specific values live here, each resolved as:
 *
 *   PHP constant override  →  wp_option  →  universal default
 *
 * Every default is BRAND-NEUTRAL. The plugin never names a specific project
 * anywhere a person can see it, and it does not need wp-config to be neutral —
 * an install that configures nothing reads as a generic member library. A
 * brand that wants its own wording sets the option (or the constant) as an
 * override; that is opt-in decoration, never something the core relies on.
 *
 * NOTE: machine identifiers (REST namespace tsol-library/v1, tsol_* options/
 * hooks/meta, CPTs, the text domain 'member-library') are a frozen
 * cross-brand compatibility contract and are deliberately NOT brand config.
 * See docs/plans/plugin-consolidation-plan.md invariants.
 */
class MemberLibrary_Brand {

    /**
     * Full display name, e.g. for the auth interstitial logo alt text.
     */
    public static function name() {
        return self::get('TSOL_LIBRARY_BRAND_NAME', 'tsol_library_brand_name', __('Member Library', 'member-library'));
    }

    /**
     * Library admin menu / heading label.
     */
    public static function library_menu_label() {
        return self::get('TSOL_LIBRARY_BRAND_LIBRARY_MENU_LABEL', 'tsol_library_brand_library_menu_label', __('Library', 'member-library'));
    }

    /**
     * Logo shown on the auth interstitial / error page. Empty by default: the
     * core ships no brand's artwork, and the interstitial simply renders no
     * logo until an install supplies one.
     */
    public static function logo_url() {
        return self::get('TSOL_LIBRARY_BRAND_LOGO_URL', 'tsol_library_brand_logo_url', '');
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
     * Name of the member-facing application this plugin feeds, used in admin
     * copy that talks about the front-end ("... signed in to the Library").
     */
    public static function app_name() {
        return self::get('TSOL_LIBRARY_BRAND_APP_NAME', 'tsol_library_brand_app_name', __('Library', 'member-library'));
    }

    /**
     * Default OAuth client id (TSOL_LIBRARY_CLIENT_ID constant still overrides
     * at the auth layer; this is only the fallback default).
     */
    public static function client_id_default() {
        return self::get('TSOL_LIBRARY_BRAND_CLIENT_ID', 'tsol_library_brand_client_id', 'tsol-library');
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
