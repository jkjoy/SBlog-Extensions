<?php

declare(strict_types=1);

// Exercise the real plugin against SQLite and the core's public helper contracts.
$GLOBALS['menu_test_db'] = new PDO('sqlite::memory:');
$GLOBALS['menu_test_db']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['menu_test_db']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['menu_test_settings'] = [];
$GLOBALS['menu_test_settings_writes'] = 0;
$GLOBALS['menu_test_hooks'] = ['actions' => [], 'filters' => []];
$GLOBALS['menu_test_admin'] = false;
$GLOBALS['menu_test_theme'] = 'default';
$GLOBALS['menu_test_pretty'] = false;

define('APP_VERSION', '1.14.8');
define('PLUGINS_DIR', dirname(__DIR__) . '/plugins');
define('THEMES_DIR', dirname(__DIR__) . '/themes');

function db(): PDO { return $GLOBALS['menu_test_db']; }
function q(string $sql, array $parameters = []): PDOStatement
{
    $statement = db()->prepare($sql);
    $statement->execute($parameters);
    return $statement;
}
function one(string $sql, array $parameters = []): ?array
{
    $row = q($sql, $parameters)->fetch();
    return is_array($row) ? $row : null;
}
function all_rows(string $sql, array $parameters = []): array { return q($sql, $parameters)->fetchAll(); }
function val(string $sql, array $parameters = []): mixed { return q($sql, $parameters)->fetchColumn(); }
function fetch_nav_pages(): array
{
    return all_rows('SELECT id,slug,title,kind FROM posts WHERE kind=? AND status=? AND published_at<=? ORDER BY published_at,id LIMIT 6', ['page', 'published', time()]);
}
function setting(string $name, string $default = ''): string
{
    return (string)($GLOBALS['menu_test_settings'][$name] ?? $default);
}
function save_settings(array $values): void
{
    $GLOBALS['menu_test_settings_writes']++;
    foreach ($values as $name => $value) {
        q('INSERT OR REPLACE INTO settings(name,value) VALUES(?,?)', [(string)$name, (string)$value]);
        $GLOBALS['menu_test_settings'][(string)$name] = (string)$value;
    }
}
function h(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function sblog_t(string $value, array $parameters = []): string
{
    foreach ($parameters as $name => $parameter) $value = str_replace('{' . $name . '}', (string)$parameter, $value);
    return $value;
}
function sblog_tn(string $value, int $count, array $parameters = []): string { return sblog_t($value, ['count' => $count] + $parameters); }
function sblog_i18n_register(string $locale, array $catalog): void {}
function sblog_i18n_register_client(string $locale, array $catalog): void {}
function sblog_i18n_locale(): string { return 'zh-CN'; }
function str_len_u(string $value): int { return mb_strlen($value, 'UTF-8'); }
function str_sub_u(string $value, int $start, int $length): string { return mb_substr($value, $start, $length, 'UTF-8'); }
function script_url(): string { return '/blog/index.php'; }
function app_base_path(): string { return '/blog'; }
function use_pretty_url(): bool { return $GLOBALS['menu_test_pretty']; }
function url_for(string $route, array $parameters = []): string
{
    if (use_pretty_url()) {
        $paths = ['home' => '/', 'rss' => '/rss.xml', 'sitemap' => '/sitemap.xml'];
        $path = $paths[$route] ?? ('/' . $route);
        if ($route === 'page') $path = '/' . rawurlencode((string)($parameters['slug'] ?? ''));
        if (in_array($route, ['post', 'category', 'tag'], true)) {
            $path = '/' . ($route === 'post' ? 'archive' : $route) . '/' . rawurlencode((string)($parameters['slug'] ?? ''));
        }
        return '/blog' . $path;
    }
    return script_url() . ($route === 'home' ? '' : '?' . http_build_query(['a' => $route] + $parameters));
}
function content_permalink(array $row): string
{
    return url_for(($row['kind'] ?? '') === 'page' ? 'page' : 'post', ['slug' => (string)$row['slug']]);
}
function fetch_page_by_identifier(string $identifier, bool $allowPreview = false): ?array
{
    if ($identifier === '') return null;
    $published = static fn(array $row): bool => $allowPreview || ($row['status'] === 'published' && (int)$row['published_at'] <= time());
    $row = one('SELECT * FROM posts WHERE slug=? AND kind=?', [$identifier, 'page']);
    if ($row) return $published($row) ? $row : null;
    if (preg_match('/^[0-9]+$/D', $identifier)) {
        $row = one('SELECT * FROM posts WHERE id=? AND kind=?', [(int)$identifier, 'page']);
        if ($row && $published($row)) return $row;
    }
    return null;
}
function active_theme_slug(): string { return $GLOBALS['menu_test_theme']; }
function active_theme(): array { return ['slug' => active_theme_slug(), 'version' => '1.0.0']; }
function active_theme_file(string $filename): string
{
    if (in_array(active_theme_slug(), ['default', 'starter'], true)) return '';
    $file = THEMES_DIR . '/' . active_theme_slug() . '/' . $filename;
    return is_file($file) ? $file : '';
}
function plugin_asset_url(string $slug, string $path): string { return '/blog/plugins/' . $slug . '/' . $path; }
function add_plugin_action(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['menu_test_hooks']['actions'][$hook][$priority][] = $callback;
}
function add_plugin_filter(string $hook, callable $callback, int $priority = 10): void
{
    $GLOBALS['menu_test_hooks']['filters'][$hook][$priority][] = $callback;
}
function plugin_action(string $hook, array $context = []): void
{
    $callbacks = $GLOBALS['menu_test_hooks']['actions'][$hook] ?? [];
    ksort($callbacks, SORT_NUMERIC);
    foreach ($callbacks as $group) foreach ($group as $callback) $callback($context);
}
function current_admin(): ?array { return $GLOBALS['menu_test_admin'] ? ['id' => 1, 'username' => 'admin'] : null; }
function is_admin(): bool { return current_admin() !== null; }
class MenuTestResponse extends RuntimeException {}
function require_admin(): void { if (!is_admin()) throw new MenuTestResponse('login'); }
function require_admin_post(string $fallback): void
{
    require_admin();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new MenuTestResponse('method');
    verify_csrf();
}
function csrf_token(): string { return 'menu-test-csrf'; }
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="' . csrf_token() . '">'; }
function verify_csrf(): void
{
    if (($_POST['csrf_token'] ?? '') !== csrf_token()) throw new MenuTestResponse('csrf');
}
function set_flash(string $type, string $message): void { $GLOBALS['menu_test_flash'] = [$type, $message]; }
function redirect_to(string $url, int $status = 302): void { throw new MenuTestResponse('redirect:' . $url); }
function simple_error_page(string $title, string $message, int $status = 400): never { throw new MenuTestResponse('error:' . $status); }
function admin_icon(string $name): string { return '<span data-icon="' . h($name) . '"></span>'; }
function render_admin_sidebar(string $active, array $summary = []): string { return '<aside></aside>'; }
function render_admin_topbar(string $title, string $label = '', string $url = ''): string { return '<header>' . h($title) . '</header>'; }
function render_layout(string $title, string $content, array $options = []): void
{
    $GLOBALS['menu_test_rendered'] = ['title' => $title, 'content' => $content, 'options' => $options];
}

function menu_test_assert(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function menu_test_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) throw new RuntimeException($message . ': expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
}
function menu_test_exception(string $class, callable $callback, string $message): void
{
    $caught = null;
    try { $callback(); } catch (Throwable $exception) { $caught = $exception; }
    menu_test_assert($caught instanceof $class, $message . ($caught ? ': ' . get_class($caught) . ': ' . $caught->getMessage() : ''));
}
function menu_test_item(string $id, string $type, string $reference = '', string $label = '', string $url = '', array $extra = []): array
{
    return array_replace(['id' => $id, 'type' => $type, 'reference' => $reference, 'label' => $label, 'url' => $url, 'enabled' => true, 'new_tab' => false], $extra);
}
function menu_test_config(array $items, bool $managed = true): array
{
    return ['version' => 1, 'managed' => $managed, 'items' => $items];
}
function menu_test_context(string $active = '', string $theme = 'default'): array
{
    return ['active' => $active, 'admin' => current_admin(), 'theme' => ['slug' => $theme], 'options' => ['mode' => 'public']];
}

db()->exec('CREATE TABLE settings(name TEXT PRIMARY KEY,value TEXT NOT NULL)');
db()->exec('CREATE TABLE posts(id INTEGER PRIMARY KEY,kind TEXT NOT NULL,slug TEXT NOT NULL,title TEXT NOT NULL,status TEXT NOT NULL,published_at INTEGER NOT NULL,created_at INTEGER NOT NULL DEFAULT 0,updated_at INTEGER NOT NULL DEFAULT 0)');
db()->exec('CREATE TABLE categories(id INTEGER PRIMARY KEY,slug TEXT NOT NULL,name TEXT NOT NULL,sort_order INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL DEFAULT 0,updated_at INTEGER NOT NULL DEFAULT 0)');
$now = time();
foreach ([
    [1, 'page', 'about', 'About <site>', 'published', $now - 100],
    [2, 'page', 'draft', 'Draft secret', 'draft', $now - 100],
    [3, 'page', 'scheduled', 'Future secret', 'published', $now + 86400],
    [4, 'post', 'article', 'An article', 'published', $now - 100],
] as $row) q('INSERT INTO posts(id,kind,slug,title,status,published_at) VALUES(?,?,?,?,?,?)', $row);
q('INSERT INTO categories(id,slug,name) VALUES(?,?,?)', [8, 'engineering', 'Engineering & design']);

require dirname(__DIR__) . '/plugins/menu-manager/plugin.php';

try {
    $defaults = sblog_menu_defaults();
    menu_test_same(1, $defaults['version'], 'Default schema version is wrong');
    menu_test_same(false, $defaults['managed'], 'Installing the plugin unexpectedly takes over navigation');
    menu_test_assert(!sblog_menu_is_managed(), 'An unconfigured plugin takes over navigation');

    $config = menu_test_config([
        menu_test_item('home', 'route', 'home'),
        menu_test_item('about', 'page', '1'),
        menu_test_item('category', 'category', '8'),
        menu_test_item('outside', 'custom', '', '<script>alert("x")</script>', 'https://example.test/?a=1&b=2', ['new_tab' => true]),
        menu_test_item('hidden', 'route', 'tags', '', '', ['enabled' => false]),
    ]);
    sblog_menu_save($config);
    menu_test_assert(sblog_menu_is_managed(), 'Saved managed navigation did not activate');
    menu_test_same(1, $GLOBALS['menu_test_settings_writes'], 'Saving one config performed unexpected writes');
    menu_test_same($config, json_decode(setting('menu_manager_config'), true), 'Config did not persist atomically as JSON');

    $items = sblog_menu_items(menu_test_context('page:about'));
    menu_test_same(4, count($items), 'Disabled entries were rendered');
    menu_test_same('About <site>', $items[1]['label'], 'Blank page label did not follow the page title');
    menu_test_same(content_permalink(one('SELECT * FROM posts WHERE id=?', [1])), $items[1]['url'], 'Page URL did not use the current permalink');
    menu_test_assert($items[1]['active'], 'Page active state is missing');
    menu_test_same('Engineering & design', $items[2]['label'], 'Blank category label did not follow the category name');
    menu_test_same(url_for('category', ['slug' => 'engineering']), $items[2]['url'], 'Category URL is wrong');

    $html = sblog_menu_render(menu_test_context('page:about'), []);
    menu_test_assert(str_contains($html, 'About &lt;site&gt;'), 'Page title was not escaped');
    menu_test_assert(str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>'), 'Custom labels can inject markup');
    menu_test_assert(str_contains($html, 'a=1&amp;b=2'), 'URL ampersands were not escaped');
    menu_test_assert(str_contains($html, 'target="_blank"'), 'New-tab links did not open in a new tab');
    menu_test_assert(preg_match('/rel="[^"]*noopener[^\"]*"/', $html) === 1 && str_contains($html, 'noreferrer'), 'New-tab links lack safe rel attributes');
    menu_test_assert(str_contains($html, 'aria-current="page"'), 'Current page has no accessible active marker');

    $wrapped = sblog_menu_render(menu_test_context('home'), [
        'item_tag' => 'li', 'item_class' => 'item', 'active_item_class' => 'selected',
        'link_class' => 'link', 'active_class' => 'current', 'label_tag' => 'span',
        'label_prefix' => '[', 'label_suffix' => ']',
    ]);
    menu_test_assert(str_contains($wrapped, '<li class="item selected">') && str_contains($wrapped, 'class="link current"'), 'Theme wrapper or active classes were not rendered');
    menu_test_assert(str_contains($wrapped, '<span>[首页]</span>'), 'Theme label presentation was not rendered');
    $invalidTags = sblog_menu_render(menu_test_context(), ['item_tag' => 'script', 'label_tag' => 'img']);
    menu_test_assert(!str_contains($invalidTags, '<script') && !str_contains($invalidTags, '<img'), 'Invalid theme wrapper tags were accepted');

    $_GET = ['a' => 'home', 's' => 'keyword'];
    menu_test_assert(!sblog_menu_items(menu_test_context('home'))[0]['active'], 'Search incorrectly marks Home as active');
    $_GET = [];
    menu_test_assert(!sblog_menu_items(['active' => 'home', 'is_search' => true])[0]['active'], 'Theme-provided search context incorrectly marks Home as active');
    $_GET = ['a' => 'category', 'slug' => 'engineering'];
    $categoryView = sblog_menu_items(menu_test_context('home'));
    menu_test_assert(!$categoryView[0]['active'] && $categoryView[2]['active'], 'Category incorrectly marks Home as active or misses the current category');
    $_GET = [];

    q('UPDATE posts SET slug=?,title=? WHERE id=?', ['renamed-about', 'Updated about', 1]);
    q('UPDATE categories SET slug=?,name=? WHERE id=?', ['new-engineering', 'New category', 8]);
    $changed = sblog_menu_items(menu_test_context('page:renamed-about'));
    menu_test_same(url_for('page', ['slug' => 'renamed-about']), $changed[1]['url'], 'Page slug change left a stale URL');
    menu_test_same('Updated about', $changed[1]['label'], 'Page title change left a stale label');
    menu_test_assert($changed[1]['active'], 'Renamed page did not retain active matching');
    menu_test_same(url_for('category', ['slug' => 'new-engineering']), $changed[2]['url'], 'Category slug change left a stale URL');

    foreach ([false, true] as $pretty) {
        $GLOBALS['menu_test_pretty'] = $pretty;
        $resolved = sblog_menu_items(menu_test_context());
        menu_test_same(url_for('home'), $resolved[0]['url'], 'Home route ignored pretty URL or install path');
        menu_test_same(url_for('page', ['slug' => 'renamed-about']), $resolved[1]['url'], 'Page route ignored pretty URL or install path');
    }
    $GLOBALS['menu_test_pretty'] = false;

    q('DELETE FROM categories WHERE id=?', [8]);
    menu_test_same(3, count(sblog_menu_items(menu_test_context())), 'Deleting a selected category left its menu link visible');
    q('INSERT INTO posts(id,kind,slug,title,status,published_at) VALUES(?,?,?,?,?,?)', [7, 'page', 'delete-me', 'Delete me', 'published', $now - 100]);
    sblog_menu_save(menu_test_config([menu_test_item('to-delete', 'page', '7')]));
    menu_test_same(1, count(sblog_menu_items(menu_test_context())), 'Published deletion fixture did not resolve');
    q('DELETE FROM posts WHERE id=?', [7]);
    menu_test_same([], sblog_menu_items(menu_test_context()), 'Deleting a selected page left its menu link visible');

    for ($id = 20; $id < 27; $id++) {
        q('INSERT INTO posts(id,kind,slug,title,status,published_at) VALUES(?,?,?,?,?,?)', [$id, 'page', 'page-' . $id, 'Page ' . $id, 'published', $now - 100]);
    }
    menu_test_same(8, count(sblog_menu_pages()), 'Admin page candidates inherit the core navigation limit of six');
    menu_test_assert(!in_array('Draft secret', array_column(sblog_menu_pages(), 'title'), true)
        && !in_array('Future secret', array_column(sblog_menu_pages(), 'title'), true), 'Page candidates expose unpublished pages');

    sblog_menu_save(menu_test_config([
        menu_test_item('draft', 'page', '2'), menu_test_item('future', 'page', '3'),
        menu_test_item('post', 'page', '4'), menu_test_item('deleted', 'page', '999'),
        menu_test_item('deleted-category', 'category', '999'), menu_test_item('about', 'page', '1'),
    ]));
    menu_test_same(1, count(sblog_menu_items(menu_test_context())), 'Private, future, deleted, or wrong-kind content leaked into public navigation');
    q('UPDATE posts SET status=? WHERE id=?', ['draft', 1]);
    menu_test_same([], sblog_menu_items(menu_test_context()), 'Withdrawing a page left a public menu entry');
    q('UPDATE posts SET status=? WHERE id=?', ['published', 1]);

    $valid = menu_test_config([menu_test_item('safe', 'custom', '', 'Safe', '/blog/about')]);
    foreach ([
        'javascript:alert(1)', 'data:text/html,<script>alert(1)</script>', '//evil.example/path', '/%2fevil.example/path',
        '/\\evil.example/path', '\\evil.example', 'https://user:password@example.test/',
        "https://example.test/\nalert", "#bad\x00fragment", '/blog/%0aevil', '/blog/%5cevil',
    ] as $url) {
        $bad = $valid;
        $bad['items'][0]['url'] = $url;
        menu_test_exception(InvalidArgumentException::class, static fn() => sblog_menu_validate($bad), 'Unsafe URL was accepted: ' . json_encode($url));
    }
    foreach (['/blog/about', '#content', 'http://example.test/about', 'https://example.test/about'] as $url) {
        $candidate = $valid;
        $candidate['items'][0]['url'] = $url;
        menu_test_same($url, sblog_menu_validate($candidate)['items'][0]['url'], 'Valid link was rejected');
    }

    sblog_menu_save($valid);
    $stored = setting('menu_manager_config');
    $writes = $GLOBALS['menu_test_settings_writes'];
    $invalidConfigs = [];
    $bad = $valid; $bad['items'] = ['invalid']; $invalidConfigs[] = $bad;
    $bad = $valid; $bad['items'][0]['type'] = 'unknown'; $invalidConfigs[] = $bad;
    $bad = $valid; $bad['items'][0]['label'] = ''; $invalidConfigs[] = $bad;
    $bad = $valid; $bad['items'][0]['label'] = ['nested']; $invalidConfigs[] = $bad;
    $bad = $valid; $bad['items'][0]['url'] = ['nested']; $invalidConfigs[] = $bad;
    $bad = $valid; $bad['managed'] = 'false'; $invalidConfigs[] = $bad;
    $bad = $valid; $bad['version'] = '1'; $invalidConfigs[] = $bad;
    $bad = $valid; $bad['items'][0]['enabled'] = 'false'; $invalidConfigs[] = $bad;
    $bad = $valid; $bad['items'][0]['new_tab'] = []; $invalidConfigs[] = $bad;
    $bad = $valid; $bad['items'][0]['id'] = 'not a valid id'; $invalidConfigs[] = $bad;
    $bad = $valid; $bad['items'][0]['label'] = str_repeat('名', 101); $invalidConfigs[] = $bad;
    $bad = $valid; $bad['items'][0]['label'] = "bad\x01label"; $invalidConfigs[] = $bad;
    $bad = $valid; $bad['items'][] = $bad['items'][0]; $invalidConfigs[] = $bad;
    $invalidConfigs[] = menu_test_config([menu_test_item('unknown-route', 'route', 'save_settings')]);
    $invalidConfigs[] = menu_test_config([menu_test_item('bad-page', 'page', '1 OR 1=1')]);
    $invalidConfigs[] = menu_test_config([menu_test_item('bad-category', 'category', '-1')]);
    $invalidConfigs[] = menu_test_config([menu_test_item('safe', 'route', 'home'), menu_test_item('invalid-second', 'custom', '', 'Invalid', 'javascript:alert(1)')]);
    foreach ($invalidConfigs as $bad) {
        menu_test_exception(InvalidArgumentException::class, static fn() => sblog_menu_save($bad), 'Malformed configuration was saved');
        menu_test_same($stored, setting('menu_manager_config'), 'Invalid config changed the persisted menu');
        menu_test_same($writes, $GLOBALS['menu_test_settings_writes'], 'Validation failure caused a partial write');
    }
    $tooMany = [];
    for ($index = 0; $index < 101; $index++) $tooMany[] = menu_test_item('limit-' . $index, 'route', 'home');
    menu_test_exception(InvalidArgumentException::class, static fn() => sblog_menu_save(menu_test_config($tooMany)), 'More than 100 menu entries were saved');
    menu_test_same($stored, setting('menu_manager_config'), 'An oversized config changed the persisted menu');

    foreach (['{invalid', 'null', json_encode(['version' => 1, 'managed' => true, 'items' => [['type' => 'custom']]])] as $corrupt) {
        $GLOBALS['menu_test_settings']['menu_manager_config'] = $corrupt;
        menu_test_assert(!sblog_menu_is_managed(), 'Corrupt stored config unexpectedly takes over navigation');
    }
    $GLOBALS['menu_test_settings']['menu_manager_config'] = $stored;

    sblog_menu_save(menu_test_config([]));
    menu_test_assert(sblog_menu_is_managed(), 'An intentionally empty menu disabled takeover');
    menu_test_same([], sblog_menu_items(menu_test_context()), 'An empty menu regained default entries');
    menu_test_same('', sblog_menu_render(menu_test_context(), []), 'An empty managed menu emitted links');

    sblog_menu_save(menu_test_config([menu_test_item('replacement', 'custom', '', 'Replacement menu', '/blog/replacement')]));
    $public = '<!doctype html><html><head></head><body class="theme-public"><header class="text-header"><nav class="text-nav" aria-label="Main"><a href="/old">Old navigation</a></nav></header><main><nav class="article-nav"><a href="/article">Article navigation</a></nav></main></body></html>';
    foreach (['default', 'starter'] as $theme) {
        $GLOBALS['menu_test_theme'] = $theme;
        $output = sblog_menu_output_html($public, ['action' => 'home', 'content_type' => 'text/html']);
        menu_test_assert(str_contains($output, 'Replacement menu') && !str_contains($output, 'Old navigation'), $theme . ' navigation fallback did not take over');
        menu_test_assert(str_contains($output, 'Article navigation'), 'Fallback replaced an unrelated navigation element');
        menu_test_same(1, substr_count($output, 'Replacement menu'), 'Fallback rendered duplicate primary menus');
    }
    sblog_menu_save(menu_test_config([menu_test_item('home', 'route', 'home'), menu_test_item('numeric-page', 'page', '1')]));
    $expectedPageLink = '<a href="' . h(url_for('page', ['slug' => 'renamed-about'])) . '" aria-current="page">Updated about</a>';
    foreach (['default', 'starter'] as $theme) {
        $GLOBALS['menu_test_theme'] = $theme;
        foreach ([['a' => 'page', 'id' => '1'], ['a' => 'page', 'slug' => '1'], ['a' => 'page', 'slug' => 'renamed-about']] as $query) {
            $_GET = $query;
            $output = sblog_menu_output_html($public, ['action' => 'page', 'content_type' => 'text/html']);
            menu_test_assert(str_contains($output, $expectedPageLink), $theme . ' fallback did not resolve the page identifier before marking its menu entry current: ' . json_encode($query));
            menu_test_same(1, substr_count($output, 'aria-current="page"'), 'Fallback marked an extra menu item current for a page request');
        }
    }
    $_GET = [];
    sblog_menu_save(menu_test_config([menu_test_item('replacement', 'custom', '', 'Replacement menu', '/blog/replacement')]));
    $GLOBALS['menu_test_theme'] = 'paper';
    $dedicatedLayout = str_replace(['text-header', 'text-nav'], ['paper-header', 'paper-primary-nav'], $public);
    menu_test_same($dedicatedLayout, sblog_menu_output_html($dedicatedLayout, ['action' => 'home', 'content_type' => 'text/html']), 'Fallback rewrote a theme with a dedicated layout');
    $GLOBALS['menu_test_theme'] = 'default';
    $adminHtml = str_replace('theme-public', 'theme-admin', $public);
    $adminOutput = sblog_menu_output_html($adminHtml, ['action' => 'admin', 'content_type' => 'text/html']);
    menu_test_assert(str_contains($adminOutput, 'Old navigation') && !str_contains($adminOutput, 'Replacement menu'), 'Default fallback rewrote an admin page');
    sblog_menu_save(menu_test_config([], false));
    menu_test_same($public, sblog_menu_output_html($public, ['action' => 'home', 'content_type' => 'text/html']), 'Disabling takeover changed the original navigation');

    $_SESSION = [];
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = ['csrf_token' => csrf_token()];
    $writes = $GLOBALS['menu_test_settings_writes'];
    menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'admin_menus']), 'Anonymous visitors can access menu management');
    menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'save_menus']), 'Anonymous visitors can save menus');
    menu_test_same($writes, $GLOBALS['menu_test_settings_writes'], 'Anonymous request modified the config');
    $GLOBALS['menu_test_admin'] = true;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'save_menus']), 'GET was accepted as a write request');
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST['csrf_token'] = 'invalid';
    menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'save_menus']), 'Invalid CSRF token was accepted');
    menu_test_same($writes, $GLOBALS['menu_test_settings_writes'], 'Rejected method or CSRF request changed the config');

    sblog_menu_render_admin();
    $adminContent = $GLOBALS['menu_test_rendered']['content'];
    menu_test_assert(str_contains($adminContent, csrf_field()), 'Admin form lacks its CSRF token');
    menu_test_assert(str_contains($adminContent, 'name="items_present"') && str_contains($adminContent, 'name="items_complete"'), 'Admin form lacks completeness markers');
    menu_test_assert(strrpos($adminContent, 'name="items_complete"') > strrpos($adminContent, 'name="new_destination"'), 'Completeness marker is not after the submitted item fields');
    menu_test_assert(str_contains($adminContent, 'value="page:26"'), 'Admin destination list omits pages past the core limit of six');
    menu_test_assert(!str_contains($adminContent, 'Draft secret') && !str_contains($adminContent, 'Future secret'), 'Admin destination list offers unpublished pages');
    $assetHtml = '<html><head></head><body class="theme-admin">' . $adminContent . '</body></html>';
    $assetOutput = sblog_menu_output_html($assetHtml, ['action' => 'admin_menus']);
    menu_test_assert(str_contains($assetOutput, '/plugins/menu-manager/assets/admin.css') && str_contains($assetOutput, '/plugins/menu-manager/assets/admin.js'), 'Admin assets were not added to the management page');
    menu_test_same($assetHtml, sblog_menu_output_html($assetHtml, ['action' => 'admin']), 'Admin assets were added to unrelated admin pages');

    $post = [
        'csrf_token' => csrf_token(), 'operation' => 'save', 'managed' => '1',
        'items_present' => '1', 'items_complete' => '1',
        'items' => [
            ['id' => 'first', 'destination' => 'route:home', 'label' => '', 'url' => '', 'enabled' => '1'],
            ['id' => 'second', 'destination' => 'custom', 'label' => 'Saved external', 'url' => 'https://example.test/', 'enabled' => '1', 'new_tab' => '1'],
        ],
    ];
    $_POST = $post;
    menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'save_menus']), 'A valid save did not finish with a redirect');
    menu_test_same('success', $GLOBALS['menu_test_flash'][0], 'Valid form save reported failure');
    menu_test_assert(sblog_menu_is_managed(), 'Valid admin save did not activate takeover');
    menu_test_same(['first', 'second'], array_column(sblog_menu_config()['items'], 'id'), 'Admin save changed the submitted order');
    menu_test_assert(sblog_menu_config()['items'][1]['new_tab'], 'Admin save lost the new-tab setting');

    $stored = setting('menu_manager_config');
    $writes = $GLOBALS['menu_test_settings_writes'];
    $badPosts = [];
    $bad = $post; unset($bad['items_complete']); $badPosts[] = $bad;
    $bad = $post; unset($bad['items_present']); $badPosts[] = $bad;
    $bad = $post; $bad['items'] = 'not rows'; $badPosts[] = $bad;
    $bad = $post; $bad['items'][1]['url'] = ['nested']; $badPosts[] = $bad;
    $bad = $post; $bad['items'][1]['destination'] = ['nested']; $badPosts[] = $bad;
    $bad = $post; $bad['items'][1]['url'] = 'javascript:alert(1)'; $badPosts[] = $bad;
    $bad = $post; $bad['managed'] = ['1']; $badPosts[] = $bad;
    $bad = $post; $bad['items'][0]['enabled'] = ['1']; $badPosts[] = $bad;
    $bad = $post; $bad['items'][1]['new_tab'] = ['1']; $badPosts[] = $bad;
    $bad = $post; $bad['operation'] = ['save']; $badPosts[] = $bad;
    foreach ($badPosts as $bad) {
        $_POST = $bad;
        menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'save_menus']), 'Invalid form did not finish with a redirect');
        menu_test_same('error', $GLOBALS['menu_test_flash'][0], 'Invalid form did not report a validation error');
        menu_test_same($stored, setting('menu_manager_config'), 'Invalid form overwrote the last good config');
        menu_test_same($writes, $GLOBALS['menu_test_settings_writes'], 'Invalid or truncated form caused a partial write');
    }
    unset($_SESSION['menu_manager_draft']);

    $_POST = $post;
    $_POST['operation'] = 'down:first';
    menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'save_menus']), 'Reorder did not redirect');
    menu_test_same(['second', 'first'], array_column(sblog_menu_config()['items'], 'id'), 'No-JS reorder lost or duplicated an item');
    $_POST = $post;
    $_POST['operation'] = 'remove:first';
    menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'save_menus']), 'Remove did not redirect');
    menu_test_same(['second'], array_column(sblog_menu_config()['items'], 'id'), 'No-JS remove did not remove the requested item');
    $_POST = $post;
    $_POST['operation'] = 'add';
    $_POST['new_destination'] = 'page:1';
    menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'save_menus']), 'Add did not redirect');
    $added = sblog_menu_config()['items'];
    menu_test_same(3, count($added), 'No-JS add did not preserve existing items');
    menu_test_same('page', $added[2]['type'], 'No-JS add did not choose the requested type');
    menu_test_same('1', $added[2]['reference'], 'No-JS add did not choose the requested page');

    $beforeReset = sblog_menu_config()['items'];
    $_POST = ['csrf_token' => csrf_token(), 'operation' => 'reset'];
    menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'save_menus']), 'Reset did not redirect');
    menu_test_assert(!sblog_menu_is_managed(), 'Reset did not restore the theme menu');
    menu_test_same($beforeReset, sblog_menu_config()['items'], 'Reset discarded the saved menu configuration');

    echo "Menu manager tests passed.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Menu manager test failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
