<?php
declare(strict_types=1);

/** Keep only the login credentials needed by the fixed official endpoints. */
function sunny_music_cookie(string $input): string
{
    if ($input === '' || strlen($input) > 8192 || preg_match('/[\x00-\x1f\x7f-\xff]/', $input)) {
        return '';
    }
    $input = trim($input);
    $input = preg_replace('/^Cookie:\s*/i', '', $input) ?? '';
    $values = [];
    foreach (explode(';', $input) as $part) {
        $pair = explode('=', trim($part), 2);
        if (count($pair) !== 2 || !in_array($pair[0], ['MUSIC_U', '__csrf'], true)) {
            continue;
        }
        if ($pair[1] === '' || strlen($pair[1]) > 4096 || preg_match('/[\s,"\\\\]/', $pair[1])) {
            return '';
        }
        $values[$pair[0]] = $pair[1];
    }
    return isset($values['MUSIC_U'])
        ? 'MUSIC_U=' . $values['MUSIC_U'] . (isset($values['__csrf']) ? '; __csrf=' . $values['__csrf'] : '')
        : '';
}

function sunny_music_stream_url(mixed $value): string
{
    if (!is_string($value) || $value === '' || strlen($value) > 4096 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
        return '';
    }
    $parts = parse_url($value);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port']) || isset($parts['fragment'])
        || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*music\.126\.net\z/iD', (string)($parts['host'] ?? ''))
        || !str_starts_with((string)($parts['path'] ?? ''), '/')) {
        return '';
    }
    return 'https://' . strtolower($parts['host']) . $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

function sunny_music_auth_error(string $code): array
{
    $messages = [
        'login_expired' => '网易云登录已失效，请联系站长更新 Cookie。',
        'invalid_cookie' => '网易云 Cookie 格式无效，请联系站长重新填写。',
        'not_configured' => '音乐登录配置已更改，请刷新页面。',
        'stale_token' => '播放请求已过期，请刷新页面后重试。',
        'trial_only' => '此账号仅能试听这首歌曲，正在切换下一首。',
        'unavailable' => '此歌曲没有可用的播放权限或音源，正在切换下一首。',
        'temporary' => '暂时无法取得播放地址，正在尝试下一首。',
        'rate_limited' => '播放请求较多，请稍后重试。',
    ];
    if (!isset($messages[$code])) { $code = 'temporary'; }
    return ['ok' => false, 'code' => $code, 'message' => $messages[$code], 'skip' => in_array($code, ['trial_only', 'unavailable', 'temporary'], true)];
}

function sunny_music_access_token(array $config, string $cookie, ?int $now = null): string
{
    $expires = ($now ?? time()) + 86400;
    return $expires . '.' . hash_hmac('sha256', 'sunny-play|' . $config['id'] . '|' . $expires, $cookie);
}

function sunny_music_access_valid(string $token, array $config, string $cookie, ?int $now = null): bool
{
    $now ??= time();
    if ($cookie === '' || !preg_match('/\A([0-9]{10})\.([a-f0-9]{64})\z/D', $token, $match)) { return false; }
    $expires = (int)$match[1];
    return $expires > $now && $expires <= $now + 86400
        && hash_equals(hash_hmac('sha256', 'sunny-play|' . $config['id'] . '|' . $expires, $cookie), $match[2]);
}

/** This public payload contains a scoped capability, never the account Cookie. */
function sunny_music_resolver_payload(array $config): ?array
{
    $raw = theme_setting('music_cookie', '', 'sunny');
    if ($raw === '') { return null; }
    $cookie = sunny_music_cookie($raw);
    return [
        'url' => url_with_query(url_for('home'), ['sunny_music_action' => 'resolve']),
        'token' => $cookie !== '' ? sunny_music_access_token($config, $cookie) : '',
    ];
}

