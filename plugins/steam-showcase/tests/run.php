<?php

declare(strict_types=1);

// This entry point is deliberately CLI-only and never loads the live blog.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "The test suite requires the pdo_sqlite PHP extension.\n");
    exit(1);
}

$testDirectory = sys_get_temp_dir() . '/sblog-steam-tests-' . bin2hex(random_bytes(8));
if (!mkdir($testDirectory, 0700, true)) {
    throw new RuntimeException('Could not create the temporary test directory.');
}

define('PLUGINS_DIR', dirname(__DIR__, 2));
define('CACHE_DIR', $testDirectory . '/cache');
mkdir(CACHE_DIR, 0700);

function db(): PDO
{
    return $GLOBALS['steam_test_db'];
}

function h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function str_len_u(string $value): int
{
    return preg_match_all('/./us', $value);
}

function script_url(): string
{
    return (string)($_SERVER['SCRIPT_NAME'] ?? '/index.php');
}

function app_path(string $path = '/'): string
{
    $base = str_replace('\\', '/', dirname(script_url()));
    return ($base === '/' || $base === '.' ? '' : rtrim($base, '/')) . '/' . ltrim($path, '/');
}

function use_pretty_url(): bool
{
    return $GLOBALS['steam_test_pretty_url'];
}

function url_with_query(string $url, array $params): string
{
    return $url . '?' . http_build_query($params);
}

function url_for(string $action = 'home', array $params = []): string
{
    return '/index.php?' . http_build_query(['action' => $action] + $params);
}

function plugin_asset_url(string $slug, string $path): string
{
    return '/plugins/' . rawurlencode($slug) . '/' . $path;
}

function add_plugin_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['steam_test_actions'][$hook][$priority][] = $callback;
}

function add_plugin_filter(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['steam_test_filters'][$hook][$priority][] = $callback;
}

function add_theme_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['steam_test_theme_actions'][$hook][$priority][] = $callback;
}

function require_admin(): void
{
    if (!$GLOBALS['steam_test_admin']) {
        throw new RuntimeException('TEST_ACCESS_DENIED');
    }
}

