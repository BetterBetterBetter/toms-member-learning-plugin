<?php
/**
 * Cookie consent frontend feature.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Cookie_Consent implements TSOL_Site_Feature {

    private $settings = null;

    public function init() {
        add_action('wp_head', array($this, 'render_consent_mode_defaults'), 0);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_footer', array($this, 'render_banner'));
        add_action('admin_bar_menu', array($this, 'add_admin_bar_button'), 110);
    }

    public function render_consent_mode_defaults() {
        if (!$this->should_load()) {
            return;
        }

        $settings = $this->get_settings();

        if ($settings['google_consent_mode'] !== '1') {
            return;
        }

        $consent = TSOL_Cookie_Consent_Settings::get_consent_from_cookie($settings);
        $state = TSOL_Cookie_Consent_Settings::get_consent_mode_state($consent);

        ?>
        <script id="tsol-cookie-consent-mode">
            window.dataLayer = window.dataLayer || [];
            window.gtag = window.gtag || function(){ window.dataLayer.push(arguments); };
            window.gtag('consent', 'default', <?php echo wp_json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>);
        </script>
        <?php
    }

    public function enqueue_assets() {
        if (!$this->should_load()) {
            return;
        }

        $settings = $this->get_settings();
        $categories = TSOL_Cookie_Consent_Settings::get_categories($settings);

        wp_enqueue_style(
            'tsol-cookie-consent',
            TSOL_SITE_PLUGIN_URL . 'assets/features/cookie-consent/cookie-consent.css',
            array(),
            TSOL_SITE_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'tsol-cookie-consent',
            TSOL_SITE_PLUGIN_URL . 'assets/features/cookie-consent/cookie-consent.js',
            array(),
            TSOL_SITE_PLUGIN_VERSION,
            true
        );

        wp_localize_script('tsol-cookie-consent', 'tsolCookieConsentSettings', array(
            'enabled' => $settings['enabled'] === '1',
            'bannerEnabled' => $settings['banner_enabled'] === '1',
            'cookieName' => TSOL_Cookie_Consent_Settings::COOKIE_NAME,
            'version' => $settings['consent_version'],
            'cookieLifetimeDays' => (int) $settings['cookie_lifetime_days'],
            'respectGpc' => $settings['respect_gpc'] === '1',
            'showReopenButton' => $settings['show_reopen_button'] === '1',
            'googleConsentMode' => $settings['google_consent_mode'] === '1',
            'gtmContainerId' => $settings['gtm_container_id'],
            'googleAdsId' => $settings['google_ads_id'],
            'scripts' => TSOL_Cookie_Consent_Settings::get_script_payload($settings),
            'categories' => array(
                'necessary' => array(
                    'enabled' => true,
                    'required' => true,
                    'label' => $categories['necessary']['label'],
                ),
                'analytics' => array(
                    'enabled' => $categories['analytics']['enabled'],
                    'required' => false,
                    'label' => $categories['analytics']['label'],
                ),
                'marketing' => array(
                    'enabled' => $categories['marketing']['enabled'],
                    'required' => false,
                    'label' => $categories['marketing']['label'],
                ),
            ),
            'messages' => array(
                'saved' => __('Cookie choices saved.', 'tomschooloflife-plugin'),
                'gpc' => __('Global Privacy Control is enabled in this browser, so marketing cookies stay off.', 'tomschooloflife-plugin'),
            ),
            'consentModeMap' => array(
                'analyticsGranted' => array(
                    'analytics_storage' => 'granted',
                ),
                'analyticsDenied' => array(
                    'analytics_storage' => 'denied',
                ),
                'marketingGranted' => array(
                    'ad_storage' => 'granted',
                    'ad_user_data' => 'granted',
                    'ad_personalization' => 'granted',
                    'personalization_storage' => 'granted',
                ),
                'marketingDenied' => array(
                    'ad_storage' => 'denied',
                    'ad_user_data' => 'denied',
                    'ad_personalization' => 'denied',
                    'personalization_storage' => 'denied',
                ),
            ),
        ));
    }

    public function render_banner() {
        if (!$this->should_load()) {
            return;
        }

        $settings = $this->get_settings();
        $categories = TSOL_Cookie_Consent_Settings::get_categories($settings);
        $has_valid_consent = is_array(TSOL_Cookie_Consent_Settings::get_consent_from_cookie($settings));
        $site_icon_url = get_site_icon_url(192);
        $root_style = $site_icon_url ? '--tsol-cookie-icon-mask: url("' . esc_url_raw($site_icon_url) . '");' : '';
        $show_reopen_button = $settings['show_reopen_button'] === '1' && ($has_valid_consent || $settings['banner_enabled'] !== '1');
        $hide_root = ($has_valid_consent && !$show_reopen_button) || (!$has_valid_consent && $settings['banner_enabled'] !== '1' && !$show_reopen_button);
        $root_classes = array(
            'tsol-cookie-consent',
            'tsol-cookie-consent--' . sanitize_html_class($settings['banner_position']),
        );
        $reopen_classes = array(
            'tsol-cookie-consent__reopen',
            'tsol-cookie-consent__reopen--' . sanitize_html_class($settings['reopen_position']),
        );

        ?>
        <div
            id="tsol-cookie-consent"
            class="<?php echo esc_attr(implode(' ', $root_classes)); ?>"
            data-tsol-cookie-consent
            style="<?php echo esc_attr($root_style); ?>"
            <?php echo $hide_root ? 'hidden' : ''; ?>
        >
            <section
                class="tsol-cookie-consent__banner"
                data-tsol-cookie-banner
                role="dialog"
                aria-modal="false"
                aria-labelledby="tsol-cookie-consent-title"
                aria-describedby="tsol-cookie-consent-description"
                <?php echo $has_valid_consent || $settings['banner_enabled'] !== '1' ? 'hidden' : ''; ?>
            >
                <div class="tsol-cookie-consent__mark" aria-hidden="true">
                    <span></span>
                </div>

                <div class="tsol-cookie-consent__copy">
                    <p class="tsol-cookie-consent__eyebrow"><?php echo esc_html($settings['banner_eyebrow']); ?></p>
                    <h2 id="tsol-cookie-consent-title"><?php echo esc_html($settings['banner_title']); ?></h2>
                    <p id="tsol-cookie-consent-description"><?php echo esc_html($settings['banner_intro']); ?></p>

                    <div class="tsol-cookie-consent__links">
                        <?php if ($settings['privacy_url']) : ?>
                            <a href="<?php echo esc_url($settings['privacy_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Privacy Policy', 'tomschooloflife-plugin'); ?></a>
                        <?php endif; ?>
                        <?php if ($settings['terms_url']) : ?>
                            <a href="<?php echo esc_url($settings['terms_url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Terms', 'tomschooloflife-plugin'); ?></a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tsol-cookie-consent__actions">
                    <button type="button" class="tsol-cookie-consent__button tsol-cookie-consent__button--ghost" data-tsol-cookie-manage>
                        <?php echo esc_html($settings['manage_label']); ?>
                    </button>
                    <button type="button" class="tsol-cookie-consent__button tsol-cookie-consent__button--secondary" data-tsol-cookie-reject>
                        <?php echo esc_html($settings['reject_all_label']); ?>
                    </button>
                    <button type="button" class="tsol-cookie-consent__button tsol-cookie-consent__button--primary" data-tsol-cookie-accept>
                        <?php echo esc_html($settings['accept_all_label']); ?>
                    </button>
                </div>
            </section>

            <div class="tsol-cookie-consent__modal" data-tsol-cookie-preferences role="dialog" aria-modal="true" aria-labelledby="tsol-cookie-preferences-title" aria-describedby="tsol-cookie-preferences-description" hidden>
                <div class="tsol-cookie-consent__backdrop" data-tsol-cookie-close aria-hidden="true"></div>
                <div class="tsol-cookie-consent__dialog" tabindex="-1">
                    <div class="tsol-cookie-consent__dialog-header">
                        <button type="button" class="tsol-cookie-consent__close" data-tsol-cookie-close aria-label="<?php echo esc_attr($settings['close_label']); ?>">&times;</button>
                        <p class="tsol-cookie-consent__eyebrow"><?php echo esc_html($settings['banner_eyebrow']); ?></p>
                        <h2 id="tsol-cookie-preferences-title"><?php echo esc_html($settings['preferences_title']); ?></h2>
                        <p id="tsol-cookie-preferences-description"><?php echo esc_html($settings['preferences_intro']); ?></p>
                    </div>

                    <form class="tsol-cookie-consent__form" data-tsol-cookie-form>
                        <div class="tsol-cookie-consent__notice" data-tsol-cookie-gpc-notice hidden>
                            <?php esc_html_e('Global Privacy Control is enabled in this browser, so marketing cookies stay off.', 'tomschooloflife-plugin'); ?>
                        </div>

                        <?php foreach ($categories as $category_key => $category) : ?>
                            <?php $input_id = 'tsol-cookie-category-' . sanitize_html_class($category_key); ?>
                            <fieldset class="tsol-cookie-consent__category">
                                <div>
                                    <legend><?php echo esc_html($category['label']); ?></legend>
                                    <p><?php echo esc_html($category['description']); ?></p>
                                </div>

                                <label class="tsol-cookie-consent__switch" for="<?php echo esc_attr($input_id); ?>">
                                    <span class="screen-reader-text"><?php echo esc_html($category['label']); ?></span>
                                    <input
                                        id="<?php echo esc_attr($input_id); ?>"
                                        type="checkbox"
                                        data-tsol-cookie-category="<?php echo esc_attr($category_key); ?>"
                                        <?php checked(true, $category['required']); ?>
                                        <?php disabled(true, $category['required'] || !$category['enabled']); ?>
                                    >
                                    <span aria-hidden="true"></span>
                                </label>
                            </fieldset>
                        <?php endforeach; ?>

                        <div class="tsol-cookie-consent__dialog-actions">
                            <button type="button" class="tsol-cookie-consent__button tsol-cookie-consent__button--secondary" data-tsol-cookie-reject>
                                <?php echo esc_html($settings['reject_all_label']); ?>
                            </button>
                            <button type="button" class="tsol-cookie-consent__button tsol-cookie-consent__button--primary" data-tsol-cookie-save>
                                <?php echo esc_html($settings['save_label']); ?>
                            </button>
                        </div>

                        <p class="tsol-cookie-consent__status" data-tsol-cookie-status role="status" aria-live="polite"></p>
                    </form>
                </div>
            </div>

            <button
                type="button"
                class="<?php echo esc_attr(implode(' ', $reopen_classes)); ?>"
                data-tsol-cookie-reopen
                aria-label="<?php echo esc_attr($settings['settings_label']); ?>"
                <?php echo $show_reopen_button ? '' : 'hidden'; ?>
            >
                <span aria-hidden="true"></span>
            </button>
        </div>
        <?php
    }

    public function add_admin_bar_button($wp_admin_bar) {
        $settings = $this->get_settings();

        if (
            $settings['enabled'] !== '1'
            || $settings['show_admin_bar_button'] !== '1'
            || is_admin()
            || !current_user_can('manage_options')
        ) {
            return;
        }

        $wp_admin_bar->add_node(array(
            'id' => 'tsol-cookie-consent-open',
            'title' => __('Cookie Preferences', 'tomschooloflife-plugin'),
            'href' => '#',
            'meta' => array(
                'class' => 'tsol-cookie-consent-admin-bar',
            ),
        ));
    }

    private function should_load() {
        if (get_option('tsol_site_plugin_enabled', '1') !== '1') {
            return false;
        }

        $settings = $this->get_settings();

        if ($settings['enabled'] !== '1' || is_admin() || wp_doing_ajax() || $this->is_json_or_rest_request()) {
            return false;
        }

        /**
         * Filters whether the cookie consent feature should load on the current request.
         *
         * @param bool  $should_load Whether to load the feature.
         * @param array $settings    Cookie consent settings.
         */
        return (bool) apply_filters('tsol_site_cookie_consent_should_load', true, $settings);
    }

    private function get_settings() {
        if ($this->settings === null) {
            $this->settings = TSOL_Cookie_Consent_Settings::get_settings();
        }

        return $this->settings;
    }

    private function is_json_or_rest_request() {
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return true;
        }

        if (function_exists('wp_is_json_request') && wp_is_json_request()) {
            return true;
        }

        return false;
    }
}
