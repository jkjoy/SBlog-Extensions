<?php
declare(strict_types=1);

// Isolated public-player checks. No core bootstrap, actual credentials, or network.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

define('CACHE_DIR', sys_get_temp_dir() . '/sunny-music-test-' . bin2hex(random_bytes(8)));
mkdir(CACHE_DIR, 0700);
register_shutdown_function(static function (): void {
    foreach (glob(CACHE_DIR . '/*') ?: [] as $file) { @unlink($file); }
    @rmdir(CACHE_DIR);
});

function theme_setting(string $key, string $default = '', ?string $slug = null): string
{
    if ($slug !== 'sunny') { throw new RuntimeException('Music settings must remain scoped to Sunny.'); }
    return $GLOBALS['music_test_settings'][$key] ?? $default;
}
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function sunny_icon(string $name, int $size = 20): string { return '<svg aria-hidden="true"></svg>'; }

require dirname(__DIR__) . '/music.php';
require dirname(__DIR__) . '/music-view.php';

function music_test_assert(bool $condition, string $message): void
{
    $GLOBALS['music_test_assertions']++;
    if (!$condition) { throw new RuntimeException($message); }
}
function music_test_same(mixed $expected, mixed $actual, string $message): void
{
    music_test_assert($expected === $actual, $message);
}
function music_test_config(array $overrides = []): array
{
    return array_replace(['id' => '19723756', 'limit' => 50, 'mode' => 'list', 'volume' => 0.6, 'title' => '音乐单'], $overrides);
}
function music_test_payload(int $count = 3, bool $modern = false): array
{
    $tracks = [];
    for ($index = 0; $index < $count; $index++) {
        $tracks[] = $modern
            ? ['id' => 1000 + $index, 'name' => '歌曲 ' . $index, 'ar' => [['name' => '歌手甲'], ['name' => '歌手乙']], 'al' => ['picUrl' => 'http://p2.music.126.net/cover.jpg?param=200y200'], 'dt' => 123456]
            : ['id' => 1000 + $index, 'name' => '歌曲 ' . $index, 'artists' => [['name' => '歌手甲'], ['name' => '歌手乙']], 'album' => ['picUrl' => 'http://p1.music.126.net/cover.jpg'], 'duration' => 123456];
    }
    return ['code' => 200, $modern ? 'playlist' : 'result' => ['id' => 19723756, 'name' => '公开歌单', 'trackCount' => $count, 'privacy' => 0, 'tracks' => $tracks]];
}
function music_test_transport(array $payload, array &$calls): Closure
{
    return static function (string $endpoint) use ($payload, &$calls): array {
        $calls[] = $endpoint;
        return $payload;
    };
}
function music_test_cache_path(): string { return CACHE_DIR . '/sunny-music-19723756.json'; }
function music_test_change_cache(callable $callback): void
{
    $path = music_test_cache_path();
    $record = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    $callback($record);
    file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR));
}
function music_test_expire_cache(): void
{
    music_test_change_cache(static function (array &$record): void {
        $record['checked_at'] = time() - 3601;
        $record['next_retry_at'] = 0;
    });
}

