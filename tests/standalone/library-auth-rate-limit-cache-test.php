<?php
// Standalone harness for MemberLibrary_Auth_Repository::increment_rate_limit() (no WordPress; stubs only).
// Not a WP-CLI contract: tools/run-contract-tests.sh runs tests/*.php only, never this directory.
// The fake object cache models Redis Object Cache 3.0.0 on Predis: add = SET NX EX, incr = INCRBY (keeps
// the TTL, recreates a missing key without one), get prefers the request's own copy unless $force, and
// after a Redis error every call is answered from that per-request array.
// The igbinary guard is not covered: it needs the igbinary extension.
// Usage: php tests/standalone/library-auth-rate-limit-cache-test.php [plugin dir]
define('ABSPATH', '/');
define('MINUTE_IN_SECONDS', 60);
define('DAY_IN_SECONDS', 86400);
define('ARRAY_A', 'ARRAY_A');

class WP_Error {
    private $code;
    private $data;
    public function __construct($code = '', $message = '', $data = '') { $this->code = $code; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_data() { return $this->data; }
}
function is_wp_error($thing) { return $thing instanceof WP_Error; }
function __($text, $domain = 'default') { return $text; }
function sanitize_key($key) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $key)); }
function wp_unslash($value) { return $value; }
function apply_filters($hook, $value, ...$args) { return array_key_exists($hook, $GLOBALS['filters']) ? $GLOBALS['filters'][$hook] : $value; }
function wp_using_ext_object_cache() { return $GLOBALS['ext_cache']; }
function wp_cache_add($key, $data, $group = '', $expire = 0) { return $GLOBALS['wp_object_cache']->add($key, $data, $group, $expire); }
function wp_cache_set($key, $data, $group = '', $expire = 0) { return $GLOBALS['wp_object_cache']->set($key, $data, $group, $expire); }
function wp_cache_get($key, $group = '', $force = false, &$found = null) { return $GLOBALS['wp_object_cache']->get($key, $group, $force); }
function wp_cache_incr($key, $offset = 1, $group = '') { return $GLOBALS['wp_object_cache']->incr($key, $offset, $group); }
function wp_cache_delete($key, $group = '') { return $GLOBALS['wp_object_cache']->delete($key, $group); }

final class Fake_Redis_Object_Cache {
    public $now = 0;
    public $connected = true;
    public $ignore_ttl = false;
    public $redis = array();  // derived key => array(value, expires_at; 0 = no TTL)
    public $local = array();  // the drop-in's per-request array
    public $fail = array();   // method => 'false' (returns false), 'error' (graceful Redis error), 'throw'
    public $calls = 0;
    public $writes = 0;
    public $interleave_at = 0; // run $rival just before this write (add/set/incr) of the request
    public $rival;

    public function redis_status() { return $this->connected; }

    private function enter($method) {
        $this->calls++;
        if (in_array($method, array('add', 'set', 'incr'), true) && ++$this->writes === $this->interleave_at && $this->rival) {
            $rival = $this->rival;
            $this->rival = null;
            $rival();
        }
        $mode = $this->fail[$method] ?? '';
        if ($mode === 'throw') {
            throw new RuntimeException('cache error');
        }
        if ($mode === 'error') {
            $this->connected = false;
        }
        return $mode === '';
    }

    private function alive($k) {
        if (isset($this->redis[$k]) && $this->redis[$k][1] && $this->redis[$k][1] <= $this->now && !$this->ignore_ttl) {
            unset($this->redis[$k]);
        }
        return isset($this->redis[$k]);
    }

    public function add($key, $value, $group, $ttl) {
        $k = $group . ':' . $key;
        if (!$this->enter('add')) {
            return false;
        }
        if (!$this->connected) {
            if (array_key_exists($k, $this->local)) {
                return false;
            }
            $this->local[$k] = $value;
            return true;
        }
        if ($this->alive($k)) {
            return false;
        }
        $this->redis[$k] = array($value, $ttl ? $this->now + $ttl : 0);
        $this->local[$k] = $value;
        return true;
    }

