<?php
declare(strict_types=1);

/** One canonical address is used for sending mail, order binding and recovery. */
function pr_normalize_email(string $email): ?string
{
    if (str_contains($email, "\r") || str_contains($email, "\n")) {
        return null;
    }
    $email = strtolower(trim($email));
    if ($email === '' || strlen($email) > 254 || preg_match('/[^\x20-\x7e]/', $email)
        || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return null;
    }
    return $email;
}

/** SMTP configuration is required before promising cross-device recovery. */
function pr_recovery_mail_ready(): bool
{
    if (!function_exists('send_site_mail') || !function_exists('sblog_mail_settings')) {
        return false;
    }
    try {
        $settings = sblog_mail_settings();
    } catch (Throwable $exception) {
        return false;
    }
    $host = trim(pr_scalar($settings['smtp_host'] ?? ''));
    $port = pr_scalar($settings['smtp_port'] ?? '');
    return ($settings['smtp_enabled'] ?? '') === '1' && $host !== ''
        && !preg_match('/[\x00-\x20\x7f]/', $host)
        && preg_match('/^[0-9]{1,5}$/D', $port) && (int)$port >= 1 && (int)$port <= 65535
        && pr_normalize_email(pr_scalar($settings['smtp_from_email'] ?? '')) !== null;
}

/** An email is trusted only after its owner proves possession on this browser. */
function pr_verified_email(): string
{
    $buyer = pr_buyer_hash();
    if ($buyer === '') {
        return '';
    }
    $email = val(
        'SELECT email FROM sblog_paid_reading_devices WHERE buyer_hash = ? AND expires_at > ? ORDER BY verified_at DESC, rowid DESC LIMIT 1',
        [$buyer, time()]
    );
    return is_string($email) ? (pr_normalize_email($email) ?? '') : '';
}

/** Return displayable challenge metadata without exposing its verification hash. */
function pr_email_challenge(string $id): ?array
{
    if (!preg_match('/^[a-f0-9]{32}$/D', $id)) {
        return null;
    }
    $buyer = pr_buyer_hash();
    return $buyer === '' ? null : one(
        'SELECT id, buyer_hash, email, attempts, created_at, expires_at, consumed_at FROM sblog_paid_reading_challenges WHERE id = ? AND buyer_hash = ?',
        [$id, $buyer]
    );
}

