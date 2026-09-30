<?php

declare(strict_types=1);

$spcTestRoot = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'sblog-static-page-cache-'
    . bin2hex(random_bytes(6));
if (!mkdir($spcTestRoot, 0700, true) && !is_dir($spcTestRoot)) {
    throw new RuntimeException('Unable to create the static-page-cache test directory.');
}

define('CACHE_DIR', $spcTestRoot . '/cache');
$GLOBALS['spc_test_settings'] = [];

function add_plugin_action(string $hook, callable $callback, int $priority = 10): void {}
function add_plugin_filter(string $hook, callable $callback, int $priority = 10): void {}
function setting(string $key, string $default = ''): string
{
    return (string)($GLOBALS['spc_test_settings'][$key] ?? $default);
}
function save_settings(array $settings): void
{
    $GLOBALS['spc_test_settings'] = array_merge($GLOBALS['spc_test_settings'], $settings);
}
function csrf_token(): string { return 'csrf-test-secret'; }
function plugin_output_buffer(string $output): string { return $output; }

require dirname(__DIR__) . '/plugins/static-page-cache/plugin.php';

function spc_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$spcRemoveTree = static function (string $path) use (&$spcRemoveTree): void {
    if (!is_dir($path) || is_link($path)) {
        if (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $spcRemoveTree($path . DIRECTORY_SEPARATOR . $entry);
        }
    }
    @rmdir($path);
};

$spcWriteEntry = static function (
    string $key,
    string $dependency,
    string $html,
    int $expiresAt,
    array $overrides = []
): array {
    $paths = spc_cache_paths($key);
    $meta = array_merge([
        'format' => SBLOG_STATIC_PAGE_CACHE_FORMAT,
        'key' => $key,
        'dependency' => $dependency,
        'post_id' => 17,
        'created_at' => time(),
        'expires_at' => $expiresAt,
        'bytes' => strlen($html),
        'sha256' => hash('sha256', $html),
    ], $overrides);
    $encoded = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    spc_test_assert(spc_atomic_write($paths['html'], $html), 'Could not write cache HTML fixture.');
    spc_test_assert(spc_atomic_write($paths['meta'], $encoded), 'Could not write cache metadata fixture.');
    return $paths;
};

$originalGet = $_GET;
$originalPost = $_POST;
$originalServer = $_SERVER;
$originalSession = $_SESSION ?? null;
$hadSession = isset($_SESSION);
$failure = null;

