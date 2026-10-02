<?php
declare(strict_types=1);

function pr_settings(): array
{
    $defaults = [
        'default_price' => '5.00', 'order_ttl' => '30',
        'alipay_enabled' => '0', 'alipay_app_id' => '', 'alipay_seller_id' => '',
        'alipay_private_key' => '', 'alipay_app_cert' => '', 'alipay_public_cert' => '',
        'alipay_root_cert' => '', 'alipay_sandbox' => '0',
        'wechat_enabled' => '0', 'wechat_mch_id' => '', 'wechat_app_id' => '',
        'wechat_private_key' => '', 'wechat_mch_cert' => '', 'wechat_secret_key' => '',
        'wechat_public_cert' => '',
    ];
    foreach ($defaults as $key => $value) {
        $defaults[$key] = setting('paid_reading_' . $key, $value);
    }
    return $defaults;
}

function pr_install(): void
{
    db()->exec("CREATE TABLE IF NOT EXISTS sblog_paid_reading_content (
        reference TEXT PRIMARY KEY,
        post_id INTEGER,
        source_markdown TEXT NOT NULL,
        public_markdown TEXT NOT NULL,
        paid_markdown TEXT NOT NULL,
        price_cents INTEGER NOT NULL CHECK(price_cents > 0),
        updated_at INTEGER NOT NULL
    )");
    db()->exec("CREATE TABLE IF NOT EXISTS sblog_paid_reading_orders (
        order_no TEXT PRIMARY KEY,
        post_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        buyer_hash TEXT NOT NULL,
        amount_cents INTEGER NOT NULL CHECK(amount_cents > 0),
        channel TEXT NOT NULL CHECK(channel IN ('alipay', 'wechat')),
        merchant_id TEXT NOT NULL,
        app_id TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'paid', 'revoked')),
        trade_no TEXT,
        created_at INTEGER NOT NULL,
        expires_at INTEGER NOT NULL,
        paid_at INTEGER,
        UNIQUE(channel, trade_no)
    )");
    db()->exec('CREATE INDEX IF NOT EXISTS idx_pr_reader ON sblog_paid_reading_orders(buyer_hash, post_id, status)');
    db()->exec('CREATE INDEX IF NOT EXISTS idx_pr_created ON sblog_paid_reading_orders(created_at)');
}

function pr_transaction(callable $callback): mixed
{
    $owns = !db()->inTransaction() && empty($GLOBALS['pr_transaction_active']);
    if ($owns) {
        db()->exec('BEGIN IMMEDIATE');
        $GLOBALS['pr_transaction_active'] = true;
    }
    try {
        $result = $callback();
        if ($owns) {
            db()->exec('COMMIT');
        }
        return $result;
    } catch (Throwable $exception) {
        if ($owns) {
            db()->exec('ROLLBACK');
        }
        throw $exception;
    } finally {
        if ($owns) {
            unset($GLOBALS['pr_transaction_active']);
        }
    }
}

function pr_scalar(mixed $value): string
{
    return is_string($value) || is_int($value) ? (string)$value : '';
}

/** Parse yuan without using floating point, including provider amounts of zero. */
function pr_parse_money(string $value): ?int
{
    if (!preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D', trim($value), $match)) {
        return null;
    }
    $cents = (int)$match[1] * 100 + (int)str_pad($match[2] ?? '', 2, '0');
    return $cents <= 100000000 ? $cents : null;
}

function pr_money_cents(string $value): ?int
{
    $cents = pr_parse_money($value);
    return $cents !== null && $cents > 0 ? $cents : null;
}

function pr_format_money(int $cents): string
{
    return intdiv($cents, 100) . '.' . str_pad((string)($cents % 100), 2, '0', STR_PAD_LEFT);
}

/** Never derive callback destinations from a visitor-controlled Host header. */
function pr_base_url(): string
{
    $url = rtrim(setting('site_url', ''), '/');
    $parts = parse_url($url);
    if (!filter_var($url, FILTER_VALIDATE_URL) || !is_array($parts)
        || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return '';
    }
    return $url;
}

