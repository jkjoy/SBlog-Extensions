<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

/** Keep the form declaration and runtime defaults in one place. */
function sbvoid_settings_fields(): array
{
    $schema = json_decode((string)file_get_contents(__DIR__ . '/settings.json'), true);
    $fields = [];
    foreach ($schema['sections'] ?? [] as $section) {
        foreach ($section['fields'] ?? [] as $field) { $fields[$field['key']] = $field; }
    }
    return $fields;
}

/** Local assets and HTTP(S) links only; arbitrary schemes never reach a template. */
function sbvoid_setting_url(string $url, bool $local = true): string
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return ''; }
    if ($local && str_starts_with($url, '/') && !str_starts_with($url, '//')) { return $url; }
    $valid = function_exists('valid_http_url') ? valid_http_url($url)
        : filter_var($url, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string)parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true);
    return $valid ? $url : '';
}

function sbvoid_setting_text_limit(string $text, int $limit): string
{
    if (!preg_match('//u', $text)) { return ''; }
    preg_match('/\A.{0,' . $limit . '}/us', $text, $match);
    return $match[0] ?? '';
}

/** Convert both legacy PHP values and native form strings to safe runtime types. */
function sbvoid_setting_normalize(array $field, mixed $value): string|int|float|bool
{
    $default = (string)($field['default'] ?? '');
    $type = (string)($field['type'] ?? 'text');
    if (!is_scalar($value) || !preg_match('//u', (string)$value)) { $value = $default; }
    if ($type === 'checkbox') {
        if (is_bool($value)) { return $value; }
        if (in_array((string)$value, ['1', 'true'], true)) { return true; }
        if (in_array((string)$value, ['0', 'false', ''], true)) { return false; }
        return $default === '1';
    }
    $value = trim((string)$value);
    $ranges = [
        'banner_height' => [15, 100], 'mobile_banner_height' => [15, 100],
        'font_size' => [1, 5],
        'cover_style' => [0, 2], 'excerpt_length' => [20, 500],
        'content_width' => [600, 1100], 'list_width' => [800, 1400], 'toc_depth' => [2, 6],
    ];
    if (in_array($field['key'], ['dark_start', 'dark_end'], true)) {
        return preg_match('/^\d{1,2}(?:\.[05])?$/D', $value) && (float)$value >= 0 && (float)$value <= 24 ? (float)$value : (float)$default;
    }
    if (isset($ranges[$field['key']])) {
        [$minimum, $maximum] = $ranges[$field['key']];
        return preg_match('/^\d+$/D', $value) && (int)$value >= $minimum && (int)$value <= $maximum ? (int)$value : (int)$default;
    }
    if ($type === 'select') { return array_key_exists($value, $field['options'] ?? []) ? $value : $default; }
    if ($type === 'url') { return sbvoid_setting_url($value, $field['key'] !== 'math_url'); }
    if ($type === 'color') { return preg_match('/^#[a-f0-9]{6}$/iD', $value) ? strtolower($value) : $default; }
    if ($type === 'date' && $value !== '') {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $date) && checkdate((int)$date[2], (int)$date[3], (int)$date[1]) ? $value : '';
    }
    return sbvoid_setting_text_limit($value, min(20000, max(1, (int)($field['maxlength'] ?? 2000))));
}

function sbvoid_navigation_links(string $text): array
{
    $groups = [];
    foreach (array_slice(preg_split('/\R/u', $text) ?: [], 0, 40) as $line) {
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) === 2) { array_unshift($parts, '更多'); }
        if (count($parts) !== 3) { continue; }
        [$group, $title, $url] = $parts;
        $url = sbvoid_setting_url($url);
        if ($group === '' || $title === '' || $url === '') { continue; }
        $group = sbvoid_setting_text_limit($group, 100);
        $groups[$group][] = ['title' => sbvoid_setting_text_limit($title, 100), 'url' => $url];
    }
    $navigation = [];
    foreach ($groups as $name => $items) { $navigation[] = ['name' => (string)$name, 'items' => $items]; }
    return $navigation;
}

