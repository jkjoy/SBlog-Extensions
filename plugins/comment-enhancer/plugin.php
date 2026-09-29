<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/local-geo.php';

const SCE_VERSION = '1.2.0';
const SCE_SCHEMA_VERSION = '3';
const SCE_LOCATION_CACHE_TTL = 15552000;
const SCE_LOCATION_FAILURE_TTL = 21600;

function sce_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    db()->exec(
        "CREATE TABLE IF NOT EXISTS comment_enhancer_settings(
            name TEXT PRIMARY KEY,
            value TEXT NOT NULL DEFAULT ''
        )"
    );
    db()->exec(
        "CREATE TABLE IF NOT EXISTS comment_enhancer_ip_cache(
            ip_hash TEXT PRIMARY KEY,
            location TEXT NOT NULL DEFAULT '',
            status TEXT NOT NULL DEFAULT 'failed',
            checked_at INTEGER NOT NULL DEFAULT 0,
            attempted_at INTEGER NOT NULL DEFAULT 0
        )"
    );
    $columns = table_columns(db(), 'comment_enhancer_ip_cache');
    if (!isset($columns['attempted_at'])) {
        db()->exec('ALTER TABLE comment_enhancer_ip_cache ADD COLUMN attempted_at INTEGER NOT NULL DEFAULT 0');
    }

    $insertDefault = db()->prepare('INSERT OR IGNORE INTO comment_enhancer_settings(name, value) VALUES(?, ?)');
    $insertDefault->execute(['cache_secret', bin2hex(random_bytes(32))]);
    $insertDefault->execute(['lookup_generation', bin2hex(random_bytes(16))]);
    $insertDefault->execute(['local_geo_database', '']);
    $insertDefault->execute(['backfill_cursor', (string)PHP_INT_MAX]);

    $schemaVersion = (string)val('SELECT value FROM comment_enhancer_settings WHERE name = ?', ['schema_version']);
    $lookupMode = (string)val('SELECT value FROM comment_enhancer_settings WHERE name = ?', ['lookup_mode']);
    if (!in_array($lookupMode, ['off', 'local', 'online'], true)) {
        $lookupMode = (string)val('SELECT value FROM comment_enhancer_settings WHERE name = ?', ['online_lookup']) === '1'
            ? 'online'
            : 'off';
    }
    $insertDefault->execute(['lookup_mode', $lookupMode]);

    $keyVersion = (string)val('SELECT value FROM comment_enhancer_settings WHERE name = ?', ['cache_key_version']);
    if ($schemaVersion !== SCE_SCHEMA_VERSION || $keyVersion !== '2') {
        $generation = bin2hex(random_bytes(16));
        $statement = db()->prepare('INSERT OR REPLACE INTO comment_enhancer_settings(name, value) VALUES(?, ?)');
        db()->beginTransaction();
        try {
            db()->exec('DELETE FROM comment_enhancer_ip_cache');
            foreach ([
                'lookup_mode' => $lookupMode,
                'lookup_generation' => $generation,
                'backfill_cursor' => (string)PHP_INT_MAX,
                'cache_key_version' => '2',
                'schema_version' => SCE_SCHEMA_VERSION,
            ] as $name => $value) {
                $statement->execute([$name, $value]);
            }
            db()->prepare('DELETE FROM comment_enhancer_settings WHERE name = ?')->execute(['online_lookup']);
            db()->commit();
        } catch (Throwable $exception) {
            if (db()->inTransaction()) {
                db()->rollBack();
            }
            throw $exception;
        }
    }
    $lastPrune = (int)val('SELECT value FROM comment_enhancer_settings WHERE name = ?', ['last_cache_prune']);
    if ($lastPrune < time() - 86400) {
        q(
            "DELETE FROM comment_enhancer_ip_cache
             WHERE (status = 'ok' AND checked_at < ?)
                OR (status <> 'ok' AND attempted_at < ?)",
            [time() - SCE_LOCATION_CACHE_TTL, time() - SCE_LOCATION_CACHE_TTL]
        );
        db()->prepare('INSERT OR REPLACE INTO comment_enhancer_settings(name, value) VALUES(?, ?)')
            ->execute(['last_cache_prune', (string)time()]);
    }
}

function sce_cache_key_for_ip(string $ip): string
{
    $settings = sce_settings();
    $secret = (string)($settings['cache_secret'] ?? '');
    $generation = (string)($settings['lookup_generation'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $secret) || !preg_match('/^[a-f0-9]{32}$/', $generation)) {
        $secret = preg_match('/^[a-f0-9]{64}$/', $secret) ? $secret : bin2hex(random_bytes(32));
        $generation = bin2hex(random_bytes(16));
        $statement = db()->prepare('INSERT OR REPLACE INTO comment_enhancer_settings(name, value) VALUES(?, ?)');
        $statement->execute(['cache_secret', $secret]);
        $statement->execute(['lookup_generation', $generation]);
        db()->exec('DELETE FROM comment_enhancer_ip_cache');
        $GLOBALS['sce_settings_cache'] = array_replace($settings, [
            'cache_secret' => $secret,
            'lookup_generation' => $generation,
        ]);
        $GLOBALS['sce_location_memory'] = [];
    }
    $canonical = sce_canonical_ip($ip);
    $packed = $canonical !== '' ? @inet_pton($canonical) : false;
    return is_string($packed) ? hash_hmac('sha256', "v2\0" . $generation . "\0" . $packed, $secret) : '';
}

function sce_settings(): array
{
    if (is_array($GLOBALS['sce_settings_cache'] ?? null)) {
        return $GLOBALS['sce_settings_cache'];
    }
    $settings = [
        'lookup_mode' => 'off',
        'local_geo_database' => '',
        'lookup_generation' => '',
        'backfill_cursor' => (string)PHP_INT_MAX,
    ];
    try {
        foreach (all_rows('SELECT name, value FROM comment_enhancer_settings') as $row) {
            $settings[(string)$row['name']] = (string)$row['value'];
        }
    } catch (Throwable) {
    }
    return $GLOBALS['sce_settings_cache'] = $settings;
}

function sce_save_settings(array $values): void
{
    sce_install();
    $statement = db()->prepare('INSERT OR REPLACE INTO comment_enhancer_settings(name, value) VALUES(?, ?)');
    foreach ($values as $name => $value) {
        $statement->execute([(string)$name, (string)$value]);
    }
    $GLOBALS['sce_settings_cache'] = array_replace(sce_settings(), array_map('strval', $values));
}

function sce_lookup_mode(): string
{
    $mode = (string)(sce_settings()['lookup_mode'] ?? 'off');
    return in_array($mode, ['off', 'local', 'online'], true) ? $mode : 'off';
}

