<?php

declare(strict_types=1);

// Run from a SBlog installation: php themes/void/tests/settings.php
// Only extract actual core helpers. Never boot index.php, open a database or write site settings.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('APP_VERSION', 'void-settings-test');
define('THEMES_DIR', dirname(__DIR__, 2));

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    check($expected === $actual, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}

function setting(string $key, string $default = ''): string
{
    return (string)($GLOBALS['void_settings_store'][$key] ?? $default);
}

function sblog_t(string $message, array $parameters = []): string
{
    foreach ($parameters as $key => $value) { $message = str_replace('{' . $key . '}', (string)$value, $message); }
    return $message;
}

function load_settings_core(): void
{
    $needed = array_fill_keys([
        'str_len_u', 'str_sub_u', 'str_lower_u', 'valid_http_url',
        'theme_inventory_cache', 'refresh_theme_cache', 'theme_manifest',
        'theme_settings_schema', 'theme_setting_value_error', 'theme_settings', 'theme_setting', 'theme_settings_values',
    ], true);
    $tokens = token_get_all((string)file_get_contents(dirname(__DIR__, 3) . '/index.php'));
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
        $j = $i + 1;
        while (isset($tokens[$j]) && ((is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) || (is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j]) === '&')) { $j++; }
        $name = is_array($tokens[$j] ?? null) ? $tokens[$j][1] : '';
        if (!isset($needed[$name])) { continue; }
        $code = '';
        $depth = 0;
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; }
            elseif ($token === '}' && --$depth === 0) { break; }
        }
        eval($code);
        unset($needed[$name]);
    }
    same([], array_keys($needed), 'Every tested settings API must come from the real SBlog core.');
}

