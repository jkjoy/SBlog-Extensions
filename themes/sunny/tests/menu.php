<?php
declare(strict_types=1);

// php themes/sunny/tests/menu.php — fixed public data, no core bootstrap or database.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
const APP_VERSION = '1.14.33';

function menu_assert(bool $condition, string $message): void
{
    $GLOBALS['assertions'] = ($GLOBALS['assertions'] ?? 0) + 1;
    if (!$condition) { throw new RuntimeException($message); }
}
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function setting(string $key, string $default = ''): string { return $key === 'site_name' ? 'Fixture site' : $default; }
function theme_setting(string $key, string $default = '', ?string $theme = null): string { return $default; }
function add_theme_action(string $name, callable $callback, int $priority = 10): void {}
function add_theme_filter(string $name, callable $callback, int $priority = 10): void {}
function theme_action(string $name, array $context = []): void {}
function one(string $sql, array $params = []): ?array { return null; }
function all_rows(string $sql, array $params = []): array { return []; }
function val(string $sql, array $params = []): mixed { return 0; }
function fetch_archive_posts(): array { return []; }
function fetch_published_posts(int $limit = 10, int $offset = 0): array { return []; }
function fetch_categories(): array { return []; }
function social_profile_definitions(): array { return []; }
function tag_index_data(): array { return []; }
function count_published_posts(): int { return 0; }
function is_admin(): bool { return false; }
function theme_manifest(string $slug): array { return ['version' => '1.0.15']; }
function sblog_i18n_locale(): string { return 'zh-CN'; }
function theme_logo_url(): string { return '/fixture.png'; }
function theme_favicon_url(): string { return '/favicon.png'; }
function theme_asset_url(string $file): string { return '/themes/sunny/' . $file; }
function asset_url(string $file): string { return '/' . $file; }
function url_for(string $route, array $params = []): string { return '/' . $route . ($params ? '?' . http_build_query($params) : ''); }
function safe_link_url(string $url): string { return $url; }
function site_footer_text(): string { return 'Fixture footer'; }

function menu_render_fixture(string $mode): void
{
    if ($mode !== 'absent') {
        function sblog_menu_is_managed(): bool { return $GLOBALS['fixture_mode'] !== 'fallback'; }
        function sblog_menu_items(array $context = []): array
        {
            $GLOBALS['fixture_context'] = $context;
            return $GLOBALS['fixture_mode'] === 'empty' ? [] : $GLOBALS['fixture_items'];
        }
        if ($mode !== 'legacy') {
            function sblog_menu_tree(array $context = []): array { return sblog_menu_items($context); }
        }
    }
    $GLOBALS['fixture_mode'] = $mode;
    $leaf = ['id' => 'leaf', 'label' => 'Leaf <script>', 'url' => '/category/themes', 'active' => true];
    $child = ['id' => 'child', 'label' => 'Child', 'url' => '/archives', 'has_active_child' => true, 'children' => [$leaf]];
    $GLOBALS['fixture_items'] = [
        ['id' => 'parent', 'label' => 'Parent & "Link"', 'url' => '/links?x="&y=2', 'new_tab' => true, 'has_active_child' => true, 'children' => [$child]],
        ['id' => 'home', 'label' => 'Home custom', 'url' => '/home'],
        ['id' => 'three', 'label' => 'Three', 'url' => '/three'],
        ['id' => 'four', 'label' => 'Four', 'url' => '/four'],
        ['id' => 'five', 'label' => 'Five', 'url' => '/five'],
    ];
    if ($mode === 'legacy') { foreach ($GLOBALS['fixture_items'] as &$item) { unset($item['children']); } unset($item); }
    require dirname(__DIR__) . '/functions.php';
    $_GET = ['a' => 'category', 'slug' => 'themes'];
    $active = 'home'; $title = 'Fixture category'; $siteName = 'Fixture site'; $fullTitle = $title;
    $description = ''; $keywords = ''; $customHeadCode = ''; $bodyClass = 'sunny-theme'; $admin = null;
    $navPages = [['slug' => 'legacy', 'title' => 'Legacy page']]; $content = '<article>Fixture</article>'; $flash = null;
    $themeContext = ['active' => $active, 'title' => $title, 'site_name' => $siteName];
    require dirname(__DIR__) . '/layout.php';
}