function sce_change_lookup_context(string $mode, string $database): void
{
    if (!in_array($mode, ['off', 'local', 'online'], true)) {
        throw new DomainException(sce_text('invalid_mode'));
    }
    $database = trim($database);
    if ($database !== '' && !preg_match('/^geoip-[a-f0-9]{32}\.mmdb$/', $database)) {
        throw new DomainException(sce_text('database_invalid'));
    }
    $settings = sce_settings();
    if ((string)($settings['lookup_mode'] ?? 'off') === $mode
        && (string)($settings['local_geo_database'] ?? '') === $database) {
        return;
    }

    $currentMode = (string)($settings['lookup_mode'] ?? 'off');
    $currentDatabase = (string)($settings['local_geo_database'] ?? '');
    if ($currentMode === $mode && $currentMode !== 'local') {
        sce_save_settings(['local_geo_database' => $database]);
        return;
    }
    if ($mode === 'off' && $currentMode !== 'off' && $database === $currentDatabase) {
        sce_save_settings(['lookup_mode' => 'off']);
        return;
    }

    $generation = bin2hex(random_bytes(16));
    $values = [
        'lookup_mode' => $mode,
        'local_geo_database' => $database,
        'lookup_generation' => $generation,
        'backfill_cursor' => (string)PHP_INT_MAX,
    ];
    $pdo = db();
    $statement = $pdo->prepare('INSERT OR REPLACE INTO comment_enhancer_settings(name, value) VALUES(?, ?)');
    $ownsTransaction = !$pdo->inTransaction();
    $transactionStarted = false;
    if ($ownsTransaction) {
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
    }
    try {
        foreach ($values as $name => $value) {
            $statement->execute([$name, $value]);
        }
        $pdo->exec('DELETE FROM comment_enhancer_ip_cache');
        if ($ownsTransaction) {
            $pdo->exec('COMMIT');
            $transactionStarted = false;
        }
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $exception;
    }
    $GLOBALS['sce_settings_cache'] = array_replace($settings, $values);
    $GLOBALS['sce_location_memory'] = [];
}

function sce_clear_location_cache(): void
{
    $settings = sce_settings();
    $values = [
        'lookup_generation' => bin2hex(random_bytes(16)),
        'backfill_cursor' => (string)PHP_INT_MAX,
    ];
    $pdo = db();
    $statement = $pdo->prepare('INSERT OR REPLACE INTO comment_enhancer_settings(name, value) VALUES(?, ?)');
    $ownsTransaction = !$pdo->inTransaction();
    $transactionStarted = false;
    if ($ownsTransaction) {
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
    }
    try {
        foreach ($values as $name => $value) {
            $statement->execute([$name, $value]);
        }
        $pdo->exec('DELETE FROM comment_enhancer_ip_cache');
        if ($ownsTransaction) {
            $pdo->exec('COMMIT');
            $transactionStarted = false;
        }
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $exception;
    }
    $GLOBALS['sce_settings_cache'] = array_replace($settings, $values);
    $GLOBALS['sce_location_memory'] = [];
}

function sce_lookup_context_is_current(string $mode, string $generation): bool
{
    $context = one(
        "SELECT
            MAX(CASE WHEN name = 'lookup_mode' THEN value ELSE '' END) AS lookup_mode,
            MAX(CASE WHEN name = 'lookup_generation' THEN value ELSE '' END) AS lookup_generation
         FROM comment_enhancer_settings
         WHERE name IN ('lookup_mode', 'lookup_generation')"
    );
    return is_array($context)
        && $mode !== 'off'
        && (string)($context['lookup_mode'] ?? '') === $mode
        && hash_equals($generation, (string)($context['lookup_generation'] ?? ''));
}

function sce_store_lookup_result_if_current(
    string $mode,
    string $generation,
    string $ipHash,
    string $cacheValue,
    int $now,
    bool $staleIsFresh
): bool {
    $pdo = db();
    $ownsTransaction = !$pdo->inTransaction();
    $transactionStarted = false;
    if ($ownsTransaction) {
        $pdo->exec('BEGIN IMMEDIATE');
        $transactionStarted = true;
    }
    try {
        if (!sce_lookup_context_is_current($mode, $generation)) {
            if ($ownsTransaction) {
                $pdo->exec('COMMIT');
                $transactionStarted = false;
            }
            return false;
        }
        if ($cacheValue !== '') {
            sce_store_location($ipHash, $cacheValue, 'ok', $now, $now);
        } elseif ($staleIsFresh) {
            q('UPDATE comment_enhancer_ip_cache SET attempted_at = ? WHERE ip_hash = ?', [$now, $ipHash]);
        } else {
            sce_store_location($ipHash, '', 'failed', 0, $now);
        }
        if ($ownsTransaction) {
            $pdo->exec('COMMIT');
            $transactionStarted = false;
        }
        return true;
    } catch (Throwable $exception) {
        if ($transactionStarted) {
            try {
                $pdo->exec('ROLLBACK');
            } catch (Throwable) {
            }
        }
        throw $exception;
    }
}

