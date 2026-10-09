<?php

declare(strict_types=1);

if (!defined('CACHE_DIR')) {
    http_response_code(404);
    exit;
}

/** Only cover paths on Douban's image CDN can be registered for local delivery. */
function sblog_douban_cover_source_url(string $url): string
{
    $url = sblog_douban_image_url($url);
    $parts = $url === '' ? [] : parse_url($url);
    return is_array($parts) && !isset($parts['query'])
        && preg_match('~^/view/(?:photo|subject)/(?:[A-Za-z0-9_-]+/){1,4}[A-Za-z0-9_-]+\.(?:jpe?g|png|webp|gif)$~D', (string)($parts['path'] ?? ''))
        ? $url : '';
}

function sblog_douban_cover_paths(string $key): array
{
    $base = rtrim(CACHE_DIR, '/\\') . '/douban-showcase-cover-' . $key;
    return ['source' => $base . '.json', 'image' => $base . '.bin', 'lock' => $base . '.lock'];
}

function sblog_douban_cover_source(string $path, string $key): ?array
{
    if (!is_file($path) || @filesize($path) > 4096) {
        return null;
    }
    $json = @file_get_contents($path);
    $source = is_string($json) ? json_decode($json, true) : null;
    return is_array($source) && is_string($source['url'] ?? null)
        && $source['url'] !== '' && sblog_douban_cover_source_url($source['url']) === $source['url']
        && hash_equals($key, hash('sha256', $source['url']))
        && is_int($source['updated_at'] ?? null) && $source['updated_at'] >= 0
        && is_int($source['next_retry_at'] ?? null) && $source['next_retry_at'] >= 0
        ? $source : null;
}

/** The public endpoint accepts a registered hash, never a client-supplied upstream URL. */
function sblog_douban_cover_url(string $url): string
{
    $url = sblog_douban_cover_source_url($url);
    if ($url === '') {
        return '';
    }
    $key = hash('sha256', $url);
    $paths = sblog_douban_cover_paths($key);
    if (sblog_douban_cover_source($paths['source'], $key) === null) {
        sblog_douban_write_cache($paths['source'], ['url' => $url, 'updated_at' => 0, 'next_retry_at' => 0]);
    }
    return sblog_douban_url('douban_showcase_cover', ['key' => $key]);
}

function sblog_douban_cover_image(string $body): ?array
{
    if ($body === '' || strlen($body) > 2 * 1024 * 1024) {
        return null;
    }
    $size = @getimagesizefromstring($body);
    if (!is_array($size) || !in_array($size['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)
        || $size[0] < 1 || $size[1] < 1 || $size[0] > 16000 || $size[1] > 16000
        || $size[0] * $size[1] > 40000000) {
        return null;
    }
    return ['body' => $body, 'type' => $size['mime']];
}

function sblog_douban_cover_fetch(string $url): array
{
    $failure = ['ok' => false, 'body' => '', 'type' => ''];
    if ($url === '' || sblog_douban_cover_source_url($url) !== $url || !function_exists('curl_init')) {
        return $failure;
    }
    $handle = curl_init($url);
    if ($handle === false) {
        return $failure;
    }
    $body = '';
    $options = [CURLOPT_RETURNTRANSFER => false, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SBlog-Douban-Showcase/1.0.2)',
        CURLOPT_HTTPHEADER => ['Accept: image/jpeg,image/png,image/webp,image/gif', 'Referer: https://www.douban.com/'],
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2 * 1024 * 1024) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        }];
    $trust = function_exists('curl_trust_options') ? curl_trust_options() : [];
    curl_setopt_array($handle, array_replace($trust, $options));
    $ok = curl_exec($handle);
    $code = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $type = strtolower(trim(explode(';', (string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE))[0]));
    curl_close($handle);
    return $ok !== false && $code === 200 ? ['ok' => true, 'body' => $body, 'type' => $type] : $failure;
}

/** Cache successful images for a week, with a five-minute failure cooldown. */
function sblog_douban_cover_data(string $key, ?callable $transport = null): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $key)) {
        return null;
    }
    $paths = sblog_douban_cover_paths($key);
    $source = sblog_douban_cover_source($paths['source'], $key);
    if ($source === null) {
        return null;
    }
    $now = time();
    $readImage = static function (array $source) use ($paths, $now): ?array {
        if ($source['updated_at'] < $now - 30 * 86400 || !is_file($paths['image']) || @filesize($paths['image']) > 2 * 1024 * 1024) {
            return null;
        }
        $body = @file_get_contents($paths['image']);
        return is_string($body) ? sblog_douban_cover_image($body) : null;
    };
    $cached = $readImage($source);
    if (($cached !== null && $source['updated_at'] > $now - 7 * 86400) || $source['next_retry_at'] > $now) {
        return $cached;
    }
    $lock = @fopen($paths['lock'], 'c');
    if ($lock === false) {
        return $cached;
    }
    $locked = @flock($lock, LOCK_EX | LOCK_NB);
    if (!$locked && $cached === null) {
        // Another request may be downloading this cover for the first time.
        $deadline = microtime(true) + 5.5;
        do {
            usleep(100000);
            $locked = @flock($lock, LOCK_EX | LOCK_NB);
        } while (!$locked && microtime(true) < $deadline);
    }
    if (!$locked) {
        fclose($lock);
        return $cached;
    }
    try {
        $source = sblog_douban_cover_source($paths['source'], $key);
        if ($source === null) {
            return null;
        }
        $cached = $readImage($source);
        if (($cached !== null && $source['updated_at'] > $now - 7 * 86400) || $source['next_retry_at'] > $now) {
            return $cached;
        }
        $response = ($transport ?? 'sblog_douban_cover_fetch')($source['url']);
        $image = ($response['ok'] ?? false) && is_string($response['body'] ?? null)
            ? sblog_douban_cover_image($response['body']) : null;
        if ($image !== null && ($response['type'] ?? '') === $image['type']) {
            $temporary = @tempnam(dirname($paths['image']), 'douban-cover-');
            if (is_string($temporary)) {
                @chmod($temporary, 0600);
                $written = @file_put_contents($temporary, $image['body'], LOCK_EX);
                if ($written === strlen($image['body']) && @rename($temporary, $paths['image'])) {
                    $source['updated_at'] = $now;
                    $source['next_retry_at'] = 0;
                    sblog_douban_write_cache($paths['source'], $source);
                    return $image;
                }
                @unlink($temporary);
            }
            // The response remains usable even if the disk cache cannot be written.
            return $image;
        }
        $source['next_retry_at'] = $now + 300;
        sblog_douban_write_cache($paths['source'], $source);
        return $cached;
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function sblog_douban_render_cover(): never
{
    $key = isset($_GET['key']) && is_string($_GET['key']) ? $_GET['key'] : '';
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        http_response_code(404);
        exit;
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $image = sblog_douban_cover_data($key);
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
    if ($image === null) {
        http_response_code(404);
        header('Cache-Control: no-store');
        exit;
    }
    header('Content-Type: ' . $image['type']);
    header('Content-Length: ' . strlen($image['body']));
    header('Cache-Control: public, max-age=86400');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $image['body'];
    }
    exit;
}
