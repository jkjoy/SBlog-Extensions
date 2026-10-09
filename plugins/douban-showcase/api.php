<?php
declare(strict_types=1);

// Loaded by the plugin; this file is not a public API endpoint.
if (!defined('CACHE_DIR')) {
    http_response_code(404);
    exit;
}

function sblog_douban_empty_snapshot(string $status, string $message): array
{
    return ['status' => $status, 'message' => $message, 'updated_at' => 0, 'profile' => null,
        'items' => [], 'total' => null, 'truncated' => false];
}

function sblog_douban_valid_id(string $id): bool
{
    return (bool)preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/D', $id);
}

function sblog_douban_list_url(string $userId, string $type = 'movie', string $status = 'collect', int $start = 0): string
{
    if (!sblog_douban_valid_id($userId) || !in_array($type, ['movie', 'book', 'music'], true)
        || !in_array($status, ['collect', 'wish', 'do'], true) || $start < 0 || $start > 1000000) {
        return '';
    }
    return 'https://' . $type . '.douban.com/people/' . rawurlencode($userId) . '/' . $status . '?'
        . http_build_query(['start' => $start, 'sort' => 'time', 'rating' => 'all', 'filter' => 'all', 'mode' => 'grid'], '', '&', PHP_QUERY_RFC3986);
}

/** Covers and avatars may use Douban's image CDN, never an arbitrary remote host. */
function sblog_douban_image_url(mixed $value): string
{
    if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $value)) {
        return '';
    }
    if (str_starts_with($value, '//')) {
        $value = 'https:' . $value;
    }
    $url = parse_url($value);
    if (!is_array($url) || !in_array(strtolower((string)($url['scheme'] ?? '')), ['https', 'http'], true)
        || isset($url['user']) || isset($url['pass']) || (isset($url['port']) && $url['port'] !== 443)) {
        return '';
    }
    $host = strtolower((string)($url['host'] ?? ''));
    if (!preg_match('/^img[0-9]*\.doubanio\.com$/D', $host)
        && !preg_match('/^img[0-9]*\.douban\.com$/D', $host)) {
        return '';
    }
    $path = (string)($url['path'] ?? '');
    return str_starts_with($path, '/') ? 'https://' . $host . $path . (isset($url['query']) ? '?' . $url['query'] : '') : '';
}

function sblog_douban_text(string $value, int $limit = 2000): string
{
    $value = trim((string)preg_replace('/[\s\x{00A0}\x00-\x1F\x7F]+/u', ' ', $value));
    preg_match('/^.{0,' . max(1, min(65535, $limit)) . '}/us', $value, $matches);
    return $matches[0] ?? '';
}

function sblog_douban_xpath_text(DOMXPath $xpath, string $query, ?DOMNode $context = null, int $limit = 2000): string
{
    $node = $xpath->query($query, $context)?->item(0);
    return $node instanceof DOMNode ? sblog_douban_text($node->textContent, $limit) : '';
}

function sblog_douban_xpath_attr(DOMXPath $xpath, string $query, string $attribute, ?DOMNode $context = null): string
{
    $node = $xpath->query($query, $context)?->item(0);
    return $node instanceof DOMElement ? $node->getAttribute($attribute) : '';
}

/** Resolve only an official HTTPS URL, without userinfo, ports, or backslashes. */
function sblog_douban_official_url(string $value, string $type): string
{
    if ($value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7F\\\\]/', $value)) {
        return '';
    }
    if (str_starts_with($value, '/') && !str_starts_with($value, '//')) {
        $value = 'https://' . $type . '.douban.com' . $value;
    } elseif (str_starts_with($value, '//')) {
        $value = 'https:' . $value;
    }
    $url = parse_url($value);
    return is_array($url) && ($url['scheme'] ?? '') === 'https'
        && ($url['host'] ?? '') === $type . '.douban.com'
        && !isset($url['user']) && !isset($url['pass'])
        && !isset($url['port']) ? $value : '';
}