function menu_fixture(string $mode): DOMXPath
{
    $process = proc_open([PHP_BINARY, __FILE__, '--render', $mode], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    menu_assert(is_resource($process), 'Renderer starts'); fclose($pipes[0]);
    $html = stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
    menu_assert(proc_close($process) === 0 && $errors === '', $mode . ' renders without errors: ' . $errors);
    $dom = new DOMDocument(); $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET); libxml_clear_errors(); libxml_use_internal_errors($previous);
    return new DOMXPath($dom);
}

try {
    if (($argv[1] ?? '') === '--render') { menu_render_fixture((string)$argv[2]); exit; }
    foreach (['absent', 'fallback'] as $mode) {
        $x = menu_fixture($mode);
        menu_assert($x->query('//*[@id="sunny-nav"]/a[@class="sunny-nav-home"]')->length === 1, $mode . ': default main navigation remains');
        menu_assert($x->query('//nav[contains(@class,"sunny-mobile-dock")]//a[@class="sunny-dock-home"]')->length === 1, $mode . ': default dock remains');
    }
    $x = menu_fixture('tree');
    menu_assert($x->query('//*[@id="sunny-nav"]/details/details')->length === 0, 'Children stay inside the dropdown panel');
    menu_assert($x->query('//*[@id="sunny-nav"]/details/div/details/div/a[@data-menu-id="leaf" and @aria-current="page"]')->length === 1, 'Three levels preserve leaf page state');
    menu_assert($x->query('//*[@id="sunny-nav"]//details[contains(@class,"sunny-menu-active")]')->length === 2, 'Both active ancestors are highlighted');
    menu_assert($x->query('//*[@id="sunny-nav"]//summary/a[@data-menu-id="parent" and @target="_blank" and @rel="noopener noreferrer"]')->length === 1, 'Parent has an independent safe new-tab link');
    menu_assert($x->query('//*[@id="sunny-nav"]//a[@data-menu-id="leaf"]')->item(0)->textContent === 'Leaf <script>', 'Labels render as escaped text');
    menu_assert($x->query('//*[@id="sunny-nav"]//a[@data-menu-id="parent"]')->item(0)->getAttribute('href') === '/links?x="&y=2', 'URLs remain escaped and complete');
    menu_assert($x->query('//nav[contains(@class,"sunny-mobile-dock")]//a[@data-menu-id]')->length === 4, 'Dock contains the first four root entries');
    menu_assert($x->query('//nav[contains(@class,"sunny-mobile-dock")]//a[@data-menu-id="leaf" or @data-menu-id="five"]')->length === 0, 'Dock does not flatten children or add default entries');
    menu_assert($x->query('//*[@id="sunny-nav"]/a[@data-menu-id="five"]')->length === 1, 'Complete main navigation retains remaining roots');
    menu_assert($x->query('//button[@data-sunny-search-open]')->length === 2 && $x->query('//div[@class="sunny-tools"]/a[@href="/login"]')->length === 1, 'Search and account utilities survive');
    $x = menu_fixture('legacy');
    menu_assert($x->query('//*[@id="sunny-nav"]/a[@data-menu-id]')->length === 5, 'Plugin 1.0.0 flat API remains supported');
    $x = menu_fixture('empty');
    menu_assert($x->query('//*[@id="sunny-nav"]//a')->length === 0, 'Managed empty main menu stays empty');
    menu_assert($x->query('//nav[contains(@class,"sunny-mobile-dock")]//a')->length === 0, 'Managed empty dock has no fallback links');
    menu_assert($x->query('//nav[contains(@class,"sunny-mobile-dock")]/button[@data-sunny-search-open]')->length === 1, 'Empty dock retains search');
    echo 'Sunny menu: ' . $GLOBALS['assertions'] . " assertions passed.\n";
} catch (Throwable $exception) { fwrite(STDERR, $exception->getMessage() . "\n"); exit(1); }
