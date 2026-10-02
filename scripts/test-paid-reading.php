<?php
declare(strict_types=1);

// Payment service tests use verified-notification fixtures and SQLite. They do
// not contact a gateway, use real merchant credentials, or charge a buyer.
$GLOBALS['pr_test_db'] = new PDO('sqlite::memory:');
$GLOBALS['pr_test_db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pr_test_settings'] = ['site_url' => 'https://blog.example.test/blog'];
$GLOBALS['pr_test_admin'] = false;
$_SESSION = [];
$_COOKIE = ['sblog_paid_reader' => str_repeat('a', 64)];
$_SERVER['REQUEST_METHOD'] = 'GET';

function db(): PDO { return $GLOBALS['pr_test_db']; }
function q(string $sql, array $params = []): PDOStatement
{
    if (!empty($GLOBALS['pr_test_storage_failure']) && str_starts_with($sql, 'INSERT INTO sblog_paid_reading_content')) {
        throw new RuntimeException('Simulated private storage failure.');
    }
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}
function all_rows(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(PDO::FETCH_ASSOC); }
function val(string $sql, array $params = []): mixed { return q($sql, $params)->fetchColumn(); }
function setting(string $name, string $default = ''): string { return (string)($GLOBALS['pr_test_settings'][$name] ?? $default); }
function save_settings(array $values): void { $GLOBALS['pr_test_settings'] = array_replace($GLOBALS['pr_test_settings'], $values); }
function is_admin(): bool { return $GLOBALS['pr_test_admin']; }
function script_url(): string { return '/blog/index.php'; }
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function derive_excerpt(string $value): string { return trim(preg_replace('/\s+/', ' ', strip_tags($value)) ?? ''); }
function is_live_content(array $post): bool { return $post['status'] === 'published' && (int)$post['published_at'] <= time(); }
function content_password_is_unlocked(array $post): bool { return true; }
function render_content_html(array $post): string { return '<p>' . h((string)$post['content']) . '</p>'; }
function fetch_content_by_identifier(string $kind, string $identifier, bool $preview = false): ?array
{
    $post = one('SELECT * FROM posts WHERE kind = ? AND (slug = ? OR id = ?)', [$kind, $identifier, $identifier]);
    return $post !== null && ($preview || is_live_content($post)) ? $post : null;
}
function pr_payment_ready(array $settings, string $channel): bool { return true; }
function send_site_mail(string $recipient, string $subject, string $body): bool { return false; }
function sblog_mail_settings(): array { return ['smtp_enabled' => '1', 'smtp_host' => 'smtp.example.test', 'smtp_port' => '465', 'smtp_from_email' => 'blog@example.test']; }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="test-csrf">'; }
function add_plugin_action(string $hook, callable $callback, int $priority = 10): void {}
function add_plugin_filter(string $hook, callable $callback, int $priority = 10): void {}
define('CACHE_DIR', sys_get_temp_dir() . '/pr-unused-test-cache');
require dirname(__DIR__) . '/plugins/paid-reading/includes/data.php';
require dirname(__DIR__) . '/plugins/paid-reading/includes/recovery.php';
require dirname(__DIR__) . '/plugins/paid-reading/includes/reader.php';
require dirname(__DIR__) . '/plugins/paid-reading/includes/content.php';
require dirname(__DIR__) . '/plugins/paid-reading/includes/public.php';
require dirname(__DIR__) . '/plugins/static-page-cache/plugin.php';

