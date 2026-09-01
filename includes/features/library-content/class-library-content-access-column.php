<?php
/**
 * Compact, read-only MemberPress access presentation for WordPress list tables.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MemberLibrary_Content_Access_Column {

    const COLUMN = 'mepr-access';

    private $summary_cache = array();
    private $condition_cache = array();
    private $templates = array();

    public function init() {
        foreach (MemberLibrary_Content_Model::post_types() as $post_type) {
            add_filter('manage_edit-' . $post_type . '_columns', array($this, 'add_column'));
            add_action('manage_' . $post_type . '_posts_custom_column', array($this, 'render_column'), 10, 2);
        }
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('current_screen', array($this, 'suppress_memberpress_renderer_on_library_lists'), 20);
        // Render before WordPress prints footer scripts so the dialog exists
        // when the scoped controller initializes.
        add_action('admin_footer', array($this, 'render_modal'));
    }

    /**
     * MemberPress prints its own verbose Access cell on every eligible post
     * list. Replace it only for the two TSOL-owned Library lists; native
     * MemberPress, Page, and other WordPress screens remain untouched.
     */
    public function suppress_memberpress_renderer_on_library_lists($screen) {
        if (!self::is_access_list_screen($screen)) {
            return;
        }
        remove_action('manage_posts_custom_column', 'MeprAppCtrl::custom_columns', 100);
        remove_action('manage_pages_custom_column', 'MeprAppCtrl::custom_columns', 100);
    }

    public function add_column($columns) {
        $columns[self::COLUMN] = __('MemberPress access', 'member-library');
        return $columns;
    }

    public function enqueue_assets($hook) {
        if ('edit.php' !== $hook || !self::is_access_list_screen(get_current_screen())) {
            return;
        }

        wp_enqueue_style(
            'tsol-library-content-access-column',
            MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/access-column.css',
            array(),
            MEMBER_LIBRARY_PLUGIN_VERSION
        );
        wp_enqueue_script(
            'tsol-library-content-access-column',
            MEMBER_LIBRARY_PLUGIN_URL . 'assets/features/library-content/access-column.js',
            array(),
            MEMBER_LIBRARY_PLUGIN_VERSION,
            true
        );
    }

    public function render_column($column, $post_id) {
        if (self::COLUMN !== $column) {
            return;
        }

        $summary = $this->access_summary($post_id);
        if (is_wp_error($summary)) {
            echo '<span class="tsol-content-access tsol-content-access--unavailable">';
            esc_html_e('Unavailable', 'member-library');
            echo '</span>';
            return;
        }

        if (!empty($summary['public'])) {
            echo '<span class="tsol-content-access tsol-content-access--public">';
            esc_html_e('All signed-in users', 'member-library');
            echo '</span>';
            return;
        }

        $this->templates[$summary['template_key']] = $summary;
        $content_title = get_the_title($post_id);
        $aria_label = sprintf(
            /* translators: 1: access summary, 2: content title. */
            __('View %1$s granting access to %2$s', 'member-library'),
            $summary['label'],
            $content_title
        );
        ?>
        <button
            type="button"
            class="button-link tsol-content-access tsol-content-access--protected"
            data-tsol-content-access
            data-template-id="<?php echo esc_attr('tsol-content-access-template-' . $summary['template_key']); ?>"
            data-content-title="<?php echo esc_attr($content_title); ?>"
            aria-haspopup="dialog"
            aria-controls="tsol-content-access-dialog"
            aria-label="<?php echo esc_attr($aria_label); ?>"
            title="<?php echo esc_attr__('View effective MemberPress access', 'member-library'); ?>"
        ><?php echo esc_html($summary['label']); ?></button>
        <?php
    }

    /**
     * Return the effective MemberPress conditions without changing them.
     */
    public function access_summary($post_id) {
        $post = get_post((int) $post_id);
        if (!$post instanceof WP_Post) {
            return new WP_Error('invalid_content', __('The content could not be resolved.', 'member-library'));
        }
        if (!class_exists('MeprRule')) {
            return new WP_Error('memberpress_unavailable', __('MemberPress access rules are unavailable.', 'member-library'));
        }

        $authorization_post_id = (int) get_post_meta($post->ID, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, true);
        $authorization_post = $authorization_post_id > 0 ? get_post($authorization_post_id) : $post;
        if (!$authorization_post instanceof WP_Post) {
            return new WP_Error('invalid_authorization_content', __('The MemberPress authorization source could not be resolved.', 'member-library'));
        }
        $rules = MeprRule::get_rules($authorization_post);
        $rule_ids = array_values(array_unique(array_filter(array_map(static function ($rule) {
            return isset($rule->ID) ? (int) $rule->ID : 0;
        }, $rules))));
        sort($rule_ids, SORT_NUMERIC);
        $cache_key = empty($rule_ids) ? 'public' : implode(',', $rule_ids);
        if (isset($this->summary_cache[$cache_key])) {
            return $this->summary_cache[$cache_key];
        }

        $membership_ids = array();
        $other_conditions = array();
        foreach ($rules as $rule) {
            if (!isset($rule->ID)) {
                continue;
            }
            foreach ($this->conditions_for_rule($rule) as $condition) {
                $type = isset($condition->access_type) ? sanitize_key((string) $condition->access_type) : '';
                $value = isset($condition->access_condition) ? (string) $condition->access_condition : '';
                if ('membership' === $type) {
                    $membership_id = (int) $value;
                    if ($membership_id > 0) {
                        $membership_ids[$membership_id] = true;
                    }
                    continue;
                }
                if ('' === $type) {
                    continue;
                }
                if (!isset($other_conditions[$type])) {
                    $other_conditions[$type] = array();
                }
                $other_conditions[$type][$value] = true;
            }
        }

        $membership_ids = array_map('intval', array_keys($membership_ids));
        sort($membership_ids, SORT_NUMERIC);
        $memberships = array_map(array($this, 'membership_detail'), $membership_ids);
        usort($memberships, static function ($left, $right) {
            return strnatcasecmp($left['title'], $right['title']);
        });

        $other_details = array();
        $other_count = 0;
        foreach ($other_conditions as $type => $value_lookup) {
            $values = array_map('strval', array_keys($value_lookup));
            sort($values, SORT_NATURAL | SORT_FLAG_CASE);
            $other_count += count($values);
            $other_details[] = array(
                'type' => $type,
                'label' => self::condition_type_label($type),
                'values' => $values,
            );
        }
        usort($other_details, static function ($left, $right) {
            return strnatcasecmp($left['label'], $right['label']);
        });

        $rule_details = array();
        foreach ($rule_ids as $rule_id) {
            $rule_details[] = self::post_detail($rule_id, __('Rule', 'member-library'));
        }

        $membership_count = count($memberships);
        $public = 0 === $membership_count && 0 === $other_count;
        $summary = array(
            'public' => $public,
            'membership_count' => $membership_count,
            'memberships' => $memberships,
            'other_condition_count' => $other_count,
            'other_conditions' => $other_details,
            'rules' => $rule_details,
            'label' => self::summary_label($membership_count, $other_count),
            'template_key' => substr(hash('sha256', wp_json_encode(array(
                'rules' => $rule_ids,
                'memberships' => $membership_ids,
                'other' => $other_conditions,
            ))), 0, 16),
        );

        $this->summary_cache[$cache_key] = $summary;
        return $summary;
    }

    public function render_modal($footer_data = '') {
        if (!self::is_access_list_screen(get_current_screen()) || empty($this->templates)) {
            return;
        }
        ?>
        <dialog
            id="tsol-content-access-dialog"
            class="tsol-content-access-dialog"
            aria-labelledby="tsol-content-access-dialog-title"
            data-title-prefix="<?php echo esc_attr__('Access:', 'member-library'); ?>"
        >
            <div class="tsol-content-access-dialog__header">
                <h2 id="tsol-content-access-dialog-title"><?php esc_html_e('Content access', 'member-library'); ?></h2>
                <button type="button" class="button-link tsol-content-access-dialog__close" data-tsol-content-access-close aria-label="<?php echo esc_attr__('Close access details', 'member-library'); ?>">
                    <span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
                </button>
            </div>
            <div class="tsol-content-access-dialog__body" data-tsol-content-access-details></div>
        </dialog>

        <div hidden aria-hidden="true">
            <?php foreach ($this->templates as $template_key => $summary) : ?>
                <template id="<?php echo esc_attr('tsol-content-access-template-' . $template_key); ?>">
                    <?php $this->render_access_details($summary); ?>
                </template>
            <?php endforeach; ?>
        </div>
        <?php
    }

    private function render_access_details($summary) {
        ?>
        <p class="tsol-content-access-dialog__intro">
            <?php esc_html_e('This is the effective access inherited from MemberPress rules. Nothing is stored or edited on the content by this TSOL view.', 'member-library'); ?>
        </p>

        <?php if (!empty($summary['memberships'])) : ?>
            <section class="tsol-content-access-dialog__section">
                <h3><?php echo esc_html(sprintf(
                    /* translators: %s: number of memberships. */
                    _n('%s effective membership', '%s effective memberships', $summary['membership_count'], 'member-library'),
                    number_format_i18n($summary['membership_count'])
                )); ?></h3>
                <ul class="tsol-content-access-dialog__membership-list">
                    <?php foreach ($summary['memberships'] as $membership) : ?>
                        <li>
                            <?php if ('' !== $membership['edit_url']) : ?>
                                <a href="<?php echo esc_url($membership['edit_url']); ?>"><?php echo esc_html($membership['title']); ?></a>
                            <?php else : ?>
                                <?php echo esc_html($membership['title']); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if (!empty($summary['other_conditions'])) : ?>
            <section class="tsol-content-access-dialog__section">
                <h3><?php esc_html_e('Other access conditions', 'member-library'); ?></h3>
                <dl class="tsol-content-access-dialog__conditions">
                    <?php foreach ($summary['other_conditions'] as $condition) : ?>
                        <dt><?php echo esc_html($condition['label']); ?></dt>
                        <dd><?php echo esc_html(implode(', ', $condition['values'])); ?></dd>
                    <?php endforeach; ?>
                </dl>
            </section>
        <?php endif; ?>

        <?php if (!empty($summary['rules'])) : ?>
            <section class="tsol-content-access-dialog__section">
                <h3><?php esc_html_e('Providing MemberPress rules', 'member-library'); ?></h3>
                <ul class="tsol-content-access-dialog__rule-list">
                    <?php foreach ($summary['rules'] as $rule) : ?>
                        <li>
                            <?php if ('' !== $rule['edit_url']) : ?>
                                <a href="<?php echo esc_url($rule['edit_url']); ?>"><?php echo esc_html($rule['title']); ?></a>
                            <?php else : ?>
                                <?php echo esc_html($rule['title']); ?>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
        <?php
    }

    private function conditions_for_rule($rule) {
        $rule_id = isset($rule->ID) ? (int) $rule->ID : 0;
        if ($rule_id <= 0) {
            return array();
        }
        if (!isset($this->condition_cache[$rule_id])) {
            $this->condition_cache[$rule_id] = is_callable(array($rule, 'access_conditions'))
                ? (array) $rule->access_conditions()
                : array();
        }
        return $this->condition_cache[$rule_id];
    }

    private function membership_detail($membership_id) {
        return self::post_detail($membership_id, __('Membership', 'member-library'));
    }

    private static function post_detail($post_id, $fallback_label) {
        $post = get_post((int) $post_id);
        $title = $post instanceof WP_Post ? trim(wp_strip_all_tags((string) $post->post_title)) : '';
        if ('' === $title) {
            $title = sprintf(
                /* translators: 1: object label, 2: WordPress post ID. */
                __('%1$s #%2$d', 'member-library'),
                $fallback_label,
                (int) $post_id
            );
        }
        $edit_url = get_edit_post_link((int) $post_id, '');
        return array(
            'id' => (int) $post_id,
            'title' => $title,
            'edit_url' => $edit_url ? (string) $edit_url : '',
        );
    }

    private static function summary_label($membership_count, $other_count) {
        $membership_label = sprintf(
            /* translators: %s: number of memberships. */
            _n('%s membership', '%s memberships', $membership_count, 'member-library'),
            number_format_i18n($membership_count)
        );
        if ($membership_count > 0 && $other_count <= 0) {
            return $membership_label;
        }
        $condition_label = sprintf(
            /* translators: %s: number of non-membership conditions. */
            _n('%s condition', '%s conditions', $other_count, 'member-library'),
            number_format_i18n($other_count)
        );
        if ($membership_count <= 0) {
            return $condition_label;
        }
        return sprintf(
            /* translators: 1: membership count label, 2: other-condition count. */
            __('%1$s + %2$d other', 'member-library'),
            $membership_label,
            $other_count
        );
    }

    private static function condition_type_label($type) {
        $labels = array(
            'logged_in' => __('Logged-in status', 'member-library'),
            'role' => __('WordPress role', 'member-library'),
            'capability' => __('WordPress capability', 'member-library'),
            'member' => __('Member', 'member-library'),
            'course_completed' => __('Completed course', 'member-library'),
        );
        if (isset($labels[$type])) {
            return $labels[$type];
        }
        return ucwords(str_replace(array('-', '_'), ' ', (string) $type));
    }

    private static function is_access_list_screen($screen) {
        if (!$screen instanceof WP_Screen || 'edit' !== $screen->base || empty($screen->post_type)) {
            return false;
        }

        return in_array((string) $screen->post_type, MemberLibrary_Content_Model::post_types(), true);
    }
}