    public function set($key, $value, $group, $ttl) {
        $k = $group . ':' . $key;
        if (!$this->enter('set')) {
            return false;
        }
        if ($this->connected) {
            $this->redis[$k] = array($value, $ttl ? $this->now + $ttl : 0);
        }
        $this->local[$k] = $value;
        return true;
    }

    public function get($key, $group, $force = false) {
        $k = $group . ':' . $key;
        if (!$this->enter('get')) {
            return false;
        }
        if (array_key_exists($k, $this->local) && !$force) {
            return $this->local[$k];
        }
        if (!$this->connected || !$this->alive($k)) {
            return false;
        }
        $value = $this->redis[$k][0];
        $value = is_int($value) ? (string) $value : $value; // Redis hands scalars back as strings
        $this->local[$k] = $value;
        return $value;
    }

    public function incr($key, $offset, $group) {
        $k = $group . ':' . $key;
        if (!$this->enter('incr')) {
            return false;
        }
        if (!$this->connected) {
            $this->local[$k] = ($this->local[$k] ?? 0) + $offset;
            return $this->local[$k];
        }
        if ($this->alive($k)) {
            $this->redis[$k][0] = (int) $this->redis[$k][0] + $offset;
        } else {
            $this->redis[$k] = array($offset, 0);
        }
        $this->local[$k] = $this->redis[$k][0];
        return $this->redis[$k][0];
    }

    public function delete($key, $group) {
        $k = $group . ':' . $key;
        $this->enter('delete');
        unset($this->local[$k]);
        if ($this->connected) {
            unset($this->redis[$k]);
        }
        return true;
    }
}

final class Fake_Wpdb {
    public $prefix = 'wp_';
    public $rows = array(); // rate_key => array(request_count, window_started_at, expires_at)
    public $writes = 0;
    public $fail_query = false;
    public $fail_select = false;
    public $deleted = array();

    public function prepare($sql, ...$args) { return array($sql, $args); }

    public function query($prepared) {
        list($sql, $args) = $prepared;
        if ($this->fail_query) {
            return false;
        }
        if (strpos($sql, 'INSERT INTO wp_tsol_library_auth_rate_limits ') === 0) {
            // ON DUPLICATE KEY UPDATE: restart the window when expires_at <= now, else count on.
            $this->writes++;
            list($key, $now, $expires_at) = $args;
            $row = $this->rows[$key] ?? null;
            $this->rows[$key] = ($row === null || $row[2] <= $now) ? array(1, $now, $expires_at) : array($row[0] + 1, $row[1], $row[2]);
            return $row === null ? 1 : 2;
        }
        if (preg_match('/^DELETE FROM (\S+)/', $sql, $match)) {
            $this->deleted[] = array($match[1], $args[0]);
            return 1;
        }
        return false;
    }

    public function get_row($prepared, $output) {
        $row = $this->rows[$prepared[1][0]] ?? null;
        if ($this->fail_select || $row === null || $output !== ARRAY_A) {
            return null;
        }
        return array('request_count' => (string) $row[0], 'expires_at' => (string) $row[2]);
    }
}

$plugin = rtrim($argv[1] ?? dirname(__DIR__, 2), '/');
require $plugin . '/includes/features/library-auth/class-library-auth-repository.php';
require $plugin . '/includes/features/library-auth/class-library-auth-security.php';