function pr_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Paid reading test failed: ' . $message);
    }
}
function pr_test_order(string $seed, string $channel = 'alipay', int $postId = 1, int $amount = 500): array
{
    $order = ['order_no' => 'PR' . substr(hash('sha256', $seed), 0, 30), 'post_id' => $postId,
        'title' => 'Fixture article', 'buyer_hash' => pr_buyer_hash(), 'amount_cents' => $amount,
        'channel' => $channel, 'merchant_id' => 'merchant-1', 'app_id' => 'app-1',
        'status' => 'pending', 'created_at' => time(), 'expires_at' => time() + 1800];
    q('INSERT INTO sblog_paid_reading_orders(order_no, post_id, title, buyer_hash, amount_cents, channel, merchant_id, app_id, status, created_at, expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)', array_values($order));
    return $order;
}
function pr_test_alipay(array $order, string $trade = 'ali-trade-1'): array
{
    return ['out_trade_no' => $order['order_no'], 'trade_status' => 'TRADE_SUCCESS',
        'total_amount' => pr_format_money((int)$order['amount_cents']),
        'seller_id' => $order['merchant_id'], 'app_id' => $order['app_id'], 'trade_no' => $trade];
}
function pr_test_wechat(array $order, string $trade = 'wx-trade-1'): array
{
    return ['_event_type' => 'TRANSACTION.SUCCESS', 'out_trade_no' => $order['order_no'],
        'trade_state' => 'SUCCESS', 'amount' => ['total' => $order['amount_cents'], 'currency' => 'CNY'],
        'mchid' => $order['merchant_id'], 'appid' => $order['app_id'], 'transaction_id' => $trade];
}
function pr_test_browser_input(bool $enabled = true): void
{
    $GLOBALS['pr_test_admin'] = true;
    $GLOBALS['sblog_current_action'] = 'edit';
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['paid_reading_present' => '1', 'paid_reading_enabled' => $enabled ? '1' : '0', 'paid_reading_price' => '5.00'];
}

pr_install();
db()->exec("CREATE TABLE posts(id INTEGER PRIMARY KEY, title TEXT, slug TEXT, kind TEXT, content TEXT, excerpt TEXT, status TEXT, published_at INTEGER)");
q('INSERT INTO posts VALUES(?,?,?,?,?,?,?,?)', [1, 'Article', 'article', 'post', 'Public text', 'Public text', 'published', time() - 60]);

$case = str_starts_with((string)($argv[1] ?? ''), '--case=') ? substr($argv[1], 7) : '';
if ($case === 'output-buffer') {
    pr_test_browser_input();
    $data = pr_before_save(['content' => 'Free preview' . PR_CONTENT_SEPARATOR . 'private-secret-needle'], ['post_id' => 1]);
    q('UPDATE posts SET content = ? WHERE id = 1', [$data['content']]);
    $GLOBALS['pr_test_admin'] = false;
    $_GET = ['slug' => 'article'];
    pr_protect_request(['action' => 'post']);
    ob_start(static fn(string $html): string => pr_filter_output($html, ['action' => 'post']));
    echo '<main><p>[sblog-paid:' . pr_reference($data['content']) . ']</p></main>';
    ob_end_flush();
    exit;
}
if ($case !== '') {
    register_shutdown_function(static function (): void {
        echo "\nTEST_STATE:" . json_encode(['status' => http_response_code(), 'saved' => !empty($GLOBALS['pr_test_core_saved']),
            'content' => val('SELECT content FROM posts WHERE id = 1')]);
    });
    pr_test_browser_input();
    $data = ['content' => 'Free preview' . "\n\n" . PR_CONTENT_SEPARATOR . "\n\n" . 'private-secret-needle', 'excerpt' => 'private-secret-needle'];
    if ($case === 'storage-failure') {
        $GLOBALS['pr_test_storage_failure'] = true;
    } elseif ($case === 'fenced-separator') {
        $data['content'] = "```php\n" . PR_CONTENT_SEPARATOR . "\nprivate-secret-needle\n```";
    } elseif ($case === 'reply-separator') {
        $data['content'] = "[reply]\n" . PR_CONTENT_SEPARATOR . "\nprivate-secret-needle\n[/reply]";
    } elseif ($case === 'multiple-separators') {
        $data['content'] .= PR_CONTENT_SEPARATOR . 'more-secret';
    } elseif ($case === 'invalid-price') {
        $_POST['paid_reading_price'] = '0.00';
    } elseif ($case === 'api-removal') {
        $data = pr_before_save($data, ['post_id' => 1]);
        q('UPDATE posts SET content = ? WHERE id = 1', [$data['content']]);
        $GLOBALS['sblog_current_action'] = 'sblog_rest_api';
        $_POST = [];
        $data['content'] = 'private-secret-needle';
    }
    // Mimic the core's catch-and-continue behavior. A protection failure must
    // terminate before this code can persist the original sensitive body.
    try {
        $data = pr_before_save($data, ['post_id' => 1]);
    } catch (Throwable) {}
    q('UPDATE posts SET content = ? WHERE id = 1', [$data['content']]);
    $GLOBALS['pr_test_core_saved'] = true;
    exit;
}

