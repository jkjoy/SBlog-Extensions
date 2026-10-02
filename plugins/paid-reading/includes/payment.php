<?php

/**
 * Payment gateway boundary. Only verified trade data leaves this file;
 * order matching and granting reading access belong to the plugin service.
 */

use Yansongda\Pay\Pay;

function pr_payment_load_sdk(): void
{
    if (PHP_VERSION_ID < 80200) {
        throw new RuntimeException('支付 SDK 需要 PHP 8.2 或更高版本。');
    }
    foreach (['openssl', 'json', 'bcmath', 'simplexml', 'libxml'] as $extension) {
        if (!extension_loaded($extension)) {
            throw new RuntimeException('支付 SDK 缺少 PHP 扩展：' . $extension . '。');
        }
    }
    if (!class_exists(Pay::class)) {
        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
    }
    if (!class_exists(Pay::class)) {
        throw new RuntimeException('尚未安装支付 SDK，请在 paid-reading 插件目录运行 composer install --no-dev。');
    }
}

function pr_payment_setting(array $settings, string $key): string
{
    return isset($settings[$key]) && is_scalar($settings[$key]) ? trim((string) $settings[$key]) : '';
}

/** Accept PEM contents or an absolute, readable local path; reject wrappers. */
function pr_payment_material(string $value): string
{
    $value = trim($value);
    if ($value === '' || strpos($value, "\0") !== false) {
        throw new RuntimeException('支付证书或私钥配置为空或格式错误。');
    }
    if (strpos($value, '-----BEGIN ') === 0) {
        return $value;
    }
    if ($value[0] === '/' || preg_match('~^[A-Za-z]:[\\\\/]~', $value)) {
        if (strpos($value, '://') !== false || !is_file($value) || !is_readable($value)) {
            throw new RuntimeException('支付证书或私钥文件不存在或不可读。');
        }
        $size = filesize($value);
        if ($size === false || $size > 131072) {
            throw new RuntimeException('支付证书或私钥文件过大。');
        }
        $contents = file_get_contents($value);
        if ($contents === false || trim($contents) === '') {
            throw new RuntimeException('支付证书或私钥文件无法读取。');
        }
        return trim($contents);
    }
    // Alipay also supplies private keys as a bare base64 string.
    if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $value)) {
        return "-----BEGIN RSA PRIVATE KEY-----\n" . chunk_split(preg_replace('/\s+/', '', $value), 64, "\n") . '-----END RSA PRIVATE KEY-----';
    }
    throw new RuntimeException('请填写 PEM 内容或证书、私钥的绝对本地路径。');
}

/**
 * Export as PKCS#8 because the referenced SDK treats inline PKCS#1 differently
 * from a private-key file. Never put keys or certificate contents in errors.
 */
function pr_payment_private_key(string $value): string
{
    $key = @openssl_pkey_get_private(pr_payment_material($value));
    $details = $key === false ? false : openssl_pkey_get_details($key);
    if ($key === false || !is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
        throw new RuntimeException('支付私钥必须是有效的 RSA 2048 位或更高位数私钥。');
    }
    if (!openssl_pkey_export($key, $pem)) {
        throw new RuntimeException('支付私钥无法解析。');
    }
    return $pem;
}

function pr_payment_certificate(string $value, bool $allowPublicKey = false): string
{
    $pem = pr_payment_material($value);
    if ($allowPublicKey) {
        $key = @openssl_pkey_get_public($pem);
        $details = $key === false ? false : openssl_pkey_get_details($key);
        if (!is_array($details) || $details['type'] !== OPENSSL_KEYTYPE_RSA || $details['bits'] < 2048) {
            throw new RuntimeException('支付平台证书或公钥无效。');
        }
    } elseif (@openssl_x509_read($pem) === false) {
        throw new RuntimeException('支付公钥证书无效，请使用证书模式的 X.509 证书。');
    }
    return $pem;
}

