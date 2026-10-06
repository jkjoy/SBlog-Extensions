<?php
declare(strict_types=1);

// This module is loaded by the plugin, never used as a public API endpoint.
if (!defined('CACHE_DIR')) {
    http_response_code(404);
    exit;
}

/** @return array{status:string,updated_at:int,message:string,profile:?array,recent_games:array,owned_games:array,stats:array,library_state:string,recent_state:string} */
function sblog_steam_empty_snapshot(string $status, string $message): array
{
    return [
        'status' => $status,
        'updated_at' => 0,
        'message' => $message,
        'profile' => null,
        'recent_games' => [],
        'owned_games' => [],
        'stats' => ['game_count' => null, 'total_minutes' => null, 'recent_minutes' => null],
        'library_state' => 'error',
        'recent_state' => 'error',
    ];
}

/** Cache filenames identify credentials without persisting or exposing the API key. */
function sblog_steam_cache_paths(array $config): array
{
    $identity = hash('sha256', trim((string)($config['steam_id'] ?? '')) . ':' . strtolower(trim((string)($config['api_key'] ?? ''))));
    $base = rtrim(CACHE_DIR, '/\\') . '/steam-showcase-' . $identity;
    return ['data' => $base . '.json', 'lock' => $base . '.lock'];
}

function sblog_steam_read_cache(string $path): ?array
{
    if (!is_file($path) || @filesize($path) > 12 * 1024 * 1024) {
        return null;
    }
    $json = @file_get_contents($path);
    $record = is_string($json) ? json_decode($json, true) : null;
    if (!is_array($record) || ($record['version'] ?? null) !== 1 || !is_array($record['snapshot'] ?? null)
        || !is_int($record['checked_at'] ?? null) || !is_int($record['next_retry_at'] ?? null)) {
        return null;
    }
    $snapshot = $record['snapshot'];
    if (!in_array($snapshot['status'] ?? '', ['ok', 'stale', 'error'], true)
        || !is_array($snapshot['recent_games'] ?? null) || !is_array($snapshot['owned_games'] ?? null)
        || !is_array($snapshot['stats'] ?? null) || !is_int($snapshot['updated_at'] ?? null)
        || $snapshot['updated_at'] < 0 || !is_string($snapshot['message'] ?? null)
        || !array_key_exists('profile', $snapshot) || ($snapshot['profile'] !== null && !is_array($snapshot['profile']))
        || !in_array($snapshot['library_state'] ?? '', ['public', 'private', 'error'], true)
        || !in_array($snapshot['recent_state'] ?? '', ['public', 'private', 'error'], true)) {
        return null;
    }
    foreach (['game_count', 'total_minutes', 'recent_minutes'] as $key) {
        if (!array_key_exists($key, $snapshot['stats']) || ($snapshot['stats'][$key] !== null
            && (!is_int($snapshot['stats'][$key]) || $snapshot['stats'][$key] < 0))) {
            return null;
        }
    }
    if ($snapshot['profile'] !== null) {
        $profile = $snapshot['profile'];
        foreach (['steamid', 'name', 'avatar', 'profile_url', 'current_game', 'current_appid'] as $key) {
            if (!is_string($profile[$key] ?? null)) {
                return null;
            }
        }
        foreach (['personastate', 'visibility', 'last_online'] as $key) {
            if (!is_int($profile[$key] ?? null) || $profile[$key] < 0) {
                return null;
            }
        }
        if (!preg_match('/^[0-9]{17}$/D', $profile['steamid'])
            || $profile['profile_url'] !== 'https://steamcommunity.com/profiles/' . $profile['steamid']
            || ($profile['avatar'] !== '' && sblog_steam_image_url($profile['avatar']) !== $profile['avatar'])) {
            return null;
        }
    }
    foreach (['owned_games', 'recent_games'] as $collection) {
        if (array_values($snapshot[$collection]) !== $snapshot[$collection]) {
            return null;
        }
        foreach ($snapshot[$collection] as $game) {
            if (!is_array($game) || !is_int($game['appid'] ?? null) || $game['appid'] < 1
                || !is_string($game['name'] ?? null)
                || !is_int($game['minutes_2weeks'] ?? null) || $game['minutes_2weeks'] < 0
                || !is_int($game['minutes_total'] ?? null) || $game['minutes_total'] < 0
                || !is_string($game['cover_url'] ?? null) || !is_string($game['icon_url'] ?? null)
                || sblog_steam_image_url($game['cover_url']) !== $game['cover_url']
                || ($game['icon_url'] !== '' && sblog_steam_image_url($game['icon_url']) !== $game['icon_url'])) {
                return null;
            }
        }
    }
    return $record;
}

