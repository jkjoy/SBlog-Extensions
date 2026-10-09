<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

function sblog_steam_defaults(): array
{
    return [
        'api_key' => '',
        'steam_id' => '',
        'page_title' => '游戏时光',
        'game_limit' => 12,
        'cache_minutes' => 15,
        'show_recent' => true,
        'show_library' => true,
        'home_widget' => false,
        'show_nav' => true,
    ];
}

function sblog_steam_config(): array
{
    $defaults = sblog_steam_defaults();
    try {
        $payload = db()->query('SELECT payload FROM plugin_steam_settings WHERE id = 1')->fetchColumn();
        $stored = is_string($payload) ? json_decode($payload, true) : null;
        if (is_array($stored)) {
            foreach ($defaults as $key => $default) {
                if (isset($stored[$key]) && gettype($stored[$key]) === gettype($default)) {
                    $defaults[$key] = $stored[$key];
                }
            }
        }
    } catch (PDOException $exception) {
        // A new installation has no configuration table until its first save.
        if (!str_contains($exception->getMessage(), 'no such table')) {
            throw $exception;
        }
    }
    return $defaults;
}

function sblog_steam_input(array $input, string $key): string
{
    return isset($input[$key]) && is_scalar($input[$key]) ? trim((string)$input[$key]) : '';
}

function sblog_steam_validate_config(array $input, array $existing): array
{
    $config = array_merge(sblog_steam_defaults(), $existing);
    $errors = [];
    $config['steam_id'] = sblog_steam_input($input, 'steam_id');
    $config['page_title'] = sblog_steam_input($input, 'page_title');
    $key = sblog_steam_input($input, 'api_key');
    if (sblog_steam_input($input, 'clear_api_key') === '1') {
        $config['api_key'] = '';
    }
    if ($key !== '') {
        if (preg_match('/\A[a-fA-F0-9]{32}\z/', $key) !== 1) {
            $errors[] = 'Web API Key 应为 32 位十六进制字符。';
        } else {
            $config['api_key'] = $key;
        }
    }
    if ($config['steam_id'] !== '' && preg_match('/\A7656119[0-9]{10}\z/', $config['steam_id']) !== 1) {
        $errors[] = '请填写 17 位 SteamID64，例如 76561198000000000；不要填写昵称或个人资料网址。';
    }
    if ($config['page_title'] === '' || str_len_u($config['page_title']) > 60) {
        $errors[] = '展示页标题应为 1–60 个字符。';
    }
    foreach (['game_limit' => [1, 24, '每次展示游戏数'], 'cache_minutes' => [5, 1440, '缓存时间']] as $field => [$min, $max, $label]) {
        $value = sblog_steam_input($input, $field);
        if (preg_match('/\A[0-9]{1,4}\z/', $value) !== 1 || (int)$value < $min || (int)$value > $max) {
            $errors[] = $label . '应为 ' . $min . '–' . $max . ' 之间的整数。';
        } else {
            $config[$field] = (int)$value;
        }
    }
    foreach (['show_recent', 'show_library', 'home_widget', 'show_nav'] as $field) {
        $config[$field] = sblog_steam_input($input, $field) === '1';
    }
    return ['config' => $config, 'errors' => $errors];
}

function sblog_steam_save_config(array $config): void
{
    // Keep credentials out of core settings and its generated PHP cache.
    $payload = json_encode(array_intersect_key($config, sblog_steam_defaults()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $database = db();
    $database->exec('CREATE TABLE IF NOT EXISTS plugin_steam_settings (id INTEGER PRIMARY KEY CHECK (id = 1), payload TEXT NOT NULL)');
    $statement = $database->prepare('INSERT OR REPLACE INTO plugin_steam_settings (id, payload) VALUES (1, ?)');
    $statement->execute([$payload]);
}

function sblog_steam_url(string $action = 'steam'): string
{
    if ($action === 'steam' && use_pretty_url()) {
        return app_path('/steam');
    }
    return url_with_query(script_url(), ['a' => $action]);
}