function pr_payment_public_certs($value): array
{
    if (is_string($value)) {
        $value = trim($value);
        if ($value === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded) || $value[0] !== '{') {
            throw new RuntimeException('微信平台公钥配置必须是 JSON 对象：证书序列号或公钥 ID 对应证书路径。');
        }
        $value = $decoded;
    }
    if (!is_array($value)) {
        throw new RuntimeException('微信平台公钥配置格式错误。');
    }
    foreach ($value as $serial => $path) {
        if (!is_string($path) || trim($path) === '' || !preg_match('/^(?:[A-Fa-f0-9]{1,128}|PUB_KEY_ID_[A-Za-z0-9_-]{1,128})$/', (string) $serial)) {
            throw new RuntimeException('微信平台公钥配置中的序列号、公钥 ID 或证书路径无效。');
        }
        // PHP casts short numeric object keys to integers; the SDK needs strings.
        if (is_int($serial)) {
            throw new RuntimeException('微信平台证书序列号格式错误，请填写完整序列号。');
        }
    }
    return $value;
}

function pr_payment_credentials(array $settings, string $channel): array
{
    if (!in_array($channel, ['alipay', 'wechat'], true)) {
        throw new RuntimeException('不支持的支付方式。');
    }
    $required = $channel === 'alipay'
        ? ['alipay_app_id', 'alipay_seller_id', 'alipay_private_key', 'alipay_app_cert', 'alipay_public_cert', 'alipay_root_cert']
        : ['wechat_mch_id', 'wechat_app_id', 'wechat_private_key', 'wechat_mch_cert', 'wechat_secret_key'];
    foreach ($required as $key) {
        if (pr_payment_setting($settings, $key) === '') {
            throw new RuntimeException('支付配置不完整，缺少：' . $key . '。');
        }
    }
    $material = [];
    if ($channel === 'alipay') {
        $material['app_secret_cert'] = pr_payment_private_key(pr_payment_setting($settings, 'alipay_private_key'));
        $material['app_public_cert_path'] = pr_payment_certificate(pr_payment_setting($settings, 'alipay_app_cert'));
        $material['alipay_public_cert_path'] = pr_payment_certificate(pr_payment_setting($settings, 'alipay_public_cert'));
        $material['alipay_root_cert_path'] = pr_payment_certificate(pr_payment_setting($settings, 'alipay_root_cert'));
    } else {
        if (strlen(pr_payment_setting($settings, 'wechat_secret_key')) !== 32) {
            throw new RuntimeException('微信 API v3 密钥必须是 32 字节。');
        }
        $material['mch_secret_cert'] = pr_payment_private_key(pr_payment_setting($settings, 'wechat_private_key'));
        $material['mch_public_cert_path'] = pr_payment_certificate(pr_payment_setting($settings, 'wechat_mch_cert'));
        $certs = pr_payment_public_certs($settings['wechat_public_cert'] ?? '');
        if ($certs === []) {
            throw new RuntimeException('请配置微信平台证书或微信支付公钥，以验证支付通知。');
        }
        foreach ($certs as $serial => $path) {
            $certs[$serial] = pr_payment_certificate($path, true);
        }
        $material['wechat_public_cert_path'] = $certs;
    }
    return $material;
}

function pr_payment_ready(array $settings, string $channel): bool
{
    if (!in_array($channel, ['alipay', 'wechat'], true) || empty($settings[$channel . '_enabled'])) {
        return false;
    }
    try {
        pr_payment_load_sdk();
        pr_payment_credentials($settings, $channel);
        return true;
    } catch (Throwable $error) {
        return false;
    }
}

