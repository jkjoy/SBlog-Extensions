<?php
declare(strict_types=1);

if (!defined('CACHE_DIR')) { http_response_code(404); exit; }

/** Normalize only official work links. Query strings never reach the upstream request. */
function sblog_douban_subject_identity(string $value): ?array
{
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) { return null; }
    $parts = parse_url($value);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) { return null; }
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '');
    if (preg_match('/^(movie|book|music)\.douban\.com$/D', $host, $hostMatch)
        && preg_match('~^/subject/([1-9][0-9]{0,17})/?$~D', $path, $pathMatch)) {
        $type = $hostMatch[1];
        $id = $pathMatch[1];
    } elseif (in_array($host, ['m.douban.com', 'www.douban.com'], true)
        && preg_match('~^/(movie|book|music)/subject/([1-9][0-9]{0,17})/?$~D', $path, $pathMatch)) {
        $type = $pathMatch[1];
        $id = $pathMatch[2];
    } else { return null; }
    return ['type' => $type, 'id' => $id, 'url' => 'https://' . $type . '.douban.com/subject/' . $id . '/'];
}

function sblog_douban_subject_names(mixed $value, int $limit = 4): array
{
    if (is_string($value)) { $value = [$value]; }
    elseif (is_array($value) && isset($value['name'])) { $value = [$value]; }
    if (!is_array($value)) { return []; }
    $names = [];
    foreach ($value as $item) {
        $name = is_array($item) ? ($item['name'] ?? '') : $item;
        if (!is_string($name)) { continue; }
        $name = sblog_douban_text(strip_tags($name), 180);
        if ($name !== '') { $names[] = $name; }
        if (count($names) >= $limit) { break; }
    }
    return array_values(array_unique($names));
}

function sblog_douban_subject_normalize(array $subject, string $type, string $id): array
{
    $identity = sblog_douban_subject_identity('https://' . $type . '.douban.com/subject/' . $id . '/');
    if ($identity === null || $identity['type'] !== $type || $identity['id'] !== $id
        || (isset($subject['id']) && ((!is_string($subject['id']) && !is_int($subject['id'])) || (string)$subject['id'] !== $id))) { return []; }
    $text = static fn(mixed $value, int $limit = 500): string => is_string($value) ? sblog_douban_text(strip_tags($value), $limit) : '';
    $title = $text($subject['title'] ?? $subject['name'] ?? '', 240);
    if ($title === '') { return []; }
    $original = $text($subject['original_title'] ?? $subject['alternateName'] ?? $subject['subtitle'] ?? '', 240);
    if ($original === $title) { $original = ''; }
    $date = $text($subject['year'] ?? $subject['datePublished'] ?? '', 50);
    if ($date === '') { $date = implode(' ', sblog_douban_subject_names($subject['pubdate'] ?? [], 1)); }
    preg_match('/(?:18|19|20|21)[0-9]{2}/', $date, $yearMatch);
    $year = $yearMatch[0] ?? '';
    $ratingData = is_array($subject['rating'] ?? null) ? $subject['rating'] : [];
    $aggregate = is_array($subject['aggregateRating'] ?? null) ? $subject['aggregateRating'] : [];
    $ratingValue = $ratingData['value'] ?? $aggregate['ratingValue'] ?? null;
    $rating = is_numeric($ratingValue) && is_finite((float)$ratingValue) && (float)$ratingValue > 0 && (float)$ratingValue <= 10
        ? number_format((float)$ratingValue, 1, '.', '') : '';
    $count = $ratingData['count'] ?? $aggregate['ratingCount'] ?? 0;
    $count = is_numeric($count) ? max(0, min(2147483647, (int)$count)) : 0;
    $meta = array_merge($year !== '' ? [$year] : [], sblog_douban_subject_names($subject['genres'] ?? $subject['genre'] ?? [], 4));
    $credits = match ($type) {
        'movie' => ['导演' => $subject['directors'] ?? $subject['director'] ?? [], '主演' => $subject['actors'] ?? $subject['actor'] ?? []],
        'book' => ['作者' => $subject['author'] ?? [], '出版社' => $subject['press'] ?? $subject['publisher'] ?? []],
        'music' => ['表演者' => $subject['singer'] ?? $subject['byArtist'] ?? [], '发行' => $subject['publisher'] ?? []],
    };
    foreach ($credits as $label => $items) {
        $names = sblog_douban_subject_names($items, 3);
        if ($names !== []) { $meta[] = $label . '：' . implode(' / ', $names); }
    }
    if (is_string($subject['info'] ?? null) && trim($subject['info']) !== '') { $meta[] = $text($subject['info'], 500); }
    $cover = $subject['cover_url'] ?? $subject['image'] ?? ($subject['pic']['large'] ?? $subject['pic']['normal'] ?? '');
    if (is_array($cover)) { $cover = $cover['url'] ?? $cover[0] ?? ''; }
    return $identity + [
        'title' => $title, 'original_title' => $original, 'year' => $year,
        'rating' => $rating, 'rating_count' => $count, 'meta' => array_slice(array_values(array_unique($meta)), 0, 8),
        'summary' => $text($subject['intro'] ?? $subject['summary'] ?? $subject['description'] ?? '', 3000),
        'cover_url' => sblog_douban_image_url($cover),
    ];
}

