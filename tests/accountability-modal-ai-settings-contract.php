<?php
/**
 * Read-only contract for Accountability Modal Gemini settings ownership.
 *
 * Run with:
 * php -d memory_limit=512M /usr/local/bin/wp eval-file ../plugin/tests/accountability-modal-ai-settings-contract.php --skip-themes
 */

if (!defined('ABSPATH')) {
    exit;
}

$assert = static function ($condition, $message) {
    if (!$condition) {
        WP_CLI::error($message);
    }
};

$rules = TSOL_Accountability_Modal_Settings::get_display_rules();
$display_submission = $rules;
unset($display_submission['ai_matching_enabled'], $display_submission['fit_threshold']);
$sanitized_display = TSOL_Accountability_Modal_Settings::sanitize_display_rules($display_submission);

$assert(
    $sanitized_display['ai_matching_enabled'] === $rules['ai_matching_enabled'],
    'Saving Display Rules without AI fields must preserve the AI matching toggle.'
);
$assert(
    (float) $sanitized_display['fit_threshold'] === (float) $rules['fit_threshold'],
    'Saving Display Rules without AI fields must preserve the strong-fit threshold.'
);
$assert(
    TSOL_Accountability_Modal_Settings::sanitize_gemini_model('not-a-model') === TSOL_Accountability_Modal_Settings::DEFAULT_GEMINI_MODEL,
    'Unknown Gemini models must fall back to the Accountability Modal default.'
);
$assert(
    (new TSOL_Gemini_Client())->is_configured() === ('' !== TSOL_Accountability_Modal_Settings::get_gemini_api_key()),
    'The Gemini client must read configuration from Accountability Modal settings.'
);

global $title;
$previous_title = isset($title) ? $title : null;
$title = 'TSOL Dashboard';
ob_start();
(new TSOL_Site_Admin_Settings())->display_page();
$site_settings_html = (string) ob_get_clean();
$title = $previous_title;

$assert(false === strpos($site_settings_html, 'Gemini API Key'), 'The generic TSOL settings page must not render the Gemini API key control.');
$assert(false === strpos($site_settings_html, 'Gemini Model'), 'The generic TSOL settings page must not render the Gemini model control.');

$admin = new TSOL_Accountability_Modal_Admin();
$render_ai_tab = new ReflectionMethod($admin, 'render_ai_tab');
$render_ai_tab->setAccessible(true);
ob_start();
$render_ai_tab->invoke($admin);
$ai_settings_html = (string) ob_get_clean();

$assert(false !== strpos($ai_settings_html, 'Accountability AI matching'), 'The Accountability Modal must render a dedicated AI settings panel.');
$assert(false !== strpos($ai_settings_html, 'It is not used by TSOL Library.'), 'The AI settings panel must state its feature boundary.');
$assert(false !== strpos($ai_settings_html, 'name="' . TSOL_Accountability_Modal_Settings::GEMINI_API_KEY_OPTION . '"'), 'The AI settings panel must retain the existing key option name.');
$assert(false !== strpos($ai_settings_html, 'type="password"'), 'The Gemini key control must remain a password field.');
$assert(false !== strpos($ai_settings_html, 'value=""'), 'The Gemini key control must never render the stored value.');
$assert(false !== strpos($ai_settings_html, 'tsol_accountability_save_ai_settings'), 'The AI settings form must use its dedicated protected save action.');

WP_CLI::success('Accountability Modal AI settings contract passed without changing stored options or credentials.');
