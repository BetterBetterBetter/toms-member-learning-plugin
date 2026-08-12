<?php
/**
 * Cookie consent frontend feature.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Cookie_Consent implements TSOL_Site_Feature {

    private $settings = null;
    private $consent_managed_vendor_scripts = array();

    public function init() {
        add_action('wp_head', array($this, 'render_consent_mode_defaults'), 0);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('wp_enqueue_scripts', array($this, 'capture_consent_managed_vendor_scripts'), PHP_INT_MAX);
        add_action('wp_footer', array($this, 'render_consent_managed_vendor_scripts'), 5);
        add_action('wp_footer', array($this, 'render_banner'));
        add_action('admin_bar_menu', array($this, 'add_admin_bar_button'), 110);
        add_filter('wpcode_snippet_output', array($this, 'gate_wpcode_marketing_snippets'), 20, 2);
        add_filter('the_content', array($this, 'gate_third_party_embeds'), PHP_INT_MAX);
        add_filter('widget_text_content', array($this, 'gate_third_party_embeds'), PHP_INT_MAX);
        $this->register_hfcm_consent_bridge();
    }

    public function gate_wpcode_marketing_snippets($output, $snippet) {
        if (!$this->should_load() || !is_object($snippet) || !method_exists($snippet, 'get_id')) {
            return $output;
        }

        /**
         * Filters WPCode snippet IDs whose emitted scripts require Marketing consent.
         *
         * These are the existing MemberPress and WooCommerce Tapfiliate handlers.
         * Their PHP still prepares page-specific conversion data, but every script
         * tag it emits remains inert until the consent frontend activates it.
         *
         * @param int[] $snippet_ids Marketing-controlled WPCode snippet IDs.
         */
        $snippet_ids = apply_filters('tsol_site_cookie_consent_wpcode_marketing_snippet_ids', array(102804, 102816));
        $snippet_id = absint($snippet->get_id());

        if (!in_array($snippet_id, array_map('absint', (array) $snippet_ids), true)) {
            return $output;
        }

        return $this->make_script_tags_consent_managed($output, 'marketing', 'wpcode-' . $snippet_id);
    }

    public function render_hfcm_header_scripts() {
        $this->render_hfcm_scripts('hfcm_header_scripts');
    }

    public function render_hfcm_footer_scripts() {
        $this->render_hfcm_scripts('hfcm_footer_scripts');
    }

    public function gate_hfcm_snippet_output($output) {
        /**
         * Filters HFCM snippet IDs and the consent categories that control them.
         *
         * @param array $snippet_categories HFCM snippet ID to category map.
         */
        $snippet_categories = apply_filters('tsol_site_cookie_consent_hfcm_snippet_categories', array(
            4 => 'marketing',
            14 => 'marketing',
            21 => 'marketing',
            24 => 'marketing',
            26 => 'marketing',
            28 => 'marketing',
            37 => 'marketing',
            57 => 'analytics',
        ));

        return preg_replace_callback(
            '/(<!-- HFCM by 99 Robots - Snippet #\s*(\d+):.*?-->)(.*?)(<!-- \/end HFCM by 99 Robots -->)/is',
            function($matches) use ($snippet_categories) {
                $snippet_id = absint($matches[2]);
                $category = isset($snippet_categories[$snippet_id]) ? sanitize_key($snippet_categories[$snippet_id]) : '';

                if (!in_array($category, array('analytics', 'marketing'), true)) {
                    return $matches[0];
                }

                return $matches[1]
                    . $this->make_script_tags_consent_managed($matches[3], $category, 'hfcm-' . $snippet_id)
                    . $matches[4];
            },
            (string) $output
        );
    }

    public function gate_third_party_embeds($content) {
        if (!$this->should_load() || stripos((string) $content, '<iframe') === false) {
            return $content;
        }

        /**
         * Filters third-party iframe hosts and their consent categories.
         *
         * @param array $host_categories Lowercase hostname to category map.
         */
        $host_categories = apply_filters('tsol_site_cookie_consent_embed_hosts', array(
            'player.vimeo.com' => 'marketing',
            'vimeo.com' => 'marketing',
            'www.vimeo.com' => 'marketing',
            'youtube.com' => 'marketing',
            'www.youtube.com' => 'marketing',
            'youtube-nocookie.com' => 'marketing',
            'www.youtube-nocookie.com' => 'marketing',
        ));

        return preg_replace_callback('/<iframe\b([^>]*)>(.*?)<\/iframe>/is', static function($matches) use ($host_categories) {
            $attributes = (string) $matches[1];
            $inner_html = (string) $matches[2];
            $source_match = array();

            if (
                stripos($attributes, 'data-tsol-consent-src=') !== false
                || !preg_match('/\s+src\s*=\s*(["\'])(.*?)\1/i', $attributes, $source_match)
            ) {
                return $matches[0];
            }

            $source = html_entity_decode($source_match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $hostname = strtolower((string) wp_parse_url($source, PHP_URL_HOST));
            $category = isset($host_categories[$hostname]) ? sanitize_key($host_categories[$hostname]) : '';

            if (!in_array($category, array('analytics', 'marketing'), true)) {
                return $matches[0];
            }

            $attributes = preg_replace('/\s+src\s*=\s*(["\'])(.*?)\1/i', '', $attributes, 1);

            return '<div class="tsol-cookie-consent-embed" data-tsol-consent-embed data-tsol-consent-category="' . esc_attr($category) . '">'
                . '<iframe' . rtrim((string) $attributes) . ' src="about:blank" data-tsol-consent-src="' . esc_url($source) . '">' . $inner_html . '</iframe>'
                . '<div class="tsol-cookie-consent-embed__placeholder" data-tsol-consent-embed-placeholder>'
                . '<p>' . esc_html__('This video is available after you allow marketing cookies.', 'tomschooloflife-plugin') . '</p>'
                . '<button type="button" data-tsol-cookie-embed-manage>' . esc_html__('Cookie settings', 'tomschooloflife-plugin') . '</button>'
                . '</div>'
                . '</div>';
        }, (string) $content);
    }

    public function capture_consent_managed_vendor_scripts() {
        if (!$this->should_load()) {
            return;
        }

        /**
         * Filters script handles that should be converted to inert consent-managed tags.
         *
         * This captures both the registered source and inline data attached to a handle,
         * which preserves conversion payloads while preventing execution before consent.
         *
         * @param array $handles Script handle to consent-category map.
         */
        $handles = apply_filters('tsol_site_cookie_consent_managed_script_handles', array(
            'tapfiliate-js' => 'marketing',
        ));
        $wp_scripts = wp_scripts();

        foreach ((array) $handles as $handle => $category) {
            $handle = sanitize_key((string) $handle);
            $category = sanitize_key((string) $category);

            if (
                $handle === ''
                || !in_array($category, array('analytics', 'marketing'), true)
                || !wp_script_is($handle, 'enqueued')
                || !isset($wp_scripts->registered[$handle])
            ) {
                continue;
            }

            $registered = $wp_scripts->registered[$handle];
            $extra = is_array($registered->extra) ? $registered->extra : array();
            $this->consent_managed_vendor_scripts[] = array(
                'handle' => $handle,
                'category' => $category,
                'src' => (string) $registered->src,
                'before' => isset($extra['before']) ? (array) $extra['before'] : array(),
                'data' => isset($extra['data']) ? (string) $extra['data'] : '',
                'after' => isset($extra['after']) ? (array) $extra['after'] : array(),
            );

            wp_dequeue_script($handle);
        }
    }

    public function render_consent_managed_vendor_scripts() {
        foreach ($this->consent_managed_vendor_scripts as $script) {
            $attributes = array(
                'type' => 'text/plain',
                'data-tsol-consent-category' => $script['category'],
                'data-tsol-consent-vendor' => $script['handle'],
            );
            $index = 0;

            foreach ($script['before'] as $code) {
                $this->render_consent_managed_inline_script($code, $attributes, $script['handle'] . '-before-' . $index);
                $index++;
            }

            if ($script['data'] !== '') {
                $this->render_consent_managed_inline_script($script['data'], $attributes, $script['handle'] . '-data');
            }

            if ($script['src'] !== '') {
                echo wp_get_script_tag(array_merge($attributes, array(
                    'id' => 'tsol-consent-' . sanitize_html_class($script['handle']) . '-src',
                    'src' => esc_url($script['src']),
                )));
            }

            foreach ($script['after'] as $code) {
                $this->render_consent_managed_inline_script($code, $attributes, $script['handle'] . '-after-' . $index);
                $index++;
            }
        }
    }

    public function render_consent_mode_defaults() {
        if (!$this->should_load()) {
            return;
        }

        $settings = $this->get_settings();

        if ($settings['google_consent_mode'] !== '1') {
            return;
        }

        // Always render a denied default so a cached page can never inherit another
        // visitor's stored consent. The browser applies the visitor's choice later.
        $state = TSOL_Cookie_Consent_Settings::get_consent_mode_state(null);

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

        $this->enqueue_launcher_dock_assets();

        wp_enqueue_style(
            'tsol-cookie-consent',
            TSOL_SITE_PLUGIN_URL . 'assets/features/cookie-consent/cookie-consent.css',
            array('tsol-site-launcher-dock'),
            TSOL_SITE_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'tsol-cookie-consent',
            TSOL_SITE_PLUGIN_URL . 'assets/features/cookie-consent/cookie-consent.js',
            array('tsol-site-launcher-dock'),
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
            'cookieCleanup' => TSOL_Cookie_Consent_Settings::get_cookie_cleanup_payload(),
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
                    <?php echo TSOL_Cookie_Consent_Settings::get_cookie_icon_svg('tsol-cookie-consent__icon'); ?>
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
                data-tsol-launcher-dock-item
                data-tsol-launcher-dock-position="<?php echo esc_attr($settings['reopen_position']); ?>"
                data-tsol-launcher-dock-priority="10"
                aria-label="<?php echo esc_attr($settings['settings_label']); ?>"
                <?php echo $show_reopen_button ? '' : 'hidden'; ?>
            >
                <?php echo TSOL_Cookie_Consent_Settings::get_cookie_icon_svg('tsol-cookie-consent__icon'); ?>
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

    private function enqueue_launcher_dock_assets() {
        wp_enqueue_style(
            'tsol-site-launcher-dock',
            TSOL_SITE_PLUGIN_URL . 'assets/shared/launcher-dock.css',
            array(),
            TSOL_SITE_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'tsol-site-launcher-dock',
            TSOL_SITE_PLUGIN_URL . 'assets/shared/launcher-dock.js',
            array(),
            TSOL_SITE_PLUGIN_VERSION,
            true
        );
    }

    private function register_hfcm_consent_bridge() {
        if (!class_exists('NNR_HFCM')) {
            return;
        }

        $hooks = array(
            'wp_head' => array(
                'original' => 'hfcm_header_scripts',
                'replacement' => 'render_hfcm_header_scripts',
            ),
            'wp_footer' => array(
                'original' => 'hfcm_footer_scripts',
                'replacement' => 'render_hfcm_footer_scripts',
            ),
        );

        foreach ($hooks as $hook => $callbacks) {
            $original = array('NNR_HFCM', $callbacks['original']);
            $priority = has_action($hook, $original);

            if ($priority === false) {
                continue;
            }

            remove_action($hook, $original, $priority);
            add_action($hook, array($this, $callbacks['replacement']), $priority);
        }
    }

    private function render_hfcm_scripts($callback) {
        if (!class_exists('NNR_HFCM') || !is_callable(array('NNR_HFCM', $callback))) {
            return;
        }

        if (!$this->should_load()) {
            call_user_func(array('NNR_HFCM', $callback));
            return;
        }

        ob_start();
        call_user_func(array('NNR_HFCM', $callback));
        $output = ob_get_clean();

        echo $this->gate_hfcm_snippet_output($output);
    }

    private function make_script_tags_consent_managed($output, $category, $vendor) {
        $category = sanitize_key((string) $category);
        $vendor = sanitize_html_class((string) $vendor);

        return preg_replace_callback('/<script\b([^>]*)>/i', static function($matches) use ($category, $vendor) {
            $attributes = preg_replace('/\s+type\s*=\s*(["\'])[^"\']*\1/i', '', $matches[1]);
            $attributes = preg_replace('/\s+data-tsol-consent-(?:category|vendor)\s*=\s*(["\'])[^"\']*\1/i', '', $attributes);

            return '<script' . rtrim((string) $attributes) . ' type="text/plain" data-tsol-consent-category="' . $category . '" data-tsol-consent-vendor="' . $vendor . '">';
        }, (string) $output);
    }

    private function render_consent_managed_inline_script($code, $attributes, $id_suffix) {
        $code = trim((string) $code);

        if ($code === '') {
            return;
        }

        $attributes['id'] = 'tsol-consent-' . sanitize_html_class($id_suffix);
        echo wp_get_inline_script_tag($code, $attributes);
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
