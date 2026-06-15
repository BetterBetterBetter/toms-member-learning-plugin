<?php
/**
 * Accountability modal group matcher.
 */

if (!defined('ABSPATH')) {
    exit;
}

class TSOL_Accountability_Modal_Matcher {

    private $repository;
    private $gemini;

    public function __construct(TSOL_Accountability_Modal_Repository $repository, TSOL_Gemini_Client $gemini) {
        $this->repository = $repository;
        $this->gemini = $gemini;
    }

    public function recommend($intake, $selected_event_ids) {
        $candidates = $this->repository->get_candidate_groups_for_calls($selected_event_ids);
        $all_groups = $this->format_groups($this->repository->get_all_joinable_groups());

        if (!$candidates) {
            return array(
                'mode' => 'show_all',
                'has_strong_fit' => false,
                'recommendations' => array(),
                'all_groups' => $all_groups,
            );
        }

        if (!TSOL_Accountability_Modal_Settings::ai_matching_enabled() || !$this->gemini->is_configured()) {
            return $this->availability_result($candidates, $all_groups);
        }

        $cache_key = $this->get_cache_key($intake, $selected_event_ids, $candidates);
        $cached = get_transient($cache_key);

        if (is_array($cached)) {
            return $cached;
        }

        $response = $this->gemini->generate_json(
            $this->build_prompt($intake, array_slice($candidates, 0, 25)),
            $this->get_response_schema()
        );

        if (is_wp_error($response)) {
            return $this->availability_result($candidates, $all_groups);
        }

        $recommendations = $this->normalize_ai_recommendations($response, $candidates);

        if (!$recommendations) {
            return $this->availability_result($candidates, $all_groups);
        }

        $recommendations = $this->fill_recommendations($recommendations, $candidates);

        $threshold = (float) apply_filters('tsol_site_accountability_fit_threshold', TSOL_Accountability_Modal_Settings::get_fit_threshold());
        $best_score = isset($recommendations[0]['fit_score']) ? (float) $recommendations[0]['fit_score'] : 0;
        $has_strong_fit = $best_score >= $threshold;

        $result = array(
            'mode' => $has_strong_fit ? 'ai' : 'show_all',
            'has_strong_fit' => $has_strong_fit,
            'recommendations' => array_slice($recommendations, 0, 3),
            'all_groups' => $all_groups,
        );

        set_transient($cache_key, $result, $this->get_cache_ttl());

        return $result;
    }

    private function availability_result($candidates, $all_groups) {
        $recommendations = array();

        foreach (array_slice($candidates, 0, 3) as $candidate) {
            $recommendations[] = array(
                'group_id' => (int) $candidate['group_id'],
                'event_id' => (int) $candidate['event_id'],
                'label' => (string) $candidate['label'],
                'group_name' => (string) $candidate['group_name'],
                'facilitator' => isset($candidate['facilitator']) ? (string) $candidate['facilitator'] : '',
                'reason' => __('This group matches a weekly call time you said you can attend.', 'tomschooloflife-plugin'),
                'fit_score' => null,
            );
        }

        return array(
            'mode' => 'availability',
            'has_strong_fit' => true,
            'recommendations' => $recommendations,
            'all_groups' => $all_groups,
        );
    }

