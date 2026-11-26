<?php
declare(strict_types=1);

// Isolated list/settings checks. Optional --core extracts only public URL/query helpers.
// Define SUNNY_LIST_FIXTURE_ONLY before requiring this file to reuse its synthetic data.
if (PHP_SAPI !== 'cli' && !defined('SUNNY_LIST_FIXTURE_ONLY')) { http_response_code(403); exit; }

function list_assert(bool $condition, string $message): void
{
    $GLOBALS['list_assertions'] = (int)($GLOBALS['list_assertions'] ?? 0) + 1;
    if (!$condition) { throw new RuntimeException($message); }
}
function list_same(mixed $expected, mixed $actual, string $message): void
{
    list_assert($expected === $actual, $message . '\nExpected: ' . var_export($expected, true) . '\nActual: ' . var_export($actual, true));
}
function list_load_core(string $path): void
{
    $needed = array_fill_keys(['app_base_path', 'app_path', 'script_url', 'url_for', 'url_with_query', 'home_page_url', 'use_pretty_url', 'fetch_published_posts', 'count_published_posts'], true);
    $tokens = token_get_all((string)file_get_contents($path));
    for ($index = 0, $count = count($tokens); $index < $count; $index++) {
        if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) { continue; }
        $nameIndex = $index + 1;
        while (is_array($tokens[$nameIndex] ?? null) && $tokens[$nameIndex][0] === T_WHITESPACE) { $nameIndex++; }
        $name = is_array($tokens[$nameIndex] ?? null) ? $tokens[$nameIndex][1] : '';
        if (!isset($needed[$name])) { continue; }
        $source = ''; $depth = 0; $started = false;
        for (; $index < $count; $index++) {
            $token = $tokens[$index]; $source .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; $started = true; }
            elseif ($token === '}' && $started && --$depth === 0) { break; }
        }
        eval($source); unset($needed[$name]);
    }
    list_same([], array_keys($needed), 'The supplied core must provide actual URL and public listing helpers.');
}
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function str_len_u(string $text): int { return mb_strlen($text, 'UTF-8'); }
function str_sub_u(string $text, int $start, ?int $length = null): string { return mb_substr($text, $start, $length, 'UTF-8'); }
function setting(string $key, string $default = ''): string { return (string)($GLOBALS['list_settings'][$key] ?? $default); }
function theme_setting(string $key, string $default = '', ?string $slug = null): string
{
    list_same('sunny', $slug, 'List settings must remain scoped to Sunny.');
    return (string)($GLOBALS['list_theme_settings'][$key] ?? $default);
}
function is_admin(): bool { return (bool)($GLOBALS['list_admin'] ?? false); }
function add_theme_action(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['list_actions'][$hook][$priority][] = $callback; }
function add_theme_filter(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['list_filters'][$hook][$priority][] = $callback; }
function theme_filter(string $hook, mixed $value, array $context = []): mixed
{
    $groups = $GLOBALS['list_filters'][$hook] ?? []; ksort($groups);
    foreach ($groups as $callbacks) foreach ($callbacks as $callback) { $value = $callback($value, $context); }
    return $value;
}
function db(): PDO { return $GLOBALS['list_db']; }
function all_rows(string $sql, array $params = []): array { $statement = db()->prepare($sql); $statement->execute($params); return $statement->fetchAll(PDO::FETCH_ASSOC); }
function one(string $sql, array $params = []): ?array { return all_rows($sql, $params)[0] ?? null; }
function val(string $sql, array $params = []): mixed { $statement = db()->prepare($sql); $statement->execute($params); return $statement->fetchColumn(); }
function public_content_context(array $post): array { $GLOBALS['list_public_context_ids'][] = (int)$post['id']; return $post; }
function content_requires_password(array $post): bool { return false; }
function content_permalink(array $post): string { return url_for('post', ['slug' => $post['slug']]); }
function safe_link_url(string $url): string { return $url; }
function theme_logo_url(): string { return '/blog/avatar.svg'; }
function tag_descriptors(array $post): array { return []; }
function public_date(int $date): string { return date('Y-m-d H:i', $date); }
function friendly_public_date(int $date): string { return date('m-d', $date); }
function fetch_posts_by_tag_slug(string $slug): array
{
    $ids = $GLOBALS['list_tag_ids'][$slug] ?? [];
    if ($ids === []) { return []; }
    return array_map('public_content_context', all_rows('SELECT * FROM posts WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') AND kind = ? AND status = ? AND published_at <= ? ORDER BY is_pinned DESC, published_at DESC, id DESC', ['post', 'published', time()]));
}

$_SERVER['SCRIPT_NAME'] = '/blog/index.php';
$_GET = []; $_SESSION = [];
$GLOBALS['list_settings'] = ['posts_per_page' => '3', 'pretty_url' => '1'];
$GLOBALS['list_theme_settings'] = [];
$GLOBALS['list_db'] = new PDO('sqlite::memory:');
db()->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
db()->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, description TEXT, sort_order INTEGER)');
db()->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, nickname TEXT, username TEXT)');
db()->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, author_id INTEGER, slug TEXT, title TEXT, excerpt TEXT, content TEXT, kind TEXT, status TEXT, published_at INTEGER, is_pinned INTEGER, category_id INTEGER, views INTEGER, content_password_hash TEXT)');
db()->exec('CREATE TABLE comments (id INTEGER PRIMARY KEY, post_id INTEGER, parent_id INTEGER, reply_to_name TEXT, author_name TEXT, author_email TEXT, content TEXT, created_at INTEGER, status TEXT)');
db()->exec("INSERT INTO categories VALUES (1, 'life', '生活', '生活文章', 0), (2, 'tech', '技术', '技术文章', 1), (3, 'empty', '空分类', '', 2)");
db()->exec("INSERT INTO users VALUES (1, '示例站长', 'fixture')");
$insert = db()->prepare('INSERT INTO posts VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)');
for ($id = 1; $id <= 10; $id++) {
    $insert->execute([$id, 'post-' . $id, '示例文章 ' . $id, '第 ' . $id . ' 篇摘要', '', $id === 10 ? 'page' : 'post', $id === 8 ? 'draft' : 'published', $id === 9 ? time() + 3600 : time() - $id * 60, $id === 5 ? 1 : 0, $id <= 5 ? 1 : 2]);
}
$GLOBALS['list_tag_ids'] = ['sunny' => [1, 3, 5, 6, 7, 8, 9, 10], 'tech' => [6, 7]];
$options = PHP_SAPI === 'cli' ? getopt('', ['core:']) : [];
$corePath = (string)($options['core'] ?? '');
if ($corePath !== '') { list_assert(is_file($corePath), 'The supplied core source must exist.'); list_load_core($corePath); }
else {
    function app_path(string $path = '/'): string { return '/blog/' . ltrim($path, '/'); }
    function script_url(): string { return '/blog/index.php'; }
    function use_pretty_url(): bool { return setting('pretty_url', '0') === '1'; }
    function url_for(string $action, array $params = []): string
    {
        if ($action === 'home') { return use_pretty_url() ? app_path() : script_url(); }
        $slug = rawurlencode((string)($params['slug'] ?? ''));
        return use_pretty_url() ? app_path(($action === 'post' ? 'archive' : $action) . '/' . $slug) : script_url() . '?a=' . $action . '&slug=' . $slug;
    }
    function url_with_query(string $url, array $params): string { return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($params); }
    function home_page_url(int $page): string { return $page <= 1 ? url_for('home') : (use_pretty_url() ? app_path('/page/' . $page) : script_url() . '?p=' . $page); }
    function fetch_published_posts(int $limit, int $offset): array { return array_map('public_content_context', all_rows('SELECT * FROM posts WHERE kind = ? AND status = ? AND published_at <= ? ORDER BY is_pinned DESC, published_at DESC, id DESC LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset), ['post', 'published', time()])); }
    function count_published_posts(): int { return (int)val('SELECT COUNT(*) FROM posts WHERE kind = ? AND status = ? AND published_at <= ?', ['post', 'published', time()]); }
}
require dirname(__DIR__) . '/functions.php';

function list_render(string $action = 'home', string $slug = '', mixed $page = 1): string
{
    $_GET = ['a' => $action, 'p' => $page, 'slug' => $slug] + array_intersect_key($_GET, ['theme_preview' => true]);
    return (string)theme_filter('content', '<p>Original route content</p>', ['active' => $action === 'tag' ? 'tags' : 'home', 'title' => $action === 'home' ? 'Fixture site' : 'Fixture ' . $slug, 'site_name' => 'Fixture site']);
}
function list_xpath(string $html): DOMXPath
{
    $document = new DOMDocument(); $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><main>' . $html . '</main>');
    libxml_clear_errors(); libxml_use_internal_errors($previous); return new DOMXPath($document);
}
function list_ids(DOMXPath $xpath): array { return array_map(static fn(DOMElement $node): int => (int)$node->getAttribute('data-sunny-post-id'), iterator_to_array($xpath->query('//*[@data-sunny-list]//article[@data-sunny-post-id]'))); }
function list_next(DOMXPath $xpath): string { return $xpath->query('//a[@rel="next"]')->item(0)?->getAttribute('href') ?? ''; }

if (defined('SUNNY_LIST_FIXTURE_ONLY')) { return; }

try {
    list_assert(class_exists(DOMDocument::class), 'List checks require PHP DOM.');
    $schema = json_decode((string)file_get_contents(dirname(__DIR__) . '/settings.json'), true, 512, JSON_THROW_ON_ERROR);
    $fields = array_merge(...array_column($schema['sections'], 'fields'));
    $field = array_values(array_filter($fields, static fn(array $item): bool => $item['key'] === 'list_load_mode'))[0] ?? [];
    list_same('pagination', $field['default'] ?? null, 'Existing sites default to ordinary page navigation.');
    list_same(['pagination', 'button', 'auto'], array_keys($field['options'] ?? []), 'The settings expose exactly the supported modes.');
    foreach ([null, '', 'invalid', 'AUTO', '0'] as $invalid) {
        $GLOBALS['list_theme_settings'] = $invalid === null ? [] : ['list_load_mode' => $invalid];
        list_same('pagination', sunny_list_load_mode(), 'Missing or invalid modes must keep pagination.');
    }
    foreach (['pagination', 'button', 'auto'] as $mode) {
        $GLOBALS['list_theme_settings'] = ['list_load_mode' => $mode];
        $first = list_xpath(list_render());
        list_same([5, 1, 2], list_ids($first), 'Home keeps pinned order and uses the core per-page count in ' . $mode . '.');
        list_same($mode, $first->query('//*[@data-sunny-list]')->item(0)->getAttribute('data-load-mode'), 'The DOM declares its active loading mode.');
        list_same('home', $first->query('//*[@data-sunny-list]')->item(0)->getAttribute('data-list-key'), 'Home owns a stable list identity.');
        list_same('/blog/page/2', list_next($first), 'Home keeps core pretty pagination paths.');
        list_same(1, $first->query('//nav[contains(@class,"sunny-pagination")]')->length, 'Every mode retains usable no-JS pagination.');
        list_same($mode === 'pagination' ? 0 : 1, $first->query('//*[@data-sunny-load-controls and @hidden]')->length, 'Enhanced controls start hidden.');
        if ($mode !== 'pagination') {
            list_same('/blog/page/2', $first->query('//a[@data-sunny-load-more and @data-no-pjax]')->item(0)?->getAttribute('href'), 'Load controls use the same next page as fallback navigation.');
        }
        $middle = list_xpath(list_render('home', '', 2));
        list_same([3, 4, 6], list_ids($middle), 'Home page two must not repeat the pinned article.');
        list_same('/blog/page/3', list_next($middle), 'Middle pages advance once.');
        $last = list_xpath(list_render('home', '', 3));
        list_same([7], list_ids($last), 'The final home page contains only its remainder.');
        list_same('', list_next($last), 'Final pages have no next link.');
        if ($mode !== 'pagination') {
            list_same(1, $last->query('//*[@data-sunny-load-controls and not(a)]')->length, 'The final enhanced control container remains identifiable without a next button.');
            list_same('已全部加载', trim($last->query('//*[@data-sunny-load-status]')->item(0)->textContent), 'Final controls announce completion.');
        }
    }
    $GLOBALS['list_theme_settings'] = ['list_load_mode' => 'auto'];
    foreach ([0, -4, 'bad', ['2']] as $page) {
        list_same([5, 1, 2], list_ids(list_xpath(list_render('home', '', $page))), 'Invalid or nonpositive page requests safely choose page one.');
    }
    $category = list_xpath(list_render('category', 'life', 2));
    list_same([3, 4], list_ids($category), 'Categories paginate only their own published articles.');
    list_same('category:life', $category->query('//*[@data-sunny-list]')->item(0)->getAttribute('data-list-key'), 'Category lists cannot mix with home or tags.');
    list_same('/blog/category/life', $category->query('//a[@rel="prev"]')->item(0)?->getAttribute('href'), 'Category page one has a clean previous URL.');
    list_same([3, 4], list_ids(list_xpath(list_render('category', 'life', PHP_INT_MAX))), 'Category overflow clamps to its final page.');
    $tag = list_xpath(list_render('tag', 'sunny'));
    list_same([5, 1, 3], list_ids($tag), 'Tags keep their published, pinned ordering without unrelated posts.');
    list_same('tag:sunny', $tag->query('//*[@data-sunny-list]')->item(0)->getAttribute('data-list-key'), 'Tags have their own stable identity.');
    list_same('/blog/tag/sunny?p=2', list_next($tag), 'Tag pagination appends p explicitly because core slug URLs ignore extra params.');
    list_same([6, 7], list_ids(list_xpath(list_render('tag', 'sunny', 2))), 'Tag page two uses its own remainder.');
    list_same([6, 7], list_ids(list_xpath(list_render('category', 'tech'))), 'Switching categories starts a separate group.');
    list_same([6, 7], list_ids(list_xpath(list_render('tag', 'tech'))), 'The same slug in a tag cannot reuse a category page.');
    $empty = list_xpath(list_render('category', 'empty'));
    list_same([], list_ids($empty), 'Empty categories produce no fake cards.');
    list_same(1, $empty->query('//*[@data-sunny-list]/*[contains(@class,"sunny-post-grid")]')->length, 'Even an empty list exposes the common grid contract.');
    list_same('', list_next($empty), 'Empty lists never offer another page.');
    list_same('<p>Original route content</p>', list_render('category', 'missing'), 'Missing categories keep the core route result.');
    foreach (['1' => true, '0' => false] as $pretty => $unused) {
        $GLOBALS['list_settings']['pretty_url'] = (string)$pretty;
        $GLOBALS['list_admin'] = true; $_GET['theme_preview'] = 'sunny';
        foreach (['home' => '', 'category' => 'life', 'tag' => 'sunny'] as $action => $slug) {
            $xpath = list_xpath(list_render($action, $slug));
            $next = list_next($xpath); $query = []; parse_str((string)parse_url($next, PHP_URL_QUERY), $query);
            list_same('sunny', $query['theme_preview'] ?? null, 'Authorized theme preview survives ' . $action . ' pagination.');
            if ($action !== 'home' || $pretty === 0) { list_same('2', $query['p'] ?? null, 'Page query survives slug-route and nonpretty home generation.'); }
            if ($action !== 'home' && $pretty === 0) { list_same($action, $query['a'] ?? null, 'Plain filter URLs preserve their route.'); list_same($slug, $query['slug'] ?? null, 'Plain filter URLs preserve their slug.'); }
        }
    }
    $GLOBALS['list_admin'] = false; $_GET['theme_preview'] = 'sunny';
    list_assert(!str_contains(sunny_list_page_url('tag', 'sunny', 2), 'theme_preview'), 'A visitor cannot propagate theme preview authorization.');
    $slug = 'quoted"<&分類';
    $html = sunny_render_list([], 7, 'tag', $slug); $xpath = list_xpath($html);
    list_same('tag:' . $slug, $xpath->query('//*[@data-sunny-list]')->item(0)->getAttribute('data-list-key'), 'Special slugs remain intact through escaped identity attributes.');
    list_assert(str_contains($html, 'tag:quoted&quot;&lt;&amp;分類'), 'List identity escapes HTML metacharacters.');
    list_assert(!str_contains($html, 'data-list-key="tag:quoted"'), 'A slug cannot break out of the identity attribute.');
    foreach (['0', '-2', 'invalid'] as $invalidPerPage) {
        $GLOBALS['list_settings']['posts_per_page'] = $invalidPerPage;
        list_same(1, sunny_list_per_page(), 'Invalid core page sizes retain at least one article.');
    }
    list_same([6], list_ids(list_xpath(list_render('category', 'tech'))), 'The minimum page size also applies to categories.');
    list_same([7], list_ids(list_xpath(list_render('tag', 'tech', 2))), 'The minimum page size also applies to tags.');
    $GLOBALS['list_settings']['posts_per_page'] = '3';
    list_same(7, count_published_posts(), 'Synthetic draft, future and page records remain excluded from the source.');
    echo 'Sunny list loading: ', $GLOBALS['list_assertions'], ' assertions passed', $corePath !== '' ? ' with real core URL/query helpers.' : '.', "\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n" . $error->getTraceAsString() . "\n"); exit(1);
}
