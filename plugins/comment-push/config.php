<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) { http_response_code(403); exit; }

function sblog_push_defaults(): array
{
    return [
        'qq_enabled' => false,
        'qq_app_id' => '',
        'qq_app_secret' => '',
        'qq_target_type' => 'c2c',
        'qq_target_id' => '',
        'weixin_enabled' => false,
        'weixin_user_id' => '',
        'notify_pending' => true,
        'notify_approved' => true,
        'notify_admin' => false,
        'include_link' => true,
    ];
}

function sblog_push_read(string $key): array
{
    try {
        $statement = db()->prepare('SELECT payload FROM plugin_comment_push WHERE name = ?');
        $statement->execute([$key]);
        $payload = $statement->fetchColumn();
    } catch (PDOException $exception) {
        if (str_contains($exception->getMessage(), 'no such table')) { return []; }
        throw $exception;
    }
    $decoded = is_string($payload) ? json_decode($payload, true) : null;
    return is_array($decoded) ? $decoded : [];
}

function sblog_push_write(string $key, array $record): void
{
    // Secrets stay in the protected database, outside the core settings PHP cache.
    $payload = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    db()->exec('CREATE TABLE IF NOT EXISTS plugin_comment_push (name TEXT PRIMARY KEY, payload TEXT NOT NULL)');
    $statement = db()->prepare('INSERT OR REPLACE INTO plugin_comment_push (name, payload) VALUES (?, ?)');
    $statement->execute([$key, $payload]);
}

function sblog_push_config(): array
{
    $config = sblog_push_defaults();
    foreach (sblog_push_read('config') as $key => $value) {
        if (array_key_exists($key, $config) && gettype($config[$key]) === gettype($value)) { $config[$key] = $value; }
    }
    return $config;
}

function sblog_push_input(array $input, string $key): string
{
    return isset($input[$key]) && is_scalar($input[$key]) ? trim((string)$input[$key]) : '';
}

function sblog_push_validate(array $input, array $existing): array
{
    $config = array_merge(sblog_push_defaults(), $existing);
    $errors = [];
    foreach (['qq_enabled', 'weixin_enabled', 'notify_pending', 'notify_approved', 'notify_admin', 'include_link'] as $key) {
        $config[$key] = sblog_push_input($input, $key) === '1';
    }
    foreach (['qq_app_id', 'qq_target_type', 'qq_target_id'] as $key) { $config[$key] = sblog_push_input($input, $key); }
    if (sblog_push_input($input, 'clear_qq_app_secret') === '1') { $config['qq_app_secret'] = ''; }
    $secret = sblog_push_input($input, 'qq_app_secret');
    if ($secret !== '') { $config['qq_app_secret'] = $secret; }
    if ($config['qq_app_id'] !== '' && !preg_match('/\A[0-9]{1,32}\z/', $config['qq_app_id'])) { $errors[] = 'QQ AppID 只能填写数字。'; }
    if ($config['qq_app_secret'] !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,256}\z/', $config['qq_app_secret'])) { $errors[] = 'QQ AppSecret 格式无效。'; }
    if (!in_array($config['qq_target_type'], ['c2c', 'group', 'channel'], true)) { $errors[] = '请选择有效的 QQ 接收类型。'; }
    if ($config['qq_target_id'] !== '' && !preg_match('/\A[A-Za-z0-9_-]{1,128}\z/', $config['qq_target_id'])) { $errors[] = 'QQ 接收 ID 格式无效。'; }
    if ($config['qq_enabled'] && ($config['qq_app_id'] === '' || $config['qq_app_secret'] === '' || $config['qq_target_id'] === '')) {
        $errors[] = '启用 QQ 通知前，请填写 AppID、AppSecret 和接收 ID。';
    }
    // The Weixin recipient is obtained from getupdates, never accepted from an arbitrary form field.
    if ($config['weixin_enabled']) {
        $account = sblog_push_read('weixin_account');
        if (empty($account['token']) || !empty($account['expired']) || $config['weixin_user_id'] === '') {
            $errors[] = '启用微信通知前，请先扫码登录，并向微信中的机器人发送一条消息以绑定接收人。';
        }
    }
    if (!$config['notify_pending'] && !$config['notify_approved']) { $errors[] = '请至少选择一种评论状态。'; }
    return ['config' => $config, 'errors' => $errors];
}

function sblog_push_save_config(array $config): void
{
    $existing = sblog_push_config();
    if ($existing['qq_app_id'] !== $config['qq_app_id'] || $existing['qq_app_secret'] !== $config['qq_app_secret']) { sblog_push_write('qq_token', []); }
    sblog_push_write('config', array_intersect_key($config, sblog_push_defaults()));
}

function sblog_push_admin_url(): string
{
    return url_with_query(script_url(), ['a' => 'admin_comment_push']);
}