/** Parse public grid pages. Login, challenge, and changed HTML are errors, not empty lists. */
function sblog_douban_parse_page(string $html, string $userId, string $type = 'movie', string $status = 'collect', int $start = 0): array
{
    $error = ['status' => 'error', 'message' => '豆瓣页面暂时无法读取，可能需要登录、访问验证或页面结构已变化。',
        'profile' => null, 'items' => [], 'total' => null, 'next_start' => null];
    if (!class_exists('DOMDocument') || $html === '' || strlen($html) > 2 * 1024 * 1024
        || sblog_douban_list_url($userId, $type, $status, $start) === '') {
        return $error;
    }
    $document = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    try {
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
    }
    if (!$loaded) {
        return $error;
    }
    $xpath = new DOMXPath($document);
    $class = static fn(string $name): string => "contains(concat(' ',normalize-space(@class),' '),' " . $name . " ')";
    $heading = sblog_douban_xpath_text($xpath, "//*[@id='db-usr-profile']//h1", null, 200);
    $title = sblog_douban_xpath_text($xpath, '//title', null, 200);
    $visible = '';
    foreach ($xpath->query("//*[@id='content']//text()[not(ancestor::script) and not(ancestor::style)]") ?: [] as $text) {
        $visible .= ' ' . $text->textContent;
    }
    if ($visible === '') {
        foreach ($xpath->query('//body//text()[not(ancestor::script) and not(ancestor::style)]') ?: [] as $text) {
            $visible .= ' ' . $text->textContent;
        }
    }
    $visible = sblog_douban_text($visible, 250000);
    $rows = $xpath->query("//*[" . $class('grid-view') . "]//*[" . $class('item') . "]");
    $rowCount = $rows === false ? 0 : $rows->length;
    if ($rowCount === 0 && preg_match('/(?:仅(?:自己|本人)可见|设为私密|设为隐私|设置了隐私|(?:记录|列表|收藏|书架)[^。]{0,16}(?:不公开|不对外公开)|无权查看|没有权限(?:查看|访问)|不允许(?:你|您)?(?:查看|访问))/u', $visible)) {
        return array_replace($error, ['status' => 'private', 'message' => '该豆瓣记录未公开，无法展示；已清除这份列表的旧公开缓存。']);
    }
    if (preg_match('/(?:验证码|安全验证|异常请求|访问异常|访问过于频繁|登录豆瓣|豆瓣登录|Access Denied|Forbidden|robot check|captcha)/iu', $title)
        || $xpath->query("//input[contains(@name,'captcha')] | //form[contains(@action,'accounts.douban.com')]")?->length > 0) {
        return $error;
    }
    $labels = ['movie' => ['collect' => '看过的(?:影视|电影)', 'wish' => '想看的(?:影视|电影)', 'do' => '在看的(?:影视|电影)'],
        'book' => ['collect' => '读过的书', 'wish' => '想读的书', 'do' => '在读的书'],
        'music' => ['collect' => '听过的音乐', 'wish' => '想听的音乐', 'do' => '在听的音乐']];
    if (!preg_match('/^(.*?)' . $labels[$type][$status] . '(?:\s*[（(]([\d,]+)[)）])?$/uD', $heading, $headingMatch)) {
        return $error;
    }
    $total = isset($headingMatch[2]) ? (int)str_replace(',', '', $headingMatch[2]) : null;
    $subjectCount = sblog_douban_xpath_text($xpath, "//*[" . $class('subject-num') . "]", null, 100);
    if (preg_match('/\/\s*([\d,]+)\s*$/D', $subjectCount, $countMatch)) {
        $total = (int)str_replace(',', '', $countMatch[1]);
    }
    $canonicalId = $userId;
    foreach ($xpath->query("//*[@id='db-usr-profile']//a[@href]") ?: [] as $anchor) {
        $href = sblog_douban_official_url($anchor->getAttribute('href'), $type);
        if ($href !== '' && preg_match('~^/people/([A-Za-z0-9][A-Za-z0-9_-]{0,63})/$~D', (string)parse_url($href, PHP_URL_PATH), $match)) {
            $canonicalId = $match[1];
            break;
        }
    }
    $avatar = sblog_douban_xpath_attr($xpath, "//*[@id='db-usr-profile']//img | //*[" . $class('side-info-avatar') . "]//img | //*[" . $class('music-user-profile') . "]//img | //*[" . $class('user-profile') . "]//img", 'src');
    $profile = ['id' => $userId, 'name' => sblog_douban_text($headingMatch[1], 120) ?: '豆瓣用户',
        'avatar' => sblog_douban_image_url($avatar), 'url' => 'https://www.douban.com/people/' . $canonicalId . '/'];
    $items = [];
    foreach ($rows ?: [] as $row) {
        $anchor = $xpath->query('.//*[' . $class('title') . ']//a[@href]', $row)?->item(0);
        if (!$anchor instanceof DOMElement) {
            return $error;
        }
        $url = sblog_douban_official_url($anchor->getAttribute('href'), $type);
        if ($url === '' || !preg_match('~^/subject/([0-9]{1,18})/$~D', (string)parse_url($url, PHP_URL_PATH), $idMatch)) {
            return $error;
        }
        $itemTitle = sblog_douban_xpath_text($xpath, './/em', $anchor, 240) ?: sblog_douban_text($anchor->textContent, 240);
        if ($itemTitle === '') {
            return $error;
        }
        $ratingClass = sblog_douban_xpath_attr($xpath, ".//*[contains(@class,'rating') and contains(@class,'-t')]", 'class', $row);
        $rating = preg_match('/(?:^|\s)rating([1-5])-t(?:\s|$)/D', $ratingClass, $ratingMatch) ? (int)$ratingMatch[1] : 0;
        $date = sblog_douban_xpath_text($xpath, './/*[' . $class('date') . ']', $row, 50);
        $validDate = preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $date, $dateMatch)
            && checkdate((int)$dateMatch[2], (int)$dateMatch[3], (int)$dateMatch[1]);
        $tags = sblog_douban_xpath_text($xpath, './/*[' . $class('tags') . ']', $row, 500);
        $tags = (string)preg_replace('/^(?:tags?|标签)\s*[:：]\s*/iu', '', $tags);
        $items[] = ['id' => $idMatch[1], 'title' => $itemTitle, 'url' => 'https://' . $type . '.douban.com/subject/' . $idMatch[1] . '/',
            'cover_url' => sblog_douban_image_url(sblog_douban_xpath_attr($xpath, './/*[' . $class('pic') . ']//img', 'src', $row)),
            'rating' => $rating, 'date' => $validDate ? $date : '',
            'comment' => sblog_douban_xpath_text($xpath, './/*[' . $class('comment') . ']', $row),
            'intro' => sblog_douban_xpath_text($xpath, './/*[' . $class('intro') . ']', $row, 1500),
            'tags' => $tags === '' ? [] : array_slice(array_values(array_filter(preg_split('/\s+/u', $tags) ?: [])), 0, 20)];
    }
    // An authenticated-looking shell alone does not prove an empty public collection.
    if ($items === [] && $total !== 0) {
        return $error;
    }
    $nextNode = $xpath->query("//*[" . $class('paginator') . "]//*[" . $class('next') . "]//a[@href] | //link[@rel='next' and @href]")?->item(0);
    $nextStart = null;
    if ($nextNode instanceof DOMElement) {
        $nextUrl = sblog_douban_official_url($nextNode->getAttribute('href'), $type);
        $nextPath = (string)parse_url($nextUrl, PHP_URL_PATH);
        parse_str((string)parse_url($nextUrl, PHP_URL_QUERY), $query);
        if ($nextUrl === '' || !in_array($nextPath, ['/people/' . $userId . '/' . $status, '/people/' . $canonicalId . '/' . $status], true)
            || !is_string($query['start'] ?? null) || !preg_match('/^[0-9]{1,7}$/D', $query['start'])
            || (int)$query['start'] <= $start || (int)$query['start'] > 1000000) {
            return $error;
        }
        $nextStart = (int)$query['start'];
    }
    if ($total !== null && ($total < count($items) || ($total === 0 && $nextStart !== null))) {
        return $error;
    }
    return ['status' => 'ok', 'message' => '', 'profile' => $profile, 'items' => $items, 'total' => $total, 'next_start' => $nextStart];
}

