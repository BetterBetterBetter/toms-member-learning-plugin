<?php
/**
 * Compare MemberPress's legacy runtime decisions with the current TSOL policy.
 *
 * This contract deliberately emits aggregate counts only. It does not print
 * user IDs, logins, email addresses, membership IDs, or the member exception.
 */

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run this contract through WP-CLI.');
}

global $wpdb;

$failures = array();
$assert = static function ($condition, $message) use (&$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
};

$migration = new TSOL_Library_Access_Rules_Migration();
$verification = $migration->verify();
$plan_method = new ReflectionMethod(TSOL_Library_Access_Rules_Migration::class, 'plan');
$state_method = new ReflectionMethod(TSOL_Library_Access_Rules_Migration::class, 'state');
$state = $state_method->invoke($migration);
$plan = $plan_method->invoke($migration, (array) $state['previous_authorization']);

$condition_key = static function ($condition) {
    return implode('|', array(
        (string) $condition['access_type'],
        (string) $condition['access_operator'],
        (string) $condition['access_condition'],
    ));
};

$native_conditions_for = static function ($policy_keys) use ($plan, $condition_key) {
    $conditions = array();
    foreach ((array) $policy_keys as $policy_key) {
        foreach ((array) $plan['rules'][$policy_key]['conditions'] as $condition) {
            $conditions[$condition_key($condition)] = $condition;
        }
    }
    ksort($conditions, SORT_STRING);
    return $conditions;
};

$conditions_allow = static function ($conditions, $context) {
    if (!empty($context['is_admin'])) {
        return true;
    }
    foreach ($conditions as $condition) {
        if ('membership' === $condition['access_type']
            && in_array((int) $condition['access_condition'], $context['memberships'], true)
        ) {
            return true;
        }
        if ('member' === $condition['access_type']
            && (string) $condition['access_condition'] === (string) $context['login']
        ) {
            return true;
        }
        if ('role' === $condition['access_type']
            && in_array((string) $condition['access_condition'], $context['roles'], true)
        ) {
            return true;
        }
        if ('capability' === $condition['access_type']
            && in_array((string) $condition['access_condition'], $context['capabilities'], true)
        ) {
            return true;
        }
    }
    return false;
};

$groups = array();
$native_member_condition_count = 0;
foreach ($plan['target_ids'] as $target_id) {
    $source_id = (int) $plan['baseline_authorization'][$target_id];
    $source = get_post($source_id);
    $assert($source instanceof WP_Post, 'A legacy authorization source is missing.');
    if (!$source instanceof WP_Post) {
        continue;
    }
    $source_rule_ids = array_map(static function ($rule) {
        return (int) $rule->ID;
    }, MeprRule::get_rules($source));
    sort($source_rule_ids, SORT_NUMERIC);
    $conditions = $native_conditions_for($plan['mapping'][$target_id]);
    foreach ($conditions as $condition) {
        if ('member' === (string) $condition['access_type']) {
            $native_member_condition_count++;
        }
    }
    $group_key = implode(',', $source_rule_ids) . ':' . hash('sha256', serialize(array_keys($conditions)));
    if (!isset($groups[$group_key])) {
        $groups[$group_key] = array(
            'source' => $source,
            'conditions' => $conditions,
            'target_count' => 0,
            'allow_to_allow' => 0,
            'allow_to_deny' => 0,
            'deny_to_allow' => 0,
            'deny_to_deny' => 0,
            'administrators_checked' => 0,
            'administrators_allowed' => 0,
            'wordpress_only_non_admins_checked' => 0,
            'wordpress_only_non_admins_denied' => 0,
        );
    }
    $groups[$group_key]['target_count']++;
}

$transaction_user_ids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->prefix}mepr_transactions WHERE user_id > 0");
$subscription_user_ids = $wpdb->get_col("SELECT DISTINCT user_id FROM {$wpdb->prefix}mepr_subscriptions WHERE user_id > 0");
$memberpress_user_lookup = array_fill_keys(array_map('intval', array_merge($transaction_user_ids, $subscription_user_ids)), true);
$user_ids = array_map('intval', $wpdb->get_col("SELECT ID FROM {$wpdb->users} ORDER BY ID"));
$administrator_count = 0;
$wordpress_only_non_admin_count = 0;