function sce_text(string $key, array $parameters = []): string
{
    $english = str_starts_with(strtolower(sblog_i18n_locale()), 'en');
    $messages = $english ? [
        'group' => 'Commenter details',
        'level' => 'Comment activity level {level}',
        'owner' => 'Owner',
        'owner_title' => 'Authenticated site owner',
        'location' => 'IP location: {value}',
        'browser' => 'Browser: {value}',
        'os' => 'Operating system: {value}',
        'local' => 'Local network',
        'unknown_location' => 'Unknown location',
        'settings_title' => 'Comment Enhancer',
        'settings_heading' => 'Comment metadata',
        'settings_description' => 'Show activity level, coarse IP location, browser, and operating system without exposing email, IP, or the full user agent.',
        'source_title' => 'IP location source',
        'mode_off' => 'Do not look up new IPs',
        'mode_off_hint' => 'Only existing cached locations are displayed. No new local or online lookup is made.',
        'mode_local' => 'Local MMDB database',
        'mode_local_hint' => 'Look up IPv4 and IPv6 on this server. IP addresses never leave the site.',
        'mode_online' => 'Online service (ipwho.is)',
        'mode_online_hint' => 'New public IPs are sent to ipwho.is over HTTPS after comment submission. Public page views never trigger a lookup.',
        'invalid_mode' => 'Choose a valid IP location source.',
        'local_required' => 'Upload a valid MMDB database before selecting local lookup.',
        'save' => 'Save settings',
        'saved' => 'Comment enhancer settings saved.',
        'database_title' => 'Local IP database',
        'database_ready' => 'Ready',
        'database_missing' => 'No database uploaded',
        'database_invalid' => 'The selected local IP database is missing or invalid.',
        'database_summary' => '{type} · built {built} · {size} · {networks}',
        'database_build_unknown' => 'unknown date',
        'database_both' => 'IPv4 and IPv6',
        'database_ipv4' => 'IPv4 only',
        'database_ipv6' => 'IPv6 only',
        'database_none' => 'no supported network',
        'database_upload_label' => 'MMDB file',
        'database_upload_hint' => 'Upload a MaxMind GeoLite2 or GeoIP2 City/Country .mmdb file, up to 128 MiB. The database file is stored under the protected data directory and is not bundled with the plugin.',
        'database_upload' => 'Upload database',
        'database_uploaded' => 'Local IP database uploaded.',
        'database_delete' => 'Delete database',
        'database_delete_confirm' => 'Delete the local IP database? Local lookup will be turned off.',
        'database_deleted' => 'Local IP database deleted.',
        'database_delete_failed' => 'The database file is still in use and could not be removed. Local lookup was turned off; retry deletion after current requests finish.',
        'database_replaced_cleanup_failed' => 'The new database is active, but an old database file is still in use. Retry cleanup below.',
        'database_cleanup_pending' => '{count} old database file(s) are awaiting cleanup.',
        'database_cleanup' => 'Retry cleanup',
        'database_cleanup_done' => 'Removed {count} old database file(s).',
        'database_cleanup_failed' => 'Some old database files are still in use. Try again after current requests finish.',
        'upload_server_limit' => 'The database exceeds the server upload limit.',
        'upload_missing' => 'Select a MaxMind .mmdb database to upload.',
        'upload_failed' => 'The database upload failed. Check the server upload settings and try again.',
        'upload_invalid' => 'The uploaded database is invalid.',
        'upload_extension_invalid' => 'Only .mmdb database files are supported.',
        'upload_size_invalid' => 'The database must be a non-empty file no larger than 128 MiB.',
        'data_directory_unavailable' => 'The site data directory is unavailable.',
        'data_directory_unsafe' => 'The local database directory is unsafe.',
        'data_directory_create_failed' => 'The local database directory could not be created.',
        'data_directory_not_writable' => 'The local database directory is not writable.',
        'upload_store_failed' => 'The uploaded database could not be saved.',
        'upload_database_unsupported' => 'The file is not a supported MaxMind City or Country database.',
        'upload_hash_failed' => 'The uploaded database could not be verified.',
        'database_destination_invalid' => 'The local database destination is invalid.',
        'database_conflict' => 'A conflicting database file already exists.',
        'temporary_cleanup_failed' => 'The temporary database file could not be removed.',
        'database_install_failed' => 'The local database could not be installed.',
        'database_verify_failed' => 'The installed database could not be verified.',
        'database_lock_failed' => 'The local database is busy. Try again after current database maintenance finishes.',
        'cache_title' => 'Location cache',
        'cache_summary' => '{success} locations cached; {failed} failed lookups cached.',
        'backfill' => 'Backfill history',
        'backfill_hint_online' => 'Looks up at most five uncached historical public IPs per click. No email, name, comment text, or user agent is sent.',
        'backfill_hint_local' => 'Looks up at most 100 uncached historical public IPs per click using the local database.',
        'backfill_hint_off' => 'Select a lookup source before backfilling historical comments.',
        'backfill_done' => 'Historical location lookup processed {count} IPs.',
        'lookup_required' => 'Select an available IP lookup source before backfilling history.',
        'clear_cache' => 'Clear location cache',
        'cache_cleared' => 'Location cache cleared.',
    ] : [
        'group' => '评论者信息',
        'level' => '评论活跃等级 {level}',
        'owner' => '博主',
        'owner_title' => '已登录的站点管理员',
        'location' => 'IP 归属地：{value}',
        'browser' => '浏览器：{value}',
        'os' => '操作系统：{value}',
        'local' => '本地网络',
        'unknown_location' => '未知地区',
        'settings_title' => '评论增强',
        'settings_heading' => '评论元信息',
        'settings_description' => '显示活跃等级、粗粒度 IP 归属地、浏览器和操作系统，不公开邮箱、完整 IP 或完整 User-Agent。',
        'source_title' => 'IP 归属地查询源',
        'mode_off' => '不查询新 IP',
        'mode_off_hint' => '仅显示已有缓存，不进行新的本地或在线查询。',
        'mode_local' => '本地 MMDB 数据库',
        'mode_local_hint' => '在本站服务器内查询 IPv4 和 IPv6，IP 地址不会离开网站。',
        'mode_online' => '在线服务（ipwho.is）',
        'mode_online_hint' => '评论提交后通过 HTTPS 查询公网 IP；访客浏览公开页面不会触发查询。',
        'invalid_mode' => '请选择有效的 IP 归属地查询源。',
        'local_required' => '选择本地查询前，请先上传有效的 MMDB 数据库。',
        'save' => '保存设置',
        'saved' => '评论增强设置已保存。',
        'database_title' => '本地 IP 数据库',
        'database_ready' => '可用',
        'database_missing' => '尚未上传数据库',
        'database_invalid' => '当前选择的本地 IP 数据库不存在或无效。',
        'database_summary' => '{type} · 构建于 {built} · {size} · {networks}',
        'database_build_unknown' => '日期未知',
        'database_both' => '支持 IPv4 和 IPv6',
        'database_ipv4' => '仅支持 IPv4',
        'database_ipv6' => '仅支持 IPv6',
        'database_none' => '不支持可识别的地址类型',
        'database_upload_label' => 'MMDB 文件',
        'database_upload_hint' => '支持 MaxMind GeoLite2 或 GeoIP2 的 City/Country .mmdb 文件，最大 128 MiB。数据库保存在受保护的数据目录中，不随插件打包。',
        'database_upload' => '上传数据库',
        'database_uploaded' => '本地 IP 数据库已上传。',
        'database_delete' => '删除数据库',
        'database_delete_confirm' => '确定删除本地 IP 数据库吗？本地查询将同时关闭。',
        'database_deleted' => '本地 IP 数据库已删除。',
        'database_delete_failed' => '数据库文件仍被占用，暂时无法删除；本地查询已关闭，请等待当前请求结束后重试。',
        'database_replaced_cleanup_failed' => '新数据库已启用，但旧数据库文件仍被占用，请在下方重试清理。',
        'database_cleanup_pending' => '有 {count} 个旧数据库文件等待清理。',
        'database_cleanup' => '重试清理',
        'database_cleanup_done' => '已清理 {count} 个旧数据库文件。',
        'database_cleanup_failed' => '部分旧数据库文件仍被占用，请等待当前请求结束后重试。',
        'upload_server_limit' => '数据库超过了服务器的上传限制。',
        'upload_missing' => '请选择要上传的 MaxMind .mmdb 数据库。',
        'upload_failed' => '数据库上传失败，请检查服务器上传配置后重试。',
        'upload_invalid' => '上传的数据库文件无效。',
        'upload_extension_invalid' => '仅支持 .mmdb 数据库文件。',
        'upload_size_invalid' => '数据库必须是非空文件，且不能超过 128 MiB。',
        'data_directory_unavailable' => '站点数据目录不可用。',
        'data_directory_unsafe' => '本地数据库目录不安全。',
        'data_directory_create_failed' => '无法创建本地数据库目录。',
        'data_directory_not_writable' => '本地数据库目录不可写。',
        'upload_store_failed' => '无法保存上传的数据库。',
        'upload_database_unsupported' => '该文件不是受支持的 MaxMind City 或 Country 数据库。',
        'upload_hash_failed' => '无法校验上传的数据库。',
        'database_destination_invalid' => '本地数据库保存位置无效。',
        'database_conflict' => '服务器上已存在冲突的数据库文件。',
        'temporary_cleanup_failed' => '无法删除数据库临时文件。',
        'database_install_failed' => '无法安装本地数据库。',
        'database_verify_failed' => '安装后无法验证本地数据库。',
        'database_lock_failed' => '本地数据库正忙，请等待当前维护操作结束后重试。',
        'cache_title' => '归属地缓存',
        'cache_summary' => '已缓存 {success} 个归属地，另有 {failed} 个失败记录。',
        'backfill' => '补全历史归属地',
        'backfill_hint_online' => '每次最多在线查询 5 个尚未缓存的历史公网 IP；不会发送邮箱、昵称、评论内容或 User-Agent。',
        'backfill_hint_local' => '每次最多使用本地数据库查询 100 个尚未缓存的历史公网 IP。',
        'backfill_hint_off' => '请先选择查询源，再补全历史评论。',
        'backfill_done' => '已处理 {count} 个历史 IP。',
        'lookup_required' => '请先选择可用的 IP 归属地查询源。',
        'clear_cache' => '清空归属地缓存',
        'cache_cleared' => '归属地缓存已清空。',
    ];

    $message = $messages[$key] ?? $key;
    $replacements = [];
    foreach ($parameters as $name => $value) {
        $replacements['{' . $name . '}'] = (string)$value;
    }
    return strtr($message, $replacements);
}