/** No cookies or challenge bypass. Redirects remain on the original official list host. */
function sblog_douban_request(string $url, float $timeoutSeconds = 12.0): array
{
    $failure = static fn(string $message, int $code = 0, string $body = ''): array => ['ok' => false, 'body' => $body, 'message' => $message, 'http_code' => $code];
    if (!function_exists('curl_init')) {
        return $failure('服务器未启用 cURL 扩展，无法获取豆瓣记录。');
    }
    $origin = parse_url($url);
    $host = (string)($origin['host'] ?? '');
    $type = explode('.', $host)[0];
    if (!in_array($type, ['movie', 'book', 'music'], true) || sblog_douban_official_url($url, $type) === '') {
        return $failure('豆瓣请求地址无效。');
    }
    $deadline = microtime(true) + max(0.001, min(12.0, $timeoutSeconds));
    for ($redirect = 0; $redirect <= 2; $redirect++) {
        if (!preg_match('~^/people/[A-Za-z0-9][A-Za-z0-9_-]{0,63}/(?:collect|wish|do)/?$~D', (string)parse_url($url, PHP_URL_PATH))) {
            return $failure('豆瓣要求登录或验证，请稍后重试。');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            return $failure('豆瓣暂时无法连接，请稍后重试。');
        }
        $body = '';
        $location = '';
        $tooLarge = false;
        $remainingMs = (int)(($deadline - microtime(true)) * 1000);
        if ($remainingMs <= 0) {
            curl_close($handle);
            return $failure('豆瓣连接超时，请稍后重试。');
        }
        $options = [CURLOPT_RETURNTRANSFER => false, CURLOPT_CONNECTTIMEOUT_MS => min(3000, $remainingMs),
            CURLOPT_TIMEOUT_MS => $remainingMs, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'SBlog-Douban-Showcase/1.0',
            CURLOPT_HTTPHEADER => ['Accept: text/html', 'Accept-Language: zh-CN,zh;q=0.9'], CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
                if (strlen($body) + strlen($chunk) > 2 * 1024 * 1024) {
                    $tooLarge = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
                if (stripos($header, 'Location:') === 0) {
                    $location = trim(substr($header, 9));
                }
                return strlen($header);
            }];
        $trust = function_exists('curl_trust_options') ? curl_trust_options() : [];
        curl_setopt_array($handle, array_replace($trust, $options));
        $ok = curl_exec($handle);
        $code = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        if ($ok === false || $tooLarge) {
            return $failure('豆瓣连接超时或返回内容过大，请稍后重试。', $code);
        }
        if (in_array($code, [301, 302, 303, 307, 308], true)) {
            $next = sblog_douban_official_url($location, $type);
            if ($next === '' || $redirect === 2) {
                return $failure('豆瓣要求登录或验证，暂时无法同步。', $code);
            }
            $url = $next;
            continue;
        }
        if ($code !== 200) {
            return $failure($code === 404 ? '未找到该豆瓣用户或记录，请检查豆瓣 ID。'
                : '豆瓣暂时拒绝访问或服务不可用，请稍后重试。', $code, $body);
        }
        return ['ok' => true, 'body' => $body, 'message' => '', 'http_code' => $code];
    }
    return $failure('豆瓣暂时无法连接，请稍后重试。');
}

