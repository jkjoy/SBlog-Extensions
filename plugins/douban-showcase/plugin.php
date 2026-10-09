<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/covers.php';
require_once __DIR__ . '/views.php';

add_plugin_filter('route_action', static function (string $action, array $context): string {
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($action === 'page' && ($_GET['slug'] ?? '') === 'douban'
        && is_string($path) && rtrim(rawurldecode($path), '/') === app_path('/douban')) {
        $_GET['a'] = 'douban';
        $_REQUEST['a'] = 'douban';
        unset($_GET['slug'], $_REQUEST['slug']);
        return 'douban';
    }
    return $action;
}, 25);

add_plugin_action('request', static function (array $context): void {
    $action = (string)($context['action'] ?? '');
    if ($action === 'douban_showcase_cover') {
        sblog_douban_render_cover();
    }
    if ($action === 'admin_douban') {
        require_once __DIR__ . '/admin.php';
        sblog_douban_admin_request();
        exit;
    }
    if ($action === 'douban') {
        $config = sblog_douban_config();
        [$type, $status] = sblog_douban_selection($_GET);
        $snapshot = sblog_douban_snapshot($config, $type, $status);
        render_layout($config['page_title'], sblog_douban_render($snapshot, $config, $type, $status), [
            'mode' => 'public',
            'active' => 'douban',
            'description' => '豆瓣公开的观影、阅读和音乐记录。',
        ]);
        exit;
    }
});

function sblog_douban_home_visible(array $context, array $config): bool
{
    return ($context['active'] ?? '') === 'home'
        && ($GLOBALS['sblog_current_action'] ?? '') === 'home'
        && (int)($_GET['p'] ?? 1) <= 1
        && $config['home_widget'] && $config['user_id'] !== '';
}

add_theme_action('content_after', static function (array $context): string {
    $config = sblog_douban_config();
    if (!sblog_douban_home_visible($context, $config)) {
        return '';
    }
    return sblog_douban_render(sblog_douban_snapshot($config), $config, 'movie', 'collect', true);
}, 25);

add_theme_action('head', static function (array $context): string {
    if (($context['active'] ?? '') !== 'douban' && !sblog_douban_home_visible($context, sblog_douban_config())) {
        return '';
    }
    return '<link rel="stylesheet" href="' . h(plugin_asset_url('douban-showcase', 'assets/style.css') . '?v=1.0.2') . '">';
}, 25);

add_theme_action('body_close', static function (array $context): string {
    if (($context['active'] ?? '') !== 'douban' && !sblog_douban_home_visible($context, sblog_douban_config())) {
        return '';
    }
    return '<script defer src="' . h(plugin_asset_url('douban-showcase', 'assets/script.js') . '?v=1.0.2') . '"></script>';
}, 25);

add_plugin_filter('output_html', static function (string $html, array $context): string {
    if (!str_contains($html, 'text-site--default') || !str_contains($html, 'theme-public')) {
        return $html;
    }
    $config = sblog_douban_config();
    if (!$config['show_nav']) {
        return $html;
    }
    $link = '<a' . (($context['action'] ?? '') === 'douban' ? ' aria-current="page"' : '')
        . ' href="' . h(sblog_douban_url()) . '">' . h($config['page_title']) . '</a>';
    return preg_replace_callback('~(<nav\b[^>]*class="text-nav"[^>]*>)(.*?)(</nav>)~s', static function (array $match) use ($link): string {
        return $match[1] . $match[2] . $link . $match[3];
    }, $html, 1) ?? $html;
}, 25);
