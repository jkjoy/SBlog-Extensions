<?php
declare(strict_types=1);

/** Accept only playlist identifiers and the official desktop/mobile playlist routes. */
function sunny_music_playlist_id(string $input): string
{
    $input = trim($input);
    if (preg_match('/\A[1-9][0-9]{0,19}\z/D', $input)) {
        return $input;
    }
    if ($input === '' || strlen($input) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $input)) {
        return '';
    }
    if (preg_match('~\A(?:music\.163\.com|y\.music\.163\.com)/~i', $input)) {
        $input = 'https://' . $input;
    }
    $parts = parse_url($input);
    if (!is_array($parts) || isset($parts['user'])
        || isset($parts['pass']) || isset($parts['port'])) {
        return '';
    }
    $local = str_starts_with($input, '/') && !str_starts_with($input, '//') && !isset($parts['host']);
    if (!$local && (!in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || !in_array(strtolower((string)($parts['host'] ?? '')), ['music.163.com', 'y.music.163.com'], true))) {
        return '';
    }
    $path = (string)($parts['path'] ?? '');
    $query = (string)($parts['query'] ?? '');
    if (isset($parts['fragment']) && str_starts_with($parts['fragment'], '/')) {
        if (!in_array($path, ['', '/'], true)) {
            return '';
        }
        $fragment = parse_url($parts['fragment']);
        if (!is_array($fragment) || isset($fragment['host']) || isset($fragment['scheme'])) {
            return '';
        }
        $path = (string)($fragment['path'] ?? '');
        $query = (string)($fragment['query'] ?? '');
    }
    if (!in_array($path, ['/playlist', '/m/playlist'], true)) {
        return '';
    }
    parse_str($query, $parameters);
    $id = $parameters['id'] ?? null;
    return is_string($id) && preg_match('/\A[1-9][0-9]{0,19}\z/D', $id) ? $id : '';
}

function sunny_music_text(mixed $value, int $limit = 180): string
{
    if (!is_string($value) || !preg_match('//u', $value)) {
        return '';
    }
    $value = trim((string)preg_replace('/[\x00-\x1f\x7f]/u', '', $value));
    preg_match('/\A.{0,' . $limit . '}/us', $value, $match);
    return $match[0] ?? '';
}

function sunny_music_config(): ?array
{
    if (theme_setting('music_enabled', '0', 'sunny') !== '1') {
        return null;
    }
    $id = sunny_music_playlist_id(theme_setting('music_playlist', '', 'sunny'));
    if ($id === '') {
        return null;
    }
    $limit = theme_setting('music_limit', '50', 'sunny');
    $mode = theme_setting('music_mode', 'list', 'sunny');
    $volume = theme_setting('music_volume', '60', 'sunny');
    return [
        'id' => $id,
        'limit' => in_array($limit, ['20', '50', '100', '200'], true) ? (int)$limit : 50,
        'mode' => in_array($mode, ['list', 'single', 'random'], true) ? $mode : 'list',
        'volume' => in_array($volume, ['20', '40', '60', '80', '100'], true) ? (int)$volume / 100 : 0.6,
        'title' => sunny_music_text(theme_setting('music_title', '音乐单', 'sunny'), 80) ?: '音乐单',
    ];
}

function sunny_music_track_id(mixed $value): string
{
    if (!is_int($value) && !is_string($value)) {
        return '';
    }
    $value = (string)$value;
    return preg_match('/\A[1-9][0-9]{0,19}\z/D', $value) ? $value : '';
}

/** Covers stay on NetEase's image CDN; credentials, alternate ports and other hosts are rejected. */
function sunny_music_cover_url(mixed $value): string
{
    if (!is_string($value) || $value === '' || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) {
        return '';
    }
    $parts = parse_url($value);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
        || !preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+music\.126\.net\z/D', $host)
        || !str_starts_with((string)($parts['path'] ?? ''), '/')) {
        return '';
    }
    return 'https://' . $host . $parts['path'] . (isset($parts['query']) ? '?' . $parts['query'] : '');
}

function sunny_music_empty_snapshot(string $id, string $message = '音乐歌单暂时无法获取，请稍后再试。'): array
{
    return ['status' => 'error', 'name' => '', 'url' => 'https://music.163.com/playlist?id=' . $id,
        'tracks' => [], 'updated_at' => 0, 'message' => $message];
}

