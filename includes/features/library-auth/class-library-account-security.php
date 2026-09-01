<?php
/**
 * MemberPress account security controls for standalone Library sessions.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Account_Security {

    const ACCOUNT_ACTION = 'security';
    const POST_ACTION = 'tsol_library_force_logout';
    const NONCE_ACTION = 'tsol_library_force_logout';
    const STATUS_QUERY = 'tsol_library_sessions';

    public static function register_hooks() {
        // Run before third-party account extensions that mutate the shared nav hook.
        add_action('mepr_account_nav', array(__CLASS__, 'render_navigation'), 5);
        add_action('mepr_account_nav_content', array(__CLASS__, 'render_content'), 10, 2);
        add_filter('mepr_custom_account_nav_title', array(__CLASS__, 'account_title'), 10, 2);
        add_filter('mepr_view_get_string_/account/nav', array(__CLASS__, 'ensure_navigation_link'), 10, 2);
        add_filter('mepr_view_get_string_/readylaunch/account/nav', array(__CLASS__, 'ensure_navigation_link'), 10, 2);
        add_action('admin_post_' . self::POST_ACTION, array(__CLASS__, 'handle_forced_logout'));
    }

    public static function render_navigation() {
        $container_tag = class_exists('MeprReadyLaunchCtrl')
            && MeprReadyLaunchCtrl::template_enabled('account')
            ? 'span'
            : 'li';
        echo self::navigation_item($container_tag); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fully escaped by navigation_item().
    }

    public static function ensure_navigation_link($view, $vars) {
        $view = (string) $view;
        if (false !== strpos($view, 'id="mepr-account-security"')) {
            return $view;
        }

        $container_tag = false !== stripos($view, '<ul') ? 'li' : 'span';
        $account_url = is_array($vars) && isset($vars['account_url']) && is_scalar($vars['account_url'])
            ? (string) $vars['account_url']
            : null;
        $item = self::navigation_item($container_tag, $account_url);

        if (preg_match('/<(?:li|span)\b[^>]*>\s*<a\b[^>]*\bid=(["\'])mepr-account-logout\1/i', $view, $matches, PREG_OFFSET_CAPTURE)) {
            $offset = $matches[0][1];
            return substr($view, 0, $offset) . $item . substr($view, $offset);
        }

        $nav_end = strripos($view, '</nav>');
        if (false !== $nav_end) {
            return substr($view, 0, $nav_end) . $item . substr($view, $nav_end);
        }

        return $view;
    }

    private static function navigation_item($container_tag, $account_url = null) {
        $container_tag = in_array($container_tag, array('li', 'span'), true) ? $container_tag : 'li';
        $account_url = $account_url ?: self::account_url();
        $url = add_query_arg('action', self::ACCOUNT_ACTION, $account_url);
        $request_action = isset($_REQUEST['action']) && is_scalar($_REQUEST['action'])
            ? sanitize_key(wp_unslash($_REQUEST['action']))
            : 'home';
        $classes = 'mepr-nav-item mepr-' . self::ACCOUNT_ACTION;
        if (self::ACCOUNT_ACTION === $request_action) {
            $classes .= ' mepr-active-nav-tab';
        }

        return sprintf(
            '<%1$s class="%2$s"><a href="%3$s" id="mepr-account-security">%4$s</a></%1$s>',
            $container_tag,
            esc_attr($classes),
            esc_url($url),
            esc_html__('Security', 'libertyclassroom-library')
        );
    }

    public static function render_content($action) {
        if (self::ACCOUNT_ACTION !== sanitize_key((string) $action)) {
            return;
        }

        $status = isset($_GET[self::STATUS_QUERY]) && is_scalar($_GET[self::STATUS_QUERY])
            ? sanitize_key(wp_unslash($_GET[self::STATUS_QUERY]))
            : '';
        ?>
        <div class="mp_wrapper">
            <?php if ('requested' === $status) : ?>
                <div class="mepr_updated" role="status">
                    <?php esc_html_e('Your Library sessions are being signed out on every device. This may take a moment.', 'libertyclassroom-library'); ?>
                </div>
            <?php elseif ('unavailable' === $status) : ?>
                <div class="mepr_error" role="alert">
                    <?php esc_html_e('We could not request the Library sign-out. Please try again.', 'libertyclassroom-library'); ?>
                </div>
            <?php endif; ?>

            <section aria-labelledby="tsol-library-session-security-title">
                <h3 id="tsol-library-session-security-title">
                    <?php esc_html_e('Library sessions', 'libertyclassroom-library'); ?>
                </h3>
                <p>
                    <?php esc_html_e('Use this if you have lost a device, used a shared computer, or no longer recognize a Library session.', 'libertyclassroom-library'); ?>
                </p>
                <p>
                    <?php esc_html_e('This signs your account out of the standalone Library on every browser and device. Your WordPress, MemberPress, or Access login may remain active.', 'libertyclassroom-library'); ?>
                </p>

                <form class="mepr-account-form mepr-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="<?php echo esc_attr(self::POST_ACTION); ?>" />
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>

                    <div class="mp-form-row mepr-field-required">
                        <label for="tsol-library-force-logout-confirmation">
                            <input
                                id="tsol-library-force-logout-confirmation"
                                name="confirmation"
                                type="checkbox"
                                value="yes"
                                required
                            />
                            <?php esc_html_e('I understand that every active Library session, including this browser, will be signed out.', 'libertyclassroom-library'); ?>
                        </label>
                    </div>

                    <div class="mepr_spacer">&nbsp;</div>
                    <button type="submit" class="mepr-submit">
                        <?php esc_html_e('Sign out of the Library on all devices', 'libertyclassroom-library'); ?>
                    </button>
                </form>
            </section>
        </div>
        <?php
    }

    public static function account_title($title, $action) {
        return self::ACCOUNT_ACTION === sanitize_key((string) $action)
            ? __('Security', 'libertyclassroom-library')
            : $title;
    }

    public static function handle_forced_logout() {
        $method = isset($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : '';
        $user_id = get_current_user_id();
        $confirmed = isset($_POST['confirmation'])
            && is_scalar($_POST['confirmation'])
            && 'yes' === sanitize_key(wp_unslash($_POST['confirmation']));
        $nonce = isset($_POST['_wpnonce']) && is_scalar($_POST['_wpnonce'])
            ? sanitize_text_field(wp_unslash($_POST['_wpnonce']))
            : '';
        $decision = self::request_decision(
            $method,
            $user_id,
            $confirmed,
            false !== wp_verify_nonce($nonce, self::NONCE_ACTION)
        );

        if ('method_not_allowed' === $decision) {
            wp_die(esc_html__('This action requires a POST request.', 'libertyclassroom-library'), '', array('response' => 405));
        }
        if ('authentication_required' === $decision) {
            auth_redirect();
            exit;
        }
        if ('invalid_request' === $decision) {
            wp_die(esc_html__('The security request could not be verified.', 'libertyclassroom-library'), '', array('response' => 403));
        }
        if ('confirmation_required' === $decision) {
            wp_die(esc_html__('Please confirm that every Library session should be signed out.', 'libertyclassroom-library'), '', array('response' => 400));
        }

        $queued = self::queue_forced_logout($user_id);
        $status = $queued ? 'requested' : 'unavailable';
        wp_safe_redirect(self::security_url($status));
        exit;
    }

    private static function request_decision($method, $user_id, $confirmed, $nonce_valid) {
        if ('POST' !== $method) {
            return 'method_not_allowed';
        }
        if (absint($user_id) <= 0) {
            return 'authentication_required';
        }
        if (!$nonce_valid) {
            return 'invalid_request';
        }
        if (!$confirmed) {
            return 'confirmation_required';
        }
        return 'accepted';
    }

    private static function queue_forced_logout($user_id) {
        return TSOL_Library_Auth_Revocation::queue($user_id, 'user.sessions_forced_logout');
    }

    private static function security_url($status) {
        return add_query_arg(array(
            'action' => self::ACCOUNT_ACTION,
            self::STATUS_QUERY => sanitize_key((string) $status),
        ), self::account_url());
    }

    private static function account_url() {
        if (class_exists('MeprOptions')) {
            $options = MeprOptions::fetch();
            if (is_object($options) && is_callable(array($options, 'account_page_url'))) {
                $url = (string) $options->account_page_url();
                if ('' !== $url) {
                    return $url;
                }
            }
        }

        return home_url('/account/');
    }
}
