<?php

declare(strict_types=1);

if (($argv[1] ?? '') === '--optional-lock-worker') {
    if (count($argv) !== 6) {
        exit(2);
    }
    define('DATA_DIR', (string)$argv[2]);
    require_once dirname(__DIR__) . '/plugins/comment-enhancer/local-geo.php';
    $barrier = (string)$argv[3];
    $log = (string)$argv[4];
    $worker = (string)$argv[5];
    $deadline = microtime(true) + 10;
    while (!is_file($barrier) && microtime(true) < $deadline) {
        usleep(1000);
    }
    if (!is_file($barrier)) {
        exit(3);
    }
    sce_with_optional_local_geo_lock(static function () use ($log, $worker): void {
        file_put_contents($log, $worker . ':start' . PHP_EOL, FILE_APPEND | LOCK_EX);
        usleep(150000);
        file_put_contents($log, $worker . ':end' . PHP_EOL, FILE_APPEND | LOCK_EX);
    });
    exit(0);
}

$sceTestDataDirectory = sys_get_temp_dir()
    . DIRECTORY_SEPARATOR
    . 'sblog-comment-enhancer-'
    . bin2hex(random_bytes(6));
if (!mkdir($sceTestDataDirectory, 0700, true) && !is_dir($sceTestDataDirectory)) {
    throw new RuntimeException('Unable to create the comment-enhancer test directory.');
}
define('DATA_DIR', $sceTestDataDirectory);

require_once dirname(__DIR__) . '/plugins/comment-enhancer/lib.php';
require_once dirname(__DIR__) . '/plugins/comment-enhancer/local-geo.php';

$failures = [];

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path) || is_link($path)) {
        if (file_exists($path) || is_link($path)) {
            @unlink($path);
        }
        return;
    }

    $entries = scandir($path);
    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
    }
    @rmdir($path);
};
register_shutdown_function($removeTree, $sceTestDataDirectory);