    private function normalize_ai_recommendations($response, $candidates) {
        $candidate_map = array();

        foreach ($candidates as $candidate) {
            $candidate_map[(int) $candidate['group_id']] = $candidate;
        }

        $rows = isset($response['recommendations']) && is_array($response['recommendations'])
            ? $response['recommendations']
            : array();
        $recommendations = array();

        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['group_id'])) {
                continue;
            }

            $group_id = (int) $row['group_id'];

            if (!isset($candidate_map[$group_id])) {
                continue;
            }

            $candidate = $candidate_map[$group_id];
            $fit_score = isset($row['fit_score']) && is_numeric($row['fit_score'])
                ? max(0, min(1, (float) $row['fit_score']))
                : 0;
            $reason = isset($row['reason']) ? sanitize_text_field((string) $row['reason']) : '';

            $recommendations[] = array(
                'group_id' => $group_id,
                'event_id' => (int) $candidate['event_id'],
                'label' => (string) $candidate['label'],
                'group_name' => (string) $candidate['group_name'],
                'facilitator' => isset($candidate['facilitator']) ? (string) $candidate['facilitator'] : '',
                'reason' => $reason,
                'fit_score' => $fit_score,
            );
        }

        usort($recommendations, function($left, $right) {
            return $right['fit_score'] <=> $left['fit_score'];
        });

        return $recommendations;
    }

    private function fill_recommendations($recommendations, $candidates) {
        $used_group_ids = array();

        foreach ($recommendations as $recommendation) {
            $used_group_ids[(int) $recommendation['group_id']] = true;
        }

        foreach ($candidates as $candidate) {
            if (count($recommendations) >= 3) {
                break;
            }

            $group_id = (int) $candidate['group_id'];

            if (isset($used_group_ids[$group_id])) {
                continue;
            }

            $recommendations[] = array(
                'group_id' => $group_id,
                'event_id' => (int) $candidate['event_id'],
                'label' => (string) $candidate['label'],
                'group_name' => (string) $candidate['group_name'],
                'facilitator' => isset($candidate['facilitator']) ? (string) $candidate['facilitator'] : '',
                'reason' => __('This group matches a weekly call time you said you can attend.', 'tomschooloflife-plugin'),
                'fit_score' => 0,
            );
        }

        return $recommendations;
    }

    private function format_groups($groups) {
        $formatted = array();

        foreach ((array) $groups as $group) {
            $formatted[] = array(
                'group_id' => (int) $group['group_id'],
                'event_id' => (int) $group['event_id'],
                'label' => (string) $group['label'],
                'group_name' => (string) $group['group_name'],
                'facilitator' => isset($group['facilitator']) ? (string) $group['facilitator'] : '',
                'reason' => '',
                'fit_score' => null,
            );
        }

        return $formatted;
    }

    private function build_prompt($intake, $candidates) {
        $profile = array();
        $answers = isset($intake['answers']) && is_array($intake['answers']) ? $intake['answers'] : array();

        foreach ($answers as $answer) {
            if (!is_array($answer) || (isset($answer['type']) && $answer['type'] === 'accountability_calls')) {
                continue;
            }

            $value = isset($answer['display_value']) ? $answer['display_value'] : '';

            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            $value = trim((string) $value);

            if ($value === '') {
                continue;
            }

            $profile[] = array(
                'label' => isset($answer['label']) ? (string) $answer['label'] : __('Question', 'tomschooloflife-plugin'),
                'value' => $value,
            );
        }

        $groups = array();

        foreach ($candidates as $candidate) {
            $groups[] = array(
                'group_id' => (int) $candidate['group_id'],
                'name' => (string) $candidate['group_name'],
                'bio' => (string) $candidate['bio'],
            );
        }

        return implode("\n\n", array(
            'You match a member to the best accountability group.',
            'Availability is already guaranteed. Rank only the provided groups by how well the member profile fits each group description, facilitator, and call context.',
            'Be honest with low fit_score values when nothing is a strong fit. Use only provided group_id values. Reasons must be member-facing, second person, and concise.',
            'Member profile JSON: ' . wp_json_encode($profile),
            'Candidate groups JSON: ' . wp_json_encode($groups),
        ));
    }

    private function get_response_schema() {
        return array(
            'type' => 'object',
            'properties' => array(
                'recommendations' => array(
                    'type' => 'array',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'group_id' => array(
                                'type' => 'integer',
                            ),
                            'fit_score' => array(
                                'type' => 'number',
                                'minimum' => 0,
                                'maximum' => 1,
                            ),
                            'reason' => array(
                                'type' => 'string',
                                'description' => 'Member-facing reason, second person, under 240 characters.',
                            ),
                        ),
                        'required' => array('group_id', 'fit_score', 'reason'),
                        'additionalProperties' => false,
                    ),
                ),
            ),
            'required' => array('recommendations'),
            'additionalProperties' => false,
        );
    }

    private function get_cache_key($intake, $selected_event_ids, $candidates) {
        $signature = array();

        foreach ($candidates as $candidate) {
            $signature[] = array(
                'group_id' => (int) $candidate['group_id'],
                'event_id' => (int) $candidate['event_id'],
                'bio' => (string) $candidate['bio'],
            );
        }

        $hash = md5(wp_json_encode(array(
            'user_id' => get_current_user_id(),
            'intake' => $intake,
            'selected_event_ids' => array_values(array_unique(array_filter(array_map('absint', (array) $selected_event_ids)))),
            'candidates' => $signature,
        )));

        return 'tsol_accountability_match_' . $hash;
    }

    private function get_cache_ttl() {
        return (int) apply_filters('tsol_site_accountability_match_cache_ttl', 10 * MINUTE_IN_SECONDS);
    }
}
