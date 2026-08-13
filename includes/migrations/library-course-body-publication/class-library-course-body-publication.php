<?php
/**
 * Moves legacy Course-body resources into protected lesson metadata.
 *
 * The native Course editor becomes public landing-page copy after this
 * migration. Legacy imports used that editor for member-only downloads and
 * links, so those values must be relocated before the public projection is
 * synchronized.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Course_Body_Publication {

    const VERSION = '20260813.1';
    const STATE_OPTION = 'tsol_library_course_body_publication_state';
    const APPLY_CONFIRMATION = 'publish-native-course-bodies-and-move-protected-resources';
    const ARCHIVE_BODY_META = '_tsol_library_course_body_before_publication_20260813';
    const ARCHIVE_RESOURCES_META = '_tsol_library_resources_before_course_body_publication_20260813';
    const ARCHIVE_DESCRIPTION_META = '_tsol_library_public_description_before_native_body_20260813';
    const RETIRED_DESCRIPTION_META = '_tsol_library_course_public_description';

    public function status() {
        $state = get_option(self::STATE_OPTION, array());
        return array(
            'schema_version' => self::VERSION,
            'phase' => is_array($state) ? (string) ($state['phase'] ?? 'not_started') : 'not_started',
            'targets' => $this->target_statuses(),
            'retired_description_rows' => $this->retired_description_count(),
        );
    }

    public function apply() {
        $state = get_option(self::STATE_OPTION, array());
        if (is_array($state) && 'applied' === (string) ($state['phase'] ?? '')) {
            return $this->verify();
        }

        $targets = $this->resolved_targets();
        $state = array(
            'schema_version' => self::VERSION,
            'phase' => 'applying',
            'started_at' => gmdate('c'),
        );
        update_option(self::STATE_OPTION, $state, false);

        try {
            foreach ($targets as $target) {
                $course = $target['course'];
                $lesson = $target['lesson'];
                $body = (string) $course->post_content;
                $resources = get_post_meta($lesson->ID, TSOL_Library_Content_Model::META_RESOURCES, true);
                $resources = is_array($resources) ? $resources : array();

                if (!metadata_exists('post', $course->ID, self::ARCHIVE_BODY_META)) {
                    add_post_meta($course->ID, self::ARCHIVE_BODY_META, $body, true);
                }
                if (!metadata_exists('post', $lesson->ID, self::ARCHIVE_RESOURCES_META)) {
                    add_post_meta($lesson->ID, self::ARCHIVE_RESOURCES_META, $resources, true);
                }

                update_post_meta(
                    $lesson->ID,
                    TSOL_Library_Content_Model::META_RESOURCES,
                    $this->merge_resources($resources, $target['resources'])
                );

                if ('' !== trim(wp_strip_all_tags($body))) {
                    $updated = wp_update_post(array(
                        'ID' => $course->ID,
                        'post_content' => '',
                    ), true);
                    if (is_wp_error($updated)) {
                        throw new RuntimeException($updated->get_error_message());
                    }
                }
            }

            $this->retire_duplicate_descriptions();
            $state['phase'] = 'applied';
            $state['applied_at'] = gmdate('c');
            update_option(self::STATE_OPTION, $state, false);
        } catch (Throwable $exception) {
            $state['phase'] = 'failed';
            $state['failure'] = $exception->getMessage();
            $state['failed_at'] = gmdate('c');
            update_option(self::STATE_OPTION, $state, false);
            throw $exception;
        }

        return $this->verify();
    }

    public function verify() {
        $state = get_option(self::STATE_OPTION, array());
        if (!is_array($state) || 'applied' !== (string) ($state['phase'] ?? '')) {
            throw new RuntimeException('The Course body publication migration is not applied.');
        }

        $targets = $this->resolved_targets();
        foreach ($targets as $target) {
            if ('' !== trim(wp_strip_all_tags((string) $target['course']->post_content))) {
                throw new RuntimeException(sprintf(
                    'Protected legacy content remains in Course %d.',
                    (int) $target['course']->ID
                ));
            }
            if (!metadata_exists('post', $target['course']->ID, self::ARCHIVE_BODY_META)) {
                throw new RuntimeException(sprintf('Course %d has no private body archive.', (int) $target['course']->ID));
            }
            if (!metadata_exists('post', $target['lesson']->ID, self::ARCHIVE_RESOURCES_META)) {
                throw new RuntimeException(sprintf('Lesson %d has no private resource archive.', (int) $target['lesson']->ID));
            }

            $resources = get_post_meta($target['lesson']->ID, TSOL_Library_Content_Model::META_RESOURCES, true);
            $urls = array_map(static function ($resource) {
                return strtolower(untrailingslashit((string) ($resource['url'] ?? '')));
            }, is_array($resources) ? $resources : array());
            foreach ($this->resolved_resource_specs($target['resources']) as $resource) {
                if (!in_array(strtolower(untrailingslashit((string) $resource['url'])), $urls, true)) {
                    throw new RuntimeException(sprintf(
                        'Protected resource %s is missing from lesson %d.',
                        (string) $resource['url'],
                        (int) $target['lesson']->ID
                    ));
                }
            }
        }

        if (0 !== $this->retired_description_count()) {
            throw new RuntimeException('Retired duplicate Course descriptions remain in WordPress metadata.');
        }

        return $this->status();
    }

    private function target_statuses() {
        $statuses = array();
        foreach ($this->target_specs() as $spec) {
            $course = $this->find_by_migration_key($spec['course_key'], TSOL_Library_Content_Model::COURSE_POST_TYPE, false);
            $lesson = $this->find_by_migration_key($spec['lesson_key'], TSOL_Library_Content_Model::ITEM_POST_TYPE, false);
            $statuses[] = array(
                'course_key' => $spec['course_key'],
                'course_id' => $course instanceof WP_Post ? (int) $course->ID : 0,
                'lesson_id' => $lesson instanceof WP_Post ? (int) $lesson->ID : 0,
                'course_body_empty' => $course instanceof WP_Post
                    ? '' === trim(wp_strip_all_tags((string) $course->post_content))
                    : null,
                'body_archived' => $course instanceof WP_Post
                    ? metadata_exists('post', $course->ID, self::ARCHIVE_BODY_META)
                    : false,
            );
        }
        return $statuses;
    }

    private function resolved_targets() {
        $targets = array();
        foreach ($this->target_specs() as $spec) {
            $course = $this->find_by_migration_key($spec['course_key'], TSOL_Library_Content_Model::COURSE_POST_TYPE);
            $lesson = $this->find_by_migration_key($spec['lesson_key'], TSOL_Library_Content_Model::ITEM_POST_TYPE);
            if ((int) get_post_meta($lesson->ID, TSOL_Library_Content_Model::META_COURSE_ID, true) !== (int) $course->ID) {
                throw new RuntimeException(sprintf(
                    'Lesson %d is no longer attached to Course %d.',
                    (int) $lesson->ID,
                    (int) $course->ID
                ));
            }
            $spec['course'] = $course;
            $spec['lesson'] = $lesson;
            $targets[] = $spec;
        }
        return $targets;
    }

    private function find_by_migration_key($key, $post_type, $required = true) {
        $posts = get_posts(array(
            'post_type' => $post_type,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => 2,
            'meta_key' => TSOL_Library_Content_Model::META_MIGRATION_KEY,
            'meta_value' => (string) $key,
            'suppress_filters' => true,
        ));
        if (1 === count($posts)) {
            return $posts[0];
        }
        if (!$required && empty($posts)) {
            return null;
        }
        throw new RuntimeException(sprintf(
            'Expected exactly one %s with migration key %s; found %d.',
            (string) $post_type,
            (string) $key,
            count($posts)
        ));
    }

    private function merge_resources($existing, $required) {
        $resources = TSOL_Library_Content_Model::sanitize_resources($existing);
        $seen = array();
        foreach ($resources as $resource) {
            $seen[strtolower(untrailingslashit((string) $resource['url']))] = true;
        }

        foreach ($this->resolved_resource_specs($required) as $resource) {
            $identity = strtolower(untrailingslashit((string) $resource['url']));
            if (isset($seen[$identity])) {
                continue;
            }
            $resource['position'] = count($resources) + 1;
            $resources[] = $resource;
            $seen[$identity] = true;
        }

        foreach ($resources as $index => &$resource) {
            $resource['position'] = $index + 1;
        }
        unset($resource);
        return TSOL_Library_Content_Model::sanitize_resources($resources);
    }

    private function resolved_resource_specs($resources) {
        $resolved = array();
        foreach ($resources as $index => $resource) {
            $attachment_id = absint($resource['attachment_id'] ?? 0);
            $url = $attachment_id > 0 ? wp_get_attachment_url($attachment_id) : (string) ($resource['url'] ?? '');
            if (!$url) {
                throw new RuntimeException(sprintf('Protected resource %d has no resolvable URL.', $index + 1));
            }
            $resolved[] = array(
                'key' => sanitize_key((string) ($resource['key'] ?? 'protected-resource-' . ($index + 1))),
                'type' => $attachment_id > 0 ? 'download' : 'link',
                'label' => sanitize_text_field((string) ($resource['label'] ?? 'Resource ' . ($index + 1))),
                'url' => esc_url_raw($url),
                'attachment_id' => $attachment_id,
                'position' => $index + 1,
            );
        }
        return $resolved;
    }

    private function retire_duplicate_descriptions() {
        $courses = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::COURSE_POST_TYPE,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'suppress_filters' => true,
        ));
        foreach ($courses as $course) {
            if (metadata_exists('post', $course->ID, self::RETIRED_DESCRIPTION_META)) {
                if (!metadata_exists('post', $course->ID, self::ARCHIVE_DESCRIPTION_META)) {
                    add_post_meta(
                        $course->ID,
                        self::ARCHIVE_DESCRIPTION_META,
                        get_post_meta($course->ID, self::RETIRED_DESCRIPTION_META, true),
                        true
                    );
                }
                delete_post_meta($course->ID, self::RETIRED_DESCRIPTION_META);
            }
        }
    }

    private function retired_description_count() {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
            self::RETIRED_DESCRIPTION_META
        ));
    }

    private function target_specs() {
        return array(
            array(
                'course_key' => 'course-social-media-masterclass',
                'lesson_key' => 'lesson-103790',
                'resources' => array(
                    array('key' => 'course-bonuses', 'label' => 'Course bonuses', 'url' => 'https://www.colesmithwrites.com/mastery'),
                    array('key' => 'tom-social-media-strategy', 'label' => "Tom's social media strategy", 'url' => 'https://somup.com/cOfte0VVjBL'),
                ),
            ),
            array(
                'course_key' => 'course-the-ai-advantage',
                'lesson_key' => 'lesson-103707',
                'resources' => array(
                    array('key' => 'ai-automation-recipes', 'label' => 'Done-for-You AI Automation Recipes Pack', 'attachment_id' => 103715),
                    array('key' => 'weekly-ai-prompts', 'label' => '20 AI Prompts Every Normal Person Should Use Weekly', 'attachment_id' => 103716),
                    array('key' => 'ai-safety-guide', 'label' => "The Non-Techie's Guide to Staying Safe with AI", 'attachment_id' => 103717),
                    array('key' => 'ai-toolkit', 'label' => 'The AI Toolkit', 'attachment_id' => 103718),
                    array('key' => 'ai-party-tricks', 'label' => 'AI Party Tricks', 'attachment_id' => 103719),
                ),
            ),
            array(
                'course_key' => 'course-tax-strategy-intensive',
                'lesson_key' => 'lesson-103666',
                'resources' => array(
                    array('key' => 'ultimate-tax-plan', 'label' => 'Ultimate Tax Plan', 'attachment_id' => 103681),
                    array('key' => 'tax-code-engineering', 'label' => 'Tax Code Engineering', 'attachment_id' => 103680),
                    array('key' => 'tax-load-tool', 'label' => 'Tax Load tool', 'url' => 'https://yourtaxload.com/'),
                    array('key' => 'business-expense-calculator', 'label' => 'Business Expense Reimbursement Calculator', 'url' => 'https://accountable-plan.replit.app/'),
                    array('key' => 'bookkeeping-buddy', 'label' => 'Bookkeeping Buddy (use code WOODS)', 'url' => 'https://usebookkeepingbuddy.com/'),
                ),
            ),
        );
    }
}