/** Map the plugin's saved settings to the SDK's direct merchant configuration. */
function pr_payment_config(array $settings): array
{
    $config = [
        '_force' => true,
        'logger' => ['enable' => false],
        'http' => ['timeout' => 10.0, 'connect_timeout' => 5.0],
    ];
    if (pr_payment_setting($settings, 'alipay_app_id') !== '') {
        $config['alipay']['default'] = [
            'app_id' => pr_payment_setting($settings, 'alipay_app_id'),
            'app_secret_cert' => pr_payment_setting($settings, 'alipay_private_key'),
            'app_public_cert_path' => pr_payment_setting($settings, 'alipay_app_cert'),
            'alipay_public_cert_path' => pr_payment_setting($settings, 'alipay_public_cert'),
            'alipay_root_cert_path' => pr_payment_setting($settings, 'alipay_root_cert'),
            'notify_url' => pr_url('paid_reading_notify', ['channel' => 'alipay']),
            'mode' => empty($settings['alipay_sandbox']) ? 0 : 1,
        ];
    }
    if (pr_payment_setting($settings, 'wechat_mch_id') !== '') {
        $config['wechat']['default'] = [
            'mch_id' => pr_payment_setting($settings, 'wechat_mch_id'),
            'mp_app_id' => pr_payment_setting($settings, 'wechat_app_id'),
            'mch_secret_key' => pr_payment_setting($settings, 'wechat_secret_key'),
            'mch_secret_cert' => pr_payment_setting($settings, 'wechat_private_key'),
            'mch_public_cert_path' => pr_payment_setting($settings, 'wechat_mch_cert'),
            'wechat_public_cert_path' => pr_payment_public_certs($settings['wechat_public_cert'] ?? ''),
            'notify_url' => pr_url('paid_reading_notify', ['channel' => 'wechat']),
            'mode' => 0,
        ];
    }
    return $config;
}

function pr_payment_boot(array $settings, string $channel): void
{
    pr_payment_load_sdk();
    $material = pr_payment_credentials($settings, $channel);
    // Configure only the requested channel so incomplete settings elsewhere do
    // not prevent a valid payment or a notification for an existing order.
    unset($settings[$channel === 'alipay' ? 'wechat_mch_id' : 'alipay_app_id']);
    $config = pr_payment_config($settings);
    $config[$channel]['default'] = array_replace($config[$channel]['default'], $material);
    Pay::config($config);
}

function pr_payment_order_number(array $order): string
{
    $number = isset($order['order_no']) ? (string) $order['order_no'] : '';
    if (!preg_match('/^[A-Za-z0-9_-]{6,32}$/', $number)) {
        throw new RuntimeException('支付订单号无效。');
    }
    return $number;
}

function pr_payment_create(array $order, array $settings): array
{
    $channel = isset($order['channel']) ? (string) $order['channel'] : '';
    if (!in_array($channel, ['alipay', 'wechat'], true) || empty($settings[$channel . '_enabled'])) {
        throw new RuntimeException('该支付方式尚未启用。');
    }
    $number = pr_payment_order_number($order);
    $rawAmount = isset($order['amount_cents']) ? (string) $order['amount_cents'] : '';
    if (!preg_match('/^[1-9][0-9]{0,8}$/', $rawAmount)) {
        throw new RuntimeException('支付订单金额无效。');
    }
    $cents = (int) $rawAmount;
    $title = trim(strip_tags((string) ($order['title'] ?? '付费阅读')));
    $title = str_replace('&', '＆', preg_replace('/[\x00-\x1F\x7F]/u', '', $title) ?? '付费阅读');
    $characters = preg_split('//u', $title, -1, PREG_SPLIT_NO_EMPTY);
    $title = '';
    foreach (is_array($characters) ? $characters : [] as $character) {
        if (strlen($title . $character) > 120) {
            break;
        }
        $title .= $character;
    }
    $title = $title === '' ? '付费阅读' : $title;
    $returnUrl = pr_url('paid_reading_return', ['order' => $number]);
    $expiresAt = isset($order['expires_at']) ? (int) $order['expires_at'] : time() + 1800;
    if ($expiresAt <= time()) {
        throw new RuntimeException('订单已过期，请重新下单。');
    }
    pr_payment_boot($settings, $channel);
    if ($channel === 'alipay') {
        $params = [
            'out_trade_no' => $number,
            'total_amount' => intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT),
            'subject' => $title,
            'timeout_express' => max(1, (int) ceil(($expiresAt - time()) / 60)) . 'm',
            '_return_url' => $returnUrl,
            '_notify_url' => pr_url('paid_reading_notify', ['channel' => 'alipay']),
        ];
        $mobile = preg_match('/Android|iPhone|iPad|iPod|Mobile/i', (string) ($_SERVER['HTTP_USER_AGENT'] ?? '')) === 1;
        if ($mobile) {
            $params['product_code'] = 'QUICK_WAP_WAY';
        }
        $response = $mobile ? Pay::alipay()->h5($params) : Pay::alipay()->web($params);
        return ['kind' => 'html', 'html' => (string) $response->getBody()];
    }
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        throw new RuntimeException('无法获取付款客户端 IP。');
    }
    if (stripos((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'MicroMessenger') !== false) {
        throw new RuntimeException('微信 H5 支付请使用手机系统浏览器打开当前页面。');
    }
    $result = pr_payment_result_array(Pay::wechat()->h5([
        'out_trade_no' => $number,
        'description' => $title,
        'time_expire' => date(DATE_RFC3339, $expiresAt),
        'amount' => ['total' => $cents, 'currency' => 'CNY'],
        'notify_url' => pr_url('paid_reading_notify', ['channel' => 'wechat']),
        'scene_info' => ['payer_client_ip' => $ip, 'h5_info' => ['type' => 'Wap']],
    ]));
    $url = isset($result['h5_url']) ? (string) $result['h5_url'] : '';
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['fragment']) || preg_match('/[\r\n]/', $url)) {
        throw new RuntimeException('支付网关未返回有效的微信 H5 支付地址。');
    }
    return ['kind' => 'redirect', 'url' => $url . (strpos($url, '?') === false ? '?' : '&') . 'redirect_url=' . rawurlencode($returnUrl)];
}

