<?php
declare(strict_types=1);

// Isolated SQLite fixtures capture every outgoing message. These tests never
// connect to SMTP, contact a payment gateway, or send mail to a real recipient.
$GLOBALS['pr_recovery_db'] = new PDO('sqlite::memory:');
$GLOBALS['pr_recovery_db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pr_recovery_settings'] = ['site_url' => 'https://blog.example.test/blog', 'site_name' => 'Fixture blog'];
$GLOBALS['pr_recovery_admin'] = false;
$GLOBALS['pr_recovery_mail'] = [];
$GLOBALS['pr_recovery_mail_result'] = true;
$GLOBALS['pr_recovery_smtp'] = [];
$_SESSION = [];
$_COOKIE = ['sblog_paid_reader' => str_repeat('a', 64)];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';

function db(): PDO { return $GLOBALS['pr_recovery_db']; }
function q(string $sql, array $params = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement;
}
function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}
function val(string $sql, array $params = []): mixed { return q($sql, $params)->fetchColumn(); }
function all_rows(string $sql, array $params = []): array { return q($sql, $params)->fetchAll(PDO::FETCH_ASSOC); }
function setting(string $name, string $default = ''): string { return (string)($GLOBALS['pr_recovery_settings'][$name] ?? $default); }
function is_admin(): bool { return $GLOBALS['pr_recovery_admin']; }
function script_url(): string { return '/blog/index.php'; }
function is_live_content(array $post): bool { return $post['status'] === 'published' && (int)$post['published_at'] <= time(); }
function content_password_is_unlocked(array $post): bool { return true; }
function content_permalink(array $post): string { return '/blog/index.php?a=post&slug=' . rawurlencode((string)$post['slug']); }
function pr_payment_ready(array $settings, string $channel): bool { return true; }
function sblog_mail_settings(): array
{
    return array_replace(['smtp_enabled' => '1', 'smtp_host' => 'smtp.example.test', 'smtp_port' => '465', 'smtp_from_email' => 'noreply@example.test'], $GLOBALS['pr_recovery_smtp']);
}
function send_site_mail(string $recipient, string $subject, string $body): bool
{
    $GLOBALS['pr_recovery_mail'][] = ['recipient' => $recipient, 'subject' => $subject, 'body' => $body];
    if ($GLOBALS['pr_recovery_mail_result'] === 'throw') {
        throw new RuntimeException('Simulated mail transport error.');
    }
    return $GLOBALS['pr_recovery_mail_result'] === true;
}
require dirname(__DIR__) . '/plugins/paid-reading/includes/data.php';
require dirname(__DIR__) . '/plugins/paid-reading/includes/recovery.php';