$fail = 0;
function check($name, $ok) { global $fail; echo ($ok ? 'PASS ' : 'FAIL ') . $name . "\n"; if (!$ok) { $fail++; } }
function fresh() {
    $GLOBALS['wp_object_cache'] = new Fake_Redis_Object_Cache();
    $GLOBALS['wpdb'] = new Fake_Wpdb();
    $GLOBALS['ext_cache'] = true;
    $GLOBALS['filters'] = array();
    return array($GLOBALS['wp_object_cache'], $GLOBALS['wpdb']);
}
// One request: a fresh per-request array unless $same_request, and the fake clock set to $now.
function inc($key, $now, $window = 60, $same_request = false) {
    $cache = $GLOBALS['wp_object_cache'];
    $cache->now = $now;
    if (!$same_request) {
        $cache->local = array();
    }
    return MemberLibrary_Auth_Repository::increment_rate_limit($key, $now, $window);
}
function unavailable($result) { return is_wp_error($result) && $result->get_error_code() === 'rate_limit_unavailable'; }
function counts(array $results) { return array_map(function ($r) { return is_array($r) ? $r['count'] : 'error'; }, $results); }

$group = MemberLibrary_Auth_Repository::RATE_LIMIT_CACHE_GROUP;
$key = hash('sha256', 'rate-limit-harness');

// Input validation: unchanged, and decided before any cache or SQL call.
list($cache, $wpdb) = fresh();
$invalid = array(
    'non-hex key' => array(str_repeat('g', 64), 1000, 60),
    '63-character key' => array(substr($key, 1), 1000, 60),
    'window 9 s' => array($key, 1000, 9),
    'window over a day' => array($key, 1000, DAY_IN_SECONDS + 1),
    'clock 0' => array($key, 0, 60),
);
foreach ($invalid as $name => $args) {
    check("invalid input is rate_limit_unavailable: $name", unavailable(MemberLibrary_Auth_Repository::increment_rate_limit(...$args)));
}
check('invalid input touches neither cache nor SQL', $cache->calls === 0 && $wpdb->writes === 0);
check('bounds accepted: 10 s and one day, upper-case hex', is_array(inc(strtoupper($key), 1000, 10)) && is_array(inc(hash('sha256', 'day'), 1000, DAY_IN_SECONDS)));

// First request, increments, window start and expiry, with a fake clock (t0 is not a multiple of 60).
list($cache, $wpdb) = fresh();
$t0 = 1000003;
$sequence = array($t0, $t0 + 1, $t0 + 59, $t0 + 60, $t0 + 61, $t0 + 125);
$results = array_map(function ($now) use ($key) { return inc($key, $now); }, $sequence);
check('first request: count 1, window ends first request + 60 s', $results[0] === array('count' => 1, 'expires_at' => $t0 + 60));
check('requests in the window count on with the same end', counts(array_slice($results, 0, 3)) === array(1, 2, 3) && $results[2]['expires_at'] === $t0 + 60);
check('at the window end the count resets and the window starts at that request', $results[3] === array('count' => 1, 'expires_at' => $t0 + 120) && $results[4] === array('count' => 2, 'expires_at' => $t0 + 120));
check('after an idle gap the window starts at the next request', $results[5] === array('count' => 1, 'expires_at' => $t0 + 185));
check('the cache path writes no SQL', $wpdb->writes === 0);
check('key/TTL scheme: window key holds its end with TTL to that end', ($cache->redis["$group:w:$key"] ?? null) === array($t0 + 185, $t0 + 185));
check('key/TTL scheme: counter named after its window, TTL to that end', ($cache->redis["$group:c:$key:" . ($t0 + 185)] ?? null) === array(1, $t0 + 185));
$GLOBALS['ext_cache'] = false;
$sql_results = array_map(function ($now) use ($key) { return inc(hash('sha256', 'sql-' . $key), $now); }, $sequence);
check('the SQL path returns the same sequence (contract parity)', $sql_results === $results && $wpdb->writes === count($sequence));

// Concurrent first requests: a rival request runs before each of our writes in turn.
foreach (array(1, 2) as $position) {
    list($cache, $wpdb) = fresh();
    $rival = null;
    $cache->interleave_at = $position;
    $cache->rival = function () use ($key, &$rival) { $rival = inc($key, 2000000); };
    $ours = inc($key, 2000000, 60, true);
    check("concurrent first requests (rival before our write $position): counts 1 and 2 in one window",
        is_array($rival) && is_array($ours) && array($rival['count'], $ours['count']) === array(1, 2) && $rival['expires_at'] === $ours['expires_at']);
}