function pr_payment_result_array($result): array
{
    if (is_array($result)) {
        return $result;
    }
    if (is_object($result) && method_exists($result, 'all')) {
        $data = $result->all();
        if (is_array($data)) {
            return $data;
        }
    }
    throw new RuntimeException('支付 SDK 返回了无法识别的数据。');
}

/** Never trust browser return parameters as a payment result. */
function pr_payment_callback(string $channel, array $settings): array
{
    pr_payment_boot($settings, $channel);
    if ($channel === 'alipay') {
        // Route/query parameters are not part of Alipay's signed POST payload.
        return pr_payment_result_array(Pay::alipay()->callback($_POST));
    }
    // The SDK reads the untouched raw body and Wechatpay-* headers, verifies
    // the platform signature and timestamp, then decrypts with the API v3 key.
    $event = pr_payment_result_array(Pay::wechat()->callback());
    $resource = $event['resource']['ciphertext'] ?? null;
    if (!is_array($resource)) {
        throw new RuntimeException('微信支付通知中没有有效的交易数据。');
    }
    $resource['_event_type'] = isset($event['event_type']) ? (string) $event['event_type'] : '';
    return $resource;
}

/** Query results still require the service's merchant/order/amount checks. */
function pr_payment_query(array $order, array $settings): array
{
    $channel = isset($order['channel']) ? (string) $order['channel'] : '';
    $number = pr_payment_order_number($order);
    pr_payment_boot($settings, $channel);
    if ($channel === 'alipay') {
        return pr_payment_result_array(Pay::alipay()->query(['out_trade_no' => $number]));
    }
    return pr_payment_result_array(Pay::wechat()->query(['out_trade_no' => $number, '_action' => 'h5']));
}

function pr_emit_response($response): void
{
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header($name . ': ' . $value, false);
        }
    }
    echo (string) $response->getBody();
}

/** Call only after the verified notification has been persisted successfully. */
function pr_payment_success(string $channel): void
{
    if (!in_array($channel, ['alipay', 'wechat'], true)) {
        throw new RuntimeException('不支持的支付方式。');
    }
    pr_payment_load_sdk();
    pr_emit_response($channel === 'alipay' ? Pay::alipay()->success() : Pay::wechat()->success());
}
