<?php
/** Read-only WP-CLI report of every MemberPress rule affecting TSOL Library content. */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this report through WP-CLI.');
}

$configuration = get_option(MemberLibrary_Access_Groups::OPTION_NAME, array());
$managed_ids = array_fill_keys(array_map('intval', (array) ($configuration['source_rule_ids'] ?? array())), true);
$targets = get_posts(array(
    'post_type' => MemberLibrary_Content_Model::post_types(),
    'post_status' => array_values(get_post_stati()),
    'posts_per_page' => -1,
    'fields' => 'ids',
    'suppress_filters' => true,
));
$rules = array();
foreach ($targets as $target_id) {
    $authorization_id = (int) get_post_meta((int) $target_id, MemberLibrary_Content_Model::META_AUTHORIZATION_POST_ID, true);
    $authorization_id = $authorization_id > 0 ? $authorization_id : (int) $target_id;
    $authorization_post = get_post($authorization_id);
    if (!$authorization_post instanceof WP_Post) {
        continue;
    }
    foreach ((array) MeprRule::get_rules($authorization_post) as $rule) {
        $rule_id = (int) $rule->ID;
        if ('publish' !== get_post_status($rule_id)) {
            continue;
        }
        if (!isset($rules[$rule_id])) {
            $conditions = array();
            foreach ((array) $rule->access_conditions() as $condition) {
                $conditions[] = array(
                    'type' => (string) $condition->access_type,
                    'operator' => (string) $condition->access_operator,
                    'condition' => (string) $condition->access_condition,
                );
            }
            $rules[$rule_id] = array(
                'id' => $rule_id,
                'title' => get_the_title($rule_id),
                'managed' => isset($managed_ids[$rule_id]) || MemberLibrary_Access_Groups::OWNER_VALUE === (string) get_post_meta($rule_id, MemberLibrary_Access_Groups::META_OWNER, true),
                'memberpress_type' => (string) $rule->mepr_type,
                'memberpress_content' => (string) $rule->mepr_content,
                'conditions' => $conditions,
                'authorization_posts' => array(),
                'library_target_count' => 0,
            );
        }
        $rules[$rule_id]['authorization_posts'][$authorization_id] = array(
            'id' => $authorization_id,
            'type' => (string) $authorization_post->post_type,
            'title' => (string) $authorization_post->post_title,
        );
        $rules[$rule_id]['library_target_count']++;
    }
}
ksort($rules, SORT_NUMERIC);
foreach ($rules as &$rule) {
    $rule['authorization_posts'] = array_values($rule['authorization_posts']);
}
unset($rule);
$unmanaged = array_values(array_filter($rules, static function ($rule) {
    return empty($rule['managed']);
}));

WP_CLI::line(wp_json_encode(array(
    'scope' => 'tsol-library-access-rule-ownership',
    'library_targets' => count($targets),
    'effective_rules' => count($rules),
    'managed_rules' => count($rules) - count($unmanaged),
    'unmanaged_rules' => count($unmanaged),
    'unmanaged' => $unmanaged,
), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