try {
    foreach (['0.01' => 1, '5' => 500, '5.1' => 510, '5.10' => 510, '1000000.00' => 100000000] as $input => $expected) {
        pr_test_assert(pr_money_cents((string)$input) === $expected, 'Valid money did not convert to exact cents.');
    }
    foreach (['0', '0.00', '-1', '01.00', '1.001', '1e2', 'NaN', '1000000.01', '1,00'] as $invalid) {
        pr_test_assert(pr_money_cents($invalid) === null, 'Invalid money was accepted: ' . $invalid);
    }
    pr_test_assert(pr_parse_money('0.00') === 0, 'Provider zero amount could not be parsed.');
    $_SERVER['HTTP_HOST'] = 'attacker.example';
    pr_test_assert(str_starts_with(pr_url('paid_reading_notify'), 'https://blog.example.test/blog/index.php?'), 'Callback URL trusted a visitor Host header.');

    $order = pr_test_order('ali-primary');
    $payment = pr_test_alipay($order);
    foreach (['seller_id' => 'wrong', 'app_id' => 'wrong', 'total_amount' => '5.01', 'trade_status' => 'WAIT_BUYER_PAY', 'trade_no' => ''] as $field => $bad) {
        pr_test_assert(!pr_settle_order('alipay', array_replace($payment, [$field => $bad])), 'Mismatched Alipay ' . $field . ' was accepted.');
    }
    pr_test_assert(!pr_can_read(1), 'Pending order granted reading access.');
    pr_test_assert(pr_settle_order('alipay', $payment), 'Valid Alipay payment was rejected.');
    pr_test_assert(pr_can_read(1), 'Verified payment did not grant access.');
    $paidAt = val('SELECT paid_at FROM sblog_paid_reading_orders WHERE order_no = ?', [$order['order_no']]);
    pr_test_assert(pr_settle_order('alipay', $payment), 'Repeated notification was not idempotent.');
    pr_test_assert(val('SELECT paid_at FROM sblog_paid_reading_orders WHERE order_no = ?', [$order['order_no']]) === $paidAt, 'Repeated notification changed the settled timestamp.');
    pr_test_assert(!pr_settle_order('alipay', array_replace($payment, ['trade_no' => 'different-trade'])), 'Settled order accepted a different transaction.');
    pr_test_assert(pr_owned_order($order['order_no']) !== null, 'Buyer could not access their order.');
    $_COOKIE['sblog_paid_reader'] = str_repeat('b', 64);
    pr_test_assert(pr_owned_order($order['order_no']) === null && !pr_can_read(1), 'Another visitor could reuse an order number or entitlement.');
    $_COOKIE['sblog_paid_reader'] = str_repeat('a', 64);
    pr_revoke_order($order['order_no']);
    pr_test_assert(!pr_can_read(1), 'Revoked order still granted access.');
    pr_test_assert(pr_settle_order('alipay', $payment) && !pr_can_read(1), 'Callback retry restored revoked access.');

    $wxOrder = pr_test_order('wechat-primary', 'wechat', 2, 501);
    $wxPayment = pr_test_wechat($wxOrder);
    foreach (['mchid' => 'wrong', 'appid' => 'wrong', 'trade_state' => 'NOTPAY', '_event_type' => 'REFUND.SUCCESS'] as $field => $bad) {
        pr_test_assert(!pr_settle_order('wechat', array_replace($wxPayment, [$field => $bad])), 'Mismatched WeChat ' . $field . ' was accepted.');
    }
    foreach ([['total' => 500, 'currency' => 'CNY'], ['total' => 501, 'currency' => 'USD'], ['total' => 5.01, 'currency' => 'CNY'], ['total' => '501.0', 'currency' => 'CNY']] as $badAmount) {
        pr_test_assert(!pr_settle_order('wechat', array_replace($wxPayment, ['amount' => $badAmount])), 'Invalid WeChat amount or currency was accepted.');
    }
    pr_test_assert(pr_settle_order('wechat', $wxPayment) && pr_can_read(2), 'Valid WeChat payment did not grant access.');
    $duplicate = pr_test_order('duplicate-trade', 'wechat', 3, 501);
    $duplicateRejected = false;
    try { pr_settle_order('wechat', pr_test_wechat($duplicate)); } catch (PDOException) { $duplicateRejected = true; }
    pr_test_assert($duplicateRejected && !pr_can_read(3), 'One gateway transaction paid for multiple orders.');
    pr_test_assert(val('SELECT status FROM sblog_paid_reading_orders WHERE order_no = ?', [$duplicate['order_no']]) === 'pending', 'Duplicate transaction partially settled an order.');

    pr_test_browser_input();
    $source = 'Free preview' . "\n\n" . PR_CONTENT_SEPARATOR . "\n\n" . 'private-secret-needle';
    $filtered = pr_before_save(['content' => $source, 'excerpt' => 'private-secret-needle'], ['post_id' => 1]);
    pr_test_assert(!str_contains($filtered['content'], 'private-secret') && !str_contains($filtered['excerpt'], 'private-secret'), 'Core body or excerpt retained private content.');
    q('UPDATE posts SET content = ?, excerpt = ? WHERE id = 1', [$filtered['content'], $filtered['excerpt']]);
    pr_post_saved(['post_id' => 1, 'data' => $filtered]);
    $reference = pr_reference($filtered['content']);
    $record = pr_content_for_post(1);
    pr_test_assert($record !== null && $record['source_markdown'] === $source && $record['paid_markdown'] === 'private-secret-needle', 'Private source did not survive separation.');
    pr_test_assert((int)$record['post_id'] === 1, 'Saved post was not linked to private content.');
    pr_test_assert((int)val("SELECT COUNT(*) FROM posts WHERE content LIKE '%private-secret%' OR excerpt LIKE '%private-secret%'") === 0, 'SQL-based feeds or search could match the private content.');
    $changed = pr_before_save(['content' => $source . '-updated', 'excerpt' => 'secret'], ['post_id' => 1]);
    pr_test_assert(pr_reference($changed['content']) !== $reference, 'A revision reused mutable private storage.');
    pr_test_assert(pr_record($reference)['source_markdown'] === $source, 'Editing destroyed the previous protected revision.');

    $editorHtml = '<textarea id="content" class="editor-textarea" name="content">' . h($filtered['content']) . '</textarea>';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $restored = pr_filter_output($editorHtml, ['action' => 'edit']);
    pr_test_assert(str_contains($restored, h($source)), 'Administrator editor did not restore source.');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    pr_test_assert(pr_filter_output($editorHtml, ['action' => 'edit']) === $editorHtml, 'Validation-error editor form was overwritten.');
    $GLOBALS['pr_test_admin'] = false;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    pr_test_assert(!str_contains(pr_filter_output($editorHtml, ['action' => 'edit']), 'private-secret'), 'Anonymous editor output exposed protected source.');

    $_GET = ['slug' => 'article'];
    pr_test_assert(spc_session_is_public(), 'Test fixture did not start with a public cache session.');
    pr_protect_request(['action' => 'post']);
    pr_test_assert(!spc_session_is_public(), 'Protected detail request could read a public static cache.');
    pr_release_cache_guard();
    pr_test_assert(spc_session_is_public(), 'Temporary cache guard leaked into subsequent public requests.');
    $rendered = '<main><p>Free preview</p><p>[sblog-paid:' . $reference . ']</p></main>';
    pr_test_assert(str_contains(pr_filter_output($rendered, ['action' => 'post']), '解锁完整内容'), 'Guest did not receive a paywall.');
    $unlock = pr_test_order('content-unlock');
    pr_test_assert(pr_settle_order('alipay', pr_test_alipay($unlock, 'content-unlock-trade')), 'Content unlock fixture could not settle.');
    pr_test_assert(str_contains(pr_filter_output($rendered, ['action' => 'post']), 'private-secret-needle'), 'Buyer could not read the private tail.');
    unset($GLOBALS['paid_reading_detail_post']);
    pr_test_assert(!str_contains(pr_filter_output($rendered, ['action' => 'home']), 'private-secret'), 'Buyer entitlement exposed private content on a public listing.');
    $api = ['id' => 1, 'content' => ['raw' => $filtered['content'], 'rendered' => '<p>[sblog-paid:' . $reference . ']</p>', 'protected' => false], 'excerpt' => ['rendered' => 'Free preview', 'protected' => false]];
    $api = json_decode(pr_filter_output(json_encode($api), ['action' => 'sblog_rest_api']), true);
    pr_test_assert($api['content']['protected'] && $api['excerpt']['protected'], 'Public REST omitted paid-protection flags.');
    pr_test_assert(!str_contains(json_encode($api), 'private-secret') && str_contains($api['content']['raw'], '[sblog-paid:'), 'REST editing exposed or discarded private source.');

    $GLOBALS['sblog_current_action'] = 'sblog_rest_api';
    $_POST = [];
    $kept = pr_before_save(['content' => $filtered['content'], 'excerpt' => 'unsafe-private-excerpt'], ['post_id' => 1]);
    pr_test_assert($kept['content'] === $filtered['content'] && !str_contains($kept['excerpt'], 'unsafe-private'), 'API metadata update changed protection or retained unsafe excerpts.');
    pr_test_browser_input(false);
    $public = pr_before_save(['content' => $source, 'excerpt' => 'Free preview'], ['post_id' => 1]);
    pr_test_assert(str_contains($public['content'], 'private-secret') && pr_reference($public['content']) === null, 'Deliberately disabling protection did not publish the source.');

    q('INSERT INTO sblog_paid_reading_devices(buyer_hash,email,verified_at,expires_at) VALUES(?,?,?,?)', [pr_buyer_hash(), 'buyer@example.test', time(), time() + 600]);
    $created = pr_create_order(one('SELECT * FROM posts WHERE id = 1'), $record, 'alipay');
    pr_test_assert(strlen($created['order_no']) <= 32 && pr_owned_order($created['order_no']) !== null, 'Created order exceeds provider order-number limits or cannot be retrieved.');
    $bufferOutput = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' --case=output-buffer 2>&1', $bufferOutput, $bufferStatus);
    pr_test_assert($bufferStatus === 0 && str_contains(implode("\n", $bufferOutput), '解锁完整内容')
        && !str_contains(implode("\n", $bufferOutput), 'private-secret'), 'Paywall failed inside the core output-buffer handler.');
    foreach (['storage-failure' => 503, 'fenced-separator' => 422, 'reply-separator' => 422, 'multiple-separators' => 422, 'invalid-price' => 422, 'api-removal' => 409] as $failureCase => $expectedStatus) {
        $pipes = [];
        $child = proc_open([PHP_BINARY, __FILE__, '--case=' . $failureCase], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        pr_test_assert(is_resource($child), 'Could not launch failure isolation test.');
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        $exitCode = proc_close($child);
        pr_test_assert($exitCode === 0 && preg_match('/TEST_STATE:(.+)$/m', $output, $matches) === 1, 'Failure isolation test crashed: ' . $errors);
        $state = json_decode($matches[1], true);
        pr_test_assert($state['status'] === $expectedStatus && !$state['saved'] && !str_contains($state['content'], 'private-secret'), 'Core fail-open behavior published sensitive content after ' . $failureCase . '.');
    }
    echo "Paid reading payment and content-protection tests passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