/** Accept Douban's public JSON, JSON-LD, and its normal work page metadata. */
function sblog_douban_subject_parse(string $body, string $type, string $id): array
{
    $expected = sblog_douban_subject_identity('https://' . $type . '.douban.com/subject/' . $id . '/');
    if ($body === '' || strlen($body) > 2 * 1024 * 1024 || $expected === null
        || $expected['type'] !== $type || $expected['id'] !== $id) { return []; }
    $json = json_decode($body, true, 32);
    if (is_array($json)) { return sblog_douban_subject_normalize($json, $type, $id); }
    if (!class_exists('DOMDocument')) { return []; }
    $document = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    try { $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $body, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($previousErrors); }
    if (!$loaded) { return []; }
    $xpath = new DOMXPath($document);
    $pageTitle = sblog_douban_xpath_text($xpath, '//title', null, 200);
    if (preg_match('/(?:验证码|安全验证|异常请求|访问异常|访问过于频繁|登录豆瓣|豆瓣登录|Access Denied|Forbidden|robot check|captcha)/iu', $pageTitle)
        || $xpath->query("//input[contains(@name,'captcha')] | //form[contains(@action,'accounts.douban.com')]")?->length > 0) { return []; }
    $subject = [];
    foreach ($xpath->query('//script[@type="application/ld+json"]') ?: [] as $node) {
        $data = json_decode($node->textContent, true, 24);
        if (!is_array($data)) { continue; }
        $entries = isset($data['@graph']) && is_array($data['@graph']) ? $data['@graph'] : (array_values($data) === $data ? $data : [$data]);
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['name'] ?? null)) { continue; }
            $kinds = array_filter((array)($entry['@type'] ?? ''), 'is_string');
            $workTypes = ['movie' => ['Movie', 'TVSeries'], 'book' => ['Book'], 'music' => ['MusicAlbum', 'MusicRecording', 'MusicRelease']];
            if (!array_intersect($kinds, $workTypes[$type])) { continue; }
            if (isset($entry['url']) && (!is_string($entry['url']) || sblog_douban_subject_identity($entry['url']) !== $expected)) { continue; }
            $subject = $entry;
            break 2;
        }
    }
    $og = static function (string $name) use ($xpath): string {
        return sblog_douban_xpath_attr($xpath, '//meta[@property="og:' . $name . '" or @name="og:' . $name . '"]', 'content');
    };
    $pageUrl = $og('url');
    if ($pageUrl !== '' && sblog_douban_subject_identity($pageUrl) !== $expected) { return []; }
    $title = $og('title') ?: sblog_douban_xpath_text($xpath, '//h1/*[@property="v:itemreviewed"]', null, 240);
    if ($subject === [] && $title === '') { return []; }
    if ($title !== '') { $subject['title'] = $title; }
    $cover = $og('image') ?: sblog_douban_xpath_attr($xpath, '//*[@id="mainpic"]//img', 'src');
    if ($cover !== '') { $subject['cover_url'] = $cover; }
    $year = sblog_douban_xpath_text($xpath, '//h1/*[contains(concat(" ",normalize-space(@class)," ")," year ")]', null, 30);
    if ($year !== '') { $subject['year'] = $year; }
    $rating = sblog_douban_xpath_text($xpath, '//*[@property="v:average"]', null, 20);
    $ratingCount = sblog_douban_xpath_text($xpath, '//*[@property="v:votes"]', null, 30);
    if ($rating !== '') { $subject['rating'] = ['value' => $rating, 'count' => $ratingCount]; }
    $summary = sblog_douban_xpath_text($xpath, '//*[@property="v:summary"] | //*[@id="link-report"]//span[contains(@class,"all")]', null, 3000) ?: $og('description');
    if ($summary !== '') { $subject['summary'] = $summary; }
    $info = sblog_douban_xpath_text($xpath, '//*[@id="info"]', null, 500);
    if ($info !== '') { $subject['info'] = $info; }
    return sblog_douban_subject_normalize($subject, $type, $id);
}

