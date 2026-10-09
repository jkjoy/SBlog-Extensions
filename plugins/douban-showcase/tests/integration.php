<?php

declare(strict_types=1);

// CLI-only integration checks use an in-memory database, never the installed blog.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

define('PLUGINS_DIR', dirname(__DIR__, 2));
$doubanIntegrationCache = rtrim(sys_get_temp_dir(), '/\\') . '/sblog-douban-integration-' . bin2hex(random_bytes(8));
if (!mkdir($doubanIntegrationCache, 0700)) {
    fwrite(STDERR, "Could not create the isolated integration cache.\n");
    exit(1);
}
define('CACHE_DIR', $doubanIntegrationCache);
register_shutdown_function(static function () use ($doubanIntegrationCache): void {
    foreach (glob($doubanIntegrationCache . '/*') ?: [] as $file) {
        if (is_file($file)) { unlink($file); }
    }
    rmdir($doubanIntegrationCache);
});
$GLOBALS['douban_test_db'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$GLOBALS['douban_test_admin'] = false;
$GLOBALS['douban_test_pretty'] = false;
$_SERVER['SCRIPT_NAME'] = '/blog/index.php';

function db(): PDO { return $GLOBALS['douban_test_db']; }
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function str_len_u(string $value): int { return preg_match_all('/./us', $value); }
function app_path(string $path): string {
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? '/blog/index.php'));
    $base = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return ($base === '.' ? '' : $base) . '/' . ltrim($path, '/');
}
function script_url(): string { return app_path('/index.php'); }
function use_pretty_url(): bool { return $GLOBALS['douban_test_pretty']; }
function url_with_query(string $url, array $params): string { return $url . '?' . http_build_query($params); }
function url_for(string $route): string { return '/blog/index.php?a=' . $route; }
function plugin_asset_url(string $slug, string $path): string { return '/blog/plugins/' . $slug . '/' . $path; }
function add_plugin_action(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['douban_test_actions'][$hook][] = $callback; }
function add_plugin_filter(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['douban_test_filters'][$hook][] = $callback; }
function add_theme_action(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['douban_test_themes'][$hook][] = $callback; }
function require_admin(): void {
    if (!$GLOBALS['douban_test_admin']) { throw new RuntimeException('ADMIN_DENIED'); }
}
function verify_csrf(): void {
    if (($_POST['csrf_token'] ?? '') !== 'valid-test-token') { throw new RuntimeException('CSRF_DENIED'); }
}
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="valid-test-token">'; }
function render_admin_sidebar(string $active): string { return ''; }
function render_admin_topbar(string $title, string $label, string $url): string { return '<h1>' . h($title) . '</h1>'; }
function render_layout(string $title, string $content, array $options): void { $GLOBALS['douban_test_html'] = $content; }

require_once dirname(__DIR__) . '/plugin.php';
require_once dirname(__DIR__) . '/admin.php';

function douban_integration_assert(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}