/** Send a short-lived code, with shared email and connection-address limits. */
function pr_request_email_code(string $email): string
{
    $email = pr_normalize_email($email);
    if ($email === null) {
        throw new DomainException('请填写有效的邮箱地址。');
    }
    if (!pr_recovery_mail_ready()) {
        throw new DomainException('站点暂时无法发送验证码，请联系站长配置邮件服务。');
    }
    try {
        $buyer = pr_buyer_hash(true);
    } catch (RuntimeException $exception) {
        throw new DomainException('请允许浏览器 Cookie，重新打开页面后再试。');
    }
    if ($buyer === '') {
        throw new DomainException('请允许浏览器 Cookie，重新打开页面后再试。');
    }

    // Proxy headers are visitor-controlled unless the host explicitly trusts a
    // proxy. Use only the socket address, with equivalent IPv6 forms normalized.
    $address = trim(pr_scalar($_SERVER['REMOTE_ADDR'] ?? ''));
    $packedAddress = filter_var($address, FILTER_VALIDATE_IP) !== false ? inet_pton($address) : false;
    $address = $packedAddress !== false ? (string)inet_ntop($packedAddress) : 'unknown';
    $limits = [
        ['scope' => 'email', 'key_hash' => hash('sha256', $email), 'limit' => 5],
        ['scope' => 'ip', 'key_hash' => hash('sha256', $address), 'limit' => 20],
    ];
    $now = time();
    $id = bin2hex(random_bytes(16));
    $code = str_pad((string)random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
    $codeHash = password_hash($code, PASSWORD_DEFAULT);

    pr_transaction(static function () use ($limits, $now, $id, $buyer, $email, $codeHash): void {
        $rates = [];
        foreach ($limits as $limit) {
            $rate = one(
                'SELECT window_start, hits, last_sent FROM sblog_paid_reading_recovery_rate WHERE scope = ? AND key_hash = ?',
                [$limit['scope'], $limit['key_hash']]
            );
            if ($rate !== null && (int)$rate['last_sent'] > $now - 60) {
                throw new DomainException('验证码发送过于频繁，请一分钟后重试。');
            }
            $freshWindow = $rate === null || (int)$rate['window_start'] <= $now - 3600;
            if (!$freshWindow && (int)$rate['hits'] >= $limit['limit']) {
                throw new DomainException('验证码发送次数已达上限，请一小时后重试。');
            }
            $rates[] = [
                $limit['scope'], $limit['key_hash'], $freshWindow ? $now : (int)$rate['window_start'],
                $freshWindow ? 1 : (int)$rate['hits'] + 1, $now,
            ];
        }
        foreach ($rates as $rate) {
            q(
                'INSERT INTO sblog_paid_reading_recovery_rate(scope, key_hash, window_start, hits, last_sent) VALUES(?,?,?,?,?) ON CONFLICT(scope, key_hash) DO UPDATE SET window_start = excluded.window_start, hits = excluded.hits, last_sent = excluded.last_sent',
                $rate
            );
        }
        q(
            'UPDATE sblog_paid_reading_challenges SET consumed_at = ? WHERE buyer_hash = ? AND consumed_at IS NULL',
            [$now, $buyer]
        );
        q(
            'INSERT INTO sblog_paid_reading_challenges(id, buyer_hash, email, code_hash, attempts, created_at, expires_at) VALUES(?,?,?,?,0,?,?)',
            [$id, $buyer, $email, $codeHash, $now, $now + 600]
        );
    });

    $siteName = trim(preg_replace('/[\x00-\x1f\x7f]+/', ' ', setting('site_name', 'SBlog')) ?? 'SBlog');
    $siteName = mb_substr($siteName !== '' ? $siteName : 'SBlog', 0, 80, 'UTF-8');
    $subject = '[' . $siteName . '] 付费阅读邮箱验证码';
    $body = "你的付费阅读邮箱验证码是：" . $code . "\n\n"
        . "请在刚才申请验证码的浏览器中输入，有效期为 10 分钟，仅可使用一次。\n"
        . "验证邮箱后，可绑定本浏览器中已购买的文章，或在其他设备恢复该邮箱的购买权限。\n"
        . "如果这不是你本人发起的请求，请忽略此邮件，不要向任何人提供验证码。\n\n"
        . $siteName . "\n" . pr_base_url();
    try {
        $sent = send_site_mail($email, $subject, $body) === true;
    } catch (Throwable $exception) {
        $sent = false;
    }
    if (!$sent) {
        // A transport may fail after accepting the message. The code is still
        // invalidated, and the reserved cooldown prevents immediate mail abuse.
        q('UPDATE sblog_paid_reading_challenges SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL', [time(), $id]);
        throw new DomainException('验证码发送失败，请稍后重试或联系站长检查邮件服务。');
    }
    return $id;
}

/** Consume a browser-bound proof once and authorize this device for one year. */
function pr_verify_email_code(string $id, string $code): string
{
    $buyer = pr_buyer_hash();
    if ($buyer === '' || !preg_match('/^[a-f0-9]{32}$/D', $id)) {
        throw new DomainException('验证码无效或已过期，请重新获取。');
    }
    $code = trim($code);
    $now = time();
    // Return errors from inside the transaction, then throw after commit so
    // incorrect guesses always persist, including the attempt that locks a code.
    $outcome = pr_transaction(static function () use ($id, $code, $buyer, $now): array {
        $challenge = one(
            'SELECT * FROM sblog_paid_reading_challenges WHERE id = ? AND buyer_hash = ?',
            [$id, $buyer]
        );
        if ($challenge === null || $challenge['consumed_at'] !== null || (int)$challenge['expires_at'] <= $now
            || (int)$challenge['attempts'] >= 5) {
            return ['error' => '验证码无效或已过期，请重新获取。'];
        }
        if (!preg_match('/^[0-9]{8}$/D', $code) || !password_verify($code, (string)$challenge['code_hash'])) {
            $attempts = (int)$challenge['attempts'] + 1;
            q(
                'UPDATE sblog_paid_reading_challenges SET attempts = ?, consumed_at = ? WHERE id = ? AND consumed_at IS NULL',
                [$attempts, $attempts >= 5 ? $now : null, $id]
            );
            return ['error' => $attempts >= 5 ? '验证码尝试次数已达上限，请重新获取。' : '验证码不正确，请检查后重试。'];
        }
        $email = pr_normalize_email((string)$challenge['email']);
        if ($email === null) {
            q('UPDATE sblog_paid_reading_challenges SET consumed_at = ? WHERE id = ?', [$now, $id]);
            return ['error' => '验证码无效或已过期，请重新获取。'];
        }
        q('UPDATE sblog_paid_reading_challenges SET consumed_at = ? WHERE id = ? AND consumed_at IS NULL', [$now, $id]);
        q(
            // Reinsert the verified grant so rowid breaks equal-second timestamps
            // in favor of the address most recently proved on this browser.
            'INSERT OR REPLACE INTO sblog_paid_reading_devices(buyer_hash, email, verified_at, expires_at) VALUES(?,?,?,?)',
            [$buyer, $email, $now, $now + 31536000]
        );
        // Possessing an email never claims another browser's anonymous orders.
        // This only upgrades paid legacy orders already owned by this browser.
        pr_bind_legacy_orders($email, $buyer);
        return ['email' => $email];
    });
    if (isset($outcome['error'])) {
        throw new DomainException($outcome['error']);
    }
    return $outcome['email'];
}