$tests = [];
$tests['playlist IDs accept only the official playlist routes'] = static function (): void {
    foreach (['19723756', ' 19723756 ', 'https://music.163.com/#/playlist?id=19723756',
        'https://music.163.com/playlist?id=19723756&userid=12', 'http://music.163.com/playlist?id=19723756',
        'music.163.com/#/playlist?id=19723756', '/playlist?id=19723756',
        'https://y.music.163.com/m/playlist?id=19723756&app_version=9.0'] as $input) {
        music_test_same('19723756', sunny_music_playlist_id($input), 'An official desktop/mobile playlist route must normalize: ' . $input);
    }
    foreach (['', '0', '-1', '1.0', str_repeat('9', 21), 'https://evil.example/?id=19723756',
        'https://music.163.com.evil.example/playlist?id=19723756', 'https://evil.example/music.163.com/playlist?id=19723756',
        'https://music.163.com/song?id=19723756', 'https://music.163.com/album?id=19723756',
        'https://music.163.com/private/playlist?id=19723756', 'https://music.163.com/playlist?id[]=19723756',
        'https://music.163.com/playlist?id=19723756%0A', 'https://user:pass@music.163.com/playlist?id=19723756',
        'https://music.163.com:443/playlist?id=19723756', '//music.163.com/playlist?id=19723756',
        'javascript:playlist?id=19723756', 'https://music.163.com/#//evil.example/playlist?id=19723756',
        'https://music.163.com/playlist/19723756', "https://music.163.com/playlist?id=19723756\nextra"] as $input) {
        music_test_same('', sunny_music_playlist_id($input), 'Untrusted or non-playlist input must not become an API target: ' . $input);
    }
};
$tests['theme configuration is optional, scoped and bounded'] = static function (): void {
    music_test_same(null, sunny_music_config(), 'Music is disabled by default.');
    $GLOBALS['music_test_settings'] = ['music_enabled' => '1', 'music_playlist' => 'https://evil.example/?id=1'];
    music_test_same(null, sunny_music_config(), 'An invalid playlist must keep the player disabled.');
    $GLOBALS['music_test_settings']['music_playlist'] = '19723756';
    music_test_same(music_test_config(), sunny_music_config(), 'Default settings must produce the agreed public config.');
    $GLOBALS['music_test_settings'] += ['music_limit' => '200', 'music_mode' => 'random', 'music_volume' => '20', 'music_title' => ' 我的歌单 '];
    music_test_same(music_test_config(['limit' => 200, 'mode' => 'random', 'volume' => 0.2, 'title' => '我的歌单']), sunny_music_config(), 'Valid selection values must be normalized.');
    $GLOBALS['music_test_settings'] = ['music_enabled' => '1', 'music_playlist' => '19723756', 'music_limit' => '999', 'music_mode' => 'autoplay', 'music_volume' => '-5', 'music_title' => ''];
    music_test_same(music_test_config(), sunny_music_config(), 'Invalid options must fall back to bounded defaults.');
    foreach (['20', '50', '100', '200'] as $limit) {
        $GLOBALS['music_test_settings']['music_limit'] = $limit;
        music_test_same((int)$limit, sunny_music_config()['limit'], 'All declared display limits must work.');
    }
    foreach (['20', '40', '60', '80', '100'] as $volume) {
        $GLOBALS['music_test_settings']['music_volume'] = $volume;
        music_test_same((int)$volume / 100, sunny_music_config()['volume'], 'All declared volume percentages must work.');
    }
    $GLOBALS['music_test_settings']['music_mode'] = 'single';
    music_test_same('single', sunny_music_config()['mode'], 'Single-track looping must remain configurable.');
};
$tests['invalid snapshot config never invokes transport or writes cache'] = static function (): void {
    $calls = [];
    $result = sunny_music_snapshot(['id' => '../private'], music_test_transport(music_test_payload(), $calls));
    music_test_same('error', $result['status'], 'Invalid IDs must produce a safe empty state.');
    music_test_same([], $calls, 'Invalid IDs must not reach the transport.');
    music_test_same([], glob(CACHE_DIR . '/*') ?: [], 'Invalid IDs must not write any file.');
};
$tests['legacy and modern API models normalize public metadata'] = static function (): void {
    foreach ([false, true] as $modern) {
        $normalized = sunny_music_normalize(music_test_payload(3, $modern), '19723756');
        music_test_same('公开歌单', $normalized['name'], 'The real playlist title must survive.');
        music_test_same(3, count($normalized['tracks']), 'Both API shapes must supply their tracks.');
        music_test_same('1000', $normalized['tracks'][0]['id'], 'Track IDs must remain exact decimal strings.');
        music_test_same('歌手甲 / 歌手乙', $normalized['tracks'][0]['artist'], 'Artists must retain their order.');
        music_test_same(123, $normalized['tracks'][0]['duration'], 'Music durations convert milliseconds to seconds.');
        music_test_same('https://music.163.com/song/media/outer/url?id=1000.mp3', $normalized['tracks'][0]['url'], 'Audio sources must be derived from validated IDs.');
        music_test_assert(str_starts_with($normalized['tracks'][0]['cover'], 'https://p'), 'Official image URLs must upgrade to HTTPS.');
    }
};
$tests['image URLs reject other origins and ambiguous authorities'] = static function (): void {
    music_test_same('https://p1.music.126.net/image.jpg', sunny_music_cover_url('http://p1.music.126.net/image.jpg'), 'Upgrade official HTTP covers.');
    foreach (['https://evil.example/image.jpg', 'https://p1.music.126.net.evil.example/image.jpg',
        'https://user:pass@p1.music.126.net/image.jpg', 'https://p1.music.126.net:443/image.jpg',
        'javascript:alert(1)', '//p1.music.126.net/image.jpg', "https://p1.music.126.net/a\nb.jpg", 'https://p1.music.126.net\\evil.example/a'] as $input) {
        music_test_same('', sunny_music_cover_url($input), 'Only unambiguous official CDN covers are allowed.');
    }
};
$tests['normalization rejects bad records, removes duplicates and bounds text'] = static function (): void {
    $payload = music_test_payload();
    $payload['result']['tracks'][0]['name'] = "  " . str_repeat('音', 240) . "\n ";
    $payload['result']['tracks'][0]['url'] = 'https://evil.example/track.mp3';
    $payload['result']['tracks'][0]['album']['picUrl'] = 'https://evil.example/cover.jpg';
    $payload['result']['tracks'][] = $payload['result']['tracks'][0];
    $payload['result']['tracks'][] = ['id' => 'bad', 'name' => 'Bad ID'];
    $payload['result']['tracks'][] = ['id' => 3000, 'name' => "\xff"];
    $normalized = sunny_music_normalize($payload, '19723756');
    music_test_same(3, count($normalized['tracks']), 'Duplicate, missing and invalid UTF-8 records must not enter the public playlist.');
    music_test_same(180, preg_match_all('/./us', $normalized['tracks'][0]['name']), 'Names must be bounded by Unicode characters.');
    music_test_same('', $normalized['tracks'][0]['cover'], 'Unsafe covers must be discarded.');
    music_test_assert(!str_contains(json_encode($normalized, JSON_THROW_ON_ERROR), 'evil.example'), 'Raw API audio URLs must never be trusted.');
};
$tests['one cache identity supports every display limit'] = static function (): void {
    $calls = [];
    $payload = music_test_payload(250);
    $payload['result']['private_diagnostic'] = 'not-for-cache';
    $first = sunny_music_snapshot(music_test_config(['limit' => 20]), music_test_transport($payload, $calls));
    music_test_same(20, count($first['tracks']), 'Each result must honor its own limit.');
    $second = sunny_music_snapshot(music_test_config(['limit' => 200]), music_test_transport([], $calls));
    music_test_same(200, count($second['tracks']), 'A larger display limit must use the same complete bounded cache.');
    music_test_same(['https://music.163.com/api/playlist/detail?id=19723756'], $calls, 'Only the fixed official metadata endpoint may be requested.');
    $contents = (string)file_get_contents(music_test_cache_path());
    music_test_assert(!str_contains($contents, 'not-for-cache'), 'Public cache must exclude raw API fields.');
    music_test_same(1, count(glob(CACHE_DIR . '/*.json') ?: []), 'Changing the display limit must not create extra account identities.');
};
$tests['fresh cache avoids transport and expiration refreshes it'] = static function (): void {
    $calls = [];
    $first = sunny_music_snapshot(music_test_config(), music_test_transport(music_test_payload(), $calls));
    music_test_same($first, sunny_music_snapshot(music_test_config(), music_test_transport([], $calls)), 'Fresh results must remain unchanged.');
    music_test_same(1, count($calls), 'The one-hour cache must prevent redundant requests.');
    music_test_expire_cache();
    $payload = music_test_payload();
    $payload['result']['name'] = '刷新后的歌单';
    music_test_same('刷新后的歌单', sunny_music_snapshot(music_test_config(), music_test_transport($payload, $calls))['name'], 'Expired metadata must refresh.');
    music_test_same(2, count($calls), 'An expired cache must refresh exactly once.');
};
$tests['failures retain recent data and enforce five-minute cooldown'] = static function (): void {
    $calls = [];
    $first = sunny_music_snapshot(music_test_config(), music_test_transport(music_test_payload(), $calls));
    music_test_expire_cache();
    $stale = sunny_music_snapshot(music_test_config(), music_test_transport([], $calls));
    music_test_same('stale', $stale['status'], 'An outage must mark retained metadata as stale.');
    music_test_same($first['tracks'], $stale['tracks'], 'Recent public metadata must remain available during outages.');
    music_test_same($first['updated_at'], $stale['updated_at'], 'Stale results must not pretend to be newly synchronized.');
    sunny_music_snapshot(music_test_config(), music_test_transport(music_test_payload(), $calls));
    music_test_same(2, count($calls), 'Outages must suppress repeat requests during cooldown.');
    $record = json_decode((string)file_get_contents(music_test_cache_path()), true, 512, JSON_THROW_ON_ERROR);
    music_test_assert($record['next_retry_at'] >= time() + 299, 'Failure cooldown must last five minutes.');
};
$tests['failure without previous data caches a safe empty result'] = static function (): void {
    $calls = [];
    $result = sunny_music_snapshot(music_test_config(), music_test_transport(['code' => 500, 'message' => '<script>private-detail</script>'], $calls));
    music_test_same('error', $result['status'], 'Failures without metadata must stay errors.');
    music_test_same([], $result['tracks'], 'Failures must not synthesize sample tracks.');
    music_test_assert(!str_contains($result['message'], 'private-detail'), 'Remote error details must not be exposed.');
    sunny_music_snapshot(music_test_config(), music_test_transport(music_test_payload(), $calls));
    music_test_same(1, count($calls), 'An empty failed result also needs cooldown.');
};
$tests['confirmed privacy or removal replaces older public metadata'] = static function (): void {
    foreach ([['code' => 403], ['code' => 404], ['code' => 200, 'result' => ['privacy' => 10]]] as $private) {
        foreach (glob(CACHE_DIR . '/*') ?: [] as $file) { unlink($file); }
        $calls = [];
        sunny_music_snapshot(music_test_config(), music_test_transport(music_test_payload(), $calls));
        music_test_expire_cache();
        $result = sunny_music_snapshot(music_test_config(), music_test_transport($private, $calls));
        music_test_same('error', $result['status'], 'Confirmed unavailable playlists must not use stale public metadata.');
        music_test_same([], $result['tracks'], 'Privacy changes must clear all earlier public tracks.');
        music_test_same('', $result['name'], 'Privacy changes must clear the earlier title.');
    }
};
$tests['a public empty list differs from malformed API data'] = static function (): void {
    music_test_same(['name' => '公开歌单', 'tracks' => []], sunny_music_normalize(music_test_payload(0), '19723756'), 'Explicit public empty playlists remain valid.');
    foreach ([[], ['code' => 200], ['code' => 200, 'result' => []],
        ['code' => 200, 'result' => ['name' => 'Broken', 'tracks' => [], 'trackCount' => 10]],
        ['code' => 200, 'result' => ['name' => 'Broken', 'tracks' => [['id' => -1, 'name' => 'Bad']]]],
        ['code' => 200, 'result' => ['id' => 999, 'name' => 'Other account', 'tracks' => []]]] as $payload) {
        music_test_same([], sunny_music_normalize($payload, '19723756'), 'Malformed API data must never be labeled a public empty success.');
    }
};
$tests['metadata older than seven days is not retained during outages'] = static function (): void {
    $calls = [];
    sunny_music_snapshot(music_test_config(), music_test_transport(music_test_payload(), $calls));
    music_test_change_cache(static function (array &$record): void {
        $record['checked_at'] = time() - 8 * 86400;
        $record['snapshot']['updated_at'] = time() - 8 * 86400;
    });
    $result = sunny_music_snapshot(music_test_config(), music_test_transport([], $calls));
    music_test_same('error', $result['status'], 'Old data must expire even throughout a long outage.');
    music_test_same([], $result['tracks'], 'Long-expired tracks must disappear.');
};
$tests['concurrent workers do not duplicate metadata requests'] = static function (): void {
    $calls = [];
    $first = sunny_music_snapshot(music_test_config(), music_test_transport(music_test_payload(), $calls));
    music_test_expire_cache();
    $lock = fopen(CACHE_DIR . '/sunny-music-19723756.lock', 'c');
    music_test_assert(is_resource($lock) && flock($lock, LOCK_EX | LOCK_NB), 'The regression fixture must hold the refresh lock.');
    try {
        music_test_same($first, sunny_music_snapshot(music_test_config(), music_test_transport([], $calls)), 'Concurrent reads must retain the last usable snapshot.');
        music_test_same(1, count($calls), 'A held lock must prevent a duplicate request.');
    } finally { flock($lock, LOCK_UN); fclose($lock); }
};
$tests['cache corruption and unsafe cached sources trigger recovery'] = static function (): void {
    $calls = [];
    file_put_contents(music_test_cache_path(), '{invalid-json');
    music_test_same('ok', sunny_music_snapshot(music_test_config(), music_test_transport(music_test_payload(), $calls))['status'], 'Invalid JSON must be replaced.');
    music_test_change_cache(static function (array &$record): void { $record['snapshot']['tracks'][0]['url'] = 'https://evil.example/source.mp3'; });
    music_test_same('ok', sunny_music_snapshot(music_test_config(), music_test_transport(music_test_payload(), $calls))['status'], 'Unsafe cached sources must be replaced.');
    music_test_same(2, count($calls), 'Corrupt cache must not suppress recovery.');
};
$tests['transport exceptions cannot leak diagnostics or bypass cooldown'] = static function (): void {
    $calls = 0;
    $result = sunny_music_snapshot(music_test_config(), static function () use (&$calls): array { $calls++; throw new RuntimeException('private-url-token<script>'); });
    music_test_same('error', $result['status'], 'Transport exceptions must become safe errors.');
    music_test_assert(!str_contains(json_encode($result, JSON_THROW_ON_ERROR), 'private-url-token'), 'Exception details must remain private.');
    sunny_music_snapshot(music_test_config(), static function () use (&$calls): array { $calls++; return music_test_payload(); });
    music_test_same(1, $calls, 'Thrown transports must still create cooldown.');
};
$tests['public markup escapes metadata and serializes inert JSON'] = static function (): void {
    $calls = [];
    $payload = music_test_payload(1);
    $payload['result']['name'] = '</script><script>alert(1)</script>';
    $payload['result']['tracks'][0]['name'] = '<img src=x onerror=alert(2)>';
    $payload['result']['tracks'][0]['artists'][0]['name'] = 'Singer " & <script>alert(3)</script>';
    $snapshot = sunny_music_snapshot(music_test_config(), music_test_transport($payload, $calls));
    $config = music_test_config(['title' => '<script>音乐单</script>']);
    $player = sunny_render_music_player($snapshot, $config);
    $sidebar = sunny_render_music_sidebar($snapshot, $config);
    foreach ([$player, $sidebar] as $html) {
        music_test_assert(!str_contains($html, '<img src=x') && !str_contains($html, '<script>alert('), 'Song names and artist names must not create active markup.');
        music_test_assert(str_contains($html, '&lt;img src=x onerror=alert(2)&gt;'), 'Song names must remain visible as escaped text.');
        music_test_assert(str_contains($html, 'Singer &quot; &amp; &lt;script&gt;alert(3)&lt;/script&gt;'), 'Artist names must remain visible as escaped text.');
        music_test_assert(str_contains($html, '&lt;script&gt;音乐单&lt;/script&gt;'), 'Configured titles must be escaped.');
    }
    music_test_assert(preg_match('~<audio\b([^>]*)>~', $player, $audio) === 1
        && str_contains($audio[1], 'preload="none"') && !preg_match('/\bsrc\s*=|\bautoplay\b/i', $audio[1]), 'Audio must not preload or autoplay any source.');
    music_test_assert(preg_match('~<script id="sunny-music-data" type="application/json">(.*?)</script>~s', $player, $json) === 1, 'Player configuration must use inert JSON.');
    music_test_assert(!str_contains($json[1], '<') && str_contains($json[1], '\\u003C'), 'JSON must hex-escape HTML delimiters.');
    music_test_same($snapshot['tracks'], json_decode($json[1], true, 512, JSON_THROW_ON_ERROR)['tracks'], 'Safe JSON must preserve exact normalized metadata.');
};
$tests['empty snapshots hide the player and retain an official fallback link'] = static function (): void {
    $snapshot = sunny_music_empty_snapshot('19723756');
    music_test_same('', sunny_render_music_player($snapshot, music_test_config()), 'No valid track means no audio, dialog or player payload.');
    $sidebar = sunny_render_music_sidebar($snapshot, music_test_config());
    music_test_assert(str_contains($sidebar, 'href="https://music.163.com/playlist?id=19723756"') && str_contains($sidebar, '在网易云查看歌单'), 'Failure must retain the configured official playlist link.');
};

$failures = 0;
$GLOBALS['music_test_assertions'] = 0;
foreach ($tests as $name => $test) {
    foreach (glob(CACHE_DIR . '/*') ?: [] as $file) { unlink($file); }
    $GLOBALS['music_test_settings'] = [];
    try { $test(); fwrite(STDOUT, 'PASS ' . $name . "\n"); }
    catch (Throwable $error) { $failures++; fwrite(STDERR, 'FAIL ' . $name . ': ' . $error->getMessage() . "\n"); }
}
fwrite(STDOUT, count($tests) . ' tests, ' . $GLOBALS['music_test_assertions'] . ' assertions, ' . $failures . " failures. No network requests.\n");
exit($failures === 0 ? 0 : 1);
