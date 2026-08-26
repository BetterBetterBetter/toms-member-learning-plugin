<?php
/**
 * WP-CLI contract checks for the TSOL Library authentication bridge.
 *
 * Run: wp eval-file tests/library-auth-contract.php --skip-themes
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract check through WP-CLI.');
}

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$assert(class_exists('TSOL_Library_Auth'), 'Library auth class is not loaded.');
$assert(class_exists('TSOL_Library_Auth_Repository'), 'Authorization-code repository is not loaded.');
$assert(class_exists('TSOL_Library_Auth_Entitlements'), 'MemberPress content authority is not loaded.');
$assert(has_action('admin_post_tsol_library_authorize') !== false, 'Authorization action is not registered.');
$assert(has_action('admin_post_tsol_library_logout') !== false, 'Signed logout action is not registered.');
$assert(wp_next_scheduled('tsol_library_auth_cleanup') !== false, 'Authorization-code cleanup is not scheduled.');

if (!defined('TSOL_LIBRARY_CLIENT_SECRET')) {
    $previous_client_secret = get_option(TSOL_Library_Auth_Settings::CLIENT_SECRET_OPTION, null);
    $database_client_secret = 'library-auth-contract-database-secret-value';
    update_option(TSOL_Library_Auth_Settings::CLIENT_SECRET_OPTION, $database_client_secret, false);
    $assert(
        TSOL_Library_Auth_Settings::client_secret() === $database_client_secret,
        'The write-only WordPress client-secret fallback is unavailable.'
    );
    $administrator_ids = get_users(array(
        'role' => 'administrator',
        'fields' => 'ID',
        'number' => 1,
    ));
    $assert(!empty($administrator_ids), 'An administrator is required to verify the Library authentication settings UI.');
    if (!empty($administrator_ids)) {
        $previous_user_id = get_current_user_id();
        wp_set_current_user((int) $administrator_ids[0]);
        ob_start();
        (new TSOL_Library_Auth_Settings())->render();
        $settings_html = (string) ob_get_clean();
        $assert(strpos($settings_html, $database_client_secret) === false, 'The saved client secret was rendered into the settings page.');
        $secret_input = array();
        $assert(
            preg_match('/<input id="tsol-library-client-secret"[^>]*>/', $settings_html, $secret_input) === 1,
            'The Library client-secret input is missing.'
        );
        $assert(
            empty($secret_input) || strpos($secret_input[0], 'disabled') === false,
            'The write-only Library client-secret input is disabled without a host-managed constant.'
        );
        wp_set_current_user($previous_user_id);
    }
    if (null === $previous_client_secret) {
        delete_option(TSOL_Library_Auth_Settings::CLIENT_SECRET_OPTION);
    } else {
        update_option(TSOL_Library_Auth_Settings::CLIENT_SECRET_OPTION, $previous_client_secret, false);
    }
}

if (!defined('TSOL_LIBRARY_CATALOGUE_WEBHOOK_SECRET')) {
    $previous_catalogue_secret = get_option(TSOL_Library_Auth_Settings::CATALOGUE_WEBHOOK_SECRET_OPTION, null);
    $database_catalogue_secret = 'library-catalogue-contract-database-secret-value';
    update_option(TSOL_Library_Auth_Settings::CATALOGUE_WEBHOOK_SECRET_OPTION, $database_catalogue_secret, false);
    $assert(
        TSOL_Library_Auth_Settings::catalogue_webhook_secret() === $database_catalogue_secret,
        'The write-only WordPress catalogue-secret fallback is unavailable.'
    );
    $administrator_ids = get_users(array('role' => 'administrator', 'fields' => 'ID', 'number' => 1));
    if (!empty($administrator_ids)) {
        $previous_user_id = get_current_user_id();
        wp_set_current_user((int) $administrator_ids[0]);
        ob_start();
        (new TSOL_Library_Auth_Settings())->render();
        $settings_html = (string) ob_get_clean();
        $assert(strpos($settings_html, $database_catalogue_secret) === false, 'The saved catalogue synchronization secret was rendered into the settings page.');
        $assert(strpos($settings_html, 'id="tsol-library-catalogue-webhook-secret"') !== false, 'The catalogue synchronization secret input is missing.');
        wp_set_current_user($previous_user_id);
    }
    if (null === $previous_catalogue_secret) {
        delete_option(TSOL_Library_Auth_Settings::CATALOGUE_WEBHOOK_SECRET_OPTION);
    } else {
        update_option(TSOL_Library_Auth_Settings::CATALOGUE_WEBHOOK_SECRET_OPTION, $previous_catalogue_secret, false);
    }
}

$cache_exclusions = apply_filters('rocket_cache_reject_uri', array(), true);
$assert(in_array('/wp-admin/admin-post\\.php', $cache_exclusions, true), 'Library browser authentication is not excluded from WP Rocket.');
$library_rest_exclusion = '/(index\\.php/)?' . preg_quote(rest_get_url_prefix(), '/') . '/tsol-library/v1(/.*|$)';
$assert(in_array($library_rest_exclusion, $cache_exclusions, true), 'Library REST authentication is not excluded from WP Rocket.');

$routes = rest_get_server()->get_routes();
foreach (array(
    '/tsol-library/v1/token',
    '/tsol-library/v1/userinfo',
    '/tsol-library/v1/content-access/(?P<user_id>\d+)/(?P<post_id>\d+)',
    '/tsol-library/v1/content-access/(?P<user_id>\d+)',
    '/tsol-library/v1/catalogue',
    '/tsol-library/v1/catalogue/(?P<post_id>\d+)',
    '/tsol-library/v1/changes',
    '/tsol-library/v1/readiness',
    '/tsol-library/v1/footer-navigation',
    '/tsol-library/v1/header-navigation',
) as $route) {
    $assert(isset($routes[$route]), 'Missing REST route: ' . $route);
}

$registered_menus = get_registered_nav_menus();
$assert(isset($registered_menus['tsol_library_footer']), 'The TSOL Library Footer menu location is not registered.');

global $wpdb;
$table = TSOL_Library_Auth_Repository::table();
$assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table, 'Authorization-code table is missing.');
$messages_table = TSOL_Library_Auth_Repository::messages_table();
$assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $messages_table)) === $messages_table, 'Authentication-message replay table is missing.');
$rate_limits_table = TSOL_Library_Auth_Repository::rate_limits_table();
$assert($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $rate_limits_table)) === $rate_limits_table, 'Atomic authentication rate-limit table is missing.');

$rate_key = hash('sha256', 'library-auth-contract-' . wp_generate_uuid4());
$rate_now = time();
$first_rate = TSOL_Library_Auth_Repository::increment_rate_limit($rate_key, $rate_now, MINUTE_IN_SECONDS);
$second_rate = TSOL_Library_Auth_Repository::increment_rate_limit($rate_key, $rate_now, MINUTE_IN_SECONDS);
$assert(is_array($first_rate) && (int) ($first_rate['count'] ?? 0) === 1, 'The atomic rate limiter did not create its first request count.');
$assert(is_array($second_rate) && (int) ($second_rate['count'] ?? 0) === 2, 'The atomic rate limiter did not increment its request count.');
$wpdb->delete($rate_limits_table, array('rate_key' => $rate_key), array('%s'));

$auth = new TSOL_Library_Auth();
$authorize_request_method = new ReflectionMethod(TSOL_Library_Auth::class, 'has_exact_authorize_request');
$authorize_request_method->setAccessible(true);
$previous_get = $_GET;
$previous_query_string = $_SERVER['QUERY_STRING'] ?? null;
$previous_request_uri = $_SERVER['REQUEST_URI'] ?? null;
$authorize_query = array(
    'action' => 'tsol_library_authorize',
    'client_id' => 'tsol-library',
    'code_challenge' => str_repeat('a', 43),
    'code_challenge_method' => 'S256',
    'redirect_uri' => 'https://library.example.test/api/auth/oauth2/callback/tsol-wordpress',
    'response_type' => 'code',
    'scope' => '',
    'state' => str_repeat('b', 32),
);
$_GET = $authorize_query;
$_SERVER['QUERY_STRING'] = http_build_query($authorize_query, '', '&', PHP_QUERY_RFC3986);
$_SERVER['REQUEST_URI'] = (string) wp_parse_url(admin_url('admin-post.php'), PHP_URL_PATH) . '?' . $_SERVER['QUERY_STRING'];
$assert(true === $authorize_request_method->invoke($auth), 'The exact authorization request shape was rejected.');
$_GET['unexpected'] = '1';
$_SERVER['QUERY_STRING'] .= '&unexpected=1';
$assert(false === $authorize_request_method->invoke($auth), 'An unexpected authorization query parameter was accepted.');
unset($_GET['unexpected']);
$_SERVER['QUERY_STRING'] = http_build_query($authorize_query, '', '&', PHP_QUERY_RFC3986) . '&state=duplicate';
$assert(false === $authorize_request_method->invoke($auth), 'A duplicate authorization query parameter was accepted.');
$_GET = $previous_get;
if (null === $previous_query_string) {
    unset($_SERVER['QUERY_STRING']);
} else {
    $_SERVER['QUERY_STRING'] = $previous_query_string;
}
if (null === $previous_request_uri) {
    unset($_SERVER['REQUEST_URI']);
} else {
    $_SERVER['REQUEST_URI'] = $previous_request_uri;
}

if (TSOL_Library_Auth_Settings::configured()) {
    $navigation_request = new WP_REST_Request('GET', '/tsol-library/v1/footer-navigation');
    $navigation_request->set_header('x-tsol-client-id', TSOL_Library_Auth_Settings::client_id());
    $navigation_request->set_header('x-tsol-client-secret', TSOL_Library_Auth_Settings::client_secret());
    $navigation_response = rest_do_request($navigation_request);
    $navigation_data = $navigation_response->get_data();
    $assert($navigation_response->get_status() === 200, 'The footer navigation endpoint did not accept valid Library credentials.');
    $assert(is_array($navigation_data) && isset($navigation_data['items']) && is_array($navigation_data['items']), 'The footer navigation endpoint returned an invalid payload.');

    $menu_locations = get_nav_menu_locations();
    if (empty($menu_locations['tsol_library_footer'])) {
        $assert(empty($navigation_data['items']), 'Footer navigation must be empty when no WordPress menu is assigned.');
    }

    $header_navigation_request = new WP_REST_Request('GET', '/tsol-library/v1/header-navigation');
    $header_navigation_request->set_header('x-tsol-client-id', TSOL_Library_Auth_Settings::client_id());
    $header_navigation_request->set_header('x-tsol-client-secret', TSOL_Library_Auth_Settings::client_secret());
    $header_navigation_response = rest_do_request($header_navigation_request);
    $header_navigation_data = $header_navigation_response->get_data();
    $assert($header_navigation_response->get_status() === 200, 'The header navigation endpoint did not accept valid Library credentials.');
    $assert(is_array($header_navigation_data) && isset($header_navigation_data['items']) && is_array($header_navigation_data['items']), 'The header navigation endpoint returned an invalid payload.');
    foreach ((array) ($header_navigation_data['items'] ?? array()) as $header_navigation_item) {
        $assert(
            is_array($header_navigation_item)
                && isset($header_navigation_item['id'], $header_navigation_item['parent_id'], $header_navigation_item['label'], $header_navigation_item['url'], $header_navigation_item['order']),
            'The header navigation endpoint returned an invalid item.'
        );
    }

    $user_id = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->users} ORDER BY ID ASC LIMIT 1");
    $assert($user_id > 0, 'A WordPress user is required for the authentication contract checks.');

    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $code = TSOL_Library_Auth_Repository::create(
        $user_id,
        TSOL_Library_Auth_Settings::client_id(),
        TSOL_Library_Auth_Settings::callback_url(),
        $challenge
    );
    $assert(!is_wp_error($code), 'Could not create a test authorization code.');
    if (!is_wp_error($code)) {
        $first = TSOL_Library_Auth_Repository::consume($code, TSOL_Library_Auth_Settings::client_id(), TSOL_Library_Auth_Settings::callback_url(), $verifier);
        $replay = TSOL_Library_Auth_Repository::consume($code, TSOL_Library_Auth_Settings::client_id(), TSOL_Library_Auth_Settings::callback_url(), $verifier);
        $assert($first === $user_id, 'A valid authorization code could not be consumed.');
        $assert(is_wp_error($replay) && $replay->get_error_code() === 'invalid_grant', 'An authorization code replay was not rejected.');
    }

    $logout_message_method = new ReflectionMethod(TSOL_Library_Auth::class, 'logout_message');
    $logout_message_method->setAccessible(true);
    $logout_jti = wp_generate_uuid4();
    $logout_timestamp = time();
    $logout_message = $logout_message_method->invoke(
        $auth,
        'tsol-wordpress',
        $logout_jti,
        $logout_timestamp,
        home_url('/'),
        $user_id
    );
    $expected_subject = hash_hmac(
        'sha256',
        "logout-subject\n{$logout_jti}\n{$user_id}",
        TSOL_Library_Auth_Settings::client_secret()
    );
    $expected_signature = 'sha256=' . hash_hmac('sha256', implode("\n", array(
        '1',
        'auth.logout',
        'tsol-wordpress',
        $logout_jti,
        (string) $logout_timestamp,
        home_url('/'),
        $expected_subject,
    )), TSOL_Library_Auth_Settings::client_secret());
    $assert($expected_subject === ($logout_message['subject'] ?? ''), 'The logout subject does not match the cross-application contract.');
    $assert($expected_signature === ($logout_message['signature'] ?? ''), 'The logout signature does not match the cross-application contract.');

    $previous_get = $_GET;
    $previous_query_string = $_SERVER['QUERY_STRING'] ?? null;
    $_GET = array_merge(array('action' => 'tsol_library_logout'), $logout_message);
    $_SERVER['QUERY_STRING'] = http_build_query($_GET, '', '&', PHP_QUERY_RFC3986);
    $valid_logout_method = new ReflectionMethod(TSOL_Library_Auth::class, 'valid_logout_message');
    $valid_logout_method->setAccessible(true);
    $assert(true === $valid_logout_method->invoke($auth, $logout_message, $user_id), 'A canonical signed Library logout message was rejected.');
    $_SERVER['QUERY_STRING'] .= '&jti=' . rawurlencode($logout_jti);
    $assert(false === $valid_logout_method->invoke($auth, $logout_message, $user_id), 'A duplicate logout query parameter was accepted.');
    $_GET = $previous_get;
    if (null === $previous_query_string) {
        unset($_SERVER['QUERY_STRING']);
    } else {
        $_SERVER['QUERY_STRING'] = $previous_query_string;
    }
} else {
    $failures[] = 'Bridge is not ready: ' . TSOL_Library_Auth_Settings::readiness_error();
}

if (!empty($failures)) {
    WP_CLI::error(implode("\n", $failures));
}

WP_CLI::success('TSOL Library authentication contract checks passed.');