function sce_comment_email_counts(array $comments): array
{
    $emails = [];
    foreach ($comments as $comment) {
        if ((int)($comment['user_id'] ?? 0) > 0) {
            continue;
        }
        $email = str_lower_u(trim((string)($comment['author_email'] ?? '')));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = true;
        }
    }
    if ($emails === []) {
        return [];
    }

    $emailList = array_keys($emails);
    $placeholders = implode(',', array_fill(0, count($emailList), '?'));
    $rows = all_rows(
        "SELECT author_email AS email_key, COUNT(*) AS total
         FROM comments
         WHERE user_id IS NULL AND status = 'approved' AND author_email COLLATE NOCASE IN ({$placeholders})
         GROUP BY author_email COLLATE NOCASE",
        $emailList
    );

    $counts = [];
    foreach ($rows as $row) {
        $counts[str_lower_u(trim((string)$row['email_key']))] = (int)$row['total'];
    }
    return $counts;
}

function sce_lookup_public_ip(string $ip): string
{
    if (sce_ip_scope($ip) !== 'public' || !function_exists('curl_init')) {
        return '';
    }

    $handle = curl_init('https://ipwho.is/' . rawurlencode($ip) . '?fields=success,country_code,country,region');
    if ($handle === false) {
        return '';
    }

    $body = '';
    $tooLarge = false;
    $options = [
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT_MS => 800,
        CURLOPT_TIMEOUT_MS => 1800,
        CURLOPT_NOSIGNAL => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'SBlog-Comment-Enhancer/' . SCE_VERSION,
        CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$tooLarge): int {
            if (strlen($body) + strlen($chunk) > 8192) {
                $tooLarge = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ];
    if (defined('CURLOPT_PROTOCOLS') && defined('CURLPROTO_HTTPS')) {
        $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
    }
    curl_setopt_array($handle, array_replace($options, curl_trust_options()));
    $ok = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);

    if ($ok === false || $tooLarge || $status !== 200 || $body === '') {
        return '';
    }

    try {
        $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return '';
    }
    return is_array($payload) ? sce_geo_cache_value($payload) : '';
}

function sce_lookup_configured_ip(string $ip): string
{
    $mode = sce_lookup_mode();
    if ($mode === 'local') {
        return sce_lookup_local_ip($ip, (string)(sce_settings()['local_geo_database'] ?? ''));
    }
    if ($mode === 'online') {
        return sce_lookup_public_ip($ip);
    }
    return '';
}

