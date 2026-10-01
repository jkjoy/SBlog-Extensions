<?php

declare(strict_types=1);

// Every layout runs in its own process with its real theme helpers and plugin.
// Core stubs supply a small empty site; navigation is never stubbed.
const MENU_THEME_TEST_LABEL = '<img data-menu-payload="1"> & "Link"';
const MENU_THEME_TEST_URL = '/menu-fixture?x="quoted"&n=2';

function setting(string $name, string $default = ''): string
{
    return (string)($GLOBALS['menu_theme_test_settings'][$name] ?? $default);
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sblog_t(string $value, array $replacements = []): string
{
    return strtr($value, $replacements);
}

function str_len_u(string $value): int
{
    return mb_strlen($value, 'UTF-8');
}

function str_sub_u(string $value, int $offset, ?int $length = null): string
{
    return mb_substr($value, $offset, $length, 'UTF-8');
}

function url_for(string $route, array $parameters = []): string
{
    return '/' . $route . ($parameters ? '?' . http_build_query($parameters) : '');
}

function content_permalink(array $post): string
{
    return '/page/' . rawurlencode((string)$post['slug']);
}

function one(string $sql, array $parameters = []): ?array
{
    return null;
}

function all_rows(string $sql, array $parameters = []): array
{
    return [];
}

function val(string $sql, array $parameters = []): mixed
{
    return 0;
}

function add_theme_filter(string $name, callable $callback, int $priority = 10): void {}
function add_theme_action(string $name, callable $callback, int $priority = 10): void {}
function add_plugin_filter(string $name, callable $callback, int $priority = 10): void {}
function add_plugin_action(string $name, callable $callback, int $priority = 10): void {}
function theme_action(string $name, array $context = []): void {}
function plugin_action(string $name, array $context = []): void {}
function sblog_i18n_locale(): string { return 'zh-CN'; }
function sblog_i18n_head(): string { return ''; }
function site_footer_text(): string { return 'Fixture footer'; }
function theme_logo_url(): string { return '/fixture/logo.png'; }
function theme_favicon_url(): string { return '/fixture/favicon.ico'; }
function asset_url(string $path): string { return '/' . $path; }
function theme_asset_url(string $path): string { return '/themes/' . $GLOBALS['menu_theme_test_slug'] . '/' . $path; }
function active_theme_file(string $path): string { return dirname(__DIR__) . '/themes/' . $GLOBALS['menu_theme_test_slug'] . '/' . $path; }
function count_published_posts(): int { return 0; }
function tag_index_data(): array { return []; }
function fetch_categories(): array { return []; }
function social_profile_definitions(): array { return []; }
function use_pretty_url(): bool { return true; }
function admin_is_online(): bool { return false; }
function is_admin(): bool { return (bool)$GLOBALS['menu_theme_test_admin']; }
function public_quote(): string { return 'Fixture quote'; }
function csrf_token(): string { return 'fixture-token'; }
function gravatar_url(string $email, int $size = 80): string { return '/fixture/avatar.png'; }
function safe_link_url(string $url): string { return trim($url) !== '' ? $url : '#'; }

function menu_theme_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function menu_theme_test_child(string $slug, string $mode, bool $guest): void
{
    define('APP_VERSION', '1.14.5');
    $GLOBALS['menu_theme_test_slug'] = $slug;
    $GLOBALS['menu_theme_test_admin'] = !$guest;
    $items = [
        ['id' => 'fixture-archive', 'type' => 'route', 'reference' => 'archives', 'label' => 'Managed archives', 'url' => '', 'enabled' => true, 'new_tab' => false],
        ['id' => 'fixture-custom', 'type' => 'custom', 'reference' => '', 'label' => MENU_THEME_TEST_LABEL, 'url' => MENU_THEME_TEST_URL, 'enabled' => true, 'new_tab' => true],
        ['id' => 'fixture-hidden', 'type' => 'route', 'reference' => 'tags', 'label' => 'Hidden item', 'url' => '', 'enabled' => false, 'new_tab' => false],
    ];
    if ($mode === 'home') {
        array_unshift($items, ['id' => 'fixture-home', 'type' => 'route', 'reference' => 'home', 'label' => 'Managed home', 'url' => '', 'enabled' => true, 'new_tab' => false]);
    }
    $GLOBALS['menu_theme_test_settings'] = ['menu_manager_config' => json_encode([
        'version' => 1,
        'managed' => in_array($mode, ['managed', 'empty', 'home'], true),
        'items' => $mode === 'empty' ? [] : $items,
    ], JSON_THROW_ON_ERROR)];

    if ($mode !== 'absent') {
        require dirname(__DIR__) . '/plugins/menu-manager/plugin.php';
    }
    require dirname(__DIR__) . '/themes/' . $slug . '/functions.php';
    $theme = ['version' => 'fixture'];
    $active = 'archives';
    $_GET = ['a' => 'archives'];
    $siteName = 'Fixture site';
    $title = 'Fixture title';
    $fullTitle = 'Fixture title - Fixture site';
    $description = 'Fixture description';
    $bodyClass = 'theme-public fixture-' . $slug;
    $admin = $guest ? null : ['id' => 1];
    $navPages = [['id' => 1, 'kind' => 'page', 'slug' => 'legacy-fixture', 'title' => 'Legacy page fixture']];
    $themeContext = compact('theme', 'active', 'siteName', 'title', 'navPages');
    $content = '<article id="fixture-content">Content &amp; navigation-independent.</article>';
    $flash = null;
    require dirname(__DIR__) . '/themes/' . $slug . '/layout.php';
}

function menu_theme_test_render(string $slug, string $mode, bool $guest = false): string
{
    $process = proc_open([PHP_BINARY, __FILE__, '--render', $slug, $mode, $guest ? 'guest' : 'admin'], [
        0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
    ], $pipes);
    menu_theme_test_assert(is_resource($process), $slug . ': could not start the renderer');
    fclose($pipes[0]);
    $html = stream_get_contents($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    menu_theme_test_assert($status === 0 && $errors === '', $slug . ' (' . $mode . '): ' . trim($errors));
    menu_theme_test_assert(is_string($html) && str_contains($html, '</html>'), $slug . ': layout did not render a document');
    return $html;
}

function menu_theme_test_dom(string $html): DOMXPath
{
    $document = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    try {
        menu_theme_test_assert($document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET), 'Could not parse rendered HTML');
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    return new DOMXPath($document);
}

function menu_theme_test_links(DOMXPath $xpath, DOMNode $menu): array
{
    $links = [];
    foreach ($xpath->query('.//a', $menu) as $link) {
        $links[] = [
            $link->getAttribute('href'), trim($link->textContent), $link->getAttribute('class'),
            $link->getAttribute('target'), $link->getAttribute('rel'), $link->getAttribute('aria-current'),
        ];
    }
    return $links;
}

function menu_theme_test_utilities(DOMXPath $xpath): array
{
    $utilities = [];
    foreach ($xpath->query('//a[@href="/admin" or @href="/login" or @href="/rss" or @href="/sitemap"] | //input[@type="search"] | //button') as $element) {
        $utilities[] = $xpath->document->saveHTML($element);
    }
    return $utilities;
}

try {
    if (($argv[1] ?? '') === '--render') {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });
        ob_start();
        try {
            menu_theme_test_child((string)$argv[2], (string)$argv[3], ($argv[4] ?? '') === 'guest');
            ob_end_flush();
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }
        exit(0);
    }

    // Narrow selectors identify the menu itself, including each separate drawer.
    $themes = [
        'adams' => ['//nav[@class="header_nav"]', '', 'current-menu-item'],
        'butterfly' => ['//*[@id="bf-menu"] | //*[@id="sidebar-menus"]/div[@class="menus_items"]', '', ''],
        'candy' => ['//*[@id="candy-navigation"]', '', ''],
        'clarity' => ['//div[contains(concat(" ", @class, " "), " nav_body_left ")]', '', 'nav_active'],
        'clay' => ['//*[@id="clay-nav-links"]', 'is-active', ''],
        'farallon' => ['//header//nav[@aria-label="主导航"]', 'current', ''],
        'hammeros' => ['//*[@id="hammer-nav"]', 'is-active', ''],
        'jaguar' => ['//nav[@class="site--nav"]', 'current', ''],
        'liquid-glass' => ['//*[@id="aqua-nav-links"]', 'is-active', ''],
        'mango' => ['//nav[@class="header-menu"] | //aside[@class="mobile_nav"]/nav', 'current', ''],
        'nebula' => ['//nav[@class="site-nav"]', 'active', ''],
        'nojs' => ['//ul[@class="header__list"]', '', ''],
        'once' => ['//nav[@class="once-desktop-nav"] | //*[@id="once-mobile-drawer"]/nav', 'is-active', ''],
        'paper' => ['//nav[@class="paper-primary-nav"]', 'is-active', ''],
        'photograph' => ['//ul[@class="photo-nav-list"]', 'is-active', ''],
        'terminal' => ['//nav[@class="terminal-menu"]', 'is-active', ''],
        'timellow' => ['//nav[@class="site-nav"]', 'is-current', ''],
        'ying' => ['//*[@id="main-menu"]', 'is-active', ''],
    ];
    $menuCount = 0;
    foreach ($themes as $slug => [$selector, $activeClass, $activeItemClass]) {
        $withoutPlugin = menu_theme_test_dom(menu_theme_test_render($slug, 'absent'));
        $fallback = menu_theme_test_dom(menu_theme_test_render($slug, 'fallback'));
        $managedHtml = menu_theme_test_render($slug, 'managed');
        $managed = menu_theme_test_dom($managedHtml);
        $empty = menu_theme_test_dom(menu_theme_test_render($slug, 'empty'));
        $baseMenus = $withoutPlugin->query($selector);
        $fallbackMenus = $fallback->query($selector);
        $managedMenus = $managed->query($selector);
        $emptyMenus = $empty->query($selector);
        $expectedCount = in_array($slug, ['butterfly', 'mango', 'once'], true) ? 2 : 1;
        foreach ([$baseMenus, $fallbackMenus, $managedMenus, $emptyMenus] as $menus) {
            menu_theme_test_assert($menus->length === $expectedCount, $slug . ': desktop/mobile menu container count changed');
        }
        menu_theme_test_assert(menu_theme_test_utilities($withoutPlugin) === menu_theme_test_utilities($managed), $slug . ': takeover changed account, RSS, search or toggle controls');
        menu_theme_test_assert(menu_theme_test_utilities($withoutPlugin) === menu_theme_test_utilities($empty), $slug . ': an empty menu changed utility controls');
        menu_theme_test_assert($managed->query('//*[@id="fixture-content"]')->length === 1, $slug . ': takeover changed body content');
        menu_theme_test_assert($managed->query('//*[@data-menu-payload]')->length === 0, $slug . ': menu label injected an element');
        menu_theme_test_assert(str_contains($managedHtml, 'href="' . h(MENU_THEME_TEST_URL) . '"'), $slug . ': custom URL was not escaped');
        menu_theme_test_assert(str_contains($managedHtml, h(MENU_THEME_TEST_LABEL)), $slug . ': custom label was not escaped');
        for ($index = 0; $index < $expectedCount; $index++) {
            $base = menu_theme_test_links($withoutPlugin, $baseMenus->item($index));
            menu_theme_test_assert($base === menu_theme_test_links($fallback, $fallbackMenus->item($index)), $slug . ': opting out did not restore the original theme navigation');
            menu_theme_test_assert(in_array('/tags', array_column($base, 0), true), $slug . ': fallback fixture did not exercise the default tags link');
            menu_theme_test_assert(in_array('/page/legacy-fixture', array_column($base, 0), true), $slug . ': fallback lost the original page link');
            foreach ([$managedMenus->item($index), $emptyMenus->item($index)] as $menu) {
                $links = menu_theme_test_links($menu->ownerDocument === $managed->document ? $managed : $empty, $menu);
                menu_theme_test_assert(!in_array('/tags', array_column($links, 0), true), $slug . ': removed default tags link remained in a menu');
                menu_theme_test_assert(!in_array('/page/legacy-fixture', array_column($links, 0), true), $slug . ': original page link remained in a managed menu');
            }
            $menu = $managedMenus->item($index);
            $archive = $managed->query('.//a[@href="/archives"]', $menu);
            $custom = $managed->query('.//a[starts-with(@href,"/menu-fixture?")]', $menu);
            menu_theme_test_assert($archive->length === 1 && $custom->length === 1, $slug . ': configured entries were missing or duplicated');
            menu_theme_test_assert($archive->item(0)->getAttribute('aria-current') === 'page', $slug . ': active entry was not marked current');
            $prefix = $slug === 'terminal' ? '[' : '';
            $suffix = $slug === 'terminal' ? ']' : '';
            menu_theme_test_assert(trim($archive->item(0)->textContent) === $prefix . 'Managed archives' . $suffix, $slug . ': configured route label was ignored');
            menu_theme_test_assert(trim($custom->item(0)->textContent) === $prefix . MENU_THEME_TEST_LABEL . $suffix, $slug . ': label markup changed its text');
            menu_theme_test_assert($custom->item(0)->getAttribute('href') === MENU_THEME_TEST_URL, $slug . ': custom destination changed');
            menu_theme_test_assert($custom->item(0)->getAttribute('target') === '_blank', $slug . ': new-tab preference was ignored');
            menu_theme_test_assert($custom->item(0)->getAttribute('rel') === 'noopener noreferrer', $slug . ': new-tab link lost its rel attributes');
            if ($activeClass !== '') {
                menu_theme_test_assert(in_array($activeClass, explode(' ', $archive->item(0)->getAttribute('class')), true), $slug . ': active theme link styling was lost');
            }
            if ($activeItemClass !== '') {
                menu_theme_test_assert(in_array($activeItemClass, explode(' ', $archive->item(0)->parentNode->getAttribute('class')), true), $slug . ': active wrapper styling was lost');
            }
            menu_theme_test_assert($empty->query('.//a[@href="/archives" or starts-with(@href,"/menu-fixture?")]', $emptyMenus->item($index))->length === 0, $slug . ': an intentionally empty menu fell back to default entries');
            $menuCount++;
        }
    }
    $paper = menu_theme_test_dom(menu_theme_test_render('paper', 'home'));
    menu_theme_test_assert($paper->query('//nav[@class="paper-primary-nav"]/a[@href="/home" and contains(@class,"paper-home-link")]')->length === 1, 'Paper: managed homepage lost its mobile-only class');
    $guestFallback = menu_theme_test_dom(menu_theme_test_render('nojs', 'fallback', true));
    $guestManaged = menu_theme_test_dom(menu_theme_test_render('nojs', 'managed', true));
    menu_theme_test_assert(menu_theme_test_utilities($guestFallback) === menu_theme_test_utilities($guestManaged), 'NoJS: guest login changed during takeover');
    menu_theme_test_assert($guestManaged->query('//a[@href="/login"]')->length === 1, 'NoJS: takeover removed the guest login link');
    echo 'Menu theme integration tests passed (' . count($themes) . ' themes, ' . $menuCount . " desktop/mobile menus).\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Menu theme integration tests failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
