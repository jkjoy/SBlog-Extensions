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
    return array_replace(['id' => $id, 'type' => $type, 'reference' => $reference, 'label' => $label, 'url' => $url, 'enabled' => true, 'new_tab' => false, 'parent_id' => ''], $extra);
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
    $legacy = $config;
    foreach ($legacy['items'] as &$legacyItem) unset($legacyItem['parent_id']);
    unset($legacyItem);
    sblog_menu_save($legacy);
    menu_test_same(array_fill(0, 5, ''), array_column(sblog_menu_config()['items'], 'parent_id'), 'Legacy menu entries were not normalized to roots');
    menu_test_same([0, 0, 0, 0], array_column(sblog_menu_items(menu_test_context()), 'depth'), 'Legacy menu depths changed');
    $legacyExpected = '<a href="/blog/index.php">首页</a>' . "\n"
        . '<a href="/blog/index.php?a=page&amp;slug=about" aria-current="page">About &lt;site&gt;</a>' . "\n"
        . '<a href="/blog/index.php?a=category&amp;slug=engineering">Engineering &amp; design</a>' . "\n"
        . '<a href="https://example.test/?a=1&amp;b=2" target="_blank" rel="noopener noreferrer">&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;</a>' . "\n";
    menu_test_same($legacyExpected, sblog_menu_render(menu_test_context('page:about')), 'Flat menu output is no longer byte-compatible with 1.0.0');
    $legacyWrappedExpected = '<li class="item selected"><a href="/blog/index.php" class="link current" aria-current="page"><span>[首页]</span></a></li>' . "\n"
        . '<li class="item"><a href="/blog/index.php?a=page&amp;slug=about" class="link"><span>[About &lt;site&gt;]</span></a></li>' . "\n"
        . '<li class="item"><a href="/blog/index.php?a=category&amp;slug=engineering" class="link"><span>[Engineering &amp; design]</span></a></li>' . "\n"
        . '<li class="item"><a href="https://example.test/?a=1&amp;b=2" class="link" target="_blank" rel="noopener noreferrer"><span>[&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;]</span></a></li>' . "\n";
    menu_test_same($legacyWrappedExpected, $wrapped, 'Wrapped flat menu output is no longer byte-compatible with 1.0.0');
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
    foreach ([null, 1, [], 'invalid parent', 'missing', 'safe'] as $parentId) {
        $bad = $valid; $bad['items'][0]['parent_id'] = $parentId; $invalidConfigs[] = $bad;
    }
    $invalidConfigs[] = menu_test_config([
        menu_test_item('cycle-a', 'route', 'home', '', '', ['parent_id' => 'cycle-b']),
        menu_test_item('cycle-b', 'route', 'archives', '', '', ['parent_id' => 'cycle-a']),
    ]);
    $invalidConfigs[] = menu_test_config([
        menu_test_item('level-0', 'route', 'home'),
        menu_test_item('level-1', 'route', 'archives', '', '', ['parent_id' => 'level-0']),
        menu_test_item('level-2', 'route', 'tags', '', '', ['parent_id' => 'level-1']),
        menu_test_item('level-3', 'route', 'links', '', '', ['parent_id' => 'level-2']),
    ]);
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
    menu_test_same([], sblog_menu_tree(menu_test_context()), 'An empty menu tree regained default entries');
    menu_test_same('', sblog_menu_render(menu_test_context(), []), 'An empty managed menu emitted links');

    $hierarchy = menu_test_config([
        menu_test_item('child-b', 'route', 'tags', '', '', ['parent_id' => 'root']),
        menu_test_item('leaf', 'page', '1', '', '', ['parent_id' => 'child-a']),
        menu_test_item('root', 'custom', '', 'Root <& "docs">', '/blog/catalog'),
        menu_test_item('child-a', 'route', 'archives', '', '', ['parent_id' => 'root']),
        menu_test_item('other-root', 'route', 'links'),
        menu_test_item('external', 'custom', '', '<img src=x onerror=alert(1)>', 'https://example.test/?x=1&y=2', ['parent_id' => 'child-b', 'new_tab' => true]),
    ]);
    sblog_menu_save($hierarchy);
    menu_test_same($hierarchy, json_decode(setting('menu_manager_config'), true), 'Validation reordered a child-before-parent import');
    $nestedContext = menu_test_context('page:renamed-about');
    $nestedFlat = sblog_menu_items($nestedContext);
    menu_test_same(array_column($hierarchy['items'], 'id'), array_column($nestedFlat, 'id'), 'Flat API changed the configured order');
    menu_test_same([1, 2, 0, 1, 0, 2], array_column($nestedFlat, 'depth'), 'Flat API reported incorrect menu depths');
    $tree = sblog_menu_tree($nestedContext);
    menu_test_same(['root', 'other-root'], array_column($tree, 'id'), 'Tree API lost root order');
    menu_test_same(['child-b', 'child-a'], array_column($tree[0]['children'], 'id'), 'Tree API lost sibling order');
    menu_test_same('leaf', $tree[0]['children'][1]['children'][0]['id'], 'Tree API lost the third level');
    menu_test_assert(!$tree[0]['active'] && $tree[0]['has_active_child'], 'A root ancestor confused its own active state with a descendant');
    menu_test_assert(!$tree[0]['children'][1]['active'] && $tree[0]['children'][1]['has_active_child'], 'Active state did not propagate through an intermediate parent');
    menu_test_assert($tree[0]['children'][1]['children'][0]['active'] && !$tree[0]['children'][1]['children'][0]['has_active_child'], 'Leaf active state is incorrect');
    menu_test_assert(!$tree[0]['children'][0]['has_active_child'] && !$tree[1]['has_active_child'], 'Active state leaked into another branch');
    $callbackItems = [];
    $nestedHtml = sblog_menu_render($nestedContext, [
        'item_tag' => 'li', 'item_class' => 'item', 'active_item_class' => 'selected',
        'link_class' => static fn(array $item): string => 'link-' . $item['id'], 'active_class' => 'current',
        'label_tag' => 'span', 'label_prefix' => '[', 'label_suffix' => ']',
        'url' => static function (string $url, array $item) use (&$callbackItems): string {
            $callbackItems[] = [$item['id'], $item['depth']];
            return $url . (str_contains($url, '?') ? '&' : '?') . 'menu=' . $item['id'];
        },
        'icon' => static fn(array $item): string => '<i data-menu-icon="' . h($item['id']) . '"></i>',
    ]);
    menu_test_same([['root', 0], ['child-b', 1], ['external', 2], ['child-a', 1], ['leaf', 2], ['other-root', 0]], $callbackItems, 'Recursive rendering skipped or changed callback contracts');
    menu_test_same(1, substr_count($nestedHtml, 'aria-current="page"'), 'Ancestors incorrectly acquired aria-current');
    menu_test_same(3, substr_count($nestedHtml, 'class="item selected"'), 'Theme active wrapper classes do not highlight ancestors');
    menu_test_assert(str_contains($nestedHtml, 'class="link-root current"') && str_contains($nestedHtml, 'class="link-child-a current"'), 'Theme active link classes do not highlight ancestors');
    menu_test_assert(str_contains($nestedHtml, '[Root &lt;&amp; &quot;docs&quot;&gt;]') && str_contains($nestedHtml, '[&lt;img src=x onerror=alert(1)&gt;]'), 'Nested labels can inject markup');
    menu_test_assert(str_contains($nestedHtml, 'x=1&amp;y=2&amp;menu=external') && str_contains($nestedHtml, 'target="_blank" rel="noopener noreferrer"'), 'Nested external URL escaping or new-tab policy changed');
    $dom = new DOMDocument();
    $previousErrors = libxml_use_internal_errors(true);
    $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body><ul id="menu">' . $nestedHtml . '</ul></body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previousErrors);
    $xpath = new DOMXPath($dom);
    menu_test_same(6, $xpath->query('//*[@id="menu"]//li')->length, 'Nested list markup lost menu items');
    menu_test_same(0, $xpath->query('//*[@id="menu"]//ul/*[not(self::li)]')->length, 'Nested list contains invalid direct children');
    menu_test_same(3, $xpath->query('//*[@id="menu"]//details/summary/a')->length, 'A parent stopped being a real link inside its toggle');
    menu_test_same(2, $xpath->query('//*[@id="menu"]//details[@open]')->length, 'Active descendants are not expanded by default');
    menu_test_same(0, $xpath->query('//*[@id="menu"]//script|//*[@id="menu"]//img')->length, 'Nested rendering introduced executable label content');
    menu_test_assert(str_contains(sblog_menu_render($nestedContext, ['item_tag' => 'div']), '<div class="sblog-menu-children">'), 'Div wrappers acquired incompatible nested lists');

    foreach ([
        menu_test_item('unavailable', 'route', 'archives', '', '', ['enabled' => false]),
        menu_test_item('unavailable', 'page', '2'), menu_test_item('unavailable', 'page', '3'),
        menu_test_item('unavailable', 'page', '4'), menu_test_item('unavailable', 'page', '999'),
        menu_test_item('unavailable', 'category', '999'),
    ] as $unavailable) {
        sblog_menu_save(menu_test_config([
            menu_test_item('grandchild', 'route', 'tags', '', '', ['parent_id' => 'child']),
            menu_test_item('child', 'route', 'home', '', '', ['parent_id' => 'unavailable']),
            $unavailable, menu_test_item('unaffected', 'route', 'links'),
        ]));
        menu_test_same(['unaffected'], array_column(sblog_menu_items($nestedContext), 'id'), 'An unavailable parent exposed a descendant or hid an unrelated branch');
        menu_test_same(['unaffected'], array_column(sblog_menu_tree($nestedContext), 'id'), 'Tree API exposed an orphan descendant');
    }

    sblog_menu_save(menu_test_config([menu_test_item('replacement', 'custom', '', 'Replacement menu', '/blog/replacement')]));
    $public = '<!doctype html><html><head></head><body class="theme-public"><header class="text-header"><nav class="text-nav" aria-label="Main"><a href="/old">Old navigation</a></nav></header><main><nav class="article-nav"><a href="/article">Article navigation</a></nav></main></body></html>';
    foreach (['default', 'starter'] as $theme) {
        $GLOBALS['menu_test_theme'] = $theme;
        $output = sblog_menu_output_html($public, ['action' => 'home', 'content_type' => 'text/html']);
        menu_test_assert(str_contains($output, 'Replacement menu') && !str_contains($output, 'Old navigation'), $theme . ' navigation fallback did not take over');
        menu_test_assert(str_contains($output, 'Article navigation'), 'Fallback replaced an unrelated navigation element');
        menu_test_same(1, substr_count($output, 'Replacement menu'), 'Fallback rendered duplicate primary menus');
        menu_test_assert(!str_contains($output, 'data-sblog-menu-style'), 'A flat default menu loaded unused hierarchy CSS');
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
    sblog_menu_save($hierarchy);
    foreach (['default', 'starter', 'paper'] as $theme) {
        $GLOBALS['menu_test_theme'] = $theme;
        $nestedPage = $theme === 'paper'
            ? str_replace('Old navigation', sblog_menu_render($nestedContext), $dedicatedLayout) : $public;
        $output = sblog_menu_output_html($nestedPage, ['action' => 'home', 'content_type' => 'text/html']);
        menu_test_same(1, substr_count($output, 'data-sblog-menu-style'), $theme . ' did not load hierarchy CSS exactly once');
        menu_test_assert(str_contains($output, 'assets/public.css?v=' . SBLOG_MENU_MANAGER_VERSION), 'Hierarchy CSS did not use the plugin asset URL and cache version');
        menu_test_same($output, sblog_menu_output_html($output, ['action' => 'home', 'content_type' => 'text/html']), 'A second output pass duplicated hierarchy assets or markup');
        menu_test_assert(!str_contains($output, '<script'), 'Native submenu rendering introduced JavaScript');
        menu_test_assert(str_contains($output, 'Article navigation'), 'Nested fallback changed an unrelated navigation');
    }
    sblog_menu_save(menu_test_config([menu_test_item('replacement', 'custom', '', 'Replacement menu', '/blog/replacement')]));
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
    menu_test_same(['', ''], array_column(sblog_menu_config()['items'], 'parent_id'), 'Legacy form fields without parent_id did not remain roots');
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
    foreach ([null, 1, [], 'missing', 'first'] as $parentId) {
        $bad = $post; $bad['items'][0]['parent_id'] = $parentId; $badPosts[] = $bad;
    }
    $bad = $post; $bad['items'][0]['parent_id'] = 'second'; $bad['items'][1]['parent_id'] = 'first'; $badPosts[] = $bad;
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

    $nestedPost = $post;
    $nestedPost['items'] = [
        ['id' => 'leaf-a', 'parent_id' => 'child-a', 'destination' => 'route:home', 'label' => '', 'url' => '', 'enabled' => '1'],
        ['id' => 'child-a', 'parent_id' => 'root-a', 'destination' => 'route:archives', 'label' => '', 'url' => '', 'enabled' => '1'],
        ['id' => 'root-a', 'parent_id' => '', 'destination' => 'route:tags', 'label' => '', 'url' => '', 'enabled' => '1'],
        ['id' => 'leaf-b', 'parent_id' => 'child-b', 'destination' => 'route:links', 'label' => '', 'url' => '', 'enabled' => '1'],
        ['id' => 'child-b', 'parent_id' => 'root-a', 'destination' => 'route:categories', 'label' => '', 'url' => '', 'enabled' => '1'],
        ['id' => 'root-b', 'parent_id' => '', 'destination' => 'route:rss', 'label' => '', 'url' => '', 'enabled' => '1'],
        ['id' => 'other-child', 'parent_id' => 'root-b', 'destination' => 'route:sitemap', 'label' => '', 'url' => '', 'enabled' => '1'],
    ];
    foreach ([
        'save' => ['root-a', 'child-a', 'leaf-a', 'child-b', 'leaf-b', 'root-b', 'other-child'],
        'down:root-a' => ['root-b', 'other-child', 'root-a', 'child-a', 'leaf-a', 'child-b', 'leaf-b'],
        'down:child-a' => ['root-a', 'child-b', 'leaf-b', 'child-a', 'leaf-a', 'root-b', 'other-child'],
        'up:child-b' => ['root-a', 'child-b', 'leaf-b', 'child-a', 'leaf-a', 'root-b', 'other-child'],
        'up:root-a' => ['root-a', 'child-a', 'leaf-a', 'child-b', 'leaf-b', 'root-b', 'other-child'],
        'remove:root-a' => ['child-a', 'leaf-a', 'child-b', 'leaf-b', 'root-b', 'other-child'],
        'remove:child-a' => ['root-a', 'leaf-a', 'child-b', 'leaf-b', 'root-b', 'other-child'],
    ] as $operation => $expectedOrder) {
        $_POST = $nestedPost;
        $_POST['operation'] = $operation;
        menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'save_menus']), 'Nested form operation did not redirect');
        menu_test_same('success', $GLOBALS['menu_test_flash'][0], 'Nested form operation failed: ' . $operation);
        $savedItems = sblog_menu_config()['items'];
        menu_test_same($expectedOrder, array_column($savedItems, 'id'), 'Nested form operation lost sibling order or detached a subtree: ' . $operation);
        $parents = array_column($savedItems, 'parent_id', 'id');
        if ($operation === 'remove:root-a') {
            menu_test_same('', $parents['child-a'], 'Deleting a root did not promote its direct child');
            menu_test_same('', $parents['child-b'], 'Deleting a root did not promote every direct child');
            menu_test_same('child-a', $parents['leaf-a'], 'Deleting a root detached a grandchild from its surviving parent');
        } elseif ($operation === 'remove:child-a') {
            menu_test_same('root-a', $parents['leaf-a'], 'Deleting an intermediate parent did not promote its child one level');
            menu_test_same('child-b', $parents['leaf-b'], 'Deleting a parent changed an unrelated branch');
        }
    }
    $maxPost = $post;
    $maxPost['items'] = [];
    for ($index = 0; $index < 100; $index++) {
        $maxPost['items'][] = ['id' => 'max-' . $index, 'parent_id' => $index === 0 ? '' : 'max-0', 'destination' => 'route:home', 'label' => '', 'url' => '', 'enabled' => '1'];
    }
    $_POST = $maxPost;
    menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'save_menus']), 'A complete 100-item hierarchy did not redirect');
    menu_test_same('success', $GLOBALS['menu_test_flash'][0], 'A complete 100-item hierarchy was rejected');
    menu_test_same(100, count(sblog_menu_config()['items']), 'A complete 100-item hierarchy was truncated');
    $maxStored = setting('menu_manager_config');
    $maxWrites = $GLOBALS['menu_test_settings_writes'];
    unset($_POST['items_complete']);
    menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'save_menus']), 'A truncated 100-item hierarchy did not redirect');
    menu_test_same('error', $GLOBALS['menu_test_flash'][0], 'A missing 100-item completeness sentinel was accepted');
    menu_test_same($maxStored, setting('menu_manager_config'), 'A truncated 100-item hierarchy overwrote the stored menu');
    menu_test_same($maxWrites, $GLOBALS['menu_test_settings_writes'], 'A truncated hierarchy caused a partial write');
    $_POST = $maxPost;
    $_POST['items'][] = ['id' => 'max-100', 'parent_id' => 'max-0', 'destination' => 'route:home', 'label' => '', 'url' => '', 'enabled' => '1'];
    menu_test_exception(MenuTestResponse::class, static fn() => sblog_menu_handle_request(['action' => 'save_menus']), 'An oversized hierarchy did not redirect');
    menu_test_same('error', $GLOBALS['menu_test_flash'][0], 'More than 100 combined parent and child entries were accepted');
    menu_test_same($maxStored, setting('menu_manager_config'), 'An oversized hierarchy overwrote the stored menu');
    unset($_SESSION['menu_manager_draft']);

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