function sblog_steam_write_cache(string $path, array $record): void
{
    $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($encoded)) {
        return;
    }
    $temporary = @tempnam(dirname($path), 'steam-write-');
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

/** Old successful data expires even while Steam remains unavailable. */
function sblog_steam_usable_snapshot(?array $snapshot, int $now): ?array
{
    if ($snapshot !== null && (int)($snapshot['updated_at'] ?? 0) > 0
        && (int)$snapshot['updated_at'] < $now - 7 * 86400) {
        return null;
    }
    return $snapshot;
}

/** Explicit admin refresh may clear the current account's cached result, but never removes an active lock. */
function sblog_steam_clear_cache(array $config): void
{
    $paths = sblog_steam_cache_paths($config);
    $lock = @fopen($paths['lock'], 'c');
    if ($lock === false) {
        return;
    }
    if (@flock($lock, LOCK_EX | LOCK_NB)) {
        @unlink($paths['data']);
        @flock($lock, LOCK_UN);
    }
    fclose($lock);
}

/**
 * $transport is an optional server-side test seam; it is never read from configuration.
 * It receives ('profile'|'recent'|'owned', query parameters) and returns {ok,data,message}.
 */
function sblog_steam_snapshot(array $config, bool $force = false, ?callable $transport = null): array
{
    $key = trim((string)($config['api_key'] ?? ''));
    $steamId = trim((string)($config['steam_id'] ?? ''));
    if ($key === '' || $steamId === '') {
        return sblog_steam_empty_snapshot('unconfigured', '请在后台填写 Steam Web API Key 和 SteamID64。');
    }
    if (!preg_match('/^[a-f0-9]{32}$/iD', $key) || !preg_match('/^[0-9]{17}$/D', $steamId)) {
        return sblog_steam_empty_snapshot('unconfigured', 'Steam Web API Key 或 SteamID64 格式不正确，请检查插件设置。');
    }
    $ttl = max(5, min(1440, (int)($config['cache_minutes'] ?? 15))) * 60;
    $paths = sblog_steam_cache_paths($config);
    $record = sblog_steam_read_cache($paths['data']);
    $now = time();
    $previous = sblog_steam_usable_snapshot($record['snapshot'] ?? null, $now);
    if ($record !== null && ($record['next_retry_at'] > $now
        || (!$force && $record['snapshot']['status'] === 'ok' && $record['checked_at'] + $ttl > $now))) {
        return $previous ?? sblog_steam_empty_snapshot('error', 'Steam 缓存数据已过期，请稍后重新同步。');
    }
    $lock = @fopen($paths['lock'], 'c');
    if ($lock === false) {
        return $previous ?? sblog_steam_empty_snapshot('error', 'Steam 缓存暂时不可用，请检查缓存目录权限。');
    }
    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return $previous ?? sblog_steam_empty_snapshot('error', 'Steam 数据正在更新，请稍后再试。');
    }
    try {
        // A different worker may have completed the refresh between the first read and the lock.
        $latest = sblog_steam_read_cache($paths['data']);
        if ($latest !== null && ($latest['next_retry_at'] > $now
            || (!$force && $latest['snapshot']['status'] === 'ok' && $latest['checked_at'] + $ttl > $now))) {
            return sblog_steam_usable_snapshot($latest['snapshot'], $now)
                ?? sblog_steam_empty_snapshot('error', 'Steam 缓存数据已过期，请稍后重新同步。');
        }
        $previous = sblog_steam_usable_snapshot(($latest ?? $record)['snapshot'] ?? null, $now);
        $responses = sblog_steam_fetch_all($key, $steamId, $transport);
        $snapshot = sblog_steam_combine_snapshot($responses, $steamId, $previous, $now);
        sblog_steam_write_cache($paths['data'], [
            'version' => 1,
            'checked_at' => $now,
            'next_retry_at' => $snapshot['status'] === 'ok' ? 0 : $now + 60,
            'snapshot' => $snapshot,
        ]);
        return $snapshot;
    } catch (Throwable $exception) {
        // Never log a cURL URL or exception that could contain the API key.
        $snapshot = $previous ?? sblog_steam_empty_snapshot('error', 'Steam 数据暂时无法获取，请稍后再试。');
        if ($snapshot['profile'] !== null || $snapshot['updated_at'] > 0) {
            $snapshot['status'] = 'stale';
            $snapshot['message'] = 'Steam 暂时无法连接，正在显示上次同步的数据。';
        }
        sblog_steam_write_cache($paths['data'], ['version' => 1, 'checked_at' => $now, 'next_retry_at' => $now + 60, 'snapshot' => $snapshot]);
        return $snapshot;
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function sblog_steam_request_parameters(string $key, string $steamId): array
{
    return [
        'profile' => ['key' => $key, 'steamids' => $steamId, 'format' => 'json'],
        'recent' => ['key' => $key, 'steamid' => $steamId, 'format' => 'json'],
        'owned' => ['key' => $key, 'steamid' => $steamId, 'include_appinfo' => 1, 'include_played_free_games' => 1, 'format' => 'json'],
    ];
}

/** All network targets are fixed official HTTPS methods, fetched together to bound page latency. */
function sblog_steam_fetch_all(string $key, string $steamId, ?callable $transport = null): array
{
    $parameters = sblog_steam_request_parameters($key, $steamId);
    $results = [];
    if ($transport !== null) {
        foreach ($parameters as $method => $query) {
            try {
                $response = $transport($method, $query);
                $results[$method] = is_array($response) ? $response : ['ok' => false];
            } catch (Throwable $exception) {
                $results[$method] = ['ok' => false];
            }
        }
        return $results;
    }
    if (!function_exists('curl_multi_init')) {
        return array_fill_keys(array_keys($parameters), ['ok' => false, 'message' => '服务器未启用 cURL 扩展，暂时无法连接 Steam。']);
    }
    $paths = [
        'profile' => '/ISteamUser/GetPlayerSummaries/v0002/',
        'recent' => '/IPlayerService/GetRecentlyPlayedGames/v0001/',
        'owned' => '/IPlayerService/GetOwnedGames/v0001/',
    ];
    $multi = curl_multi_init();
    $handles = [];
    $bodies = [];
    $oversized = [];
    try {
        foreach ($parameters as $method => $query) {
            $handle = curl_init('https://api.steampowered.com' . $paths[$method] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
            if ($handle === false) {
                $results[$method] = ['ok' => false];
                continue;
            }
            $bodies[$method] = '';
            $oversized[$method] = false;
            $options = [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_USERAGENT => 'SBlog-Steam-Showcase/1.0',
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_ENCODING => '',
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$bodies, &$oversized, $method): int {
                    if (strlen($bodies[$method]) + strlen($chunk) > 4 * 1024 * 1024) {
                        $oversized[$method] = true;
                        return 0;
                    }
                    $bodies[$method] .= $chunk;
                    return strlen($chunk);
                },
            ];
            $trust = function_exists('curl_trust_options') ? curl_trust_options() : [];
            curl_setopt_array($handle, array_replace($options, $trust));
            curl_multi_add_handle($multi, $handle);
            $handles[$method] = $handle;
        }
        do {
            $code = curl_multi_exec($multi, $running);
            if ($running > 0 && $code === CURLM_OK) {
                // select's bounded blocking avoids a spin loop while waiting for the network.
                if (curl_multi_select($multi, 0.25) === -1) {
                    usleep(10000);
                }
            }
        } while ($running > 0 && $code === CURLM_OK);
        foreach ($handles as $method => $handle) {
            $httpCode = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($code !== CURLM_OK || curl_errno($handle) !== 0 || $oversized[$method] || $httpCode !== 200) {
                $message = in_array($httpCode, [401, 403], true)
                    ? 'Steam 拒绝了访问，请检查 Web API Key 是否有效。'
                    : 'Steam 数据暂时无法获取，请稍后再试。';
                $results[$method] = ['ok' => false, 'message' => $message];
                continue;
            }
            $data = json_decode($bodies[$method], true, 64, JSON_BIGINT_AS_STRING);
            $results[$method] = is_array($data) && json_last_error() === JSON_ERROR_NONE
                ? ['ok' => true, 'data' => $data]
                : ['ok' => false, 'message' => 'Steam 返回的数据暂时无法读取，请稍后再试。'];
        }
    } finally {
        foreach ($handles as $handle) {
            curl_multi_remove_handle($multi, $handle);
            curl_close($handle);
        }
        curl_multi_close($multi);
    }
    return $results;
}

function sblog_steam_nonnegative_integer(mixed $value): ?int
{
    if (is_int($value)) {
        return $value >= 0 ? $value : null;
    }
    if (is_string($value) && preg_match('/^[0-9]{1,18}$/D', $value)) {
        $number = (int)$value;
        return $number >= 0 ? $number : null;
    }
    return null;
}

function sblog_steam_text(mixed $value, string $fallback = ''): string
{
    if (!is_string($value)) {
        return $fallback;
    }
    $value = trim((string)preg_replace('/[\x00-\x1F\x7F]/u', '', $value));
    if ($value === '') {
        return $fallback;
    }
    preg_match('/^.{0,240}/us', $value, $matches);
    return $matches[0] ?? $fallback;
}

/** Return only HTTPS Steam CDN URLs; arbitrary URLs from API data are never accepted. */
function sblog_steam_image_url(mixed $value): string
{
    if (!is_string($value) || strlen($value) > 2048 || preg_match('/[\x00-\x20\x7F]/', $value)) {
        return '';
    }
    $url = parse_url($value);
    if (!is_array($url) || !in_array(strtolower((string)($url['scheme'] ?? '')), ['https', 'http'], true)
        || isset($url['user']) || isset($url['pass']) || (isset($url['port']) && $url['port'] !== 443)) {
        return '';
    }
    $host = strtolower((string)($url['host'] ?? ''));
    $allowed = $host === 'steamstatic.com' || str_ends_with($host, '.steamstatic.com')
        || $host === 'steamusercontent.com' || str_ends_with($host, '.steamusercontent.com')
        || in_array($host, ['steamcdn-a.akamaihd.net', 'steamuserimages-a.akamaihd.net', 'media.steampowered.com'], true);
    $path = (string)($url['path'] ?? '');
    if (!$allowed || $path === '' || !str_starts_with($path, '/') || str_contains($path, '\\')) {
        return '';
    }
    return 'https://' . $host . $path . (isset($url['query']) ? '?' . $url['query'] : '');
}

/** A missing player is an invalid account/response, not a private library. */
function sblog_steam_normalize_profile(array $payload, string $steamId): ?array
{
    $players = $payload['response']['players'] ?? null;
    if (!is_array($players) || array_values($players) !== $players) {
        return null;
    }
    foreach ($players as $player) {
        if (!is_array($player)) {
            continue;
        }
        $playerId = $player['steamid'] ?? null;
        if ((!is_string($playerId) && !is_int($playerId)) || (string)$playerId !== $steamId) {
            continue;
        }
        $gameId = $player['gameid'] ?? '';
        $appid = is_string($gameId) || is_int($gameId) ? (string)$gameId : '';
        return [
            'steamid' => $steamId,
            'name' => sblog_steam_text($player['personaname'] ?? null, 'Steam 玩家'),
            'avatar' => sblog_steam_image_url($player['avatarfull'] ?? $player['avatarmedium'] ?? $player['avatar'] ?? ''),
            'profile_url' => 'https://steamcommunity.com/profiles/' . $steamId,
            'personastate' => max(0, min(6, sblog_steam_nonnegative_integer($player['personastate'] ?? null) ?? 0)),
            'visibility' => max(0, min(3, sblog_steam_nonnegative_integer($player['communityvisibilitystate'] ?? null) ?? 0)),
            'current_game' => sblog_steam_text($player['gameextrainfo'] ?? ''),
            'current_appid' => preg_match('/^[0-9]{1,18}$/D', $appid) ? $appid : '',
            'last_online' => sblog_steam_nonnegative_integer($player['lastlogoff'] ?? null) ?? 0,
        ];
    }
    return null;
}

/** Missing counts indicate privacy; an explicit zero is a valid, empty public library. */
function sblog_steam_normalize_games(array $payload, bool $owned): ?array
{
    $response = $payload['response'] ?? null;
    if (!is_array($response)) {
        return null;
    }
    $countKey = $owned ? 'game_count' : 'total_count';
    if (!array_key_exists($countKey, $response)) {
        return ['state' => 'private', 'games' => [], 'game_count' => null, 'total_minutes' => null, 'recent_minutes' => null];
    }
    $count = sblog_steam_nonnegative_integer($response[$countKey]);
    if ($count === null || ($count > 0 && !is_array($response['games'] ?? null))
        || (isset($response['games']) && (!is_array($response['games']) || array_values($response['games']) !== $response['games']))) {
        return null;
    }
    $games = [];
    $total = 0;
    $recent = 0;
    foreach ($response['games'] ?? [] as $game) {
        if (!is_array($game) || ($appid = sblog_steam_nonnegative_integer($game['appid'] ?? null)) === null || $appid < 1) {
            return null;
        }
        $minutesTotal = sblog_steam_nonnegative_integer($game['playtime_forever'] ?? 0);
        $minutesRecent = sblog_steam_nonnegative_integer($game['playtime_2weeks'] ?? 0);
        if ($minutesTotal === null || $minutesRecent === null || isset($games[$appid])) {
            return null;
        }
        if ($total > PHP_INT_MAX - $minutesTotal || $recent > PHP_INT_MAX - $minutesRecent) {
            return null;
        }
        $icon = is_string($game['img_icon_url'] ?? null) ? $game['img_icon_url'] : '';
        $games[$appid] = [
            'appid' => $appid,
            'name' => sblog_steam_text($game['name'] ?? null, 'Steam 游戏'),
            'minutes_2weeks' => $minutesRecent,
            'minutes_total' => $minutesTotal,
            'cover_url' => 'https://shared.fastly.steamstatic.com/store_item_assets/steam/apps/' . $appid . '/header.jpg',
            'icon_url' => preg_match('/^[a-f0-9]{40}$/iD', $icon)
                ? 'https://media.steampowered.com/steamcommunity/public/images/apps/' . $appid . '/' . strtolower($icon) . '.jpg'
                : sblog_steam_image_url($icon),
        ];
        $total += $minutesTotal;
        $recent += $minutesRecent;
    }
    if (count($games) !== $count) {
        return null;
    }
    $games = array_values($games);
    $sortKey = $owned ? 'minutes_total' : 'minutes_2weeks';
    usort($games, static fn(array $a, array $b): int => ($b[$sortKey] <=> $a[$sortKey]) ?: ($a['appid'] <=> $b['appid']));
    return ['state' => 'public', 'games' => $games, 'game_count' => $count, 'total_minutes' => $total, 'recent_minutes' => $recent];
}

/** A successful privacy response always replaces old public data, even if another method fails. */
function sblog_steam_combine_snapshot(array $responses, string $steamId, ?array $previous, int $now): array
{
    $previous = sblog_steam_usable_snapshot($previous, $now);
    $snapshot = sblog_steam_empty_snapshot('error', 'Steam 数据暂时无法获取，请稍后再试。');
    $failed = false;
    $retainedMethods = [];
    $successful = false;
    $profilePrivate = false;
    $ownedPrivate = false;
    $recentPrivate = false;
    $ownedPublicConfirmed = false;
    $message = '';
    foreach (['profile', 'recent', 'owned'] as $method) {
        $response = $responses[$method] ?? [];
        $data = !empty($response['ok']) && is_array($response['data'] ?? null) ? $response['data'] : null;
        $normalized = $data !== null
            ? ($method === 'profile' ? sblog_steam_normalize_profile($data, $steamId) : sblog_steam_normalize_games($data, $method === 'owned'))
            : null;
        if ($normalized !== null) {
            $successful = true;
            if ($method === 'profile') {
                $snapshot['profile'] = $normalized;
                $profilePrivate = $normalized['visibility'] < 3;
            } elseif ($method === 'owned') {
                $snapshot['library_state'] = $normalized['state'];
                $snapshot['owned_games'] = $normalized['games'];
                $snapshot['stats']['game_count'] = $normalized['game_count'];
                $snapshot['stats']['total_minutes'] = $normalized['total_minutes'];
                $ownedPrivate = $normalized['state'] === 'private';
                $ownedPublicConfirmed = $normalized['state'] === 'public';
            } else {
                $snapshot['recent_state'] = $normalized['state'];
                $snapshot['recent_games'] = $normalized['games'];
                $snapshot['stats']['recent_minutes'] = $normalized['recent_minutes'];
                $recentPrivate = $normalized['state'] === 'private';
            }
            continue;
        }
        $failed = true;
        // Only known safe messages are forwarded; transport exception/URL details are never exposed.
        $safeMessages = [
            '服务器未启用 cURL 扩展，暂时无法连接 Steam。',
            'Steam 拒绝了访问，请检查 Web API Key 是否有效。',
            'Steam 返回的数据暂时无法读取，请稍后再试。',
        ];
        if (in_array($response['message'] ?? '', $safeMessages, true)) {
            $message = $response['message'];
        }
        if ($previous === null) {
            continue;
        }
        if ($method === 'profile' && is_array($previous['profile'] ?? null)) {
            $snapshot['profile'] = $previous['profile'];
            $retainedMethods['profile'] = true;
        } elseif ($method === 'owned' && in_array($previous['library_state'] ?? '', ['public', 'private'], true)) {
            $snapshot['library_state'] = $previous['library_state'];
            $snapshot['owned_games'] = $previous['owned_games'];
            $snapshot['stats']['game_count'] = $previous['stats']['game_count'];
            $snapshot['stats']['total_minutes'] = $previous['stats']['total_minutes'];
            $retainedMethods['owned'] = true;
        } elseif ($method === 'recent' && in_array($previous['recent_state'] ?? '', ['public', 'private'], true)) {
            $snapshot['recent_state'] = $previous['recent_state'];
            $snapshot['recent_games'] = $previous['recent_games'];
            $snapshot['stats']['recent_minutes'] = $previous['stats']['recent_minutes'];
            $retainedMethods['recent'] = true;
        }
    }
    // Explicit owned-game visibility is authoritative for the library. A missing
    // recent-play count cannot erase a newly verified public library, but does
    // prevent displaying a retained library when its own request failed.
    $hideOwned = $profilePrivate || $ownedPrivate || ($recentPrivate && !$ownedPublicConfirmed);
    $hideRecent = $profilePrivate || $ownedPrivate || $recentPrivate;
    if ($hideOwned) {
        $snapshot['owned_games'] = [];
        $snapshot['stats']['game_count'] = null;
        $snapshot['stats']['total_minutes'] = null;
        $snapshot['library_state'] = 'private';
        unset($retainedMethods['owned']);
    }
    if ($hideRecent) {
        $snapshot['recent_games'] = [];
        $snapshot['stats']['recent_minutes'] = null;
        $snapshot['recent_state'] = 'private';
        unset($retainedMethods['recent']);
    }
    if ($snapshot['profile'] !== null
        && ($profilePrivate || $ownedPrivate || ($recentPrivate && isset($retainedMethods['profile'])))) {
        $snapshot['profile']['current_game'] = '';
        $snapshot['profile']['current_appid'] = '';
    }
    $retained = $retainedMethods !== [];
    $snapshot['updated_at'] = $retained ? (int)($previous['updated_at'] ?? 0) : ($successful ? $now : 0);
    $snapshot['status'] = !$failed ? 'ok' : ($retained ? 'stale' : 'error');
    $snapshot['message'] = !$failed ? '' : ($retained
        ? 'Steam 暂时无法完整同步，正在显示部分上次同步的数据。'
        : ($message !== '' ? $message : 'Steam 数据暂时无法获取，请检查账号和插件设置，或稍后再试。'));
    return $snapshot;
}
