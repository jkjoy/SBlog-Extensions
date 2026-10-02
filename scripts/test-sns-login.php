<?php

declare(strict_types=1);

// OAuth fixtures exercise the plugin and real SQLite constraints without
// credentials, network requests, or a provider account.
ini_set('session.use_cookies', '0');
session_start();
$GLOBALS['sns_test_db'] = new PDO('sqlite::memory:');
$GLOBALS['sns_test_db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['sns_test_db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['sns_test_settings'] = ['site_url' => 'https://blog.example.test/blog'];
$GLOBALS['sns_test_hooks'] = ['actions' => [], 'filters' => []];
$GLOBALS['sns_test_assertions'] = 0;
$_SESSION = [];
$_SERVER['REQUEST_METHOD'] = 'GET';

function db(): PDO
{
    if (!empty($GLOBALS['sns_test_database_failure'])) throw new RuntimeException('Simulated database failure.');
    return $GLOBALS['sns_test_db'];
}
function q(string $sql, array $parameters = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}
function one(string $sql, array $parameters = []): ?array
{
    $row = q($sql, $parameters)->fetch();
    return is_array($row) ? $row : null;
}
function all_rows(string $sql, array $parameters = []): array { return q($sql, $parameters)->fetchAll(); }
function val(string $sql, array $parameters = []): mixed { return q($sql, $parameters)->fetchColumn(); }
function setting(string $name, string $default = ''): string { return (string)($GLOBALS['sns_test_settings'][$name] ?? $default); }
function save_settings(array $values): void { $GLOBALS['sns_test_settings'] = array_replace($GLOBALS['sns_test_settings'], $values); }
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function sblog_t(string $value, array $parameters = []): string
{
    foreach ($parameters as $name => $parameter) $value = str_replace('{' . $name . '}', (string)$parameter, $value);
    return $value;
}
function sblog_i18n_register(string $locale, array $catalog): void {}
function sblog_i18n_register_client(string $locale, array $catalog): void {}
function sblog_i18n_locale(): string { return 'zh-CN'; }
function str_len_u(string $value): int { return mb_strlen($value, 'UTF-8'); }
function str_sub_u(string $value, int $start, int $length): string { return mb_substr($value, $start, $length, 'UTF-8'); }
function script_url(): string { return '/blog/index.php'; }
function app_base_path(): string { return '/blog'; }
function url_for(string $action, array $parameters = []): string
{
    return script_url() . '?' . http_build_query(['a' => $action] + $parameters);
}
function plugin_asset_url(string $slug, string $path): string { return '/blog/plugins/' . $slug . '/' . $path; }
function add_plugin_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['sns_test_hooks']['actions'][$hook][$priority][] = $callback;
}
function add_plugin_filter(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['sns_test_hooks']['filters'][$hook][$priority][] = $callback;
}
function plugin_action(string $hook, array $context = []): void
{
    $groups = $GLOBALS['sns_test_hooks']['actions'][$hook] ?? [];
    ksort($groups, SORT_NUMERIC);
    foreach ($groups as $callbacks) foreach ($callbacks as $callback) $callback($context);
}
function plugin_filter(string $hook, mixed $value, array $context = []): mixed
{
    $groups = $GLOBALS['sns_test_hooks']['filters'][$hook] ?? [];
    ksort($groups, SORT_NUMERIC);
    foreach ($groups as $callbacks) foreach ($callbacks as $callback) $value = $callback($value, $context);
    return $value;
}
function current_admin(): ?array
{
    $id = (int)($_SESSION['admin_id'] ?? 0);
    return $id > 0 ? one('SELECT * FROM users WHERE id = ?', [$id]) : null;
}
function is_admin(): bool { return current_admin() !== null; }
function login_rate_state(bool $failed = false, bool $reset = false): array
{
    if ($reset) $GLOBALS['sns_test_login_failures'] = 0;
    if ($failed) $GLOBALS['sns_test_login_failures'] = ($GLOBALS['sns_test_login_failures'] ?? 0) + 1;
    return ['count' => $GLOBALS['sns_test_login_failures'] ?? 0];
}
function update_admin_presence(?int $userId): void { $GLOBALS['sns_test_presence'] = $userId; }
function clear_admin_authentication(): void
{
    unset($_SESSION['admin_id'], $_SESSION['admin_authenticated_at'], $_SESSION['admin_last_seen_at'], $_SESSION['admin_password_fingerprint']);
    update_admin_presence(null);
    session_regenerate_id(true);
}
class SnsTestResponse extends RuntimeException
{
    public function __construct(public string $kind, public string $target = '', public int $status = 302)
    {
        parent::__construct($kind . ':' . $target);
    }
}
function require_admin(): void { if (!is_admin()) throw new SnsTestResponse('auth', '', 403); }
function require_admin_post(string $fallback): void
{
    require_admin();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new SnsTestResponse('method', $fallback, 405);
    verify_csrf();
}
function csrf_token(): string { return 'sns-test-csrf'; }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">'; }
function verify_csrf(): void
{
    if (($_POST['csrf_token'] ?? '') !== csrf_token()) throw new SnsTestResponse('csrf', '', 403);
}
function set_flash(string $type, string $message): void { $GLOBALS['sns_test_flash'] = [$type, $message]; }
function redirect_to(string $url, int $status = 302): never { throw new SnsTestResponse('redirect', $url, $status); }
function simple_error_page(string $title, string $message, int $status = 400): never
{
    throw new SnsTestResponse('error', $message, $status);
}
function render_admin_sidebar(string $active, array $summary = []): string { return '<aside></aside>'; }
function render_admin_topbar(string $title, string $label = '', string $url = ''): string { return '<header>' . h($title) . '</header>'; }
function render_layout(string $title, string $content, array $options = []): never
{
    $GLOBALS['sns_test_rendered'] = ['title' => $title, 'content' => $content, 'options' => $options];
    throw new SnsTestResponse('render', $title, 200);
}
function admin_icon(string $name): string { return '<span>' . h($name) . '</span>'; }

