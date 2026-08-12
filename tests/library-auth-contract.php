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

    $auth = new TSOL_Library_Auth();
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