function recovery_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
function recovery_rejected(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (DomainException) {
        return;
    }
    throw new RuntimeException($message);
}
function recovery_browser(string $seed, string $address = '192.0.2.1'): void
{
    $_COOKIE = ['sblog_paid_reader' => hash('sha256', $seed)];
    $_SESSION = [];
    $_SERVER['REMOTE_ADDR'] = $address;
    unset($_SERVER['HTTP_X_FORWARDED_FOR']);
}
function recovery_reset(): void
{
    $GLOBALS['pr_recovery_db'] = new PDO('sqlite::memory:');
    db()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $GLOBALS['pr_recovery_admin'] = false;
    $GLOBALS['pr_recovery_mail'] = [];
    $GLOBALS['pr_recovery_mail_result'] = true;
    $GLOBALS['pr_recovery_smtp'] = [];
    recovery_browser('original-browser');
    pr_install();
    db()->exec('CREATE TABLE posts(id INTEGER PRIMARY KEY, title TEXT, slug TEXT, kind TEXT, status TEXT, published_at INTEGER)');
    foreach (range(1, 12) as $id) {
        q('INSERT INTO posts VALUES(?,?,?,?,?,?)', [$id, 'Fixture article ' . $id, 'article-' . $id, 'post', 'published', time() - 60]);
    }
}
function recovery_order(string $seed, int $postId, string $status = 'paid', string $email = '', ?string $buyer = null): array
{
    $order = [
        'order_no' => 'PR' . substr(hash('sha256', $seed), 0, 30), 'post_id' => $postId, 'title' => 'Fixture article',
        'buyer_hash' => $buyer ?? pr_buyer_hash(), 'buyer_email' => $email, 'amount_cents' => 500,
        'channel' => 'alipay', 'merchant_id' => 'merchant-1', 'app_id' => 'app-1', 'status' => $status,
        'created_at' => time() - 120, 'expires_at' => time() + 1800,
    ];
    q('INSERT INTO sblog_paid_reading_orders(order_no,post_id,title,buyer_hash,buyer_email,amount_cents,channel,merchant_id,app_id,status,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)', array_values($order));
    return $order;
}
function recovery_mail_code(): string
{
    $message = $GLOBALS['pr_recovery_mail'][array_key_last($GLOBALS['pr_recovery_mail'])] ?? [];
    recovery_assert(preg_match('/验证码是：([0-9]{8})/', (string)($message['body'] ?? ''), $matches) === 1, 'Mail did not contain an eight-digit recovery code.');
    return $matches[1];
}
function recovery_allow_resend(): void
{
    // Move only cooldown timestamps. Hourly hit counters remain intact.
    q('UPDATE sblog_paid_reading_recovery_rate SET last_sent = ?', [time() - 61]);
}
function recovery_prove(string $email): string
{
    recovery_allow_resend();
    $id = pr_request_email_code($email);
    return pr_verify_email_code($id, recovery_mail_code());
}