foreach ($user_ids as $user_id) {
    $is_admin = user_can($user_id, 'manage_options');
    $wp_user = get_user_by('id', $user_id);
    $is_wordpress_only_non_admin = !$is_admin && !isset($memberpress_user_lookup[$user_id]);
    $member = $is_admin ? null : new MeprUser($user_id);
    $context = array(
        'is_admin' => $is_admin,
        'login' => $wp_user ? (string) $wp_user->user_login : '',
        'roles' => $wp_user ? (array) $wp_user->roles : array(),
        'capabilities' => $wp_user ? array_keys(array_filter((array) $wp_user->allcaps)) : array(),
        'memberships' => $member ? array_map('intval', (array) $member->active_product_subscriptions()) : array(),
    );
    if ($is_admin) {
        $administrator_count++;
    }
    if ($is_wordpress_only_non_admin) {
        $wordpress_only_non_admin_count++;
    }

    foreach ($groups as &$group) {
        $legacy_allowed = $is_admin || !MeprRule::is_locked_for_user($member, $group['source']);
        $native_allowed = $conditions_allow($group['conditions'], $context);
        $transition = ($legacy_allowed ? 'allow' : 'deny') . '_to_' . ($native_allowed ? 'allow' : 'deny');
        $group[$transition]++;
        if ($is_admin) {
            $group['administrators_checked']++;
            if ($native_allowed) {
                $group['administrators_allowed']++;
            }
        }
        if ($is_wordpress_only_non_admin) {
            $group['wordpress_only_non_admins_checked']++;
            if (!$native_allowed) {
                $group['wordpress_only_non_admins_denied']++;
            }
        }
    }
    unset($group);
}

$summary = array(
    'allow_to_allow' => 0,
    'allow_to_deny' => 0,
    'deny_to_allow' => 0,
    'deny_to_deny' => 0,
);
foreach ($groups as $group) {
    foreach (array_keys($summary) as $transition) {
        $summary[$transition] += (int) $group[$transition] * (int) $group['target_count'];
    }
    $assert(
        (int) $group['administrators_checked'] === (int) $group['administrators_allowed'],
        'A normalized policy denied an administrator.'
    );
    $assert(
        (int) $group['wordpress_only_non_admins_checked'] === (int) $group['wordpress_only_non_admins_denied'],
        'A normalized policy exposed protected content to a WordPress-only non-administrator.'
    );
}

$assert(in_array($verification['phase'], array('staged', 'activated'), true), 'The access-rule migration is not staged or activated.');
$assert((int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}") === count($user_ids), 'The runtime matrix did not cover the complete current local user population.');
$assert(154 === count($plan['target_ids']), 'The runtime matrix did not cover every TSOL Library target.');
$assert(0 === $summary['allow_to_deny'], 'The TSOL policy removes access granted by MemberPress at runtime.');
$assert(18 === $summary['deny_to_allow'], 'The approved Social Media Course-root correction changed.');
$assert($administrator_count > 0, 'The runtime matrix did not cover an administrator.');
$assert($wordpress_only_non_admin_count > 0, 'The runtime matrix did not cover a WordPress-only non-administrator.');
$assert($native_member_condition_count > 0, 'The AI Advantage member-specific exception was not present in the native policy.');

if (!empty($failures)) {
    WP_CLI::error(implode("\n", array_values(array_unique($failures))));
}

WP_CLI::line(wp_json_encode(array_merge(array(
    'scope' => 'tsol-library-access-rules-runtime-matrix',
    'phase' => $verification['phase'],
    'users_checked' => count($user_ids),
    'targets_checked' => count($plan['target_ids']),
    'decisions_checked' => count($user_ids) * count($plan['target_ids']),
    'legacy_runtime_policy_pairs_checked' => count($groups),
    'administrators_checked' => $administrator_count,
    'wordpress_only_non_admins_checked' => $wordpress_only_non_admin_count,
    'member_specific_conditions_preserved' => $native_member_condition_count > 0,
    'identities_emitted' => 0,
), $summary), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
WP_CLI::success('Legacy MemberPress runtime and TSOL policy matrix passed without identity output.');
