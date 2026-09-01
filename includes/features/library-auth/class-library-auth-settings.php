<?php
/**
 * Settings for the TSOL-only Library authentication bridge.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Auth_Settings {

    public const ENABLED_OPTION = 'tsol_library_auth_enabled';
    public const APP_URL_OPTION = 'tsol_library_auth_app_url';
    public const CLIENT_ID_OPTION = 'tsol_library_auth_client_id';
    public const CLIENT_SECRET_OPTION = 'tsol_library_auth_client_secret';
    public const CATALOGUE_WEBHOOK_SECRET_OPTION = 'tsol_library_catalogue_webhook_secret';

    public function init() {
        add_action('admin_init', array($this, 'register'));
    }

    public function register() {
        register_setting('tsol_library_auth', self::ENABLED_OPTION, array(
            'sanitize_callback' => static function ($value) {
                return $value === '1' ? '1' : '0';
            },
            'default' => '0',
        ));
        register_setting('tsol_library_auth', self::APP_URL_OPTION, array(
            'sanitize_callback' => array($this, 'sanitize_app_url'),
            'default' => '',
        ));
        register_setting('tsol_library_auth', self::CLIENT_ID_OPTION, array(
            'sanitize_callback' => array($this, 'sanitize_client_id'),
            'default' => MemberLibrary_Brand::client_id_default(),
        ));
        register_setting('tsol_library_auth', self::CLIENT_SECRET_OPTION, array(
            'sanitize_callback' => array($this, 'sanitize_secret'),
            'default' => '',
        ));
        register_setting('tsol_library_auth', self::CATALOGUE_WEBHOOK_SECRET_OPTION, array(
            'sanitize_callback' => array($this, 'sanitize_catalogue_webhook_secret'),
            'default' => '',
        ));
    }

    public function sanitize_app_url($value) {
        $url = self::normalize_app_url($value);
        if ((string) $value !== '' && $url === '') {
            add_settings_error(self::APP_URL_OPTION, 'invalid_url', __('Enter an HTTPS origin with no path, query, fragment, or credentials. HTTP is allowed only for localhost.', 'member-library'));
            return (string) get_option(self::APP_URL_OPTION, '');
        }

        return $url;
    }

    public function sanitize_client_id($value) {
        $value = trim((string) wp_unslash($value));
        if ($value !== '' && !preg_match('/^[A-Za-z0-9._-]{3,128}$/', $value)) {
            add_settings_error(self::CLIENT_ID_OPTION, 'invalid_client_id', __('Use 3–128 letters, numbers, dots, underscores, or hyphens.', 'member-library'));
            return (string) get_option(self::CLIENT_ID_OPTION, MemberLibrary_Brand::client_id_default());
        }

        return $value;
    }

    public function sanitize_secret($value) {
        if (defined('TSOL_LIBRARY_CLIENT_SECRET')) {
            return (string) get_option(self::CLIENT_SECRET_OPTION, '');
        }

        $value = trim((string) wp_unslash($value));
        if ($value === '') {
            return (string) get_option(self::CLIENT_SECRET_OPTION, '');
        }
        if (strlen($value) < 32) {
            add_settings_error(self::CLIENT_SECRET_OPTION, 'short_secret', __('The Library client secret must be at least 32 characters.', 'member-library'));
            return (string) get_option(self::CLIENT_SECRET_OPTION, '');
        }
        return $value;
    }

    public function sanitize_catalogue_webhook_secret($value) {
        if (defined('TSOL_LIBRARY_CATALOGUE_WEBHOOK_SECRET')) {
            return (string) get_option(self::CATALOGUE_WEBHOOK_SECRET_OPTION, '');
        }

        $value = trim((string) wp_unslash($value));
        if ($value === '') {
            return (string) get_option(self::CATALOGUE_WEBHOOK_SECRET_OPTION, '');
        }
        if (strlen($value) < 32) {
            add_settings_error(self::CATALOGUE_WEBHOOK_SECRET_OPTION, 'short_secret', __('The catalogue synchronization secret must be at least 32 characters.', 'member-library'));
            return (string) get_option(self::CATALOGUE_WEBHOOK_SECRET_OPTION, '');
        }
        if (self::client_secret() !== '' && hash_equals(self::client_secret(), $value)) {
            add_settings_error(self::CATALOGUE_WEBHOOK_SECRET_OPTION, 'reused_secret', __('The catalogue synchronization secret must be different from the Library client secret.', 'member-library'));
            return (string) get_option(self::CATALOGUE_WEBHOOK_SECRET_OPTION, '');
        }
        return $value;
    }

    public function render($embedded = false) {
        if (!current_user_can('manage_options')) {
            return;
        }

        $secret_from_constant = defined('TSOL_LIBRARY_CLIENT_SECRET');
        $secret_is_present = self::client_secret() !== '';
        $secret_is_valid = strlen(self::client_secret()) >= 32;
        $catalogue_secret_from_constant = defined('TSOL_LIBRARY_CATALOGUE_WEBHOOK_SECRET');
        $catalogue_secret_is_present = self::catalogue_webhook_secret() !== '';
        $catalogue_secret_is_valid = strlen(self::catalogue_webhook_secret()) >= 32
            && ($secret_is_valid ? !hash_equals(self::client_secret(), self::catalogue_webhook_secret()) : true);
        $reason = self::readiness_error();
        $memberpress_ready = class_exists('MeprUser') && class_exists('MeprRule');
        ?>
        <?php if (!$embedded) : ?><div class="wrap"><?php endif; ?>
            <?php if ($embedded) : ?>
                <h2><?php esc_html_e('Library Authentication', 'member-library'); ?></h2>
            <?php else : ?>
                <h1><?php esc_html_e('Library Authentication', 'member-library'); ?></h1>
            <?php endif; ?>
            <p><?php esc_html_e('WordPress authenticates every Library user. MemberPress rules authorize each protected course or lesson, while WordPress administrator permissions continue to authorize admins.', 'member-library'); ?></p>
            <?php settings_errors(); ?>
            <?php if ($reason === '') : ?>
                <div class="notice notice-success inline"><p><?php esc_html_e('The Library authentication bridge is ready.', 'member-library'); ?></p></div>
            <?php else : ?>
                <div class="notice notice-warning inline"><p><?php echo esc_html($reason); ?></p></div>
            <?php endif; ?>
            <?php if ($reason === '' && !$memberpress_ready) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e('WordPress sign-in is ready, but MemberPress is unavailable. Users can authenticate, while protected content will remain unavailable until MemberPress returns.', 'member-library'); ?></p></div>
            <?php endif; ?>
            <?php if (!$secret_from_constant) : ?>
                <div class="notice notice-info inline"><p><?php esc_html_e('This site stores the Library client secret as a write-only WordPress setting because no server-managed TSOL_LIBRARY_CLIENT_SECRET constant is available. The value is never displayed after saving. A server-managed constant remains the preferred option when hosting access becomes available.', 'member-library'); ?></p></div>
            <?php endif; ?>
            <form method="post" action="options.php">
                <?php settings_fields('tsol_library_auth'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><?php esc_html_e('Enable bridge', 'member-library'); ?></th>
                        <td><label><input type="checkbox" name="<?php echo esc_attr(self::ENABLED_OPTION); ?>" value="1" <?php checked(self::enabled()); ?>> <?php esc_html_e('Allow authenticated WordPress users to sign in to the Library', 'member-library'); ?></label></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tsol-library-app-url"><?php esc_html_e('Library URL', 'member-library'); ?></label></th>
                        <td><input id="tsol-library-app-url" class="regular-text code" type="url" name="<?php echo esc_attr(self::APP_URL_OPTION); ?>" value="<?php echo esc_attr((string) get_option(self::APP_URL_OPTION, '')); ?>" placeholder="http://localhost:3000"><p class="description"><?php esc_html_e('Only the exact Better Auth callback on this origin is accepted. TSOL_LIBRARY_APP_URL overrides this setting.', 'member-library'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tsol-library-client-id"><?php esc_html_e('Client ID', 'member-library'); ?></label></th>
                        <td><input id="tsol-library-client-id" class="regular-text code" type="text" name="<?php echo esc_attr(self::CLIENT_ID_OPTION); ?>" value="<?php echo esc_attr((string) get_option(self::CLIENT_ID_OPTION, MemberLibrary_Brand::client_id_default())); ?>"><p class="description"><?php esc_html_e('Use a different client ID per environment. TSOL_LIBRARY_CLIENT_ID overrides this setting.', 'member-library'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tsol-library-client-secret"><?php esc_html_e('Client secret', 'member-library'); ?></label></th>
                        <td>
                            <p style="margin: 0 0 10px;">
                                <?php if ($secret_is_valid) : ?>
                                    <span data-library-secret-status="configured" style="align-items: center; background: #edfaef; border: 1px solid #8fd19e; border-radius: 999px; color: #166534; display: inline-flex; font-weight: 600; gap: 5px; padding: 4px 10px;">
                                        <span class="dashicons dashicons-yes-alt" aria-hidden="true" style="font-size: 17px; height: 17px; width: 17px;"></span>
                                        <?php esc_html_e('Configured', 'member-library'); ?>
                                    </span>
                                <?php elseif ($secret_is_present) : ?>
                                    <span data-library-secret-status="invalid" style="align-items: center; background: #fcf0f1; border: 1px solid #d63638; border-radius: 999px; color: #8a2424; display: inline-flex; font-weight: 600; gap: 5px; padding: 4px 10px;">
                                        <span class="dashicons dashicons-warning" aria-hidden="true" style="font-size: 17px; height: 17px; width: 17px;"></span>
                                        <?php esc_html_e('Needs replacement', 'member-library'); ?>
                                    </span>
                                <?php else : ?>
                                    <span data-library-secret-status="missing" style="align-items: center; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 999px; color: #50575e; display: inline-flex; font-weight: 600; gap: 5px; padding: 4px 10px;">
                                        <span class="dashicons dashicons-marker" aria-hidden="true" style="font-size: 17px; height: 17px; width: 17px;"></span>
                                        <?php esc_html_e('Not configured', 'member-library'); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="description" style="margin-left: 6px;">
                                    <?php echo esc_html($secret_from_constant ? __('Provided by the server environment', 'member-library') : ($secret_is_present ? __('Saved in WordPress; the value is never displayed', 'member-library') : __('No secret has been provided yet', 'member-library'))); ?>
                                </span>
                            </p>
                            <input id="tsol-library-client-secret" class="regular-text code" type="password" autocomplete="new-password" name="<?php echo esc_attr(self::CLIENT_SECRET_OPTION); ?>" value="" <?php disabled($secret_from_constant); ?> placeholder="<?php echo esc_attr($secret_is_present ? __('Enter a new secret to replace the current one', 'member-library') : __('At least 32 characters', 'member-library')); ?>">
                            <p class="description"><?php echo esc_html($secret_from_constant ? __('This field is disabled because the server environment controls the secret.', 'member-library') : __('Leave this field blank to keep the current secret. Entering a value replaces it after you save; the saved value is never displayed.', 'member-library')); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="tsol-library-catalogue-webhook-secret"><?php esc_html_e('Catalogue synchronization secret', 'member-library'); ?></label></th>
                        <td>
                            <p style="margin: 0 0 10px;">
                                <?php if ($catalogue_secret_is_valid) : ?>
                                    <span data-library-catalogue-secret-status="configured" style="align-items: center; background: #edfaef; border: 1px solid #8fd19e; border-radius: 999px; color: #166534; display: inline-flex; font-weight: 600; gap: 5px; padding: 4px 10px;">
                                        <span class="dashicons dashicons-yes-alt" aria-hidden="true" style="font-size: 17px; height: 17px; width: 17px;"></span>
                                        <?php esc_html_e('Configured', 'member-library'); ?>
                                    </span>
                                <?php elseif ($catalogue_secret_is_present) : ?>
                                    <span data-library-catalogue-secret-status="invalid" style="align-items: center; background: #fcf0f1; border: 1px solid #d63638; border-radius: 999px; color: #8a2424; display: inline-flex; font-weight: 600; gap: 5px; padding: 4px 10px;">
                                        <span class="dashicons dashicons-warning" aria-hidden="true" style="font-size: 17px; height: 17px; width: 17px;"></span>
                                        <?php esc_html_e('Needs replacement', 'member-library'); ?>
                                    </span>
                                <?php else : ?>
                                    <span data-library-catalogue-secret-status="missing" style="align-items: center; background: #f6f7f7; border: 1px solid #c3c4c7; border-radius: 999px; color: #50575e; display: inline-flex; font-weight: 600; gap: 5px; padding: 4px 10px;">
                                        <span class="dashicons dashicons-marker" aria-hidden="true" style="font-size: 17px; height: 17px; width: 17px;"></span>
                                        <?php esc_html_e('Not configured', 'member-library'); ?>
                                    </span>
                                <?php endif; ?>
                                <span class="description" style="margin-left: 6px;">
                                    <?php echo esc_html($catalogue_secret_from_constant ? __('Provided by the server environment', 'member-library') : ($catalogue_secret_is_present ? __('Saved in WordPress; the value is never displayed', 'member-library') : __('No secret has been provided yet', 'member-library'))); ?>
                                </span>
                            </p>
                            <input id="tsol-library-catalogue-webhook-secret" class="regular-text code" type="password" autocomplete="new-password" name="<?php echo esc_attr(self::CATALOGUE_WEBHOOK_SECRET_OPTION); ?>" value="" <?php disabled($catalogue_secret_from_constant); ?> placeholder="<?php echo esc_attr($catalogue_secret_is_present ? __('Enter a new secret to replace the current one', 'member-library') : __('At least 32 characters', 'member-library')); ?>">
                            <p class="description"><?php echo esc_html($catalogue_secret_from_constant ? __('This field is disabled because the server environment controls the secret.', 'member-library') : __('Paste the exact TSOL_LIBRARY_CATALOGUE_WEBHOOK_SECRET from the Library app. Leave blank to keep the current value; the saved value is never displayed.', 'member-library')); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        <?php if (!$embedded) : ?></div><?php endif; ?>
        <?php
    }

    public static function enabled() {
        return get_option(self::ENABLED_OPTION, '0') === '1';
    }

    public static function app_url() {
        $value = defined('TSOL_LIBRARY_APP_URL') ? TSOL_LIBRARY_APP_URL : get_option(self::APP_URL_OPTION, '');
        return self::normalize_app_url($value);
    }

    public static function callback_url() {
        $app_url = self::app_url();
        return $app_url === '' ? '' : $app_url . '/api/auth/oauth2/callback/tsol-wordpress';
    }

    public static function client_id() {
        $value = defined('TSOL_LIBRARY_CLIENT_ID') ? TSOL_LIBRARY_CLIENT_ID : get_option(self::CLIENT_ID_OPTION, MemberLibrary_Brand::client_id_default());
        return trim((string) $value);
    }

    public static function client_secret() {
        if (defined('TSOL_LIBRARY_CLIENT_SECRET')) {
            return trim((string) TSOL_LIBRARY_CLIENT_SECRET);
        }
        return trim((string) get_option(self::CLIENT_SECRET_OPTION, ''));
    }

    public static function catalogue_webhook_secret() {
        if (defined('TSOL_LIBRARY_CATALOGUE_WEBHOOK_SECRET')) {
            return trim((string) TSOL_LIBRARY_CATALOGUE_WEBHOOK_SECRET);
        }
        return trim((string) get_option(self::CATALOGUE_WEBHOOK_SECRET_OPTION, ''));
    }

    public static function configured() {
        return self::readiness_error() === '';
    }

    public static function readiness_error() {
        if (!self::enabled()) {
            return __('The Library authentication bridge is disabled.', 'member-library');
        }
        if (self::app_url() === '') {
            return __('A valid Library URL is required.', 'member-library');
        }
        if (!preg_match('/^[A-Za-z0-9._-]{3,128}$/', self::client_id())) {
            return __('A valid environment-specific client ID is required.', 'member-library');
        }
        if (strlen(self::client_secret()) < 32) {
            return __('A client secret of at least 32 characters is required.', 'member-library');
        }
        return '';
    }

    private static function normalize_app_url($value) {
        $url = untrailingslashit(esc_url_raw(trim((string) $value)));
        if ($url === '') {
            return '';
        }

        $parts = wp_parse_url($url);
        if (!is_array($parts)) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $is_local = in_array($host, array('localhost', '127.0.0.1', '::1'), true);
        $valid_port = !isset($parts['port']) || ((int) $parts['port'] >= 1 && (int) $parts['port'] <= 65535);
        $has_extra = isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || (!empty($parts['path']) && $parts['path'] !== '/');
        if (!in_array($scheme, array('http', 'https'), true) || $host === '' || !$valid_port || $has_extra || ($scheme !== 'https' && !$is_local)) {
            return '';
        }

        return $url;
    }

}