/** $transport receives the fixed official endpoint and returns its decoded JSON object. */
function sunny_music_fetch(string $id, ?callable $transport = null): array
{
    $endpoint = 'https://music.163.com/api/playlist/detail?id=' . $id;
    if ($transport !== null) {
        $payload = $transport($endpoint);
        return is_array($payload) ? $payload : [];
    }
    if (!function_exists('curl_init')) {
        return [];
    }
    $handle = curl_init($endpoint);
    if ($handle === false) {
        return [];
    }
    $body = '';
    $oversized = false;
    try {
        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 1,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'SBlog-Sunny-Music/1.0',
            CURLOPT_ENCODING => '',
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$oversized): int {
                if (strlen($body) + strlen($chunk) > 4 * 1024 * 1024) {
                    $oversized = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ];
        $trust = function_exists('curl_trust_options') ? curl_trust_options() : [];
        curl_setopt_array($handle, array_replace($trust, $options));
        if (curl_exec($handle) === false || $oversized || curl_errno($handle) !== 0
            || (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE) !== 200) {
            return [];
        }
        $payload = json_decode($body, true, 64, JSON_BIGINT_AS_STRING);
        return is_array($payload) && json_last_error() === JSON_ERROR_NONE ? $payload : [];
    } finally {
        unset($handle);
    }
}

/** Normalize only the public fields used by the player, never raw API responses. */
function sunny_music_normalize(array $payload, string $id): array
{
    $code = $payload['code'] ?? null;
    if (in_array($code, [401, 403, 404, '401', '403', '404'], true)) {
        return ['unavailable' => true];
    }
    if (!in_array($code, [200, '200'], true)) {
        return [];
    }
    $playlist = $payload['result'] ?? $payload['playlist'] ?? null;
    if (!is_array($playlist)) {
        return [];
    }
    if (isset($playlist['privacy']) && (int)$playlist['privacy'] > 0) {
        return ['unavailable' => true];
    }
    if (isset($playlist['id']) && sunny_music_track_id($playlist['id']) !== $id) {
        return [];
    }
    $name = sunny_music_text($playlist['name'] ?? null);
    $rawTracks = $playlist['tracks'] ?? null;
    if ($name === '' || !is_array($rawTracks) || array_values($rawTracks) !== $rawTracks
        || ($rawTracks === [] && (int)($playlist['trackCount'] ?? 0) > 0)) {
        return [];
    }
    $tracks = [];
    foreach ($rawTracks as $track) {
        if (!is_array($track) || ($trackId = sunny_music_track_id($track['id'] ?? null)) === ''
            || ($trackName = sunny_music_text($track['name'] ?? null)) === '' || isset($tracks[$trackId])) {
            continue;
        }
        $artists = [];
        foreach (array_slice(is_array($track['artists'] ?? $track['ar'] ?? null) ? ($track['artists'] ?? $track['ar']) : [], 0, 16) as $artist) {
            $artistName = is_array($artist) ? sunny_music_text($artist['name'] ?? null, 100) : '';
            if ($artistName !== '') {
                $artists[] = $artistName;
            }
        }
        $milliseconds = $track['duration'] ?? $track['dt'] ?? 0;
        $duration = (is_int($milliseconds) || (is_string($milliseconds) && preg_match('/\A[0-9]{1,12}\z/D', $milliseconds)))
            ? max(0, min(604800, intdiv(max(0, (int)$milliseconds), 1000))) : 0;
        $tracks[$trackId] = [
            'id' => $trackId,
            'name' => $trackName,
            'artist' => sunny_music_text(implode(' / ', array_unique($artists)), 240),
            'url' => 'https://music.163.com/song/media/outer/url?id=' . $trackId . '.mp3',
            'cover' => sunny_music_cover_url($track['album']['picUrl'] ?? $track['al']['picUrl'] ?? ''),
            'duration' => $duration,
        ];
        if (count($tracks) >= 200) {
            break;
        }
    }
    return $rawTracks !== [] && $tracks === [] ? [] : ['name' => $name, 'tracks' => array_values($tracks)];
}

function sunny_music_read_cache(string $path, string $id): ?array
{
    if (!is_file($path) || @filesize($path) > 4 * 1024 * 1024) {
        return null;
    }
    $json = @file_get_contents($path);
    $record = is_string($json) ? json_decode($json, true) : null;
    $snapshot = is_array($record) ? ($record['snapshot'] ?? null) : null;
    if (!is_array($record) || ($record['version'] ?? null) !== 1
        || !is_int($record['checked_at'] ?? null) || $record['checked_at'] < 0
        || !is_int($record['next_retry_at'] ?? null) || $record['next_retry_at'] < 0
        || !is_array($snapshot) || !in_array($snapshot['status'] ?? '', ['ok', 'stale', 'error'], true)
        || !is_int($snapshot['updated_at'] ?? null) || $snapshot['updated_at'] < 0
        || !is_string($snapshot['name'] ?? null) || !is_string($snapshot['message'] ?? null)
        || ($snapshot['url'] ?? '') !== 'https://music.163.com/playlist?id=' . $id
        || !is_array($snapshot['tracks'] ?? null) || count($snapshot['tracks']) > 200
        || array_values($snapshot['tracks']) !== $snapshot['tracks']) {
        return null;
    }
    foreach ($snapshot['tracks'] as $track) {
        if (!is_array($track) || sunny_music_track_id($track['id'] ?? null) === ''
            || sunny_music_track_id($track['id'] ?? null) !== ($track['id'] ?? null)
            || !is_string($track['name'] ?? null) || !is_string($track['artist'] ?? null)
            || !is_int($track['duration'] ?? null) || $track['duration'] < 0 || $track['duration'] > 604800
            || ($track['url'] ?? '') !== 'https://music.163.com/song/media/outer/url?id=' . $track['id'] . '.mp3'
            || !is_string($track['cover'] ?? null) || sunny_music_cover_url($track['cover']) !== $track['cover']) {
            return null;
        }
    }
    return $record;
}