function sunny_music_auth_read(string $path): array
{
    if (!is_file($path) || @filesize($path) > 32768) { return []; }
    $json = @file_get_contents($path);
    $record = is_string($json) ? json_decode($json, true, 8) : null;
    return is_array($record) ? $record : [];
}

/** The Cookie is sent only to these two official API paths, with no redirects. */
function sunny_music_auth_fetch(string $endpoint, array $parameters, string $cookie, ?callable $transport = null): array
{
    if (!in_array($endpoint, [
        'https://interface.music.163.com/api/nuser/account/get',
        'https://interface.music.163.com/api/song/enhance/player/url/v1',
    ], true) || sunny_music_cookie($cookie) !== $cookie) { return []; }
    preg_match('/(?:^|; )__csrf=([^;]+)/', $cookie, $csrf);
    $parameters['csrf_token'] = $csrf[1] ?? '';
    if ($transport !== null) {
        $value = $transport($endpoint, $parameters, $cookie);
        return is_array($value) ? $value : [];
    }
    if (!function_exists('curl_init')) { return []; }
    $handle = curl_init($endpoint);
    if ($handle === false) { return []; }
    $body = '';
    try {
        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($parameters, '', '&', PHP_QUERY_RFC3986),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_COOKIE => $cookie,
            CURLOPT_REFERER => 'https://music.163.com/',
            CURLOPT_USERAGENT => 'Mozilla/5.0 SBlog-Sunny-Music',
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body): int {
                if (strlen($body) + strlen($chunk) > 262144) { return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        $trust = function_exists('curl_trust_options') ? curl_trust_options() : [];
        curl_setopt_array($handle, array_replace($trust, $options));
        if (curl_exec($handle) === false || (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) { return []; }
        $value = json_decode($body, true, 16, JSON_BIGINT_AS_STRING);
        return is_array($value) ? $value : [];
    } finally { curl_close($handle); }
}

function sunny_music_resolve_track(string $id, string $cookie, bool $refresh = false, ?callable $transport = null): array
{
    if (sunny_music_track_id($id) === '') { return sunny_music_auth_error('unavailable'); }
    if (sunny_music_cookie($cookie) !== $cookie || $cookie === '') { return sunny_music_auth_error('invalid_cookie'); }
    if (!defined('CACHE_DIR')) { return sunny_music_auth_error('temporary'); }
    $now = time();
    $identity = hash('sha256', $cookie);
    $authPath = CACHE_DIR . '/sunny-music-account-' . $identity . '.json';
    $path = CACHE_DIR . '/sunny-music-stream-' . hash('sha256', $identity . '|' . $id) . '.json';
    $record = sunny_music_auth_read($path);
    if (!$refresh && is_int($record['expires_at'] ?? null) && $record['expires_at'] > $now + 30
        && $record['expires_at'] <= $now + 3600 && sunny_music_stream_url($record['url'] ?? '') !== '') {
        return ['ok' => true, 'url' => sunny_music_stream_url($record['url']), 'expires_at' => $record['expires_at']];
    }
    try {
        $auth = sunny_music_auth_read($authPath);
        if (($auth['checked_at'] ?? 0) + 300 <= $now || !is_int($auth['checked_at'] ?? null) || ($auth['ok'] ?? null) !== true) {
            $account = sunny_music_auth_fetch('https://interface.music.163.com/api/nuser/account/get', [], $cookie, $transport);
            if ($account === []) { return sunny_music_auth_error('temporary'); }
            $accountCode = (int)($account['code'] ?? 0);
            if (in_array($accountCode, [301, 401], true)
                || ($accountCode === 200 && (int)($account['account']['id'] ?? 0) < 1 && (int)($account['profile']['userId'] ?? 0) < 1)) {
                return sunny_music_auth_error('login_expired');
            }
            if ($accountCode !== 200) { return sunny_music_auth_error('temporary'); }
            sunny_music_write_cache($authPath, ['checked_at' => $now, 'ok' => true]);
        }
        $response = sunny_music_auth_fetch('https://interface.music.163.com/api/song/enhance/player/url/v1', [
            'ids' => json_encode([$id]), 'level' => 'standard', 'encodeType' => 'mp3',
        ], $cookie, $transport);
        if ($response === []) { return sunny_music_auth_error('temporary'); }
        if (in_array((int)($response['code'] ?? 0), [301, 401], true)) {
            @unlink($authPath);
            return sunny_music_auth_error('login_expired');
        }
        $item = null;
        foreach (is_array($response['data'] ?? null) ? $response['data'] : [] as $candidate) {
            if (is_array($candidate) && (string)($candidate['id'] ?? '') === $id) { $item = $candidate; break; }
        }
        if ((int)($response['code'] ?? 0) !== 200 || $item === null || (int)($item['code'] ?? 0) !== 200) {
            return sunny_music_auth_error('unavailable');
        }
        if (isset($item['freeTrialInfo']) && $item['freeTrialInfo'] !== false) { return sunny_music_auth_error('trial_only'); }
        $url = sunny_music_stream_url($item['url'] ?? null);
        if ($url === '') { return sunny_music_auth_error('unavailable'); }
        $lifetime = (int)($item['expi'] ?? 0);
        if ($lifetime < 60) { return sunny_music_auth_error('temporary'); }
        $result = ['ok' => true, 'url' => $url, 'expires_at' => $now + min(3600, $lifetime)];
        sunny_music_write_cache($path, $result);
        return $result;
    } catch (Throwable) {
        return sunny_music_auth_error('temporary');
    }
}

/** Limit public URL resolution without retaining an IP address or any credential. */
function sunny_music_resolve_allowed(): bool
{
    $path = CACHE_DIR . '/sunny-music-rate-' . hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'local')) . '.json';
    $handle = @fopen($path, 'c+');
    if ($handle === false) { return false; }
    @chmod($path, 0600);
    try {
        if (!flock($handle, LOCK_EX | LOCK_NB)) { return false; }
        $json = stream_get_contents($handle, 512);
        $record = is_string($json) ? json_decode($json, true) : null;
        $now = time();
        if (!is_array($record) || (int)($record['until'] ?? 0) <= $now) { $record = ['until' => $now + 60, 'count' => 0]; }
        if ((int)($record['count'] ?? 0) >= 120) { return false; }
        $record['count'] = (int)($record['count'] ?? 0) + 1;
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($record));
        return true;
    } finally { @flock($handle, LOCK_UN); fclose($handle); }
}

