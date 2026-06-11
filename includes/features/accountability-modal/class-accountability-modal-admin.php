<?php
/**
 * Accountability modal admin screens.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Accountability_Modal_Admin {

    public const PAGE_SLUG = 'tsol-accountability-modal';

    private $repository;

    public function __construct(?TSOL_Accountability_Modal_Repository $repository = null) {
        $this->repository = $repository ? $repository : new TSOL_Accountability_Modal_Repository();
    }

    public function init() {
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_post_tsol_accountability_delete_submission', array($this, 'handle_delete_submission'));
    }

    public function register_settings() {
        TSOL_Accountability_Modal_Settings::register_settings();
    }

    public function display_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'overview';
        $allowed_tabs = array('overview', 'submissions', 'display', 'content');

        if (!in_array($tab, $allowed_tabs, true)) {
            $tab = 'overview';
        }

        ?>
        <div class="wrap tsol-site-plugin-admin tsol-accountability-admin">
            <h1><?php esc_html_e('Accountability Modal', 'tomschooloflife-plugin'); ?></h1>
            <?php settings_errors(); ?>
            <?php $this->render_action_notice(); ?>
            <?php $this->render_tabs($tab); ?>

            <div class="tsol-site-panel">
                <?php
                if ($tab === 'submissions') {
                    $this->render_submissions_tab();
                } elseif ($tab === 'display') {
                    $this->render_display_tab();
                } elseif ($tab === 'content') {
                    $this->render_content_tab();
                } else {
                    $this->render_overview_tab();
                }
                ?>
            </div>
        </div>
        <?php
    }

    public function handle_delete_submission() {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to delete accountability submissions.', 'tomschooloflife-plugin'));
        }

        $user_id = isset($_POST['user_id']) ? absint(wp_unslash($_POST['user_id'])) : 0;

        if (!$user_id) {
            $this->redirect_to_submissions('submission_delete_error');
        }

        check_admin_referer('tsol_accountability_delete_submission_' . $user_id);

        $deleted = $this->repository->delete_submission($user_id);

        $this->redirect_to_submissions($deleted ? 'submission_deleted' : 'submission_not_found');
    }

    private function render_action_notice() {
        $notice = isset($_GET['tsol_notice']) ? sanitize_key(wp_unslash($_GET['tsol_notice'])) : '';

        if (!$notice) {
            return;
        }

        $messages = array(
            'submission_deleted' => array(
                'class' => 'notice-success',
                'message' => __('Submission deleted. The user can see the modal again if they are otherwise eligible.', 'tomschooloflife-plugin'),
            ),
            'submission_not_found' => array(
                'class' => 'notice-warning',
                'message' => __('No saved submission was found for that user.', 'tomschooloflife-plugin'),
            ),
            'submission_delete_error' => array(
                'class' => 'notice-error',
                'message' => __('The submission could not be deleted.', 'tomschooloflife-plugin'),
            ),
        );

        if (!isset($messages[$notice])) {
            return;
        }

        printf(
            '<div class="notice %1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($messages[$notice]['class']),
            esc_html($messages[$notice]['message'])
        );
    }

    private function redirect_to_submissions($notice) {
        wp_safe_redirect(add_query_arg(array(
            'page' => self::PAGE_SLUG,
            'tab' => 'submissions',
            'tsol_notice' => sanitize_key($notice),
        ), admin_url('admin.php')));
        exit;
    }

    private function render_tabs($active_tab) {
        $tabs = array(
            'overview' => __('Overview', 'tomschooloflife-plugin'),
            'submissions' => __('Submissions', 'tomschooloflife-plugin'),
            'display' => __('Display Rules', 'tomschooloflife-plugin'),
            'content' => __('Content', 'tomschooloflife-plugin'),
        );

        echo '<nav class="nav-tab-wrapper tsol-site-tabs" aria-label="' . esc_attr__('Accountability modal settings', 'tomschooloflife-plugin') . '">';

        foreach ($tabs as $tab => $label) {
            $classes = 'nav-tab';
            if ($tab === $active_tab) {
                $classes .= ' nav-tab-active';
            }

            printf(
                '<a class="%1$s" href="%2$s">%3$s</a>',
                esc_attr($classes),
                esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab)),
                esc_html($label)
            );
        }

        echo '</nav>';
    }

    private function render_overview_tab() {
        $submissions = $this->repository->get_submissions(200);
        $joinable_calls = $this->repository->get_joinable_calls();
        $rules = TSOL_Accountability_Modal_Settings::get_display_rules();
        $location_count = count($rules['page_ids']);
        $total_submissions = count($submissions);
        $unassigned = $this->count_unassigned_submissions($submissions);

        ?>
        <div class="tsol-site-stat-grid">
            <?php $this->render_stat_card(__('Total submissions', 'tomschooloflife-plugin'), (string) $total_submissions); ?>
            <?php $this->render_stat_card(__('Awaiting placement', 'tomschooloflife-plugin'), (string) $unassigned); ?>
            <?php $this->render_stat_card(__('Joinable calls', 'tomschooloflife-plugin'), (string) count($joinable_calls)); ?>
            <?php $this->render_stat_card(__('Display locations', 'tomschooloflife-plugin'), (string) $location_count); ?>
        </div>

        <div class="tsol-site-admin-grid">
            <section class="tsol-site-card">
                <h2><?php esc_html_e('Recommendation approach', 'tomschooloflife-plugin'); ?></h2>
                <p><?php esc_html_e('For now, recommendations should be availability-based. If a user selects three calls they can attend, those are the safest recommended fits because they are both open and realistic for the user.', 'tomschooloflife-plugin'); ?></p>
                <p><?php esc_html_e('The next useful layer would be matching goal keywords or topic tags to group themes, but I would not auto-enroll users until the client confirms the business rules.', 'tomschooloflife-plugin'); ?></p>
            </section>

            <section class="tsol-site-card">
                <h2><?php esc_html_e('Current behavior', 'tomschooloflife-plugin'); ?></h2>
                <ul class="tsol-site-check-list">
                    <li><?php esc_html_e('Logged-in users only.', 'tomschooloflife-plugin'); ?></li>
                    <li><?php esc_html_e('Only selected content locations can auto-open the modal.', 'tomschooloflife-plugin'); ?></li>
                    <li><?php esc_html_e('Calls marked full or waitlist are excluded from choices.', 'tomschooloflife-plugin'); ?></li>
                    <li><?php esc_html_e('Submissions are stored on the user profile as user meta.', 'tomschooloflife-plugin'); ?></li>
                </ul>
            </section>
        </div>
        <?php
    }

    private function render_submissions_tab() {
        $submissions = $this->repository->get_submissions(100);

        if (!$submissions) {
            echo '<div class="tsol-submissions-empty">';
            echo '<h2>' . esc_html__('No submissions yet', 'tomschooloflife-plugin') . '</h2>';
            echo '<p>' . esc_html__('Once logged-in users complete the accountability modal, their intake details and selected call availability will appear here.', 'tomschooloflife-plugin') . '</p>';
            echo '</div>';
            return;
        }

        $review_count = $this->count_unassigned_submissions($submissions);
        $export_rows = $this->get_submission_export_rows($submissions);
        $call_filters = $this->get_submission_call_filters($submissions);

        ?>
        <div class="tsol-submissions-header">
            <div>
                <h2><?php esc_html_e('Accountability intake submissions', 'tomschooloflife-plugin'); ?></h2>
                <p><?php esc_html_e('Review user goals, available calls, and the current recommendation status before assigning anyone to a group.', 'tomschooloflife-plugin'); ?></p>
            </div>
            <div class="tsol-submissions-header__stats">
                <span><strong><?php echo esc_html((string) count($submissions)); ?></strong><?php esc_html_e('Total', 'tomschooloflife-plugin'); ?></span>
                <span><strong><?php echo esc_html((string) $review_count); ?></strong><?php esc_html_e('Awaiting placement', 'tomschooloflife-plugin'); ?></span>
            </div>
        </div>

        <?php $this->render_submissions_controls($call_filters); ?>

        <div class="tsol-submissions-list" data-submissions-list>
            <?php foreach ($submissions as $index => $submission) : ?>
                <?php $this->render_submission_card($submission, $index); ?>
            <?php endforeach; ?>
        </div>

        <div class="tsol-submissions-empty" data-submissions-no-results hidden>
            <h2><?php esc_html_e('No matching submissions', 'tomschooloflife-plugin'); ?></h2>
            <p><?php esc_html_e('Try clearing a filter or broadening your search.', 'tomschooloflife-plugin'); ?></p>
        </div>

        <div class="tsol-submissions-pagination" data-submissions-pagination></div>

        <script type="application/json" data-submissions-export><?php echo wp_json_encode($export_rows, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
        <?php
    }

    private function render_submissions_controls($call_filters) {
        ?>
        <div class="tsol-submissions-controls" data-submissions-controls>
            <div class="tsol-submissions-controls__search">
                <label for="tsol-submission-search"><?php esc_html_e('Search submissions', 'tomschooloflife-plugin'); ?></label>
                <input id="tsol-submission-search" type="search" placeholder="<?php esc_attr_e('Search name, email, occupation, answers, or calls', 'tomschooloflife-plugin'); ?>" data-submissions-search>
            </div>

            <div class="tsol-submissions-controls__grid">
                <label>
                    <span><?php esc_html_e('Placement status', 'tomschooloflife-plugin'); ?></span>
                    <select data-submissions-status-filter>
                        <option value=""><?php esc_html_e('All statuses', 'tomschooloflife-plugin'); ?></option>
                        <option value="review"><?php esc_html_e('Awaiting group placement', 'tomschooloflife-plugin'); ?></option>
                        <option value="assigned"><?php esc_html_e('Already in a group', 'tomschooloflife-plugin'); ?></option>
                    </select>
                </label>

                <label>
                    <span><?php esc_html_e('Selected call', 'tomschooloflife-plugin'); ?></span>
                    <select data-submissions-call-filter>
                        <option value=""><?php esc_html_e('Any call', 'tomschooloflife-plugin'); ?></option>
                        <?php foreach ($call_filters as $event_id => $label) : ?>
                            <option value="<?php echo esc_attr((string) $event_id); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span><?php esc_html_e('Submitted after', 'tomschooloflife-plugin'); ?></span>
                    <input type="date" data-submissions-date-from>
                </label>

                <label>
                    <span><?php esc_html_e('Submitted before', 'tomschooloflife-plugin'); ?></span>
                    <input type="date" data-submissions-date-to>
                </label>

                <label>
                    <span><?php esc_html_e('Sort by', 'tomschooloflife-plugin'); ?></span>
                    <select data-submissions-sort>
                        <option value="newest"><?php esc_html_e('Newest first', 'tomschooloflife-plugin'); ?></option>
                        <option value="oldest"><?php esc_html_e('Oldest first', 'tomschooloflife-plugin'); ?></option>
                        <option value="name_asc"><?php esc_html_e('Name A-Z', 'tomschooloflife-plugin'); ?></option>
                        <option value="name_desc"><?php esc_html_e('Name Z-A', 'tomschooloflife-plugin'); ?></option>
                        <option value="status"><?php esc_html_e('Awaiting placement first', 'tomschooloflife-plugin'); ?></option>
                        <option value="calls_desc"><?php esc_html_e('Most call choices', 'tomschooloflife-plugin'); ?></option>
                    </select>
                </label>

                <label>
                    <span><?php esc_html_e('Per page', 'tomschooloflife-plugin'); ?></span>
                    <select data-submissions-page-size>
                        <option value="10">10</option>
                        <option value="25">25</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </label>
            </div>

            <div class="tsol-submissions-controls__actions">
                <span data-submissions-result-count></span>
                <button type="button" class="button" data-submissions-clear><?php esc_html_e('Clear filters', 'tomschooloflife-plugin'); ?></button>
                <button type="button" class="button" data-submissions-export-filtered><?php esc_html_e('Export filtered CSV', 'tomschooloflife-plugin'); ?></button>
                <button type="button" class="button button-secondary" data-submissions-export-all><?php esc_html_e('Export all CSV', 'tomschooloflife-plugin'); ?></button>
            </div>
        </div>
        <?php
    }

    private function render_submission_card($submission, $index) {
        $user = $submission['user'];
        $is_assigned = $this->repository->user_has_accountability_group($user->ID);
        $submitted_at = $submission['submitted_at'] ? $submission['submitted_at'] : __('Unknown', 'tomschooloflife-plugin');
        $selected_count = is_array($submission['selected_calls']) ? count($submission['selected_calls']) : 0;
        $timestamp = $submission['submitted_at'] ? strtotime($submission['submitted_at']) : 0;
        $status = $is_assigned ? 'assigned' : 'review';
        $call_ids = implode(',', $this->get_submission_call_ids($submission));
        $search_text = $this->get_submission_search_text($submission, $is_assigned);

        ?>
        <article class="tsol-submission-card" data-submission-card data-submission-index="<?php echo esc_attr((string) $index); ?>" data-submission-status="<?php echo esc_attr($status); ?>" data-submission-submitted="<?php echo esc_attr((string) $timestamp); ?>" data-submission-name="<?php echo esc_attr(strtolower($user->display_name)); ?>" data-submission-call-count="<?php echo esc_attr((string) $selected_count); ?>" data-submission-call-ids="<?php echo esc_attr($call_ids); ?>" data-submission-search="<?php echo esc_attr(strtolower($search_text)); ?>">
            <header class="tsol-submission-card__header">
                <div class="tsol-submission-card__user">
                    <?php echo get_avatar($user->ID, 42); ?>
                    <div>
                        <strong><?php echo esc_html($user->display_name); ?></strong>
                        <a href="<?php echo esc_url('mailto:' . $user->user_email); ?>"><?php echo esc_html($user->user_email); ?></a>
                    </div>
                </div>

                <div class="tsol-submission-card__status">
                    <span class="<?php echo esc_attr($is_assigned ? 'tsol-site-status tsol-site-status--ok' : 'tsol-site-status tsol-site-status--warning'); ?>">
                        <?php echo esc_html($this->get_submission_status_label($is_assigned)); ?>
                    </span>
                    <small><?php echo esc_html($submitted_at); ?></small>
                </div>
            </header>

            <div class="tsol-submission-card__meta">
                <span>
                    <strong><?php esc_html_e('Occupation', 'tomschooloflife-plugin'); ?></strong>
                    <?php echo esc_html($submission['occupation'] ? $submission['occupation'] : __('Not provided', 'tomschooloflife-plugin')); ?>
                </span>
                <span>
                    <strong><?php esc_html_e('Available calls', 'tomschooloflife-plugin'); ?></strong>
                    <?php echo esc_html(sprintf(_n('%d selected', '%d selected', $selected_count, 'tomschooloflife-plugin'), $selected_count)); ?>
                </span>
            </div>

            <div class="tsol-submission-card__grid">
                <section>
                    <h3><?php esc_html_e('Recommended fit', 'tomschooloflife-plugin'); ?></h3>
                    <?php $this->render_recommendations($submission['selected_calls']); ?>
                </section>

                <section>
                    <h3><?php esc_html_e('Admin actions', 'tomschooloflife-plugin'); ?></h3>
                    <div class="tsol-submission-card__actions">
                        <a class="button button-secondary" href="<?php echo esc_url(get_edit_user_link($user->ID)); ?>"><?php esc_html_e('Edit user', 'tomschooloflife-plugin'); ?></a>
                        <a class="button button-secondary" href="<?php echo esc_url('mailto:' . $user->user_email); ?>"><?php esc_html_e('Email user', 'tomschooloflife-plugin'); ?></a>
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(sprintf(__('Delete the saved accountability submission for %s? This cannot be undone.', 'tomschooloflife-plugin'), $user->display_name)); ?>');">
                            <input type="hidden" name="action" value="tsol_accountability_delete_submission">
                            <input type="hidden" name="user_id" value="<?php echo esc_attr((string) $user->ID); ?>">
                            <?php wp_nonce_field('tsol_accountability_delete_submission_' . $user->ID); ?>
                            <button type="submit" class="button button-link-delete"><?php esc_html_e('Delete submission', 'tomschooloflife-plugin'); ?></button>
                        </form>
                    </div>
                </section>
            </div>

            <details class="tsol-submission-card__answers">
                <summary><?php esc_html_e('Review answers', 'tomschooloflife-plugin'); ?></summary>
                <?php $this->render_submission_answers($submission); ?>
            </details>
        </article>
        <?php
    }

    private function get_submission_export_rows($submissions) {
        $rows = array();

        foreach ($submissions as $index => $submission) {
            $user = $submission['user'];
            $is_assigned = $this->repository->user_has_accountability_group($user->ID);
            $selected_call_labels = $this->get_submission_call_labels($submission);
            $answers = $this->get_submission_answer_map($submission);

            $rows[] = array(
                'index' => $index,
                'user_id' => (int) $user->ID,
                'name' => $user->display_name,
                'email' => $user->user_email,
                'status' => $this->get_submission_status_label($is_assigned),
                'status_key' => $is_assigned ? 'assigned' : 'review',
                'submitted_at' => $submission['submitted_at'],
                'submitted_timestamp' => $submission['submitted_at'] ? strtotime($submission['submitted_at']) : 0,
                'occupation' => $submission['occupation'],
                'selected_call_count' => count($selected_call_labels),
                'selected_calls' => implode(', ', $selected_call_labels),
                'answers' => $answers,
            );
        }

        return $rows;
    }

    private function get_submission_call_filters($submissions) {
        $calls = array();

        foreach ($submissions as $submission) {
            foreach ((array) $submission['selected_calls'] as $call) {
                if (!is_array($call) || empty($call['event_id'])) {
                    continue;
                }

                $calls[(int) $call['event_id']] = isset($call['label']) ? $call['label'] : sprintf(__('Call #%d', 'tomschooloflife-plugin'), (int) $call['event_id']);
            }
        }

        asort($calls);

        return $calls;
    }

    private function get_submission_call_ids($submission) {
        $ids = array();

        foreach ((array) $submission['selected_calls'] as $call) {
            if (is_array($call) && !empty($call['event_id'])) {
                $ids[] = (int) $call['event_id'];
            }
        }

        return $ids;
    }

    private function get_submission_call_labels($submission) {
        $labels = array();

        foreach ((array) $submission['selected_calls'] as $call) {
            if (is_array($call) && !empty($call['label'])) {
                $labels[] = (string) $call['label'];
            }
        }

        return $labels;
    }

    private function get_submission_answer_map($submission) {
        $answers = array();

        if (!empty($submission['answers']) && is_array($submission['answers'])) {
            foreach ($submission['answers'] as $answer) {
                if (!is_array($answer)) {
                    continue;
                }

                $label = isset($answer['label']) ? (string) $answer['label'] : __('Question', 'tomschooloflife-plugin');
                $value = isset($answer['display_value']) ? $answer['display_value'] : '';

                if (is_array($value)) {
                    $value = implode(', ', array_map('strval', $value));
                }

                $answers[$label] = (string) $value;
            }
        }

        if ($answers) {
            return $answers;
        }

        return array(
            __('Professional goals', 'tomschooloflife-plugin') => $submission['professional_goals'],
            __('Personal goals', 'tomschooloflife-plugin') => $submission['personal_goals'],
            __('Why TSoL', 'tomschooloflife-plugin') => $submission['tsol_reason'],
        );
    }

    private function get_submission_status_label($is_assigned) {
        return $is_assigned
            ? __('Already in a group', 'tomschooloflife-plugin')
            : __('Awaiting group placement', 'tomschooloflife-plugin');
    }

    private function get_submission_search_text($submission, $is_assigned) {
        $user = $submission['user'];
        $parts = array(
            $user->display_name,
            $user->user_email,
            $submission['occupation'],
            $this->get_submission_status_label($is_assigned),
            implode(' ', $this->get_submission_call_labels($submission)),
        );

        foreach ($this->get_submission_answer_map($submission) as $label => $value) {
            $parts[] = $label;
            $parts[] = $value;
        }

        return implode(' ', array_filter(array_map('strval', $parts)));
    }

    private function render_display_tab() {
        $rules = TSOL_Accountability_Modal_Settings::get_display_rules();
        $content_items = $this->get_display_location_posts();

        ?>
        <form method="post" action="options.php">
            <?php settings_fields(TSOL_Accountability_Modal_Settings::DISPLAY_GROUP); ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e('Enable modal', 'tomschooloflife-plugin'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr(TSOL_Accountability_Modal_Settings::DISPLAY_OPTION); ?>[enabled]" value="1" <?php checked('1', $rules['enabled']); ?>>
                            <?php esc_html_e('Allow the accountability modal to render.', 'tomschooloflife-plugin'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <?php esc_html_e('Show on content', 'tomschooloflife-plugin'); ?>
                    </th>
                    <td>
                        <?php $this->render_page_picker($content_items, $rules['page_ids']); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="tsol-accountability-scroll-threshold"><?php esc_html_e('Scroll trigger', 'tomschooloflife-plugin'); ?></label>
                    </th>
                    <td>
                        <input id="tsol-accountability-scroll-threshold" type="number" min="0" step="1" name="<?php echo esc_attr(TSOL_Accountability_Modal_Settings::DISPLAY_OPTION); ?>[scroll_threshold]" value="<?php echo esc_attr($rules['scroll_threshold']); ?>" class="small-text">
                        <span><?php esc_html_e('pixels before auto-opening. Use 0 to open immediately.', 'tomschooloflife-plugin'); ?></span>
                    </td>
                </tr>
                <?php $this->render_display_checkbox('hide_if_current_member', __('Hide from users already in an accountability group', 'tomschooloflife-plugin'), $rules); ?>
                <?php $this->render_display_checkbox('hide_after_submission', __('Hide after the user submits the intake form', 'tomschooloflife-plugin'), $rules); ?>
                <?php $this->render_display_checkbox('show_admin_bar_button', __('Show admin bar preview button on selected content', 'tomschooloflife-plugin'), $rules); ?>
                <?php $this->render_display_checkbox('save_draft_answers', __('Save unfinished answers in the browser', 'tomschooloflife-plugin'), $rules); ?>
                <tr>
                    <th scope="row">
                        <label for="tsol-accountability-draft-ttl"><?php esc_html_e('Draft expiry', 'tomschooloflife-plugin'); ?></label>
                    </th>
                    <td>
                        <input id="tsol-accountability-draft-ttl" type="number" min="1" step="1" name="<?php echo esc_attr(TSOL_Accountability_Modal_Settings::DISPLAY_OPTION); ?>[draft_ttl_days]" value="<?php echo esc_attr($rules['draft_ttl_days']); ?>" class="small-text">
                        <span><?php esc_html_e('days before unfinished local drafts expire.', 'tomschooloflife-plugin'); ?></span>
                    </td>
                </tr>
                <?php $this->render_display_checkbox('show_resume_launcher', __('Show circular resume button after close', 'tomschooloflife-plugin'), $rules); ?>
                <tr>
                    <th scope="row">
                        <label for="tsol-accountability-launcher-position"><?php esc_html_e('Resume button position', 'tomschooloflife-plugin'); ?></label>
                    </th>
                    <td>
                        <select id="tsol-accountability-launcher-position" name="<?php echo esc_attr(TSOL_Accountability_Modal_Settings::DISPLAY_OPTION); ?>[launcher_position]">
                            <?php foreach (TSOL_Accountability_Modal_Settings::get_launcher_positions() as $position => $label) : ?>
                                <option value="<?php echo esc_attr($position); ?>" <?php selected($position, $rules['launcher_position']); ?>><?php echo esc_html($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e('Choose where the circular resume button appears after a user closes an unfinished modal.', 'tomschooloflife-plugin'); ?></p>
                    </td>
                </tr>
                <?php $this->render_display_checkbox('animate_resume_launcher', __('Animate the resume button occasionally', 'tomschooloflife-plugin'), $rules); ?>
            </table>

            <?php submit_button(__('Save display rules', 'tomschooloflife-plugin')); ?>
        </form>
        <?php
    }

    private function get_display_location_posts() {
        $post_types = get_post_types(array(
            'public' => true,
        ), 'names');

        unset($post_types['attachment']);

        $post_types = array_values((array) apply_filters('tsol_site_accountability_modal_location_post_types', $post_types));

        $posts = get_posts(array(
            'post_type' => $post_types,
            'post_status' => array('publish', 'private', 'draft'),
            'posts_per_page' => -1,
            'orderby' => 'title',
            'order' => 'ASC',
            'no_found_rows' => true,
        ));

        usort($posts, function($left, $right) {
            $type_compare = strcmp($left->post_type, $right->post_type);

            if ($type_compare !== 0) {
                return $type_compare;
            }

            return strcasecmp($left->post_title, $right->post_title);
        });

        return $posts;
    }

    private function render_page_picker($pages, $selected_page_ids) {
        $selected_page_ids = array_map('absint', (array) $selected_page_ids);
        $input_name = TSOL_Accountability_Modal_Settings::DISPLAY_OPTION . '[page_ids][]';
        $filter_data = $this->get_page_picker_filter_data($pages);

        ?>
        <div class="tsol-page-picker" data-page-picker>
            <div class="tsol-page-picker__header">
                <div>
                    <strong><?php esc_html_e('Selected display locations', 'tomschooloflife-plugin'); ?></strong>
                    <span class="tsol-page-picker__count" data-page-picker-count></span>
                </div>
                <p><?php esc_html_e('The modal will auto-open only on these selected content items for eligible logged-in users.', 'tomschooloflife-plugin'); ?></p>
            </div>

            <div class="tsol-page-picker__selected" data-page-picker-selected aria-live="polite"></div>

            <div class="tsol-page-picker__search">
                <label class="screen-reader-text" for="tsol-accountability-page-search"><?php esc_html_e('Search content', 'tomschooloflife-plugin'); ?></label>
                <input id="tsol-accountability-page-search" type="search" class="regular-text" data-page-picker-search placeholder="<?php esc_attr_e('Search by title, URL, type, status, or ID', 'tomschooloflife-plugin'); ?>">
            </div>

            <div class="tsol-page-picker__filters" aria-label="<?php esc_attr_e('Content filters', 'tomschooloflife-plugin'); ?>">
                <label>
                    <span><?php esc_html_e('Type', 'tomschooloflife-plugin'); ?></span>
                    <select data-page-picker-type-filter>
                        <option value=""><?php esc_html_e('All content types', 'tomschooloflife-plugin'); ?></option>
                        <?php foreach ($filter_data['post_types'] as $post_type => $label) : ?>
                            <option value="<?php echo esc_attr($post_type); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e('Status', 'tomschooloflife-plugin'); ?></span>
                    <select data-page-picker-status-filter>
                        <option value=""><?php esc_html_e('All statuses', 'tomschooloflife-plugin'); ?></option>
                        <?php foreach ($filter_data['statuses'] as $status => $label) : ?>
                            <option value="<?php echo esc_attr($status); ?>"><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span><?php esc_html_e('Selection', 'tomschooloflife-plugin'); ?></span>
                    <select data-page-picker-selection-filter>
                        <option value=""><?php esc_html_e('All items', 'tomschooloflife-plugin'); ?></option>
                        <option value="selected"><?php esc_html_e('Selected only', 'tomschooloflife-plugin'); ?></option>
                        <option value="unselected"><?php esc_html_e('Not selected', 'tomschooloflife-plugin'); ?></option>
                    </select>
                </label>
                <button type="button" class="button" data-page-picker-clear><?php esc_html_e('Clear filters', 'tomschooloflife-plugin'); ?></button>
            </div>

            <div class="tsol-page-picker__results-meta">
                <span data-page-picker-visible-count></span>
            </div>

            <div class="tsol-page-picker__results" data-page-picker-results>
                <?php foreach ($pages as $page) : ?>
                    <?php $this->render_page_picker_option($page, $selected_page_ids, $input_name); ?>
                <?php endforeach; ?>
            </div>

            <p class="tsol-page-picker__empty" data-page-picker-empty hidden>
                <?php esc_html_e('No pages match that search.', 'tomschooloflife-plugin'); ?>
            </p>

            <p class="description">
                <?php esc_html_e('Use search and filters to find the exact content where this modal should appear. Draft items can be selected now and will work once published.', 'tomschooloflife-plugin'); ?>
            </p>
        </div>
        <?php
    }

    private function get_page_picker_filter_data($posts) {
        $post_types = array();
        $statuses = array();

        foreach ($posts as $post) {
            $post_type = get_post_type_object($post->post_type);
            $status = get_post_status_object($post->post_status);

            $post_types[$post->post_type] = $post_type && isset($post_type->labels->singular_name)
                ? $post_type->labels->singular_name
                : $post->post_type;

            $statuses[$post->post_status] = $status && isset($status->label)
                ? $status->label
                : ucfirst($post->post_status);
        }

        asort($post_types);
        asort($statuses);

        return array(
            'post_types' => $post_types,
            'statuses' => $statuses,
        );
    }

    private function render_page_picker_option($post, $selected_page_ids, $input_name) {
        $page_id = (int) $post->ID;
        $title = $post->post_title ? $post->post_title : sprintf(__('Untitled content #%d', 'tomschooloflife-plugin'), $page_id);
        $post_type = get_post_type_object($post->post_type);
        $type_label = $post_type && isset($post_type->labels->singular_name) ? $post_type->labels->singular_name : $post->post_type;
        $status = get_post_status_object($post->post_status);
        $status_label = $status && isset($status->label) ? $status->label : ucfirst($post->post_status);
        $status_class = $post->post_status === 'publish' ? 'tsol-site-status--ok' : 'tsol-site-status--warning';
        $permalink = get_permalink($page_id);
        $path = wp_parse_url($permalink, PHP_URL_PATH);
        $path = $path ? untrailingslashit($path) . '/' : get_page_uri($page_id);
        $edit_url = get_edit_post_link($page_id, 'raw');
        $is_selected = in_array($page_id, $selected_page_ids, true);
        $search_text = implode(' ', array($title, $path, $type_label, $post->post_type, $status_label, $post->post_status, (string) $page_id));
        $option_classes = 'tsol-page-picker__option';

        if ($is_selected) {
            $option_classes .= ' is-selected';
        }

        ?>
        <div class="<?php echo esc_attr($option_classes); ?>" data-page-picker-option data-page-title="<?php echo esc_attr($title); ?>" data-page-path="<?php echo esc_attr($path); ?>" data-page-id="<?php echo esc_attr($page_id); ?>" data-page-type="<?php echo esc_attr($post->post_type); ?>" data-page-type-label="<?php echo esc_attr($type_label); ?>" data-page-status="<?php echo esc_attr($post->post_status); ?>" data-page-status-label="<?php echo esc_attr($status_label); ?>" data-page-status-class="<?php echo esc_attr($status_class); ?>" data-page-search="<?php echo esc_attr(strtolower($search_text)); ?>">
            <label class="tsol-page-picker__option-main">
                <input type="checkbox" name="<?php echo esc_attr($input_name); ?>" value="<?php echo esc_attr($page_id); ?>" data-page-picker-checkbox <?php checked($is_selected); ?>>
                <span class="tsol-page-picker__option-copy">
                    <span class="tsol-page-picker__option-title"><?php echo esc_html($title); ?></span>
                    <span class="tsol-page-picker__option-meta">
                        <span><?php echo esc_html($path); ?></span>
                        <span class="tsol-site-status tsol-page-picker__type"><?php echo esc_html($type_label); ?></span>
                        <span class="tsol-site-status tsol-page-picker__status <?php echo esc_attr($status_class); ?>"><?php echo esc_html($status_label); ?></span>
                        <span><?php echo esc_html('#' . $page_id); ?></span>
                    </span>
                </span>
            </label>

            <span class="tsol-page-picker__option-actions">
                <?php if ($edit_url) : ?>
                    <a href="<?php echo esc_url($edit_url); ?>"><?php esc_html_e('Edit', 'tomschooloflife-plugin'); ?></a>
                <?php endif; ?>
                <?php if ($permalink) : ?>
                    <a href="<?php echo esc_url($permalink); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View', 'tomschooloflife-plugin'); ?></a>
                <?php endif; ?>
            </span>
        </div>
        <?php
    }

    private function render_content_tab() {
        $content = TSOL_Accountability_Modal_Settings::get_content();
        ?>
        <form method="post" action="options.php">
            <?php settings_fields(TSOL_Accountability_Modal_Settings::CONTENT_GROUP); ?>

            <h2><?php esc_html_e('Header', 'tomschooloflife-plugin'); ?></h2>
            <table class="form-table" role="presentation">
                <?php $this->render_text_field('header_eyebrow', __('Eyebrow', 'tomschooloflife-plugin'), $content); ?>
                <?php $this->render_text_field('header_title', __('Headline', 'tomschooloflife-plugin'), $content); ?>
                <?php $this->render_text_field('header_intro', __('Intro', 'tomschooloflife-plugin'), $content, 'regular-text tsol-site-wide-input'); ?>
            </table>

            <h2><?php esc_html_e('Question flow', 'tomschooloflife-plugin'); ?></h2>
            <p class="description"><?php esc_html_e('Build the modal like an ACF repeater: add questions, choose the field type, decide what renders, and configure choices or numeric bounds where needed.', 'tomschooloflife-plugin'); ?></p>
            <?php $this->render_questions_builder($content['questions']); ?>

            <h2><?php esc_html_e('Buttons and messages', 'tomschooloflife-plugin'); ?></h2>
            <table class="form-table" role="presentation">
                <?php $this->render_text_field('back_button', __('Back button', 'tomschooloflife-plugin'), $content); ?>
                <?php $this->render_text_field('continue_button', __('Continue button', 'tomschooloflife-plugin'), $content); ?>
                <?php $this->render_text_field('submit_button', __('Submit button', 'tomschooloflife-plugin'), $content); ?>
                <?php $this->render_text_field('success_message', __('Success message', 'tomschooloflife-plugin'), $content, 'regular-text tsol-site-wide-input'); ?>
            </table>

            <?php submit_button(__('Save content', 'tomschooloflife-plugin')); ?>
        </form>
        <?php
    }

    private function render_questions_builder($questions) {
        $questions = is_array($questions) ? $questions : TSOL_Accountability_Modal_Settings::default_questions();

        ?>
        <div class="tsol-question-builder" data-question-builder>
            <div class="tsol-question-builder__toolbar">
                <button type="button" class="button button-secondary" data-question-builder-add>
                    <?php esc_html_e('Add question', 'tomschooloflife-plugin'); ?>
                </button>
            </div>

            <div class="tsol-question-builder__rows" data-question-builder-rows>
                <?php foreach ($questions as $index => $question) : ?>
                    <?php $this->render_question_builder_row($question, (string) $index); ?>
                <?php endforeach; ?>
            </div>

            <template data-question-builder-template>
                <?php $this->render_question_builder_row($this->get_blank_question(), '__index__'); ?>
            </template>
        </div>
        <?php
    }

    private function render_question_builder_row($question, $index) {
        $question = wp_parse_args($question, $this->get_blank_question());
        $base_name = TSOL_Accountability_Modal_Settings::CONTENT_OPTION . '[questions][' . $index . ']';
        $field_id_prefix = 'tsol-question-' . $index . '-';
        $body_id = 'tsol-question-row-body-' . $index;
        $title = $question['title'] ? $question['title'] : __('Untitled question', 'tomschooloflife-plugin');
        $type_label = isset(TSOL_Accountability_Modal_Settings::get_question_types()[$question['type']])
            ? TSOL_Accountability_Modal_Settings::get_question_types()[$question['type']]
            : __('Text field', 'tomschooloflife-plugin');

        ?>
        <section class="tsol-question-row" data-question-row>
            <div class="tsol-question-row__bar">
                <button type="button" class="tsol-question-row__toggle" data-question-row-toggle aria-expanded="false" aria-controls="<?php echo esc_attr($body_id); ?>">
                    <span class="tsol-question-row__summary">
                        <strong data-question-summary><?php echo esc_html($title); ?></strong>
                        <span data-question-type-summary><?php echo esc_html($type_label); ?></span>
                    </span>
                    <span class="tsol-question-row__chevron" aria-hidden="true"></span>
                </button>
                <div class="tsol-question-row__bar-actions">
                    <label class="tsol-question-row__enabled">
                        <input type="checkbox" name="<?php echo esc_attr($base_name . '[enabled]'); ?>" value="1" <?php checked('1', $question['enabled']); ?>>
                        <?php esc_html_e('Render', 'tomschooloflife-plugin'); ?>
                    </label>
                    <button type="button" class="button" data-question-row-up><?php esc_html_e('Up', 'tomschooloflife-plugin'); ?></button>
                    <button type="button" class="button" data-question-row-down><?php esc_html_e('Down', 'tomschooloflife-plugin'); ?></button>
                    <button type="button" class="button button-link-delete" data-question-row-remove><?php esc_html_e('Remove', 'tomschooloflife-plugin'); ?></button>
                </div>
            </div>

            <div id="<?php echo esc_attr($body_id); ?>" class="tsol-question-row__body" data-question-row-body hidden>
                <input type="hidden" name="<?php echo esc_attr($base_name . '[key]'); ?>" value="<?php echo esc_attr($question['key']); ?>" data-question-key>

                <div class="tsol-question-row__section">
                    <h3><?php esc_html_e('Answer setup', 'tomschooloflife-plugin'); ?></h3>
                    <div class="tsol-question-row__grid tsol-question-row__grid--settings">
                        <div class="tsol-question-row__field">
                            <label for="<?php echo esc_attr($field_id_prefix . 'type'); ?>"><?php esc_html_e('Answer type', 'tomschooloflife-plugin'); ?></label>
                            <select id="<?php echo esc_attr($field_id_prefix . 'type'); ?>" name="<?php echo esc_attr($base_name . '[type]'); ?>" data-question-type>
                                <?php foreach (TSOL_Accountability_Modal_Settings::get_question_types() as $type => $label) : ?>
                                    <option value="<?php echo esc_attr($type); ?>" <?php selected($type, $question['type']); ?>><?php echo esc_html($label); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="tsol-question-row__field tsol-question-row__toggle-field">
                            <label>
                                <input type="checkbox" name="<?php echo esc_attr($base_name . '[required]'); ?>" value="1" <?php checked('1', $question['required']); ?>>
                                <?php esc_html_e('Require an answer', 'tomschooloflife-plugin'); ?>
                            </label>
                        </div>
                    </div>
                </div>

                <div class="tsol-question-row__section">
                    <h3><?php esc_html_e('What the member sees', 'tomschooloflife-plugin'); ?></h3>
                    <div class="tsol-question-row__grid">
                        <?php $this->render_question_input($base_name, $field_id_prefix, 'title', __('Main question', 'tomschooloflife-plugin'), $question['title'], 'text', 'tsol-question-row__field--wide', 'data-question-title'); ?>
                        <?php $this->render_question_input($base_name, $field_id_prefix, 'eyebrow', __('Small intro label', 'tomschooloflife-plugin'), $question['eyebrow']); ?>
                        <?php $this->render_question_input($base_name, $field_id_prefix, 'label', __('Input label', 'tomschooloflife-plugin'), $question['label']); ?>
                        <?php $this->render_question_input($base_name, $field_id_prefix, 'helper', __('Supporting text', 'tomschooloflife-plugin'), $question['helper'], 'text', 'tsol-question-row__field--wide'); ?>
                        <?php $this->render_question_input($base_name, $field_id_prefix, 'placeholder', __('Placeholder text', 'tomschooloflife-plugin'), $question['placeholder'], 'text', '', 'data-question-placeholder-setting'); ?>
                    </div>
                </div>

                <div class="tsol-question-row__section" data-question-options-setting>
                    <h3><?php esc_html_e('Answer choices', 'tomschooloflife-plugin'); ?></h3>
                    <div class="tsol-question-row__field">
                        <label for="<?php echo esc_attr($field_id_prefix . 'options'); ?>"><?php esc_html_e('Choices', 'tomschooloflife-plugin'); ?></label>
                        <textarea id="<?php echo esc_attr($field_id_prefix . 'options'); ?>" name="<?php echo esc_attr($base_name . '[options]'); ?>" rows="4"><?php echo esc_textarea($question['options']); ?></textarea>
                        <p class="description"><?php esc_html_e('Enter one choice per line. For text fields, these appear as quick-pick buttons.', 'tomschooloflife-plugin'); ?></p>
                    </div>
                </div>

                <div class="tsol-question-row__section" data-question-number-setting>
                    <h3><?php esc_html_e('Number limits', 'tomschooloflife-plugin'); ?></h3>
                    <div class="tsol-question-row__numbers">
                        <?php $this->render_question_input($base_name, $field_id_prefix, 'min', __('Minimum', 'tomschooloflife-plugin'), $question['min'], 'number'); ?>
                        <?php $this->render_question_input($base_name, $field_id_prefix, 'max', __('Maximum', 'tomschooloflife-plugin'), $question['max'], 'number'); ?>
                        <?php $this->render_question_input($base_name, $field_id_prefix, 'step', __('Step size', 'tomschooloflife-plugin'), $question['step'], 'number'); ?>
                    </div>
                </div>
            </div>
        </section>
        <?php
    }

    private function render_question_input($base_name, $field_id_prefix, $key, $label, $value, $type = 'text', $class = '', $data_attribute = '') {
        $classes = trim('tsol-question-row__field ' . $class);

        ?>
        <div class="<?php echo esc_attr($classes); ?>" <?php echo $data_attribute ? esc_attr($data_attribute) : ''; ?>>
            <label for="<?php echo esc_attr($field_id_prefix . $key); ?>"><?php echo esc_html($label); ?></label>
            <input id="<?php echo esc_attr($field_id_prefix . $key); ?>" type="<?php echo esc_attr($type); ?>" name="<?php echo esc_attr($base_name . '[' . $key . ']'); ?>" value="<?php echo esc_attr($value); ?>">
        </div>
        <?php
    }

    private function get_blank_question() {
        return array(
            'key' => '',
            'enabled' => '1',
            'required' => '0',
            'type' => 'text',
            'eyebrow' => '',
            'title' => '',
            'helper' => '',
            'label' => '',
            'placeholder' => '',
            'options' => '',
            'min' => '',
            'max' => '',
            'step' => '',
        );
    }

    private function render_stat_card($label, $value) {
        ?>
        <div class="tsol-site-stat-card">
            <span><?php echo esc_html($label); ?></span>
            <strong><?php echo esc_html($value); ?></strong>
        </div>
        <?php
    }

    private function render_display_checkbox($key, $label, $rules) {
        ?>
        <tr>
            <th scope="row"><?php echo esc_html($label); ?></th>
            <td>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr(TSOL_Accountability_Modal_Settings::DISPLAY_OPTION . '[' . $key . ']'); ?>" value="1" <?php checked('1', $rules[$key]); ?>>
                    <?php esc_html_e('Enabled', 'tomschooloflife-plugin'); ?>
                </label>
            </td>
        </tr>
        <?php
    }

    private function render_text_field($key, $label, $content, $class = 'regular-text') {
        ?>
        <tr>
            <th scope="row">
                <label for="<?php echo esc_attr('tsol-accountability-content-' . $key); ?>"><?php echo esc_html($label); ?></label>
            </th>
            <td>
                <input id="<?php echo esc_attr('tsol-accountability-content-' . $key); ?>" type="text" class="<?php echo esc_attr($class); ?>" name="<?php echo esc_attr(TSOL_Accountability_Modal_Settings::CONTENT_OPTION . '[' . $key . ']'); ?>" value="<?php echo esc_attr($content[$key]); ?>">
            </td>
        </tr>
        <?php
    }

    private function render_textarea_field($key, $label, $content) {
        ?>
        <tr>
            <th scope="row">
                <label for="<?php echo esc_attr('tsol-accountability-content-' . $key); ?>"><?php echo esc_html($label); ?></label>
            </th>
            <td>
                <textarea id="<?php echo esc_attr('tsol-accountability-content-' . $key); ?>" class="large-text" rows="5" name="<?php echo esc_attr(TSOL_Accountability_Modal_Settings::CONTENT_OPTION . '[' . $key . ']'); ?>"><?php echo esc_textarea($content[$key]); ?></textarea>
            </td>
        </tr>
        <?php
    }

    private function render_question_fields($prefix, $title, $content, $include_quick_picks = false) {
        ?>
        <section class="tsol-site-card tsol-content-section">
            <h3><?php echo esc_html($title); ?></h3>
            <table class="form-table" role="presentation">
                <?php $this->render_text_field($prefix . '_eyebrow', __('Step label', 'tomschooloflife-plugin'), $content); ?>
                <?php $this->render_text_field($prefix . '_title', __('Question', 'tomschooloflife-plugin'), $content, 'regular-text tsol-site-wide-input'); ?>
                <?php $this->render_text_field($prefix . '_helper', __('Helper text', 'tomschooloflife-plugin'), $content, 'regular-text tsol-site-wide-input'); ?>
                <?php if (isset($content[$prefix . '_label'])) : ?>
                    <?php $this->render_text_field($prefix . '_label', __('Field label', 'tomschooloflife-plugin'), $content); ?>
                <?php endif; ?>
                <?php if (isset($content[$prefix . '_placeholder'])) : ?>
                    <?php $this->render_text_field($prefix . '_placeholder', __('Placeholder', 'tomschooloflife-plugin'), $content); ?>
                <?php endif; ?>
                <?php if (isset($content[$prefix . '_empty'])) : ?>
                    <?php $this->render_text_field($prefix . '_empty', __('Empty state', 'tomschooloflife-plugin'), $content, 'regular-text tsol-site-wide-input'); ?>
                <?php endif; ?>
                <?php if ($include_quick_picks) : ?>
                    <?php $this->render_textarea_field('occupation_quick_picks', __('Quick-pick answers', 'tomschooloflife-plugin'), $content); ?>
                    <tr>
                        <th></th>
                        <td><p class="description"><?php esc_html_e('One quick-pick answer per line.', 'tomschooloflife-plugin'); ?></p></td>
                    </tr>
                <?php endif; ?>
            </table>
        </section>
        <?php
    }

    private function render_recommendations($selected_calls) {
        if (!$selected_calls) {
            echo '<span class="tsol-site-muted">' . esc_html__('No calls selected', 'tomschooloflife-plugin') . '</span>';
            return;
        }

        $joinable_map = $this->repository->get_joinable_call_map();

        echo '<ol class="tsol-recommendation-list">';
        foreach ($selected_calls as $call) {
            $event_id = isset($call['event_id']) ? (int) $call['event_id'] : 0;
            $label = isset($call['label']) ? $call['label'] : '';
            $is_open = $event_id && isset($joinable_map[$event_id]);

            echo '<li>';
            echo esc_html($label);
            echo ' <span class="' . esc_attr($is_open ? 'tsol-site-status tsol-site-status--ok' : 'tsol-site-status tsol-site-status--warning') . '">';
            echo esc_html($is_open ? __('Open', 'tomschooloflife-plugin') : __('Review', 'tomschooloflife-plugin'));
            echo '</span>';
            echo '</li>';
        }
        echo '</ol>';
    }

    private function render_submission_answers($submission) {
        $answers = array();

        if (!empty($submission['answers']) && is_array($submission['answers'])) {
            foreach ($submission['answers'] as $answer) {
                if (!is_array($answer)) {
                    continue;
                }

                $label = isset($answer['label']) ? (string) $answer['label'] : __('Question', 'tomschooloflife-plugin');
                $value = isset($answer['display_value']) ? $answer['display_value'] : '';

                if (is_array($value)) {
                    $value = implode(', ', array_map('strval', $value));
                }

                $answers[$label] = (string) $value;
            }
        }

        if (!$answers) {
            $answers = array(
                __('Professional goals', 'tomschooloflife-plugin') => $submission['professional_goals'],
                __('Personal goals', 'tomschooloflife-plugin') => $submission['personal_goals'],
                __('Why TSoL', 'tomschooloflife-plugin') => $submission['tsol_reason'],
            );
        }

        echo '<dl class="tsol-submission-answers">';
        foreach ($answers as $label => $answer) {
            echo '<dt>' . esc_html($label) . '</dt>';
            echo '<dd>' . esc_html($answer) . '</dd>';
        }
        echo '</dl>';
    }

    private function count_unassigned_submissions($submissions) {
        $count = 0;

        foreach ($submissions as $submission) {
            if (!$this->repository->user_has_accountability_group($submission['user']->ID)) {
                $count++;
            }
        }

        return $count;
    }
}