function sblog_douban_cache_paths(array $config, string $type = 'movie', string $status = 'collect'): array
{
    $identity = hash('sha256', trim((string)($config['user_id'] ?? '')) . ':' . $type . ':' . $status . ':' . max(1, min(10, (int)($config['max_pages'] ?? 3))));
    $base = rtrim(CACHE_DIR, '/\\') . '/douban-showcase-' . $identity;
    $privacyIdentity = hash('sha256', trim((string)($config['user_id'] ?? '')) . ':' . $type . ':' . $status);
    return ['data' => $base . '.json', 'lock' => $base . '.lock',
        'privacy' => rtrim(CACHE_DIR, '/\\') . '/douban-showcase-private-' . $privacyIdentity . '.json'];
}

/** A privacy notice invalidates every old page-limit variant of the same collection. */
function sblog_douban_read_privacy_marker(string $path): ?array
{
    if (!is_file($path) || @filesize($path) > 2048) {
        return null;
    }
    $json = @file_get_contents($path);
    $marker = is_string($json) ? json_decode($json, true) : null;
    return is_array($marker) && ($marker['version'] ?? null) === 1
        && is_int($marker['observed_at'] ?? null) && is_string($marker['token'] ?? null)
        && preg_match('/^[a-f0-9]{32}$/D', $marker['token']) ? $marker : null;
}

