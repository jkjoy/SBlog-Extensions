<?php

declare(strict_types=1);

// Standalone CLI harness: only in-memory SQLite and injected HTTP responses are used.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (!extension_loaded('pdo_sqlite')) { fwrite(STDERR, "Required extension: pdo_sqlite\n"); exit(1); }
define('PLUGINS_DIR', dirname(__DIR__, 2));

$actions = [];
$checks = [];
$results = [];
$database = null;
$authenticated = true;
$csrfValid = true;
$authCalls = 0;
$csrfCalls = 0;
$queryCalls = [];
$flashes = [];

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) { return false; }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function db(): PDO { return $GLOBALS['database']; }
function one(string $sql, array $params = []): array|false
{
    $GLOBALS['queryCalls'][] = [$sql, $params];
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement->fetch(PDO::FETCH_ASSOC);
}
function setting(string $key, string $default = ''): string { return $key === 'site_name' ? '离线测试博客' : $default; }
function url_for(string $route, array $params = []): string { return '/' . $route; }
function script_url(): string { return '/index.php'; }
function url_with_query(string $url, array $params): string { return $url . '?' . http_build_query($params); }
function absolute_url(string $path): string { return 'https://blog.example.test' . $path; }
function content_permalink(array $post): string { return '/post/' . $post['slug']; }
function add_plugin_action(string $name, callable $handler, int $priority = 10): void { $GLOBALS['actions'][$name][] = [$handler, $priority]; }
function h(string|int|float|bool|null $value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function require_admin(): void
{
    $GLOBALS['authCalls']++;
    if (!$GLOBALS['authenticated']) { throw new RuntimeException('TEST_AUTH_REQUIRED'); }
}
function verify_csrf(): void
{
    $GLOBALS['csrfCalls']++;
    if (!$GLOBALS['csrfValid']) { throw new RuntimeException('TEST_CSRF_REQUIRED'); }
}
function csrf_field(): string { return '<input name="csrf_token" value="test-csrf" type="hidden">'; }
function set_flash(string $type, string $message, string $context = ''): void { $GLOBALS['flashes'][] = [$type, $message, $context]; }
function redirect_to(string $url, int $status = 302): void { throw new RuntimeException('TEST_REDIRECT:' . $url); }
function plugin_asset_url(string $slug, string $path): string { return '/plugins/' . $slug . '/' . $path; }
function render_admin_sidebar(string $active, array $summary = []): string { return '<aside>admin</aside>'; }
function render_admin_topbar(string $title, string $actionLabel = '', string $actionUrl = ''): string { return '<header>' . h($title) . '</header>'; }
function render_admin_content_start(string $title, string $sidebar): string { return $sidebar . '<main>'; }
function render_admin_content_end(string $title, string $active, string $description): void { echo '</main>'; }
function render_layout(string $title, string $content, array $options = []): void { echo $content; }

require dirname(__DIR__) . '/plugin.php';
require dirname(__DIR__) . '/admin.php';

function check(bool $condition, string $message = 'Assertion failed'): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) { throw new RuntimeException($message !== '' ? $message : 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true)); }
}
function raises(callable $callback, string $contains = ''): Throwable
{
    try { $callback(); } catch (Throwable $exception) {
        if ($contains !== '') { check(str_contains($exception->getMessage(), $contains), 'Unexpected error: ' . $exception->getMessage()); }
        return $exception;
    }
    throw new RuntimeException('Expected an exception');
}
function response(array $data = [], int $status = 200): array { return ['status' => $status, 'data' => $data]; }
function transport(array &$calls, array $responses): Closure
{
    return static function (string $method, string $url, array $headers = [], ?array $payload = null, int $timeout = 6) use (&$calls, &$responses): array {
        $calls[] = compact('method', 'url', 'headers', 'payload', 'timeout');
        if ($responses === []) { throw new RuntimeException('Unexpected HTTP request'); }
        $next = array_shift($responses);
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    };
}
function test(string $name, callable $callback): void { $GLOBALS['checks'][$name] = $callback; }
function fresh(): void
{
    $GLOBALS['database'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $GLOBALS['database']->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, title TEXT, slug TEXT)');
    $GLOBALS['database']->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, user_id INTEGER, author_name TEXT, author_email TEXT, ip_address TEXT, content TEXT, status TEXT)');
    $GLOBALS['authenticated'] = true;
    $GLOBALS['csrfValid'] = true;
    $GLOBALS['authCalls'] = 0;
    $GLOBALS['csrfCalls'] = 0;
    $GLOBALS['queryCalls'] = [];
    $GLOBALS['flashes'] = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_POST = [];
}
function qqConfig(array $changes = []): array
{
    return array_replace(sblog_push_defaults(), ['qq_enabled' => true, 'qq_app_id' => '123456', 'qq_app_secret' => 'test-secret', 'qq_target_id' => 'USER_OPENID'], $changes);
}
function cachedQQ(array $config, int $expires = 3600, string $token = 'cached-token'): void
{
    sblog_push_write('qq_token', ['identity' => hash('sha256', $config['qq_app_id'] . ':' . $config['qq_app_secret']), 'token' => $token, 'expires_at' => time() + $expires]);
}
function weixinAccount(string $owner = 'owner@im.wechat'): array
{
    $account = ['token' => 'weixin-test-secret', 'account_id' => 'bot@im.bot', 'owner_id' => $owner, 'base_url' => 'https://ilinkai.weixin.qq.com', 'expired' => false];
    sblog_push_write('weixin_account', $account);
    return $account;
}
function weixinBound(bool $context = true): array
{
    weixinAccount();
    sblog_push_write('weixin_sync', ['candidates' => ['owner@im.wechat'], 'context_tokens' => $context ? ['owner@im.wechat' => 'session-context'] : []]);
    $config = qqConfig(['weixin_enabled' => true, 'weixin_user_id' => 'owner@im.wechat']);
    sblog_push_save_config($config);
    return $config;
}
function commentFixture(string $status = 'approved', int $userId = 0): array
{
    db()->exec("INSERT INTO posts VALUES (7, '真实文章', 'actual-post')");
    $statement = db()->prepare('INSERT INTO comments VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $statement->execute([91, 7, $userId, '真实评论人', 'private@example.test', '192.0.2.17', '真实评论内容', $status]);
    return ['comment_id' => 91, 'post_id' => 7, 'status' => $status, 'comment' => ['content' => 'FORGED_CONTENT', 'author_name' => 'FORGED_AUTHOR']];
}

test('plugin registers the committed-comment hook', static function (): void {
    same('sblog_push_comment_created', $GLOBALS['actions']['comment_created'][0][0]);
    same(30, $GLOBALS['actions']['comment_created'][0][1]);
    check(is_callable($GLOBALS['actions']['request'][0][0]));
});
test('disabled plugin creates no storage and makes no comment or HTTP query', static function (): void {
    $calls = [];
    sblog_push_comment_created(['comment_id' => 91, 'post_id' => 7], transport($calls, []));
    same([], $calls);
    same([], $GLOBALS['queryCalls']);
    same(false, db()->query("SELECT name FROM sqlite_master WHERE name = 'plugin_comment_push'")->fetchColumn());
});
test('malformed stored config and unknown fields fall back to safe defaults', static function (): void {
    sblog_push_write('config', ['qq_enabled' => '1', 'qq_app_id' => [], 'qq_target_type' => false, 'include_link' => [], 'unknown' => 'https://evil.test']);
    same(sblog_push_defaults(), sblog_push_config());
    db()->exec("UPDATE plugin_comment_push SET payload = 'not-json' WHERE name = 'config'");
    same(sblog_push_defaults(), sblog_push_config());
});
test('config validation handles non-scalar inputs without warnings', static function (): void {
    $result = sblog_push_validate(['qq_enabled' => '1', 'qq_app_id' => [], 'qq_app_secret' => ['bad'], 'qq_target_id' => ['https://evil.test'], 'qq_target_type' => ['c2c'], 'notify_pending' => '1'], sblog_push_defaults());
    check(count($result['errors']) >= 2);
    same('', $result['config']['qq_app_id']);
});
test('blank secrets preserve existing credentials and explicit clear removes them', static function (): void {
    $input = ['qq_app_id' => '123456', 'qq_target_type' => 'c2c', 'qq_target_id' => 'USER_OPENID', 'notify_pending' => '1', 'qq_app_secret' => ''];
    same('test-secret', sblog_push_validate($input, qqConfig())['config']['qq_app_secret']);
    $input['clear_qq_app_secret'] = '1';
    same('', sblog_push_validate($input, qqConfig())['config']['qq_app_secret']);
    $input['qq_app_secret'] = 'replacement-secret';
    same('replacement-secret', sblog_push_validate($input, qqConfig())['config']['qq_app_secret']);
});
test('form fields cannot replace the synced Weixin recipient', static function (): void {
    $config = qqConfig(['weixin_user_id' => 'owner@im.wechat']);
    $result = sblog_push_validate(['qq_target_type' => 'c2c', 'notify_pending' => '1', 'weixin_user_id' => 'attacker@im.wechat'], $config);
    same('owner@im.wechat', $result['config']['weixin_user_id']);
});
test('credential changes invalidate QQ token and unrelated changes preserve it', static function (): void {
    $config = qqConfig();
    sblog_push_save_config($config);
    cachedQQ($config);
    sblog_push_save_config(array_replace($config, ['include_link' => false]));
    same('cached-token', sblog_push_read('qq_token')['token']);
    sblog_push_save_config(array_replace($config, ['qq_app_secret' => 'new-secret']));
    same([], sblog_push_read('qq_token'));
});
test('QQ token endpoint, body, authentication and cache reuse match official protocol', static function (): void {
    $calls = [];
    $config = qqConfig();
    $mock = transport($calls, [response(['access_token' => 'issued-token', 'expires_in' => '7200']), response(['id' => 'message-1']), response(['id' => 'message-2'])]);
    sblog_push_qq_send($config, '你好', $mock);
    sblog_push_qq_send($config, '再次通知', $mock);
    same(3, count($calls));
    same('https://api.bot.qq.com/app/getAppAccessToken', $calls[0]['url']);
    same(['appId' => '123456', 'clientSecret' => 'test-secret'], $calls[0]['payload']);
    same('https://api.bot.qq.com/v2/users/USER_OPENID/messages', $calls[1]['url']);
    same(['Authorization: QQBot issued-token'], $calls[1]['headers']);
    same(['content' => '你好', 'msg_type' => 0], $calls[1]['payload']);
    check(!isset($calls[1]['payload']['msg_id'], $calls[1]['payload']['event_id']));
});
test('QQ near-expiry and other-identity tokens are never reused', static function (): void {
    $config = qqConfig();
    cachedQQ($config, 30);
    $calls = [];
    $mock = transport($calls, [response(['access_token' => 'renewed-token', 'expires_in' => 7200]), response(['id' => 'ok'])]);
    sblog_push_qq_send($config, '通知', $mock);
    same('https://api.bot.qq.com/app/getAppAccessToken', $calls[0]['url']);
    $calls = [];
    $changed = array_replace($config, ['qq_app_id' => '654321']);
    sblog_push_qq_send($changed, '通知', transport($calls, [response(['access_token' => 'different-bot', 'expires_in' => 7200]), response(['id' => 'ok'])]));
    same('654321', $calls[0]['payload']['appId']);
});
test('QQ HTTP 401 refreshes once and retries with the new token', static function (): void {
    $config = qqConfig();
    cachedQQ($config);
    $calls = [];
    sblog_push_qq_send($config, '通知', transport($calls, [response(['code' => 11243], 401), response(['access_token' => 'new-token', 'expires_in' => 7200]), response(['id' => 'ok'])]));
    same(3, count($calls));
    same(['Authorization: QQBot cached-token'], $calls[0]['headers']);
    same(['Authorization: QQBot new-token'], $calls[2]['headers']);
});
test('QQ a second HTTP 401 terminates without a retry loop', static function (): void {
    $config = qqConfig();
    cachedQQ($config);
    $calls = [];
    $mock = transport($calls, [response([], 401), response(['access_token' => 'new-token', 'expires_in' => 7200]), response([], 401)]);
    raises(static fn() => sblog_push_qq_send($config, '通知', $mock), 'HTTP 401');
    same(3, count($calls));
});
test('QQ HTTP 200 business errors in both code formats are failures', static function (): void {
    foreach (['code', 'err_code'] as $field) {
        $config = qqConfig();
        cachedQQ($config);
        $calls = [];
        $mock = transport($calls, [response([$field => 40034105, 'message' => 'do not render this remote payload'])]);
        $error = raises(static fn() => sblog_push_qq_send($config, '通知', $mock), '40034105');
        check(!str_contains($error->getMessage(), 'do not render'));
        same(1, count($calls));
    }
});
test('QQ token errors returned with HTTP 200 never reach send endpoint', static function (): void {
    $calls = [];
    $mock = transport($calls, [response(['code' => 100016, 'access_token' => 'invalid', 'expires_in' => 7200])]);
    raises(static fn() => sblog_push_qq_send(qqConfig(), '通知', $mock), '100016');
    same(1, count($calls));
    same([], sblog_push_read('qq_token'));
});
test('QQ malformed success and audit-pending responses do not claim delivery', static function (): void {
    $config = qqConfig();
    cachedQQ($config);
    foreach ([response([]), response(['code' => 304023, 'data' => ['message_audit' => ['audit_id' => 'audit']]], 202)] as $fixture) {
        $calls = [];
        $mock = transport($calls, [$fixture]);
        raises(static fn() => sblog_push_qq_send($config, '通知', $mock));
        same(1, count($calls));
    }
});
test('QQ network ambiguity and rate limits never trigger automatic resends', static function (): void {
    $config = qqConfig();
    cachedQQ($config);
    foreach ([new RuntimeException('simulated timeout'), response(['err_code' => 40034100], 429), response([], 503)] as $fixture) {
        $calls = [];
        $mock = transport($calls, [$fixture]);
        raises(static fn() => sblog_push_qq_send($config, '通知', $mock));
        same(1, count($calls));
    }
});
test('QQ group and channel use correct endpoints and channel omits msg_type', static function (): void {
    foreach (['group' => '/v2/groups/', 'channel' => '/channels/'] as $kind => $path) {
        $config = qqConfig(['qq_target_type' => $kind]);
        cachedQQ($config);
        $calls = [];
        sblog_push_qq_send($config, '通知', transport($calls, [response(['id' => 'ok'])]));
        same('https://api.bot.qq.com' . $path . 'USER_OPENID/messages', $calls[0]['url']);
        same($kind !== 'channel', isset($calls[0]['payload']['msg_type']));
    }
});
test('QQ target cannot inject paths or arbitrary hosts', static function (): void {
    foreach (['https://evil.test', '../messages', 'target?token=x', "target\r\nX-Test:1"] as $target) {
        $calls = [];
        $mock = transport($calls, []);
        raises(static fn() => sblog_push_qq_send(qqConfig(['qq_target_id' => $target]), '通知', $mock));
        same([], $calls);
    }
});
test('notification reloads committed IDs and ignores untrusted context fields', static function (): void {
    $config = qqConfig();
    sblog_push_save_config($config);
    cachedQQ($config);
    $context = commentFixture();
    $context['status'] = 'spam';
    $calls = [];
    sblog_push_comment_created($context, transport($calls, [response(['id' => 'ok'])]));
    same(1, count($calls));
    $text = $calls[0]['payload']['content'];
    check(str_contains($text, '真实文章') && str_contains($text, '真实评论人') && str_contains($text, '真实评论内容'));
    check(str_contains($text, '/post/actual-post#comment-91'));
    check(!str_contains($text, 'FORGED_') && !str_contains($text, 'private@example.test') && !str_contains($text, '192.0.2.17'));
    same(true, sblog_push_read('result_qq')['ok']);
});
test('comment context requires matching stored comment and post IDs', static function (): void {
    sblog_push_save_config(qqConfig());
    $context = commentFixture();
    $context['post_id'] = 8;
    $calls = [];
    sblog_push_comment_created($context, transport($calls, []));
    same([], $calls);
});
test('pending, approved, spam and administrator filters use stored status', static function (): void {
    foreach ([['pending', 0, 'notify_pending'], ['approved', 0, 'notify_approved'], ['spam', 0, ''], ['approved', 4, 'notify_admin']] as [$status, $user, $flag]) {
        fresh();
        $config = qqConfig($flag !== '' ? [$flag => false] : []);
        sblog_push_save_config($config);
        $context = commentFixture($status, $user);
        $calls = [];
        sblog_push_comment_created($context, transport($calls, []));
        same([], $calls, 'Unexpected notification for ' . $status . '/' . $flag);
    }
});
test('pending messages use review anchor and administrator opt-in sends', static function (): void {
    foreach ([['pending', 0, '#comments'], ['approved', 4, '#comment-91']] as [$status, $user, $anchor]) {
        fresh();
        $config = qqConfig(['notify_admin' => true]);
        sblog_push_save_config($config);
        cachedQQ($config);
        $context = commentFixture($status, $user);
        $calls = [];
        sblog_push_comment_created($context, transport($calls, [response(['id' => 'ok'])]));
        same(1, count($calls));
        check(str_ends_with($calls[0]['payload']['content'], $anchor));
    }
});
test('message snippets keep UTF-8 intact and neutralize encoded QQ mentions', static function (): void {
    same('你好🙂…', sblog_push_text('<b>你好🙂再见</b>', 3));
    $text = sblog_push_text('&lt;@everyone&gt;&lt;@!1234&gt;正文<script>bad</script>', 400);
    check(!str_contains($text, '<@'), 'Encoded mention survived notification sanitation');
    check(str_contains($text, '正文'));
    same('', sblog_push_text("\xff\xfe", 20));
});
test('one failed channel does not prevent the other notification', static function (): void {
    $config = weixinBound();
    cachedQQ($config);
    $context = commentFixture();
    $calls = [];
    sblog_push_comment_created($context, transport($calls, [response(['err_code' => 40034105]), response(['ret' => 0])]));
    same(2, count($calls));
    same(false, sblog_push_read('result_qq')['ok']);
    same(true, sblog_push_read('result_weixin')['ok']);
    check(str_ends_with($calls[1]['url'], '/ilink/bot/sendmessage'));
});

// Weixin fixtures follow the official Tencent/openclaw-weixin protocol port.
test('Weixin rejects non-official and malformed API bases', static function (): void {
    same('https://ilinkai.weixin.qq.com', sblog_push_weixin_base('https://ilinkai.weixin.qq.com/'));
    foreach (['http://ilinkai.weixin.qq.com', 'https://weixin.qq.com.evil.test', 'https://evil.test', 'https://127.0.0.1', 'https://user@ilinkai.weixin.qq.com', 'https://ilinkai.weixin.qq.com:443', 'https://ilinkai.weixin.qq.com/api', 'https://ilinkai.weixin.qq.com?x=1'] as $base) {
        raises(static fn() => sblog_push_weixin_base($base));
    }
});
test('Weixin QR initiation stores only verified official login links', static function (): void {
    $calls = [];
    $result = sblog_push_weixin_start(transport($calls, [response(['qrcode' => 'qr-code', 'qrcode_img_content' => 'https://liteapp.weixin.qq.com/q/test'])]));
    same('wait', $result['status']);
    same('qr-code', sblog_push_read('weixin_login')['qrcode']);
    same('https://ilinkai.weixin.qq.com/ilink/bot/get_bot_qrcode?bot_type=3', $calls[0]['url']);
    check(in_array('iLink-App-Id: bot', $calls[0]['headers'], true));
    $calls = [];
    $mock = transport($calls, [response(['qrcode' => 'bad', 'qrcode_img_content' => 'https://evil.test/q'])]);
    raises(static fn() => sblog_push_weixin_start($mock));
    same('qr-code', sblog_push_read('weixin_login')['qrcode']);
});
test('Weixin confirmed QR login saves account and clears stale binding', static function (): void {
    weixinBound();
    sblog_push_write('weixin_login', ['qrcode' => 'qr value', 'started_at' => time(), 'base_url' => 'https://ilinkai.weixin.qq.com']);
    $calls = [];
    $result = sblog_push_weixin_check('', transport($calls, [response(['status' => 'confirmed', 'bot_token' => 'new-weixin-secret', 'ilink_bot_id' => 'bot@im.bot', 'ilink_user_id' => 'owner@im.wechat', 'baseurl' => 'https://ilinkai.weixin.qq.com'])]));
    same('confirmed', $result['status']);
    same('new-weixin-secret', sblog_push_read('weixin_account')['token']);
    same([], sblog_push_read('weixin_login'));
    same([], sblog_push_read('weixin_sync'));
    same('', sblog_push_config()['weixin_user_id']);
    same(false, sblog_push_config()['weixin_enabled']);
    check(str_contains($calls[0]['url'], 'qrcode=qr%20value'));
});
test('Weixin login redirects cannot move credentials to an arbitrary host', static function (): void {
    sblog_push_write('weixin_login', ['qrcode' => 'qr', 'started_at' => time(), 'base_url' => 'https://ilinkai.weixin.qq.com']);
    $calls = [];
    $mock = transport($calls, [response(['status' => 'scaned_but_redirect', 'redirect_host' => 'evil.test'])]);
    raises(static fn() => sblog_push_weixin_check('', $mock));
    same('https://ilinkai.weixin.qq.com', sblog_push_read('weixin_login')['base_url']);
    same(1, count($calls));
});
test('Weixin sync binds the scanning owner and ignores other users or group messages', static function (): void {
    weixinAccount();
    $calls = [];
    $result = sblog_push_weixin_sync(transport($calls, [response(['ret' => 0, 'get_updates_buf' => 'next-cursor', 'msgs' => [
        ['message_type' => 1, 'from_user_id' => 'attacker@im.wechat', 'context_token' => 'attacker-context'],
        ['message_type' => 1, 'from_user_id' => 'owner@im.wechat', 'group_id' => 'group', 'context_token' => 'group-context'],
        ['message_type' => 2, 'from_user_id' => 'owner@im.wechat', 'context_token' => 'outbound-context'],
        ['message_type' => 1, 'from_user_id' => 'owner@im.wechat', 'context_token' => 'owner-context'],
    ]])]));
    same('bound', $result['status']);
    same('owner@im.wechat', sblog_push_config()['weixin_user_id']);
    same(['owner@im.wechat'], sblog_push_read('weixin_sync')['candidates']);
    same('owner-context', sblog_push_read('weixin_sync')['context_tokens']['owner@im.wechat']);
    same('next-cursor', sblog_push_read('weixin_sync')['get_updates_buf']);
    check(in_array('Authorization: Bearer weixin-test-secret', $calls[0]['headers'], true));
});
test('Weixin unsynced recipients cannot bind or receive notifications', static function (): void {
    weixinAccount();
    raises(static fn() => sblog_push_weixin_bind('attacker@im.wechat'));
    $calls = [];
    $mock = transport($calls, []);
    raises(static fn() => sblog_push_weixin_send(qqConfig(['weixin_user_id' => 'attacker@im.wechat']), '通知', $mock));
    same([], $calls);
});
test('Weixin send includes context, protocol metadata and unique client IDs', static function (): void {
    $config = weixinBound();
    $calls = [];
    $mock = transport($calls, [response(['ret' => 0]), response(['ret' => 0])]);
    sblog_push_weixin_send($config, '你好', $mock);
    sblog_push_weixin_send($config, '你好', $mock);
    same('https://ilinkai.weixin.qq.com/ilink/bot/sendmessage', $calls[0]['url']);
    same('owner@im.wechat', $calls[0]['payload']['msg']['to_user_id']);
    same('session-context', $calls[0]['payload']['msg']['context_token']);
    same([['type' => 1, 'text_item' => ['text' => '你好']]], $calls[0]['payload']['msg']['item_list']);
    same(2, $calls[0]['payload']['msg']['message_type']);
    check($calls[0]['payload']['msg']['client_id'] !== $calls[1]['payload']['msg']['client_id']);
    check(isset($calls[0]['payload']['base_info']['channel_version']));
});
test('Weixin a synced owner can attempt sending without context as upstream permits', static function (): void {
    $config = weixinBound(false);
    $calls = [];
    sblog_push_weixin_send($config, '通知', transport($calls, [response(['ret' => 0])]));
    same(1, count($calls));
    check(!isset($calls[0]['payload']['msg']['context_token']));
});
test('Weixin polling read timeout returns wait and preserves login and sync state', static function (): void {
    weixinBound();
    $login = ['qrcode' => 'qr', 'started_at' => time(), 'base_url' => 'https://ilinkai.weixin.qq.com'];
    sblog_push_write('weixin_login', $login);
    $sync = sblog_push_read('weixin_sync');
    $calls = [];
    same('wait', sblog_push_weixin_check('', transport($calls, [new RuntimeException('safe read timeout', 28)]))['status']);
    same($login, sblog_push_read('weixin_login'));
    same('wait', sblog_push_weixin_sync(transport($calls, [new RuntimeException('safe read timeout', 28)]))['status']);
    same($sync, sblog_push_read('weixin_sync'));
    same(2, count($calls));
    $mock = transport($calls, [new RuntimeException('connection failure')]);
    raises(static fn() => sblog_push_weixin_sync($mock), 'connection failure');
    same(3, count($calls));
});
test('Weixin either negative ret or errcode defeats an apparent successful acknowledgement', static function (): void {
    $config = weixinBound();
    foreach ([['ret' => 0, 'errcode' => -2], ['ret' => -2, 'errcode' => 0], ['message_id' => 42, 'ret' => -2]] as $data) {
        $calls = [];
        $mock = transport($calls, [response($data)]);
        raises(static fn() => sblog_push_weixin_send($config, '通知', $mock), '-2');
        same(1, count($calls));
    }
});
test('expired Weixin account cannot be enabled through settings', static function (): void {
    $config = weixinBound();
    sblog_push_write('weixin_account', array_replace(sblog_push_read('weixin_account'), ['expired' => true]));
    $result = sblog_push_validate(['weixin_enabled' => '1', 'notify_pending' => '1', 'qq_target_type' => 'c2c'], $config);
    check($result['errors'] !== []);
});
test('Weixin session-expiry errors disable cached account and never retry', static function (): void {
    $config = weixinBound();
    $calls = [];
    $mock = transport($calls, [response(['ret' => -14])]);
    raises(static fn() => sblog_push_weixin_send($config, '通知', $mock), '-14');
    same(1, count($calls));
    same(true, sblog_push_read('weixin_account')['expired']);
    $calls = [];
    $mock = transport($calls, []);
    raises(static fn() => sblog_push_weixin_send($config, '通知', $mock));
    same([], $calls);
});
test('Weixin rejects malformed acknowledgements and avoids ambiguous resend', static function (): void {
    $config = weixinBound();
    foreach ([response([]), response(['ret' => -2]), new RuntimeException('simulated timeout')] as $fixture) {
        $calls = [];
        $mock = transport($calls, [$fixture]);
        raises(static fn() => sblog_push_weixin_send($config, '通知', $mock));
        same(1, count($calls));
    }
});
test('Weixin disconnect clears all account, login, sync and recipient state', static function (): void {
    weixinBound();
    sblog_push_write('weixin_login', ['qrcode' => 'old-qr', 'started_at' => time()]);
    sblog_push_weixin_disconnect();
    foreach (['weixin_account', 'weixin_login', 'weixin_sync'] as $key) { same([], sblog_push_read($key)); }
    same('', sblog_push_config()['weixin_user_id']);
    same(false, sblog_push_config()['weixin_enabled']);
});

test('admin page and request both require authentication', static function (): void {
    $GLOBALS['authenticated'] = false;
    raises(static fn() => sblog_push_admin(qqConfig()), 'TEST_AUTH_REQUIRED');
    raises(static fn() => sblog_push_admin_request(), 'TEST_AUTH_REQUIRED');
    check($GLOBALS['authCalls'] >= 2);
});
test('every admin POST operation verifies CSRF before any mutation or HTTP call', static function (): void {
    foreach (['save', 'test_qq', 'test_weixin', 'weixin_start', 'weixin_check', 'weixin_sync', 'weixin_bind', 'weixin_disconnect'] as $operation) {
        fresh();
        $config = weixinBound();
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['operation' => $operation];
        $GLOBALS['csrfValid'] = false;
        raises(static fn() => sblog_push_admin_request(), 'TEST_CSRF_REQUIRED');
        same(1, $GLOBALS['csrfCalls']);
        same($config, sblog_push_config());
        same('weixin-test-secret', sblog_push_read('weixin_account')['token']);
    }
});
test('admin rendering escapes identities and never includes saved secrets', static function (): void {
    $config = weixinBound();
    sblog_push_write('weixin_account', array_replace(sblog_push_read('weixin_account'), ['account_id' => '<script>alert(1)</script>']));
    cachedQQ($config);
    ob_start();
    try { sblog_push_admin($config); $html = (string)ob_get_contents(); }
    finally { ob_end_clean(); }
    foreach (['test-secret', 'weixin-test-secret', 'cached-token', 'session-context'] as $secret) { check(!str_contains($html, $secret), 'Saved secret was rendered: ' . $secret); }
    check(!str_contains($html, '<script>alert(1)</script>'));
    check(str_contains($html, 'csrf_token'));
    check((bool)preg_match('/name="qq_app_secret"[^>]*value=""|name="qq_app_secret"(?![^>]*value=)/', $html));
});

foreach ($checks as $name => $callback) {
    fresh();
    $level = ob_get_level();
    ob_start();
    try { $callback(); $results[] = 'PASS ' . $name; }
    catch (Throwable $exception) { $results[] = 'FAIL ' . $name . "\n     " . $exception->getMessage(); }
    finally { while (ob_get_level() > $level) { ob_end_clean(); } }
}
$failed = count(array_filter($results, static fn(string $result): bool => str_starts_with($result, 'FAIL')));
echo implode("\n", $results) . "\n\n" . count($results) . ' checks, ' . $failed . " failures.\n";
exit($failed === 0 ? 0 : 1);
