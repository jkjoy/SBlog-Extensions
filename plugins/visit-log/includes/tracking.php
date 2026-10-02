<?php

declare(strict_types=1);

function sblog_visit_text(string $value, int $limit): string
{
    $value = (string)preg_replace('/[\x00-\x1F\x7F]/', '', $value);
    return mb_strcut($value, 0, $limit, 'UTF-8');
}

function sblog_visit_request_path(array $server, array $query): string
{
    $uri = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : script_url();
    $path = explode('?', $uri, 2)[0];
    // Store a local path only, never a client-supplied absolute URL or credentials.
    if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
        $path = script_url();
    }
    $path = sblog_visit_text($path, 1024);
    $safe = [];
    foreach (['a', 'id', 'slug', 'p', 'q', 'category', 'uncategorized'] as $key) {
        if (isset($query[$key]) && is_string($query[$key])) {
            $safe[$key] = sblog_visit_text($query[$key], $key === 'q' ? 200 : 160);
        }
    }
    // Pretty routing adds a/slug to $_GET. Preserve only query fields actually in the URL.
    $original = [];
    parse_str(explode('?', $uri, 2)[1] ?? '', $original);
    $safe = array_intersect_key($safe, $original);
    return $path . ($safe === [] ? '' : '?' . http_build_query($safe, '', '&', PHP_QUERY_RFC3986));
}

function sblog_visit_referrer(string $value): array
{
    if (strlen($value) > 4096 || preg_match('/[\x00-\x20\x7F\\\\]/', $value)) {
        return ['', ''];
    }
    $parts = parse_url($value);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || empty($parts['host'])) {
        return ['', ''];
    }
    $host = strtolower((string)$parts['host']);
    $url = strtolower((string)$parts['scheme']) . '://' . $host;
    if (isset($parts['port'])) {
        $url .= ':' . (int)$parts['port'];
    }
    $url .= (string)($parts['path'] ?? '/');
    return [sblog_visit_text($url, 1024), sblog_visit_text($host, 255)];
}

function sblog_visit_agent(string $agent): array
{
    $bot = preg_match('/bot\b|spider|crawler|slurp|bingpreview|facebookexternalhit|headlesschrome|lighthouse|curl\/|wget\/|python-requests|go-http-client/i', $agent) === 1;
    $browser = match (true) {
        preg_match('/Edg(?:e|A|iOS)?\//i', $agent) === 1 => 'Edge',
        preg_match('/OPR\/|Opera/i', $agent) === 1 => 'Opera',
        preg_match('/Firefox\/|FxiOS\//i', $agent) === 1 => 'Firefox',
        preg_match('/Chrome\/|CriOS\//i', $agent) === 1 => 'Chrome',
        stripos($agent, 'Safari/') !== false => 'Safari',
        preg_match('/MSIE|Trident\//i', $agent) === 1 => 'Internet Explorer',
        default => '未知',
    };
    $os = match (true) {
        preg_match('/iPhone|iPad|iPod/i', $agent) === 1 => 'iOS',
        stripos($agent, 'Android') !== false => 'Android',
        stripos($agent, 'Windows') !== false => 'Windows',
        preg_match('/Macintosh|Mac OS X/i', $agent) === 1 => 'macOS',
        stripos($agent, 'Linux') !== false => 'Linux',
        default => '未知',
    };
    $device = match (true) {
        $bot => 'bot',
        preg_match('/iPad|Tablet|Kindle|Silk\//i', $agent) === 1
            || (stripos($agent, 'Android') !== false && stripos($agent, 'Mobile') === false) => 'tablet',
        preg_match('/Mobile|iPhone|iPod/i', $agent) === 1 => 'mobile',
        $agent === '' => 'unknown',
        default => 'desktop',
    };
    return [$browser, $os, $device, $bot];
}

function sblog_visit_prepare_request(array $context): void
{
    $action = (string)($context['action'] ?? '');
    if (!in_array($action, ['home', 'post', 'page', 'archives', 'tags', 'categories', 'tag', 'category', 'links', 'gallery'], true)
        || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET'
        || isset($_GET['preview']) || current_admin() !== null
        || isset($GLOBALS['sblog_visit_pending'])) {
        return;
    }
    try {
        $config = sblog_visit_config();
        if (!$config['enabled']) {
            return;
        }
        $agent = sblog_visit_text(is_string($_SERVER['HTTP_USER_AGENT'] ?? null) ? $_SERVER['HTTP_USER_AGENT'] : '', 512);
        [$browser, $os, $device, $bot] = sblog_visit_agent($agent);
        if ($bot && !$config['record_bots']) {
            return;
        }
        $ip = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? trim($_SERVER['REMOTE_ADDR']) : '';
        $ip = filter_var($ip, FILTER_VALIDATE_IP) ? (string)inet_ntop((string)inet_pton($ip)) : '';
        [$referrer, $source] = sblog_visit_referrer(is_string($_SERVER['HTTP_REFERER'] ?? null) ? $_SERVER['HTTP_REFERER'] : '');
        $now = time();
        $GLOBALS['sblog_visit_pending'] = [
            'visited_at' => $now,
            'started_at' => (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true)),
            'visitor_hash' => hash_hmac('sha256', sblog_visit_date('Y-m-d', $now) . "\0" . $ip . "\0" . $agent,
                sblog_visit_setting('visitor_salt')),
            'ip' => $ip, 'path' => sblog_visit_request_path($_SERVER, $_GET),
            'referrer' => $referrer, 'referrer_host' => $source,
            'browser' => $browser, 'os' => $os, 'device' => $device,
            'user_agent' => $agent, 'is_bot' => $bot ? 1 : 0, 'action' => $action,
        ];
        register_shutdown_function('sblog_visit_finish_request');
    } catch (Throwable $exception) {
        error_log('Visit log capture failed: ' . $exception->getMessage());
    }
}

function sblog_visit_finish_request(): void
{
    $visit = $GLOBALS['sblog_visit_pending'] ?? null;
    unset($GLOBALS['sblog_visit_pending']);
    if (!is_array($visit)) {
        return;
    }
    try {
        $status = http_response_code() ?: 200;
        $lastError = error_get_last();
        if ($lastError !== null && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            $status = 500;
        }
        $duration = max(0, min(2147483647, (int)round((microtime(true) - $visit['started_at']) * 1000)));
        q('INSERT INTO sblog_visit_logs(visited_at, visitor_hash, ip, path, referrer, referrer_host,
            browser, os, device, user_agent, is_bot, status_code, duration_ms, action)
            VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $visit['visited_at'], $visit['visitor_hash'], $visit['ip'], $visit['path'],
            $visit['referrer'], $visit['referrer_host'], $visit['browser'], $visit['os'],
            $visit['device'], $visit['user_agent'], $visit['is_bot'], $status, $duration, $visit['action'],
        ]);
        sblog_visit_cleanup();
    } catch (Throwable $exception) {
        // Observability must never turn a public page into a failed response.
        error_log('Visit log write failed: ' . $exception->getMessage());
    }
}