function stored(array $values): void
{
    $GLOBALS['void_settings_store']['theme_settings_void'] = json_encode($values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}

function form_defaults(): array
{
    $values = [];
    foreach (theme_settings_schema('void') as $section) {
        foreach ($section['fields'] as $field) { $values[$field['key']] = $field['default']; }
    }
    return $values;
}

load_settings_core();
require dirname(__DIR__) . '/settings.php';
$tests = [];
$tests['the core recognizes all 71 settings and accepts their complete default form'] = static function (): void {
    $sections = theme_settings_schema('void');
    same(8, count($sections), 'The native settings page lost a group.');
    $fields = sbvoid_settings_fields();
    same(71, count($fields), 'A declared native field disappeared.');
    $defaults = form_defaults();
    same(array_keys($fields), array_keys($defaults), 'The core rejected or reordered the theme schema.');
    same([], theme_settings_values('void', $defaults)['errors'], 'Bundled defaults must be accepted by the real save validator.');
    foreach (['brand_font_url', 'brand_font_weight', 'header_mode_mobile', 'parse_figcaption', 'comment_notice', 'navigation_links', 'custom_css'] as $key) {
        check(isset($fields[$key]), 'Expected customization is absent: ' . $key);
    }
};
$tests['defaults and legacy values survive until their own native keys are saved'] = static function (): void {
    $config = sbvoid_settings_config();
    same(true, $config['show_toc'], 'A new visibility switch should default on.');
    same(false, $config['parse_figcaption'], 'Existing article appearance should be retained.');
    same(820, $config['content_width'], 'Content width default is missing.');
    same(22.0, $config['dark_start'], 'Night start must be a numeric hour.');
    $legacy = array_replace(require dirname(__DIR__) . '/config.php', [
        'name' => '保留的站名', 'banner' => '/legacy/banner.jpg', 'content_width' => 900, 'pjax' => false,
        'social' => [['name' => 'Legacy profile', 'icon' => 'github', 'url' => 'https://example.com/profile']],
        'navigation' => [['name' => '旧菜单', 'items' => [['title' => '旧页面', 'url' => '/legacy']]]],
    ]);
    stored(['name' => '后台站名']);
    $config = sbvoid_settings_config($legacy);
    same('后台站名', $config['name'], 'The saved name must override legacy config.');
    same('/legacy/banner.jpg', $config['banner'], 'Unsaved schema defaults must not erase an old banner.');
    same(900, $config['content_width'], 'A partial native save must not erase unsaved legacy width.');
    same(false, $config['pjax'], 'An unsaved old false switch must remain false.');
    same('Legacy profile', $config['social'][0]['name'], 'Unrelated saves must retain old social links.');
    same('/legacy', $config['navigation'][0]['items'][0]['url'], 'Unrelated saves must retain old navigation.');
    stored(['name' => '即时更新']);
    same('即时更新', sbvoid_settings_config($legacy)['name'], 'Settings must not be hidden behind a stale static config cache.');
    $GLOBALS['void_settings_store']['theme_settings_void'] = '{invalid';
    same('保留的站名', sbvoid_settings_config($legacy)['name'], 'Corrupt native JSON must leave retained legacy values intact.');
};
$tests['native save values preserve false switches and normalize integers and fractional hours'] = static function (): void {
    $input = array_replace(form_defaults(), [
        'show_toc' => '0', 'show_copy' => '0', 'image_lightbox' => '0', 'lazyload' => '0',
        'title_cover' => '0', 'pjax' => '0', 'math' => '0', 'serif' => '1',
        'dark_start' => '22.5', 'dark_end' => '7.5', 'font_size' => '4', 'cover_style' => '2',
        'content_width' => '1000', 'list_width' => '1200', 'excerpt_length' => '160',
        'brand_font_weight' => '600', 'brand_font_italic' => '1',
    ]);
    $result = theme_settings_values('void', $input + ['undeclared_key' => 'ignored']);
    same([], $result['errors'], 'Valid form settings should pass the real core validator.');
    check(!isset($result['values']['undeclared_key']), 'Unknown keys must not be stored.');
    stored($result['values']);
    $config = sbvoid_settings_config();
    foreach (['show_toc', 'show_copy', 'image_lightbox', 'lazyload', 'title_cover', 'pjax', 'math'] as $key) {
        same(false, $config[$key], 'Unchecked switch reverted to on: ' . $key);
    }
    same(true, $config['serif'], 'Saved true switch was lost.');
    same(22.5, $config['dark_start'], 'Half-hour start was truncated.');
    same(7.5, $config['dark_end'], 'Half-hour end was truncated.');
    same(4, $config['font_size'], 'Font size must be an integer.');
    same(2, $config['cover_style'], 'Cover style must be an integer.');
    same(1000, $config['content_width'], 'Saved content width was ignored.');
    same('600', $config['brand_font_weight'], 'Font weight must preserve its supported CSS value.');
    same(true, $config['brand_font_italic'], 'Italic switch was ignored.');
    stored(['dark_start' => '24', 'dark_end' => '0']);
    same(24.0, sbvoid_settings_config()['dark_start'], '24:00 should be accepted as a boundary.');
    same(0.0, sbvoid_settings_config()['dark_end'], 'Midnight should remain zero.');
};
$tests['real field validation rejects unsafe URL colors dates and enum input'] = static function (): void {
    $defaults = form_defaults();
    foreach (['javascript:alert(1)', '//example.com/a', '/\\example.com/a', "/a\nb", 'ftp://example.com/a'] as $url) {
        foreach (['banner', 'brand_font_url', 'logo', 'default_cover'] as $key) {
            check(isset(theme_settings_values('void', array_replace($defaults, [$key => $url]))['errors'][$key]), 'Unsafe ' . $key . ' URL passed the native save validator.');
        }
    }
    $invalid = theme_settings_values('void', array_replace($defaults, [
        'accent_color' => 'red', 'brand_color' => '#abc', 'brand_font' => 'injected',
        'brand_font_weight' => '900;display:none', 'header_mode' => 'other',
        'start_date' => '2023-02-29', 'show_toc' => ['1'],
    ]));
    foreach (['accent_color', 'brand_color', 'brand_font', 'brand_font_weight', 'header_mode', 'start_date', 'show_toc'] as $key) {
        check(isset($invalid['errors'][$key]), 'Native validation missed invalid ' . $key);
    }
    $valid = theme_settings_values('void', array_replace($defaults, [
        'logo' => '/uploads/logo.png', 'brand_font_url' => 'https://example.com/font.woff2',
        'accent_color' => '#ABC123', 'start_date' => '2024-02-29', 'brand_font' => 'serif',
    ]));
    same([], $valid['errors'], 'Safe custom font, colors and date should be accepted.');
    stored($valid['values']);
    $config = sbvoid_settings_config();
    same('/uploads/logo.png', $config['logo'], 'Logo path was lost.');
    same('https://example.com/font.woff2', $config['brand_font_url'], 'Custom font URL was lost.');
    same('#abc123', $config['accent_color'], 'Colors must normalize to a valid CSS token.');
};
$tests['corrupt stored values and out of range legacy options degrade safely'] = static function (): void {
    stored([
        'logo' => 'javascript:alert(1)', 'brand_font_url' => 'data:text/css,injected',
        'accent_color' => '#fff;display:none', 'brand_font' => 'unlisted', 'show_copy' => ['1'],
        'dark_start' => '25', 'dark_end' => '7.25', 'content_width' => '10', 'list_width' => '99999',
    ]);
    $config = sbvoid_settings_config();
    same('', $config['logo'], 'Unsafe stored logo survived.');
    same('', $config['brand_font_url'], 'Unsafe font resource survived.');
    same('#bd6262', $config['accent_color'], 'Invalid saved color did not fall back.');
    same('sans', $config['brand_font'], 'Invalid saved enum did not fall back.');
    same(true, $config['show_copy'], 'Array-shaped stored switch should use schema default.');
    same(22.0, $config['dark_start'], 'Out-of-range start hour should use default.');
    same(7.0, $config['dark_end'], 'Unsupported fractional hour should use default.');
    same(820, $config['content_width'], 'Too narrow content width should use default.');
    same(1000, $config['list_width'], 'Too wide list should use default.');
    $legacy = sbvoid_settings_config(['logo' => '//outside.example/logo.png', 'banner_height' => 'injected', 'start_date' => '2025-02-30']);
    same('', $legacy['logo'], 'Unsafe legacy URL must also be rejected.');
    same(40, $legacy['banner_height'], 'Invalid legacy numeric value should use default.');
    same('', $legacy['start_date'], 'Invalid legacy date should use default.');
};
$tests['native navigation and social links ignore unsafe rows and support explicit clearing'] = static function (): void {
    $legacy = [
        'navigation' => [['name' => '旧导航', 'items' => [['title' => '首页', 'url' => '/old']]]],
        'social' => [['name' => '旧链接', 'icon' => 'github', 'url' => 'https://old.example.com']],
    ];
    stored([
        'navigation_links' => "分组 | 链接 <img> | /about\n更多 | 外站 | https://example.com\n不安全 | javascript:alert(1)\n漏名称 | | /about\n两段 | /second",
        'social_github' => 'https://github.com/example', 'social_email' => 'hello@example.com',
        'social_twitter' => '/not-an-external-profile',
    ]);
    $config = sbvoid_settings_config($legacy);
    same('链接 <img>', $config['navigation'][0]['items'][0]['title'], 'Link labels must remain literal text for template escaping.');
    same('/about', $config['navigation'][0]['items'][0]['url'], 'Local navigation path was lost.');
    same(2, count($config['navigation']), 'Two- and three-part navigation rows should share named groups.');
    same('/second', $config['navigation'][1]['items'][1]['url'], 'Two-part links should enter 更多.');
    same(2, count($config['social']), 'Only safe external profiles and mail links should render.');
    same('mailto:hello@example.com', $config['social'][1]['url'], 'Email link was not converted.');
    stored(['navigation_links' => '', 'social_github' => '', 'social_email' => '']);
    $config = sbvoid_settings_config($legacy);
    same([], $config['navigation'], 'Saving an empty navigation must clear old entries.');
    same([], $config['social'], 'Saving empty profiles must clear old social links.');
};
$tests['custom CSS cannot close its style element and long text follows core limits'] = static function (): void {
    $payload = "body { color: red; }\n</StYlE><script>alert('x')</script>";
    $result = theme_settings_values('void', array_replace(form_defaults(), ['custom_css' => $payload, 'comment_notice' => '<b>纯文本</b>']));
    same([], $result['errors'], 'The settings form should retain editable CSS and plain text.');
    stored($result['values']);
    $config = sbvoid_settings_config();
    same($payload, $config['custom_css'], 'Stored CSS must remain editable without irreversible escaping.');
    check(!str_contains(sbvoid_custom_css($config['custom_css']), '<'), 'Output CSS can close its style element.');
    check(str_contains(sbvoid_custom_css($config['custom_css']), 'body { color: red; }'), 'CSS sanitizing changed ordinary rules.');
    same('<b>纯文本</b>', $config['comment_notice'], 'Comment notice should remain plain source text for HTML escaping.');
    check(isset(theme_settings_values('void', array_replace(form_defaults(), ['custom_css' => str_repeat('a', 20001)]))['errors']['custom_css']), 'Core must enforce the custom CSS length limit.');
    check(isset(theme_settings_values('void', array_replace(form_defaults(), ['comment_notice' => "bad\xff"]))['errors']['comment_notice']), 'Core must reject invalid UTF-8 before JSON storage.');
};

$failures = 0;
foreach ($tests as $name => $test) {
    $GLOBALS['void_settings_store'] = ['active_theme' => 'another-theme', 'theme_settings_another-theme' => '{"name":"other"}'];
    refresh_theme_cache();
    try { $test(); echo "PASS $name\n"; }
    catch (Throwable $error) { $failures++; fwrite(STDERR, "FAIL $name\n" . $error->getMessage() . "\n"); }
}
echo count($tests) . " settings checks, $failures failures.\n";
exit($failures ? 1 : 0);