function sns_test_assert(bool $condition, string $message): void
{
    $GLOBALS['sns_test_assertions']++;
    if (!$condition) throw new RuntimeException($message);
}
function sns_test_same(mixed $expected, mixed $actual, string $message): void
{
    sns_test_assert($expected === $actual, $message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function sns_test_reject(callable $callback, string $message): void
{
    $exception = null;
    try { $callback(); } catch (Throwable $caught) { $exception = $caught; }
    sns_test_assert($exception !== null, $message);
}
function sns_test_response(callable $callback): SnsTestResponse
{
    try { $callback(); } catch (SnsTestResponse $response) { return $response; }
    throw new RuntimeException('Expected a terminating response.');
}
function sns_test_admin(int $userId): void
{
    $user = one('SELECT * FROM users WHERE id = ?', [$userId]);
    if ($user === null) throw new RuntimeException('Unknown test user.');
    $_SESSION['admin_id'] = $userId;
    $_SESSION['admin_authenticated_at'] = time();
    $_SESSION['admin_last_seen_at'] = time();
    $_SESSION['admin_password_fingerprint'] = hash('sha256', $user['password_hash']);
}

db()->exec('CREATE TABLE users(id INTEGER PRIMARY KEY, username TEXT NOT NULL, password_hash TEXT NOT NULL, nickname TEXT NOT NULL DEFAULT "", email TEXT NOT NULL DEFAULT "", website_url TEXT NOT NULL DEFAULT "", created_at INTEGER NOT NULL DEFAULT 0)');
$snsPassword = 'existing-local-account-password';
q('INSERT INTO users(id,username,password_hash,nickname,email,created_at) VALUES(?,?,?,?,?,?)', [1, 'owner', password_hash($snsPassword, PASSWORD_DEFAULT), 'Owner', 'owner@example.com', time()]);
q('INSERT INTO users(id,username,password_hash,nickname,created_at) VALUES(?,?,?,?,?)', [2, 'second', password_hash('second-account-password', PASSWORD_DEFAULT), 'Second', time()]);

require dirname(__DIR__) . '/plugins/sns-login/plugin.php';

$snsFixture = [
    'name' => 'Fixture Provider',
    'authorize_url' => 'https://identity.example.com/authorize',
    'token_url' => 'https://identity.example.com/token',
    'profile_url' => 'https://identity.example.com/userinfo',
    'scope' => 'profile', 'pkce' => true, 'token_auth' => 'post',
    'adapter' => static function (array $provider, array $config, string $redirectUri, string $code, string $verifier): array {
        $GLOBALS['sns_test_adapter_calls'][] = compact('config', 'redirectUri', 'code', 'verifier');
        if ($code === 'private-error') throw new RuntimeException('provider-token-secret-do-not-display');
        return $GLOBALS['sns_test_identity'];
    },
];
add_plugin_filter('sns_login_providers', static function (array $providers) use ($snsFixture): array {
    $providers['fixture'] = $snsFixture;
    return $providers;
});

try {
    sns_install();
    sns_install();
    $snsConfig = sns_config();
    foreach (['github', 'google', 'linuxdo', 'qq', 'fixture'] as $slug) {
        sns_test_assert(isset($snsConfig[$slug]), 'Provider missing from default configuration: ' . $slug);
        sns_test_same(false, $snsConfig[$slug]['enabled'], 'Providers should start disabled.');
    }
    $snsConfig['fixture'] = ['enabled' => true, 'client_id' => 'fixture-app', 'client_secret' => 'fixture-client-secret'];
    sns_save_config($snsConfig);
    sns_test_same($snsConfig, sns_config(), 'Saving configuration did not round-trip.');
    sns_test_assert(!str_contains(json_encode($GLOBALS['sns_test_settings']), 'fixture-client-secret'), 'A client secret leaked into core settings.');

    $_SERVER['HTTP_HOST'] = 'attacker.example';
    $_SERVER['HTTPS'] = 'off';
    sns_test_same('https://blog.example.test/blog/index.php?a=sns_login_callback&provider=github', sns_callback_url('github'), 'Callback URL trusted a request origin or lost the installation path.');
    foreach (['http://blog.example.test/blog', 'https://blog.example.test', 'https://blog.example.test/blog?redirect=evil', 'https://user:secret@blog.example.test/blog', 'https://blog.example.test/blog#fragment', 'https://blog.example.test/blog%2f', 'https://blog.example.test/blog\\x'] as $invalidOrigin) {
        $GLOBALS['sns_test_settings']['site_url'] = $invalidOrigin;
        sns_test_reject(static fn() => sns_callback_url('github'), 'Unsafe or mismatched callback origin was accepted: ' . $invalidOrigin);
    }
    $GLOBALS['sns_test_settings']['site_url'] = 'https://blog.example.test/blog';

    foreach ([
        ['enabled', '1'], ['client_id', ['array']], ['client_secret', ['array']],
        ['client_id', "line\nbreak"], ['client_secret', 'secret with spaces'],
        ['client_secret', str_repeat('a', 2049)], ['client_id', ''], ['client_secret', ''],
    ] as [$field, $invalidValue]) {
        $invalidConfig = $snsConfig;
        $invalidConfig['fixture'][$field] = $invalidValue;
        sns_test_reject(static fn() => sns_save_config($invalidConfig), 'Invalid provider configuration was accepted: ' . $field);
        sns_test_same($snsConfig, sns_config(), 'Rejected configuration partially replaced valid settings.');
    }
    $partial = $snsConfig;
    unset($partial['github']);
    sns_test_reject(static fn() => sns_save_config($partial), 'An incomplete configuration was accepted.');

    $post = ['config_complete' => '1', 'providers' => []];
    foreach ($snsConfig as $slug => $item) {
        $post['providers'][$slug] = ['client_id' => $item['client_id'], 'client_secret' => ''];
        if ($item['enabled']) $post['providers'][$slug]['enabled'] = '1';
    }
    sns_test_same($snsConfig, sns_admin_post_config($post), 'Blank password inputs erased previously saved secrets.');
    $clearPost = $post;
    unset($clearPost['providers']['fixture']['enabled']);
    $clearPost['providers']['fixture']['clear_secret'] = '1';
    sns_test_same('', sns_admin_post_config($clearPost)['fixture']['client_secret'], 'Explicit clearing did not remove a secret.');
    foreach (['providers', 'config_complete'] as $missing) {
        $invalidPost = $post;
        unset($invalidPost[$missing]);
        sns_test_reject(static fn() => sns_admin_post_config($invalidPost), 'An incomplete settings form was accepted.');
    }
    foreach (['client_id', 'client_secret', 'enabled', 'clear_secret'] as $field) {
        $invalidPost = $post;
        $invalidPost['providers']['fixture'][$field] = ['nested'];
        sns_test_reject(static fn() => sns_admin_post_config($invalidPost), 'A nested settings value was accepted: ' . $field);
    }

    $config = $snsConfig['fixture'];
    $pending = sns_pending_create('fixture', 'login', $config);
    sns_test_assert(preg_match('/^[a-f0-9]{64}$/D', $pending['state']) === 1, 'OAuth state has insufficient random bytes.');
    sns_test_assert(strlen($pending['verifier']) >= 43 && strlen($pending['verifier']) <= 128, 'PKCE verifier is outside the OAuth length limits.');
    sns_test_assert(!str_contains(json_encode($_SESSION['sns_login_pending']), $pending['state']), 'Session pending keys retained the raw state.');
    sns_test_assert(in_array($pending['expires_at'] - time(), [599, 600], true), 'Authorization lifetime is not ten minutes.');
    sns_test_same('login', sns_pending_take('fixture', $pending['state'])['purpose'], 'A valid pending authorization was rejected.');
    sns_test_reject(static fn() => sns_pending_take('fixture', $pending['state']), 'A replayed authorization state was accepted.');
    foreach (['', 'not-a-state', str_repeat('a', 63), str_repeat('A', 64), str_repeat('a', 65), str_repeat('b', 64)] as $state) {
        sns_test_reject(static fn() => sns_pending_take('fixture', $state), 'Malformed or unknown authorization state was accepted.');
    }
    $wrongProvider = sns_pending_create('fixture', 'login', $config);
    sns_test_reject(static fn() => sns_pending_take('github', $wrongProvider['state']), 'An authorization state was accepted for a different provider.');
    sns_test_reject(static fn() => sns_pending_take('fixture', $wrongProvider['state']), 'A state survived an attempted callback with the wrong provider.');
    $expired = sns_pending_create('fixture', 'login', $config);
    $_SESSION['sns_login_pending'][hash('sha256', $expired['state'])]['expires_at'] = time() - 1;
    sns_test_reject(static fn() => sns_pending_take('fixture', $expired['state']), 'Expired state was accepted.');
    $changed = sns_pending_create('fixture', 'login', $config);
    $changedConfig = $snsConfig;
    $changedConfig['fixture']['client_secret'] = 'rotated-fixture-secret';
    q('UPDATE sblog_sns_settings SET value = ? WHERE name = ?', [json_encode($changedConfig), 'config']);
    sns_test_reject(static fn() => sns_pending_take('fixture', $changed['state']), 'A state created before credential rotation was accepted.');
    sns_save_config($snsConfig);
    $firstPending = null;
    for ($index = 0; $index < 9; $index++) {
        $nextPending = sns_pending_create('fixture', 'login', $config);
        $firstPending ??= $nextPending;
    }
    sns_test_same(8, count($_SESSION['sns_login_pending']), 'Outstanding authorization states were not bounded.');
    sns_test_reject(static fn() => sns_pending_take('fixture', $firstPending['state']), 'The oldest excess authorization state was not evicted.');
    sns_test_reject(static fn() => sns_pending_create('fixture', 'bind', $config), 'An anonymous bind intent was accepted.');
    sns_test_reject(static fn() => sns_pending_create('fixture', 'other', $config), 'An unsupported authorization purpose was accepted.');
    unset($_SESSION['sns_login_pending']);

    $identity = ['subject' => 'stable-fixture-subject', 'name' => '<script>alert("binding-name")</script>'];
    sns_test_same(null, sns_find_binding('fixture', $config, $identity['subject']), 'An unbound identity unexpectedly matched a local account.');
    sns_bind_identity('fixture', $config, $identity, 1);
    $binding = sns_find_binding('fixture', $config, $identity['subject']);
    sns_test_assert($binding !== null && (int)$binding['user_id'] === 1, 'Binding did not belong to the intended local account.');
    $bindingId = (int)$binding['id'];
    sns_bind_identity('fixture', $config, ['subject' => $identity['subject'], 'name' => $identity['name']], 1);
    sns_test_same(1, (int)val('SELECT COUNT(*) FROM sblog_sns_bindings'), 'Retrying a same-account bind created duplicate identities.');
    sns_test_reject(static fn() => sns_bind_identity('fixture', $config, $identity, 2), 'A provider identity was transferred to another local account.');
    sns_test_same(1, (int)sns_find_binding('fixture', $config, $identity['subject'])['user_id'], 'A conflict changed binding ownership.');
    sns_test_reject(static fn() => sns_bind_identity('fixture', $config, ['subject' => 'different-subject', 'name' => 'Other'], 1), 'One local user bound two identities to the same provider application.');
    sns_test_reject(static fn() => sns_bind_identity('fixture', $config, ['subject' => 'new-subject'], 999), 'Binding created a nonexistent local account.');
    foreach (['', 'invalid subject', ['nested'], str_repeat('x', 257)] as $invalidSubject) {
        sns_test_reject(static fn() => sns_bind_identity('fixture', $config, ['subject' => $invalidSubject], 2), 'A malformed stable subject was accepted.');
    }
    $otherApp = array_replace($config, ['client_id' => 'another-application']);
    sns_test_same(null, sns_find_binding('fixture', $otherApp, $identity['subject']), 'A binding crossed the OAuth application boundary.');
    sns_test_same(null, sns_find_binding('github', $config, $identity['subject']), 'A binding crossed the provider boundary.');
    sns_unlink($bindingId, 2);
    sns_test_assert(sns_find_binding('fixture', $config, $identity['subject']) !== null, 'Another administrator unlinked a binding they do not own.');
    sns_test_admin(1);
    sns_verify_password($snsPassword);
    sns_test_reject(static fn() => sns_verify_password('wrong-password'), 'Binding verification accepted the wrong local password.');
    sns_test_same(1, login_rate_state()['count'], 'A wrong binding password did not count toward the core login limit.');
    $GLOBALS['sns_test_login_failures'] = 5;
    sns_test_reject(static fn() => sns_verify_password($snsPassword), 'Binding password verification bypassed the core login rate limit.');
    $GLOBALS['sns_test_login_failures'] = 0;

    $rendered = sns_test_response(static fn() => sns_render_admin());
    sns_test_same('render', $rendered->kind, 'Settings page did not render for an administrator.');
    $html = $GLOBALS['sns_test_rendered']['content'];
    sns_test_assert(!str_contains($html, 'fixture-client-secret'), 'The settings page exposed a saved OAuth secret.');
    sns_test_assert(!str_contains($html, '<script>alert("binding-name")</script>') && str_contains($html, '&lt;script&gt;'), 'A provider display name executed HTML on the settings page.');
    sns_test_assert(str_contains($html, 'https://blog.example.test/blog/index.php?a=sns_login_callback'), 'Settings page did not include canonical callback addresses.');

    $providers = sns_providers();
    $github = sns_provider_identity($providers['github'], ['id' => 123, 'login' => 'old-handle', 'email' => 'owner@example.com'], ['emails' => [
        ['email' => 'unverified@example.com', 'verified' => false, 'primary' => true],
        ['email' => 'verified@example.com', 'verified' => true, 'primary' => true],
    ]]);
    sns_test_same('123', $github['subject'], 'GitHub used a mutable handle as account identity.');
    sns_test_same($github['subject'], sns_provider_identity($providers['github'], ['id' => 123, 'login' => 'new-handle'])['subject'], 'Changing a GitHub handle changed the stable account identity.');
    sns_test_same('verified@example.com', $github['email'], 'GitHub did not prefer a verified email.');
    sns_test_same(true, $github['email_verified'], 'GitHub omitted verified email status.');
    sns_test_reject(static fn() => sns_provider_identity($providers['github'], ['login' => 'handle-only']), 'GitHub accepted a handle without a stable ID.');
    foreach ([0, -1, 1.5, '01', '1e2', ['nested']] as $badId) {
        sns_test_reject(static fn() => sns_provider_identity($providers['github'], ['id' => $badId]), 'GitHub accepted a malformed stable ID.');
    }
    $google = sns_provider_identity($providers['google'], ['sub' => 'google-subject', 'email' => 'owner@example.com', 'email_verified' => 'true']);
    sns_test_same('google-subject', $google['subject'], 'Google did not use the subject claim as identity.');
    sns_test_same(false, $google['email_verified'], 'Google accepted a non-boolean verified email claim.');
    sns_test_reject(static fn() => sns_provider_identity($providers['google'], ['email' => 'owner@example.com', 'email_verified' => true]), 'Google used an email address without a subject.');
    sns_test_reject(static fn() => sns_provider_identity($providers['google'], ['sub' => 123]), 'Google accepted a numeric subject instead of a string.');
    sns_test_same('456', sns_provider_identity($providers['linuxdo'], ['id' => 456, 'username' => 'linux-user'])['subject'], 'LINUX DO did not use its stable numeric ID.');
    sns_test_reject(static fn() => sns_provider_identity($providers['linuxdo'], ['id' => 456, 'active' => false]), 'An inactive LINUX DO account was accepted.');
    sns_test_reject(static fn() => sns_provider_identity($providers['linuxdo'], ['id' => 456, 'silenced' => true]), 'A silenced LINUX DO account was accepted.');

    sns_test_same(['access_token' => 'qq-token', 'expires_in' => '7776000'], sns_parse_qq_response('access_token=qq-token&expires_in=7776000'), 'QQ query response did not parse.');
    $openid = ['client_id' => 'qq-app', 'openid' => str_repeat('A', 32)];
    sns_test_same($openid, sns_parse_qq_response('callback( ' . json_encode($openid) . ' );'), 'QQ JSONP response did not parse.');
    sns_test_same($openid, sns_parse_qq_response(json_encode($openid)), 'QQ JSON response did not parse.');
    foreach (['callback({"error":100000,"error_description":"secret"});', '{"error":0}', 'access_token=token&error=denied', 'access_token=first&access_token=second', 'access_token[]=nested', 'callback({"openid":"x"});alert(1)', 'not-a-response'] as $badQq) {
        sns_test_reject(static fn() => sns_parse_qq_response($badQq), 'An error or malformed QQ response was accepted.');
    }
    sns_test_same(str_repeat('A', 32), sns_provider_identity($providers['qq'], ['ret' => 0, 'nickname' => 'QQ User'], ['openid' => $openid, 'client_id' => 'qq-app'])['subject'], 'QQ did not use the application-scoped OpenID.');
    sns_test_reject(static fn() => sns_provider_identity($providers['qq'], ['ret' => 0], ['openid' => $openid, 'client_id' => 'other-qq-app']), 'QQ accepted an OpenID from a different application.');
    sns_test_reject(static fn() => sns_provider_identity($providers['qq'], ['ret' => 1], ['openid' => $openid, 'client_id' => 'qq-app']), 'QQ accepted a failed profile response.');
    $normalized = sns_normalize_identity(['subject' => 'stable', 'name' => "name\x00\n", 'avatar' => 'javascript:alert(1)', 'url' => 'http://insecure.example.com', 'email' => 'not-an-email', 'email_verified' => true]);
    sns_test_same('name', $normalized['name'], 'Provider names retained control characters.');
    sns_test_same('', $normalized['avatar'], 'An unsafe avatar URL was retained.');
    sns_test_same('', $normalized['url'], 'An insecure profile URL was retained.');
    sns_test_same(false, $normalized['email_verified'], 'A malformed email retained verified status.');
    foreach (['http://provider.example.com/oauth', 'https://127.0.0.1/oauth', 'https://localhost/oauth', 'https://provider.internal/oauth', 'https://provider.example.com:8443/oauth', 'https://user:secret@provider.example.com/oauth', 'https://provider.example.com/oauth#fragment', "https://provider.example.com/\n"] as $endpoint) {
        sns_test_reject(static fn() => sns_endpoint_parts($endpoint), 'An unsafe provider endpoint was accepted: ' . $endpoint);
    }
    foreach (['127.0.0.1', '10.0.0.1', '172.16.0.1', '192.168.1.1', '169.254.169.254', '100.64.0.1', '198.18.0.150', '192.0.2.1', '::1', 'fc00::1', 'fe80::1', '::ffff:0:c612:96', '2002:7f00:1::', 'not-an-ip'] as $ip) {
        sns_test_same(false, sns_public_ip($ip), 'A provider DNS response could access a private or reserved address: ' . $ip);
    }
    foreach (['1.1.1.1', '8.8.8.8', '2606:4700:4700::1111'] as $ip) {
        sns_test_same(true, sns_public_ip($ip), 'A globally reachable provider address was rejected: ' . $ip);
    }
    add_plugin_filter('sns_login_providers', static function (array $registered) use ($snsFixture): array {
        $registered['github']['authorize_url'] = 'https://attacker.example.com/authorize';
        $registered['bad-name!'] = $snsFixture;
        $registered['insecure'] = array_replace($snsFixture, ['token_url' => 'http://attacker.example.com/token']);
        $registered['noadapter'] = array_diff_key($snsFixture, ['adapter' => true]);
        return $registered;
    }, 50);
    $registry = sns_providers();
    sns_test_same($providers['github']['authorize_url'], $registry['github']['authorize_url'], 'An extension replaced a built-in provider endpoint.');
    sns_test_assert(!isset($registry['bad-name!']) && !isset($registry['insecure']) && !isset($registry['noadapter']), 'An invalid provider extension was registered.');

    $_SESSION = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = ['provider' => 'fixture', 'purpose' => 'login', 'csrf_token' => csrf_token()];
    sns_test_reject(static fn() => sns_start(), 'A GET request started authorization.');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = 'wrong';
    sns_test_reject(static fn() => sns_start(), 'Authorization started without a valid CSRF token.');
    $_POST['csrf_token'] = csrf_token();
    $_POST['provider'] = ['nested'];
    sns_test_reject(static fn() => sns_start(), 'Authorization accepted a nested provider parameter.');
    $_POST['provider'] = 'fixture';
    $_POST['return_url'] = 'https://attacker.example.com/steal';
    $authorization = sns_start();
    parse_str((string)parse_url($authorization, PHP_URL_QUERY), $authQuery);
    sns_test_same('identity.example.com', parse_url($authorization, PHP_URL_HOST), 'Authorization redirected to a caller-supplied URL.');
    sns_test_same('code', $authQuery['response_type'], 'Authorization requested a non-code flow.');
    sns_test_same('fixture-app', $authQuery['client_id'], 'Authorization used the wrong application ID.');
    sns_test_same(sns_callback_url('fixture'), $authQuery['redirect_uri'], 'Authorization used an incorrect callback.');
    sns_test_same('S256', $authQuery['code_challenge_method'], 'Authorization did not use S256 PKCE.');
    sns_test_assert(!str_contains($authorization, 'fixture-client-secret') && !isset($authQuery['return_url']), 'Authorization URL exposed secret credentials or a visitor redirect.');
    $entry = $_SESSION['sns_login_pending'][hash('sha256', $authQuery['state'])];
    sns_test_same(rtrim(strtr(base64_encode(hash('sha256', $entry['verifier'], true)), '+/', '-_'), '='), $authQuery['code_challenge'], 'PKCE challenge does not match the stored verifier.');

    $GLOBALS['sns_test_identity'] = ['subject' => 'unbound', 'email' => 'owner@example.com', 'email_verified' => true, 'name' => 'Unbound'];
    $GLOBALS['sns_test_adapter_calls'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['provider' => 'fixture', 'state' => $authQuery['state'], 'code' => 'fixture-code'];
    sns_test_reject(static fn() => sns_complete(), 'An unbound third-party identity logged into an existing local account.');
    sns_test_same(2, (int)val('SELECT COUNT(*) FROM users'), 'An unbound identity created a local user.');
    sns_test_same(null, current_admin(), 'An unbound identity authenticated a local account.');
    sns_test_same(1, count($GLOBALS['sns_test_adapter_calls']), 'An unbound callback did not resolve its identity once.');
    sns_test_same(1, login_rate_state()['count'], 'Failed account matching did not count toward the login limit.');

    $denied = sns_pending_create('fixture', 'login', $config);
    $_GET = ['provider' => 'fixture', 'state' => $denied['state'], 'error' => 'access_denied'];
    sns_test_reject(static fn() => sns_complete(), 'A denied authorization was accepted.');
    sns_test_same(1, count($GLOBALS['sns_test_adapter_calls']), 'A denied authorization contacted the provider adapter.');
    sns_test_reject(static fn() => sns_pending_take('fixture', $denied['state']), 'A denied authorization did not consume its state.');
    $missingCode = sns_pending_create('fixture', 'login', $config);
    $_GET = ['provider' => 'fixture', 'state' => $missingCode['state']];
    sns_test_reject(static fn() => sns_complete(), 'A callback without an authorization code was accepted.');
    $nestedCode = sns_pending_create('fixture', 'login', $config);
    $_GET = ['provider' => 'fixture', 'state' => $nestedCode['state'], 'code' => ['nested']];
    sns_test_reject(static fn() => sns_complete(), 'A nested authorization code was accepted.');
    sns_test_same(1, count($GLOBALS['sns_test_adapter_calls']), 'Malformed callbacks contacted the provider adapter.');

    $GLOBALS['sns_test_identity'] = $identity;
    $valid = sns_pending_create('fixture', 'login', $config);
    $_SESSION['csrf_token'] = 'old-session-csrf';
    $oldSessionId = session_id();
    $_GET = ['provider' => 'fixture', 'state' => $valid['state'], 'code' => 'successful-code'];
    $result = sns_complete();
    sns_test_same(url_for('admin'), $result['url'], 'Successful login did not redirect to the local backend.');
    sns_test_assert(session_id() !== $oldSessionId, 'Successful login did not regenerate the session ID.');
    sns_test_same(1, $_SESSION['admin_id'], 'Successful login authenticated the wrong local account.');
    sns_test_same(hash('sha256', one('SELECT password_hash FROM users WHERE id = 1')['password_hash']), $_SESSION['admin_password_fingerprint'], 'OAuth login omitted the core password fingerprint.');
    sns_test_assert(abs($_SESSION['admin_authenticated_at'] - time()) <= 1 && $_SESSION['admin_authenticated_at'] === $_SESSION['admin_last_seen_at'], 'OAuth login omitted the core session timestamps.');
    sns_test_same($bindingId, $_SESSION['sns_login_binding_id'], 'OAuth session did not retain its revocable binding.');
    sns_test_same(sns_config_fingerprint('fixture', $config), $_SESSION['sns_login_config_hash'], 'OAuth session did not retain its provider configuration fingerprint.');
    sns_test_assert(!isset($_SESSION['csrf_token']) && !isset($_SESSION['sns_login_pending']), 'Successful login retained an old CSRF token or pending authorization.');
    sns_test_same(1, $GLOBALS['sns_test_presence'], 'OAuth login did not update core administrator presence.');
    sns_test_same(0, login_rate_state()['count'], 'Successful login did not clear prior login failures.');
    sns_test_assert((int)val('SELECT last_used_at FROM sblog_sns_bindings WHERE id = ?', [$bindingId]) > 0, 'Successful login did not update binding usage.');
    sns_test_reject(static fn() => sns_complete(), 'A successful callback was accepted twice.');
    sns_bootstrap();
    sns_test_same(1, $_SESSION['admin_id'], 'An unchanged binding invalidated a valid OAuth login.');
    $disabled = $snsConfig;
    $disabled['fixture']['enabled'] = false;
    sns_save_config($disabled);
    sns_bootstrap();
    sns_test_same(null, current_admin(), 'Disabling a provider left an OAuth administrator session valid.');
    sns_test_assert(!isset($_SESSION['sns_login_binding_id']) && !isset($_SESSION['sns_login_config_hash']), 'Invalidated OAuth session retained its binding markers.');
    sns_save_config($snsConfig);

    sns_test_admin(1);
    $_SESSION['sns_login_binding_id'] = $bindingId;
    $_SESSION['sns_login_config_hash'] = sns_config_fingerprint('fixture', $config);
    $GLOBALS['sns_test_database_failure'] = true;
    sns_bootstrap();
    $GLOBALS['sns_test_database_failure'] = false;
    sns_test_same(null, current_admin(), 'A storage failure left an unverifiable OAuth administrator session authenticated.');
    sns_test_assert(!isset($_SESSION['sns_login_binding_id']) && !isset($_SESSION['sns_login_config_hash']), 'A storage failure retained unverifiable OAuth markers.');

    $_SESSION = ['sns_login_binding_id' => $bindingId, 'sns_login_config_hash' => 'orphan-marker'];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    sns_handle_request(['action' => 'login']);
    sns_test_assert(!isset($_SESSION['sns_login_binding_id']) && !isset($_SESSION['sns_login_config_hash']), 'An anonymous core password login inherited orphan OAuth markers.');
    $_SERVER['REQUEST_METHOD'] = 'GET';

    $newApplication = $snsConfig;
    $newApplication['fixture']['client_id'] = 'new-fixture-app';
    sns_save_config($newApplication);
    $newAppPending = sns_pending_create('fixture', 'login', $newApplication['fixture']);
    $_GET = ['provider' => 'fixture', 'state' => $newAppPending['state'], 'code' => 'same-subject-new-app'];
    sns_test_reject(static fn() => sns_complete(), 'An existing binding logged in through a different OAuth application.');
    sns_test_same(null, current_admin(), 'A new OAuth application reused an old administrator binding.');
    sns_test_same(2, (int)val('SELECT COUNT(*) FROM users'), 'Changing the OAuth application created a local user.');
    sns_save_config($snsConfig);
    sns_test_admin(1);
    sns_save_config($disabled);
    sns_bootstrap();
    sns_test_same(1, $_SESSION['admin_id'], 'Disabling OAuth invalidated a local password login.');
    $_SESSION = [];
    sns_save_config($snsConfig);

    $GLOBALS['sns_test_login_failures'] = 5;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['provider' => 'fixture', 'purpose' => 'login', 'csrf_token' => csrf_token()];
    sns_test_reject(static fn() => sns_start(), 'OAuth start bypassed the core login rate limit.');
    $ratePending = sns_pending_create('fixture', 'login', $config);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['provider' => 'fixture', 'state' => $ratePending['state'], 'code' => 'limited-code'];
    $callsBeforeLimit = count($GLOBALS['sns_test_adapter_calls']);
    sns_test_reject(static fn() => sns_complete(), 'OAuth callback bypassed the core login rate limit.');
    sns_test_same($callsBeforeLimit, count($GLOBALS['sns_test_adapter_calls']), 'A rate-limited callback contacted the provider adapter.');
    $GLOBALS['sns_test_login_failures'] = 0;

    $privateError = sns_pending_create('fixture', 'login', $config);
    $_GET = ['provider' => 'fixture', 'state' => $privateError['state'], 'code' => 'private-error'];
    $safeResponse = sns_test_response(static fn() => sns_handle_request(['action' => 'sns_login_callback']));
    sns_test_same(url_for('login'), $safeResponse->target, 'A failed callback redirected outside the local login page.');
    sns_test_same(303, $safeResponse->status, 'Callback redirects are not converted to a safe GET.');
    sns_test_assert(!str_contains(json_encode($GLOBALS['sns_test_flash']), 'provider-token-secret'), 'An upstream exception exposed credentials through the flash message.');

    sns_test_admin(1);
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['provider' => 'fixture', 'purpose' => 'bind', 'password' => 'wrong', 'csrf_token' => csrf_token()];
    sns_test_reject(static fn() => sns_start(), 'A bind attempt bypassed current-password verification.');
    $_POST['password'] = $snsPassword;
    $bindUrl = sns_start();
    parse_str((string)parse_url($bindUrl, PHP_URL_QUERY), $bindQuery);
    sns_test_admin(2);
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = ['provider' => 'fixture', 'state' => $bindQuery['state'], 'code' => 'switched-user'];
    $callsBeforeBind = count($GLOBALS['sns_test_adapter_calls']);
    sns_test_reject(static fn() => sns_complete(), 'A binding callback followed a switched local account.');
    sns_test_same($callsBeforeBind, count($GLOBALS['sns_test_adapter_calls']), 'A switched-account binding contacted the provider adapter.');
    sns_test_admin(1);
    $passwordChange = sns_pending_create('fixture', 'bind', $config, 1);
    $oldPasswordHash = one('SELECT password_hash FROM users WHERE id = 1')['password_hash'];
    q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash('changed-password', PASSWORD_DEFAULT), 1]);
    $_GET = ['provider' => 'fixture', 'state' => $passwordChange['state'], 'code' => 'changed-password'];
    sns_test_reject(static fn() => sns_complete(), 'A bind callback remained valid after changing the local password.');
    q('UPDATE users SET password_hash = ? WHERE id = ?', [$oldPasswordHash, 1]);

    $_SESSION = [];
    sns_test_admin(2);
    $newBind = sns_pending_create('fixture', 'bind', $config, 2);
    $GLOBALS['sns_test_identity'] = ['subject' => 'second-fixture-subject', 'name' => 'Second'];
    $_GET = ['provider' => 'fixture', 'state' => $newBind['state'], 'code' => 'new-binding'];
    sns_test_same(sns_url('admin_sns_login'), sns_complete()['url'], 'Binding completion did not return to plugin settings.');
    sns_test_same(2, (int)sns_find_binding('fixture', $config, 'second-fixture-subject')['user_id'], 'Binding completion linked the wrong local account.');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf_token' => csrf_token(), 'binding_id' => (string)$bindingId, 'password' => 'second-account-password'];
    sns_test_response(static fn() => sns_handle_admin_request('unlink_sns_login'));
    sns_test_assert(sns_find_binding('fixture', $config, $identity['subject']) !== null, 'A settings request unlinked another administrator identity.');
    sns_test_admin(1);
    $_POST['password'] = 'wrong-password';
    sns_test_response(static fn() => sns_handle_admin_request('unlink_sns_login'));
    sns_test_assert(sns_find_binding('fixture', $config, $identity['subject']) !== null, 'Unlink bypassed local-password confirmation.');
    $_POST['password'] = $snsPassword;
    $_POST['csrf_token'] = 'wrong-csrf';
    sns_test_reject(static fn() => sns_handle_admin_request('unlink_sns_login'), 'Unlink bypassed CSRF protection.');
    $_POST['csrf_token'] = csrf_token();
    $_SESSION['sns_login_binding_id'] = $bindingId;
    $_SESSION['sns_login_config_hash'] = sns_config_fingerprint('fixture', $config);
    sns_test_response(static fn() => sns_handle_admin_request('unlink_sns_login'));
    sns_test_same(null, sns_find_binding('fixture', $config, $identity['subject']), 'A confirmed owner unlink did not remove the binding.');
    sns_test_same(null, current_admin(), 'Unlinking the identity used for login left the administrator session authenticated.');

    $loginHtml = '<html><head></head><body><form action="/blog/index.php?a=login"><input id="username"><input type="password"></form></body></html>';
    $withButtons = sns_output_html($loginHtml, ['action' => 'login', 'content_type' => 'text/html; charset=UTF-8']);
    sns_test_same(2, substr_count($withButtons, '<form'), 'Login page did not add exactly one enabled provider form.');
    sns_test_assert(strpos($withButtons, '</form>') < strpos($withButtons, 'data-sns-login>'), 'Provider forms were nested inside the password form.');
    sns_test_same($withButtons, sns_output_html($withButtons, ['action' => 'login']), 'Repeated output filtering duplicated the login interface.');
    sns_test_same($loginHtml, sns_output_html($loginHtml, ['action' => 'home']), 'Public pages received the login controls.');
    sns_test_same($loginHtml, sns_output_html($loginHtml, ['action' => 'login', 'content_type' => 'application/json']), 'A JSON response was modified with login HTML.');
    $coreHeaders = 'Cache-Control: private, no-store; Pragma: no-cache; Vary: Cookie';
    sns_test_same($withButtons, sns_output_html($loginHtml, ['action' => 'login', 'content_type' => $coreHeaders]), 'Core headers without an explicit Content-Type suppressed the login controls.');
    sns_test_same($withButtons, sns_output_html($loginHtml, ['action' => 'login', 'content_type' => $coreHeaders . '; Content-Type: text/html; charset=UTF-8']), 'Core headers with an HTML Content-Type suppressed the login controls.');
    sns_test_same($loginHtml, sns_output_html($loginHtml, ['action' => 'login', 'content_type' => $coreHeaders . '; Content-Type: application/json; charset=UTF-8']), 'Core JSON response headers were modified with login HTML.');
    sns_test_same($loginHtml, sns_output_html($loginHtml, ['action' => 'login', 'content_type' => 'text/plain; charset=UTF-8']), 'A plain-text response was modified with login HTML.');
    sns_test_assert(!str_contains($withButtons, 'fixture-client-secret'), 'The public login interface exposed a secret.');

    echo 'SNS login tests passed (' . $GLOBALS['sns_test_assertions'] . " assertions).\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'SNS login tests failed: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    session_write_close();
}