function sunny_music_auth_json(array $result, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: private, no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Cross-Origin-Resource-Policy: same-origin');
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sunny_music_handle_resolve(): never
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        sunny_music_auth_json(sunny_music_auth_error('unavailable'), 405);
    }
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    $config = sunny_music_config();
    $raw = theme_setting('music_cookie', '', 'sunny');
    $cookie = sunny_music_cookie($raw);
    if ($config === null || $raw === '') { sunny_music_auth_json(sunny_music_auth_error('not_configured'), 409); }
    if ($cookie === '') { sunny_music_auth_json(sunny_music_auth_error('invalid_cookie'), 422); }
    $token = is_string($_POST['token'] ?? null) ? $_POST['token'] : '';
    if (!sunny_music_access_valid($token, $config, $cookie)) { sunny_music_auth_json(sunny_music_auth_error('stale_token'), 403); }
    if (!sunny_music_resolve_allowed()) { sunny_music_auth_json(sunny_music_auth_error('rate_limited'), 429); }
    $id = sunny_music_track_id($_POST['id'] ?? null);
    $snapshot = sunny_music_snapshot($config);
    if ($id === '' || !in_array($id, array_column($snapshot['tracks'], 'id'), true)) {
        sunny_music_auth_json(sunny_music_auth_error('unavailable'), 404);
    }
    sunny_music_auth_json(sunny_music_resolve_track($id, $cookie, ($_POST['refresh'] ?? '') === '1'));
}
