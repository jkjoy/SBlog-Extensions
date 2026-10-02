<?php

declare(strict_types=1);

require __DIR__ . '/../plugins/paid-reading/includes/payment.php';

function pr_url(string $action, array $params = []): string
{
    return 'https://blog.example/index.php?' . http_build_query(['action' => $action] + $params);
}

function payment_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function payment_test_canonical(array $params, bool $notification = false): string
{
    unset($params['sign']);
    if ($notification) {
        unset($params['sign_type']);
    }
    $params = array_filter($params, static fn ($value) => $value !== '');
    ksort($params);
    $parts = [];
    foreach ($params as $name => $value) {
        $parts[] = $name . '=' . $value;
    }
    return implode('&', $parts);
}

function payment_test_rejected(callable $call, string $message): void
{
    try {
        $call();
    } catch (Throwable $error) {
        return;
    }
    throw new RuntimeException($message);
}

$temporary = sys_get_temp_dir() . '/sblog-paid-reading-payment-' . bin2hex(random_bytes(6));
$failed = false;
try {
    payment_test_assert(mkdir($temporary, 0700), 'Could not create certificate fixture directory.');
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    payment_test_assert($key !== false && openssl_pkey_export($key, $privatePem), 'Could not create RSA key fixture.');
    $csr = openssl_csr_new(['commonName' => 'paid-reading.fixture.invalid'], $key, ['digest_alg' => 'sha256']);
    $certificate = $csr === false ? false : openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256'], 1234567);
    payment_test_assert($certificate !== false && openssl_x509_export($certificate, $certificatePem), 'Could not create X.509 fixture.');
    $privatePath = $temporary . '/merchant-private.pem';
    $certificatePath = $temporary . '/merchant-cert.pem';
    file_put_contents($privatePath, $privatePem);
    file_put_contents($certificatePath, $certificatePem);
    chmod($privatePath, 0600);
    chmod($certificatePath, 0600);
    $publicKey = openssl_pkey_get_details($key)['key'];
    $settings = [
        'alipay_enabled' => true,
        'alipay_app_id' => '2026100200000001',
        'alipay_seller_id' => '2088000000000001',
        'alipay_private_key' => $privatePath,
        'alipay_app_cert' => $certificatePath,
        'alipay_public_cert' => $certificatePem,
        'alipay_root_cert' => $certificatePath,
        'alipay_sandbox' => false,
        'wechat_enabled' => true,
        'wechat_mch_id' => '1900000001',
        'wechat_app_id' => 'wx0123456789abcdef',
        'wechat_private_key' => $privatePem,
        'wechat_mch_cert' => $certificatePath,
        'wechat_secret_key' => '0123456789abcdef0123456789abcdef',
        'wechat_public_cert' => json_encode(['PUB_KEY_ID_TEST_123' => $publicKey], JSON_THROW_ON_ERROR),
    ];
    payment_test_assert(pr_payment_ready($settings, 'alipay'), 'Bundled SDK could not initialize Alipay prerequisites.');
    payment_test_assert(pr_payment_ready($settings, 'wechat'), 'Bundled SDK could not initialize WeChat prerequisites.');
    payment_test_assert(!pr_payment_ready($settings, 'unknown'), 'Unknown payment channel was accepted.');
    payment_test_assert(!pr_payment_ready(array_replace($settings, ['wechat_secret_key' => 'short']), 'wechat'), 'Invalid API v3 key was accepted.');
    payment_test_assert(!pr_payment_ready(array_replace($settings, ['wechat_public_cert' => 'not-json']), 'wechat'), 'Invalid WeChat public-key map was accepted.');
    payment_test_rejected(static fn () => pr_payment_material('https://example.invalid/private.pem'), 'Remote certificate wrapper was accepted.');

    $order = ['channel' => 'alipay', 'order_no' => 'PR' . str_repeat('1', 30), 'amount_cents' => 123, 'title' => '文章 & <b>阅读</b>', 'expires_at' => time() + 900];
    foreach (['Mozilla/5.0 (X11; Linux x86_64)' => 'alipay.trade.page.pay', 'Mozilla/5.0 (iPhone; Mobile)' => 'alipay.trade.wap.pay'] as $agent => $method) {
        $_SERVER['HTTP_USER_AGENT'] = $agent;
        $checkout = pr_payment_create($order, $settings);
        payment_test_assert($checkout['kind'] === 'html', 'Alipay checkout did not produce a form.');
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $checkout['html'], LIBXML_NOERROR | LIBXML_NOWARNING);
        $params = [];
        foreach ($dom->getElementsByTagName('input') as $input) {
            if ($input->hasAttribute('name')) {
                $params[$input->getAttribute('name')] = $input->getAttribute('value');
            }
        }
        $business = json_decode($params['biz_content'], true, 512, JSON_THROW_ON_ERROR);
        payment_test_assert($params['method'] === $method, 'Alipay checkout used the wrong browser payment product.');
        payment_test_assert($params['return_url'] === pr_url('paid_reading_return', ['order' => $order['order_no']]), 'Alipay return URL lost the order number.');
        payment_test_assert($params['notify_url'] === pr_url('paid_reading_notify', ['channel' => 'alipay']), 'Alipay notification URL was incorrect.');
        payment_test_assert($business['out_trade_no'] === $order['order_no'] && $business['total_amount'] === '1.23', 'Alipay checkout altered the integer-fen amount or order number.');
        payment_test_assert($business['timeout_express'] === '15m', 'Alipay checkout did not honor the order expiry.');
        payment_test_assert(openssl_verify(payment_test_canonical($params), base64_decode($params['sign'], true), $publicKey, OPENSSL_ALGO_SHA256) === 1, 'Generated Alipay HTML did not preserve the signed payload.');
    }
    payment_test_rejected(static fn () => pr_payment_create(array_replace($order, ['expires_at' => time() - 1]), $settings), 'Expired order produced a payment form.');

    $notification = [
        'app_id' => $settings['alipay_app_id'], 'seller_id' => $settings['alipay_seller_id'],
        'out_trade_no' => $order['order_no'], 'trade_no' => '2026100200000000000001',
        'total_amount' => '1.23', 'trade_status' => 'TRADE_SUCCESS', 'sign_type' => 'RSA2',
    ];
    openssl_sign(payment_test_canonical($notification, true), $signature, $key, OPENSSL_ALGO_SHA256);
    $notification['sign'] = base64_encode($signature);
    $_POST = $notification;
    $_GET = ['action' => 'paid_reading_notify', 'channel' => 'alipay', 'total_amount' => '0.01', 'sign' => 'forged'];
    $verified = pr_payment_callback('alipay', array_replace($settings, ['alipay_enabled' => false]));
    payment_test_assert($verified['total_amount'] === '1.23' && $verified['out_trade_no'] === $order['order_no'], 'Alipay callback failed after disabling checkout or accepted unsigned query data.');
    $_POST['total_amount'] = '0.01';
    payment_test_rejected(static fn () => pr_payment_callback('alipay', $settings), 'Tampered Alipay callback passed signature verification.');
    $_POST = $notification;
    ob_start();
    pr_payment_success('alipay');
    payment_test_assert(ob_get_clean() === 'success', 'Alipay acknowledgement did not use the provider success response.');

    // Exercise real SDK signature checking and AES-GCM decryption without
    // contacting a gateway. The same raw body and headers arrive over HTTP.
    pr_payment_boot($settings, 'wechat');
    $trade = ['mchid' => $settings['wechat_mch_id'], 'appid' => $settings['wechat_app_id'], 'out_trade_no' => $order['order_no'], 'transaction_id' => '42000000000000000001', 'trade_state' => 'SUCCESS', 'amount' => ['total' => 123, 'currency' => 'CNY']];
    $encryptionNonce = 'abcdefghijkl';
    $associated = 'transaction';
    $ciphertext = openssl_encrypt(json_encode($trade, JSON_THROW_ON_ERROR), 'aes-256-gcm', $settings['wechat_secret_key'], OPENSSL_RAW_DATA, $encryptionNonce, $tag, $associated);
    payment_test_assert($ciphertext !== false, 'Could not encrypt WeChat fixture.');
    $event = ['id' => 'fixture-event', 'event_type' => 'TRANSACTION.SUCCESS', 'resource' => ['algorithm' => 'AEAD_AES_256_GCM', 'ciphertext' => base64_encode($ciphertext . $tag), 'nonce' => $encryptionNonce, 'associated_data' => $associated]];
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp = (string) time();
    $nonce = 'signed-fixture';
    openssl_sign($timestamp . "\n" . $nonce . "\n" . $body . "\n", $signature, $key, OPENSSL_ALGO_SHA256);
    $headers = ['Wechatpay-Serial' => 'PUB_KEY_ID_TEST_123', 'Wechatpay-Timestamp' => $timestamp, 'Wechatpay-Nonce' => $nonce, 'Wechatpay-Signature' => base64_encode($signature)];
    $decoded = pr_payment_result_array(Yansongda\Pay\Pay::wechat()->callback(['body' => $body, 'headers' => $headers]));
    payment_test_assert($decoded['resource']['ciphertext'] === $trade && $decoded['event_type'] === 'TRANSACTION.SUCCESS', 'SDK did not verify and decrypt WeChat transaction data.');
    payment_test_rejected(static fn () => Yansongda\Pay\Pay::wechat()->callback(['body' => $body . ' ', 'headers' => $headers]), 'Tampered WeChat body passed signature verification.');
    $staleHeaders = $headers;
    $staleHeaders['Wechatpay-Timestamp'] = (string) (time() - 601);
    openssl_sign($staleHeaders['Wechatpay-Timestamp'] . "\n" . $nonce . "\n" . $body . "\n", $signature, $key, OPENSSL_ALGO_SHA256);
    $staleHeaders['Wechatpay-Signature'] = base64_encode($signature);
    payment_test_rejected(static fn () => Yansongda\Pay\Pay::wechat()->callback(['body' => $body, 'headers' => $staleHeaders]), 'Stale WeChat notification passed timestamp verification.');
    ob_start();
    pr_payment_success('wechat');
    $acknowledgement = json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    payment_test_assert($acknowledgement['code'] === 'SUCCESS', 'WeChat acknowledgement did not use the provider success response.');
    echo "Paid reading bundled SDK payment tests passed.\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Paid reading SDK payment test failed: ' . $error->getMessage() . "\n");
    $failed = true;
} finally {
    foreach ([$temporary . '/merchant-private.pem', $temporary . '/merchant-cert.pem'] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
    if (is_dir($temporary)) {
        rmdir($temporary);
    }
}
if ($failed) {
    exit(1);
}
