<?php
/**
 * TSOL WordPress -> Library OAuth-style authentication bridge.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Auth {

    private const TOKEN_TTL = 300;
    private const LOGOUT_TTL = 60;
    private const CLEANUP_HOOK = 'tsol_library_auth_cleanup';
    private const FOOTER_MENU_LOCATION = 'tsol_library_footer';
    private $settings;
    private $suppress_logout_propagation = false;

    public function __construct() {
        $this->settings = new TSOL_Library_Auth_Settings();
    }

    public function init() {
        $this->settings->init();
        TSOL_Library_Auth_Revocation::register_hooks();
        add_action('init', array($this, 'mark_auth_request_uncacheable'), 0);
        add_action('init', array($this, 'maybe_install'));
        add_filter('rocket_cache_reject_uri', array($this, 'exclude_auth_routes_from_wp_rocket'), 10, 2);
        add_action(self::CLEANUP_HOOK, array('TSOL_Library_Auth_Repository', 'cleanup'));
        add_action('admin_post_tsol_library_authorize', array($this, 'authorize'));
        add_action('admin_post_nopriv_tsol_library_authorize', array($this, 'authorize'));
        add_action('admin_post_tsol_library_logout', array($this, 'logout_from_library'));
        add_action('admin_post_nopriv_tsol_library_logout', array($this, 'logout_from_library'));
        add_action('after_setup_theme', array($this, 'register_navigation_locations'));
        add_action('rest_api_init', array($this, 'register_routes'));

        // MemberPress redirects during wp_logout at priority 99999. This fixed,
        // signed Library hop must run immediately before it in browser requests.
        add_action('wp_logout', array($this, 'propagate_wordpress_logout'), 99998, 1);
    }

    /**
     * Ensure application caches never store authentication or authorization data.
     */
    public function mark_auth_request_uncacheable() {
        $action = isset($_GET['action']) && is_scalar($_GET['action'])
            ? sanitize_key(wp_unslash($_GET['action']))
            : '';
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '';
        $request_path = (string) wp_parse_url($request_uri, PHP_URL_PATH);
        $is_library_rest = strpos($request_path, '/' . rest_get_url_prefix() . '/tsol-library/v1/') !== false;
        $is_auth_request = in_array($action, array('tsol_library_authorize', 'tsol_library_logout'), true) || $is_library_rest;

        if ($is_auth_request && !defined('DONOTCACHEPAGE')) {
            define('DONOTCACHEPAGE', true);
        }
    }

    /**
     * Persist explicit exclusions when WP Rocket regenerates its cache config.
     */
    public function exclude_auth_routes_from_wp_rocket($uris) {
        $uris = is_array($uris) ? $uris : array();
        $uris[] = '/wp-admin/admin-post\\.php';
        $uris[] = '/(index\\.php/)?' . preg_quote(rest_get_url_prefix(), '/') . '/tsol-library/v1(/.*|$)';
        return array_values(array_unique($uris));
    }

    public static function activate() {
        TSOL_Library_Auth_Repository::install();
        TSOL_Library_Auth_Revocation::install();
        update_option('tsol_library_auth_schema_version', TSOL_Library_Auth_Repository::SCHEMA_VERSION, false);
        self::schedule_cleanup();
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
        TSOL_Library_Auth_Revocation::deactivate();
    }

    public function maybe_install() {
        if (get_option('tsol_library_auth_schema_version') !== TSOL_Library_Auth_Repository::SCHEMA_VERSION) {
            TSOL_Library_Auth_Repository::install();
            update_option('tsol_library_auth_schema_version', TSOL_Library_Auth_Repository::SCHEMA_VERSION, false);
        }
        TSOL_Library_Auth_Revocation::maybe_install();
        self::schedule_cleanup();
    }

    private static function schedule_cleanup() {
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CLEANUP_HOOK);
        }
    }

    public function register_routes() {
        register_rest_route('tsol-library/v1', '/token', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'token'),
            'permission_callback' => array($this, 'public_rest_endpoint'),
        ));
        register_rest_route('tsol-library/v1', '/userinfo', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'userinfo'),
            'permission_callback' => array($this, 'public_rest_endpoint'),
        ));
        register_rest_route('tsol-library/v1', '/content-access/(?P<user_id>\d+)/(?P<post_id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'content_access'),
            'permission_callback' => array($this, 'public_rest_endpoint'),
            'args' => array(
                'user_id' => array('required' => true, 'validate_callback' => static function ($value) { return absint($value) > 0; }),
                'post_id' => array('required' => true, 'validate_callback' => static function ($value) { return absint($value) > 0; }),
            ),
        ));
        register_rest_route('tsol-library/v1', '/content-access/(?P<user_id>\d+)', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array($this, 'content_access_batch'),
            'permission_callback' => array($this, 'public_rest_endpoint'),
            'args' => array(
                'user_id' => array('required' => true, 'validate_callback' => static function ($value) { return absint($value) > 0; }),
            ),
        ));
        register_rest_route('tsol-library/v1', '/catalogue', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'catalogue'),
            'permission_callback' => array($this, 'public_rest_endpoint'),
            'args' => array(
                'after_id' => array('default' => 0, 'sanitize_callback' => 'absint'),
                'per_page' => array('default' => 50, 'sanitize_callback' => 'absint'),
            ),
        ));
        register_rest_route('tsol-library/v1', '/catalogue/(?P<post_id>\d+)', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'catalogue_item'),
            'permission_callback' => array($this, 'public_rest_endpoint'),
            'args' => array(
                'post_id' => array('required' => true, 'validate_callback' => static function ($value) { return absint($value) > 0; }),
            ),
        ));
        register_rest_route('tsol-library/v1', '/changes', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'catalogue_changes'),
            'permission_callback' => array($this, 'public_rest_endpoint'),
            'args' => array(
                'after' => array('default' => 0, 'sanitize_callback' => 'absint'),
                'per_page' => array('default' => 100, 'sanitize_callback' => 'absint'),
            ),
        ));
        register_rest_route('tsol-library/v1', '/readiness', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'readiness'),
            'permission_callback' => array($this, 'public_rest_endpoint'),
        ));
        register_rest_route('tsol-library/v1', '/footer-navigation', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'footer_navigation'),
            'permission_callback' => array($this, 'public_rest_endpoint'),
        ));
        register_rest_route('tsol-library/v1', '/header-navigation', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array($this, 'header_navigation'),
            'permission_callback' => array($this, 'public_rest_endpoint'),
        ));
    }

    public function register_navigation_locations() {
        register_nav_menu(
            self::FOOTER_MENU_LOCATION,
            __('TSOL Library Footer', 'tomschooloflife-plugin')
        );
    }

    /**
     * OAuth and server-authenticated endpoints perform their own narrow checks.
     */
    public function public_rest_endpoint() {
        return true;
    }

    public function authorize() {
        $this->browser_security_headers();
        $client_id = $this->query_string('client_id');
        $redirect_uri = $this->query_string('redirect_uri');
        $state = $this->query_string('state');
        $challenge = $this->query_string('code_challenge');
        $method = $this->query_string('code_challenge_method');
        $response_type = $this->query_string('response_type');

        $rate = TSOL_Library_Auth_Rate_Limiter::check('authorize', 30, MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) {
            TSOL_Library_Auth_Logger::event('authorize', array('outcome' => 'failure', 'error' => 'rate_limited', 'endpoint' => 'authorize'));
            $rate_data = $rate->get_error_data();
            $retry_after = is_array($rate_data) && isset($rate_data['retry_after']) ? max(1, absint($rate_data['retry_after'])) : MINUTE_IN_SECONDS;
            header('Retry-After: ' . $retry_after, true);
            $this->oauth_error('temporarily_unavailable', $client_id, $state, $redirect_uri, 429);
        }

        if (!TSOL_Library_Auth_Settings::configured()) {
            TSOL_Library_Auth_Logger::event('authorize', array('outcome' => 'failure', 'error' => 'not_ready', 'endpoint' => 'authorize'));
            $this->oauth_error('temporarily_unavailable', $client_id, $state, $redirect_uri, 503);
        }
        if (!$this->valid_client($client_id, $redirect_uri)) {
            TSOL_Library_Auth_Logger::event('authorize', array('outcome' => 'failure', 'error' => 'invalid_client', 'endpoint' => 'authorize'));
            $this->oauth_error('invalid_request', '', '', '', 400);
        }
        if (!$this->valid_state($state) || $response_type !== 'code' || $method !== 'S256' || !$this->valid_pkce_value($challenge)) {
            TSOL_Library_Auth_Logger::event('authorize', array('outcome' => 'failure', 'error' => 'invalid_request', 'endpoint' => 'authorize', 'client_id' => $client_id));
            $this->oauth_error('invalid_request', $client_id, $state, $redirect_uri, 400);
        }

        if (!is_user_logged_in()) {
            wp_safe_redirect(wp_login_url($this->current_authorize_url(array(
                'client_id' => $client_id,
                'redirect_uri' => $redirect_uri,
                'state' => $state,
                'code_challenge' => $challenge,
                'code_challenge_method' => $method,
                'response_type' => $response_type,
            ))));
            exit;
        }

        $user_id = get_current_user_id();

        $code = TSOL_Library_Auth_Repository::create($user_id, $client_id, $redirect_uri, $challenge);
        if (is_wp_error($code)) {
            TSOL_Library_Auth_Logger::event('authorize', array('outcome' => 'failure', 'error' => $code->get_error_code(), 'endpoint' => 'authorize', 'user_id' => $user_id, 'client_id' => $client_id));
            $this->oauth_error('server_error', $client_id, $state, $redirect_uri, 500);
        }

        TSOL_Library_Auth_Logger::event('authorize', array('outcome' => 'success', 'endpoint' => 'authorize', 'user_id' => $user_id, 'client_id' => $client_id));
        wp_redirect(add_query_arg(array('code' => $code, 'state' => $state), $redirect_uri)); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Exact configured callback.
        exit;
    }

    public function token(WP_REST_Request $request) {
        $started_at = microtime(true);
        $server_only = $this->require_server_request($request);
        if (is_wp_error($server_only)) {
            return $this->rest_error('invalid_request', __('Browser requests are not accepted by this endpoint.', 'tomschooloflife-plugin'), 403);
        }
        $rate = TSOL_Library_Auth_Rate_Limiter::check('token', 60, MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) {
            return $this->rate_error($rate, 'token');
        }
        if (!TSOL_Library_Auth_Settings::configured()) {
            return $this->logged_rest_error('token', 'not_ready', 503, $started_at);
        }

        list($client_id, $client_secret) = $this->client_credentials($request);
        if (!$this->valid_secret($client_id, $client_secret)) {
            return $this->logged_rest_error('token', 'invalid_client', 401, $started_at, 0, $client_id);
        }
        if ($this->request_string($request, 'grant_type') !== 'authorization_code') {
            return $this->logged_rest_error('token', 'unsupported_grant_type', 400, $started_at, 0, $client_id);
        }

        $code = $this->request_string($request, 'code');
        $redirect_uri = $this->request_string($request, 'redirect_uri');
        $verifier = $this->request_string($request, 'code_verifier');
        if (!preg_match('/^[A-Za-z0-9_-]{43,128}$/', $code) || !$this->valid_client($client_id, $redirect_uri) || !$this->valid_pkce_value($verifier)) {
            return $this->logged_rest_error('token', 'invalid_request', 400, $started_at, 0, $client_id);
        }

        $user_id = TSOL_Library_Auth_Repository::consume($code, $client_id, $redirect_uri, $verifier);
        if (is_wp_error($user_id)) {
            return $this->logged_rest_error('token', 'invalid_grant', 400, $started_at, 0, $client_id);
        }
        try {
            $access_token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        } catch (Throwable $exception) {
            return $this->logged_rest_error('token', 'server_error', 500, $started_at, $user_id, $client_id);
        }
        set_transient('tsol_library_token_' . hash('sha256', $access_token), array(
            'user_id' => (int) $user_id,
            'client_id' => $client_id,
            'issued_at' => time(),
        ), self::TOKEN_TTL);

        TSOL_Library_Auth_Logger::event('token', array('outcome' => 'success', 'endpoint' => 'token', 'user_id' => $user_id, 'client_id' => $client_id, 'duration_ms' => $this->duration_ms($started_at)));
        return $this->rest_response(array(
            'access_token' => $access_token,
            'token_type' => 'Bearer',
            'expires_in' => self::TOKEN_TTL,
        ));
    }

    public function userinfo(WP_REST_Request $request) {
        $started_at = microtime(true);
        if (is_wp_error($this->require_server_request($request))) {
            return $this->rest_error('invalid_request', __('Browser requests are not accepted by this endpoint.', 'tomschooloflife-plugin'), 403);
        }
        $rate = TSOL_Library_Auth_Rate_Limiter::check('userinfo', 120, MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) {
            return $this->rate_error($rate, 'userinfo');
        }
        if (!TSOL_Library_Auth_Settings::configured()) {
            return $this->logged_rest_error('userinfo', 'not_ready', 503, $started_at);
        }

        $token_data = $this->bearer_token_data($request);
        if (is_wp_error($token_data)) {
            return $this->logged_rest_error('userinfo', 'invalid_token', 401, $started_at);
        }
        $user_id = (int) $token_data['user_id'];
        $user = get_user_by('id', $user_id);
        if (!$user) {
            return $this->logged_rest_error('userinfo', 'unknown_user', 404, $started_at, $user_id, (string) $token_data['client_id']);
        }

        // WordPress accounts do not universally prove ownership of their email
        // address. Sites with an audited verification source may opt in here.
        $email_verified = (bool) apply_filters('tsol_library_auth_email_verified', false, $user);
        TSOL_Library_Auth_Logger::event('userinfo', array('outcome' => 'success', 'endpoint' => 'userinfo', 'user_id' => $user_id, 'client_id' => (string) $token_data['client_id'], 'duration_ms' => $this->duration_ms($started_at)));
        return $this->rest_response(array(
            'sub' => (string) $user->ID,
            'id' => (string) $user->ID,
            'email' => (string) $user->user_email,
            'email_verified' => $email_verified,
            'name' => (string) ($user->display_name ?: $user->user_login),
            'given_name' => (string) $user->first_name,
            'family_name' => (string) $user->last_name,
        ));
    }

    public function content_access(WP_REST_Request $request) {
        $started_at = microtime(true);
        $auth_error = $this->server_client_auth($request, 'content_access', 600);
        if (is_wp_error($auth_error)) {
            return $this->server_auth_error($auth_error, 'content_access', $started_at);
        }

        $user_id = absint($request['user_id']);
        $result = TSOL_Library_Auth_Entitlements::for_content($user_id, absint($request['post_id']));
        if (is_wp_error($result)) {
            $status = in_array($result->get_error_code(), array('unknown_user', 'unknown_content'), true) ? 404 : 503;
            return $this->logged_rest_error('content_access', $result->get_error_code(), $status, $started_at, $user_id, $auth_error);
        }

        TSOL_Library_Auth_Logger::event('content_access', array('outcome' => 'success', 'endpoint' => 'content_access', 'user_id' => $user_id, 'client_id' => $auth_error, 'duration_ms' => $this->duration_ms($started_at)));
        return $this->rest_response($result);
    }

    public function content_access_batch(WP_REST_Request $request) {
        $started_at = microtime(true);
        $auth_error = $this->server_client_auth($request, 'content_access_batch', 120);
        if (is_wp_error($auth_error)) {
            return $this->server_auth_error($auth_error, 'content_access_batch', $started_at);
        }

        $body = $request->get_json_params();
        $post_ids = is_array($body) && isset($body['post_ids']) && is_array($body['post_ids'])
            ? $body['post_ids']
            : array();
        if (empty($post_ids) || count($post_ids) > 200) {
            return $this->logged_rest_error('content_access_batch', 'invalid_request', 400, $started_at, absint($request['user_id']), $auth_error);
        }

        $normalized_ids = array();
        foreach ($post_ids as $post_id) {
            if (!is_int($post_id) && !(is_string($post_id) && ctype_digit($post_id))) {
                return $this->logged_rest_error('content_access_batch', 'invalid_request', 400, $started_at, absint($request['user_id']), $auth_error);
            }
            $post_id = absint($post_id);
            if ($post_id <= 0) {
                return $this->logged_rest_error('content_access_batch', 'invalid_request', 400, $started_at, absint($request['user_id']), $auth_error);
            }
            $normalized_ids[$post_id] = $post_id;
        }

        $user_id = absint($request['user_id']);
        $items = array();
        foreach (array_values($normalized_ids) as $post_id) {
            $result = TSOL_Library_Auth_Entitlements::for_content($user_id, $post_id);
            if (is_wp_error($result)) {
                if ('memberpress_unavailable' === $result->get_error_code()) {
                    return $this->logged_rest_error('content_access_batch', 'memberpress_unavailable', 503, $started_at, $user_id, $auth_error);
                }
                $items[] = array(
                    'post_id' => $post_id,
                    'can_access' => false,
                    'is_protected' => true,
                    'access_source' => 'unavailable',
                    'checked_at' => gmdate('c'),
                );
                continue;
            }
            $items[] = $result;
        }

        TSOL_Library_Auth_Logger::event('content_access_batch', array(
            'outcome' => 'success',
            'endpoint' => 'content_access_batch',
            'user_id' => $user_id,
            'client_id' => $auth_error,
            'item_count' => count($items),
            'duration_ms' => $this->duration_ms($started_at),
        ));
        return $this->rest_response(array('items' => $items, 'checked_at' => gmdate('c')));
    }

    public function catalogue(WP_REST_Request $request) {
        $started_at = microtime(true);
        $auth_error = $this->server_client_auth($request, 'catalogue', 120);
        if (is_wp_error($auth_error)) {
            return $this->server_auth_error($auth_error, 'catalogue', $started_at);
        }

        $payload = TSOL_Library_Content_Catalogue::snapshot(
            absint($request->get_param('after_id')),
            absint($request->get_param('per_page'))
        );
        TSOL_Library_Auth_Logger::event('catalogue', array(
            'outcome' => 'success',
            'endpoint' => 'catalogue',
            'client_id' => $auth_error,
            'item_count' => count($payload['items']),
            'duration_ms' => $this->duration_ms($started_at),
        ));
        return $this->rest_response($payload);
    }

    public function catalogue_item(WP_REST_Request $request) {
        $started_at = microtime(true);
        $auth_error = $this->server_client_auth($request, 'catalogue_item', 600);
        if (is_wp_error($auth_error)) {
            return $this->server_auth_error($auth_error, 'catalogue_item', $started_at);
        }

        $record = TSOL_Library_Content_Catalogue::record(absint($request['post_id']));
        if (is_wp_error($record)) {
            return $this->logged_rest_error('catalogue_item', 'unknown_catalogue_content', 404, $started_at, 0, $auth_error);
        }
        return $this->rest_response(array(
            'schema_version' => TSOL_Library_Content_Catalogue::SCHEMA_VERSION,
            'generated_at' => gmdate('c'),
            'item' => $record,
        ));
    }

    public function catalogue_changes(WP_REST_Request $request) {
        $started_at = microtime(true);
        $auth_error = $this->server_client_auth($request, 'catalogue_changes', 120);
        if (is_wp_error($auth_error)) {
            return $this->server_auth_error($auth_error, 'catalogue_changes', $started_at);
        }

        $payload = TSOL_Library_Content_Catalogue::changes(
            absint($request->get_param('after')),
            absint($request->get_param('per_page'))
        );
        TSOL_Library_Auth_Logger::event('catalogue_changes', array(
            'outcome' => 'success',
            'endpoint' => 'catalogue_changes',
            'client_id' => $auth_error,
            'item_count' => count($payload['changes']),
            'duration_ms' => $this->duration_ms($started_at),
        ));
        return $this->rest_response($payload);
    }

    public function readiness(WP_REST_Request $request) {
        $started_at = microtime(true);
        $auth_error = $this->server_client_auth($request, 'readiness', 60, false);
        if (is_wp_error($auth_error)) {
            return $this->server_auth_error($auth_error, 'readiness', $started_at);
        }

        $error = TSOL_Library_Auth_Settings::readiness_error();
        $configured = $error === '';
        $memberpress_ready = class_exists('MeprUser') && class_exists('MeprRule');
        $ready = $configured && $memberpress_ready;
        return $this->rest_response(array(
            'status' => $ready ? 'ready' : 'not_ready',
            'memberpress' => $memberpress_ready ? 'available' : 'unavailable',
            'configured' => $configured,
        ), $ready ? 200 : 503);
    }

    public function footer_navigation(WP_REST_Request $request) {
        $started_at = microtime(true);
        $auth_error = $this->server_client_auth($request, 'footer_navigation', 600, false);
        if (is_wp_error($auth_error)) {
            return $this->server_auth_error($auth_error, 'footer_navigation', $started_at);
        }

        $locations = get_nav_menu_locations();
        $menu_id = isset($locations[self::FOOTER_MENU_LOCATION])
            ? absint($locations[self::FOOTER_MENU_LOCATION])
            : 0;
        $items = array();

        if ($menu_id > 0) {
            $menu_items = wp_get_nav_menu_items($menu_id, array('post_status' => 'publish'));
            if (is_array($menu_items)) {
                foreach ($menu_items as $menu_item) {
                    $label = trim(wp_strip_all_tags((string) $menu_item->title));
                    $url = $this->safe_navigation_url((string) $menu_item->url);
                    if ($label === '' || $url === '') {
                        continue;
                    }

                    $items[] = array(
                        'id' => absint($menu_item->ID),
                        'label' => wp_html_excerpt($label, 80, '…'),
                        'url' => $url,
                    );

                    if (count($items) >= 12) {
                        break;
                    }
                }
            }
        }

        TSOL_Library_Auth_Logger::event('footer_navigation', array(
            'outcome' => 'success',
            'endpoint' => 'footer_navigation',
            'client_id' => $auth_error,
            'duration_ms' => $this->duration_ms($started_at),
        ));

        return $this->rest_response(array('items' => $items));
    }

    public function header_navigation(WP_REST_Request $request) {
        $started_at = microtime(true);
        $auth_error = $this->server_client_auth($request, 'header_navigation', 600, false);
        if (is_wp_error($auth_error)) {
            return $this->server_auth_error($auth_error, 'header_navigation', $started_at);
        }

        $previous_user_id = get_current_user_id();
        $items = array();
        wp_set_current_user(0);

        try {
            $menu_id = $this->elementor_header_menu_id();
            if ($menu_id > 0) {
                $menu_items = wp_get_nav_menu_items($menu_id, array('post_status' => 'publish'));
                if (is_array($menu_items)) {
                    foreach ($menu_items as $menu_item) {
                        $classes = array_map('sanitize_key', (array) $menu_item->classes);
                        if (array_intersect(array('account', 'login', 'logout'), $classes)) {
                            continue;
                        }

                        $label = trim(wp_strip_all_tags((string) $menu_item->title));
                        $url = $this->safe_navigation_url((string) $menu_item->url);
                        if ($label === '' || $url === '' || $this->is_library_navigation_url($url)) {
                            continue;
                        }

                        $items[] = array(
                            'id' => absint($menu_item->ID),
                            'parent_id' => absint($menu_item->menu_item_parent),
                            'label' => wp_html_excerpt($label, 80, '…'),
                            'url' => $url,
                            'order' => absint($menu_item->menu_order),
                        );

                        if (count($items) >= 40) {
                            break;
                        }
                    }
                }
            }
        } finally {
            wp_set_current_user($previous_user_id);
        }

        TSOL_Library_Auth_Logger::event('header_navigation', array(
            'outcome' => 'success',
            'endpoint' => 'header_navigation',
            'client_id' => $auth_error,
            'item_count' => count($items),
            'duration_ms' => $this->duration_ms($started_at),
        ));

        return $this->rest_response(array('items' => $items));
    }

    public function logout_from_library() {
        $this->browser_security_headers();
        $message = array(
            'version' => $this->query_string('version'),
            'event' => $this->query_string('event'),
            'audience' => $this->query_string('audience'),
            'jti' => strtolower($this->query_string('jti')),
            'issued_at' => $this->query_string('issued_at'),
            'return_to' => esc_url_raw($this->query_string('return_to')),
            'subject' => strtolower($this->query_string('subject')),
            'signature' => strtolower($this->query_string('signature')),
        );
        $user_id = get_current_user_id();
        if (!$this->valid_logout_message($message, $user_id)) {
            TSOL_Library_Auth_Logger::event('logout', array('outcome' => 'failure', 'error' => 'invalid_signature', 'endpoint' => 'wordpress_logout'));
            $this->redirect_to_library_error('invalid_sign_out');
            $this->render_browser_error('invalid_sign_out', 400);
        }

        $consumed = TSOL_Library_Auth_Repository::consume_message(
            $message['jti'],
            self::LOGOUT_EVENT,
            (int) $message['issued_at'] + self::LOGOUT_TTL
        );
        if (is_wp_error($consumed)) {
            TSOL_Library_Auth_Logger::event('logout', array('outcome' => 'failure', 'error' => $consumed->get_error_code(), 'endpoint' => 'wordpress_logout'));
            $this->redirect_to_library_error('invalid_sign_out');
            $this->render_browser_error('invalid_sign_out', 400);
        }

        $this->suppress_logout_propagation = true;
        wp_logout();
        wp_safe_redirect(home_url('/'));
        exit;
    }

    public function propagate_wordpress_logout($user_id) {
        if ($this->suppress_logout_propagation || !$user_id || !TSOL_Library_Auth_Settings::configured() || !$this->is_browser_request() || headers_sent()) {
            return;
        }

        $return_to = home_url('/');
        $timestamp = time();
        $jti = wp_generate_uuid4();
        $message = $this->logout_message('tsol-library', $jti, $timestamp, $return_to, (int) $user_id);
        $url = add_query_arg($message, TSOL_Library_Auth_Settings::app_url() . '/auth/wordpress-logout');

        wp_redirect($url); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Fixed configured Library origin with HMAC payload.
        exit;
    }

    private function server_client_auth(WP_REST_Request $request, $scope, $limit, $require_ready = true) {
        if (is_wp_error($this->require_server_request($request))) {
            return new WP_Error('invalid_request', __('Browser requests are not accepted by this endpoint.', 'tomschooloflife-plugin'));
        }
        $rate = TSOL_Library_Auth_Rate_Limiter::check($scope, $limit, MINUTE_IN_SECONDS);
        if (is_wp_error($rate)) {
            return $rate;
        }
        list($client_id, $client_secret) = $this->client_credentials($request);
        if (!$this->valid_secret($client_id, $client_secret)) {
            return new WP_Error('invalid_client', __('Client authentication failed.', 'tomschooloflife-plugin'));
        }
        if ($require_ready && !TSOL_Library_Auth_Settings::configured()) {
            return new WP_Error('not_ready', __('Library authentication is not configured.', 'tomschooloflife-plugin'));
        }

        return $client_id;
    }

    private function server_auth_error(WP_Error $error, $endpoint, $started_at) {
        if ($error->get_error_code() === 'rate_limited') {
            return $this->rate_error($error, $endpoint);
        }
        $status = $error->get_error_code() === 'invalid_request' ? 403 : ($error->get_error_code() === 'not_ready' ? 503 : 401);
        return $this->logged_rest_error($endpoint, $error->get_error_code(), $status, $started_at);
    }

    private function bearer_token_data(WP_REST_Request $request) {
        $header = (string) $request->get_header('authorization');
        if (!preg_match('/^Bearer\s+([A-Za-z0-9_-]{43,128})$/i', $header, $matches)) {
            return new WP_Error('invalid_token', __('A valid bearer token is required.', 'tomschooloflife-plugin'));
        }
        $data = get_transient('tsol_library_token_' . hash('sha256', $matches[1]));
        if (!is_array($data) || empty($data['user_id']) || empty($data['client_id'])) {
            return new WP_Error('invalid_token', __('The bearer token is invalid or expired.', 'tomschooloflife-plugin'));
        }
        return $data;
    }

    private function client_credentials(WP_REST_Request $request) {
        $id = (string) ($request->get_header('x-tsol-client-id') ?: $this->request_string($request, 'client_id'));
        $secret = (string) ($request->get_header('x-tsol-client-secret') ?: $this->request_string($request, 'client_secret'));
        $header = (string) $request->get_header('authorization');
        if (stripos($header, 'Basic ') === 0) {
            $decoded = base64_decode(substr($header, 6), true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                list($id, $secret) = explode(':', $decoded, 2);
            }
        }
        return array(trim($id), trim($secret));
    }

    private function valid_secret($client_id, $client_secret) {
        $expected_id = TSOL_Library_Auth_Settings::client_id();
        $expected_secret = TSOL_Library_Auth_Settings::client_secret();
        return $expected_id !== '' && strlen($expected_secret) >= 32 && $client_id !== '' && $client_secret !== '' && hash_equals($expected_id, (string) $client_id) && hash_equals($expected_secret, (string) $client_secret);
    }

    private function valid_client($client_id, $redirect_uri) {
        $expected_id = TSOL_Library_Auth_Settings::client_id();
        $expected_callback = TSOL_Library_Auth_Settings::callback_url();
        return $expected_id !== '' && $expected_callback !== '' && hash_equals($expected_id, (string) $client_id) && hash_equals($expected_callback, (string) $redirect_uri);
    }

    private function valid_pkce_value($value) {
        return is_string($value) && (bool) preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $value);
    }

    private function valid_state($value) {
        return is_string($value) && (bool) preg_match('/^[A-Za-z0-9._~-]{16,512}$/', $value);
    }

    private function current_authorize_url($params) {
        return add_query_arg(array_merge(array('action' => 'tsol_library_authorize'), $params), admin_url('admin-post.php'));
    }

    private function oauth_error($code, $client_id = '', $state = '', $redirect_uri = '', $status = 400) {
        $this->browser_security_headers();
        $code = $this->public_error_code($code);
        if ($redirect_uri !== '' && $this->valid_client($client_id, $redirect_uri)) {
            $args = array('error' => $code);
            if ($this->valid_state($state)) {
                $args['state'] = $state;
            }
            wp_redirect(add_query_arg($args, $redirect_uri)); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Exact configured callback.
            exit;
        }

        $this->redirect_to_library_error($code);
        $this->render_browser_error($code, $status);
    }

    private function public_error_code($code) {
        $code = sanitize_key($code);
        $allowed = array('invalid_request', 'invalid_sign_out', 'server_error', 'temporarily_unavailable');
        return in_array($code, $allowed, true) ? $code : 'sign_in_failed';
    }

    private function redirect_to_library_error($code) {
        $app_url = TSOL_Library_Auth_Settings::app_url();
        if ($app_url === '') {
            return;
        }

        $error_url = add_query_arg('error', $this->public_error_code($code), $app_url . '/auth/error');
        wp_redirect($error_url, 302, 'TSOL Library Authentication'); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Fixed validated Library origin.
        exit;
    }

    private function render_browser_error($code, $status = 400) {
        $code = $this->public_error_code($code);
        $status = (int) $status;
        if ($status < 400 || $status > 599) {
            $status = 400;
        }

        $copy = array(
            'eyebrow' => __('Sign-in interrupted', 'tomschooloflife-plugin'),
            'title' => __('School sign-in unavailable', 'tomschooloflife-plugin'),
            'description' => __('We could not complete School sign-in. Return to TSOL and start again.', 'tomschooloflife-plugin'),
        );

        if ($code === 'invalid_request') {
            $copy = array(
                'eyebrow' => __('Sign-in expired', 'tomschooloflife-plugin'),
                'title' => __('Let’s try that again', 'tomschooloflife-plugin'),
                'description' => __('This sign-in request is invalid or has expired. Return to TSOL and start again.', 'tomschooloflife-plugin'),
            );
        } elseif ($code === 'invalid_sign_out') {
            $copy = array(
                'eyebrow' => __('Sign-out interrupted', 'tomschooloflife-plugin'),
                'title' => __('Unable to complete sign-out', 'tomschooloflife-plugin'),
                'description' => __('We could not verify that sign-out request. Return to TSOL to safely continue.', 'tomschooloflife-plugin'),
            );
        } elseif (in_array($code, array('server_error', 'temporarily_unavailable'), true)) {
            $copy = array(
                'eyebrow' => __('Service unavailable', 'tomschooloflife-plugin'),
                'title' => __('School sign-in is temporarily unavailable', 'tomschooloflife-plugin'),
                'description' => __('Your account has not been changed. Please wait a moment and try again.', 'tomschooloflife-plugin'),
            );
        }

        $this->browser_security_headers();
        status_header($status);
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=' . get_option('blog_charset'), true);
            header("Content-Security-Policy: default-src 'none'; img-src https://tomschooloflife.com; style-src 'unsafe-inline'; base-uri 'none'; form-action 'none'; frame-ancestors 'none'", true);
            header('X-Frame-Options: DENY', true);
            header('X-Robots-Tag: noindex, nofollow, noarchive', true);
        }

        $home_url = esc_url(home_url('/'));
        $language = esc_attr(get_bloginfo('language') ?: 'en-US');
        $direction = is_rtl() ? 'rtl' : 'ltr';
        ?>
        <!doctype html>
        <html lang="<?php echo $language; ?>" dir="<?php echo esc_attr($direction); ?>">
        <head>
            <meta charset="<?php echo esc_attr(get_option('blog_charset')); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <meta name="robots" content="noindex,nofollow,noarchive">
            <title><?php echo esc_html($copy['title']); ?></title>
            <style>
                :root { color-scheme: dark; }
                * { box-sizing: border-box; }
                body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 32px 24px; background: radial-gradient(circle at 15% 35%, rgba(22,155,198,.16), transparent 34%), linear-gradient(135deg, #06182b, #0a2540 58%, #1a3a52); color: #fff; font-family: Inter, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
                main { width: min(100%, 560px); text-align: center; }
                .brand { display: block; width: min(190px, 60vw); height: auto; margin: 0 auto 24px; }
                .panel { width: 100%; padding: clamp(28px, 6vw, 42px); border: 1px solid rgba(255,255,255,.12); border-radius: 18px; background: rgba(255,255,255,.05); box-shadow: 0 24px 70px rgba(0,0,0,.3); }
                .eyebrow { margin: 0; color: #65d5ee; font-size: 12px; font-weight: 800; letter-spacing: .18em; text-transform: uppercase; }
                h1 { margin: 12px auto 0; max-width: 460px; font-size: clamp(28px, 5vw, 36px); line-height: 1.12; letter-spacing: .01em; }
                .description { margin: 18px auto 0; max-width: 420px; color: #cbd5e1; font-size: 15px; line-height: 1.6; }
                a { display: inline-flex; margin-top: 26px; min-height: 44px; align-items: center; justify-content: center; padding: 11px 22px; border-radius: 6px; background: #dc3545; color: #fff; font-weight: 700; text-decoration: none; }
                a:hover { background: #c82333; }
                a:focus-visible { outline: 3px solid #65d5ee; outline-offset: 3px; }
                @media (max-width: 480px) { body { padding: 24px 16px; } .brand { width: min(176px, 58vw); margin-bottom: 20px; } .panel { border-radius: 14px; } }
            </style>
        </head>
        <body>
            <main>
                <img class="brand" src="https://tomschooloflife.com/wp-content/uploads/2020/04/THE-TOM-WOODS-SCHOOL-OF-LIFE-logo.svg" alt="<?php esc_attr_e('The Tom Woods School of Life', 'tomschooloflife-plugin'); ?>" width="190" height="51">
                <section class="panel" aria-labelledby="library-error-title">
                    <p class="eyebrow"><?php echo esc_html($copy['eyebrow']); ?></p>
                    <h1 id="library-error-title"><?php echo esc_html($copy['title']); ?></h1>
                    <p class="description"><?php echo esc_html($copy['description']); ?></p>
                    <a href="<?php echo $home_url; ?>"><?php esc_html_e('Return to TSOL', 'tomschooloflife-plugin'); ?></a>
                </section>
            </main>
        </body>
        </html>
        <?php
        exit;
    }

    private function rest_response($body, $status = 200, $headers = array()) {
        return new WP_REST_Response($body, $status, array_merge($this->security_headers(), $headers));
    }

    private function rest_error($code, $message, $status, $headers = array()) {
        return $this->rest_response(array('error' => sanitize_key($code), 'error_description' => $message), $status, $headers);
    }

    private function logged_rest_error($endpoint, $code, $status, $started_at, $user_id = 0, $client_id = '') {
        TSOL_Library_Auth_Logger::event($endpoint, array(
            'outcome' => 'failure',
            'error' => $code,
            'endpoint' => $endpoint,
            'user_id' => $user_id,
            'client_id' => $client_id,
            'duration_ms' => $this->duration_ms($started_at),
        ));
        $messages = array(
            'not_ready' => __('Library authentication is not configured.', 'tomschooloflife-plugin'),
            'invalid_client' => __('Client authentication failed.', 'tomschooloflife-plugin'),
            'invalid_request' => __('The request is invalid.', 'tomschooloflife-plugin'),
            'unsupported_grant_type' => __('Only the authorization_code grant is supported.', 'tomschooloflife-plugin'),
            'invalid_grant' => __('The authorization code is invalid, expired, or already used.', 'tomschooloflife-plugin'),
            'invalid_token' => __('The bearer token is invalid or expired.', 'tomschooloflife-plugin'),
            'unknown_user' => __('The WordPress user does not exist.', 'tomschooloflife-plugin'),
            'unknown_content' => __('The requested content does not exist.', 'tomschooloflife-plugin'),
            'unknown_catalogue_content' => __('The requested Library catalogue record does not exist.', 'tomschooloflife-plugin'),
            'memberpress_unavailable' => __('MemberPress is unavailable.', 'tomschooloflife-plugin'),
            'server_error' => __('The authentication service encountered an error.', 'tomschooloflife-plugin'),
        );
        $headers = $code === 'invalid_token' ? array('WWW-Authenticate' => 'Bearer') : array();
        return $this->rest_error($code, $messages[$code] ?? $messages['server_error'], $status, $headers);
    }

    private function rate_error(WP_Error $error, $endpoint) {
        $data = $error->get_error_data();
        $retry_after = is_array($data) && isset($data['retry_after']) ? max(1, absint($data['retry_after'])) : MINUTE_IN_SECONDS;
        TSOL_Library_Auth_Logger::event($endpoint, array('outcome' => 'failure', 'error' => 'rate_limited', 'endpoint' => $endpoint));
        return $this->rest_error('rate_limited', $error->get_error_message(), 429, array('Retry-After' => (string) $retry_after));
    }

    private function security_headers() {
        return array(
            'Cache-Control' => 'no-store, private',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Vary' => 'Authorization, X-TSOL-Client-ID',
        );
    }

    private function browser_security_headers() {
        nocache_headers();
        if (!headers_sent()) {
            header('Cache-Control: no-store, private, max-age=0, must-revalidate', true);
            header('Pragma: no-cache', true);
            header('Referrer-Policy: no-referrer', true);
            header('X-Content-Type-Options: nosniff', true);
            header('Vary: Cookie', true);
        }
    }

    private function require_server_request(WP_REST_Request $request) {
        return (string) $request->get_header('origin') === '' ? true : new WP_Error('invalid_request');
    }

    private function request_string(WP_REST_Request $request, $key) {
        $value = $request->get_param($key);
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function query_string($key) {
        if (!isset($_GET[$key]) || !is_scalar($_GET[$key])) {
            return '';
        }
        return trim((string) wp_unslash($_GET[$key]));
    }

    private const LOGOUT_EVENT = 'auth.logout';
    private const LOGOUT_VERSION = '1';

    private function logout_subject($jti, $user_id) {
        return hash_hmac('sha256', "logout-subject\n" . (string) $jti . "\n" . (string) $user_id, TSOL_Library_Auth_Settings::client_secret());
    }

    private function logout_signature($message) {
        $canonical = implode("\n", array(
            $message['version'],
            $message['event'],
            $message['audience'],
            $message['jti'],
            $message['issued_at'],
            $message['return_to'],
            $message['subject'],
        ));
        return 'sha256=' . hash_hmac('sha256', $canonical, TSOL_Library_Auth_Settings::client_secret());
    }

    private function logout_message($audience, $jti, $timestamp, $return_to, $user_id) {
        $message = array(
            'version' => self::LOGOUT_VERSION,
            'event' => self::LOGOUT_EVENT,
            'audience' => (string) $audience,
            'jti' => strtolower((string) $jti),
            'issued_at' => (string) $timestamp,
            'return_to' => (string) $return_to,
            'subject' => $this->logout_subject($jti, $user_id),
        );
        $message['signature'] = $this->logout_signature($message);
        return $message;
    }

    private function valid_logout_message($message, $user_id) {
        if (
            !$this->has_exact_logout_query()
            || self::LOGOUT_VERSION !== $message['version']
            || self::LOGOUT_EVENT !== $message['event']
            || 'tsol-wordpress' !== $message['audience']
            || !wp_is_uuid($message['jti'], 4)
            || !preg_match('/^(?:0|[1-9][0-9]{0,10})$/', $message['issued_at'])
            || abs(time() - (int) $message['issued_at']) > self::LOGOUT_TTL
            || home_url('/') !== $message['return_to']
            || !$user_id
            || !preg_match('/^[a-f0-9]{64}$/', $message['subject'])
            || !hash_equals($this->logout_subject($message['jti'], $user_id), $message['subject'])
            || !preg_match('/^sha256=[a-f0-9]{64}$/', $message['signature'])
            || strlen(TSOL_Library_Auth_Settings::client_secret()) < 32
        ) {
            return false;
        }
        return hash_equals($this->logout_signature($message), $message['signature']);
    }

    private function has_exact_logout_query() {
        $expected = array('action', 'audience', 'event', 'issued_at', 'jti', 'return_to', 'signature', 'subject', 'version');
        $actual = array_keys($_GET);
        sort($actual);
        if ($actual !== $expected) {
            return false;
        }

        $query = isset($_SERVER['QUERY_STRING']) ? (string) wp_unslash($_SERVER['QUERY_STRING']) : '';
        $keys = array();
        foreach (explode('&', $query) as $part) {
            $key = rawurldecode((string) strtok($part, '='));
            if ($key === '' || isset($keys[$key])) {
                return false;
            }
            $keys[$key] = true;
        }
        $raw_keys = array_keys($keys);
        sort($raw_keys);
        return $raw_keys === $expected;
    }

    private function safe_home_return($return_to) {
        $fallback = home_url('/');
        $validated = wp_validate_redirect((string) $return_to, $fallback);
        $home_parts = wp_parse_url($fallback);
        $return_parts = wp_parse_url($validated);
        if (!is_array($home_parts) || !is_array($return_parts)) {
            return $fallback;
        }
        $home_origin = strtolower(($home_parts['scheme'] ?? '') . '://' . ($home_parts['host'] ?? '') . ':' . ($home_parts['port'] ?? ''));
        $return_origin = strtolower(($return_parts['scheme'] ?? '') . '://' . ($return_parts['host'] ?? '') . ':' . ($return_parts['port'] ?? ''));
        return hash_equals($home_origin, $return_origin) ? $validated : $fallback;
    }

    private function safe_navigation_url($value) {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        if (strpos($value, '/') === 0 && strpos($value, '//') !== 0) {
            $value = home_url($value);
        }

        $parts = wp_parse_url($value);
        if (
            !is_array($parts)
            || empty($parts['host'])
            || !isset($parts['scheme'])
            || !in_array(strtolower((string) $parts['scheme']), array('http', 'https'), true)
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return '';
        }

        return esc_url_raw($value, array('http', 'https'));
    }

    private function is_library_navigation_url($value) {
        $app_url = TSOL_Library_Auth_Settings::app_url();
        if ($app_url === '') {
            return false;
        }

        return untrailingslashit((string) $value) === untrailingslashit($app_url);
    }

    private function elementor_header_menu_id() {
        if (!class_exists('ElementorPro\\Modules\\ThemeBuilder\\Module')) {
            return 0;
        }

        try {
            $module = \ElementorPro\Modules\ThemeBuilder\Module::instance();
            $documents = $module->get_conditions_manager()->get_documents_for_location('header');
        } catch (Throwable $error) {
            return 0;
        }

        foreach ((array) $documents as $document) {
            if (!is_object($document) || !method_exists($document, 'get_elements_data')) {
                continue;
            }

            $menu_reference = $this->elementor_nav_menu_reference($document->get_elements_data());
            if ($menu_reference === '') {
                continue;
            }

            $menu = wp_get_nav_menu_object($menu_reference);
            if ($menu instanceof WP_Term) {
                return absint($menu->term_id);
            }
        }

        return 0;
    }

    private function elementor_nav_menu_reference($elements) {
        if (!is_array($elements)) {
            return '';
        }

        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }

            if (
                isset($element['widgetType'], $element['settings']['menu'])
                && $element['widgetType'] === 'nav-menu'
                && is_scalar($element['settings']['menu'])
            ) {
                $menu_reference = sanitize_text_field((string) $element['settings']['menu']);
                if ($menu_reference !== '') {
                    return $menu_reference;
                }
            }

            $nested_reference = $this->elementor_nav_menu_reference($element['elements'] ?? array());
            if ($nested_reference !== '') {
                return $nested_reference;
            }
        }

        return '';
    }

    private function is_browser_request() {
        if ((defined('REST_REQUEST') && REST_REQUEST) || (defined('WP_CLI') && WP_CLI) || wp_doing_ajax() || wp_doing_cron()) {
            return false;
        }
        return true;
    }

    private function duration_ms($started_at) {
        return (int) round((microtime(true) - (float) $started_at) * 1000);
    }
}
