<?php
/**
 * MemberPress account security and Library-session revocation contract.
 *
 * Run: php -d memory_limit=512M /usr/local/bin/wp eval-file
 * tests/library-account-security-contract.php --skip-themes
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
$capture = static function ($callback) {
    ob_start();
    $callback();
    return (string) ob_get_clean();
};

$assert(class_exists('TSOL_Library_Account_Security'), 'The MemberPress Library security integration is not loaded.');
$assert(5 === has_action('mepr_account_nav', array('TSOL_Library_Account_Security', 'render_navigation')), 'The Security tab is not registered before third-party MemberPress navigation mutations.');
$assert(has_action('mepr_account_nav_content', array('TSOL_Library_Account_Security', 'render_content')) !== false, 'The Security tab content is not registered.');
$assert(has_filter('mepr_custom_account_nav_title', array('TSOL_Library_Account_Security', 'account_title')) !== false, 'The Security tab browser title is not registered.');
$assert(has_filter('mepr_view_get_string_/account/nav', array('TSOL_Library_Account_Security', 'ensure_navigation_link')) !== false, 'The legacy account-template compatibility filter is not registered.');
$assert(has_filter('mepr_view_get_string_/readylaunch/account/nav', array('TSOL_Library_Account_Security', 'ensure_navigation_link')) !== false, 'The ReadyLaunch account-template compatibility filter is not registered.');
$assert(has_action('admin_post_tsol_library_force_logout', array('TSOL_Library_Account_Security', 'handle_forced_logout')) !== false, 'The authenticated all-device sign-out action is not registered.');
$assert(has_action('admin_post_nopriv_tsol_library_force_logout') === false, 'Anonymous visitors can reach the all-device sign-out action.');

$user_id = (int) get_users(array('number' => 1, 'fields' => 'ID'))[0];
$previous_user_id = get_current_user_id();
$previous_get = $_GET;
$previous_request = $_REQUEST;
wp_set_current_user($user_id);

try {
    $_REQUEST['action'] = 'security';
    $navigation = $capture(static function () {
        TSOL_Library_Account_Security::render_navigation();
    });
    $assert(strpos($navigation, 'id="mepr-account-security"') !== false, 'The Security navigation item has no stable identifier.');
    $assert(strpos($navigation, 'action=security') !== false, 'The Security navigation item does not target the MemberPress Security action.');
    $assert(strpos($navigation, 'mepr-active-nav-tab') !== false, 'The Security navigation item is not marked active on its page.');
    $assert(strpos($navigation, '>Security<') !== false, 'The Security navigation item has an unexpected label.');

    $theme_override = '<div id="mepr-account-nav"><span class="mepr-nav-item"><a href="/account/?action=home">Home</a></span><span class="mepr-nav-item"><a href="/logout" id="mepr-account-logout">Logout</a></span></div>';
    $repaired_override = TSOL_Library_Account_Security::ensure_navigation_link($theme_override, array('account_url' => '/account/'));
    $assert(1 === substr_count($repaired_override, 'id="mepr-account-security"'), 'The compatibility filter did not restore exactly one Security item to the child-theme override.');
    $assert(strpos($repaired_override, 'id="mepr-account-security"') < strpos($repaired_override, 'id="mepr-account-logout"'), 'The compatibility Security item was not inserted before Logout.');
    $assert($repaired_override === TSOL_Library_Account_Security::ensure_navigation_link($repaired_override, array('account_url' => '/account/')), 'The compatibility filter duplicated an existing Security item.');

    $standard_template = '<nav><ul><li><a id="mepr-account-logout" href="/logout">Logout</a></li></ul></nav>';
    $repaired_standard = TSOL_Library_Account_Security::ensure_navigation_link($standard_template, array('account_url' => '/account/'));
    $assert(strpos($repaired_standard, '<li class="mepr-nav-item') !== false, 'The compatibility filter did not preserve list semantics for the standard MemberPress template.');

    $_GET = array();
    $unrelated_content = $capture(static function () {
        TSOL_Library_Account_Security::render_content('subscriptions');
    });
    $assert('' === $unrelated_content, 'The Library security panel rendered for an unrelated MemberPress tab.');

    $security_content = $capture(static function () {
        TSOL_Library_Account_Security::render_content('security');
    });
    $assert(strpos($security_content, 'method="post"') !== false, 'The all-device sign-out form is not a POST form.');
    $assert(strpos($security_content, 'admin-post.php') !== false, 'The all-device sign-out form does not use the authenticated WordPress handler.');
    $assert(strpos($security_content, 'name="action" value="tsol_library_force_logout"') !== false, 'The all-device sign-out form targets an unexpected action.');
    $assert(strpos($security_content, 'name="_wpnonce"') !== false, 'The all-device sign-out form is missing CSRF protection.');
    $assert(strpos($security_content, 'name="confirmation"') !== false && strpos($security_content, 'required') !== false, 'The all-device sign-out form is missing explicit confirmation.');
    $assert(strpos($security_content, 'Sign out of the Library on all devices') !== false, 'The all-device action does not name its Library-only scope.');
    $assert(strpos($security_content, 'WordPress, MemberPress, or Access login may remain active') !== false, 'The security panel does not explain the upstream-session boundary.');

    $_GET[TSOL_Library_Account_Security::STATUS_QUERY] = 'requested';
    $requested_content = $capture(static function () {
        TSOL_Library_Account_Security::render_content('security');
    });
    $assert(strpos($requested_content, 'role="status"') !== false, 'A queued revocation has no accessible status message.');

    $_GET[TSOL_Library_Account_Security::STATUS_QUERY] = 'unexpected';
    $unexpected_content = $capture(static function () {
        TSOL_Library_Account_Security::render_content('security');
    });
    $assert(strpos($unexpected_content, 'role="status"') === false && strpos($unexpected_content, 'role="alert"') === false, 'An unknown status value produced a security notice.');

    $assert('Security' === TSOL_Library_Account_Security::account_title('Account', 'security'), 'The custom Security tab title is incorrect.');
    $assert('Account' === TSOL_Library_Account_Security::account_title('Account', 'payments'), 'The Security title filter changed another MemberPress tab.');

    $security_url_method = new ReflectionMethod(TSOL_Library_Account_Security::class, 'security_url');
    $security_url_method->setAccessible(true);
    $security_url = $security_url_method->invoke(null, 'requested');
    $assert('/account/' === wp_parse_url($security_url, PHP_URL_PATH), 'The postback redirect did not use the canonical MemberPress Account page.');
    parse_str((string) wp_parse_url($security_url, PHP_URL_QUERY), $security_query);
    $assert('security' === ($security_query['action'] ?? null) && 'requested' === ($security_query[TSOL_Library_Account_Security::STATUS_QUERY] ?? null), 'The postback redirect lost its Security action or status.');

    $decision_method = new ReflectionMethod(TSOL_Library_Account_Security::class, 'request_decision');
    $decision_method->setAccessible(true);
    foreach (array('GET', 'POST') as $method) {
        foreach (array(0, $user_id) as $candidate_user_id) {
            foreach (array(false, true) as $confirmed) {
                foreach (array(false, true) as $nonce_valid) {
                    if ('POST' !== $method) {
                        $expected = 'method_not_allowed';
                    } elseif ($candidate_user_id <= 0) {
                        $expected = 'authentication_required';
                    } elseif (!$nonce_valid) {
                        $expected = 'invalid_request';
                    } elseif (!$confirmed) {
                        $expected = 'confirmation_required';
                    } else {
                        $expected = 'accepted';
                    }
                    $actual = $decision_method->invoke(null, $method, $candidate_user_id, $confirmed, $nonce_valid);
                    $assert($expected === $actual, sprintf('Unexpected request decision for %s/user:%d/confirm:%d/nonce:%d.', $method, $candidate_user_id, $confirmed, $nonce_valid));
                }
            }
        }
    }

    TSOL_Library_Auth_Revocation::maybe_install();
    global $wpdb;
    $table = TSOL_Library_Auth_Revocation::table();
    $existing_schedule = wp_next_scheduled(TSOL_Library_Auth_Revocation::CRON_HOOK);
    $before_jtis = $wpdb->get_col($wpdb->prepare(
        'SELECT jti FROM ' . $table . ' WHERE user_id = %d AND event = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
        $user_id,
        'user.sessions_forced_logout'
    ));
    $queue_method = new ReflectionMethod(TSOL_Library_Account_Security::class, 'queue_forced_logout');
    $queue_method->setAccessible(true);
    $queued = $queue_method->invoke(null, $user_id);
    $after_jtis = $wpdb->get_col($wpdb->prepare(
        'SELECT jti FROM ' . $table . ' WHERE user_id = %d AND event = %s', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Static plugin-owned table name.
        $user_id,
        'user.sessions_forced_logout'
    ));
    $new_jtis = array_values(array_diff($after_jtis, $before_jtis));
    $assert(true === $queued && 1 === count($new_jtis), 'The account security action did not queue exactly one forced Library logout event.');
    foreach ($new_jtis as $jti) {
        $wpdb->delete($table, array('jti' => $jti), array('%s'));
    }
    wp_clear_scheduled_hook(TSOL_Library_Auth_Revocation::CRON_HOOK);
    if (false !== $existing_schedule) {
        wp_schedule_single_event(max(time() + 1, (int) $existing_schedule), TSOL_Library_Auth_Revocation::CRON_HOOK);
    }
} finally {
    $_GET = $previous_get;
    $_REQUEST = $previous_request;
    wp_set_current_user($previous_user_id);
}

if (!empty($failures)) {
    foreach ($failures as $failure) {
        WP_CLI::warning($failure);
    }
    WP_CLI::error('Liberty Classroom Library account security contract failed with ' . count($failures) . ' issue(s).');
}

WP_CLI::success('Liberty Classroom Library account security contract passed.');