function verify_csrf(): void
{
    if (($GLOBALS['steam_test_csrf'] ?? '') === '' || !hash_equals($GLOBALS['steam_test_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('TEST_CSRF_DENIED');
    }
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="test-csrf">';
}

function render_admin_sidebar(string $active): string
{
    return '';
}

function render_admin_topbar(string $title, string $label = '', string $url = ''): string
{
    return '<h1>' . h($title) . '</h1>';
}

function render_layout(string $title, string $html, array $options = []): void
{
    $GLOBALS['steam_test_rendered'] = $html;
}

function steam_test_remove_directory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->isDir() && !$file->isLink()) {
            steam_test_remove_directory($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($directory);
}

register_shutdown_function(static function () use ($testDirectory): void {
    steam_test_remove_directory($testDirectory);
});

function steam_test_reset(): void
{
    $GLOBALS['steam_test_db'] = new PDO('sqlite::memory:');
    $GLOBALS['steam_test_db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $GLOBALS['steam_test_admin'] = false;
    $GLOBALS['steam_test_csrf'] = 'valid-test-token';
    $GLOBALS['steam_test_rendered'] = '';
    $GLOBALS['steam_test_pretty_url'] = false;
    $_POST = [];
    $_GET = [];
    $_REQUEST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REQUEST_URI'] = '/index.php';
    foreach (new FilesystemIterator(CACHE_DIR, FilesystemIterator::SKIP_DOTS) as $file) {
        if ($file->isDir() && !$file->isLink()) {
            steam_test_remove_directory($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
}

function steam_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function steam_test_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function steam_test_config(array $overrides = []): array
{
    return array_replace(sblog_steam_defaults(), [
        'steam_id' => '76561198000000001',
        'api_key' => str_repeat('a', 32),
    ], $overrides);
}

function steam_test_post(array $overrides = []): array
{
    return array_replace([
        'steam_id' => '76561198000000001',
        'api_key' => '',
        'page_title' => '游戏时光',
        'game_limit' => '12',
        'cache_minutes' => '15',
        'show_recent' => '1',
        'show_library' => '1',
        'show_nav' => '1',
    ], $overrides);
}

function steam_test_responses(): array
{
    $games = [
        ['appid' => 730, 'name' => 'Counter-Strike 2', 'playtime_forever' => 600, 'playtime_2weeks' => 90, 'img_icon_url' => str_repeat('b', 40)],
        ['appid' => 570, 'name' => 'Dota 2', 'playtime_forever' => 1800, 'playtime_2weeks' => 30, 'img_icon_url' => str_repeat('c', 40)],
    ];
    return [
        'profile' => ['ok' => true, 'data' => ['response' => ['players' => [[
            'steamid' => '76561198000000001',
            'personaname' => '测试玩家',
            'avatarfull' => 'https://avatars.steamstatic.com/test_full.jpg',
            'profileurl' => 'https://steamcommunity.com/id/custom-name',
            'personastate' => 1,
            'communityvisibilitystate' => 3,
            'lastlogoff' => 1700000000,
        ]]]]],
        'recent' => ['ok' => true, 'data' => ['response' => ['total_count' => 2, 'games' => $games]]],
        'owned' => ['ok' => true, 'data' => ['response' => ['game_count' => 2, 'games' => $games]]],
    ];
}

function steam_test_transport(array $responses, array &$calls): Closure
{
    return static function (string $method, array $parameters) use ($responses, &$calls): array {
        $calls[] = ['method' => $method, 'parameters' => $parameters];
        return $responses[$method];
    };
}

function steam_test_expire_cache(array $config): void
{
    $path = sblog_steam_cache_paths($config)['data'];
    $record = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $record['checked_at'] = time() - 100000;
    $record['next_retry_at'] = 0;
    file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
}

function steam_test_route(string $action, string $requestUri, array $query = [], array $context = []): string
{
    $_SERVER['REQUEST_URI'] = $requestUri;
    $_GET = $query;
    $_REQUEST = $query;
    $filters = $GLOBALS['steam_test_filters']['route_action'] ?? [];
    steam_test_assert($filters !== [], 'The plugin must register a route action filter.');
    ksort($filters, SORT_NUMERIC);
    foreach ($filters as $callbacks) {
        foreach ($callbacks as $callback) {
            $action = $callback($action, $context);
        }
    }
    return $action;
}

steam_test_reset();
require dirname(__DIR__) . '/plugin.php';
require dirname(__DIR__) . '/admin.php';

$tests = [];
$tests['reading defaults leaves the database untouched'] = static function (): void {
    steam_test_same(sblog_steam_defaults(), sblog_steam_config(), 'A new plugin must show its documented defaults.');
    steam_test_same(0, (int)db()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn(), 'Reading a public page must not create database tables.');
};

$tests['blank secret preserves the saved key and explicit clearing removes it'] = static function (): void {
    $existing = steam_test_config();
    $preserved = sblog_steam_validate_config(steam_test_post(), $existing);
    steam_test_same([], $preserved['errors'], 'A valid submitted form must pass validation.');
    steam_test_same($existing['api_key'], $preserved['config']['api_key'], 'Leaving the password input blank must preserve the saved API key.');
    $cleared = sblog_steam_validate_config(steam_test_post(['clear_api_key' => '1']), $existing);
    steam_test_same([], $cleared['errors'], 'Explicitly clearing a key must be allowed.');
    steam_test_same('', $cleared['config']['api_key'], 'The explicit clear checkbox must clear the key.');
};

$tests['configuration rejects malformed credentials and out-of-range controls'] = static function (): void {
    $invalid = [
        ['steam_id' => 'https://steamcommunity.com/id/player'],
        ['steam_id' => '7656119800000000'],
        ['api_key' => '<script>alert(1)</script>'],
        ['api_key' => str_repeat('g', 32)],
        ['game_limit' => '0'],
        ['game_limit' => '25'],
        ['game_limit' => '3.5'],
        ['cache_minutes' => '4'],
        ['cache_minutes' => '1441'],
        ['page_title' => ''],
        ['page_title' => str_repeat('游', 61)],
    ];
    foreach ($invalid as $input) {
        $result = sblog_steam_validate_config(steam_test_post($input), steam_test_config());
        steam_test_assert($result['errors'] !== [], 'The invalid setting ' . (string)array_key_first($input) . ' must return an error.');
        steam_test_assert(!str_contains(json_encode($result['errors'], JSON_THROW_ON_ERROR), str_repeat('a', 32)), 'Validation errors must not reveal the saved API key.');
    }
};

$tests['valid boundary values and an unconfigured profile can be saved'] = static function (): void {
    foreach ([[1, 5], [24, 1440]] as [$limit, $minutes]) {
        $result = sblog_steam_validate_config(steam_test_post([
            'steam_id' => '',
            'game_limit' => (string)$limit,
            'cache_minutes' => (string)$minutes,
            'page_title' => str_repeat('游', 60),
        ]), sblog_steam_defaults());
        steam_test_same([], $result['errors'], 'Boundary values must be accepted.');
        steam_test_same($limit, $result['config']['game_limit'], 'The game count must become an integer.');
        steam_test_same($minutes, $result['config']['cache_minutes'], 'The cache duration must become an integer.');
    }
};

$tests['saving settings uses the plugin table without writing a public settings cache'] = static function (): void {
    $config = steam_test_config(['home_widget' => true, 'page_title' => '我的游戏']);
    sblog_steam_save_config($config);
    steam_test_same($config, sblog_steam_config(), 'The saved plugin settings must round trip.');
    $tables = db()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    steam_test_same(['plugin_steam_settings'], $tables, 'The plugin must keep its key out of the core settings table.');
    steam_test_same([], iterator_to_array(new FilesystemIterator(CACHE_DIR, FilesystemIterator::SKIP_DOTS)), 'Saving credentials must not produce a public cache file.');
    sblog_steam_save_config(array_replace($config, ['api_key' => '']));
    steam_test_same('', sblog_steam_config()['api_key'], 'A cleared API key must stay cleared after saving.');
};

$tests['admin requests enforce authorization before reading or changing settings'] = static function (): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = steam_test_post(['operation' => 'save', 'api_key' => str_repeat('b', 32)]);
    try {
        sblog_steam_admin_request();
        throw new RuntimeException('An unauthenticated request reached the settings handler.');
    } catch (RuntimeException $exception) {
        steam_test_same('TEST_ACCESS_DENIED', $exception->getMessage(), 'Admin requests must call the core authorization guard first.');
    }
    steam_test_same(0, (int)db()->query("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn(), 'Unauthorized requests must not create settings.');
};

$tests['save and refresh operations require CSRF verification'] = static function (): void {
    $GLOBALS['steam_test_admin'] = true;
    sblog_steam_save_config(steam_test_config());
    foreach (['save', 'refresh'] as $operation) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = steam_test_post(['operation' => $operation, 'csrf_token' => 'invalid-token', 'api_key' => str_repeat('b', 32)]);
        try {
            sblog_steam_admin_request();
            throw new RuntimeException('The operation passed without a valid CSRF token.');
        } catch (RuntimeException $exception) {
            steam_test_same('TEST_CSRF_DENIED', $exception->getMessage(), 'Mutating requests must call the core CSRF guard.');
        }
        steam_test_same(str_repeat('a', 32), sblog_steam_config()['api_key'], 'A rejected request must preserve the original key.');
        steam_test_same([], iterator_to_array(new FilesystemIterator(CACHE_DIR, FilesystemIterator::SKIP_DOTS)), 'Rejected refresh requests must not create API caches.');
    }
};

$tests['admin forms escape configuration and never echo the saved secret'] = static function (): void {
    $GLOBALS['steam_test_admin'] = true;
    sblog_steam_admin(steam_test_config(['page_title' => '"><script>alert(1)</script>']));
    $html = $GLOBALS['steam_test_rendered'];
    steam_test_assert(!str_contains($html, str_repeat('a', 32)), 'A saved API key must never appear in form markup.');
    steam_test_assert(str_contains($html, 'name="api_key"') && str_contains($html, 'value=""'), 'The key input must start empty.');
    steam_test_assert(!str_contains($html, '<script>alert(1)</script>'), 'Saved display values must be escaped in the form.');
};

$tests['an unconfigured account makes no network request and writes no cache'] = static function (): void {
    $calls = [];
    $result = sblog_steam_snapshot(sblog_steam_defaults(), false, steam_test_transport(steam_test_responses(), $calls));
    steam_test_same('unconfigured', $result['status'], 'An account without credentials must explain the setup state.');
    steam_test_same([], $calls, 'The setup page must not contact Steam.');
    steam_test_same([], iterator_to_array(new FilesystemIterator(CACHE_DIR, FilesystemIterator::SKIP_DOTS)), 'An unconfigured page must not create cache files.');
};

$tests['successful responses normalize player data, totals and sorting'] = static function (): void {
    $calls = [];
    $result = sblog_steam_snapshot(steam_test_config(), false, steam_test_transport(steam_test_responses(), $calls));
    steam_test_same('ok', $result['status'], 'A complete successful sync must be healthy.');
    steam_test_same(['profile', 'recent', 'owned'], array_column($calls, 'method'), 'A sync must fetch profile, recent games and the library.');
    foreach ($calls as $call) {
        steam_test_same(str_repeat('a', 32), $call['parameters']['key'], 'The API key must be passed only to the server-side transport.');
    }
    steam_test_same('76561198000000001', $calls[0]['parameters']['steamids'], 'The summary request must target the configured account.');
    steam_test_same('76561198000000001', $calls[2]['parameters']['steamid'], 'The library request must target the configured account.');
    steam_test_same('测试玩家', $result['profile']['name'], 'The player name must be normalized.');
    steam_test_same(['game_count' => 2, 'total_minutes' => 2400, 'recent_minutes' => 120], $result['stats'], 'Statistics must sum Steam minute values.');
    steam_test_same([730, 570], array_column($result['recent_games'], 'appid'), 'Recent games must sort by recent playtime.');
    steam_test_same([570, 730], array_column($result['owned_games'], 'appid'), 'The library must sort by lifetime playtime.');
    steam_test_assert(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), str_repeat('a', 32)), 'Public snapshot data must not contain the API key.');
};

$tests['fresh cache avoids requests and explicit refresh replaces a healthy cache'] = static function (): void {
    $config = steam_test_config();
    $calls = [];
    $transport = steam_test_transport(steam_test_responses(), $calls);
    $first = sblog_steam_snapshot($config, false, $transport);
    steam_test_same($first, sblog_steam_snapshot($config, false, $transport), 'A fresh cached result must be returned unchanged.');
    steam_test_same(3, count($calls), 'Repeated public visits must not repeat fresh API calls.');
    $changed = steam_test_responses();
    $changed['profile']['data']['response']['players'][0]['personaname'] = '已更新';
    $refreshed = sblog_steam_snapshot($config, true, steam_test_transport($changed, $calls));
    steam_test_same('已更新', $refreshed['profile']['name'], 'An explicit refresh must use newly fetched data.');
    steam_test_same(6, count($calls), 'An explicit refresh must fetch all three methods once.');
};

$tests['public cache discards secrets and fields outside the display model'] = static function (): void {
    $config = steam_test_config();
    $responses = steam_test_responses();
    $responses['profile']['data']['response']['players'][0]['api_key'] = $config['api_key'];
    $responses['profile']['data']['response']['players'][0]['untrusted_field'] = 'not-for-the-public-cache';
    $calls = [];
    sblog_steam_snapshot($config, false, steam_test_transport($responses, $calls));
    $path = sblog_steam_cache_paths($config)['data'];
    $contents = (string)file_get_contents($path);
    steam_test_assert(!str_contains($path, $config['api_key']), 'Cache filenames must not expose the API key.');
    steam_test_assert(!str_contains($contents, $config['api_key']), 'Cache JSON must not expose the API key.');
    steam_test_assert(!str_contains($contents, 'not-for-the-public-cache'), 'Raw API fields must not be cached.');
};

$tests['changing the account or key cannot reuse the previous cache'] = static function (): void {
    $calls = [];
    $config = steam_test_config();
    sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    $newKey = array_replace($config, ['api_key' => str_repeat('d', 32)]);
    sblog_steam_snapshot($newKey, false, steam_test_transport(steam_test_responses(), $calls));
    $newAccount = array_replace($config, ['steam_id' => '76561198000000002']);
    $responses = steam_test_responses();
    $responses['profile']['data']['response']['players'][0]['steamid'] = $newAccount['steam_id'];
    $changed = sblog_steam_snapshot($newAccount, false, steam_test_transport($responses, $calls));
    steam_test_same(9, count($calls), 'Changing either credential must start a separate cache entry.');
    steam_test_same($newAccount['steam_id'], $changed['profile']['steamid'], 'An account change must show the new profile.');
};

$tests['a private library differs from a public empty library'] = static function (): void {
    $private = steam_test_responses();
    $private['recent']['data'] = ['response' => []];
    $private['owned']['data'] = ['response' => []];
    $calls = [];
    $snapshot = sblog_steam_snapshot(steam_test_config(), false, steam_test_transport($private, $calls));
    steam_test_same('ok', $snapshot['status'], 'Privacy responses are valid successful responses.');
    steam_test_same('private', $snapshot['library_state'], 'Missing public game details must be represented as private.');
    steam_test_same(null, $snapshot['stats']['game_count'], 'A private library must not claim a zero game count.');
    $empty = steam_test_responses();
    $empty['recent']['data'] = ['response' => ['total_count' => 0]];
    $empty['owned']['data'] = ['response' => ['game_count' => 0]];
    $snapshot = sblog_steam_snapshot(steam_test_config(['api_key' => str_repeat('e', 32)]), false, steam_test_transport($empty, $calls));
    steam_test_same('public', $snapshot['library_state'], 'An explicit zero must represent an empty public library.');
    steam_test_same(['game_count' => 0, 'total_minutes' => 0, 'recent_minutes' => 0], $snapshot['stats'], 'An empty public account must show accurate zeros.');
};

$tests['malformed responses cannot become an empty or private successful result'] = static function (): void {
    $malformed = [
        [],
        ['response' => null],
        ['response' => ['game_count' => -1]],
        ['response' => ['game_count' => 1, 'games' => []]],
        ['response' => ['game_count' => 1, 'games' => [['appid' => 'not-an-id']]]],
        ['response' => ['game_count' => 1, 'games' => [['appid' => 730, 'playtime_forever' => -1]]]],
        ['response' => ['game_count' => 2, 'games' => [['appid' => 730], ['appid' => 730]]]],
    ];
    foreach ($malformed as $response) {
        steam_test_same(null, sblog_steam_normalize_games($response, true), 'Malformed library data must be rejected instead of showing misleading zero statistics.');
    }
    steam_test_same(null, sblog_steam_normalize_profile(['response' => ['players' => []]], '76561198000000001'), 'A missing player must be treated as a failed response.');
    steam_test_same(null, sblog_steam_normalize_profile(steam_test_responses()['profile']['data'], '76561198000000002'), 'A mismatched player must not be shown.');
};

$tests['outages retain the last successful snapshot and avoid immediate retries'] = static function (): void {
    $config = steam_test_config();
    $calls = [];
    $original = sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    steam_test_expire_cache($config);
    $failure = array_fill_keys(['profile', 'recent', 'owned'], ['ok' => false, 'message' => 'URL?key=' . $config['api_key'] . '<script>alert(1)</script>']);
    $stale = sblog_steam_snapshot($config, false, steam_test_transport($failure, $calls));
    steam_test_same('stale', $stale['status'], 'A transient outage must label cached data as stale.');
    steam_test_same($original['profile'], $stale['profile'], 'An outage must preserve the last valid profile.');
    steam_test_same($original['owned_games'], $stale['owned_games'], 'An outage must preserve the last valid library.');
    steam_test_same($original['updated_at'], $stale['updated_at'], 'An outage must not claim to have synchronized old data just now.');
    steam_test_assert(!str_contains($stale['message'], $config['api_key']), 'Transport details must not expose secrets.');
    sblog_steam_snapshot($config, true, steam_test_transport($failure, $calls));
    steam_test_same(6, count($calls), 'The retry delay must prevent repeated failed refreshes.');
};

$tests['successful privacy changes remove cached public games during a partial outage'] = static function (): void {
    $config = steam_test_config();
    $calls = [];
    sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    steam_test_expire_cache($config);
    $responses = steam_test_responses();
    $responses['profile'] = ['ok' => false];
    $responses['recent']['data'] = ['response' => []];
    $responses['owned']['data'] = ['response' => []];
    $result = sblog_steam_snapshot($config, false, steam_test_transport($responses, $calls));
    steam_test_same('stale', $result['status'], 'The unavailable profile may be retained as stale.');
    steam_test_same('private', $result['library_state'], 'A newly private library must replace public cache state.');
    steam_test_same([], $result['owned_games'], 'Previously public games must be removed after a valid privacy response.');
    steam_test_same([], $result['recent_games'], 'Previously public recent games must also be removed.');
    steam_test_same(['game_count' => null, 'total_minutes' => null, 'recent_minutes' => null], $result['stats'], 'Private game statistics must stop displaying old public totals.');
};

$tests['transport exceptions and corrupt JSON produce safe recoverable results'] = static function (): void {
    $config = steam_test_config();
    $result = sblog_steam_snapshot($config, false, static function (): array {
        throw new RuntimeException('Sensitive URL?key=' . str_repeat('a', 32));
    });
    steam_test_same('error', $result['status'], 'An outage without a previous result must show an error state.');
    steam_test_assert(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), $config['api_key']), 'A transport exception must not reveal its API key.');
    file_put_contents(sblog_steam_cache_paths($config)['data'], '{not valid JSON');
    $calls = [];
    $result = sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    steam_test_same('ok', $result['status'], 'A corrupt cache must be replaced with fresh valid data.');
    steam_test_same(3, count($calls), 'A corrupt cache must not suppress synchronization.');
};

$tests['a concurrent refresh serves the previous snapshot without duplicate requests'] = static function (): void {
    $config = steam_test_config();
    $calls = [];
    $original = sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    steam_test_expire_cache($config);
    $lock = fopen(sblog_steam_cache_paths($config)['lock'], 'c');
    steam_test_assert(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'The test must acquire the cache refresh lock.');
    try {
        steam_test_same($original, sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls)), 'A concurrent request must receive the last usable snapshot.');
        steam_test_same(3, count($calls), 'A concurrent request must not duplicate API calls.');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
};

$tests['normalization accepts Steam CDNs and rejects attacker-controlled URLs'] = static function (): void {
    foreach (['javascript:alert(1)', 'https://evil.example/avatar.jpg', 'https://steamstatic.com.evil.example/avatar.jpg', 'https://user:pass@avatars.steamstatic.com/avatar.jpg', 'https://avatars.steamstatic.com:8080/avatar.jpg', '//avatars.steamstatic.com/avatar.jpg'] as $url) {
        steam_test_same('', sblog_steam_image_url($url), 'Untrusted image URL must be rejected: ' . $url);
    }
    steam_test_same('https://avatars.steamstatic.com/avatar.jpg', sblog_steam_image_url('http://avatars.steamstatic.com/avatar.jpg'), 'Known Steam CDN image URLs must use HTTPS.');
    $payload = steam_test_responses()['profile']['data'];
    $payload['response']['players'][0]['profileurl'] = 'javascript:alert(1)';
    $payload['response']['players'][0]['avatarfull'] = 'https://evil.example/track';
    $payload['response']['players'][0]['personaname'] = "玩家\x00\x01";
    $result = sblog_steam_normalize_profile($payload, '76561198000000001');
    steam_test_same('https://steamcommunity.com/profiles/76561198000000001', $result['profile_url'], 'Profile links must be generated from the validated Steam ID.');
    steam_test_same('', $result['avatar'], 'Untrusted avatar hosts must not reach rendering.');
    steam_test_same('玩家', $result['name'], 'Control characters must be stripped from display names.');
};

$tests['a newly private library clears stale recent records when the recent request fails'] = static function (): void {
    $config = steam_test_config();
    $calls = [];
    sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    steam_test_expire_cache($config);
    $responses = steam_test_responses();
    $responses['owned']['data'] = ['response' => []];
    $responses['recent'] = ['ok' => false];
    $result = sblog_steam_snapshot($config, false, steam_test_transport($responses, $calls));
    steam_test_same([], $result['owned_games'], 'A private library must remove its previous games.');
    steam_test_same([], $result['recent_games'], 'Unavailable recent records must not retain old public games after the library reports privacy.');
    steam_test_same(['game_count' => null, 'total_minutes' => null, 'recent_minutes' => null], $result['stats'], 'Privacy must hide all cached game totals.');
};

$tests['unavailable recent records do not override a freshly confirmed public library'] = static function (): void {
    $config = steam_test_config();
    $calls = [];
    sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    steam_test_expire_cache($config);
    $responses = steam_test_responses();
    $responses['recent']['data'] = ['response' => []];
    $responses['owned']['data']['response']['games'][0]['name'] = 'Newly confirmed public game';
    $responses['profile']['data']['response']['players'][0]['gameextrainfo'] = 'Current game';
    $responses['profile']['data']['response']['players'][0]['gameid'] = '730';
    $result = sblog_steam_snapshot($config, false, steam_test_transport($responses, $calls));
    steam_test_same('ok', $result['status'], 'Valid responses with unavailable recent records must complete successfully.');
    steam_test_same('public', $result['library_state'], 'Fresh explicit public library data must stay public.');
    steam_test_same('Newly confirmed public game', $result['owned_games'][1]['name'], 'The library must use the newly confirmed response.');
    steam_test_same(2, $result['stats']['game_count'], 'The public library count must remain available.');
    steam_test_same(2400, $result['stats']['total_minutes'], 'Public lifetime playtime must remain available.');
    steam_test_same('private', $result['recent_state'], 'Unavailable recent data must stay separate from the public library.');
    steam_test_same([], $result['recent_games'], 'Unavailable recent game records must be hidden.');
    steam_test_same(null, $result['stats']['recent_minutes'], 'Unavailable recent playtime must not claim zero.');
    steam_test_same('Current game', $result['profile']['current_game'], 'A freshly confirmed public current game may be displayed.');
};

$tests['newly unavailable recent data clears an old public library when its refresh fails'] = static function (): void {
    $config = steam_test_config();
    $calls = [];
    sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    steam_test_expire_cache($config);
    $responses = steam_test_responses();
    $responses['recent']['data'] = ['response' => []];
    $responses['owned'] = ['ok' => false];
    $result = sblog_steam_snapshot($config, false, steam_test_transport($responses, $calls));
    steam_test_same([], $result['owned_games'], 'An old public library must be removed when its refresh fails and recent records are now unavailable.');
    steam_test_same([], $result['recent_games'], 'Unavailable recent records must hide their old games.');
    steam_test_same(['game_count' => null, 'total_minutes' => null, 'recent_minutes' => null], $result['stats'], 'Old public game totals must be removed without a fresh public library response.');
};

$tests['a freshly private profile hides game details from inconsistent responses'] = static function (): void {
    $calls = [];
    $responses = steam_test_responses();
    $responses['profile']['data']['response']['players'][0]['communityvisibilitystate'] = 1;
    $result = sblog_steam_snapshot(steam_test_config(), false, steam_test_transport($responses, $calls));
    steam_test_same([], $result['owned_games'], 'Fresh profile privacy must hide a public-looking library response.');
    steam_test_same([], $result['recent_games'], 'Fresh profile privacy must hide public-looking recent records.');
    steam_test_same(null, $result['stats']['game_count'], 'Private profiles must not expose old game totals.');
};

$tests['an outage does not retain public account data older than seven days'] = static function (): void {
    $config = steam_test_config();
    $calls = [];
    sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    $path = sblog_steam_cache_paths($config)['data'];
    $record = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $record['checked_at'] = time() - 9 * 86400;
    $record['next_retry_at'] = 0;
    $record['snapshot']['updated_at'] = time() - 8 * 86400;
    file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
    $responses = array_fill_keys(['profile', 'recent', 'owned'], ['ok' => false]);
    $result = sblog_steam_snapshot($config, false, steam_test_transport($responses, $calls));
    steam_test_same('error', $result['status'], 'An expired snapshot must stop being served as a stale fallback.');
    steam_test_same(null, $result['profile'], 'Long-expired personal data must not be retained.');
    steam_test_same([], $result['owned_games'], 'Long-expired game records must not be retained.');
};

$tests['structurally incomplete cache records are replaced before use'] = static function (): void {
    $config = steam_test_config();
    $calls = [];
    sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    $path = sblog_steam_cache_paths($config)['data'];
    $record = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    unset($record['snapshot']['profile']);
    file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
    $result = sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    steam_test_same(6, count($calls), 'An incomplete JSON model must not be trusted as a fresh snapshot.');
    steam_test_same('测试玩家', $result['profile']['name'], 'A structurally corrupt cache must recover by fetching a valid profile.');
};

$tests['public page and compact widget escape text, validate URLs and exclude secrets'] = static function (): void {
    $calls = [];
    $config = steam_test_config(['page_title' => '"><script>alert(3)</script>']);
    $snapshot = sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    $snapshot['profile']['name'] = '<img src=x onerror=alert(1)>';
    $snapshot['profile']['current_game'] = '"><script>alert(4)</script>';
    $snapshot['profile']['avatar'] = 'https://evil.example/avatar';
    $snapshot['profile']['profile_url'] = 'javascript:alert(1)';
    foreach (['recent_games', 'owned_games'] as $field) {
        $snapshot[$field][0]['name'] = '"><script>alert(2)</script>';
        $snapshot[$field][0]['cover_url'] = 'https://evil.example/cover';
    }
    foreach ([false, true] as $compact) {
        $html = sblog_steam_render($snapshot, $config, $compact);
        foreach (['<img src=x onerror=alert(1)>', '<script>alert(2)</script>', '<script>alert(3)</script>', '<script>alert(4)</script>', 'javascript:alert(1)', 'src="https://evil.example/'] as $unsafe) {
            steam_test_assert(!str_contains($html, $unsafe), 'Unsafe input must not reach page or widget markup: ' . $unsafe);
        }
        steam_test_assert(str_contains($html, '&lt;img src=x onerror=alert(1)&gt;'), 'A hostile player name must be displayed as escaped text.');
        steam_test_assert(!str_contains($html, $config['api_key']), 'Public HTML must not contain the API key.');
        steam_test_assert(str_contains($html, 'https://steamcommunity.com/profiles/76561198000000001/'), 'The player link must stay on Steam.');
    }
};

$tests['public error messages are escaped and display controls hide their sections'] = static function (): void {
    $html = sblog_steam_render(sblog_steam_empty_snapshot('error', '</p><script>alert(1)</script>'), steam_test_config());
    steam_test_assert(!str_contains($html, '<script>alert(1)</script>'), 'Error messages must be escaped.');
    steam_test_assert(str_contains($html, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'The escaped error message must remain readable.');
    $calls = [];
    $config = steam_test_config(['show_recent' => false, 'show_library' => false]);
    $snapshot = sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    $html = sblog_steam_render($snapshot, $config);
    steam_test_assert(!str_contains($html, 'aria-label="最近在玩"'), 'Disabling recent games must hide that section.');
    steam_test_assert(!str_contains($html, 'data-steam-library'), 'Disabling the library must hide its controls and game list.');
};

$tests['a compact widget limits games and keeps the full page link'] = static function (): void {
    $calls = [];
    $snapshot = sblog_steam_snapshot(steam_test_config(), false, steam_test_transport(steam_test_responses(), $calls));
    for ($index = 0; $index < 4; $index++) {
        $game = $snapshot['recent_games'][0];
        $game['appid'] = 1000 + $index;
        $snapshot['recent_games'][] = $game;
    }
    $html = sblog_steam_render($snapshot, steam_test_config(), true);
    steam_test_same(3, substr_count($html, '<li class="steam-showcase__game'), 'The homepage widget must stay compact.');
    steam_test_assert(str_contains($html, 'href="/index.php?a=steam"'), 'The widget must link to the complete game page.');
    steam_test_assert(!str_contains($html, 'data-steam-library'), 'The compact widget must not render full library controls.');
};

$tests['navigation and asset hooks produce escaped public links and honor disabling'] = static function (): void {
    $filter = $GLOBALS['steam_test_filters']['output_html'][20][0];
    $input = '<html><head></head><body class="theme-public"><nav class="text-nav"><a href="/">首页</a></nav><section data-steam-showcase></section></body></html>';
    sblog_steam_save_config(steam_test_config(['page_title' => '<script>alert(1)</script>']));
    $html = $filter($input, ['action' => 'steam']);
    steam_test_assert(str_contains($html, 'aria-current="page" href="/index.php?a=steam"'), 'The active navigation entry must point to the plugin route.');
    steam_test_assert(!str_contains($html, '<script>alert(1)</script>'), 'Configured navigation labels must be escaped.');
    steam_test_assert(str_contains($html, '/plugins/steam-showcase/assets/style.css?v=1.0.1'), 'A rendered showcase must load its stylesheet.');
    steam_test_assert(str_contains($html, '/plugins/steam-showcase/assets/script.js?v=1.0.1'), 'A rendered showcase must load its client interactions.');
    sblog_steam_save_config(steam_test_config(['show_nav' => false]));
    steam_test_assert(!str_contains($filter($input, ['action' => 'home']), 'href="/index.php?a=steam"'), 'Disabling navigation must remove the entry.');
    steam_test_same('<html><head></head><body>Admin</body></html>', $filter('<html><head></head><body>Admin</body></html>', ['action' => 'admin_plugins']), 'Public navigation hooks must leave admin output unchanged.');
};

$tests['pretty Steam URLs follow root and subdirectory installations'] = static function (): void {
    $GLOBALS['steam_test_pretty_url'] = true;
    steam_test_same('/steam', sblog_steam_url(), 'A root installation must use the Steam path.');
    $_SERVER['SCRIPT_NAME'] = '/blog/index.php';
    steam_test_same('/blog/steam', sblog_steam_url(), 'The Steam path must preserve the blog installation directory.');
};

$tests['query URLs remain available without pretty URLs and admin URLs always use the script'] = static function (): void {
    foreach (['/index.php', '/blog/index.php'] as $script) {
        $_SERVER['SCRIPT_NAME'] = $script;
        $GLOBALS['steam_test_pretty_url'] = false;
        steam_test_same($script . '?a=steam', sblog_steam_url(), 'Disabling pretty URLs must produce the existing query link.');
        foreach ([false, true] as $pretty) {
            $GLOBALS['steam_test_pretty_url'] = $pretty;
            steam_test_same($script . '?a=admin_steam', sblog_steam_url('admin_steam'), 'The settings page must remain an explicit administrative query route.');
        }
    }
};

$tests['Steam path routes accept trailing slashes and query strings at the correct base'] = static function (): void {
    foreach (['/index.php' => ['/steam', '/steam/', '/steam?utm_source=nav', '/steam/?utm_source=nav', '/index.php/steam', '/index.php/steam/?utm_source=nav'], '/blog/index.php' => ['/blog/steam', '/blog/steam/', '/blog/steam?utm_source=nav', '/blog/index.php/steam', '/blog/index.php/steam/?utm_source=nav']] as $script => $paths) {
        $_SERVER['SCRIPT_NAME'] = $script;
        foreach ($paths as $path) {
            steam_test_same('steam', steam_test_route('page', $path, ['slug' => 'steam', 'utm_source' => 'nav'], ['slug' => 'steam']), 'The public Steam path must be claimed: ' . $path);
            steam_test_same('steam', $_GET['a'] ?? null, 'The route must update the GET action for the plugin request hook.');
            steam_test_same('steam', $_REQUEST['a'] ?? null, 'The route must update the request action consistently.');
            steam_test_same('nav', $_GET['utm_source'], 'Routing must preserve unrelated query parameters.');
        }
    }
};

$tests['Steam path matching decodes the request path and does not trust only a page slug'] = static function (): void {
    steam_test_same('steam', steam_test_route('page', '/%73team?source=encoded', ['slug' => 'steam']), 'An encoded Steam path must resolve consistently with the core path parser.');
    steam_test_same('page', steam_test_route('page', '/pages/steam', ['slug' => 'steam'], ['slug' => 'steam']), 'An ordinary page with the same slug must retain its route.');
    steam_test_same(['slug' => 'steam'], $_GET, 'An unclaimed ordinary page must not change query data.');
};

$tests['unrelated paths and explicit query actions retain their original routes'] = static function (): void {
    foreach (['/index.php' => ['/archives', '/pages/steam', '/steaming', '/steam/library'], '/blog/index.php' => ['/steam', '/other/steam', '/blog/archives', '/blog/pages/steam', '/blog/steaming', '/blogger/steam']] as $script => $paths) {
        $_SERVER['SCRIPT_NAME'] = $script;
        foreach ($paths as $path) {
            $query = ['slug' => 'steam', 'a' => 'page'];
            steam_test_same('page', steam_test_route('page', $path, $query, ['slug' => 'steam']), 'The plugin must leave unrelated paths unchanged: ' . $path);
            steam_test_same($query, $_GET, 'Unclaimed paths must preserve their GET action and parameters.');
            steam_test_same($query, $_REQUEST, 'Unclaimed paths must preserve their request data.');
        }
    }
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    foreach (['steam', 'admin_steam', 'archives'] as $action) {
        $query = ['a' => $action];
        steam_test_same($action, steam_test_route($action, '/index.php?a=' . $action, $query), 'An explicit query route must pass through unchanged.');
        steam_test_same($query, $_GET, 'A direct query request must retain its original data.');
    }
    steam_test_same('admin_steam', steam_test_route('admin_steam', '/steam', ['a' => 'admin_steam']), 'The filter must preserve actions that the core has already resolved as administrative.');
};

$tests['a canonical Steam path replaces a conflicting query action after core path parsing'] = static function (): void {
    steam_test_same('steam', steam_test_route('page', '/steam?a=admin_steam', ['a' => 'page', 'slug' => 'steam'], ['slug' => 'steam']), 'A Steam path parsed as a page must become the public Steam route.');
    steam_test_same('steam', $_GET['a'], 'The public path must drive the request hook rather than the conflicting query string.');
};

$tests['navigation widget and settings links use the chosen public URL'] = static function (): void {
    $GLOBALS['steam_test_admin'] = true;
    $calls = [];
    $config = steam_test_config();
    $snapshot = sblog_steam_snapshot($config, false, steam_test_transport(steam_test_responses(), $calls));
    sblog_steam_save_config($config);
    $filter = $GLOBALS['steam_test_filters']['output_html'][20][0];
    $document = '<html><head></head><body class="theme-public"><nav class="text-nav"></nav></body></html>';
    foreach ([['/index.php', true, '/steam'], ['/blog/index.php', true, '/blog/steam'], ['/blog/index.php', false, '/blog/index.php?a=steam']] as [$script, $pretty, $url]) {
        $_SERVER['SCRIPT_NAME'] = $script;
        $GLOBALS['steam_test_pretty_url'] = $pretty;
        $link = 'href="' . h($url) . '"';
        steam_test_assert(str_contains($filter($document, ['action' => 'steam']), $link), 'Navigation must use the current public Steam URL.');
        steam_test_assert(str_contains(sblog_steam_render($snapshot, $config, true), $link), 'The compact widget must use the current public Steam URL.');
        sblog_steam_admin($config);
        $html = $GLOBALS['steam_test_rendered'];
        steam_test_assert(str_contains($html, $link), 'The settings page must display the current public Steam URL.');
        steam_test_assert(str_contains($html, 'action="' . h($script . '?a=admin_steam') . '"'), 'Settings forms must continue posting to the admin query URL.');
    }
};

$failed = 0;
foreach ($tests as $name => $test) {
    steam_test_reset();
    try {
        $test();
        fwrite(STDOUT, "PASS {$name}\n");
    } catch (Throwable $exception) {
        $failed++;
        fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n");
    }
}
fwrite(STDOUT, sprintf("\n%d tests, %d failures. No requests were sent to Steam.\n", count($tests), $failed));
exit($failed === 0 ? 0 : 1);