/** The URL is constructed by this module. Redirects and user-supplied hosts are never followed. */
function sblog_douban_subject_request(string $url, float $timeoutSeconds): array
{
    $failure = ['ok' => false, 'body' => '', 'http_code' => 0];
    $api = preg_match('~^https://m\.douban\.com/rexxar/api/v2/(movie|book|music)/[1-9][0-9]{0,17}\?ck=&for_mobile=1$~D', $url) === 1;
    $identity = sblog_douban_subject_identity($url);
    if (!$api && ($identity === null || $identity['url'] !== $url)) { return $failure; }
    if (!function_exists('curl_init')) { return $failure; }
    $handle = curl_init($url);
    if ($handle === false) { return $failure; }
    $body = '';
    $timeoutMs = max(100, min(6000, (int)($timeoutSeconds * 1000)));
    $options = [CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT_MS => min(2000, $timeoutMs), CURLOPT_TIMEOUT_MS => $timeoutMs,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; SBlog-Douban-Showcase)', CURLOPT_ENCODING => '',
        CURLOPT_REFERER => 'https://m.douban.com/',
        CURLOPT_HTTPHEADER => ['Accept: application/json,text/html;q=0.9', 'Accept-Language: zh-CN,zh;q=0.9'],
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2 * 1024 * 1024) { return 0; }
            $body .= $chunk;
            return strlen($chunk);
        }];
    curl_setopt_array($handle, array_replace(function_exists('curl_trust_options') ? curl_trust_options() : [], $options));
    $ok = curl_exec($handle);
    $code = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return $ok !== false && $code === 200 ? ['ok' => true, 'body' => $body, 'http_code' => $code] : $failure;
}

function sblog_douban_subject_cache_path(string $type, string $id): string
{
    return rtrim(CACHE_DIR, '/\\') . '/douban-showcase-subject-' . $type . '-' . $id . '.json';
}

