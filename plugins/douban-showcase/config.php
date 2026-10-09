<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

function sblog_douban_defaults(): array
{
    return [
        'user_id' => '',
        'page_title' => '豆瓣记录',
        'page_size' => 12,
        'max_pages' => 3,
        'cache_minutes' => 360,
        'show_nav' => true,
        'home_widget' => false,
    ];
}

function sblog_douban_config(): array
{
    $config = sblog_douban_defaults();
    try {
        $payload = db()->query('SELECT payload FROM plugin_douban_settings WHERE id = 1')->fetchColumn();
        $stored = is_string($payload) ? json_decode($payload, true) : null;
        if (is_array($stored)) {
            foreach ($config as $key => $default) {
                if (isset($stored[$key]) && gettype($stored[$key]) === gettype($default)) {
                    $config[$key] = $stored[$key];
                }
            }
        }
    } catch (PDOException $exception) {
        // A newly installed plugin has no settings table until its first save.
        if (!str_contains($exception->getMessage(), 'no such table')) {
            throw $exception;
        }
    }
    return $config;
}

function sblog_douban_input(array $input, string $key): string
{
    return isset($input[$key]) && is_scalar($input[$key]) ? trim((string)$input[$key]) : '';
}

function sblog_douban_user_id(string $input): string
{
    if (preg_match('~\Ahttps://(?:www|movie|book|music)\.douban\.com/people/([A-Za-z0-9][A-Za-z0-9_-]{0,63})/?\z~i', $input, $match) === 1) {
        return $match[1];
    }
    return $input;
}

function sblog_douban_validate_config(array $input, array $existing): array
{
    $config = array_merge(sblog_douban_defaults(), $existing);
    $errors = [];
    $config['user_id'] = sblog_douban_user_id(sblog_douban_input($input, 'user_id'));
    $config['page_title'] = sblog_douban_input($input, 'page_title');
    if ($config['user_id'] !== '' && preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,63}\z/', $config['user_id']) !== 1) {
        $errors[] = '请填写豆瓣用户 ID（数字或自定义英文 ID），或完整的 HTTPS 个人主页地址；不要填写昵称。';
    }
    if ($config['page_title'] === '' || str_len_u($config['page_title']) > 60) {
        $errors[] = '展示页标题应为 1–60 个字符。';
    }
    foreach (['page_size' => [1, 48, '每批展示条数'], 'max_pages' => [1, 10, '每个列表同步页数'], 'cache_minutes' => [5, 1440, '缓存时间']] as $field => [$min, $max, $label]) {
        $value = sblog_douban_input($input, $field);
        if (preg_match('/\A[0-9]{1,4}\z/', $value) !== 1 || (int)$value < $min || (int)$value > $max) {
            $errors[] = $label . '应为 ' . $min . '–' . $max . ' 之间的整数。';
        } else {
            $config[$field] = (int)$value;
        }
    }
    foreach (['show_nav', 'home_widget'] as $field) {
        $config[$field] = sblog_douban_input($input, $field) === '1';
    }
    return ['config' => $config, 'errors' => $errors];
}

function sblog_douban_save_config(array $config): void
{
    $payload = json_encode(array_intersect_key($config, sblog_douban_defaults()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $database = db();
    $database->exec('CREATE TABLE IF NOT EXISTS plugin_douban_settings (id INTEGER PRIMARY KEY CHECK (id = 1), payload TEXT NOT NULL)');
    $statement = $database->prepare('INSERT OR REPLACE INTO plugin_douban_settings (id, payload) VALUES (1, ?)');
    $statement->execute([$payload]);
}

function sblog_douban_url(string $action = 'douban', array $params = []): string
{
    if ($action === 'douban' && use_pretty_url()) {
        unset($params['a']);
        $url = app_path('/douban');
        return $params === [] ? $url : url_with_query($url, $params);
    }
    return url_with_query(script_url(), array_merge($params, ['a' => $action]));
}

function sblog_douban_types(): array
{
    return ['movie' => '电影', 'book' => '图书', 'music' => '音乐'];
}

function sblog_douban_statuses(string $type): array
{
    $verb = ['movie' => '看', 'book' => '读', 'music' => '听'][$type] ?? '看';
    return ['collect' => $verb . '过', 'wish' => '想' . $verb, 'do' => '在' . $verb];
}

function sblog_douban_selection(array $input): array
{
    $type = sblog_douban_input($input, 'type');
    $status = sblog_douban_input($input, 'status');
    return [
        array_key_exists($type, sblog_douban_types()) ? $type : 'movie',
        in_array($status, ['collect', 'wish', 'do'], true) ? $status : 'collect',
    ];
}
