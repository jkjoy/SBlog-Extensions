<?php

declare(strict_types=1);

// Run: SBLOG_CORE_INDEX=/path/to/Simple-PHP-Blog/index.php php scripts/test-h-siren.php.
// Extracts actual core helpers without running the application; all data is in memory.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

function siren_assert(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}
function siren_same(mixed $expected, mixed $actual, string $message): void
{
    siren_assert($expected === $actual, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}
function siren_load_core(): void
{
    $needed = array_fill_keys([
        'q', 'one', 'all_rows', 'val', 'h', 'str_len_u', 'str_sub_u', 'str_lower_u',
        'valid_http_url', 'safe_link_url', 'content_kind', 'content_permalink',
        'parse_reply_hidden_blocks', 'strip_reply_hidden_blocks', 'content_has_reply_hidden_blocks',
        'markdown_to_plain', 'derive_excerpt', 'content_requires_password',
        'public_content_description', 'public_content_context',
        'fetch_published_posts', 'count_published_posts', 'url_with_query',
    ], true);
    $core = getenv('SBLOG_CORE_INDEX');
    if (!is_string($core) || !is_file($core) || !is_readable($core)) {
        throw new RuntimeException('Set SBLOG_CORE_INDEX to a readable SBlog 1.14.18+ index.php.');
    }
    $tokens = token_get_all((string)file_get_contents($core));
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if (!is_array($tokens[$j] ?? null) || !isset($needed[$tokens[$j][1]])) { continue; }
        $name = $tokens[$j][1];
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
    siren_same([], array_keys($needed), 'Every tested core helper must be found.');
}

final class SirenTestResponse extends RuntimeException {}
function simple_error_page(string $title, string $message, int $status): never { throw new SirenTestResponse($message, $status); }

function db(): PDO { return $GLOBALS['siren_test_db']; }
function setting(string $key, string $default = ''): string { return $GLOBALS['siren_test_settings'][$key] ?? $default; }
function theme_setting(string $key, string $default = '', ?string $slug = null): string { return $GLOBALS['siren_test_options'][$key] ?? $default; }
function theme_asset_url(string $path): string { return '/blog/themes/h-siren/' . $path; }
function asset_url(string $path): string { return '/blog/' . ltrim($path, '/'); }
function script_url(): string { return '/blog/index.php'; }
function url_for(string $route, array $parameters = []): string { return script_url() . '?a=' . $route . ($parameters ? '&' . http_build_query($parameters) : ''); }
function home_page_url(int $page): string { return url_for('home') . ($page > 1 ? '&p=' . $page : ''); }
function is_admin(): bool { return $GLOBALS['siren_test_admin']; }
function sblog_t(string $message, array $parameters = []): string
{
    foreach ($parameters as $key => $value) { $message = str_replace('{' . $key . '}', (string)$value, $message); }
    return $message;
}
function sblog_tn(string $message, int $count): string { return sblog_t($message, ['count' => $count]); }
function public_date(int $timestamp): string { return date('Y-m-d', $timestamp); }
function fetch_content_by_identifier(string $kind, string $identifier, bool $preview = false): ?array { return $GLOBALS['siren_test_detail']; }
function sblog_i18n_locale(): string { return 'zh-CN'; }
function sblog_i18n_head(): string { return ''; }
function theme_favicon_url(): string { return '/blog/favicon.png'; }
function site_footer_text(): string { return 'Fixture footer'; }
function theme_action(string $hook, array $context = []): void { $GLOBALS['siren_test_actions'][] = $hook; }

function siren_post(array $values = []): array
{
    return array_replace([
        'id' => 1, 'slug' => 'first', 'title' => 'Public title', 'excerpt' => '', 'content' => 'Public excerpt',
        'content_password_hash' => '', 'kind' => 'post', 'category_id' => 1, 'views' => 5,
        'is_pinned' => 0, 'status' => 'published', 'published_at' => time() - 100,
    ], $values);
}
function siren_insert(array $values): void
{
    $row = siren_post($values);
    q('INSERT INTO posts (' . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_fill(0, count($row), '?')) . ')', array_values($row));
}
function siren_reset(): void
{
    $GLOBALS['siren_test_db'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    db()->exec('CREATE TABLE posts (id INTEGER PRIMARY KEY, slug TEXT, title TEXT, excerpt TEXT, content TEXT, content_password_hash TEXT, kind TEXT, category_id INTEGER, views INTEGER, is_pinned INTEGER, status TEXT, published_at INTEGER)');
    db()->exec('CREATE TABLE categories (id INTEGER PRIMARY KEY, name TEXT, slug TEXT)');
    db()->exec('CREATE TABLE comments (post_id INTEGER, status TEXT)');
    q('INSERT INTO categories VALUES(1, ?, ?)', ['Category <&>', 'category']);
    $GLOBALS['siren_test_settings'] = ['posts_per_page' => '2'];
    $GLOBALS['siren_test_options'] = [];
    $GLOBALS['siren_test_admin'] = false;
    $GLOBALS['siren_test_detail'] = null;
    $GLOBALS['siren_test_actions'] = [];
    $_GET = [];
}
function siren_render_detail(string $content): string
{
    $GLOBALS['sblog_current_action'] = 'post';
    $status = 200;
    $siteName = 'Fixture Site';
    $title = 'Detail title';
    $fullTitle = 'Detail title · Fixture Site';
    $description = 'Description';
    $keywords = '';
    $bodyClass = 'theme-public';
    $active = 'home';
    $navPages = [];
    $themeContext = [];
    $customHeadCode = '';
    $flash = null;
    ob_start();
    require dirname(__DIR__) . '/themes/h-siren/layout.php';
    return (string)ob_get_clean();
}

define('APP_VERSION', 'test');
siren_load_core();
require dirname(__DIR__) . '/themes/h-siren/functions.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never { throw new ErrorException($message, 0, $severity, $file, $line); });

$tests = [];
$tests['covers use public images and never protected content'] = static function (): void {
    $locked = siren_post(['content' => '![Secret](/secret-password-cover.jpg)', 'content_password_hash' => 'hash']);
    siren_assert(str_contains(hsiren_post_cover($locked), '/assets/images/cover-'), 'Password images must not become covers.');
    $reply = siren_post(['content' => "[reply]\n![Secret](/secret-reply-cover.jpg)\n[/reply]\n![Public](/public-cover.jpg)"]);
    siren_same('/public-cover.jpg', hsiren_post_cover($reply), 'The first public image must supply the cover.');
    siren_same('/blog/uploads/public.jpg', hsiren_post_cover(siren_post(['content' => '![Public](uploads/public.jpg)'])), 'Editor-relative images resolve against the blog base.');
    foreach (['javascript:alert(1)', 'data:image/png;base64,abc', '//outside.test/a.jpg', '/\\outside.test/a.jpg'] as $url) {
        siren_assert(str_contains(hsiren_post_cover(siren_post(['content' => '![](' . $url . ')'])), '/assets/images/cover-'), 'Unsafe cover must use a fallback: ' . $url);
    }
};
$tests['cards escape public text and discard password and reply-only excerpts'] = static function (): void {
    $posts = [
        siren_post(['title' => '<script>title</script>', 'content_password_hash' => 'hash', 'content' => 'PASSWORD_SECRET ![](/secret-password.jpg)', 'excerpt' => 'PASSWORD_SECRET_EXCERPT']),
        siren_post(['id' => 2, 'slug' => 'second', 'title' => 'Reply post', 'excerpt' => 'REPLY_SECRET_EXCERPT', 'content' => "Visible intro\n[reply]\nREPLY_SECRET ![](/secret-reply.jpg)\n[/reply]"]),
        siren_post(['id' => 3, 'slug' => 'third', 'excerpt' => '<img src=x onerror=alert(1)>']),
    ];
    $html = hsiren_post_list($posts);
    foreach (['PASSWORD_SECRET', 'REPLY_SECRET', '/secret-password.jpg', '/secret-reply.jpg', '<script>', '<img src=x'] as $secret) {
        siren_assert(!str_contains($html, $secret), 'Card output must exclude secret/raw markup: ' . $secret);
    }
    foreach (['&lt;script&gt;title&lt;/script&gt;', 'Category &lt;&amp;&gt;', '&lt;img src=x onerror=alert(1)&gt;', 'Visible intro', '此文章受密码保护。'] as $public) {
        siren_assert(str_contains($html, $public), 'Expected escaped public text: ' . $public);
    }
};
$tests['search matches literal title characters and never private bodies'] = static function (): void {
    siren_insert(['id' => 1, 'slug' => 'literal', 'title' => 'Literal 100%_ title']);
    siren_insert(['id' => 2, 'slug' => 'other', 'title' => 'Unrelated title', 'content' => 'PRIVATE_BODY_SECRET', 'content_password_hash' => 'hash']);
    siren_insert(['id' => 3, 'slug' => 'third', 'title' => 'Other 100XX title']);
    foreach (['%', '_', '100%_'] as $query) {
        $_GET = ['q' => $query];
        $html = hsiren_home_content();
        siren_assert(str_contains($html, 'Literal 100%_ title'), 'Literal title search must match: ' . $query);
        siren_assert(!str_contains($html, 'Unrelated title') && !str_contains($html, 'Other 100XX title'), 'SQL wildcard characters must be escaped: ' . $query);
    }
    $_GET = ['q' => 'PRIVATE_BODY_SECRET'];
    $html = hsiren_home_content();
    siren_assert(!str_contains($html, 'Unrelated title'), 'Private body terms must not reveal matching posts.');
    $_GET = ['q' => '<script>'];
    siren_assert(!str_contains(hsiren_home_content(), '<script>'), 'Search heading must escape user input.');
};
$tests['search rejects pages beyond matching results'] = static function (): void {
    siren_insert(['id' => 1, 'slug' => 'match', 'title' => 'Matching title']);
    siren_insert(['id' => 2, 'slug' => 'other', 'title' => 'Other title']);
    siren_insert(['id' => 3, 'slug' => 'last', 'title' => 'Last title']);
    $_GET = ['q' => 'Matching', 'p' => '2'];
    try {
        hsiren_home_content();
        throw new RuntimeException('An out-of-range search page must not render an empty 2 / 1 list.');
    } catch (SirenTestResponse $response) {
        siren_same(404, $response->getCode(), 'Search pagination must return the core 404 response.');
    }
};
$tests['administrator preview links preserve fragments and avoid non-public endpoints'] = static function (): void {
    $_GET = ['theme_preview' => 'h-siren'];
    siren_same('/blog/archives', hsiren_url('/blog/archives'), 'Visitors must not inherit administrator preview.');
    $GLOBALS['siren_test_admin'] = true;
    siren_same('/blog/archives?theme_preview=h-siren#year', hsiren_url('/blog/archives#year'), 'Pretty navigation must preserve fragments.');
    siren_same('/blog/index.php?a=post&slug=one&theme_preview=h-siren#comments', hsiren_url('/blog/index.php?a=post&slug=one#comments'), 'Query navigation must preserve fragments.');
    foreach (['https://outside.test/path', '//outside.test/path', '#comments', '/blog/rss.xml', '/blog/file.zip', '/blog/admin/themes', '/blog/login', '/blog/index.php?a=rss', '/blog/index.php?a=admin_settings', '/blog/index.php?a=download_attachment', '/blog/index.php?theme_preview=h-siren'] as $url) {
        siren_same($url, hsiren_url($url), 'Preview must not modify this endpoint: ' . $url);
    }
};
$tests['detail layout preserves gates comments and body headings'] = static function (): void {
    $_GET = ['slug' => 'detail'];
    $GLOBALS['siren_test_detail'] = siren_post(['slug' => 'detail', 'content' => "[reply]\n![](/secret-banner.jpg)\n[/reply]"]);
    $gate = '<aside class="content-gate">GATE_MARKUP</aside>';
    $comments = '<section id="comments">PLUGIN_IDENTITY_MARKUP<form><input name="csrf_token" value="fixture"></form></section>';
    $bodyHeading = '<h1 class="post-title">BODY_HEADING</h1>';
    $content = '<article class="post-detail"><h1 class="post-title">Detail title</h1><div class="meta"><span>META_MARKUP</span></div><div class="post-content">' . $gate . $bodyHeading . '</div></article>' . $comments;
    $html = siren_render_detail($content);
    foreach ([$gate, $comments, $bodyHeading] as $markup) { siren_assert(str_contains($html, $markup), 'Existing core content must remain exact.'); }
    siren_same(1, substr_count($html, 'META_MARKUP'), 'Metadata must move to the banner once.');
    siren_same(1, substr_count($html, '<h1>Detail title</h1>'), 'Banner must contain the detail title once.');
    siren_assert(!str_contains($html, '/secret-banner.jpg'), 'Private body images must not become detail banners.');
    foreach (['head', 'body_open', 'header_before', 'header_after', 'content_before', 'content_after', 'footer_before', 'footer_after', 'body_close'] as $hook) {
        siren_assert(in_array($hook, $GLOBALS['siren_test_actions'], true), 'Theme layout must retain hook: ' . $hook);
    }
};

$failed = 0;
foreach ($tests as $name => $test) {
    siren_reset();
    try { $test(); fwrite(STDOUT, 'PASS ' . $name . "\n"); }
    catch (Throwable $exception) { $failed++; fwrite(STDERR, 'FAIL ' . $name . ': ' . $exception->getMessage() . "\n" . $exception->getTraceAsString() . "\n"); }
}
fwrite(STDOUT, sprintf("%d H-Siren tests, %d failures. Installed blog data was not loaded.\n", count($tests), $failed));
exit($failed === 0 ? 0 : 1);