try {
    // Start from the released 1.0.1 order schema, then run the upgrade twice.
    recovery_browser('original-browser');
    $originalHash = hash('sha256', $_COOKIE['sblog_paid_reader']);
    db()->exec("CREATE TABLE sblog_paid_reading_orders (
        order_no TEXT PRIMARY KEY, post_id INTEGER NOT NULL, title TEXT NOT NULL,
        buyer_hash TEXT NOT NULL, amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
        channel TEXT NOT NULL CHECK(channel IN ('alipay','wechat')), merchant_id TEXT NOT NULL,
        app_id TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','paid','revoked')),
        trade_no TEXT, created_at INTEGER NOT NULL, expires_at INTEGER NOT NULL, paid_at INTEGER,
        UNIQUE(channel, trade_no)
    )");
    $oldOrderNo = 'PR' . str_repeat('1', 30);
    q('INSERT INTO sblog_paid_reading_orders(order_no,post_id,title,buyer_hash,amount_cents,channel,merchant_id,app_id,status,created_at,expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)',
        [$oldOrderNo, 1, 'Purchased before upgrade', $originalHash, 500, 'alipay', 'merchant-1', 'app-1', 'paid', time() - 120, time() - 60]);
    pr_install();
    pr_install();
    recovery_assert((int)val('SELECT COUNT(*) FROM sblog_paid_reading_orders') === 1, 'Schema upgrade changed existing order count.');
    $migrated = one('SELECT * FROM sblog_paid_reading_orders WHERE order_no = ?', [$oldOrderNo]);
    recovery_assert($migrated['buyer_email'] === '' && $migrated['buyer_hash'] === $originalHash && $migrated['status'] === 'paid', 'Schema upgrade changed legacy payment ownership.');
    recovery_assert(pr_can_read(1), 'A legacy purchaser lost reading access after upgrade.');
    recovery_browser('other-browser');
    recovery_assert(!pr_can_read(1) && pr_owned_order($oldOrderNo) === null, 'An order number granted another browser legacy access.');

    recovery_reset();
    $originalHash = pr_buyer_hash();
    $legacy = recovery_order('legacy-paid', 1);
    $oldPending = recovery_order('legacy-pending', 2, 'pending');
    $untouchedPending = recovery_order('legacy-unpaid', 10, 'pending');
    $oldRevoked = recovery_order('legacy-revoked', 3, 'revoked');
    $previouslyBound = recovery_order('already-bound', 4, 'paid', 'existing@example.test');
    $foreign = recovery_order('foreign-legacy', 6, 'paid', '', hash('sha256', hash('sha256', 'foreign-browser')));
    $post = one('SELECT * FROM posts WHERE id = 5');
    recovery_rejected(static fn() => pr_create_order($post, ['price_cents' => 700], 'alipay'), 'An unverified email was allowed to purchase.');
    recovery_rejected(static fn() => pr_bind_legacy_orders('buyer@example.test', pr_buyer_hash()), 'Unverified address claimed legacy purchases.');
    $id = pr_request_email_code('  Buyer@Example.test  ');
    $code = recovery_mail_code();
    $storedChallenge = one('SELECT * FROM sblog_paid_reading_challenges WHERE id = ?', [$id]);
    recovery_assert($GLOBALS['pr_recovery_mail'][0]['recipient'] === 'buyer@example.test', 'Email normalization differed from delivery address.');
    recovery_assert(password_verify($code, $storedChallenge['code_hash']) && $storedChallenge['code_hash'] !== $code, 'Recovery code was not stored with a password hash.');
    recovery_assert(!array_key_exists('code_hash', pr_email_challenge($id)), 'Public challenge metadata exposed its verification hash.');
    recovery_assert(!pr_can_read(5) && pr_verified_email() === '', 'Sending a code granted access before verification.');
    recovery_assert(pr_verify_email_code($id, $code) === 'buyer@example.test', 'Valid mailbox proof did not verify the normalized address.');
    recovery_assert(pr_verified_email() === 'buyer@example.test' && pr_buyer_hash() === $originalHash, 'Mailbox proof replaced the legacy browser identity.');
    recovery_assert(val('SELECT buyer_email FROM sblog_paid_reading_orders WHERE order_no = ?', [$legacy['order_no']]) === 'buyer@example.test', 'Owned paid legacy purchase was not bound.');
    foreach ([$oldPending, $oldRevoked, $foreign] as $unbound) {
        recovery_assert(val('SELECT buyer_email FROM sblog_paid_reading_orders WHERE order_no = ?', [$unbound['order_no']]) === '', 'Unpaid, revoked, or another browser order was bound.');
    }
    recovery_assert(val('SELECT buyer_email FROM sblog_paid_reading_orders WHERE order_no = ?', [$previouslyBound['order_no']]) === 'existing@example.test', 'Verification reassigned an already-bound order.');
    q('INSERT INTO sblog_paid_reading_devices(buyer_hash,email,verified_at,expires_at) VALUES(?,?,?,?)', [$foreign['buyer_hash'], 'foreign@example.test', time(), time() + 3600]);
    recovery_rejected(static fn() => pr_bind_legacy_orders('foreign@example.test', $foreign['buyer_hash']), 'A verified row from a different browser bypassed legacy ownership proof.');
    $created = pr_create_order($post, ['price_cents' => 700], 'alipay');
    recovery_assert($created['buyer_email'] === 'buyer@example.test', 'New order omitted its verified recovery email.');
    recovery_assert(!pr_can_read(5), 'Pending email-bound purchase granted reading access.');
    recovery_assert(pr_settle_order('alipay', [
        'out_trade_no' => $created['order_no'], 'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '7.00',
        'seller_id' => $created['merchant_id'], 'app_id' => $created['app_id'], 'trade_no' => 'fixture-new-email-payment',
    ]), 'Fixture payment could not settle.');
    recovery_rejected(static fn() => pr_verify_email_code($id, $code), 'A consumed code was replayed.');
    recovery_order('email-revoked', 7, 'revoked', 'buyer@example.test');
    recovery_order('email-pending', 8, 'pending', 'buyer@example.test');

    // A fresh device must independently prove the mailbox before viewing orders.
    recovery_browser('new-device', '192.0.2.2');
    recovery_assert(!pr_can_read(1) && !pr_can_read(5) && pr_owned_order($created['order_no']) === null, 'Fresh device inherited another device identity.');
    recovery_prove('unrelated@example.test');
    recovery_assert(!pr_can_read(1) && !pr_can_read(5), 'An unrelated verified mailbox inherited purchases.');
    recovery_allow_resend();
    $newId = pr_request_email_code('BUYER@example.test');
    $newCode = recovery_mail_code();
    recovery_browser('attacker-device', '192.0.2.3');
    recovery_assert(pr_email_challenge($newId) === null, 'Another browser could inspect a challenge.');
    recovery_rejected(static fn() => pr_verify_email_code($newId, $newCode), 'A correct code worked in a browser that did not request it.');
    recovery_browser('new-device', '192.0.2.2');
    recovery_assert(pr_verify_email_code($newId, $newCode) === 'buyer@example.test', 'Mailbox recovery on the requesting device failed.');
    $_SESSION = [];
    recovery_assert(pr_verified_email() === 'buyer@example.test' && pr_can_read(1) && pr_can_read(5), 'Recovered access did not persist beyond the PHP session.');
    recovery_assert(pr_owned_order($legacy['order_no']) !== null && pr_owned_order($created['order_no']) !== null, 'Verified mailbox could not retrieve its purchased orders.');
    recovery_assert(!pr_can_read(2) && !pr_can_read(3) && !pr_can_read(6) && !pr_can_read(7) && !pr_can_read(8), 'Recovery granted pending, revoked, or unowned articles.');
    $purchases = pr_reader_purchases();
    $purchaseIds = array_column($purchases, 'post_id');
    sort($purchaseIds);
    recovery_assert($purchaseIds === [1, 5], 'Recovered purchase library included unpaid or unrelated orders.');
    $_POST['buyer_email'] = 'attacker@example.test';
    recovery_assert(pr_settle_order('alipay', [
        'out_trade_no' => $oldPending['order_no'], 'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '5.00',
        'seller_id' => $oldPending['merchant_id'], 'app_id' => $oldPending['app_id'], 'trade_no' => 'fixture-delayed-legacy-payment',
        'buyer_email' => 'attacker@example.test',
    ]), 'Delayed legacy fixture payment could not settle.');
    recovery_assert(val('SELECT buyer_email FROM sblog_paid_reading_orders WHERE order_no = ?', [$oldPending['order_no']]) === 'buyer@example.test'
        && val('SELECT buyer_hash FROM sblog_paid_reading_orders WHERE order_no = ?', [$oldPending['order_no']]) === $originalHash
        && pr_can_read(2), 'Delayed legacy payment used callback email or lost its original device proof.');
    $_POST = [];
    pr_revoke_order($created['order_no']);
    recovery_assert(!pr_can_read(5), 'Revocation did not invalidate recovered reading access.');
    q('UPDATE sblog_paid_reading_devices SET expires_at = ? WHERE buyer_hash = ?', [time(), pr_buyer_hash()]);
    recovery_assert(pr_verified_email() === '' && !pr_can_read(1), 'Expired device verification retained recovered reading access.');

    // Administrator assistance is limited to paid legacy orders without email.
    recovery_rejected(static fn() => pr_bind_order_email_admin($foreign['order_no'], 'helped@example.test'), 'A reader could manually claim a foreign legacy order.');
    $GLOBALS['pr_recovery_admin'] = true;
    pr_bind_order_email_admin($foreign['order_no'], 'HELPED@example.test');
    recovery_assert(val('SELECT buyer_email FROM sblog_paid_reading_orders WHERE order_no = ?', [$foreign['order_no']]) === 'helped@example.test', 'Administrator legacy assistance failed.');
    recovery_rejected(static fn() => pr_bind_order_email_admin($foreign['order_no'], 'other@example.test'), 'Administrator assistance overwrote an established binding.');
    recovery_rejected(static fn() => pr_bind_order_email_admin($oldRevoked['order_no'], 'helped@example.test'), 'Administrator assistance bound a revoked order.');
    recovery_rejected(static fn() => pr_bind_order_email_admin($untouchedPending['order_no'], 'helped@example.test'), 'Administrator assistance bound an unpaid order.');
    $GLOBALS['pr_recovery_admin'] = false;

    // Invalid input, expiry, retry limits, and replacement cannot grant a device.
    recovery_reset();
    foreach (["buyer@example.test\r\nBcc: victim@example.test", "buyer@example.test\n", '', 'not-an-email', str_repeat('a', 255) . '@example.test'] as $invalid) {
        recovery_rejected(static fn() => pr_request_email_code($invalid), 'An invalid or injected mailbox was accepted.');
    }
    recovery_assert($GLOBALS['pr_recovery_mail'] === [], 'Invalid mailbox input triggered a mail send.');
    $id = pr_request_email_code('expiry@example.test');
    $code = recovery_mail_code();
    q('UPDATE sblog_paid_reading_challenges SET expires_at = ? WHERE id = ?', [time(), $id]);
    recovery_rejected(static fn() => pr_verify_email_code($id, $code), 'An expired code was accepted.');
    recovery_allow_resend();
    $id = pr_request_email_code('attempts@example.test');
    $code = recovery_mail_code();
    $wrongCode = $code === '00000000' ? '00000001' : '00000000';
    foreach ([$wrongCode, 'x', '123', "12345678\n9", $wrongCode] as $invalidCode) {
        recovery_rejected(static fn() => pr_verify_email_code($id, $invalidCode), 'An incorrect or malformed code was accepted.');
    }
    $locked = one('SELECT * FROM sblog_paid_reading_challenges WHERE id = ?', [$id]);
    recovery_assert((int)$locked['attempts'] === 5 && $locked['consumed_at'] !== null, 'Five failed guesses did not persistently lock the challenge.');
    recovery_rejected(static fn() => pr_verify_email_code($id, $code), 'A correct code bypassed the attempt limit.');
    recovery_assert(pr_verified_email() === '', 'Invalid attempts granted a verified identity.');
    recovery_allow_resend();
    $first = pr_request_email_code('first@example.test');
    $firstCode = recovery_mail_code();
    recovery_allow_resend();
    $second = pr_request_email_code('second@example.test');
    $secondCode = recovery_mail_code();
    recovery_rejected(static fn() => pr_verify_email_code($first, $firstCode), 'A superseded browser challenge remained usable after changing email.');
    recovery_assert(pr_verify_email_code($second, $secondCode) === 'second@example.test', 'Latest browser challenge did not remain usable.');
    recovery_assert(pr_email_challenge('invalid') === null, 'Malformed challenge identifier was accepted.');
    recovery_prove('z-latest@example.test');
    // Two successful validations may occur within the same second. Purchasing
    // must use the address most recently validated rather than alphabetic order.
    q('UPDATE sblog_paid_reading_devices SET verified_at = ?', [time()]);
    $latestOrder = pr_create_order(one('SELECT * FROM posts WHERE id = 9'), ['price_cents' => 500], 'alipay');
    recovery_assert($latestOrder['buyer_email'] === 'z-latest@example.test', 'Same-second mailbox switch used a stale purchasing address.');
    recovery_prove('second@example.test');
    q('UPDATE sblog_paid_reading_devices SET verified_at = ?', [time()]);
    recovery_assert(pr_verified_email() === 'second@example.test', 'Revalidating an existing mailbox did not make it current.');

    recovery_reset();
    q('INSERT INTO sblog_paid_reading_devices(buyer_hash,email,verified_at,expires_at) VALUES(?,?,?,?)', [pr_buyer_hash(), 'unconfigured@example.test', time(), time() + 3600]);
    foreach ([['smtp_enabled' => '0'], ['smtp_host' => ''], ['smtp_host' => "smtp.example.test\n" . 'injected'], ['smtp_port' => '65536'], ['smtp_from_email' => 'invalid']] as $badSmtp) {
        $GLOBALS['pr_recovery_smtp'] = $badSmtp;
        recovery_assert(!pr_recovery_mail_ready(), 'Invalid SMTP setup advertised recovery readiness.');
        recovery_rejected(static fn() => pr_request_email_code('unconfigured@example.test'), 'An unconfigured mail transport issued a challenge.');
        recovery_rejected(static fn() => pr_create_order(one('SELECT * FROM posts WHERE id = 1'), ['price_cents' => 500], 'alipay'), 'Direct order creation bypassed unavailable recovery mail.');
    }
    recovery_assert((int)val('SELECT COUNT(*) FROM sblog_paid_reading_challenges') === 0
        && (int)val('SELECT COUNT(*) FROM sblog_paid_reading_recovery_rate') === 0
        && $GLOBALS['pr_recovery_mail'] === [], 'Unconfigured mail consumed recovery quotas or attempted delivery.');
    $GLOBALS['pr_recovery_smtp'] = [];
    recovery_assert(pr_recovery_mail_ready(), 'Configured fixture transport was not considered ready.');

    // Logging out this browser does not delete purchases or another device.
    recovery_reset();
    $logoutOrder = recovery_order('logout-purchase', 1);
    recovery_prove('logout@example.test');
    $firstDevice = pr_buyer_hash();
    recovery_browser('logout-second-device', '192.0.2.2');
    recovery_prove('logout@example.test');
    $secondDevice = pr_buyer_hash();
    recovery_allow_resend();
    $logoutChallenge = pr_request_email_code('pending-at-logout@example.test');
    $logoutCode = recovery_mail_code();
    pr_forget_reader();
    recovery_assert(pr_buyer_hash() === '' && pr_verified_email() === '' && !pr_can_read(1), 'Logging out retained a browser credential or reading grant.');
    recovery_assert((int)val('SELECT COUNT(*) FROM sblog_paid_reading_devices WHERE buyer_hash = ?', [$secondDevice]) === 0, 'Logging out retained this device email grants.');
    recovery_assert(val('SELECT consumed_at FROM sblog_paid_reading_challenges WHERE id = ?', [$logoutChallenge]) !== null, 'Logging out retained a pending recovery challenge.');
    recovery_rejected(static fn() => pr_verify_email_code($logoutChallenge, $logoutCode), 'A logged-out challenge restored access.');
    recovery_assert((int)val('SELECT COUNT(*) FROM sblog_paid_reading_devices WHERE buyer_hash = ?', [$firstDevice]) === 1
        && val('SELECT status FROM sblog_paid_reading_orders WHERE order_no = ?', [$logoutOrder['order_no']]) === 'paid', 'Logging out removed another device or the purchase itself.');
    recovery_browser('original-browser');
    recovery_assert(pr_can_read(1), 'Logging out another device invalidated the original buyer.');
    recovery_browser('logout-new-cookie', '192.0.2.3');
    recovery_prove('logout@example.test');
    recovery_assert(pr_can_read(1), 'A logged-out purchaser could not later restore from their mailbox.');
    recovery_browser('original-browser');
    $originalCookie = $_COOKIE['sblog_paid_reader'];
    pr_forget_reader();
    $_COOKIE['sblog_paid_reader'] = $originalCookie;
    recovery_assert(pr_buyer_hash() === '' && !pr_can_read(1), 'Replaying the logged-out original buyer cookie restored its direct order access.');
    recovery_browser('logout-new-cookie', '192.0.2.3');
    recovery_assert(pr_can_read(1), 'Retiring the original buyer cookie invalidated another verified device.');

    foreach (['expired', 'retired'] as $lostProof) {
        recovery_reset();
        $delayedOrder = recovery_order('late-' . $lostProof, 1, 'pending');
        q('INSERT INTO sblog_paid_reading_devices(buyer_hash,email,verified_at,expires_at) VALUES(?,?,?,?)',
            [pr_buyer_hash(), 'original@example.test', time() - 60, $lostProof === 'expired' ? time() : time() + 3600]);
        if ($lostProof === 'retired') {
            pr_forget_reader();
        }
        recovery_browser('callback-browser-' . $lostProof, '192.0.2.9');
        q('INSERT INTO sblog_paid_reading_devices(buyer_hash,email,verified_at,expires_at) VALUES(?,?,?,?)',
            [pr_buyer_hash(), 'unrelated@example.test', time(), time() + 3600]);
        recovery_assert(pr_settle_order('alipay', [
            'out_trade_no' => $delayedOrder['order_no'], 'trade_status' => 'TRADE_SUCCESS', 'total_amount' => '5.00',
            'seller_id' => $delayedOrder['merchant_id'], 'app_id' => $delayedOrder['app_id'], 'trade_no' => 'late-trade-' . $lostProof,
        ]), 'A delayed payment failed to settle after original device proof ended.');
        recovery_assert(val('SELECT buyer_email FROM sblog_paid_reading_orders WHERE order_no = ?', [$delayedOrder['order_no']]) === ''
            && !pr_can_read(1), 'A delayed payment bound an expired, retired, or callback-browser email.');
    }

    // Email and IP limits persist across fresh cookies and spoofed proxy headers.
    recovery_reset();
    $id = pr_request_email_code('rate@example.test');
    recovery_browser('cooldown-other-browser', '192.0.2.9');
    recovery_rejected(static fn() => pr_request_email_code('RATE@example.test'), 'Fresh cookie bypassed the email cooldown.');
    recovery_browser('cooldown-original-ip', '192.0.2.1');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.200';
    recovery_rejected(static fn() => pr_request_email_code('different@example.test'), 'Changing email and proxy header bypassed the IP cooldown.');
    foreach (range(2, 5) as $hit) {
        recovery_allow_resend();
        recovery_browser('email-cap-' . $hit, '192.0.2.' . (10 + $hit));
        pr_request_email_code('rate@example.test');
    }
    recovery_allow_resend();
    recovery_browser('email-over-cap', '192.0.2.30');
    recovery_rejected(static fn() => pr_request_email_code('rate@example.test'), 'More than five messages per hour were sent to one mailbox.');
    recovery_assert(count($GLOBALS['pr_recovery_mail']) === 5, 'Rejected email rate requests still sent messages.');

    recovery_reset();
    foreach (range(1, 20) as $hit) {
        recovery_allow_resend();
        recovery_browser('ip-cap-' . $hit, '2001:db8::1');
        pr_request_email_code('ip-' . $hit . '@example.test');
    }
    recovery_allow_resend();
    recovery_browser('ip-over-cap', '2001:0db8:0000:0000:0000:0000:0000:0001');
    recovery_rejected(static fn() => pr_request_email_code('ip-over@example.test'), 'Equivalent IPv6 notation bypassed the IP hourly limit.');
    recovery_assert(count($GLOBALS['pr_recovery_mail']) === 20, 'Rejected IP cap request sent a message.');
    q('UPDATE sblog_paid_reading_recovery_rate SET window_start = ?, last_sent = ?', [time() - 3601, time() - 61]);
    pr_request_email_code('ip-after-window@example.test');
    recovery_assert(count($GLOBALS['pr_recovery_mail']) === 21, 'A fresh rate window did not permit a later recovery message.');

    // A transport failure consumes the code and keeps its reserved rate quota.
    foreach ([false, 'throw'] as $mailFailure) {
        recovery_reset();
        $GLOBALS['pr_recovery_mail_result'] = $mailFailure;
        recovery_rejected(static fn() => pr_request_email_code('failure@example.test'), 'A failed transport reported a usable challenge.');
        $failedCode = recovery_mail_code();
        $failedChallenge = one('SELECT * FROM sblog_paid_reading_challenges');
        recovery_assert($failedChallenge['consumed_at'] !== null, 'A failed send left a valid recovery code.');
        recovery_rejected(static fn() => pr_verify_email_code($failedChallenge['id'], $failedCode), 'A code from a failed send verified the mailbox.');
        $GLOBALS['pr_recovery_mail_result'] = true;
        recovery_rejected(static fn() => pr_request_email_code('failure@example.test'), 'Mail failure bypassed the reserved cooldown.');
        recovery_assert(count($GLOBALS['pr_recovery_mail']) === 1 && pr_verified_email() === '', 'Failed sends granted identity or retried without cooldown.');
    }

    echo "Paid reading email recovery and migration tests passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Paid reading recovery test failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