// A cache call fails: the SQL path counts the request, never "unlimited".
list($cache, $wpdb) = fresh();
$cache->fail['add'] = 'false';
$results = array(inc($key, 3000000), inc($key, 3000001), inc($key, 3000002));
check('add returns false (e.g. cache addition suspended): SQL counts every request', counts($results) === array(1, 2, 3) && $wpdb->writes === 3);
foreach (array('get', 'incr') as $method) {
    list($cache, $wpdb) = fresh();
    inc($key, 3000000);
    $cache->fail[$method] = 'false';
    $results = array(inc($key, 3000001), inc($key, 3000002), inc($key, 3000003));
    check("$method returns false inside an open window: SQL counts every request", counts($results) === array(1, 2, 3) && $wpdb->writes === 3);
}
list($cache, $wpdb) = fresh();
$cache->fail['add'] = 'throw';
check('a throwing cache falls back to SQL', inc($key, 3000000) === array('count' => 1, 'expires_at' => 3000060) && $wpdb->writes === 1);

// Redis down: the drop-in answers from a per-request array, so every request would look like the first.
list($cache, $wpdb) = fresh();
$cache->connected = false;
$results = array_map(function ($now) use ($key) { return inc($key, $now); }, range(3100000, 3100004));
check('Redis down: SQL counts 1..5, not 1,1,1,1,1', counts($results) === array(1, 2, 3, 4, 5) && $wpdb->writes === 5);

// Redis error at our counter add, in a request that already counted this key: the incr is then
// answered from the per-request array (2 + 1) and looks plausible.
list($cache, $wpdb) = fresh();
inc($key, 3200000);
inc($key, 3200001, 60, true);
$cache->writes = 0;
$cache->interleave_at = 2;
$cache->rival = function () use ($cache) { $cache->fail['add'] = 'error'; };
$result = inc($key, 3200002, 60, true);
check('Redis lost mid-request: SQL counts, not the per-request array', $result === array('count' => 1, 'expires_at' => 3200062) && $wpdb->writes === 1);

// The counter expires between our failed add and the incr: INCRBY recreates it at 1 with no TTL.
list($cache, $wpdb) = fresh();
inc($key, 3300000);
$cache->writes = 0;
$cache->interleave_at = 3; // add(window) fails, add(counter) fails, then the incr
$cache->rival = function () use ($cache, $group, $key) { unset($cache->redis["$group:c:$key:3300060"]); };
$result = inc($key, 3300001);
check('counter recreated by INCRBY: SQL counts the request', $result === array('count' => 1, 'expires_at' => 3300061) && $wpdb->writes === 1);
check('counter recreated by INCRBY: no TTL-less key is left behind', !array_filter($cache->redis, function ($entry) { return $entry[1] === 0; }));

// A cache that keeps keys past their TTL.
list($cache, $wpdb) = fresh();
$cache->ignore_ttl = true;
inc($key, 3400000);
$late = inc($key, 3400061);
check('a window seconds past its end (Redis rounding) still counts, retry in 1 s', $late === array('count' => 2, 'expires_at' => 3400062) && $wpdb->writes === 0);
$stale = inc($key, 3400063);
check('a window kept beyond the slack goes to SQL', $stale === array('count' => 1, 'expires_at' => 3400123) && $wpdb->writes === 1);

// A long-lived process holding an old window must read Redis, not its own copy.
list($cache, $wpdb) = fresh();
inc($key, 3500000);
$old_request_copy = $cache->local;
inc($key, 3500070);
$cache->local = $old_request_copy;
$result = inc($key, 3500071, 60, true);
check('a process holding an expired window reads the current one', $result === array('count' => 2, 'expires_at' => 3500130) && $wpdb->writes === 0);