try {
    spc_test_assert(spc_ensure_cache_directory(), 'Cache directory could not be prepared.');
    spc_test_assert(str_starts_with(spc_cache_directory(), $spcTestRoot), 'Cache escaped the test directory.');

    $GLOBALS['spc_test_settings']['site_url'] = 'http://example.test:8787/blog';
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'Example.TEST:8787'];
    $_GET = ['a' => 'post', 'slug' => 'hello-world'];
    $descriptor = spc_request_descriptor('post');
    spc_test_assert($descriptor === [
        'action' => 'post',
        'source' => 'slug',
        'identifier' => 'hello-world',
        'origin' => 'http://example.test:8787',
    ], 'Valid slug request was not normalized as expected.');

    $GLOBALS['spc_test_settings']['site_url'] = 'https://example.test';
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'SERVER_NAME' => 'example.test', 'SERVER_PORT' => '443'];
    $_GET = ['a' => 'page', 'id' => '42'];
    $descriptorById = spc_request_descriptor('page');
    spc_test_assert(($descriptorById['origin'] ?? '') === 'https://example.test', 'HTTPS origin detection failed.');
    spc_test_assert(($descriptorById['source'] ?? '') === 'id', 'Numeric content identifier was not accepted.');

    $_SERVER = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'evil.example'];
    $_GET = ['a' => 'post', 'slug' => 'hello'];
    spc_test_assert(spc_request_descriptor('post') === null, 'A request for a non-canonical host was accepted.');
    $GLOBALS['spc_test_settings']['site_url'] = '';
    $_SERVER = ['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test'];
    spc_test_assert(spc_request_descriptor('post') === null, 'Caching was allowed without a configured site URL.');
    $GLOBALS['spc_test_settings']['site_url'] = 'http://example.test';

    $_GET = ['a' => 'post', 'id' => '01'];
    spc_test_assert(spc_request_descriptor('post') === null, 'A non-canonical numeric content ID was accepted.');
    $_GET = ['a' => 'post', 'slug' => '001'];
    spc_test_assert(spc_request_descriptor('post') === null, 'A non-canonical numeric content slug was accepted.');
    $_GET = ['a' => 'post', 'id' => '1'];
    spc_test_assert((spc_request_descriptor('post')['identifier'] ?? '') === '1', 'A canonical numeric content ID was rejected.');

    foreach ([
        [['REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'example.test'], ['a' => 'post', 'slug' => 'hello']],
        [['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test'], ['a' => 'post', 'slug' => 'hello', 'preview' => '1']],
        [['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test'], ['a' => 'post', 'slug' => 'hello', 'id' => '1']],
        [['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'bad/host'], ['a' => 'post', 'slug' => 'hello']],
        [['REQUEST_METHOD' => 'GET', 'HTTP_HOST' => 'example.test', 'HTTP_AUTHORIZATION' => 'Bearer token'], ['a' => 'post', 'slug' => 'hello']],
    ] as [$server, $query]) {
        $_SERVER = $server;
        $_GET = $query;
        spc_test_assert(spc_request_descriptor('post') === null, 'Unsafe request was accepted for caching.');
    }

    $_SESSION = [];
    spc_test_assert(spc_session_is_public(), 'Empty anonymous session was rejected.');
    $_SESSION = ['csrf_token' => 'token', 'comment_forms' => [17 => 123]];
    spc_test_assert(spc_session_is_public(), 'Expected public session keys were rejected.');
    $_SESSION['visitor_preference'] = 'compact';
    spc_test_assert(!spc_session_is_public(), 'Personalized session state was accepted.');
    $_SESSION = ['admin_id' => 1];
    spc_test_assert(!spc_session_is_public(), 'Administrator session was accepted.');

    $GLOBALS['spc_test_settings']['static_page_cache_excluded_ids'] = '[9,"2",9,0,-4,"invalid"]';
    spc_test_assert(spc_excluded_ids() === [2, 9], 'Excluded content IDs were not normalized.');
    $GLOBALS['spc_test_settings']['static_page_cache_excluded_ids'] = '{broken';
    spc_test_assert(spc_excluded_ids() === [], 'Malformed exclusion JSON was not rejected.');

    $baseDescriptor = [
        'action' => 'post',
        'source' => 'slug',
        'identifier' => 'hello-world',
        'origin' => 'https://example.test',
    ];
    $firstKey = spc_cache_key($baseDescriptor, str_repeat('a', 64));
    spc_test_assert(strlen($firstKey) === 64 && ctype_xdigit($firstKey), 'Cache key is not a SHA-256 digest.');
    spc_test_assert($firstKey === spc_cache_key($baseDescriptor, str_repeat('a', 64)), 'Cache key is not deterministic.');
    spc_test_assert($firstKey !== spc_cache_key($baseDescriptor, str_repeat('b', 64)), 'Dependency generation did not affect the cache key.');
    $pageDescriptor = $baseDescriptor;
    $pageDescriptor['action'] = 'page';
    spc_test_assert($firstKey !== spc_cache_key($pageDescriptor, str_repeat('a', 64)), 'Content kind did not affect the cache key.');

    $lockPaths = [];
    for ($index = 0; $index < 10000; $index++) {
        $lockPaths[spc_cache_paths(hash('sha256', 'request-' . $index))['lock']] = true;
    }
    spc_test_assert(count($lockPaths) === SBLOG_STATIC_PAGE_CACHE_LOCK_SHARDS, 'Cache locks were not bounded to the configured shard count.');

    $commentForm = '<!doctype html><form method="post">'
        . '<input type="hidden" name="csrf_token" value="csrf-test-secret">'
        . '<input type="hidden" name="comment_started_at" value="123456">'
        . '</form>';
    $prepared = spc_prepare_stored_html($commentForm, 17);
    spc_test_assert(is_array($prepared), 'Valid comment form could not be prepared for caching.');
    spc_test_assert(!str_contains((string)$prepared['html'], 'csrf-test-secret'), 'Private CSRF token remained in cached HTML.');
    spc_test_assert(!str_contains((string)$prepared['html'], '123456'), 'Comment start time remained in cached HTML.');
    spc_test_assert(str_contains((string)$prepared['html'], (string)$prepared['csrf_marker']), 'CSRF marker was not inserted.');
    spc_test_assert(str_contains((string)$prepared['html'], (string)$prepared['started_marker']), 'Comment timestamp marker was not inserted.');
    spc_test_assert((int)$prepared['post_id'] === 17, 'Prepared cache entry lost its post ID.');
    spc_test_assert(spc_prepare_stored_html('<form method="post"><button>Submit</button></form>', 17) === null, 'POST form without CSRF was cached.');
    spc_test_assert(spc_prepare_stored_html('<button data-post-like>Like</button>', 17) === null, 'Visitor-specific like state was cached.');
    spc_test_assert(spc_prepare_stored_html('<main nonce="secret">content</main>', 17) === null, 'Nonce-bearing HTML was cached.');
    spc_test_assert(spc_prepare_stored_html('', 17) === null, 'Empty HTML was cached.');

    $bufferLevel = ob_get_level();
    spc_test_assert(!spc_end_core_output_buffer(), 'Missing core output buffer was accepted.');
    ob_start('plugin_output_buffer');
    spc_test_assert(spc_end_core_output_buffer(), 'Known core output buffer was not closed.');
    spc_test_assert(ob_get_level() === $bufferLevel, 'Core output buffer level was not restored.');

    $atomicPath = spc_cache_directory() . '/atomic.txt';
    spc_test_assert(spc_atomic_write($atomicPath, 'first'), 'Initial atomic write failed.');
    spc_test_assert(spc_atomic_write($atomicPath, 'second'), 'Atomic replacement failed.');
    spc_test_assert(file_get_contents($atomicPath) === 'second', 'Atomic replacement stored the wrong bytes.');
    spc_test_assert((glob($atomicPath . '.*.tmp') ?: []) === [], 'Atomic write left a temporary file behind.');
    unlink($atomicPath);

    $dependency = str_repeat('c', 64);
    $validKey = hash('sha256', 'valid-entry');
    $validPaths = $spcWriteEntry($validKey, $dependency, '<html>cached</html>', time() + 60);
    $entry = spc_read_cache($validKey, $dependency);
    spc_test_assert(($entry['html'] ?? '') === '<html>cached</html>', 'Valid cache entry could not be read.');

    spc_test_assert(spc_read_cache($validKey, str_repeat('d', 64)) === null, 'Stale dependency generation was accepted.');
    spc_test_assert(!is_file($validPaths['html']) && !is_file($validPaths['meta']), 'Stale generation files were not removed.');

    $expiredKey = hash('sha256', 'expired-entry');
    $expiredPaths = $spcWriteEntry($expiredKey, $dependency, '<html>expired</html>', time() - 1);
    spc_test_assert(spc_read_cache($expiredKey, $dependency) === null, 'Expired cache entry was accepted.');
    spc_test_assert(!is_file($expiredPaths['html']) && !is_file($expiredPaths['meta']), 'Expired cache files were not removed.');

    $corruptKey = hash('sha256', 'corrupt-entry');
    $corruptPaths = $spcWriteEntry($corruptKey, $dependency, '<html>original</html>', time() + 60);
    file_put_contents($corruptPaths['html'], '<html>tampered</html>');
    spc_test_assert(spc_read_cache($corruptKey, $dependency) === null, 'Hash-mismatched cache entry was accepted.');
    spc_test_assert(!is_file($corruptPaths['html']) && !is_file($corruptPaths['meta']), 'Corrupt cache files were not removed.');

    $cleanupKey = hash('sha256', 'cleanup-entry');
    $cleanupPaths = spc_cache_paths($cleanupKey);
    $orphanHtml = $cleanupPaths['html'] . '.123456abcdef.tmp';
    $orphanMeta = $cleanupPaths['meta'] . '.abcdef123456.tmp';
    foreach ([$cleanupPaths['html'], $cleanupPaths['meta'], $orphanHtml, $orphanMeta] as $path) {
        file_put_contents($path, 'fixture');
    }
    file_put_contents($cleanupPaths['lock'], 'lock');
    file_put_contents(spc_disabled_file(), 'disabled');
    spc_test_assert(spc_clear_cache_files() === 4, 'Cache clear did not report every removable cache file.');
    foreach ([$cleanupPaths['html'], $cleanupPaths['meta'], $orphanHtml, $orphanMeta] as $path) {
        spc_test_assert(!is_file($path), 'Cache clear left a cache or atomic temporary file behind.');
    }
    spc_test_assert(is_file($cleanupPaths['lock']), 'Cache clear removed a shared generation lock.');
    spc_test_assert(is_file(spc_disabled_file()), 'Cache clear removed the safety-disable marker.');
    unlink(spc_disabled_file());

    $oldGeneration = str_repeat('e', 32);
    $GLOBALS['spc_test_settings'] = [
        'static_page_cache_enabled' => '1',
        'static_page_cache_ttl' => '900',
        'static_page_cache_generation' => $oldGeneration,
        'static_page_cache_excluded_ids' => '[2,9]',
    ];
    $_SERVER = ['REQUEST_METHOD' => 'POST'];
    $_POST = ['static_page_cache_exclude' => '1'];
    $GLOBALS['sblog_current_action'] = 'edit';
    $rotationKey = hash('sha256', 'rotation-entry');
    $rotationPaths = $spcWriteEntry($rotationKey, $dependency, '<html>rotate</html>', time() + 60);
    spc_post_saved(['post_id' => 7]);
    $newGeneration = setting('static_page_cache_generation');
    spc_test_assert(preg_match('/^[a-f0-9]{32}$/D', $newGeneration) === 1 && $newGeneration !== $oldGeneration, 'Post save did not rotate the cache generation.');
    spc_test_assert(spc_excluded_ids() === [2, 7, 9], 'Post exclusion was not persisted.');
    spc_test_assert(!is_file($rotationPaths['html']) && !is_file($rotationPaths['meta']), 'Generation rotation did not clear cache files.');

    $_POST = [];
    spc_post_saved(['post_id' => 7]);
    spc_test_assert(spc_excluded_ids() === [2, 9], 'Post exclusion could not be removed.');

    $settings = spc_settings();
    spc_test_assert($settings['enabled'] === true && $settings['ttl'] === 900, 'Valid cache settings were not loaded.');
    $GLOBALS['spc_test_settings']['static_page_cache_ttl'] = '123';
    spc_test_assert(spc_settings()['ttl'] === 3600, 'Invalid cache lifetime did not fall back to one hour.');

    unset($GLOBALS['sblog_static_page_cache_rotation_attempted']);
    $_SERVER = ['REQUEST_METHOD' => 'DELETE'];
    spc_watch_rest_mutation(['action' => 'sblog_rest_api']);
    spc_test_assert(!empty($GLOBALS['sblog_static_page_cache_rest_mutation_pending']), 'REST mutation invalidation was not scheduled.');
    $generationBeforeRestFailure = setting('static_page_cache_generation');
    spc_finish_rest_mutation_invalidation(401);
    spc_test_assert(setting('static_page_cache_generation') === $generationBeforeRestFailure, 'Failed REST mutation invalidated the cache.');

    unset($GLOBALS['sblog_static_page_cache_rotation_attempted']);
    $_SERVER = ['REQUEST_METHOD' => 'POST', 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'PATCH'];
    spc_watch_rest_mutation(['action' => 'sblog_rest_api']);
    spc_test_assert(spc_effective_request_method() === 'PATCH', 'REST method override was not recognized.');
    $generationBeforeRestSuccess = setting('static_page_cache_generation');
    spc_finish_rest_mutation_invalidation(200);
    spc_test_assert(setting('static_page_cache_generation') !== $generationBeforeRestSuccess, 'Successful REST mutation did not invalidate the cache.');

    echo "Static page cache self-test passed.\n";
} catch (Throwable $exception) {
    $failure = $exception;
} finally {
    $_GET = $originalGet;
    $_POST = $originalPost;
    $_SERVER = $originalServer;
    if ($hadSession) {
        $_SESSION = $originalSession;
    } else {
        unset($_SESSION);
    }
    unset(
        $GLOBALS['sblog_current_action'],
        $GLOBALS['spc_test_settings'],
        $GLOBALS['sblog_static_page_cache_rest_mutation_pending'],
        $GLOBALS['sblog_static_page_cache_rotation_attempted']
    );
    spc_release_plan_lock();
    $spcRemoveTree($spcTestRoot);
}

if ($failure instanceof Throwable) {
    fwrite(STDERR, 'Static page cache self-test failed: ' . $failure->getMessage() . "\n");
    exit(1);
}