$expect = static function (mixed $actual, mixed $expected, string $label) use (&$failures): void {
    if ($actual !== $expected) {
        $failures[] = $label . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

foreach ([
    0 => 1, 1 => 1, 4 => 1, 5 => 2, 9 => 2, 10 => 3, 19 => 3,
    20 => 4, 49 => 4, 50 => 5, 99 => 5, 100 => 6,
] as $count => $level) {
    $expect(sce_level_for_count($count), $level, 'level boundary ' . $count);
}

$edge = sce_parse_user_agent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/140.0 Safari/537.36 Edg/140.0');
$expect($edge, ['browser' => 'Edge', 'os' => 'Windows 10/11'], 'Edge before Chrome');

$iosChrome = sce_parse_user_agent('Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 CriOS/140.0 Mobile/15E148 Safari/604.1');
$expect($iosChrome, ['browser' => 'Chrome', 'os' => 'iOS'], 'Chrome on iOS');

$webView = sce_parse_user_agent('Mozilla/5.0 (Linux; Android 14; Pixel 8 Build/AP1A; wv) AppleWebKit/537.36 Version/4.0 Chrome/140.0 Mobile Safari/537.36');
$expect($webView, ['browser' => 'Android WebView', 'os' => 'Android'], 'Android WebView before Chrome');

$legacyEdge = sce_parse_user_agent('Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/42.0 Safari/537.36 Edge/12.246');
$expect($legacyEdge, ['browser' => 'Edge', 'os' => 'Windows 10/11'], 'legacy Edge before Chrome');

$phone = sce_parse_user_agent('Mozilla/5.0 (Linux; Android 13; CUBOT X20 Pro) AppleWebKit/537.36 Chrome/112.0 Mobile Safari/537.36');
$expect($phone, ['browser' => 'Chrome', 'os' => 'Android'], 'phone model is not a bot');

$crawler = sce_parse_user_agent('Mozilla/5.0 (compatible; AhrefsBot/7.0; +http://ahrefs.com/robot/)');
$expect($crawler, ['browser' => 'Bot', 'os' => ''], 'known crawler');

$ipadDesktop = sce_parse_user_agent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1');
$expect($ipadDesktop, ['browser' => 'Safari', 'os' => 'iPadOS'], 'iPad desktop mode before macOS');

$browserIcons = [
    'Bot' => 'generic-bot',
    'Android WebView' => 'product-android-webview',
    'Edge' => 'brand-edge',
    'Opera' => 'brand-opera',
    'Samsung Internet' => 'brand-samsung-internet',
    'WeChat' => 'brand-wechat',
    'QQ Browser' => 'brand-qq-browser',
    'UC Browser' => 'brand-uc-browser',
    'Firefox' => 'brand-firefox',
    'Chrome' => 'brand-chrome',
    'Safari' => 'brand-safari',
    'Internet Explorer' => 'brand-internet-explorer',
    'Unknown Browser' => 'generic-browser',
];
foreach ($browserIcons as $browser => $icon) {
    $expect(sce_browser_icon_name($browser), $icon, $browser . ' icon');
}

$osIcons = [
    'HarmonyOS' => 'brand-harmonyos',
    'Windows Phone' => 'brand-windows-phone',
    'Windows 10/11' => 'brand-windows',
    'Windows 8.1' => 'brand-windows',
    'Windows 8' => 'brand-windows',
    'Windows 7' => 'brand-windows',
    'Windows' => 'brand-windows',
    'iPadOS' => 'brand-apple',
    'iOS' => 'brand-apple',
    'macOS' => 'brand-apple',
    'Android' => 'brand-android',
    'ChromeOS' => 'brand-chromeos',
    'Ubuntu' => 'brand-ubuntu',
    'Linux' => 'brand-linux',
    'Unknown OS' => 'generic-os',
];
foreach ($osIcons as $os => $icon) {
    $expect(sce_os_icon_name($os), $icon, $os . ' icon');
}

$iconIds = array_unique(array_merge(array_values($browserIcons), array_values($osIcons)));
foreach ($iconIds as $iconId) {
    $svg = sce_icon_svg($iconId);
    $expect(str_starts_with($svg, '<svg '), true, $iconId . ' SVG starts with root element');
    $expect(str_ends_with($svg, '</svg>'), true, $iconId . ' SVG ends with root element');
    $expect(substr_count($svg, '<svg'), 1, $iconId . ' SVG has one opening root');
    $expect(substr_count($svg, '</svg>'), 1, $iconId . ' SVG has one closing root');
    $expect(str_contains($svg, 'viewBox="0 0 24 24"'), true, $iconId . ' SVG viewBox');
    $expect(str_contains($svg, 'aria-hidden="true"'), true, $iconId . ' SVG hidden from accessibility tree');
    $expect(str_contains($svg, 'focusable="false"'), true, $iconId . ' SVG is not focusable');
    $expect(preg_match('/^<svg\b[^>]*>\s*.+\s*<\/svg>$/s', $svg), 1, $iconId . ' SVG has nonempty content');
    $expect(preg_match('/<(?:script|foreignObject)\b/i', $svg), 0, $iconId . ' SVG has no active elements');
    $expect(preg_match('/\son[a-z0-9_-]*\s*=/i', $svg), 0, $iconId . ' SVG has no event handlers');
    $expect(preg_match('/\s(?:xlink:)?href\s*=/i', $svg), 0, $iconId . ' SVG has no links');
}

$expect(sce_parse_user_agent(''), ['browser' => '', 'os' => ''], 'empty user agent');
$expect(sce_parse_user_agent('wx-miniprogram'), ['browser' => '', 'os' => ''], 'unknown user agent');
$expect(sce_ip_scope('8.8.8.8'), 'public', 'public IPv4');
$expect(sce_ip_scope('2001:4860:4860::8888'), 'public', 'public IPv6');
$expect(sce_ip_scope('10.4.3.2'), 'local', 'private IPv4');
$expect(sce_ip_scope('127.0.0.1'), 'local', 'loopback IPv4');
$expect(sce_ip_scope('100.64.0.1'), 'local', 'shared IPv4');
$expect(sce_ip_scope('fd12::1'), 'local', 'private IPv6');
$expect(sce_ip_scope('::ffff:10.0.0.1'), 'local', 'mapped private IPv4');
$expect(sce_ip_scope('::ffff:192.168.1.1'), 'local', 'mapped private IPv4 second range');
$expect(sce_ip_scope('2001:db8::1'), 'reserved', 'documentation IPv6');
$expect(sce_ip_scope('3fff::1'), 'reserved', 'documentation IPv6 second range start');
$expect(sce_ip_scope('3fff:0fff:ffff:ffff:ffff:ffff:ffff:ffff'), 'reserved', 'documentation IPv6 second range end');
$expect(sce_ip_scope('5f00::1'), 'reserved', 'SRv6 SID IPv6 start');
$expect(sce_ip_scope('5f00:ffff:ffff:ffff:ffff:ffff:ffff:ffff'), 'reserved', 'SRv6 SID IPv6 end');
$expect(sce_ip_scope('192.0.2.1'), 'reserved', 'documentation IPv4');
$expect(sce_ip_scope('198.18.0.1'), 'reserved', 'benchmark IPv4');
$expect(sce_ip_scope('224.0.0.1'), 'reserved', 'multicast IPv4');
$expect(sce_ip_scope('ff02::1'), 'reserved', 'multicast IPv6');
$expect(sce_ip_scope('not-an-ip'), 'invalid', 'invalid IP');
$expect(sce_canonical_ip('::ffff:81.2.69.160'), '81.2.69.160', 'mapped IPv4 canonicalization');
$expect(sce_canonical_ip('not-an-ip'), '', 'invalid IP canonicalization');

$chinaPayload = [
    'success' => true,
    'country_code' => 'CN',
    'country' => 'China',
    'region' => 'Jiangsu Sheng',
];
$expect(sce_format_geo_location($chinaPayload), '中国 · 江苏', 'Chinese province mapping');
$expect(sce_format_geo_location($chinaPayload, 'en-US'), 'China · Jiangsu Sheng', 'English location formatting');
$expect(json_decode(sce_geo_cache_value($chinaPayload), true), [
    'country_code' => 'CN',
    'country' => 'China',
    'region' => 'Jiangsu Sheng',
], 'locale-neutral location cache');
$expect(sce_format_geo_location([
    'country_code' => 'US',
    'country' => 'United States',
    'region' => 'California',
]), '美国', 'foreign country mapping');
$expect(sce_geo_cache_value(['success' => false]), '', 'failed lookup');
$expect(sce_clean_geo_text("<script>\0test</script>"), 'script test /script', 'geo text sanitizing');

$fixture = __DIR__ . '/fixtures/GeoIP2-City-Test.mmdb';
$fixtureHash = is_file($fixture) ? hash_file('sha256', $fixture) : false;
$expectedFixtureHash = 'ed972738e4e03a3e56e12041a6af4d91592249d110f7e4a647e5f2fa0e639c09';
$expect($fixtureHash, $expectedFixtureHash, 'MMDB fixture integrity');

$missingBasename = 'geoip-00000000000000000000000000000000.mmdb';
$missingStatus = sce_local_geo_database_status($missingBasename);
$expect(array_keys($missingStatus), [
    'valid', 'database_type', 'ip_version', 'supports_ipv4', 'supports_ipv6',
    'build_epoch', 'size', 'basename', 'error',
], 'local database status shape');
$expect($missingStatus['valid'], false, 'missing local database is invalid');
$expect($missingStatus['basename'], $missingBasename, 'missing local database basename');
$expect($missingStatus['error'], 'database_not_found', 'missing local database error');
$expect(sce_lookup_local_ip('81.2.69.160', $missingBasename), '', 'missing local database lookup does not fall back');
$expect(sce_local_geo_database_path('../GeoIP2-City-Test.mmdb'), '', 'unsafe local database basename rejected');

$managedDirectory = sce_local_geo_directory();
$lockBarrier = $sceTestDataDirectory . DIRECTORY_SEPARATOR . 'optional-lock-start';
$lockLog = $sceTestDataDirectory . DIRECTORY_SEPARATOR . 'optional-lock.log';
$lockProcesses = [];
for ($worker = 1; $worker <= 4; $worker++) {
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--optional-lock-worker', $sceTestDataDirectory, $lockBarrier, $lockLog, (string)$worker],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        $failures[] = 'optional lock concurrency worker could not start';
        continue;
    }
    fclose($pipes[0]);
    $lockProcesses[] = [$worker, $process, $pipes];
}
touch($lockBarrier);
foreach ($lockProcesses as [$worker, $process, $pipes]) {
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    $expect(
        $status,
        0,
        'optional lock concurrency worker ' . $worker
            . ($stdout !== '' || $stderr !== '' ? ' (' . trim($stdout . ' ' . $stderr) . ')' : '')
    );
}
$lockEvents = is_file($lockLog)
    ? file($lockLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
    : [];
$lockEvents = is_array($lockEvents) ? $lockEvents : [];
$expect(count($lockEvents), count($lockProcesses) * 2, 'optional lock concurrency event count');
$activeWorker = '';
foreach ($lockEvents as $event) {
    [$worker, $state] = array_pad(explode(':', $event, 2), 2, '');
    if ($state === 'start') {
        $expect($activeWorker, '', 'optional lock callbacks do not overlap');
        $activeWorker = $worker;
    } elseif ($state === 'end') {
        $expect($activeWorker, $worker, 'optional lock callback closes in order');
        $activeWorker = '';
    } else {
        $failures[] = 'optional lock emitted an invalid event: ' . $event;
    }
}
$expect($activeWorker, '', 'optional lock callback sequence completed');

$optionalLockResult = sce_with_optional_local_geo_lock(static function () use ($managedDirectory): string {
    return is_dir($managedDirectory)
        && is_file($managedDirectory . DIRECTORY_SEPARATOR . '.database-operation.lock')
        && (int)($GLOBALS['sce_local_geo_lock_depth'] ?? 0) === 1
        ? 'locked'
        : 'unlocked';
});
$expect($optionalLockResult, 'locked', 'optional lock creates and serializes the first local database operation');
$expect(
    is_dir($managedDirectory) || mkdir($managedDirectory, 0700, true),
    true,
    'managed local database directory created'
);

$invalidBytes = "this is not a MaxMind database\n";
$invalidBasename = 'geoip-' . substr(hash('sha256', $invalidBytes), 0, 32) . '.mmdb';
$invalidPath = sce_local_geo_database_path($invalidBasename);
$expect(file_put_contents($invalidPath, $invalidBytes), strlen($invalidBytes), 'invalid local database fixture written');
$invalidStatus = sce_local_geo_database_status($invalidBasename);
$expect($invalidStatus['valid'], false, 'invalid local database is rejected');
$expect($invalidStatus['error'], 'invalid_database', 'invalid local database error');
$expect($invalidStatus['size'], strlen($invalidBytes), 'invalid local database size reported');
$expect(sce_lookup_local_ip('81.2.69.160', $invalidBasename), '', 'invalid local database lookup does not fall back');
@unlink($invalidPath);

if (is_string($fixtureHash) && hash_equals($expectedFixtureHash, $fixtureHash)) {
    $databaseBasename = 'geoip-' . substr($fixtureHash, 0, 32) . '.mmdb';
    $databasePath = sce_local_geo_database_path($databaseBasename);
    $expect(copy($fixture, $databasePath), true, 'local database fixture installed');

    $status = sce_local_geo_database_status($databaseBasename);
    $expect($status['valid'], true, 'local database status valid');
    $expect($status['database_type'], 'GeoIP2-City', 'local database type');
    $expect($status['ip_version'], 6, 'local database IP version');
    $expect($status['supports_ipv4'], true, 'local database supports IPv4');
    $expect($status['supports_ipv6'], true, 'local database supports IPv6');
    $expect($status['build_epoch'], 1770245369, 'local database build epoch');
    $expect($status['size'], filesize($fixture), 'local database size');
    $expect($status['basename'], $databaseBasename, 'local database basename');
    $expect($status['error'], '', 'local database status error');

    $ipv4Value = sce_lookup_local_ip('81.2.69.160', $databaseBasename);
    $expect(json_decode($ipv4Value, true), [
        'country_code' => 'GB',
        'country' => 'United Kingdom',
        'region' => 'England',
    ], 'local database IPv4 lookup');

    $expect(json_decode(sce_lookup_local_ip('2001:218::', $databaseBasename), true), [
        'country_code' => 'JP',
        'country' => 'Japan',
        'region' => '',
    ], 'local database IPv6 lookup');
    $expect(
        sce_lookup_local_ip('::ffff:81.2.69.160', $databaseBasename),
        $ipv4Value,
        'local database mapped IPv4 lookup'
    );

    $staleBasename = 'geoip-11111111111111111111111111111111.mmdb';
    $stalePath = sce_local_geo_database_path($staleBasename);
    $expect(file_put_contents($stalePath, 'stale'), 5, 'stale local database written');
    $expect(
        sce_local_geo_database_files(),
        [$staleBasename, $databaseBasename],
        'managed local databases listed'
    );
    $expect(
        sce_cleanup_local_geo_databases($databaseBasename),
        ['removed' => 1, 'failed' => []],
        'stale local databases cleaned'
    );
    $expect(is_file($databasePath), true, 'selected local database preserved during cleanup');

    $installRejected = false;
    try {
        sce_install_local_geo_database([
            'error' => UPLOAD_ERR_OK,
            'tmp_name' => $fixture,
            'name' => 'GeoIP2-City-Test.mmdb',
            'size' => filesize($fixture),
        ], $databaseBasename);
    } catch (DomainException) {
        $installRejected = true;
    }
    $expect($installRejected, true, 'non-uploaded local database rejected');
    $expect(hash_file('sha256', $databasePath), $fixtureHash, 'rejected upload preserves current database');

    $expect(sce_delete_local_geo_database($databaseBasename), true, 'local database deleted');
    $expect(is_file($databasePath), false, 'deleted local database removed from disk');
    $expect(sce_delete_local_geo_database($databaseBasename), false, 'missing local database is not deleted twice');
}

$removeTree($sceTestDataDirectory);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "comment-enhancer tests passed" . PHP_EOL;