// No persistent object cache, or the kill switch: the SQL path.
list($cache, $wpdb) = fresh();
$GLOBALS['ext_cache'] = false;
check('no persistent object cache: SQL path', counts(array(inc($key, 3600000), inc($key, 3600001))) === array(1, 2) && $wpdb->writes === 2 && $cache->calls === 0);
list($cache, $wpdb) = fresh();
$GLOBALS['filters']['tsol_library_auth_rate_limit_object_cache'] = false;
check('tsol_library_auth_rate_limit_object_cache = false: SQL path', is_array(inc($key, 3600000)) && $wpdb->writes === 1 && $cache->calls === 0);

// SQL fails: rate_limit_unavailable, as today, including when the cache failed first.
list($cache, $wpdb) = fresh();
$GLOBALS['ext_cache'] = false;
$wpdb->fail_query = true;
check('SQL write fails: rate_limit_unavailable', unavailable(inc($key, 3700000)));
list($cache, $wpdb) = fresh();
$GLOBALS['ext_cache'] = false;
$wpdb->fail_select = true;
check('SQL read-back fails: rate_limit_unavailable', unavailable(inc($key, 3700000)));
list($cache, $wpdb) = fresh();
$cache->fail['add'] = 'false';
$wpdb->fail_query = true;
check('cache fails and SQL fails: rate_limit_unavailable', unavailable(inc($key, 3700000)));
list($cache, $wpdb) = fresh();
$cache->connected = false;
$wpdb->fail_query = true;
check('Redis down and SQL fails: rate_limit_unavailable', unavailable(inc($key, 3700000)));

// cleanup() still purges the SQL table.
list($cache, $wpdb) = fresh();
$before = time();
$deleted = MemberLibrary_Auth_Repository::cleanup();
$rate_delete = array_values(array_filter($wpdb->deleted, function ($d) { return $d[0] === 'wp_tsol_library_auth_rate_limits'; }));
check('cleanup deletes rate-limit rows older than a day', $deleted === 3 && count($rate_delete) === 1 && $rate_delete[0][1] >= $before - DAY_IN_SECONDS && $rate_delete[0][1] <= time() - DAY_IN_SECONDS);
$wpdb->fail_query = true;
check('cleanup reports a failed delete', MemberLibrary_Auth_Repository::cleanup() === false);

// The unchanged caller limits through the cache, and through SQL when Redis is down.
foreach (array('Redis up' => true, 'Redis down' => false) as $name => $connected) {
    list($cache, $wpdb) = fresh();
    $cache->connected = $connected;
    $cache->now = time();
    $GLOBALS['filters']['tsol_library_auth_rate_limit'] = 3;
    $checks = array();
    for ($i = 0; $i < 4; $i++) {
        $cache->local = array(); // each check is its own request
        $checks[] = MemberLibrary_Auth_Rate_Limiter::check('token', 60, MINUTE_IN_SECONDS, 'client-a');
    }
    $retry = is_wp_error($checks[3]) ? ($checks[3]->get_error_data()['retry_after'] ?? 0) : 0;
    check("Rate_Limiter::check ($name): 3 allowed, the 4th rate_limited with Retry-After",
        $checks[0] === true && $checks[2] === true && is_wp_error($checks[3]) && $checks[3]->get_error_code() === 'rate_limited' && $retry >= 1 && $retry <= 60
        && $wpdb->writes === ($connected ? 0 : 4));
}

// WP_REDIS_MAXTTL below the window would expire windows early: SQL. Last, because constants stick.
define('WP_REDIS_MAXTTL', 30);
list($cache, $wpdb) = fresh();
check('WP_REDIS_MAXTTL below the window: SQL path', is_array(inc($key, 3800000, 60)) && $wpdb->writes === 1);
check('WP_REDIS_MAXTTL above the window: cache path', is_array(inc($key, 3800000, 20)) && $wpdb->writes === 1);

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit($fail ? 1 : 0);
