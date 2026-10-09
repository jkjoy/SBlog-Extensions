<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$cacheDirectory = sys_get_temp_dir() . '/sblog-douban-covers-' . bin2hex(random_bytes(6));
mkdir($cacheDirectory, 0700, true);
define('CACHE_DIR', $cacheDirectory);
require dirname(__DIR__) . '/api.php';
require dirname(__DIR__) . '/covers.php';
function sblog_douban_url(string $action, array $params): string
{
    return '/index.php?' . http_build_query(array_merge($params, ['a' => $action]));
}
$checks = 0;
function cover_expect(bool $condition, string $message): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
}

try {
    $url = 'https://img3.doubanio.com/view/photo/s_ratio_poster/public/p2527119568.jpg';
    $key = hash('sha256', $url);
    $calls = 0;
    $png = (string)base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
    $transport = static function (string $source) use (&$calls, $url, $png): array {
        $calls++;
        cover_expect($source === $url, 'only the registered official source is requested');
        return ['ok' => true, 'body' => $png, 'type' => 'image/png'];
    };
    foreach (['../cache', str_repeat('a', 63), strtoupper($key), $key] as $unknown) {
        cover_expect(sblog_douban_cover_data($unknown, $transport) === null, 'invalid or unregistered keys cannot fetch');
    }
    cover_expect($calls === 0, 'unregistered keys cause no network request');
    $localUrl = sblog_douban_cover_url($url);
    cover_expect(str_contains($localUrl, 'a=douban_showcase_cover') && !str_contains($localUrl, 'doubanio.com'), 'local URL exposes only a registered hash');
    $image = sblog_douban_cover_data($key, $transport);
    cover_expect($image === ['body' => $png, 'type' => 'image/png'], 'actual raster image and detected MIME are preserved');
    cover_expect(sblog_douban_cover_data($key, $transport) === $image && $calls === 1, 'fresh image cache avoids another upstream request');
    $paths = sblog_douban_cover_paths($key);
    $source = sblog_douban_cover_source($paths['source'], $key);
    cover_expect($source !== null && $source['updated_at'] > 0 && $source['next_retry_at'] === 0, 'successful download records its cache time');
    $source['updated_at'] = time() - 8 * 86400;
    sblog_douban_write_cache($paths['source'], $source);
    $failures = 0;
    $failure = static function (string $source) use (&$failures): array {
        $failures++;
        return ['ok' => false, 'body' => 'Forbidden', 'type' => 'text/html'];
    };
    cover_expect(sblog_douban_cover_data($key, $failure) === $image, 'temporary failure preserves a recent successful cover');
    cover_expect(sblog_douban_cover_data($key, $failure) === $image && $failures === 1, 'failure cooldown prevents repeated download attempts');
    $source = sblog_douban_cover_source($paths['source'], $key);
    $source['updated_at'] = time() - 31 * 86400;
    sblog_douban_write_cache($paths['source'], $source);
    cover_expect(sblog_douban_cover_data($key, $failure) === null && $failures === 1, 'very old covers expire during the cooldown');
    $source['url'] = 'https://evil.example/cover.png';
    sblog_douban_write_cache($paths['source'], $source);
    cover_expect(sblog_douban_cover_data($key, $transport) === null && $calls === 1, 'tampered source metadata cannot redirect the downloader');

    foreach ([
        ['ok' => true, 'body' => '<html>Access denied</html>', 'type' => 'image/jpeg'],
        ['ok' => true, 'body' => $png, 'type' => 'text/html'],
        ['ok' => true, 'body' => '<svg xmlns="http://www.w3.org/2000/svg"></svg>', 'type' => 'image/svg+xml'],
        ['ok' => true, 'body' => str_repeat('x', 2 * 1024 * 1024 + 1), 'type' => 'image/png'],
    ] as $index => $response) {
        $sourceUrl = str_replace('p2527119568', 'p252711956' . $index, $url);
        sblog_douban_cover_url($sourceUrl);
        $sourceKey = hash('sha256', $sourceUrl);
        $attempts = 0;
        $badTransport = static function () use ($response, &$attempts): array { $attempts++; return $response; };
        cover_expect(sblog_douban_cover_data($sourceKey, $badTransport) === null, 'non-image, mismatched MIME, SVG or oversized content is rejected');
        cover_expect(sblog_douban_cover_data($sourceKey, $badTransport) === null && $attempts === 1, 'bad content also receives a failure cooldown');
        cover_expect(!is_file(sblog_douban_cover_paths($sourceKey)['image']), 'rejected bytes are never stored as an image');
    }
    sblog_douban_cover_url($url);
    cover_expect(sblog_douban_cover_data($key, $transport) === $image && $calls === 2, 'a valid cover can recover after the earlier invalid metadata');

    // Use a separate worker so the waiting request can observe a real lock release.
    // Its stdout handshake guarantees the parent starts while the first download holds the lock.
    $concurrentUrl = str_replace('p2527119568', 'p2527119599', $url);
    sblog_douban_cover_url($concurrentUrl);
    $concurrentKey = hash('sha256', $concurrentUrl);
    $concurrentPaths = sblog_douban_cover_paths($concurrentKey);
    cover_expect(!is_file($concurrentPaths['image']), 'concurrent first download begins without cached image bytes');
    $workerCode = <<<'PHP'
if (PHP_SAPI !== 'cli') { exit(1); }
define('CACHE_DIR', $argv[1]);
require $argv[4] . '/api.php';
require $argv[4] . '/covers.php';
$paths = sblog_douban_cover_paths($argv[2]);
$lock = fopen($paths['lock'], 'c');
if ($lock === false || !flock($lock, LOCK_EX)) { exit(2); }
try {
    echo "locked\n";
    fflush(STDOUT);
    if (trim((string)fgets(STDIN)) !== 'publish') { exit(3); }
    usleep(350000);
    $png = base64_decode($argv[3], true);
    if (!is_string($png) || file_put_contents($paths['image'], $png, LOCK_EX) !== strlen($png)) { exit(4); }
    $source = sblog_douban_cover_source($paths['source'], $argv[2]);
    if ($source === null) { exit(5); }
    $source['updated_at'] = time();
    $source['next_retry_at'] = 0;
    sblog_douban_write_cache($paths['source'], $source);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
echo "published\n";
PHP;
    $workerCommand = [PHP_BINARY];
    if (is_string(php_ini_loaded_file())) {
        array_push($workerCommand, '-c', php_ini_loaded_file());
    }
    array_push($workerCommand, '-r', $workerCode, $cacheDirectory, $concurrentKey, base64_encode($png), dirname(__DIR__));
    $worker = proc_open($workerCommand, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    cover_expect(is_resource($worker), 'independent cover download worker starts');
    try {
        cover_expect(trim((string)fgets($pipes[1])) === 'locked', 'first worker holds the lock before the second visitor arrives');
        $duplicateCalls = 0;
        $duplicateTransport = static function () use (&$duplicateCalls, $png): array {
            $duplicateCalls++;
            return ['ok' => true, 'body' => $png, 'type' => 'image/png'];
        };
        fwrite($pipes[0], "publish\n");
        fclose($pipes[0]);
        $waitingStarted = microtime(true);
        $sharedImage = sblog_douban_cover_data($concurrentKey, $duplicateTransport);
        $waitingSeconds = microtime(true) - $waitingStarted;
        cover_expect($sharedImage === $image, 'second visitor waits for and serves the first worker\'s completed image');
        cover_expect($duplicateCalls === 0, 'waiting visitor re-reads the published cache without a duplicate download');
        cover_expect($waitingSeconds >= 0.25 && $waitingSeconds < 5.5, 'first-download wait ends after publication within its bounded time');
        cover_expect(trim((string)stream_get_contents($pipes[1])) === 'published', 'first worker publishes its image and metadata');
        $workerErrors = (string)stream_get_contents($pipes[2]);
        cover_expect($workerErrors === '', 'independent worker exits without PHP errors');
        $publishedSource = sblog_douban_cover_source($concurrentPaths['source'], $concurrentKey);
        cover_expect($publishedSource !== null && $publishedSource['updated_at'] > 0
            && sblog_douban_cover_data($concurrentKey, $duplicateTransport) === $image && $duplicateCalls === 0,
            'the same published cover remains available to following visitors without fetching');
    } finally {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) { fclose($pipe); }
        }
        $workerExit = proc_close($worker);
    }
    cover_expect($workerExit === 0, 'independent download worker exits successfully');
    echo 'PASS: ' . $checks . " cover cache checks\n";
} finally {
    foreach (glob($cacheDirectory . '/*') ?: [] as $path) {
        if (is_file($path)) { unlink($path); }
    }
    rmdir($cacheDirectory);
}