function pr_url(string $action, array $params = []): string
{
    $script = pr_base_url() !== '' ? pr_base_url() . '/index.php' : script_url();
    return $script . '?' . http_build_query(['a' => $action] + $params, '', '&', PHP_QUERY_RFC3986);
}

function pr_no_store(): void
{
    if (!headers_sent()) {
        header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');
        header('Vary: Cookie', false);
        header('X-SBlog-Page-Cache: BYPASS');
    }
}

function pr_error(string $message, int $status = 400): never
{
    pr_no_store();
    http_response_code($status);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $message;
    exit;
}

/** A random HttpOnly cookie identifies guest purchases; URLs never grant access. */
function pr_buyer_hash(bool $create = false): string
{
    $token = pr_scalar($_COOKIE['sblog_paid_reader'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) {
        if (!$create || headers_sent() || pr_base_url() === '') {
            return '';
        }
        $token = bin2hex(random_bytes(32));
        $path = (string)(parse_url(pr_base_url(), PHP_URL_PATH) ?: '');
        if (!setcookie('sblog_paid_reader', $token, [
            'expires' => time() + 31536000, 'path' => rtrim($path, '/') . '/',
            'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ])) {
            throw new RuntimeException('Could not set reader cookie.');
        }
        $_COOKIE['sblog_paid_reader'] = $token;
    }
    return hash('sha256', $token);
}

function pr_content_for_post(int $postId): ?array
{
    $post = one('SELECT content FROM posts WHERE id = ?', [$postId]);
    $reference = $post ? pr_reference((string)$post['content']) : null;
    return $reference === null ? null : one('SELECT * FROM sblog_paid_reading_content WHERE reference = ?', [$reference]);
}

function pr_can_read(int $postId): bool
{
    if (is_admin()) {
        return true;
    }
    $buyer = pr_buyer_hash();
    return $buyer !== '' && (bool)val(
        "SELECT 1 FROM sblog_paid_reading_orders WHERE post_id = ? AND buyer_hash = ? AND status = 'paid' LIMIT 1",
        [$postId, $buyer]
    );
}

function pr_owned_order(string $orderNo): ?array
{
    if (!preg_match('/^PR[a-f0-9]{30}$/D', $orderNo)) {
        return null;
    }
    $order = one('SELECT * FROM sblog_paid_reading_orders WHERE order_no = ?', [$orderNo]);
    if ($order === null || (!is_admin() && !hash_equals((string)$order['buyer_hash'], pr_buyer_hash()))) {
        return null;
    }
    return $order;
}

function pr_create_order(array $post, array $record, string $channel): array
{
    $settings = pr_settings();
    if (!in_array($channel, ['alipay', 'wechat'], true) || !pr_payment_ready($settings, $channel)
        || pr_base_url() === '') {
        throw new DomainException('所选支付方式尚未配置完成。');
    }
    if (!is_live_content($post) || !content_password_is_unlocked($post)) {
        throw new DomainException('此内容暂时无法购买。');
    }
    $buyer = pr_buyer_hash();
    if ($buyer === '') {
        throw new DomainException('请允许浏览器 Cookie，重新打开文章后购买。');
    }
    return pr_transaction(static function () use ($post, $record, $channel, $settings, $buyer): array {
        if ((int)val('SELECT COUNT(*) FROM sblog_paid_reading_orders WHERE buyer_hash = ? AND created_at > ?', [$buyer, time() - 60]) >= 5) {
            throw new DomainException('创建订单过于频繁，请一分钟后重试。');
        }
        $order = [
            'order_no' => 'PR' . bin2hex(random_bytes(15)), 'post_id' => (int)$post['id'],
            'title' => mb_substr((string)$post['title'], 0, 80, 'UTF-8'), 'buyer_hash' => $buyer,
            'amount_cents' => (int)$record['price_cents'], 'channel' => $channel,
            'merchant_id' => $channel === 'alipay' ? $settings['alipay_seller_id'] : $settings['wechat_mch_id'],
            'app_id' => $channel === 'alipay' ? $settings['alipay_app_id'] : $settings['wechat_app_id'],
            'status' => 'pending', 'created_at' => time(),
            'expires_at' => time() + max(5, min(120, (int)$settings['order_ttl'])) * 60,
        ];
        q('INSERT INTO sblog_paid_reading_orders(order_no, post_id, title, buyer_hash, amount_cents, channel, merchant_id, app_id, status, created_at, expires_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)', array_values($order));
        return $order;
    });
}

/** Called only with a signature-verified, decrypted SDK notification. */
function pr_settle_order(string $channel, array $payment): bool
{
    $orderNo = pr_scalar($payment['out_trade_no'] ?? '');
    return pr_transaction(static function () use ($channel, $payment, $orderNo): bool {
        $order = one('SELECT * FROM sblog_paid_reading_orders WHERE order_no = ? AND channel = ?', [$orderNo, $channel]);
        if ($order === null) {
            return false;
        }
        if ($channel === 'alipay') {
            $successful = in_array(pr_scalar($payment['trade_status'] ?? ''), ['TRADE_SUCCESS', 'TRADE_FINISHED'], true);
            $amount = pr_parse_money(pr_scalar($payment['total_amount'] ?? ''));
            $merchant = pr_scalar($payment['seller_id'] ?? '');
            $app = pr_scalar($payment['app_id'] ?? '');
            $trade = pr_scalar($payment['trade_no'] ?? '');
        } elseif ($channel === 'wechat') {
            $successful = pr_scalar($payment['trade_state'] ?? '') === 'SUCCESS'
                && pr_scalar($payment['_event_type'] ?? '') === 'TRANSACTION.SUCCESS';
            $total = pr_scalar($payment['amount']['total'] ?? null);
            $amount = preg_match('/^[0-9]{1,9}$/D', $total) ? (int)$total : null;
            $successful = $successful && pr_scalar($payment['amount']['currency'] ?? '') === 'CNY';
            $merchant = pr_scalar($payment['mchid'] ?? '');
            $app = pr_scalar($payment['appid'] ?? '');
            $trade = pr_scalar($payment['transaction_id'] ?? '');
        } else {
            return false;
        }
        if (!$successful || $amount !== (int)$order['amount_cents'] || $trade === '' || strlen($trade) > 100
            || !hash_equals((string)$order['merchant_id'], $merchant) || !hash_equals((string)$order['app_id'], $app)) {
            return false;
        }
        if ($order['status'] === 'paid' || $order['status'] === 'revoked') {
            // A retried callback cannot restore an entitlement revoked by the administrator.
            return hash_equals((string)$order['trade_no'], $trade);
        }
        q("UPDATE sblog_paid_reading_orders SET status = 'paid', trade_no = ?, paid_at = ? WHERE order_no = ? AND status = 'pending'", [$trade, time(), $orderNo]);
        return true;
    });
}

function pr_revoke_order(string $orderNo): void
{
    $statement = q("UPDATE sblog_paid_reading_orders SET status = 'revoked' WHERE order_no = ? AND status = 'paid'", [$orderNo]);
    if ($statement->rowCount() !== 1) {
        throw new DomainException('订单不存在或没有可撤销的阅读权限。');
    }
}

function pr_orders(int $page = 1): array
{
    $total = (int)val('SELECT COUNT(*) FROM sblog_paid_reading_orders');
    $perPage = 20;
    $page = max(1, min(max(1, (int)ceil($total / $perPage)), $page));
    $offset = ($page - 1) * $perPage;
    return ['items' => all_rows("SELECT * FROM sblog_paid_reading_orders ORDER BY created_at DESC, order_no DESC LIMIT 20 OFFSET {$offset}"),
        'total' => $total, 'page' => $page, 'per_page' => $perPage];
}