function sblog_douban_subject_read_cache(string $path, string $type, string $id): ?array
{
    if (!is_file($path) || @filesize($path) > 65536) { return null; }
    $json = @file_get_contents($path);
    $record = is_string($json) ? json_decode($json, true, 16) : null;
    if (!is_array($record) || ($record['version'] ?? null) !== 1 || !is_array($record['data'] ?? null)) { return null; }
    foreach (['updated_at', 'checked_at', 'next_retry_at'] as $key) {
        if (!is_int($record[$key] ?? null) || $record[$key] < 0 || $record[$key] > time() + 86400) { return null; }
    }
    $data = $record['data'];
    if ($data === []) { return $record['updated_at'] === 0 ? $record : null; }
    $expected = sblog_douban_subject_identity('https://' . $type . '.douban.com/subject/' . $id . '/');
    if ($expected === null || $expected['type'] !== $type || $expected['id'] !== $id
        || array_intersect_key($data, $expected) !== $expected || $record['updated_at'] === 0) { return null; }
    foreach (['title' => 1000, 'original_title' => 1000, 'year' => 4, 'rating' => 4, 'summary' => 12000, 'cover_url' => 2048] as $key => $limit) {
        if (!is_string($data[$key] ?? null) || strlen($data[$key]) > $limit) { return null; }
    }
    if ($data['title'] === '' || ($data['year'] !== '' && !preg_match('/^(18|19|20|21)[0-9]{2}$/D', $data['year']))
        || ($data['rating'] !== '' && (!is_numeric($data['rating']) || (float)$data['rating'] <= 0 || (float)$data['rating'] > 10))
        || !is_int($data['rating_count'] ?? null) || $data['rating_count'] < 0 || $data['rating_count'] > 2147483647
        || ($data['cover_url'] !== '' && sblog_douban_image_url($data['cover_url']) !== $data['cover_url'])
        || !is_array($data['meta'] ?? null) || array_values($data['meta']) !== $data['meta'] || count($data['meta']) > 8) { return null; }
    foreach ($data['meta'] as $item) { if (!is_string($item) || strlen($item) > 2000) { return null; } }
    return $record;
}

/** Fetch/cache a public subject; temporary failures retain up to seven days of successful data. */
function sblog_douban_subject_data(string $type, string $id, ?callable $transport = null): array
{
    $identity = sblog_douban_subject_identity('https://' . $type . '.douban.com/subject/' . $id . '/');
    if ($identity === null || $identity['type'] !== $type || $identity['id'] !== $id) { return []; }
    $path = sblog_douban_subject_cache_path($type, $id);
    $now = time();
    $ttl = 360 * 60;
    if (function_exists('sblog_douban_config')) { $ttl = max(5, min(1440, (int)(sblog_douban_config()['cache_minutes'] ?? 360))) * 60; }
    $cached = sblog_douban_subject_read_cache($path, $type, $id);
    $stale = static fn(?array $record): array => $record !== null && $record['updated_at'] >= $now - 7 * 86400 ? $record['data'] : [];
    if ($cached !== null && ($cached['next_retry_at'] > $now || ($cached['data'] !== [] && $cached['updated_at'] > $now - $ttl))) { return $stale($cached); }
    $lock = @fopen($path . '.lock', 'c');
    if ($lock === false) { return $stale($cached); }
    if (!@flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); return $stale($cached); }
    try {
        $cached = sblog_douban_subject_read_cache($path, $type, $id);
        if ($cached !== null && ($cached['next_retry_at'] > $now || ($cached['data'] !== [] && $cached['updated_at'] > $now - $ttl))) { return $stale($cached); }
        $fetch = $transport ?? 'sblog_douban_subject_request';
        $deadline = microtime(true) + 6;
        $urls = ['https://m.douban.com/rexxar/api/v2/' . $type . '/' . $id . '?ck=&for_mobile=1', $identity['url']];
        $data = [];
        foreach ($urls as $index => $url) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= .1) { break; }
            try { $response = $fetch($url, min($index === 0 ? 3.0 : 6.0, $remaining)); }
            catch (Throwable) { $response = []; }
            if (!is_array($response) || empty($response['ok']) || !is_string($response['body'] ?? null)) { continue; }
            $data = sblog_douban_subject_parse($response['body'], $type, $id);
            if ($data !== []) { break; }
        }
        $record = ['version' => 1, 'checked_at' => $now, 'next_retry_at' => $data !== [] ? 0 : $now + 300,
            'updated_at' => $data !== [] ? $now : ($stale($cached) !== [] ? $cached['updated_at'] : 0),
            'data' => $data !== [] ? $data : $stale($cached)];
        sblog_douban_write_cache($path, $record);
        return $record['data'];
    } finally { @flock($lock, LOCK_UN); fclose($lock); }
}