function sblog_douban_current_privacy_record(?array $record, ?array $marker): ?array
{
    return $record !== null && ($record['privacy_token'] ?? '') === ($marker['token'] ?? '') ? $record : null;
}

function sblog_douban_read_cache(string $path): ?array
{
    if (!is_file($path) || @filesize($path) > 4 * 1024 * 1024) {
        return null;
    }
    $json = @file_get_contents($path);
    $record = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($record) || ($record['version'] ?? null) !== 1 || !is_array($record['snapshot'] ?? null)
        || !is_int($record['checked_at'] ?? null) || !is_int($record['next_retry_at'] ?? null)
        || (isset($record['privacy_token']) && (!is_string($record['privacy_token'])
            || ($record['privacy_token'] !== '' && !preg_match('/^[a-f0-9]{32}$/D', $record['privacy_token']))))) {
        return null;
    }
    $snapshot = $record['snapshot'];
    if (!in_array($snapshot['status'] ?? '', ['ok', 'stale', 'error', 'private'], true)
        || !is_string($snapshot['message'] ?? null) || !is_int($snapshot['updated_at'] ?? null) || $snapshot['updated_at'] < 0
        || !is_bool($snapshot['truncated'] ?? null) || !array_key_exists('total', $snapshot)
        || ($snapshot['total'] !== null && (!is_int($snapshot['total']) || $snapshot['total'] < 0))
        || !is_array($snapshot['items'] ?? null) || array_values($snapshot['items']) !== $snapshot['items']
        || count($snapshot['items']) > 500 || !array_key_exists('profile', $snapshot)) {
        return null;
    }
    if ($snapshot['profile'] !== null) {
        $profile = $snapshot['profile'];
        if (!is_array($profile) || !is_string($profile['id'] ?? null) || !sblog_douban_valid_id($profile['id'])
            || !is_string($profile['name'] ?? null) || !is_string($profile['avatar'] ?? null)
            || ($profile['avatar'] !== '' && sblog_douban_image_url($profile['avatar']) !== $profile['avatar'])
            || !is_string($profile['url'] ?? null)
            || !preg_match('~^https://www\.douban\.com/people/[A-Za-z0-9][A-Za-z0-9_-]{0,63}/$~D', $profile['url'])) {
            return null;
        }
    }
    foreach ($snapshot['items'] as $item) {
        if (!is_array($item) || !is_string($item['id'] ?? null) || !preg_match('/^[0-9]{1,18}$/D', $item['id'])
            || !is_string($item['title'] ?? null) || !is_string($item['url'] ?? null)
            || !preg_match('~^https://(?:movie|book|music)\.douban\.com/subject/' . $item['id'] . '/$~D', $item['url'])
            || !is_string($item['cover_url'] ?? null) || ($item['cover_url'] !== '' && sblog_douban_image_url($item['cover_url']) !== $item['cover_url'])
            || !is_int($item['rating'] ?? null) || $item['rating'] < 0 || $item['rating'] > 5
            || !is_string($item['date'] ?? null) || ($item['date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $item['date']))
            || !is_string($item['comment'] ?? null) || !is_string($item['intro'] ?? null)
            || !is_array($item['tags'] ?? null) || array_values($item['tags']) !== $item['tags']) {
            return null;
        }
        foreach ($item['tags'] as $tag) {
            if (!is_string($tag)) {
                return null;
            }
        }
    }
    if (in_array($snapshot['status'], ['ok', 'stale'], true) && ($snapshot['profile'] === null || $snapshot['updated_at'] === 0)) {
        return null;
    }
    if (in_array($snapshot['status'], ['private', 'error'], true) && ($snapshot['profile'] !== null || $snapshot['items'] !== [] || $snapshot['updated_at'] !== 0)) {
        return null;
    }
    return $record;
}

function sblog_douban_write_cache(string $path, array $record): void
{
    $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $temporary = is_string($encoded) ? @tempnam(dirname($path), 'douban-write-') : false;
    if (!is_string($temporary)) {
        return;
    }
    @chmod($temporary, 0600);
    if (@file_put_contents($temporary, $encoded, LOCK_EX) !== false && @rename($temporary, $path)) {
        @chmod($path, 0600);
        return;
    }
    @unlink($temporary);
}

function sblog_douban_clear_cache(array $config): void
{
    // Clear only the configured account, including earlier page-limit settings.
    for ($pages = 1; $pages <= 10; $pages++) {
        foreach (['movie', 'book', 'music'] as $type) {
            foreach (['collect', 'wish', 'do'] as $status) {
                $paths = sblog_douban_cache_paths(array_replace($config, ['max_pages' => $pages]), $type, $status);
                if (!is_file($paths['data'])) {
                    continue;
                }
                $lock = @fopen($paths['lock'], 'c');
                if ($lock !== false) {
                    if (@flock($lock, LOCK_EX | LOCK_NB)) {
                        @unlink($paths['data']);
                        @flock($lock, LOCK_UN);
                    }
                    fclose($lock);
                }
            }
        }
    }
    foreach (['movie', 'book', 'music'] as $type) {
        foreach (['collect', 'wish', 'do'] as $status) {
            @unlink(sblog_douban_cache_paths($config, $type, $status)['privacy']);
        }
    }
}

/** Preserve successful data for no more than seven days; do not call old data fresh. */
function sblog_douban_failure_snapshot(?array $previous, string $message, int $now): array
{
    if ($previous !== null && in_array($previous['status'] ?? '', ['ok', 'stale'], true)
        && (int)($previous['updated_at'] ?? 0) > 0 && (int)$previous['updated_at'] >= $now - 7 * 86400) {
        $previous['status'] = 'stale';
        $previous['message'] = $message . ' 正在显示上次成功同步的记录。';
        return $previous;
    }
    return sblog_douban_empty_snapshot('error', $message);
}

/** $transport receives the official URL and returns {ok,body,message,http_code}. */
function sblog_douban_snapshot(array $config, string $type = 'movie', string $status = 'collect', bool $force = false, ?callable $transport = null): array
{
    $userId = trim((string)($config['user_id'] ?? ''));
    if ($userId === '' || !sblog_douban_valid_id($userId)) {
        return sblog_douban_empty_snapshot('unconfigured', '请在后台填写有效的豆瓣 ID：数字 ID 或个人主页的自定义用户名。');
    }
    if (sblog_douban_list_url($userId, $type, $status) === '') {
        return sblog_douban_empty_snapshot('error', '豆瓣记录类型或状态无效。');
    }
    $now = time();
    $ttl = max(5, min(1440, (int)($config['cache_minutes'] ?? 360))) * 60;
    $maxPages = max(1, min(10, (int)($config['max_pages'] ?? 3)));
    $paths = sblog_douban_cache_paths($config, $type, $status);
    $record = sblog_douban_read_cache($paths['data']);
    $cached = static function (?array $record) use ($now, $ttl, $force, $paths): ?array {
        $marker = sblog_douban_read_privacy_marker($paths['privacy']);
        if ($marker !== null && sblog_douban_current_privacy_record($record, $marker) === null) {
            return !$force && $marker['observed_at'] + $ttl > $now
                ? sblog_douban_empty_snapshot('private', '该豆瓣记录未公开，无法展示；已停止展示这份列表的旧公开缓存。') : null;
        }
        if ($record === null) {
            return null;
        }
        $snapshot = $record['snapshot'];
        if ($record['next_retry_at'] > $now || (!$force && in_array($snapshot['status'], ['ok', 'private'], true) && $record['checked_at'] + $ttl > $now)) {
            if (in_array($snapshot['status'], ['ok', 'stale'], true) && $snapshot['updated_at'] < $now - 7 * 86400) {
                return sblog_douban_empty_snapshot('error', '豆瓣历史缓存已过期，请稍后重新同步。');
            }
            return $snapshot;
        }
        return null;
    };
    if (($snapshot = $cached($record)) !== null) {
        return $snapshot;
    }
    $lock = @fopen($paths['lock'], 'c');
    if ($lock === false) {
        $record = sblog_douban_current_privacy_record($record, sblog_douban_read_privacy_marker($paths['privacy']));
        return sblog_douban_failure_snapshot($record['snapshot'] ?? null, '豆瓣缓存不可用，请检查缓存目录权限。', $now);
    }
    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        $record = sblog_douban_current_privacy_record($record, sblog_douban_read_privacy_marker($paths['privacy']));
        return sblog_douban_failure_snapshot($record['snapshot'] ?? null, '豆瓣记录正在同步，请稍后再试。', $now);
    }
    try {
        $latest = sblog_douban_read_cache($paths['data']);
        if (($snapshot = $cached($latest)) !== null) {
            return $snapshot;
        }
        $marker = sblog_douban_read_privacy_marker($paths['privacy']);
        $privacyToken = $marker['token'] ?? '';
        $previous = sblog_douban_current_privacy_record($latest ?? $record, $marker)['snapshot'] ?? null;
        $snapshot = sblog_douban_empty_snapshot('ok', '');
        $start = 0;
        $seen = [];
        $deadline = microtime(true) + 20;
        for ($pageNumber = 0; $pageNumber < $maxPages; $pageNumber++) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('豆瓣同步超时，请稍后重试或降低同步页数。');
            }
            $url = sblog_douban_list_url($userId, $type, $status, $start);
            try {
                $response = $transport !== null ? $transport($url) : sblog_douban_request($url, $deadline - microtime(true));
            } catch (Throwable $exception) {
                throw new RuntimeException('豆瓣暂时无法获取，请稍后重试。');
            }
            $page = null;
            if (is_array($response) && in_array($response['http_code'] ?? 0, [401, 403], true) && is_string($response['body'] ?? null)) {
                $candidate = sblog_douban_parse_page($response['body'], $userId, $type, $status, $start);
                if ($candidate['status'] === 'private') {
                    $page = $candidate;
                }
            }
            if ($page === null && (!is_array($response) || ($response['ok'] ?? false) !== true || ($response['http_code'] ?? 0) !== 200 || !is_string($response['body'] ?? null))) {
                throw new RuntimeException(is_array($response) && is_string($response['message'] ?? null) && $response['message'] !== ''
                    ? sblog_douban_text($response['message'], 240) : '豆瓣暂时无法连接，请稍后重试。');
            }
            $page = $page ?? sblog_douban_parse_page($response['body'], $userId, $type, $status, $start);
            if ($page['status'] === 'private') {
                $snapshot = sblog_douban_empty_snapshot('private', $page['message']);
                $privacyToken = bin2hex(random_bytes(16));
                sblog_douban_write_cache($paths['privacy'], ['version' => 1, 'observed_at' => $now, 'token' => $privacyToken]);
                break;
            }
            if ($page['status'] !== 'ok') {
                throw new RuntimeException($page['message']);
            }
            if ($snapshot['profile'] === null) {
                $snapshot['profile'] = $page['profile'];
                $snapshot['total'] = $page['total'];
            }
            $added = 0;
            foreach ($page['items'] as $item) {
                if (!isset($seen[$item['id']])) {
                    $seen[$item['id']] = true;
                    $snapshot['items'][] = $item;
                    $added++;
                }
            }
            if ($pageNumber > 0 && $added === 0 && $page['items'] !== []) {
                throw new RuntimeException('豆瓣分页内容重复，请稍后重新同步。');
            }
            $snapshot['truncated'] = $page['next_start'] !== null || ($snapshot['total'] !== null && $snapshot['total'] > count($snapshot['items']));
            if ($page['next_start'] === null) {
                break;
            }
            $start = $page['next_start'];
        }
        $latestToken = sblog_douban_read_privacy_marker($paths['privacy'])['token'] ?? '';
        if ($snapshot['status'] === 'ok' && $latestToken !== $privacyToken) {
            // Another worker observed privacy while this worker was fetching public pages.
            $snapshot = sblog_douban_empty_snapshot('private', '该豆瓣记录未公开，已停止展示这份列表的旧公开缓存。');
        }
        $privacyToken = $latestToken;
        if ($snapshot['status'] === 'ok') {
            $snapshot['updated_at'] = $now;
            $snapshot['message'] = $snapshot['truncated'] ? '已同步最近 ' . count($snapshot['items']) . ' 条记录；完整列表请前往豆瓣查看。' : '';
        }
        sblog_douban_write_cache($paths['data'], ['version' => 1, 'checked_at' => $now, 'next_retry_at' => 0,
            'privacy_token' => $privacyToken, 'snapshot' => $snapshot]);
        return $snapshot;
    } catch (Throwable $exception) {
        // Exception text from injected transports may contain private internals; use only our own messages.
        $message = $exception instanceof RuntimeException ? sblog_douban_text($exception->getMessage(), 240) : '豆瓣暂时无法获取，请稍后重试。';
        $currentMarker = sblog_douban_read_privacy_marker($paths['privacy']);
        $latestToken = $currentMarker['token'] ?? '';
        $failureSnapshot = sblog_douban_failure_snapshot($previous ?? null, $message, $now);
        if ($failureSnapshot['status'] === 'error' && isset($snapshot) && $snapshot['status'] === 'ok'
            && $snapshot['profile'] !== null && $snapshot['items'] !== []) {
            $failureSnapshot = $snapshot;
            $failureSnapshot['status'] = 'stale';
            $failureSnapshot['updated_at'] = $now;
            $failureSnapshot['truncated'] = true;
            $failureSnapshot['message'] = '豆瓣同步中断，已获取部分记录（' . count($snapshot['items']) . ' 条）。' . $message;
        }
        if ($latestToken !== ($privacyToken ?? '') || ($failureSnapshot['status'] === 'error' && $currentMarker !== null)) {
            $failureSnapshot = sblog_douban_empty_snapshot('private', '该豆瓣记录未公开，已停止展示这份列表的旧公开缓存。');
        }
        sblog_douban_write_cache($paths['data'], ['version' => 1, 'checked_at' => $now, 'next_retry_at' => $now + 300,
            'privacy_token' => $latestToken, 'snapshot' => $failureSnapshot]);
        return $failureSnapshot;
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}
