<?php
declare(strict_types=1);

class SnsLoginException extends RuntimeException {}

function sns_install(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS sblog_sns_settings (
        name TEXT PRIMARY KEY, value TEXT NOT NULL
    )");
    db()->exec("CREATE TABLE IF NOT EXISTS sblog_sns_bindings (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        provider TEXT NOT NULL,
        client_id TEXT NOT NULL,
        subject TEXT NOT NULL,
        name TEXT NOT NULL DEFAULT '',
        created_at INTEGER NOT NULL,
        last_used_at INTEGER NOT NULL DEFAULT 0,
        UNIQUE(provider, client_id, subject),
        UNIQUE(user_id, provider, client_id),
        FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
    )");
}

function sns_setting(string $name, string $default = ''): string
{
    $row = one('SELECT value FROM sblog_sns_settings WHERE name = ?', [$name]);
    return $row === null ? $default : (string)$row['value'];
}

function sns_config(): array
{
    $stored = json_decode(sns_setting('config', '{}'), true);
    $stored = is_array($stored) ? $stored : [];
    $config = [];
    foreach (sns_providers() as $slug => $provider) {
        $item = is_array($stored[$slug] ?? null) ? $stored[$slug] : [];
        $config[$slug] = [
            'enabled' => ($item['enabled'] ?? false) === true,
            'client_id' => is_string($item['client_id'] ?? null) ? $item['client_id'] : '',
            'client_secret' => is_string($item['client_secret'] ?? null) ? $item['client_secret'] : '',
        ];
    }
    return $config;
}

function sns_provider_config(string $provider): array
{
    $config = sns_config()[$provider] ?? null;
    if ($config === null) {
        throw new SnsLoginException(sblog_t('不支持的第三方登录平台。'));
    }
    return $config;
}