function sunny_music_write_cache(string $path, array $record): void
{
    $json = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $temporary = is_string($json) ? @tempnam(dirname($path), 'sunny-music-write-') : false;
    if (!is_string($temporary)) {
        return;
    }
    @chmod($temporary, 0600);
    if (@file_put_contents($temporary, $json, LOCK_EX) !== false && @rename($temporary, $path)) {
        @chmod($path, 0600);
        return;
    }
    @unlink($temporary);
}

function sunny_music_usable_snapshot(?array $snapshot, int $now): ?array
{
    return $snapshot !== null && (int)($snapshot['updated_at'] ?? 0) > 0
        && (int)$snapshot['updated_at'] >= $now - 7 * 86400 ? $snapshot : null;
}

/** Cache all normalized tracks once per playlist; individual callers choose their display limit. */
function sunny_music_snapshot(array $config, ?callable $transport = null): array
{
    $id = sunny_music_track_id($config['id'] ?? null);
    if ($id === '') {
        return sunny_music_empty_snapshot('', '请填写有效的网易云歌单链接或 ID。');
    }
    $limit = in_array($config['limit'] ?? null, [20, 50, 100, 200], true) ? $config['limit'] : 50;
    $limitTracks = static function (array $snapshot) use ($limit): array {
        $snapshot['tracks'] = array_slice($snapshot['tracks'], 0, $limit);
        return $snapshot;
    };
    if (!defined('CACHE_DIR')) {
        return sunny_music_empty_snapshot($id, '音乐缓存暂时不可用。');
    }
    $path = rtrim(CACHE_DIR, '/\\') . '/sunny-music-' . $id . '.json';
    $now = time();
    $record = sunny_music_read_cache($path, $id);
    $previous = sunny_music_usable_snapshot($record['snapshot'] ?? null, $now);
    if ($record !== null && ($record['next_retry_at'] > $now
        || ($record['snapshot']['status'] === 'ok' && $record['checked_at'] + 3600 > $now))) {
        return $limitTracks($previous ?? sunny_music_empty_snapshot($id, $record['snapshot']['message'] ?: '音乐缓存已过期，请稍后重试。'));
    }
    $lock = @fopen(substr($path, 0, -5) . '.lock', 'c');
    if ($lock === false) {
        return $limitTracks($previous ?? sunny_music_empty_snapshot($id, '音乐缓存暂时不可用。'));
    }
    @chmod(substr($path, 0, -5) . '.lock', 0600);
    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return $limitTracks($previous ?? sunny_music_empty_snapshot($id, '音乐歌单正在更新，请稍后再试。'));
    }
    try {
        $latest = sunny_music_read_cache($path, $id);
        if ($latest !== null && ($latest['next_retry_at'] > $now
            || ($latest['snapshot']['status'] === 'ok' && $latest['checked_at'] + 3600 > $now))) {
            return $limitTracks(sunny_music_usable_snapshot($latest['snapshot'], $now)
                ?? sunny_music_empty_snapshot($id, $latest['snapshot']['message'] ?: '音乐缓存已过期，请稍后重试。'));
        }
        $previous = sunny_music_usable_snapshot(($latest ?? $record)['snapshot'] ?? null, $now);
        try {
            $normalized = sunny_music_normalize(sunny_music_fetch($id, $transport), $id);
        } catch (Throwable $exception) {
            // The transport may contain a URL or private diagnostics; never expose it.
            $normalized = [];
        }
        if (isset($normalized['name'], $normalized['tracks'])) {
            $snapshot = ['status' => 'ok', 'name' => $normalized['name'],
                'url' => 'https://music.163.com/playlist?id=' . $id, 'tracks' => $normalized['tracks'],
                'updated_at' => $now, 'message' => ''];
        } elseif (!empty($normalized['unavailable'])) {
            // A confirmed private or missing playlist must replace older public data.
            $snapshot = sunny_music_empty_snapshot($id, '该网易云歌单未公开或不存在。');
        } elseif ($previous !== null) {
            $snapshot = $previous;
            $snapshot['status'] = 'stale';
            $snapshot['message'] = '网易云暂时无法同步，正在显示上次获取的歌单。';
        } else {
            $snapshot = sunny_music_empty_snapshot($id);
        }
        sunny_music_write_cache($path, ['version' => 1, 'checked_at' => $now,
            'next_retry_at' => $snapshot['status'] === 'ok' ? 0 : $now + 300, 'snapshot' => $snapshot]);
        return $limitTracks($snapshot);
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}
