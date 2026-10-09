<?php

declare(strict_types=1);

if (!defined('PLUGINS_DIR')) {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/views.php';

function sblog_steam_route_action(string $action, array $context = []): string
{
    if ($action !== 'page') {
        return $action;
    }
    $path = parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_string($path)) {
        return $action;
    }
    $path = rtrim(rawurldecode($path), '/');
    if (!in_array($path, [app_path('/steam'), script_url() . '/steam'], true)) {
        return $action;
    }
    $_GET['a'] = 'steam';
    $_REQUEST['a'] = 'steam';
    return 'steam';
}

add_plugin_filter('route_action', 'sblog_steam_route_action');

add_plugin_action('request', static function (array $context): void {
    $action = (string)($context['action'] ?? '');
    if ($action === 'admin_steam') {
        require_once __DIR__ . '/admin.php';
        sblog_steam_admin_request();
        exit;
    }
    if ($action === 'steam') {
        $config = sblog_steam_config();
        $snapshot = sblog_steam_snapshot($config);
        render_layout($config['page_title'], sblog_steam_render($snapshot, $config), [
            'mode' => 'public',
            'active' => 'steam',
            'description' => 'Steam 玩家资料、最近游玩与游戏收藏。',
        ]);
        exit;
    }
});

add_theme_action('content_after', static function (array $context): string {
    if (($context['active'] ?? '') !== 'home' || ($GLOBALS['sblog_current_action'] ?? '') !== 'home' || (int)($_GET['p'] ?? 1) > 1) {
        return '';
    }
    $config = sblog_steam_config();
    if (!$config['home_widget'] || $config['steam_id'] === '' || $config['api_key'] === '') {
        return '';
    }
    return sblog_steam_render(sblog_steam_snapshot($config), $config, true);
}, 20);

add_plugin_filter('output_html', static function (string $html, array $context): string {
    if (!str_contains($html, 'theme-public') || stripos($html, '</head>') === false) {
        return $html;
    }
    $config = sblog_steam_config();
    if ($config['show_nav']) {
        $link = '<a' . (($context['action'] ?? '') === 'steam' ? ' aria-current="page"' : '')
            . ' href="' . h(sblog_steam_url()) . '">' . h($config['page_title']) . '</a>';
        // Only the documented default theme navigation is modified.
        $html = preg_replace_callback('~(<nav\b[^>]*class="text-nav"[^>]*>)(.*?)(</nav>)~s', static function (array $match) use ($link): string {
            return $match[1] . $match[2] . $link . $match[3];
        }, $html, 1) ?? $html;
    }
    if (str_contains($html, 'data-steam-showcase')) {
        $style = plugin_asset_url('steam-showcase', 'assets/style.css');
        $script = plugin_asset_url('steam-showcase', 'assets/script.js');
        $version = rawurlencode('1.0.1');
        $html = str_ireplace('</head>', '<link rel="stylesheet" href="' . h($style . '?v=' . $version) . '">' . "\n</head>", $html);
        $html = str_ireplace('</body>', '<script defer src="' . h($script . '?v=' . $version) . '"></script>' . "\n</body>", $html);
    }
    return $html;
}, 20);