function sce_location_label(string $cachedValue): string
{
    $cachedValue = trim($cachedValue);
    if ($cachedValue === '') {
        return '';
    }
    try {
        $payload = json_decode($cachedValue, true, 8, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        return sce_clean_geo_text($cachedValue);
    }
    return is_array($payload) ? sce_format_geo_location($payload, sblog_i18n_locale()) : '';
}

function sce_store_location(string $ipHash, string $location, string $status, int $checkedAt, int $attemptedAt): void
{
    q(
        "INSERT OR REPLACE INTO comment_enhancer_ip_cache(ip_hash, location, status, checked_at, attempted_at)
         VALUES(?, ?, ?, ?, ?)",
        [$ipHash, $location, $status, $checkedAt, $attemptedAt]
    );
}

function sce_prime_location_cache(array $comments): void
{
    $keys = [];
    foreach ($comments as $comment) {
        $ip = trim((string)($comment['ip_address'] ?? ''));
        if (sce_ip_scope($ip) === 'public') {
            $key = sce_cache_key_for_ip($ip);
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
    }
    if ($keys === []) {
        return;
    }

    $memory = is_array($GLOBALS['sce_location_memory'] ?? null) ? $GLOBALS['sce_location_memory'] : [];
    $missing = array_values(array_filter(
        array_keys($keys),
        static fn(string $key): bool => !array_key_exists($key, $memory)
    ));
    if ($missing === []) {
        return;
    }

    $placeholders = implode(',', array_fill(0, count($missing), '?'));
    foreach (all_rows(
        "SELECT ip_hash, location, status, checked_at
         FROM comment_enhancer_ip_cache
         WHERE ip_hash IN ({$placeholders})",
        $missing
    ) as $row) {
        $fresh = (string)($row['status'] ?? '') === 'ok'
            && (int)($row['checked_at'] ?? 0) >= time() - SCE_LOCATION_CACHE_TTL;
        $location = $fresh ? sce_location_label((string)($row['location'] ?? '')) : '';
        $memory[(string)$row['ip_hash']] = $location !== '' ? $location : sce_text('unknown_location');
    }
    foreach ($missing as $key) {
        if (!array_key_exists($key, $memory)) {
            $memory[$key] = sce_text('unknown_location');
        }
    }
    $GLOBALS['sce_location_memory'] = $memory;
}

function sce_location_for_ip(string $ip, bool $allowLookup = false): ?string
{
    $memory = is_array($GLOBALS['sce_location_memory'] ?? null) ? $GLOBALS['sce_location_memory'] : [];

    $scope = sce_ip_scope($ip);
    if ($scope === 'local') {
        return sce_text('local');
    }
    if ($scope !== 'public') {
        return null;
    }

    $cacheKey = sce_cache_key_for_ip($ip);
    if ($cacheKey === '') {
        return null;
    }
    if (array_key_exists($cacheKey, $memory)) {
        return $memory[$cacheKey];
    }

    $cached = null;
    try {
        $cached = one(
            'SELECT location, status, checked_at, attempted_at FROM comment_enhancer_ip_cache WHERE ip_hash = ?',
            [$cacheKey]
        );
    } catch (Throwable) {
    }

    $now = time();
    $staleValue = is_array($cached) ? trim((string)($cached['location'] ?? '')) : '';
    $staleIsFresh = is_array($cached)
        && (string)($cached['status'] ?? '') === 'ok'
        && (int)($cached['checked_at'] ?? 0) >= $now - SCE_LOCATION_CACHE_TTL;
    $staleLocation = $staleIsFresh ? sce_location_label($staleValue) : '';
    if (is_array($cached)) {
        if ((string)($cached['status'] ?? '') === 'ok'
            && (int)($cached['checked_at'] ?? 0) >= $now - SCE_LOCATION_CACHE_TTL) {
            $memory[$cacheKey] = $staleLocation !== '' ? $staleLocation : sce_text('unknown_location');
            $GLOBALS['sce_location_memory'] = $memory;
            return $memory[$cacheKey];
        }
        if ((int)($cached['attempted_at'] ?? 0) >= $now - SCE_LOCATION_FAILURE_TTL) {
            $memory[$cacheKey] = $staleLocation !== '' ? $staleLocation : sce_text('unknown_location');
            $GLOBALS['sce_location_memory'] = $memory;
            return $memory[$cacheKey];
        }
    }

    $lookupMode = sce_lookup_mode();
    if (!$allowLookup || $lookupMode === 'off') {
        $memory[$cacheKey] = $staleLocation !== '' ? $staleLocation : sce_text('unknown_location');
        $GLOBALS['sce_location_memory'] = $memory;
        return $memory[$cacheKey];
    }

    $lookupGeneration = (string)(sce_settings()['lookup_generation'] ?? '');
    $cacheValue = sce_lookup_configured_ip($ip);
    $stored = false;
    try {
        $stored = sce_store_lookup_result_if_current(
            $lookupMode,
            $lookupGeneration,
            $cacheKey,
            $cacheValue,
            $now,
            $staleIsFresh
        );
    } catch (Throwable) {
    }

    $location = $stored && $cacheValue !== '' ? sce_location_label($cacheValue) : $staleLocation;
    $memory[$cacheKey] = $location !== '' ? $location : sce_text('unknown_location');
    $GLOBALS['sce_location_memory'] = $memory;
    return $memory[$cacheKey];
}

function sce_cache_new_comment_location(array $context): void
{
    if (sce_lookup_mode() === 'off') {
        return;
    }
    $commentId = (int)($context['comment_id'] ?? 0);
    if ($commentId < 1) {
        return;
    }

    $comment = one('SELECT ip_address FROM comments WHERE id = ?', [$commentId]);
    if (is_array($comment)) {
        sce_location_for_ip((string)$comment['ip_address'], true);
    }
}

function sce_handle_comment_status_changed(array $context): void
{
    $comments = is_array($context['comments'] ?? null) ? $context['comments'] : [];
    $operation = (string)($context['operation'] ?? '');
    if ($operation === 'delete') {
        foreach ($comments as $comment) {
            $ip = trim((string)($comment['ip_address'] ?? ''));
            if ($ip === '' || val('SELECT 1 FROM comments WHERE ip_address = ? LIMIT 1', [$ip]) !== false) {
                continue;
            }
            $cacheKey = sce_cache_key_for_ip($ip);
            if ($cacheKey !== '') {
                q('DELETE FROM comment_enhancer_ip_cache WHERE ip_hash = ?', [$cacheKey]);
            }
        }
        return;
    }

    if ((string)($context['status'] ?? '') !== 'approved' || sce_lookup_mode() === 'off') {
        return;
    }
    $lookedUp = 0;
    foreach ($comments as $comment) {
        $ip = trim((string)($comment['ip_address'] ?? ''));
        if (sce_ip_scope($ip) !== 'public') {
            continue;
        }
        sce_location_for_ip($ip, true);
        $lookedUp++;
        if ($lookedUp >= 5) {
            break;
        }
    }
}

function sce_handle_plugin_status_changed(array $context): void
{
    if ((string)($context['plugin'] ?? '') === 'comment-enhancer'
        && (string)($context['operation'] ?? '') === 'deactivate') {
        sce_with_optional_local_geo_lock(static function (): void {
            unset($GLOBALS['sce_settings_cache']);
            sce_change_lookup_context('off', (string)(sce_settings()['local_geo_database'] ?? ''));
            sce_clear_location_cache();
        });
    }
}

function sce_client_icon_html(string $kind, string $value): string
{
    $icon = $kind === 'browser' ? sce_browser_icon_name($value) : sce_os_icon_name($value);
    $label = sce_text($kind, ['value' => $value]);
    return '<span class="comment-enhancer-meta__icon comment-enhancer-meta__icon--' . h($kind) . ' comment-enhancer-meta__icon--' . h($icon) . '" role="listitem" tabindex="0" aria-label="' . h($label) . '">'
        . sce_icon_svg($icon)
        . '<span class="comment-enhancer-meta__tooltip" aria-hidden="true">' . h($value) . '</span>'
        . '</span>';
}

function sce_render_comment_identity(mixed $html, array $context): string
{
    $existing = is_string($html) ? $html : '';
    $comment = is_array($context['comment'] ?? null) ? $context['comment'] : [];
    if ($comment === []) {
        return $existing;
    }

    static $countsByEmail = [];
    $visibleComments = is_array($context['comments'] ?? null) ? $context['comments'] : [$comment];
    $emailsToLoad = [];
    foreach ($visibleComments as $visibleComment) {
        if ((int)($visibleComment['user_id'] ?? 0) > 0) {
            continue;
        }
        $visibleEmail = str_lower_u(trim((string)($visibleComment['author_email'] ?? '')));
        if (filter_var($visibleEmail, FILTER_VALIDATE_EMAIL) && !array_key_exists($visibleEmail, $countsByEmail)) {
            $emailsToLoad[$visibleEmail] = true;
        }
    }
    if ($emailsToLoad !== []) {
        $batch = sce_comment_email_counts(array_map(
            static fn(string $email): array => ['author_email' => $email, 'user_id' => null],
            array_keys($emailsToLoad)
        ));
        foreach (array_keys($emailsToLoad) as $loadedEmail) {
            $countsByEmail[$loadedEmail] = (int)($batch[$loadedEmail] ?? 0);
        }
    }

    $email = str_lower_u(trim((string)($comment['author_email'] ?? '')));
    $authenticated = (int)($comment['user_id'] ?? 0) > 0;
    $commentCount = !$authenticated && filter_var($email, FILTER_VALIDATE_EMAIL)
        ? (int)($countsByEmail[$email] ?? 0)
        : 0;
    if ($authenticated) {
        $title = sce_text('owner_title');
        return $existing . '<span class="comment-enhancer-badge comment-enhancer-badge--owner" aria-label="' . h($title) . '" title="' . h($title) . '">' . h(sce_text('owner')) . '</span>';
    } elseif ($commentCount > 0) {
        $level = sce_level_for_count($commentCount);
        $levelLabel = 'LV' . $level;
        $levelTitle = sce_text('level', ['level' => $levelLabel]);
        return $existing . '<span class="comment-enhancer-badge comment-enhancer-badge--lv' . $level . '" aria-label="' . h($levelTitle) . '" title="' . h($levelTitle) . '">' . h($levelLabel) . '</span>';
    }

    return $existing;
}

function sce_render_comment_meta(mixed $html, array $context): string
{
    $existing = is_string($html) ? $html : '';
    $comment = is_array($context['comment'] ?? null) ? $context['comment'] : [];
    if ($comment === []) {
        return $existing;
    }

    $visibleComments = is_array($context['comments'] ?? null) ? $context['comments'] : [$comment];
    static $primedPosts = [];
    $postId = (int)((is_array($context['post'] ?? null) ? $context['post'] : [])['id'] ?? 0);
    if ($postId < 1 || !isset($primedPosts[$postId])) {
        sce_prime_location_cache($visibleComments);
        if ($postId > 0 && array_key_exists('comments', $context)) {
            $primedPosts[$postId] = true;
        }
    }

    $items = [];
    $client = sce_parse_user_agent((string)($comment['user_agent'] ?? ''));
    if ($client['browser'] !== '') {
        $items[] = sce_client_icon_html('browser', $client['browser']);
    }
    if ($client['os'] !== '') {
        $items[] = sce_client_icon_html('os', $client['os']);
    }

    $location = sce_location_for_ip(
        (string)($comment['ip_address'] ?? ''),
        false
    );
    if ($location !== null && $location !== '') {
        $title = sce_text('location', ['value' => $location]);
        $items[] = '<span class="comment-enhancer-meta__item" role="listitem" data-kind="location" aria-label="' . h($title) . '" title="' . h($title) . '">' . h($location) . '</span>';
    }

    if ($items === []) {
        return $existing;
    }

    return $existing
        . '<span class="comment-enhancer-meta" role="list" aria-label="' . h(sce_text('group')) . '">'
        . implode('', $items)
        . '</span>';
}

function sce_backfill_locations(?int $limit = null): int
{
    $mode = sce_lookup_mode();
    if ($mode === 'off') {
        return 0;
    }
    $maximum = $mode === 'local' ? 100 : 5;
    $limit = $limit === null ? $maximum : max(1, min($maximum, $limit));
    $now = time();
    $processed = 0;
    $scanned = 0;
    $cursor = (int)(sce_settings()['backfill_cursor'] ?? PHP_INT_MAX);
    $cursor = $cursor > 0 ? $cursor : PHP_INT_MAX;
    while ($processed < $limit && $scanned < 1000) {
        $rows = all_rows(
            "SELECT id, ip_address
             FROM comments
             WHERE status = 'approved' AND ip_address <> '' AND id < ?
             ORDER BY id DESC
             LIMIT 200",
            [$cursor]
        );
        if ($rows === []) {
            $cursor = PHP_INT_MAX;
            break;
        }
        foreach ($rows as $row) {
            $cursor = (int)$row['id'];
            $scanned++;
            $ip = trim((string)($row['ip_address'] ?? ''));
            if (sce_ip_scope($ip) !== 'public') {
                continue;
            }
            $cacheKey = sce_cache_key_for_ip($ip);
            $cached = one(
                'SELECT status, checked_at, attempted_at FROM comment_enhancer_ip_cache WHERE ip_hash = ?',
                [$cacheKey]
            );
            if (is_array($cached)) {
                $fresh = (string)($cached['status'] ?? '') === 'ok'
                    && (int)($cached['checked_at'] ?? 0) >= $now - SCE_LOCATION_CACHE_TTL;
                $recentAttempt = (int)($cached['attempted_at'] ?? 0) >= $now - SCE_LOCATION_FAILURE_TTL;
                if ($fresh || $recentAttempt) {
                    continue;
                }
            }
            sce_location_for_ip($ip, true);
            $processed++;
            if ($processed >= $limit) {
                break;
            }
            if ($scanned >= 1000) {
                break;
            }
        }
    }
    sce_save_settings(['backfill_cursor' => (string)$cursor]);
    return $processed;
}

function sce_format_file_size(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MiB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KiB';
    }
    return max(0, $bytes) . ' B';
}

function sce_database_summary(array $status): string
{
    $supportsIpv4 = ($status['supports_ipv4'] ?? false) === true;
    $supportsIpv6 = ($status['supports_ipv6'] ?? false) === true;
    $networkKey = $supportsIpv4 && $supportsIpv6
        ? 'database_both'
        : ($supportsIpv4 ? 'database_ipv4' : ($supportsIpv6 ? 'database_ipv6' : 'database_none'));
    $builtAt = (int)($status['build_epoch'] ?? 0);
    return sce_text('database_summary', [
        'type' => (string)($status['database_type'] ?? 'MMDB'),
        'built' => $builtAt > 0 ? date('Y-m-d', $builtAt) : sce_text('database_build_unknown'),
        'size' => sce_format_file_size((int)($status['size'] ?? 0)),
        'networks' => sce_text($networkKey),
    ]);
}

function sce_database_error_text(string $code): string
{
    $known = [
        'upload_server_limit', 'upload_missing', 'upload_failed', 'upload_invalid',
        'upload_extension_invalid', 'upload_size_invalid', 'data_directory_unavailable',
        'data_directory_unsafe', 'data_directory_create_failed', 'data_directory_not_writable',
        'upload_store_failed', 'upload_database_unsupported', 'upload_hash_failed',
        'database_destination_invalid', 'database_conflict', 'temporary_cleanup_failed',
        'database_install_failed', 'database_verify_failed',
        'database_lock_failed',
    ];
    return in_array($code, $known, true) ? sce_text($code) : ($code !== '' ? $code : sce_text('database_invalid'));
}

function sce_render_settings_page(): void
{
    require_admin();
    $settings = sce_settings();
    $mode = sce_lookup_mode();
    $databaseName = (string)($settings['local_geo_database'] ?? '');
    $databaseStatus = sce_local_geo_database_status($databaseName);
    $databaseValid = ($databaseStatus['valid'] ?? false) === true;
    $staleDatabaseCount = count(array_diff(
        sce_local_geo_database_files(),
        $databaseName !== '' ? [$databaseName] : []
    ));
    $backfillHint = $mode === 'local'
        ? 'backfill_hint_local'
        : ($mode === 'online' ? 'backfill_hint_online' : 'backfill_hint_off');
    $stats = one(
        "SELECT
            COALESCE(SUM(CASE WHEN status = 'ok' THEN 1 ELSE 0 END), 0) AS success_count,
            COALESCE(SUM(CASE WHEN status <> 'ok' THEN 1 ELSE 0 END), 0) AS failed_count
         FROM comment_enhancer_ip_cache"
    ) ?? [];
    $returnUrl = script_url() . '?a=admin_comment_enhancer';

    ob_start();
    ?>
    <div class="admin-shell">
      <?= render_admin_sidebar('plugins') ?>
      <div class="admin-main">
        <?= render_admin_topbar(sce_text('settings_title')) ?>
        <section class="panel admin-list-panel admin-animate admin-animate--2">
          <div class="panel__header"><h2><?= h(sce_text('source_title')) ?></h2><p class="panel__meta"><?= h(sce_text('settings_description')) ?></p></div>
          <div class="panel__body">
            <form class="form-stack" method="post" action="<?= h(script_url() . '?a=save_comment_enhancer') ?>">
              <?= csrf_field() ?>
              <div class="settings-option-list">
                <?php foreach ([
                    'off' => ['mode_off', 'mode_off_hint'],
                    'local' => ['mode_local', 'mode_local_hint'],
                    'online' => ['mode_online', 'mode_online_hint'],
                ] as $value => [$labelKey, $hintKey]): ?>
                  <label class="setting-option comment-enhancer-source-option"><input name="lookup_mode" type="radio" value="<?= h($value) ?>"<?= $mode === $value ? ' checked' : '' ?>><span><strong><?= h(sce_text($labelKey)) ?></strong><small><?= h(sce_text($hintKey)) ?></small></span></label>
                <?php endforeach; ?>
              </div>
              <div class="action-row"><button class="button" type="submit"><?= h(sce_text('save')) ?></button></div>
            </form>
          </div>
        </section>
        <section class="panel admin-list-panel admin-animate admin-animate--3">
          <div class="panel__header"><h2><?= h(sce_text('database_title')) ?></h2><span class="status-badge <?= $databaseValid ? 'status-badge--published' : 'status-badge--draft' ?>"><?= h($databaseValid ? sce_text('database_ready') : ($databaseName === '' ? sce_text('database_missing') : sce_text('database_invalid'))) ?></span></div>
          <div class="panel__body">
            <?php if ($databaseValid): ?><p class="field-hint"><?= h(sce_database_summary($databaseStatus)) ?></p><?php endif; ?>
            <form class="form-stack" method="post" action="<?= h(script_url() . '?a=comment_enhancer_database') ?>" enctype="multipart/form-data">
              <?= csrf_field() ?><input type="hidden" name="operation" value="upload">
              <div class="field"><label for="comment-enhancer-mmdb"><?= h(sce_text('database_upload_label')) ?></label><input id="comment-enhancer-mmdb" type="file" name="database" accept=".mmdb,application/octet-stream" required><p class="field-hint"><?= h(sce_text('database_upload_hint')) ?></p></div>
              <div class="action-row"><button class="button" type="submit"><?= h(sce_text('database_upload')) ?></button></div>
            </form>
            <?php if ($staleDatabaseCount > 0): ?>
              <form method="post" action="<?= h(script_url() . '?a=comment_enhancer_database') ?>">
                <?= csrf_field() ?><input type="hidden" name="operation" value="cleanup"><p class="field-hint"><?= h(sce_text('database_cleanup_pending', ['count' => $staleDatabaseCount])) ?></p><button class="button button--secondary" type="submit"><?= h(sce_text('database_cleanup')) ?></button>
              </form>
            <?php endif; ?>
            <?php if ($databaseName !== ''): ?>
              <form method="post" action="<?= h(script_url() . '?a=comment_enhancer_database') ?>" onsubmit="return confirm(<?= h(json_encode(sce_text('database_delete_confirm'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>);">
                <?= csrf_field() ?><input type="hidden" name="operation" value="delete"><button class="button button--danger" type="submit"><?= h(sce_text('database_delete')) ?></button>
              </form>
            <?php endif; ?>
          </div>
        </section>
        <section class="panel admin-list-panel admin-animate admin-animate--4">
          <div class="panel__header"><h2><?= h(sce_text('cache_title')) ?></h2><p class="panel__meta"><?= h(sce_text('cache_summary', [
              'success' => (int)($stats['success_count'] ?? 0),
              'failed' => (int)($stats['failed_count'] ?? 0),
          ])) ?></p></div>
          <div class="panel__body">
            <p class="field-hint"><?= h(sce_text($backfillHint)) ?></p>
            <div class="action-row">
              <form method="post" action="<?= h(script_url() . '?a=backfill_comment_enhancer') ?>"><?= csrf_field() ?><button class="button" type="submit"><?= h(sce_text('backfill')) ?></button></form>
              <form method="post" action="<?= h(script_url() . '?a=clear_comment_enhancer_cache') ?>"><?= csrf_field() ?><button class="button button--ghost" type="submit"><?= h(sce_text('clear_cache')) ?></button></form>
            </div>
          </div>
        </section>
      </div>
    </div>
    <?php
    render_layout(sce_text('settings_title'), (string)ob_get_clean(), [
        'active' => 'plugins',
        'wide' => true,
        'description' => sce_text('settings_description'),
    ]);
}

function sce_handle_request(array $context): void
{
    $action = (string)($context['action'] ?? '');
    $returnUrl = script_url() . '?a=admin_comment_enhancer';
    if ($action === 'admin_comment_enhancer') {
        sce_render_settings_page();
        exit;
    }
    if ($action === 'save_comment_enhancer') {
        require_admin_post($returnUrl);
        try {
            $mode = (string)($_POST['lookup_mode'] ?? 'off');
            $saveMode = static function () use ($mode): void {
                unset($GLOBALS['sce_settings_cache']);
                $database = (string)(sce_settings()['local_geo_database'] ?? '');
                if ($mode === 'local' && (sce_local_geo_database_status($database)['valid'] ?? false) !== true) {
                    throw new DomainException(sce_text('local_required'));
                }
                sce_change_lookup_context($mode, $database);
            };
            if ($mode === 'local') {
                sce_with_local_geo_lock($saveMode);
            } else {
                sce_with_optional_local_geo_lock($saveMode);
            }
            set_flash('success', sce_text('saved'));
        } catch (DomainException $exception) {
            set_flash('error', sce_database_error_text($exception->getMessage()));
        } catch (Throwable $exception) {
            error_log('Comment enhancer settings save failed: ' . $exception->getMessage());
            set_flash('error', sce_text('database_invalid'));
        }
        redirect_to($returnUrl, 303);
    }
    if ($action === 'comment_enhancer_database') {
        require_admin_post($returnUrl);
        try {
            $operation = (string)($_POST['operation'] ?? '');
            sce_with_local_geo_lock(static function () use ($operation): void {
                unset($GLOBALS['sce_settings_cache']);
                $settings = sce_settings();
                $oldDatabase = (string)($settings['local_geo_database'] ?? '');
                if ($operation === 'upload') {
                    $result = sce_install_local_geo_database(
                        is_array($_FILES['database'] ?? null) ? $_FILES['database'] : [],
                        $oldDatabase
                    );
                    $newDatabase = (string)($result['basename'] ?? '');
                    try {
                        sce_change_lookup_context(sce_lookup_mode(), $newDatabase);
                    } catch (Throwable $exception) {
                        if ($newDatabase !== '' && $newDatabase !== $oldDatabase) {
                            sce_delete_local_geo_database($newDatabase);
                        }
                        throw $exception;
                    }
                    $cleanupFailed = false;
                    if ($oldDatabase !== '' && $oldDatabase !== $newDatabase) {
                        $oldPath = sce_local_geo_database_path($oldDatabase);
                        $cleanupFailed = $oldPath !== ''
                            && (file_exists($oldPath) || is_link($oldPath))
                            && !sce_delete_local_geo_database($oldDatabase);
                    }
                    set_flash(
                        $cleanupFailed ? 'error' : 'success',
                        sce_text($cleanupFailed ? 'database_replaced_cleanup_failed' : 'database_uploaded')
                    );
                } elseif ($operation === 'delete') {
                    $oldPath = sce_local_geo_database_path($oldDatabase);
                    if (sce_lookup_mode() === 'local') {
                        sce_change_lookup_context('off', $oldDatabase);
                    }
                    $removed = $oldPath === '' || !is_file($oldPath) || sce_delete_local_geo_database($oldDatabase);
                    if ($removed) {
                        sce_change_lookup_context(sce_lookup_mode(), '');
                    }
                    set_flash($removed ? 'success' : 'error', sce_text($removed ? 'database_deleted' : 'database_delete_failed'));
                } elseif ($operation === 'cleanup') {
                    $result = sce_cleanup_local_geo_databases($oldDatabase);
                    $failed = is_array($result['failed'] ?? null) ? $result['failed'] : [];
                    $removed = (int)($result['removed'] ?? 0);
                    set_flash(
                        $failed === [] ? 'success' : 'error',
                        $failed === []
                            ? sce_text('database_cleanup_done', ['count' => $removed])
                            : sce_text('database_cleanup_failed')
                    );
                } else {
                    throw new DomainException(sce_text('database_invalid'));
                }
            });
        } catch (DomainException $exception) {
            set_flash('error', sce_database_error_text($exception->getMessage()));
        } catch (Throwable $exception) {
            error_log('Comment enhancer database operation failed: ' . $exception->getMessage());
            set_flash('error', sce_text('database_invalid'));
        }
        redirect_to($returnUrl, 303);
    }
    if ($action === 'backfill_comment_enhancer') {
        require_admin_post($returnUrl);
        $mode = sce_lookup_mode();
        $localReady = $mode !== 'local'
            || (sce_local_geo_database_status((string)(sce_settings()['local_geo_database'] ?? ''))['valid'] ?? false) === true;
        if ($mode === 'off' || !$localReady) {
            set_flash('error', sce_text('lookup_required'));
        } else {
            $processed = sce_backfill_locations();
            set_flash('success', sce_text('backfill_done', ['count' => $processed]));
        }
        redirect_to($returnUrl, 303);
    }
    if ($action === 'clear_comment_enhancer_cache') {
        require_admin_post($returnUrl);
        sce_clear_location_cache();
        set_flash('success', sce_text('cache_cleared'));
        redirect_to($returnUrl, 303);
    }
}

function sce_stylesheet_link(array $context): string
{
    $file = __DIR__ . '/assets/comment-enhancer.css';
    $url = plugin_asset_url('comment-enhancer', 'assets/comment-enhancer.css');
    if ($url === '' || !is_file($file)) {
        return '';
    }

    return '<link rel="stylesheet" href="' . h($url . '?v=' . rawurlencode((string)filemtime($file))) . '">';
}

function sce_script_tag(array $context): string
{
    $file = __DIR__ . '/assets/comment-enhancer.js';
    $url = plugin_asset_url('comment-enhancer', 'assets/comment-enhancer.js');
    if ($url === '' || !is_file($file)) {
        return '';
    }

    return '<script src="' . h($url . '?v=' . rawurlencode((string)filemtime($file))) . '"></script>';
}

function sce_admin_stylesheet(mixed $html, array $context): string
{
    if (!is_string($html) || (string)($context['action'] ?? '') !== 'admin_comment_enhancer') {
        return is_string($html) ? $html : '';
    }
    $headEnd = stripos($html, '</head>');
    if ($headEnd === false) {
        return $html;
    }
    $link = sce_stylesheet_link($context);
    return $link !== '' ? substr_replace($html, $link, $headEnd, 0) : $html;
}

add_plugin_action('plugins_loaded', 'sce_install');
add_plugin_action('request', 'sce_handle_request');
add_plugin_action('comment_created', 'sce_cache_new_comment_location', 20);
add_plugin_action('comment_status_changed', 'sce_handle_comment_status_changed', 20);
add_plugin_action('plugin_status_changed', 'sce_handle_plugin_status_changed', 20);
add_plugin_filter('comment_identity_html', 'sce_render_comment_identity', 10);
add_plugin_filter('comment_meta_html', 'sce_render_comment_meta', 10);
add_plugin_filter('output_html', 'sce_admin_stylesheet', 20);
add_theme_action('head', 'sce_stylesheet_link', 30);
add_theme_action('body_close', 'sce_script_tag', 30);
