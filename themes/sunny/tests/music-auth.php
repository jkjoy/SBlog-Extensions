<?php
declare(strict_types=1);

// Isolated authentication checks use fake cookies, fake API data and a temporary cache.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

define('CACHE_DIR', sys_get_temp_dir() . '/sunny-music-auth-test-' . bin2hex(random_bytes(8)));
mkdir(CACHE_DIR, 0700);
register_shutdown_function(static function (): void {
    foreach (glob(CACHE_DIR . '/*') ?: [] as $file) { @unlink($file); }
    @rmdir(CACHE_DIR);
});

function theme_setting(string $key, string $default = '', ?string $slug = null): string
{
    if ($slug !== 'sunny') { throw new RuntimeException('Music settings must remain scoped to Sunny.'); }
    return (string)($GLOBALS['music_auth_settings'][$key] ?? $default);
}
function setting(string $key, string $default = ''): string { return $default; }
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

require dirname(__DIR__) . '/music.php';
require dirname(__DIR__) . '/music-auth.php';

$GLOBALS['music_auth_assertions'] = 0;
$musicAuthCases = [];
function music_auth_assert(bool $condition, string $message): void
{
    $GLOBALS['music_auth_assertions']++;
    if (!$condition) { throw new RuntimeException($message); }
}
function music_auth_same(mixed $expected, mixed $actual, string $message): void
{
    music_auth_assert($expected === $actual, $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}
function music_auth_test(string $name, callable $run): void { $GLOBALS['musicAuthCases'][] = [$name, $run]; }
function music_auth_cookie(string $name): string { return 'MUSIC_U=fixture-' . $name . '; __csrf=fixture-csrf-' . $name; }
function music_auth_transport(array &$calls, ?callable $respond = null): Closure
{
    return static function (string $endpoint, array $parameters, string $cookie) use (&$calls, $respond): array {
        music_auth_assert(str_starts_with($endpoint, 'https://interface.music.163.com/api/'), 'Only the fixed official HTTPS API receives credentials.');
        $path = (string)parse_url($endpoint, PHP_URL_PATH);
        music_auth_assert(in_array($path, ['/api/nuser/account/get', '/api/song/enhance/player/url/v1'], true), 'Only account and playback endpoints are requested.');
        $calls[] = ['endpoint' => $endpoint, 'path' => $path, 'parameters' => $parameters, 'cookie' => $cookie];
        if ($respond !== null) { return $respond($path, $parameters, $cookie); }
        if ($path === '/api/nuser/account/get') { return ['code' => 200, 'account' => ['id' => 123], 'profile' => ['userId' => 123]]; }
        $ids = json_decode((string)($parameters['ids'] ?? ''), true);
        music_auth_assert(is_array($ids) && count($ids) === 1, 'Playback resolves exactly one song ID.');
        music_auth_same('standard', $parameters['level'] ?? null, 'The requested quality remains standard.');
        music_auth_same('mp3', $parameters['encodeType'] ?? null, 'The playback codec remains browser-compatible MP3.');
        return ['code' => 200, 'data' => [['id' => $ids[0], 'code' => 200, 'url' => 'https://m10.music.126.net/' . rawurlencode((string)$ids[0]) . '.mp3', 'expi' => 1200, 'time' => 240000, 'freeTrialInfo' => null]]];
    };
}
function music_auth_failure(string $cookieName, array $response, string $expectedCode, bool $skip): void
{
    $calls = [];
    $transport = music_auth_transport($calls, static fn(string $path): array => $path === '/api/nuser/account/get' ? ['code' => 200, 'account' => ['id' => 123]] : $response);
    $result = sunny_music_resolve_track('5001', music_auth_cookie($cookieName), false, $transport);
    music_auth_same(false, $result['ok'] ?? null, 'A rejected track cannot be reported as playable.');
    music_auth_same($expectedCode, $result['code'] ?? null, 'The response explains the failure safely.');
    music_auth_same($skip, $result['skip'] ?? null, 'The caller receives the correct skip behavior.');
    music_auth_assert(!isset($result['url']), 'A rejected playback response contains no usable URL.');
}

music_auth_test('cookies retain only login credentials and reject header injection', static function (): void {
    $cookie = sunny_music_cookie('unrelated=private; MUSIC_U=fixture-value==; __csrf=fixture-csrf; Path=/; Domain=.music.163.com');
    music_auth_assert(str_contains($cookie, 'MUSIC_U=fixture-value=='), 'MUSIC_U is retained without truncating equals signs.');
    music_auth_assert(str_contains($cookie, '__csrf=fixture-csrf'), 'The CSRF credential is retained.');
    music_auth_assert(!str_contains($cookie, 'private') && !str_contains($cookie, 'Path=') && !str_contains($cookie, 'Domain='), 'Unrelated cookie fields are never forwarded.');
    music_auth_same('', sunny_music_cookie('__csrf=only-csrf'), 'CSRF alone is not a logged-in account.');
    music_auth_same('', sunny_music_cookie('MUSIC_U='), 'An empty login credential is rejected.');
    foreach (["MUSIC_U=value\r\nX-Injected: yes", "MUSIC_U=value\0", "MUSIC_U=va\tlue", 'MUSIC_U=含有中文', 'MUSIC_U=' . str_repeat('x', 8193)] as $invalid) {
        music_auth_same('', sunny_music_cookie($invalid), 'Controls, non-ASCII and oversized cookie values are rejected.');
    }
});

music_auth_test('stream URLs are confined to the official HTTPS music CDN', static function (): void {
    music_auth_same('https://m10.music.126.net/a/song.mp3?token=value', sunny_music_stream_url('http://m10.music.126.net/a/song.mp3?token=value'), 'Official HTTP media URLs are upgraded to HTTPS.');
    music_auth_same('https://music.126.net/song.mp3', sunny_music_stream_url('https://music.126.net/song.mp3'), 'The exact CDN host is valid.');
    foreach ([null, [], '/song.mp3', '//m10.music.126.net/a.mp3', 'javascript:alert(1)', 'https://example.invalid/a.mp3', 'https://music.126.net.example.invalid/a.mp3', 'https://127.0.0.1/a.mp3', 'https://user:secret@m10.music.126.net/a.mp3', 'https://m10.music.126.net:443/a.mp3', "https://m10.music.126.net/a\nb.mp3", 'https://m10.music.126.net\\example.invalid/a.mp3'] as $invalid) {
        music_auth_same('', sunny_music_stream_url($invalid), 'Unsafe and unrelated stream URLs are rejected.');
    }
});

music_auth_test('public request tokens expire and are bound to playlist and account', static function (): void {
    $config = ['id' => '19723756', 'limit' => 50, 'mode' => 'list', 'volume' => 0.6, 'title' => 'Fixture playlist'];
    $cookie = music_auth_cookie('token');
    $now = time();
    $token = sunny_music_access_token($config, $cookie, $now);
    music_auth_assert((bool)preg_match('/^([0-9]+)\.[A-Za-z0-9_-]+$/D', $token, $parts), 'Tokens contain only an expiry and a signature.');
    music_auth_assert(!str_contains($token, 'fixture'), 'A public token never embeds raw credentials.');
    music_auth_same(true, sunny_music_access_valid($token, $config, $cookie, $now), 'The original account and playlist accept their unexpired token.');
    music_auth_same(false, sunny_music_access_valid($token, $config, $cookie, (int)$parts[1] + 1), 'Expired playback tokens are rejected.');
    music_auth_same(false, sunny_music_access_valid($token . 'a', $config, $cookie, $now), 'Tampered signatures are rejected.');
    music_auth_same(false, sunny_music_access_valid($token, array_replace($config, ['id' => '19723757']), $cookie, $now), 'A token cannot be reused for a different playlist.');
    music_auth_same(false, sunny_music_access_valid($token, $config, music_auth_cookie('another-account'), $now), 'Changing credentials invalidates existing tokens.');
    foreach (['', '0.signature', 'not.a.token', str_repeat('x', 3000)] as $invalid) {
        music_auth_same(false, sunny_music_access_valid($invalid, $config, $cookie, $now), 'Malformed tokens are rejected.');
    }
});

music_auth_test('successful streams reuse a private account-scoped cache', static function (): void {
    $calls = [];
    $transport = music_auth_transport($calls);
    $cookie = music_auth_cookie('cached');
    $first = sunny_music_resolve_track('1001', $cookie, false, $transport);
    music_auth_same(true, $first['ok'] ?? null, 'An authorized complete song can play.');
    music_auth_same('https://m10.music.126.net/1001.mp3', $first['url'] ?? null, 'The exact returned song URL is used.');
    music_auth_assert(is_int($first['expires_at'] ?? null) && $first['expires_at'] > time(), 'The client receives a bounded expiry.');
    music_auth_same(2, count($calls), 'The first song validates the account and resolves the URL once.');
    $again = sunny_music_resolve_track('1001', $cookie, false, $transport);
    music_auth_same($first['url'], $again['url'] ?? null, 'Repeated requests return the cached media URL.');
    music_auth_same(2, count($calls), 'Fresh URL and account caches avoid upstream requests.');
    sunny_music_resolve_track('1002', $cookie, false, $transport);
    music_auth_same(3, count($calls), 'A second song reuses the validated account but resolves its own URL.');
    sunny_music_resolve_track('1001', $cookie, true, $transport);
    music_auth_same(4, count($calls), 'A forced URL refresh bypasses only the media cache.');
    sunny_music_resolve_track('1001', music_auth_cookie('different-account'), false, $transport);
    music_auth_same(6, count($calls), 'A different Cookie never reuses the first account or URL cache.');
    $files = glob(CACHE_DIR . '/*') ?: [];
    music_auth_assert($files !== [], 'The isolated cache stores successful resolutions.');
    foreach ($files as $file) {
        $content = (string)file_get_contents($file);
        music_auth_assert(!str_contains($content, 'fixture-cached') && !str_contains($content, 'fixture-different-account') && !str_contains($content, '__csrf') && !str_contains(basename($file), 'fixture'), 'Neither cache contents nor filenames contain raw account credentials.');
    }
});

music_auth_test('an expired login is distinguished from track permissions', static function (): void {
    $calls = [];
    $transport = music_auth_transport($calls, static fn(): array => ['code' => 200, 'account' => null, 'profile' => null]);
    $result = sunny_music_resolve_track('2001', music_auth_cookie('expired'), false, $transport);
    music_auth_same(false, $result['ok'] ?? null, 'Anonymous API code 200 does not validate a Cookie.');
    music_auth_same('login_expired', $result['code'] ?? null, 'A missing account has a specific login-expired response.');
    music_auth_same(false, $result['skip'] ?? null, 'An expired login does not loop through every song.');
    music_auth_same(1, count($calls), 'No media URL request follows a rejected login.');
});

music_auth_test('expiring or corrupted playback caches are re-resolved before use', static function (): void {
    $calls = [];
    $cookie = music_auth_cookie('expiry');
    $transport = music_auth_transport($calls);
    $first = sunny_music_resolve_track('7001', $cookie, false, $transport);
    music_auth_same(true, $first['ok'] ?? null, 'The expiry fixture first resolves successfully.');
    $cache = '';
    foreach (glob(CACHE_DIR . '/*') ?: [] as $file) {
        $record = json_decode((string)file_get_contents($file), true);
        if (is_array($record) && ($record['url'] ?? '') === $first['url']) { $cache = $file; break; }
    }
    music_auth_assert($cache !== '', 'The playback result has an isolated cache record.');
    file_put_contents($cache, json_encode(array_replace($first, ['expires_at' => time() + 20])));
    sunny_music_resolve_track('7001', $cookie, false, $transport);
    music_auth_same(3, count($calls), 'A stream with less than thirty seconds remaining is resolved again.');
    file_put_contents($cache, json_encode(['ok' => true, 'url' => 'https://evil.invalid/stolen.mp3', 'expires_at' => time() + 600]));
    $restored = sunny_music_resolve_track('7001', $cookie, false, $transport);
    music_auth_same(4, count($calls), 'A corrupted cache cannot supply an unrelated media source.');
    music_auth_same($first['url'], $restored['url'] ?? null, 'A fresh upstream response replaces the corrupted media URL.');
});

music_auth_test('invalid song IDs and credentials never invoke transport', static function (): void {
    $calls = [];
    $transport = music_auth_transport($calls);
    foreach (['0', '-1', '1,2', 'https://example.invalid/song', str_repeat('9', 30)] as $id) {
        music_auth_same(false, sunny_music_resolve_track($id, music_auth_cookie('bad-id'), false, $transport)['ok'] ?? null, 'Only one bounded decimal song ID can be resolved.');
    }
    foreach (['', '__csrf=only', "MUSIC_U=fixture\r\nInjected=yes"] as $cookie) {
        music_auth_same('invalid_cookie', sunny_music_resolve_track('8001', $cookie, false, $transport)['code'] ?? null, 'Missing or malformed account credentials are rejected locally.');
    }
    music_auth_same([], $calls, 'Rejected local inputs produce no authenticated network work.');
});

music_auth_test('profile IDs can validate an account without an account record', static function (): void {
    $calls = [];
    $transport = music_auth_transport($calls, static fn(string $path): array => $path === '/api/nuser/account/get'
        ? ['code' => 200, 'account' => null, 'profile' => ['userId' => 456]]
        : ['code' => 200, 'data' => [['id' => 3001, 'code' => 200, 'url' => 'https://m10.music.126.net/profile.mp3', 'expi' => 300, 'freeTrialInfo' => null]]]);
    music_auth_same(true, sunny_music_resolve_track('3001', music_auth_cookie('profile'), false, $transport)['ok'] ?? null, 'A positive profile user ID establishes a valid login.');
});

music_auth_test('trial-only and unavailable songs never masquerade as full playback', static function (): void {
    music_auth_failure('trial', ['code' => 200, 'data' => [['id' => 5001, 'code' => 200, 'url' => 'https://m10.music.126.net/trial.mp3', 'expi' => 1200, 'freeTrialInfo' => ['start' => 0, 'end' => 30000]]]], 'trial_only', true);
    music_auth_failure('no-url', ['code' => 200, 'data' => [['id' => 5001, 'code' => 200, 'url' => null]]], 'unavailable', true);
    music_auth_failure('denied', ['code' => 200, 'data' => [['id' => 5001, 'code' => 404, 'url' => 'https://m10.music.126.net/denied.mp3']]], 'unavailable', true);
    music_auth_failure('other-id', ['code' => 200, 'data' => [['id' => 9999, 'code' => 200, 'url' => 'https://m10.music.126.net/wrong.mp3', 'expi' => 1200]]], 'unavailable', true);
    music_auth_failure('unsafe-host', ['code' => 200, 'data' => [['id' => 5001, 'code' => 200, 'url' => 'https://evil.invalid/song.mp3', 'expi' => 1200]]], 'unavailable', true);
});

music_auth_test('transport failures cannot disclose credentials or implementation details', static function (): void {
    $cookie = music_auth_cookie('transport-failure');
    $transport = static function () use ($cookie): array { throw new RuntimeException('Private upstream diagnostic: ' . $cookie); };
    $result = sunny_music_resolve_track('6001', $cookie, false, $transport);
    music_auth_same(false, $result['ok'] ?? null, 'An upstream exception does not produce playable media.');
    music_auth_same('temporary', $result['code'] ?? null, 'Transient upstream failures have a retryable classification.');
    $encoded = json_encode($result);
    music_auth_assert(!str_contains($encoded, 'fixture') && !str_contains($encoded, 'Private upstream') && !str_contains($encoded, 'MUSIC_U'), 'Public failure responses do not echo exceptions or credentials.');
});

$failures = 0;
foreach ($musicAuthCases as [$name, $run]) {
    try { $run(); echo 'PASS ' . $name . "\n"; }
    catch (Throwable $exception) { $failures++; fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . "\n"); }
}
echo count($musicAuthCases) . ' tests, ' . $GLOBALS['music_auth_assertions'] . ' assertions, ' . $failures . " failures. No network requests.\n";
exit($failures > 0 ? 1 : 0);
