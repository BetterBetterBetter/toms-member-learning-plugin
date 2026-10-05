<?php
// Mutation check for library-auth-rate-limit-cache-test.php: each plausible regression of the
// object-cache rate limiter must make the harness fail. A surviving mutant is a guard without a test.
// Usage: php tests/standalone/rate-limit-mutants.php
$repo = dirname(__DIR__, 2);
$harness = __DIR__ . '/library-auth-rate-limit-cache-test.php';
$sources = array('includes/features/library-auth/class-library-auth-repository.php', 'includes/features/library-auth/class-library-auth-security.php');
$cache_method = '/(function increment_rate_limit_in_object_cache\(\$rate_key, \$now, \$window_seconds\) \{\n).*?(\n    \}\n)/s';
$get_set = <<<'PHP'
        if (!wp_using_ext_object_cache()) {
            return false;
        }
        $state = wp_cache_get('r:' . $rate_key, self::RATE_LIMIT_CACHE_GROUP);
        if (!is_array($state) || $state['expires_at'] <= $now) {
            $state = array('count' => 0, 'expires_at' => $now + $window_seconds);
        }
        $state['count']++;
        wp_cache_set('r:' . $rate_key, $state, self::RATE_LIMIT_CACHE_GROUP, $state['expires_at'] - $now);
        return $state;
PHP;

// name => array(pattern, replacement); each pattern must match exactly once.
$mutants = array(
    'get+set instead of add+incr' => array($cache_method, function ($m) use ($get_set) { return $m[1] . $get_set . $m[2]; }),
    'incr without a prior add (counter has no TTL)' => array('/if \(wp_cache_add\(\$counter_key, /', 'if (false && wp_cache_add($counter_key, '),
    'trust the cache while Redis is down' => array('/(function rate_limit_cache_connected\(\) \{\n)/', '$1        return true;' . "\n"),
    'accept a recreated count of 1' => array('/if \(\$count < 2\)/', 'if ($count < 1)'),
    'leave the recreated counter behind' => array('/\n\s*wp_cache_delete\(\$counter_key, \$group\);/', ''),
    'trust a window kept past its end' => array('/if \(\$expires_at < \$now - self::RATE_LIMIT_CACHE_EXPIRY_SLACK\)/', 'if (false)'),
    'read the window from the request copy' => array('/wp_cache_get\(\$window_key, \$group, true\)/', 'wp_cache_get($window_key, $group)'),
    'let cache exceptions escape' => array('/(catch \(Throwable \$exception\) \{\n\s*)return false;/', '$1throw $exception;'),
    'ignore WP_REDIS_MAXTTL' => array('/\(int\) WP_REDIS_MAXTTL < \$window_seconds/', 'false'),
    'treat WP_REDIS_MAXTTL = 0 as unlimited' => array('/defined\(\'WP_REDIS_MAXTTL\'\) && \(int\) WP_REDIS_MAXTTL </', "defined('WP_REDIS_MAXTTL') && (int) WP_REDIS_MAXTTL > 0 && (int) WP_REDIS_MAXTTL <"),
    'trust any persistent drop-in' => array('/return is_object\(\$wp_object_cache\) && is_callable/', 'return !is_object($wp_object_cache) || !is_callable'),
);

function run_harness($harness, $dir) {
    $rc = 0;
    $out = array();
    foreach (array('', 'maxttl0') as $mode) {  // maxttl0 runs in its own process (constants stick)
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($harness) . ' ' . escapeshellarg($dir) . ($mode ? ' ' . $mode : '') . ' 2>&1', $lines, $code);
        $rc = $rc ?: $code;
        $out = array_merge($out, $lines);
        $lines = array();
    }
    $failed = array_values(preg_grep('/^(FAIL |PHP Fatal|Fatal error|PHP Warning)|Uncaught/', $out));
    return array($rc, $failed);
}

function copy_sources($repo, $sources, $dir, $mutant = null) {
    foreach ($sources as $i => $source) {
        $code = file_get_contents("$repo/$source");
        if ($i === 0 && $mutant) {
            list($pattern, $replacement) = $mutant;
            $code = is_callable($replacement) ? preg_replace_callback($pattern, $replacement, $code, -1, $hits) : preg_replace($pattern, $replacement, $code, -1, $hits);
            if ($hits !== 1) {
                return false;
            }
        }
        @mkdir(dirname("$dir/$source"), 0777, true);
        file_put_contents("$dir/$source", $code);
    }
    return true;
}

$bad = 0;
$base = sys_get_temp_dir() . '/rate-limit-mutants-' . getmypid();
copy_sources($repo, $sources, "$base/original");
list($rc) = run_harness($harness, "$base/original");
echo ($rc === 0 ? 'OK       ' : 'BROKEN   ') . "unmutated code passes the harness\n";
$bad += $rc === 0 ? 0 : 1;
foreach ($mutants as $name => $mutant) {
    $dir = "$base/" . md5($name);
    if (!copy_sources($repo, $sources, $dir, $mutant)) {
        echo "NOAPPLY  $name (pattern did not match exactly once)\n";
        $bad++;
        continue;
    }
    list($rc, $failed) = run_harness($harness, $dir);
    echo ($rc !== 0 ? 'KILLED   ' : 'SURVIVED ') . $name . "\n";
    foreach (array_slice($failed, 0, 6) as $line) {
        echo '           ' . $line . "\n";
    }
    $bad += $rc !== 0 ? 0 : 1;
}
exec('rm -rf ' . escapeshellarg($base));
exit($bad ? 1 : 0);