$tests = [];
$tests['configuration accepts numeric/custom IDs and official profile URLs'] = static function (): void {
    foreach (['1000001', 'ahbei', 'imsunpw', 'test-id_2', 'https://www.douban.com/people/ahbei/'] as $id) {
        $input = array_merge(sblog_douban_defaults(), ['user_id' => $id]);
        $result = sblog_douban_validate_config($input, sblog_douban_defaults());
        douban_integration_assert($result['errors'] === [], 'A valid account was rejected: ' . $id);
        douban_integration_assert(!str_contains($result['config']['user_id'], '/'), 'Profile URL was not normalized.');
    }
    foreach (['../cache', '豆瓣昵称', 'https://evil.example/people/ahbei/', 'user?x=1', str_repeat('a', 65)] as $id) {
        $result = sblog_douban_validate_config(array_merge(sblog_douban_defaults(), ['user_id' => $id]), []);
        douban_integration_assert($result['errors'] !== [], 'An invalid account was accepted.');
    }
    $result = sblog_douban_validate_config(array_merge(sblog_douban_defaults(), ['max_pages' => '100', 'cache_minutes' => '0', 'page_size' => []]), []);
    douban_integration_assert(count($result['errors']) === 3, 'Out of range or array inputs must be rejected.');
};
$tests['settings round trip through the isolated plugin table'] = static function (): void {
    $config = array_merge(sblog_douban_defaults(), ['user_id' => 'ahbei', 'home_widget' => true]);
    sblog_douban_save_config($config);
    douban_integration_assert(sblog_douban_config() === $config, 'Stored settings were not preserved.');
    $tables = db()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    douban_integration_assert($tables === ['plugin_douban_settings'], 'Plugin must not create or mutate core tables.');
};
$tests['routing stays within a subdirectory and rejects invalid selections'] = static function (): void {
    douban_integration_assert(sblog_douban_selection(['type' => [], 'status' => 'wrong']) === ['movie', 'collect'], 'Invalid query values must fall back safely.');
    douban_integration_assert(sblog_douban_selection(['type' => 'book', 'status' => 'wish']) === ['book', 'wish'], 'Valid selections must survive.');
    $url = sblog_douban_url('douban', ['type' => 'music', 'a' => 'evil']);
    parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
    douban_integration_assert(str_starts_with($url, '/blog/index.php?') && $params['a'] === 'douban', 'Query links must preserve the blog path and plugin action.');
};
$tests['pretty links preserve filters and keep admin actions on the query endpoint'] = static function (): void {
    $GLOBALS['douban_test_pretty'] = true;
    foreach (['/index.php' => '/douban', '/blog/index.php' => '/blog/douban'] as $script => $expected) {
        $_SERVER['SCRIPT_NAME'] = $script;
        douban_integration_assert(sblog_douban_url() === $expected, 'Pretty showcase link must preserve the deployment prefix.');
        $url = sblog_douban_url('douban', ['type' => 'book', 'status' => 'wish', 'a' => 'admin']);
        parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
        douban_integration_assert(parse_url($url, PHP_URL_PATH) === $expected && $params === ['type' => 'book', 'status' => 'wish'], 'Pretty links must keep filters and discard supplied actions.');
        douban_integration_assert(sblog_douban_url('admin_douban') === $script . '?a=admin_douban', 'Admin must use its existing query endpoint.');
    }
    $GLOBALS['douban_test_pretty'] = false;
    $_SERVER['SCRIPT_NAME'] = '/blog/index.php';
    douban_integration_assert(sblog_douban_url() === '/blog/index.php?a=douban', 'Disabling pretty links must restore the query entry.');
};
$tests['pretty route filter intercepts only the douban page and preserves record selections'] = static function (): void {
    $filter = $GLOBALS['douban_test_filters']['route_action'][0];
    $_SERVER['REQUEST_URI'] = '/blog/douban?type=music&status=do';
    $_GET = ['a' => 'page', 'slug' => 'douban', 'type' => 'music', 'status' => 'do'];
    $_REQUEST = $_GET;
    douban_integration_assert($filter('page', []) === 'douban', 'The core douban page route must dispatch to the plugin.');
    douban_integration_assert($_GET['a'] === 'douban' && $_REQUEST['a'] === 'douban' && !isset($_GET['slug'], $_REQUEST['slug']), 'Matched route must update both request arrays and clear the page slug.');
    douban_integration_assert(sblog_douban_selection($_GET) === ['music', 'do'], 'Route dispatch must keep classification and record status.');
    foreach (['/douban', '/douban/', '/blog/douban', '/blog/douban/'] as $path) {
        $_SERVER['SCRIPT_NAME'] = str_starts_with($path, '/blog/') ? '/blog/index.php' : '/index.php';
        $_SERVER['REQUEST_URI'] = $path;
        $_GET = ['a' => 'page', 'slug' => 'douban'];
        $_REQUEST = $_GET;
        douban_integration_assert($filter('page', []) === 'douban', 'Root and subdirectory pretty paths must accept a trailing slash.');
    }
    $_SERVER['SCRIPT_NAME'] = '/blog/index.php';
    foreach (['/blog/index.php?a=page&slug=douban', '/blog/pages/douban', '/blog/douban/extra', '/other/douban'] as $path) {
        $_SERVER['REQUEST_URI'] = $path;
        $_GET = ['a' => 'page', 'slug' => 'douban'];
        $_REQUEST = $_GET;
        douban_integration_assert($filter('page', []) === 'page', 'Only the exact showcase path may intercept the core page action.');
    }
    $_SERVER['REQUEST_URI'] = '/blog/douban';
    foreach (['about', 'douban-extra', 'douban/other', ['douban']] as $slug) {
        $_GET = ['a' => 'page', 'slug' => $slug];
        $_REQUEST = $_GET;
        $before = $_GET;
        douban_integration_assert($filter('page', []) === 'page' && $_GET === $before && $_REQUEST === $before, 'Other page routes must be unchanged.');
    }
    $_GET = ['a' => 'admin_douban', 'slug' => 'douban'];
    $_REQUEST = $_GET;
    douban_integration_assert($filter('admin_douban', []) === 'admin_douban', 'The pretty filter must preserve admin actions.');
    $_GET = ['a' => 'douban'];
    $_REQUEST = $_GET;
    douban_integration_assert($filter('douban', []) === 'douban', 'Existing query showcase entry must remain supported.');
    $_GET = [];
    $_REQUEST = [];
};
$tests['admin save and refresh require an administrator and CSRF'] = static function (): void {
    $_SERVER['REQUEST_METHOD'] = 'POST';
    foreach (['save', 'refresh'] as $operation) {
        $_POST = ['operation' => $operation, 'user_id' => 'changed'];
        $GLOBALS['douban_test_admin'] = false;
        try { sblog_douban_admin_request(); throw new RuntimeException('Authorization did not run.'); }
        catch (RuntimeException $exception) { douban_integration_assert($exception->getMessage() === 'ADMIN_DENIED', 'Guests must be denied before processing.'); }
        $GLOBALS['douban_test_admin'] = true;
        try { sblog_douban_admin_request(); throw new RuntimeException('CSRF did not run.'); }
        catch (RuntimeException $exception) { douban_integration_assert($exception->getMessage() === 'CSRF_DENIED', 'CSRF must precede saves or network activity.'); }
    }
    douban_integration_assert(sblog_douban_config()['user_id'] === 'ahbei', 'Rejected requests must preserve settings.');
    $_POST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
};
$tests['view escapes upstream content and blocks arbitrary URLs'] = static function (): void {
    $snapshot = [
        'status' => 'ok', 'message' => '', 'updated_at' => time(), 'total' => 1, 'truncated' => false,
        'profile' => ['id' => 'ahbei', 'name' => '<script>alert(1)</script>', 'avatar' => 'https://evil.example/avatar.jpg', 'url' => 'javascript:alert(1)'],
        'items' => [['id' => '123', 'title' => '<script>alert(2)</script>', 'url' => 'javascript:alert(2)', 'cover_url' => 'https://evil.example/cover.jpg', 'rating' => 5, 'date' => '2026-10-07', 'comment' => '<img src=x onerror=alert(3)>', 'intro' => '<script>alert(4)</script>', 'tags' => ['<script>alert(5)</script>']]],
    ];
    $config = array_merge(sblog_douban_defaults(), ['user_id' => 'ahbei', 'page_title' => '<script>alert(6)</script>']);
    foreach ([false, true] as $compact) {
        $html = sblog_douban_render($snapshot, $config, 'movie', 'collect', $compact);
        foreach (['<script>alert(', '<img src=x', 'javascript:', 'src="https://evil.example'] as $unsafe) {
            douban_integration_assert(!str_contains($html, $unsafe), 'Unsafe content reached the public view: ' . $unsafe);
        }
        douban_integration_assert(str_contains($html, '&lt;script&gt;alert(2)&lt;/script&gt;'), 'Escaped record titles should remain readable.');
    }
    $GLOBALS['douban_test_admin'] = true;
    sblog_douban_admin($config);
    douban_integration_assert(!str_contains($GLOBALS['douban_test_html'], '<script>alert(6)</script>'), 'Settings inputs must escape configuration.');
};
$tests['public records omit account details and list links while keeping subject links'] = static function (): void {
    $config = array_merge(sblog_douban_defaults(), ['user_id' => 'imsunpw']);
    foreach (array_keys(sblog_douban_types()) as $type) {
        $subjectUrl = 'https://' . $type . '.douban.com/subject/123/';
        $snapshot = [
            'status' => 'ok', 'message' => '', 'updated_at' => time(), 'total' => 50, 'truncated' => true,
            'profile' => ['id' => 'imsunpw', 'name' => 'ACCOUNT_NAME_SENTINEL', 'avatar' => 'https://img3.doubanio.com/icon/u123456.jpg', 'url' => 'https://www.douban.com/people/imsunpw/'],
            'items' => [['id' => '123', 'title' => '公开条目', 'url' => $subjectUrl, 'cover_url' => '', 'rating' => 4, 'date' => '2026-10-07', 'comment' => '公开短评', 'intro' => '公开介绍', 'tags' => ['公开标签']]],
        ];
        foreach (['ok', 'stale', 'error', 'private', 'unconfigured'] as $state) {
            $snapshot['status'] = $state;
            if (in_array($state, ['error', 'private', 'unconfigured'], true)) { $snapshot['items'] = []; }
            foreach ([false, true] as $compact) {
                $html = sblog_douban_render($snapshot, $config, $type, 'collect', $compact);
                foreach (['ACCOUNT_NAME_SENTINEL', 'imsunpw', '/people/', '/icon/u123456.jpg'] as $accountDetail) {
                    douban_integration_assert(!str_contains($html, $accountDetail), 'Account details or a list-level Douban link reached the public view: ' . $accountDetail);
                }
                if (in_array($state, ['ok', 'stale'], true)) {
                    douban_integration_assert(str_contains($html, 'href="' . $subjectUrl . '"'), 'The public record must keep its individual subject link.');
                    douban_integration_assert(str_contains($html, '公开条目') && str_contains($html, '公开短评'), 'Removing account details must preserve the public records.');
                }
            }
        }
    }
};
$tests['cover URLs use the local registered endpoint in root and subdirectory deployments'] = static function (): void {
    $source = 'https://img3.doubanio.com/view/photo/s_ratio_poster/public/p2527119568.jpg';
    foreach (['/index.php', '/blog/index.php'] as $script) {
        $_SERVER['SCRIPT_NAME'] = $script;
        foreach ([false, true] as $pretty) {
            $GLOBALS['douban_test_pretty'] = $pretty;
            $url = sblog_douban_cover_url($source);
            parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
            douban_integration_assert(parse_url($url, PHP_URL_PATH) === $script && !isset(parse_url($url)['host']), 'Covers must use the blog entry point in every URL mode.');
            douban_integration_assert(($params['a'] ?? '') === 'douban_showcase_cover' && ($params['key'] ?? '') === hash('sha256', $source), 'The cover endpoint must use a registered source key.');
            douban_integration_assert(!isset($params['url']) && !str_contains($url, 'doubanio.com'), 'Visitor URLs must not contain an arbitrary fetch target.');
        }
    }
    foreach (['https://evil.example/cover.jpg', 'https://img3.doubanio.com.evil.example/view/photo/x.jpg', 'https://img3.doubanio.com/icon/u123.jpg', 'https://img3.doubanio.com/view/photo/../../icon/u123.jpg', 'https://img3.doubanio.com:444/view/photo/x.jpg', "https://img3.doubanio.com/view/photo/x.jpg\n"] as $source) {
        douban_integration_assert(sblog_douban_cover_url($source) === '', 'An unsafe host, non-cover path, or malformed cover URL was registered.');
    }
    $_SERVER['SCRIPT_NAME'] = '/blog/index.php';
    $GLOBALS['douban_test_pretty'] = false;
};
$tests['record covers render locally and malformed cover fields keep a usable record'] = static function (): void {
    $sources = [
        'movie' => 'https://img3.doubanio.com/view/photo/s_ratio_poster/public/p2527119568.jpg',
        'book' => 'https://img1.doubanio.com/view/subject/s/public/s1111111.jpg',
        'music' => 'https://img2.doubanio.com/view/subject/s/public/s2222222.jpg',
    ];
    foreach ($sources as $type => $source) {
        $item = ['id' => '123', 'title' => '有封面的记录', 'url' => 'https://' . $type . '.douban.com/subject/123/', 'cover_url' => $source];
        foreach ([false, true] as $compact) {
            $html = sblog_douban_view_item($item, $type, $compact);
            preg_match('/<img\b[^>]*\bsrc="([^"]+)"/', $html, $match);
            douban_integration_assert(isset($match[1]), 'A valid record cover must produce an image.');
            $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
            douban_integration_assert(parse_url($url, PHP_URL_PATH) === '/blog/index.php' && ($params['a'] ?? '') === 'douban_showcase_cover' && ($params['key'] ?? '') === hash('sha256', $source), 'Rendered record images must use the local registered endpoint.');
            douban_integration_assert(!str_contains($html, 'src="https://'), 'Public records must not hotlink a remote cover.');
        }
        $item['cover_url'] = 'https://evil.example/cover.jpg';
        $html = sblog_douban_view_item($item, $type);
        douban_integration_assert(!str_contains($html, '<img') && str_contains($html, '有封面的记录') && str_contains($html, '/subject/123/'), 'An invalid cover must preserve the usable record and its link.');
    }
};
$tests['theme assets cover standalone pages and first-page home widgets'] = static function (): void {
    $head = $GLOBALS['douban_test_themes']['head'][0];
    $body = $GLOBALS['douban_test_themes']['body_close'][0];
    douban_integration_assert(str_contains($head(['active' => 'douban']), '/blog/plugins/douban-showcase/assets/style.css'), 'Standalone page stylesheet must load.');
    douban_integration_assert(str_contains($body(['active' => 'douban']), 'assets/script.js'), 'Standalone page script must load.');
    $GLOBALS['sblog_current_action'] = 'home';
    $_GET = [];
    douban_integration_assert($head(['active' => 'home']) !== '', 'Enabled home widget requires assets before content rendering.');
    $_GET = ['p' => 2];
    douban_integration_assert($head(['active' => 'home']) === '', 'Later homepage pages must not load widget assets.');
    $_GET = [];
    $GLOBALS['sblog_current_action'] = 'category';
    douban_integration_assert($head(['active' => 'home']) === '', 'Category pages must not fetch or load a home widget.');
};
$tests['navigation honors settings and preserves custom and admin layouts'] = static function (): void {
    $filter = $GLOBALS['douban_test_filters']['output_html'][0];
    $html = '<html><body class="theme-public"><div class="text-site text-site--default"><nav class="text-nav"><a href="/blog/">首页</a></nav></div></body></html>';
    douban_integration_assert(str_contains($filter($html, ['action' => 'douban']), 'aria-current="page" href="/blog/index.php?a=douban"'), 'Default navigation must have an active plugin link.');
    $GLOBALS['douban_test_pretty'] = true;
    douban_integration_assert(str_contains($filter($html, ['action' => 'douban']), 'aria-current="page" href="/blog/douban"'), 'Pretty navigation must point to the new route.');
    $GLOBALS['douban_test_pretty'] = false;
    $custom = str_replace('text-site--default', 'custom-theme', $html);
    douban_integration_assert($filter($custom, ['action' => 'home']) === $custom, 'Custom layouts must retain their navigation.');
    $admin = '<html><body class="theme-admin"><nav class="text-nav"></nav></body></html>';
    douban_integration_assert($filter($admin, ['action' => 'admin_plugins']) === $admin, 'Admin navigation must be unchanged.');
    sblog_douban_save_config(array_merge(sblog_douban_config(), ['show_nav' => false]));
    douban_integration_assert($filter($html, ['action' => 'home']) === $html, 'Navigation toggle must work.');
};

$failed = 0;
foreach ($tests as $name => $test) {
    try { $test(); fwrite(STDOUT, "PASS {$name}\n"); }
    catch (Throwable $exception) { $failed++; fwrite(STDERR, "FAIL {$name}: {$exception->getMessage()}\n"); }
}
fwrite(STDOUT, sprintf("\n%d integration checks, %d failures. No requests sent to Douban.\n", count($tests), $failed));
exit($failed === 0 ? 0 : 1);