function sbvoid_legacy_social(array $items): array
{
    $social = [];
    foreach (array_slice($items, 0, 40) as $item) {
        if (!is_array($item)) { continue; }
        $url = is_string($item['url'] ?? null) ? sbvoid_setting_url($item['url'], false) : '';
        if ($url === '' && is_string($item['url'] ?? null) && str_starts_with($item['url'], 'mailto:')) {
            $email = substr($item['url'], 7);
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) { $url = 'mailto:' . $email; }
        }
        $name = is_scalar($item['name'] ?? null) ? sbvoid_setting_text_limit((string)$item['name'], 100) : '';
        if ($url === '' || $name === '') { continue; }
        $icon = is_scalar($item['icon'] ?? null) ? preg_replace('/[^a-z0-9-]/', '', (string)$item['icon']) : 'link';
        $social[] = ['name' => $name, 'icon' => $icon !== '' ? $icon : 'link', 'url' => $url];
    }
    return $social;
}

/** Defaults < retained config.php < explicitly saved, core-validated native settings. */
function sbvoid_settings_config(array $legacy = []): array
{
    $fields = sbvoid_settings_fields();
    $config = $legacy;
    foreach ($fields as $key => $field) {
        $config[$key] = sbvoid_setting_normalize($field, $legacy[$key] ?? $field['default'] ?? '');
    }
    $raw = function_exists('setting') ? json_decode(setting('theme_settings_void', '{}'), true) : [];
    $raw = is_array($raw) ? $raw : [];
    $native = function_exists('theme_settings') ? theme_settings('void') : [];
    $saved = [];
    if (is_array($native)) {
        foreach ($fields as $key => $field) {
            if (array_key_exists($key, $raw) && array_key_exists($key, $native)) {
                $config[$key] = sbvoid_setting_normalize($field, $native[$key]);
                $saved[$key] = true;
            }
        }
    }
    $config['social'] = sbvoid_legacy_social(is_array($legacy['social'] ?? null) ? $legacy['social'] : []);
    $socialKeys = ['social_github' => ['GitHub', 'github'], 'social_twitter' => ['X / Twitter', 'twitter'],
        'social_telegram' => ['Telegram', 'telegram'], 'social_weibo' => ['微博', 'weibo'], 'social_email' => ['邮箱', 'mail']];
    if (array_intersect_key($saved, $socialKeys)) {
        $config['social'] = [];
        foreach ($socialKeys as $key => [$name, $icon]) {
            $url = $key === 'social_email'
                ? (filter_var($config[$key], FILTER_VALIDATE_EMAIL) !== false ? 'mailto:' . $config[$key] : '')
                : sbvoid_setting_url($config[$key], false);
            if ($url !== '') { $config['social'][] = compact('name', 'icon', 'url'); }
        }
    }
    if (isset($saved['navigation_links'])) {
        $config['navigation'] = sbvoid_navigation_links($config['navigation_links']);
    } else {
        $config['navigation'] = [];
        foreach (is_array($legacy['navigation'] ?? null) ? $legacy['navigation'] : [] as $group) {
            if (!is_array($group) || !is_scalar($group['name'] ?? null)) { continue; }
            $items = [];
            foreach (is_array($group['items'] ?? null) ? $group['items'] : [] as $item) {
                if (!is_array($item) || !is_string($item['url'] ?? null) || !is_scalar($item['title'] ?? null)) { continue; }
                $url = sbvoid_setting_url($item['url']);
                if ($url !== '') { $items[] = ['title' => sbvoid_setting_text_limit((string)$item['title'], 100), 'url' => $url]; }
            }
            if ($items) { $config['navigation'][] = ['name' => sbvoid_setting_text_limit((string)$group['name'], 100), 'items' => array_slice($items, 0, 40)]; }
            if (count($config['navigation']) >= 20) { break; }
        }
    }
    return $config;
}

/** Inline CSS cannot close its style element or introduce HTML tags. */
function sbvoid_custom_css(string $css): string
{
    return str_replace('<', '\\3C ', sbvoid_setting_text_limit($css, 20000));
}
