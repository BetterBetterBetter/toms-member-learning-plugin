<?php
/**
 * Backfills every user-facing legacy body link into protected Resources.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Library_Resource_Backfill {

    const VERSION = '20260820.1';
    const STATE_OPTION = 'tsol_library_resource_backfill_state';
    const APPLY_CONFIRMATION = 'backfill-all-legacy-body-links-as-resources';
    const ARCHIVE_META = '_tsol_library_resources_before_legacy_link_backfill_20260820';

    public function preview() {
        $plan = $this->plan();
        return array(
            'schema_version' => self::VERSION,
            'source_bodies' => $plan['source_bodies'],
            'destinations' => count($plan['destinations']),
            'expected_resources' => $plan['expected_resources'],
            'missing_resources' => $plan['missing_resources'],
            'changed_destinations' => $plan['changed_destinations'],
        );
    }

    public function status() {
        $state = get_option(self::STATE_OPTION, array());
        return array_merge($this->preview(), array(
            'phase' => is_array($state) ? (string) ($state['phase'] ?? 'not_started') : 'not_started',
        ));
    }

    public function apply() {
        $this->assert_environment();
        $plan = $this->plan();
        $state = array(
            'schema_version' => self::VERSION,
            'phase' => 'applying',
            'started_at' => gmdate('c'),
            'changed_post_ids' => array(),
        );
        update_option(self::STATE_OPTION, $state, false);

        try {
            foreach ($plan['destinations'] as $destination) {
                if (empty($destination['missing'])) {
                    continue;
                }

                $post_id = (int) $destination['post_id'];
                $existing = get_post_meta($post_id, TSOL_Library_Content_Model::META_RESOURCES, true);
                $existing = is_array($existing) ? $existing : array();
                if (!metadata_exists('post', $post_id, self::ARCHIVE_META)) {
                    add_post_meta($post_id, self::ARCHIVE_META, $existing, true);
                }

                update_post_meta(
                    $post_id,
                    TSOL_Library_Content_Model::META_RESOURCES,
                    $this->merge_resources($existing, $destination['expected'])
                );
                clean_post_cache($post_id);
                $state['changed_post_ids'][] = $post_id;
                update_option(self::STATE_OPTION, $state, false);
            }

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
            throw new RuntimeException('The legacy resource-link backfill is not applied.');
        }

        $preview = $this->preview();
        if (0 !== (int) $preview['missing_resources'] || 0 !== (int) $preview['changed_destinations']) {
            throw new RuntimeException(sprintf(
                'Legacy link audit still finds %d missing resources across %d destinations.',
                (int) $preview['missing_resources'],
                (int) $preview['changed_destinations']
            ));
        }

        return array_merge($preview, array(
            'phase' => 'applied',
            'changed_post_ids' => array_values(array_map('intval', (array) ($state['changed_post_ids'] ?? array()))),
        ));
    }

    private function plan() {
        $destinations = array();
        $source_bodies = 0;
        $expected_resources = 0;

        $items = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'suppress_filters' => true,
        ));
        foreach ($items as $item) {
            $source_id = (int) get_post_meta($item->ID, TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID, true);
            $source = $source_id > 0 ? get_post($source_id) : null;
            if (!$source instanceof WP_Post) {
                continue;
            }
            $source_bodies++;
            $expected = TSOL_Library_Resource_Normalizer::extract_from_content($source->post_content);
            $expected_resources += count($expected);
            $this->add_destination($destinations, $item->ID, $expected);
        }

        $courses = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::COURSE_POST_TYPE,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'suppress_filters' => true,
        ));
        foreach ($courses as $course) {
            $source_id = (int) get_post_meta($course->ID, TSOL_Library_Content_Model::META_LEGACY_SOURCE_ID, true);
            $source = $source_id > 0 ? get_post($source_id) : null;
            if (!$source instanceof WP_Post) {
                continue;
            }
            $source_bodies++;
            $expected = TSOL_Library_Resource_Normalizer::extract_from_content($source->post_content);
            if (empty($expected)) {
                continue;
            }
            $lesson_id = $this->first_course_lesson_id($course->ID);
            if ($lesson_id <= 0) {
                throw new RuntimeException(sprintf('Course %d has legacy resources but no destination lesson.', (int) $course->ID));
            }
            $expected_resources += count($expected);
            $this->add_destination($destinations, $lesson_id, $expected);
        }

        $missing_resources = 0;
        $changed_destinations = 0;
        foreach ($destinations as &$destination) {
            $existing = get_post_meta($destination['post_id'], TSOL_Library_Content_Model::META_RESOURCES, true);
            $existing = is_array($existing) ? $existing : array();
            $have = array();
            foreach ($existing as $resource) {
                $have[$this->url_identity($resource['url'] ?? '')] = true;
            }
            $destination['missing'] = array_values(array_filter($destination['expected'], function ($resource) use ($have) {
                return !isset($have[$this->url_identity($resource['url'] ?? '')]);
            }));
            if (!empty($destination['missing'])) {
                $changed_destinations++;
                $missing_resources += count($destination['missing']);
            }
        }
        unset($destination);

        return array(
            'source_bodies' => $source_bodies,
            'destinations' => array_values($destinations),
            'expected_resources' => $expected_resources,
            'missing_resources' => $missing_resources,
            'changed_destinations' => $changed_destinations,
        );
    }

    private function add_destination(&$destinations, $post_id, $expected) {
        $post_id = absint($post_id);
        if (!isset($destinations[$post_id])) {
            $destinations[$post_id] = array('post_id' => $post_id, 'expected' => array());
        }
        $seen = array();
        foreach ($destinations[$post_id]['expected'] as $resource) {
            $seen[$this->url_identity($resource['url'] ?? '')] = true;
        }
        foreach ($expected as $resource) {
            $identity = $this->url_identity($resource['url'] ?? '');
            if ('' === $identity || isset($seen[$identity])) {
                continue;
            }
            $destinations[$post_id]['expected'][] = $resource;
            $seen[$identity] = true;
        }
    }

    private function first_course_lesson_id($course_id) {
        $lessons = get_posts(array(
            'post_type' => TSOL_Library_Content_Model::ITEM_POST_TYPE,
            'post_status' => array('publish', 'draft', 'private', 'pending', 'future'),
            'numberposts' => -1,
            'meta_key' => TSOL_Library_Content_Model::META_COURSE_ID,
            'meta_value' => absint($course_id),
            'suppress_filters' => true,
        ));
        usort($lessons, static function ($left, $right) {
            $left_order = array(
                (int) get_post_meta($left->ID, TSOL_Library_Content_Model::META_SECTION_POSITION, true),
                (int) get_post_meta($left->ID, TSOL_Library_Content_Model::META_POSITION, true),
                (int) $left->ID,
            );
            $right_order = array(
                (int) get_post_meta($right->ID, TSOL_Library_Content_Model::META_SECTION_POSITION, true),
                (int) get_post_meta($right->ID, TSOL_Library_Content_Model::META_POSITION, true),
                (int) $right->ID,
            );
            return $left_order <=> $right_order;
        });
        return !empty($lessons) ? (int) $lessons[0]->ID : 0;
    }

    private function merge_resources($existing, $expected) {
        $resources = TSOL_Library_Content_Model::sanitize_resources($existing);
        $seen = array();
        foreach ($resources as $resource) {
            $seen[$this->url_identity($resource['url'])] = true;
        }
        foreach ($expected as $resource) {
            $identity = $this->url_identity($resource['url'] ?? '');
            if ('' === $identity || isset($seen[$identity])) {
                continue;
            }
            $resource['key'] = 'legacy-resource-' . (count($resources) + 1);
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

    private function url_identity($url) {
        $url = esc_url_raw((string) $url);
        return '' !== $url ? strtolower(untrailingslashit($url)) : '';
    }

    private function assert_environment() {
        if ('tomschooloflife.test' !== strtolower((string) wp_parse_url(home_url(), PHP_URL_HOST))) {
            throw new RuntimeException('The legacy resource-link backfill is restricted to the local working clone.');
        }
    }
}
