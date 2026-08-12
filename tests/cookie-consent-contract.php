<?php
/**
 * WP-CLI contract checks for cookie consent persistence and privacy signals.
 *
 * Run: wp eval-file wp-content/plugins/tomschooloflife-plugin/tests/cookie-consent-contract.php --skip-themes
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

$assert(class_exists('TSOL_Cookie_Consent_Settings'), 'Cookie consent settings class is not loaded.');
$assert(class_exists('TSOL_Cookie_Consent'), 'Cookie consent frontend class is not loaded.');

$regex_script = <<<'JS'
var path = window.location.pathname.replace(/\/+$/, '/');
JS;
$sanitized_regex_settings = TSOL_Cookie_Consent_Settings::sanitize_settings(array(
    'marketing_inline_scripts' => $regex_script,
    'marketing_inline_script_names' => 'Path normalization',
));
$assert(
    $sanitized_regex_settings['marketing_inline_scripts'] === $regex_script,
    'The Settings API sanitizer removed a JavaScript regular-expression escape.'
);

$original_cookie_exists = array_key_exists(TSOL_Cookie_Consent_Settings::COOKIE_NAME, $_COOKIE);
$original_cookie = $original_cookie_exists ? $_COOKIE[TSOL_Cookie_Consent_Settings::COOKIE_NAME] : null;
$original_gpc_exists = array_key_exists('HTTP_SEC_GPC', $_SERVER);
$original_gpc = $original_gpc_exists ? $_SERVER['HTTP_SEC_GPC'] : null;
$settings = wp_parse_args(array(
    'consent_version' => 'contract-test',
    'cookie_lifetime_days' => 30,
    'respect_gpc' => '1',
    'analytics_enabled' => '1',
    'marketing_enabled' => '1',
), TSOL_Cookie_Consent_Settings::defaults());

$set_cookie = static function ($overrides = array()) use ($settings) {
    $payload = wp_parse_args($overrides, array(
        'version' => $settings['consent_version'],
        'necessary' => true,
        'analytics' => true,
        'marketing' => true,
        'timestamp' => gmdate('c'),
        'source' => 'contract_test',
    ));

    $_COOKIE[TSOL_Cookie_Consent_Settings::COOKIE_NAME] = rawurlencode(wp_json_encode($payload));
};

unset($_SERVER['HTTP_SEC_GPC']);
$set_cookie();
$consent = TSOL_Cookie_Consent_Settings::get_consent_from_cookie($settings);
$assert(is_array($consent), 'A current, well-formed consent cookie was rejected.');
$assert(!empty($consent['analytics']) && !empty($consent['marketing']), 'Granted categories were not preserved.');

$set_cookie(array('timestamp' => gmdate('c', time() - (31 * DAY_IN_SECONDS))));
$assert(TSOL_Cookie_Consent_Settings::get_consent_from_cookie($settings) === null, 'An expired consent cookie was accepted.');

$set_cookie(array('timestamp' => gmdate('c', time() + (10 * MINUTE_IN_SECONDS))));
$assert(TSOL_Cookie_Consent_Settings::get_consent_from_cookie($settings) === null, 'A consent cookie with an invalid future timestamp was accepted.');

$set_cookie(array('necessary' => false));
$assert(TSOL_Cookie_Consent_Settings::get_consent_from_cookie($settings) === null, 'A consent cookie without necessary=true was accepted.');

$set_cookie();
$_SERVER['HTTP_SEC_GPC'] = '1';
$consent = TSOL_Cookie_Consent_Settings::get_consent_from_cookie($settings);
$assert(is_array($consent) && !empty($consent['analytics']), 'GPC incorrectly disabled analytics consent.');
$assert(is_array($consent) && empty($consent['marketing']), 'GPC did not override stored marketing consent.');

$gpc_ignored_settings = $settings;
$gpc_ignored_settings['respect_gpc'] = '0';
$consent = TSOL_Cookie_Consent_Settings::get_consent_from_cookie($gpc_ignored_settings);
$assert(is_array($consent) && !empty($consent['marketing']), 'GPC was applied when the setting was disabled.');

unset($_SERVER['HTTP_SEC_GPC']);
$analytics_disabled_settings = $settings;
$analytics_disabled_settings['analytics_enabled'] = '0';
$consent = TSOL_Cookie_Consent_Settings::get_consent_from_cookie($analytics_disabled_settings);
$assert(is_array($consent) && empty($consent['analytics']), 'A disabled category remained granted from stored consent.');

$cleanup_filter = static function ($payload) {
    $payload['marketing']['names'][] = 'valid_cookie-name';
    $payload['marketing']['names'][] = 'invalid cookie name';

    return $payload;
};
add_filter('tsol_site_cookie_consent_cleanup', $cleanup_filter);
$cleanup = TSOL_Cookie_Consent_Settings::get_cookie_cleanup_payload();
remove_filter('tsol_site_cookie_consent_cleanup', $cleanup_filter);
$assert(in_array('valid_cookie-name', $cleanup['marketing']['names'], true), 'A valid filtered cleanup cookie name was removed.');
$assert(!in_array('invalid cookie name', $cleanup['marketing']['names'], true), 'An invalid cleanup cookie name was accepted.');

$frontend = new TSOL_Cookie_Consent();
$wpcode_snippet = new class {
    public function get_id() {
        return 102804;
    }
};
$gated_wpcode = $frontend->gate_wpcode_marketing_snippets(
    '<script type="text/javascript">window.affiliateData = {};</script><script async src="https://script.tapfiliate.com/tapfiliate.js"></script>',
    $wpcode_snippet
);
$assert(substr_count($gated_wpcode, 'type="text/plain"') === 2, 'WPCode Tapfiliate script tags were not made inert.');
$assert(substr_count($gated_wpcode, 'data-tsol-consent-category="marketing"') === 2, 'WPCode Tapfiliate scripts were not assigned to Marketing consent.');

$hfcm_markup = '<!-- HFCM by 99 Robots - Snippet # 4: G Tag --><script src="https://www.googletagmanager.com/gtm.js"></script><!-- /end HFCM by 99 Robots -->'
    . '<!-- HFCM by 99 Robots - Snippet # 14: HearUsOut --><script src="https://player.vimeo.com/api/player.js"></script><script>window.videoReady = true;</script><!-- /end HFCM by 99 Robots -->'
    . '<!-- HFCM by 99 Robots - Snippet # 24: Support LiveChat --><script>window.RocketChat = {};</script><!-- /end HFCM by 99 Robots -->'
    . '<!-- HFCM by 99 Robots - Snippet # 57: Kissmetrics Script --><script type="text/javascript">window._kmq = [];</script><!-- /end HFCM by 99 Robots -->'
    . '<!-- HFCM by 99 Robots - Snippet # 69: Add cookie and redirect --><script>document.cookie = "hasVisited=true";</script><!-- /end HFCM by 99 Robots -->';
$gated_hfcm = $frontend->gate_hfcm_snippet_output($hfcm_markup);
$assert(strpos($gated_hfcm, 'data-tsol-consent-category="marketing" data-tsol-consent-vendor="hfcm-4"') !== false, 'HFCM Google tracking was not assigned to Marketing consent.');
$assert(substr_count($gated_hfcm, 'data-tsol-consent-category="marketing" data-tsol-consent-vendor="hfcm-14"') === 2, 'HFCM Vimeo scripts were not assigned to Marketing consent.');
$assert(strpos($gated_hfcm, 'data-tsol-consent-category="marketing" data-tsol-consent-vendor="hfcm-24"') !== false, 'HFCM RocketChat was not assigned to Marketing consent.');
$assert(strpos($gated_hfcm, 'data-tsol-consent-category="analytics" data-tsol-consent-vendor="hfcm-57"') !== false, 'HFCM Kissmetrics tracking was not assigned to Analytics consent.');
$assert(strpos($gated_hfcm, 'data-tsol-consent-vendor="hfcm-69"') === false, 'The functional HFCM redirect cookie was incorrectly assigned to optional consent.');

$vimeo_markup = '<iframe class="video" src="https://player.vimeo.com/video/123?autoplay=0" allowfullscreen></iframe>';
$gated_vimeo = $frontend->gate_third_party_embeds($vimeo_markup);
$assert(strpos($gated_vimeo, 'data-tsol-consent-embed') !== false, 'The Vimeo iframe did not receive a consent wrapper.');
$assert(strpos($gated_vimeo, 'src="about:blank"') !== false, 'The Vimeo iframe retained a loadable source before consent.');
$assert(strpos($gated_vimeo, 'data-tsol-consent-src="https://player.vimeo.com/video/123?autoplay=0"') !== false, 'The Vimeo source was not preserved for post-consent activation.');
$assert($frontend->gate_third_party_embeds($gated_vimeo) === $gated_vimeo, 'An already-gated iframe was gated more than once.');

$first_party_markup = '<iframe src="https://tomschooloflife.test/library"></iframe>';
$assert($frontend->gate_third_party_embeds($first_party_markup) === $first_party_markup, 'A first-party iframe was incorrectly gated.');

if (class_exists('NNR_HFCM')) {
    $assert(has_action('wp_head', array('NNR_HFCM', 'hfcm_header_scripts')) === false, 'The direct HFCM header renderer still bypasses the consent bridge.');
    $assert(has_action('wp_footer', array('NNR_HFCM', 'hfcm_footer_scripts')) === false, 'The direct HFCM footer renderer still bypasses the consent bridge.');
}

wp_register_script('tapfiliate-js', 'https://script.tapfiliate.com/tapfiliate.js', array(), null, false);
wp_enqueue_script('tapfiliate-js');
wp_add_inline_script('tapfiliate-js', 'window.tapfiliateContractTest = true;');
$frontend->capture_consent_managed_vendor_scripts();
$assert(!wp_script_is('tapfiliate-js', 'enqueued'), 'Tapfiliate remained directly enqueued before marketing consent.');
ob_start();
$frontend->render_consent_managed_vendor_scripts();
$tapfiliate_markup = ob_get_clean();
$assert(strpos($tapfiliate_markup, 'type="text/plain"') !== false, 'Tapfiliate was not rendered as an inert script.');
$assert(strpos($tapfiliate_markup, 'data-tsol-consent-category="marketing"') !== false, 'Tapfiliate was not assigned to Marketing consent.');
$assert(strpos($tapfiliate_markup, 'window.tapfiliateContractTest = true;') !== false, 'Tapfiliate inline conversion data was not preserved.');
wp_deregister_script('tapfiliate-js');

if ($original_cookie_exists) {
    $_COOKIE[TSOL_Cookie_Consent_Settings::COOKIE_NAME] = $original_cookie;
} else {
    unset($_COOKIE[TSOL_Cookie_Consent_Settings::COOKIE_NAME]);
}

if ($original_gpc_exists) {
    $_SERVER['HTTP_SEC_GPC'] = $original_gpc;
} else {
    unset($_SERVER['HTTP_SEC_GPC']);
}

if (!empty($failures)) {
    WP_CLI::error(implode("\n", $failures));
}

WP_CLI::success('Cookie consent contract checks passed.');