function sns_save_config(array $config): void
{
    $validated = [];
    foreach (sns_providers() as $slug => $provider) {
        $item = $config[$slug] ?? null;
        if (!is_array($item) || !is_bool($item['enabled'] ?? null)) {
            throw new SnsLoginException(sblog_t('第三方登录设置格式无效。'));
        }
        foreach (['client_id', 'client_secret'] as $key) {
            if (!is_string($item[$key] ?? null) || strlen($item[$key]) > 2048
                || preg_match('/[\x00-\x20\x7F]/', $item[$key])) {
                throw new SnsLoginException(sblog_t('应用凭据格式无效，不能包含空白或控制字符。'));
            }
        }
        if ($item['enabled'] && ($item['client_id'] === '' || $item['client_secret'] === '')) {
            throw new SnsLoginException(sblog_t('启用平台前，请填写 Client ID 和 Client Secret。'));
        }
        $validated[$slug] = [
            'enabled' => $item['enabled'], 'client_id' => $item['client_id'],
            'client_secret' => $item['client_secret'],
        ];
    }
    // Keep secrets out of the core settings cache and any settings exports.
    q('INSERT OR REPLACE INTO sblog_sns_settings(name, value) VALUES(?, ?)', [
        'config', json_encode($validated, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
    unset($_SESSION['sns_login_pending']);
}

function sns_url(string $action, array $params = []): string
{
    return script_url() . '?' . http_build_query(['a' => $action] + $params, '', '&', PHP_QUERY_RFC3986);
}

function sns_callback_url(string $provider): string
{
    $url = rtrim(trim(setting('site_url', '')), '/');
    $parts = parse_url($url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts)
        || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
        || preg_match('/[\x00-\x20\x7F\\\\]/', $url)
        || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f|2f|5c)/i', $url)) {
        throw new SnsLoginException(sblog_t('请先在站点设置中填写准确的 HTTPS 站点地址（含安装子目录）。'));
    }
    $expectedPath = rtrim(str_replace('\\', '/', dirname(script_url())), '/');
    if ($expectedPath === '.') {
        $expectedPath = '';
    }
    if (rtrim((string)($parts['path'] ?? ''), '/') !== $expectedPath) {
        throw new SnsLoginException(sblog_t('站点地址的路径必须与 SBlog 的安装子目录一致。'));
    }
    return $url . '/index.php?' . http_build_query([
        'a' => 'sns_login_callback', 'provider' => $provider,
    ], '', '&', PHP_QUERY_RFC3986);
}

function sns_config_fingerprint(string $provider, array $config): string
{
    $descriptor = sns_providers()[$provider] ?? [];
    $protocol = array_intersect_key($descriptor, array_flip([
        'authorize_url', 'token_url', 'profile_url', 'scope', 'pkce', 'token_auth',
    ]));
    return hash('sha256', json_encode([$provider, $config, $protocol, sns_callback_url($provider)], JSON_THROW_ON_ERROR));
}

function sns_pending_cleanup(): void
{
    $pending = $_SESSION['sns_login_pending'] ?? [];
    $pending = is_array($pending) ? $pending : [];
    foreach ($pending as $key => $entry) {
        if (!is_array($entry) || (int)($entry['expires_at'] ?? 0) < time()) {
            unset($pending[$key]);
        }
    }
    if ($pending === []) {
        unset($_SESSION['sns_login_pending']);
    } else {
        $_SESSION['sns_login_pending'] = $pending;
    }
}

function sns_pending_create(string $provider, string $purpose, array $config, int $adminId = 0): array
{
    if (!in_array($purpose, ['login', 'bind'], true) || ($purpose === 'bind' && $adminId < 1)
        || !($config['enabled'] ?? false) || ($config['client_id'] ?? '') === '' || ($config['client_secret'] ?? '') === '') {
        throw new SnsLoginException(sblog_t('该平台尚未启用，或授权用途无效。'));
    }
    sns_pending_cleanup();
    $pending = $_SESSION['sns_login_pending'] ?? [];
    while (count($pending) >= SBLOG_SNS_MAX_PENDING) {
        array_shift($pending);
    }
    $state = bin2hex(random_bytes(32));
    $entry = [
        'provider' => $provider, 'purpose' => $purpose, 'admin_id' => $adminId,
        'expires_at' => time() + SBLOG_SNS_STATE_TTL,
        'verifier' => rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '='),
        'redirect_uri' => sns_callback_url($provider),
        'config_hash' => sns_config_fingerprint($provider, $config),
    ];
    if ($purpose === 'bind') {
        $admin = one('SELECT password_hash FROM users WHERE id = ?', [$adminId]);
        if ($admin === null) {
            throw new SnsLoginException(sblog_t('请重新登录本地账号后绑定。'));
        }
        $entry['admin_password_fingerprint'] = hash('sha256', (string)$admin['password_hash']);
    }
    $pending[hash('sha256', $state)] = $entry;
    $_SESSION['sns_login_pending'] = $pending;
    return ['state' => $state] + $entry;
}

function sns_pending_take(string $provider, string $state): array
{
    if (preg_match('/^[a-f0-9]{64}$/D', $state) !== 1) {
        throw new SnsLoginException(sblog_t('授权请求无效或已过期，请重新发起登录。'));
    }
    $key = hash('sha256', $state);
    $entry = $_SESSION['sns_login_pending'][$key] ?? null;
    unset($_SESSION['sns_login_pending'][$key]);
    sns_pending_cleanup();
    if (!is_array($entry) || ($entry['provider'] ?? '') !== $provider
        || (int)($entry['expires_at'] ?? 0) < time()
        || !hash_equals((string)($entry['config_hash'] ?? ''), sns_config_fingerprint($provider, sns_provider_config($provider)))) {
        throw new SnsLoginException(sblog_t('授权请求无效或已过期，请重新发起登录。'));
    }
    return $entry;
}

function sns_subject(array $identity): string
{
    $subject = $identity['subject'] ?? null;
    if (!is_string($subject) || $subject === '' || strlen($subject) > 256 || preg_match('/[\x00-\x20\x7F]/', $subject)) {
        throw new SnsLoginException(sblog_t('第三方平台没有返回有效的账号标识。'));
    }
    return $subject;
}

function sns_bindings(int $userId): array
{
    return all_rows('SELECT * FROM sblog_sns_bindings WHERE user_id = ? ORDER BY provider, id', [$userId]);
}

function sns_find_binding(string $provider, array $config, string $subject): ?array
{
    return one('SELECT b.*, u.password_hash FROM sblog_sns_bindings b JOIN users u ON u.id = b.user_id
        WHERE b.provider = ? AND b.client_id = ? AND b.subject = ?', [$provider, $config['client_id'], $subject]);
}

function sns_bind_identity(string $provider, array $config, array $identity, int $userId): void
{
    $subject = sns_subject($identity);
    if ($userId < 1 || one('SELECT id FROM users WHERE id = ?', [$userId]) === null) {
        throw new SnsLoginException(sblog_t('请重新登录本地账号后绑定。'));
    }
    $name = is_string($identity['name'] ?? null) ? str_sub_u($identity['name'], 0, 120) : '';
    $name = (string)preg_replace('/[\x00-\x1F\x7F]/', '', $name);
    // Database uniqueness also prevents two concurrent callbacks claiming the same identity.
    try {
        q('INSERT INTO sblog_sns_bindings(user_id, provider, client_id, subject, name, created_at, last_used_at)
            VALUES(?,?,?,?,?,?,?) ON CONFLICT(provider, client_id, subject) DO UPDATE SET name = excluded.name
            WHERE sblog_sns_bindings.user_id = excluded.user_id', [
            $userId, $provider, $config['client_id'], $subject, $name, time(), 0,
        ]);
    } catch (PDOException $exception) {
        throw new SnsLoginException(sblog_t('此平台已有绑定，请先解绑后再绑定其他账号。'));
    }
    $binding = sns_find_binding($provider, $config, $subject);
    if ($binding === null || (int)$binding['user_id'] !== $userId) {
        throw new SnsLoginException(sblog_t('该第三方账号已绑定其他本地账号。'));
    }
}

function sns_unlink(int $bindingId, int $userId): void
{
    q('DELETE FROM sblog_sns_bindings WHERE id = ? AND user_id = ?', [$bindingId, $userId]);
    if ((int)($_SESSION['sns_login_binding_id'] ?? 0) === $bindingId) {
        unset($_SESSION['sns_login_binding_id'], $_SESSION['sns_login_config_hash']);
        clear_admin_authentication();
    }
}

function sns_verify_password(string $password): void
{
    if (function_exists('login_rate_state') && (int)(login_rate_state()['count'] ?? 0) >= 5) {
        throw new SnsLoginException(sblog_t('登录尝试过多，请 15 分钟后再试。'));
    }
    $admin = current_admin();
    $row = $admin === null ? null : one('SELECT password_hash FROM users WHERE id = ?', [(int)$admin['id']]);
    if ($row === null || strlen($password) > 4096 || !password_verify($password, (string)$row['password_hash'])) {
        if (function_exists('login_rate_state')) {
            login_rate_state(true);
        }
        throw new SnsLoginException(sblog_t('当前密码不正确。'));
    }
}

function sns_bootstrap(): void
{
    try {
        sns_install();
        sns_pending_cleanup();
        // Run before current_admin() memoizes the identity for this request.
        if (!isset($_SESSION['sns_login_binding_id'])) {
            return;
        }
        $binding = one('SELECT * FROM sblog_sns_bindings WHERE id = ? AND user_id = ?', [
            (int)$_SESSION['sns_login_binding_id'], (int)($_SESSION['admin_id'] ?? 0),
        ]);
        $config = $binding === null ? [] : sns_provider_config((string)$binding['provider']);
        $valid = $binding !== null && !empty($config['enabled'])
            && hash_equals((string)($_SESSION['sns_login_config_hash'] ?? ''), sns_config_fingerprint((string)$binding['provider'], $config));
    } catch (Throwable $exception) {
        if (!isset($_SESSION['sns_login_binding_id'])) {
            throw $exception;
        }
        $valid = false;
    }
    if (!$valid) {
        unset($_SESSION['sns_login_binding_id'], $_SESSION['sns_login_config_hash']);
        clear_admin_authentication();
    }
}
