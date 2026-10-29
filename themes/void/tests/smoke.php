<?php

declare(strict_types=1);

// Run: php themes/void/tests/smoke.php
// Load actual helper functions, never boot index.php or open the installed database.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
if (!extension_loaded('pdo_sqlite') || !class_exists('DOMDocument')) {
    fwrite(STDERR, "VOID checks require PDO SQLite and DOM.\n");
    exit(1);
}
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('APP_VERSION', 'void-test');

function check(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}
function same(mixed $expected, mixed $actual, string $message): void
{
    check($expected === $actual, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}
function load_core_helpers(array $names): void
{
    $needed = array_fill_keys($names, true);
    $tokens = token_get_all((string)file_get_contents(dirname(__DIR__, 3) . '/index.php'));
    for ($i = 0, $length = count($tokens); $i < $length; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
        $j = $i + 1;
        while (isset($tokens[$j]) && (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE || $tokens[$j] === '&')) { $j++; }
        $name = is_array($tokens[$j] ?? null) ? $tokens[$j][1] : '';
        if (!isset($needed[$name])) { continue; }
        $code = '';
        $depth = 0;
        $started = false;
        for (; $i < $length; $i++) {
            $token = $tokens[$i];
            $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
                $started = true;
            } elseif ($token === '}' && $started && --$depth === 0) { break; }
        }
        eval($code);
        unset($needed[$name]);
    }
    same([], array_keys($needed), 'Missing actual core helpers.');
}

// Only runtime boundary functions are replaced; projections, URLs, SQL and gates are real.
function db(): PDO { return $GLOBALS['void_test_database']; }
function setting(string $name, string $default = ''): string { return (string)($GLOBALS['void_test_settings'][$name] ?? $default); }
function is_admin(): bool { return (bool)($GLOBALS['void_test_admin'] ?? false); }
function sblog_t(string $message, array $values = []): string
{
    foreach ($values as $name => $value) { $message = str_replace('{' . $name . '}', (string)$value, $message); }
    return $message;
}

load_core_helpers([
    'h', 'str_len_u', 'str_sub_u', 'str_lower_u', 'q', 'one', 'all_rows', 'val',
    'app_base_path', 'app_path', 'script_url', 'asset_url', 'use_pretty_url', 'url_for', 'home_page_url',
    'content_kind', 'content_permalink', 'parse_reply_hidden_blocks', 'strip_reply_hidden_blocks',
    'content_has_reply_hidden_blocks', 'markdown_to_plain', 'derive_excerpt', 'valid_http_url', 'safe_link_url',
    'content_requires_password', 'content_password_is_unlocked', 'public_content_description', 'public_content_context',
    'content_gate_icon', 'render_password_content_gate', 'render_reply_content_gate', 'pull_content_password_notice',
    'content_allows_comments', 'is_live_content', 'csrf_token', 'csrf_field',
    'fetch_published_posts', 'count_published_posts', 'fetch_content_by_identifier',
    'add_theme_filter', 'theme_filter', 'theme_callbacks', 'plugin_callbacks',
]);
require_once dirname(__DIR__) . '/content.php';
require dirname(__DIR__) . '/functions.php';

function post_fixture(array $changes = []): array
{
    return array_replace([
        'id' => 1, 'kind' => 'post', 'slug' => 'hello', 'title' => '公开文章',
        'content' => '公开内容', 'excerpt' => '', 'content_password_hash' => '',
        'status' => 'published', 'published_at' => time() - 60, 'created_at' => time() - 120,
        'is_pinned' => 0, 'allow_comments' => 1,
    ], $changes);
}
function reset_fixture(): void
{
    $GLOBALS['void_test_database'] = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    db()->exec('CREATE TABLE posts(id INTEGER PRIMARY KEY,kind TEXT,slug TEXT,title TEXT,content TEXT,excerpt TEXT,content_password_hash TEXT,status TEXT,published_at INTEGER,created_at INTEGER,is_pinned INTEGER,allow_comments INTEGER)');
    $GLOBALS['void_test_settings'] = ['site_name' => '测试站点', 'posts_per_page' => '2', 'pretty_url' => '0', 'comments_enabled' => '1'];
    $GLOBALS['void_test_admin'] = false;
    $GLOBALS['sblog_current_action'] = 'home';
    $_SERVER['SCRIPT_NAME'] = '/blog/index.php';
    $_SESSION = ['csrf_token' => 'fixture-csrf'];
    $_GET = [];
}
function insert_post(array $post): void
{
    $columns = array_keys($post);
    q('INSERT INTO posts(' . implode(',', $columns) . ') VALUES(' . implode(',', array_fill(0, count($columns), '?')) . ')', array_values($post));
}
function fragment(string $html): DOMXPath
{
    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><main>' . $html . '</main>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return new DOMXPath($dom);
}

$tests = [];
$tests['password and reply gates hide cover, word count and card excerpts'] = static function (): void {
    $secret = 'PRIVATESECRETMARKER';
    $password = post_fixture(['content' => "![secret](/$secret.jpg) $secret", 'excerpt' => $secret, 'content_password_hash' => 'fixture-hash']);
    same('', sbvoid_cover($password), 'Password image leaked into a cover.');
    same(0, sbvoid_word_count($password), 'Password body leaked into word count.');
    $card = sbvoid_cards([$password]);
    check(!str_contains($card, $secret), 'Password source leaked into card HTML.');
    check(str_contains($card, '此文章受密码保护'), 'Protected card must explain its content state.');
    check(!str_contains($card, 'class="word-count"'), 'Protected card should omit an unavailable word count.');

    $public = post_fixture(['content' => '公开内容', 'excerpt' => '']);
    $reply = post_fixture(['content' => "公开内容\n[reply]\n![secret](/$secret.jpg) $secret\n[/reply]", 'excerpt' => $secret]);
    same('', sbvoid_cover($reply), 'Reply-only image leaked into a cover.');
    same(sbvoid_word_count($public), sbvoid_word_count($reply), 'Hidden reply text changed the public word count.');
    check(!str_contains(sbvoid_cards([$reply]), $secret), 'Hidden reply source or private excerpt leaked into a card.');
    $mixed = post_fixture(['content' => "![visible](/visible.jpg)\n[reply]\n![secret](/$secret.jpg)\n[/reply]"]);
    same('/visible.jpg', sbvoid_cover($mixed), 'A public cover before a hidden block must remain usable.');
    foreach ([$password, $reply] as $protected) {
        $cover = sbvoid_list_cover($protected);
        check(str_starts_with($cover['src'], 'data:image/svg+xml;base64,'), 'Protected article should receive a public-title cover.');
        $svg = base64_decode(substr($cover['src'], strlen('data:image/svg+xml;base64,')), true);
        check(is_string($svg) && !str_contains($svg, $secret), 'Private content leaked into the generated cover.');
    }
};
$tests['search uses public projections and excludes unpublished content'] = static function (): void {
    insert_post(post_fixture(['id' => 1, 'title' => '正常标题', 'content' => "公开文本\n[reply]\nHIDDENSEARCHTOKEN\n[/reply]", 'excerpt' => 'HIDDENSEARCHTOKEN']));
    insert_post(post_fixture(['id' => 2, 'title' => '密码文章', 'content' => 'PASSWORDSEARCHTOKEN', 'excerpt' => 'PASSWORDSEARCHTOKEN', 'content_password_hash' => 'hash']));
    insert_post(post_fixture(['id' => 3, 'status' => 'draft', 'content' => 'DRAFTSEARCHTOKEN']));
    insert_post(post_fixture(['id' => 4, 'published_at' => time() + 86400, 'content' => 'FUTURESEARCHTOKEN']));
    foreach (['HIDDENSEARCHTOKEN', 'PASSWORDSEARCHTOKEN', 'DRAFTSEARCHTOKEN', 'FUTURESEARCHTOKEN'] as $term) {
        $_GET = ['void_search' => $term];
        $page = sbvoid_page('', '首页');
        same('<span>0 篇文章</span>', $page['meta'], 'Private or unpublished text matched search: ' . $term);
        check(!str_contains($page['body'], 'masonry-item'), 'A private search result card was exposed.');
    }
    $_GET = ['void_search' => '密码文章'];
    $page = sbvoid_page('', '首页');
    same('<span>1 篇文章</span>', $page['meta'], 'Public title of a protected article should be searchable.');
    check(!str_contains($page['body'], 'PASSWORDSEARCHTOKEN'), 'Protected snippet leaked after matching its public title.');
};
$tests['Unicode search and pagination work with query and pretty URLs'] = static function (): void {
    for ($id = 1; $id <= 5; $id++) { insert_post(post_fixture(['id' => $id, 'slug' => '文章-' . $id, 'title' => '你好世界 PHP ' . $id, 'content' => '公开内容'])); }
    foreach ([false, true] as $pretty) {
        $GLOBALS['void_test_settings']['pretty_url'] = $pretty ? '1' : '0';
        $_GET = ['void_search' => '你好世界 php', 'p' => '2'];
        $page = sbvoid_page('', '首页');
        same('search', $page['type'], 'Search page type is missing.');
        same('<span>5 篇文章</span>', $page['meta'], 'Unicode/ASCII case-insensitive search failed.');
        $xpath = fragment($page['body']);
        same(2, $xpath->query('//li[contains(@class,"masonry-item")]')->length, 'Search page size was not applied.');
        $href = $xpath->query('//a[@rel="next"]')->item(0)->getAttribute('href');
        check(str_starts_with($href, $pretty ? '/blog/page/3?' : '/blog/index.php?p=3&'), 'Wrong pagination URL mode.');
        check(str_contains($href, 'void_search=' . rawurlencode('你好世界 php')), 'Search term lost during pagination.');
    }
    $_GET = ['void_search' => ['invalid']];
    same('', sbvoid_search_term(), 'Array query input should be ignored.');
    $_GET = ['void_search' => str_repeat('界', 130)];
    same(120, str_len_u(sbvoid_search_term()), 'Unicode search limit must count characters.');
};
$tests['cards escape titles and reject executable image schemes'] = static function (): void {
    $title = '"><script>alert("x")</script> & 标题';
    $post = post_fixture(['title' => $title, 'slug' => '"><img src=x onerror=alert(1)>', 'content' => '![x](javascript:alert)']);
    $card = sbvoid_cards([$post]);
    $xpath = fragment($card);
    same(0, $xpath->query('//script')->length, 'Malicious title became executable HTML.');
    same(1, $xpath->query('//img')->length, 'A safe local fallback should replace an executable cover URL.');
    check(str_starts_with($xpath->query('//img')->item(0)->getAttribute('src'), 'data:image/svg+xml;base64,'), 'Executable image URL reached a card.');
    same($title, $xpath->query('//h1')->item(0)->textContent, 'Escaping corrupted the visible title.');
    $href = $xpath->query('//li/a')->item(0)->getAttribute('href');
    check(str_contains($href, rawurlencode($post['slug'])), 'Permalink must encode an adversarial slug.');
    foreach (['javascript:alert(1)', 'data:image/svg+xml,x', '//external.example/image.jpg', '/\\external.example/image.jpg', "/image\nname.jpg"] as $url) {
        same('', sbvoid_image_url($url), 'Unsafe image URL accepted: ' . json_encode($url));
    }
    same('https://example.com/a.jpg?x=1&y=2', sbvoid_image_url('https://example.com/a.jpg?x=1&amp;y=2'), 'Valid image URL entity decoding failed.');
};
$tests['title covers are deterministic, valid SVG with safe bounded Unicode text'] = static function (): void {
    $title = '中文标题 👩‍💻 & "引号" <script>alert(1)</script>';
    $url = sbvoid_title_cover($title);
    same($url, sbvoid_title_cover($title), 'The same title should generate the same cover.');
    check($url !== sbvoid_title_cover('另一个标题'), 'Different titles should produce distinct covers.');
    $svg = base64_decode(substr($url, strlen('data:image/svg+xml;base64,')), true);
    $dom = new DOMDocument();
    check($dom->loadXML($svg, LIBXML_NONET), 'Generated SVG is not valid XML.');
    same('http://www.w3.org/2000/svg', $dom->documentElement->namespaceURI, 'Cover is not SVG.');
    $xpath = new DOMXPath($dom);
    same($title, $xpath->query('//*[local-name()="title"]')->item(0)->textContent, 'SVG title was corrupted.');
    same(0, $xpath->query('//*[local-name()="script" or local-name()="image" or local-name()="foreignObject"]')->length, 'Title became executable or external SVG content.');
    $long = base64_decode(substr(sbvoid_title_cover(str_repeat('长标题W👩‍💻', 200)), strlen('data:image/svg+xml;base64,')), true);
    check(strlen($long) < 5000, 'Long titles should produce bounded SVG output.');
    check($dom->loadXML($long, LIBXML_NONET), 'Long Unicode title broke SVG.');
    $xpath = new DOMXPath($dom);
    $lines = $xpath->query('//*[local-name()="g" and @font-size="60"]/*[local-name()="text"]');
    same(3, $lines->length, 'Long titles should use at most three visible lines.');
    check(str_ends_with($lines->item(2)->textContent, '…'), 'Clipped titles should end with an ellipsis.');
    $control = base64_decode(substr(sbvoid_title_cover("控制\x01字符"), strlen('data:image/svg+xml;base64,')), true);
    check($dom->loadXML($control, LIBXML_NONET), 'Control characters broke SVG XML.');
    $invalid = base64_decode(substr(sbvoid_title_cover("无效\u{FFFE}\u{FFFF}字符"), strlen('data:image/svg+xml;base64,')), true);
    check($dom->loadXML($invalid, LIBXML_NONET), 'Disallowed XML characters broke SVG.');
    check(strlen(sbvoid_title_cover('a' . str_repeat("\u{0301}", 10000))) < 10000, 'Combining sequences should not produce unbounded cover output.');
};
$tests['list cover priority, disabled options and background layout preserve existing behavior'] = static function (): void {
    $config = sbvoid_config();
    $post = post_fixture(['content' => '![cover](/real-cover.jpg)']);
    $config['default_cover'] = '/uploads/default.jpg';
    $cover = sbvoid_list_cover($post, $config);
    same('/real-cover.jpg', $cover['src'], 'Default cover replaced a real article image.');
    same('/uploads/default.jpg', $cover['fallback'], 'Real image needs a fallback for load errors.');
    $shared = sbvoid_list_cover(post_fixture(['content' => '![cover](/uploads/default.jpg)']), $config);
    check(str_starts_with($shared['fallback'], 'data:image/svg+xml;base64,'), 'A failed body image identical to the default still needs a generated fallback.');
    $post['content'] = '没有图片';
    same('/uploads/default.jpg', sbvoid_list_cover($post, $config)['src'], 'Configured default cover was ignored.');
    $config['default_cover'] = 'javascript:alert(1)';
    check(str_starts_with(sbvoid_list_cover($post, $config)['src'], 'data:image/svg+xml;base64,'), 'Unsafe configured cover was accepted.');
    $config['default_cover'] = '';
    $config['auto_cover'] = false;
    $post['content'] = '![cover](/real-cover.jpg)';
    check(str_starts_with(sbvoid_list_cover($post, $config)['src'], 'data:image/svg+xml;base64,'), 'Disabling body images should retain title covers.');
    $config['title_cover'] = false;
    same('', sbvoid_list_cover($post, $config)['src'], 'Disabled fallback still generated an image.');
    $config['title_cover'] = true;
    $config['cover_style'] = 0;
    same('', sbvoid_list_cover($post, $config)['src'], 'Text-only layout gained a cover.');
    $config['cover_style'] = 2;
    $svg = base64_decode(substr(sbvoid_list_cover($post, $config)['src'], strlen('data:image/svg+xml;base64,')), true);
    $dom = new DOMDocument();
    check($dom->loadXML($svg, LIBXML_NONET), 'Background cover is invalid.');
    same(0, (new DOMXPath($dom))->query('//*[local-name()="g" and @font-size="60"]/*[local-name()="text"]')->length, 'Background cover duplicates the overlay title.');
    unset($config['title_cover'], $config['default_cover']);
    $config['cover_style'] = 1;
    check(str_starts_with(sbvoid_list_cover($post, $config)['src'], 'data:image/svg+xml;base64,'), 'Retained old configuration should enable title covers by default.');
};
$tests['detail transformation retains gate forms, comments, feedback and plugin HTML'] = static function (): void {
    $post = post_fixture(['content_password_hash' => 'hash']);
    $_SESSION['content_password_notices'][1] = '访问密码不正确。';
    $gate = render_password_content_gate($post);
    $comments = '<section id="comments" class="comments"><span data-plugin-identity="true">作者认证</span><div role="alert" id="comment-errors">邮箱错误</div><form id="comment-form" action="/submit" method="post">' . csrf_field() . '<input type="hidden" name="parent_id" value="9"><textarea name="content">保留未提交的评论 &amp; 内容</textarea></form></section>';
    $html = '<article class="post-detail"><h1 class="post-title">正文标题</h1><div class="meta"><a href="/category/one">分类</a></div><div class="post-content">' . $gate . '<pre><code class="language-html">&lt;div title="x &amp; y"&gt;保留代码&lt;/div&gt;</code></pre><table><tr><td>表格</td></tr></table><span data-plugin-body="true">插件正文</span></div></article><ul class="post-navigation"><li><a href="/next">下一篇</a></li></ul>' . $comments;
    [$body, $meta, $commentHtml] = sbvoid_detail($html);
    $bodyXpath = fragment($body);
    $commentXpath = fragment($commentHtml);
    same(0, $bodyXpath->query('//h1')->length, 'Detail heading should move to the banner.');
    same(1, $bodyXpath->query('//article[contains(@class,"yue")]')->length, 'Detail typography class was not attached.');
    same('<div title="x & y">保留代码</div>', $bodyXpath->query('//pre/code')->item(0)->textContent, 'Code content changed while moving HTML.');
    same(1, $bodyXpath->query('//table//td')->length, 'Article table was lost.');
    same(1, $bodyXpath->query('//*[@data-plugin-body]')->length, 'Plugin body markup was lost.');
    same(1, $bodyXpath->query('//input[@name="content_password"]')->length, 'Password gate was lost.');
    same('fixture-csrf', $bodyXpath->query('//input[@name="csrf_token"]')->item(0)->getAttribute('value'), 'Gate CSRF field was changed.');
    check(str_contains($body, '访问密码不正确。'), 'Password feedback was lost.');
    check(str_contains($meta, '分类') && !str_contains($body, '<div class="meta">'), 'Metadata was not separated from article body.');
    same(1, $bodyXpath->query('//ul[@class="post-navigation"]')->length, 'Neighbor navigation was lost.');
    same(0, $bodyXpath->query('//section[@id="comments"]')->length, 'Comments remained duplicated in the body.');
    same(1, $commentXpath->query('//*[@data-plugin-identity]')->length, 'Plugin identity markup was lost.');
    same('fixture-csrf', $commentXpath->query('//input[@name="csrf_token"]')->item(0)->getAttribute('value'), 'Comment CSRF field was changed.');
    same('9', $commentXpath->query('//input[@name="parent_id"]')->item(0)->getAttribute('value'), 'Reply target was changed.');
    same('保留未提交的评论 & 内容', $commentXpath->query('//textarea')->item(0)->textContent, 'Comment draft changed.');
    check(str_contains($commentHtml, '邮箱错误'), 'Comment error feedback was lost.');
    [$replyBody] = sbvoid_detail('<article><div class="post-content">' . render_reply_content_gate($post) . '</div></article>');
    check(str_contains($replyBody, 'content-gate--reply') && str_contains($replyBody, '#comments'), 'Reply gate action was lost.');
};
$tests['article metadata is separated once with and without DOM'] = static function (): void {
    $function = new ReflectionFunction('sbvoid_detail');
    $source = array_slice(file($function->getFileName()), $function->getStartLine() - 1, $function->getEndLine() - $function->getStartLine() + 1);
    // Exercise the actual fallback on PHP builds where DOM is compiled in.
    eval('namespace SBlogVoidWithoutDom; function class_exists(string $name): bool { return false; }' . implode('', $source));

    foreach (['sbvoid_detail', 'SBlogVoidWithoutDom\\sbvoid_detail'] as $transform) {
        foreach (['meta', 'meta extra-class', 'extra-class meta'] as $class) {
            $metadata = '<div class="' . $class . '"><time datetime="2026-10-29T08:00:00+08:00">2026-10-29</time><span>作者：测试作者</span><span>分类：<a href="/category/test">测试分类</a></span><span>浏览：42</span></div>';
            $html = '<article class="post-detail"><h1 class="post-title" itemprop="name headline">正文标题</h1>' . $metadata
                . '<div class="post-content"><p>正文内容</p><div class="meta">正文内的自定义信息</div><aside class="content-gate"><form><input name="csrf_token" value="must-survive"></form></aside></div></article>'
                . '<section id="comments"><p>评论内容</p></section>';
            [$body, $meta, $comments] = $transform($html);
            $xpath = fragment($body);
            same(0, $xpath->query('//article/div[contains(concat(" ", normalize-space(@class), " "), " meta ")]')->length, 'Core article metadata remained in the body: ' . $transform . ', ' . $class);
            same(1, fragment($meta)->query('//time')->length, 'Publication time was lost or duplicated.');
            check(str_contains($meta, '测试作者') && str_contains($meta, '/category/test') && str_contains($meta, '浏览：42'), 'Article metadata changed while moving it.');
            same(1, $xpath->query('//div[@class="post-content" or contains(@class,"articleBody")]/div[@class="meta"]')->length, 'Custom metadata inside the article body was removed.');
            same('must-survive', $xpath->query('//input[@name="csrf_token"]')->item(0)->getAttribute('value'), 'Content gate changed while removing metadata.');
            same(1, fragment($body . $comments)->query('//section[@id="comments"]')->length, 'Comments were lost or duplicated.');
        }
        [$body, $meta] = $transform('<article><h1 class="post-title">独立页</h1><div class="post-content"><article><div class="meta">正文嵌套文章的信息</div><p>正文内容</p></article></div></article>');
        same('', $meta, 'Nested article metadata was mistaken for the page header.');
        check(str_contains($body, '正文嵌套文章的信息'), 'Custom nested article metadata was removed from a page without header metadata.');
    }
};
$tests['neighbor titles render as visible escaped text with legacy label fallback'] = static function (): void {
    $previousTitle = '上一篇的标题 “引号” & <img src=x onerror=alert(1)>';
    $nextTitle = str_repeat('下一篇长标题', 20);
    $html = '<ul class="pagination post-navigation"><li class="page-previous"><a href="/previous" data-post-title="' . h($previousTitle) . '" aria-label="' . h('上一篇：' . $previousTitle) . '">上一篇</a></li><li class="page-next"><a href="/next" aria-label="' . h('下一篇：' . $nextTitle) . '">下一篇</a></li></ul>';
    [$body] = sbvoid_detail($html);
    $xpath = fragment($body);
    $titles = $xpath->query('//a/span[@class="void-post-nav-title"]');
    same(2, $titles->length, 'Both neighbors need real title text, without CSS-generated content.');
    same($previousTitle, $titles->item(0)->textContent, 'Attribute title was corrupted.');
    same($nextTitle, $titles->item(1)->textContent, 'Legacy accessible-label title was lost or truncated.');
    same('上一篇', $xpath->query('//a/span[@class="void-post-nav-label"]')->item(0)->textContent, 'Previous label was lost.');
    same('下一篇', $xpath->query('//a/span[@class="void-post-nav-label"]')->item(1)->textContent, 'Next label was lost.');
    same('/previous', $xpath->query('//a')->item(0)->getAttribute('href'), 'Previous destination changed.');
    same('/next', $xpath->query('//a')->item(1)->getAttribute('href'), 'Next destination changed.');
    same(0, $xpath->query('//img')->length, 'Title text became HTML.');

    [$single] = sbvoid_detail('<ul class="pagination post-navigation"><li></li><li><a href="/next" data-post-title="唯一相邻文章">下一篇</a></li></ul>');
    $singleXpath = fragment($single);
    same(1, $singleXpath->query('//a/span[@class="void-post-nav-title"]')->length, 'Single-sided navigation gained a phantom neighbor.');
    [$none] = sbvoid_detail('<article><div class="post-content">没有相邻文章</div></article>');
    same(0, fragment($none)->query('//ul[contains(@class,"post-navigation")]')->length, 'A page without neighbors gained navigation.');
};
$tests['preview URLs persist only for administrators'] = static function (): void {
    $_GET = ['theme_preview' => 'void'];
    same('/blog/archive/test', sbvoid_url('/blog/archive/test'), 'Preview leaked into public navigation.');
    $GLOBALS['void_test_admin'] = true;
    same('/blog/archive/test?theme_preview=void', sbvoid_url('/blog/archive/test'), 'Preview lost from pretty URL.');
    same('/blog/index.php?a=post&theme_preview=void', sbvoid_url('/blog/index.php?a=post'), 'Preview lost from query URL.');
};
$tests['JSON configuration cannot close its script element'] = static function (): void {
    $value = ['text' => '</script><script>alert("x")</script> & 中文'];
    $json = sbvoid_json($value);
    check(!str_contains(strtolower($json), '</script'), 'JSON permits a script element breakout.');
    same($value, json_decode($json, true, 512, JSON_THROW_ON_ERROR), 'Safe JSON encoding corrupted the value.');
};
$tests['VOID shortcuts create safe ruby, notices and photo groups'] = static function (): void {
    $xpath = fragment('<article class="post"><div class="articleBody"><p>中文 {{汉字:hàn zì}} 与 {{&lt;img src=x onerror=alert(1)&gt;:&lt;script&gt;}}。</p><p>[notice]保留 <strong>加粗</strong> 与 &lt;script&gt; 文本[/notice]</p><p>[notice]</p><p>第一段 {{提示:tí shì}}</p><p>第二段 <a href="/unchanged">原始链接</a> <code>{{code:literal}}</code></p><p>[/notice]</p><p>[photos]</p><p><img src="/a.jpg" alt="A &amp; B"></p><p><a href="/full"><img src="/b.jpg" alt="B"></a></p><p>[/photos]</p><p>尾部段落</p></div></article>');
    $root = $xpath->query('//main')->item(0);
    sbvoid_enhance_body($root);
    same(3, $xpath->query('//ruby')->length, 'Ruby shortcuts were not converted.');
    same('hàn zì', $xpath->query('//ruby/rt')->item(0)->textContent, 'Unicode pronunciation was corrupted.');
    same(6, $xpath->query('//ruby/rp')->length, 'Ruby fallback punctuation is missing.');
    same('<img src=x onerror=alert(1)>', $xpath->query('//ruby')->item(1)->firstChild->textContent, 'Potential HTML must stay literal ruby text.');
    same('<script>', $xpath->query('//ruby/rt')->item(1)->textContent, 'Potential script must stay literal pronunciation text.');
    same(0, $xpath->query('//script')->length, 'Shortcut input became executable HTML.');
    same(1, $xpath->query('//p[contains(@class,"notice")]/strong')->length, 'Inline notice lost existing emphasis.');
    same(1, $xpath->query('//aside[contains(@class,"void-shortcode-notice")]')->length, 'Block notice was not wrapped.');
    same(2, $xpath->query('//aside/p')->length, 'Block notice paragraphs were lost.');
    same('{{code:literal}}', $xpath->query('//aside//code')->item(0)->textContent, 'A block notice must preserve literal inline code.');
    same('/unchanged', $xpath->query('//aside//a')->item(0)->getAttribute('href'), 'Block notice changed an existing URL.');
    same(2, $xpath->query('//div[@class="photos"]/figure')->length, 'Photo paragraphs did not become flex figures.');
    same(2, $xpath->query('//div[@class="photos"]//img')->length, 'A photo was lost.');
    same('A & B', $xpath->query('//div[@class="photos"]//img')->item(0)->getAttribute('alt'), 'Photo alt text changed.');
    same('/full', $xpath->query('//div[@class="photos"]//a')->item(0)->getAttribute('href'), 'Photo link was lost.');
    check(!str_contains($root->textContent, '[photos]') && !str_contains($root->textContent, '[notice]'), 'Consumed markers should disappear.');
    $once = $root->ownerDocument->saveHTML($root);
    sbvoid_enhance_body($root);
    same($once, $root->ownerDocument->saveHTML($root), 'Shortcut enhancement must be idempotent.');
};
$tests['VOID shortcuts preserve code, core gates, comments and unmatched markers'] = static function (): void {
    $literal = '[notice]{{原文:yuán wén}}[/notice]';
    $xpath = fragment('<article class="post"><div class="articleBody"><pre><code>' . $literal . '</code></pre><p><code>' . $literal . '</code></p><script type="application/json">{"text":"{{script:literal}}"}</script><style>/* {{style:literal}} */</style><textarea>' . $literal . '</textarea><aside class="content-gate"><p>' . $literal . '</p><form><input name="csrf_token" value="must-survive"></form></aside><section id="comments"><p>' . $literal . '</p></section><p>[photos]</p><p>普通文字，不能当作图片组</p><p>[/photos]</p><p>[notice]</p><p>没有结束标记</p></div></article>');
    $root = $xpath->query('//main')->item(0);
    sbvoid_enhance_body($root);
    same(0, $xpath->query('//ruby')->length, 'Shortcuts inside protected elements were converted.');
    same($literal, $xpath->query('//pre/code')->item(0)->textContent, 'Code block changed.');
    same($literal, $xpath->query('//p/code')->item(0)->textContent, 'Inline code changed.');
    same($literal, $xpath->query('//textarea')->item(0)->textContent, 'Textarea value changed.');
    same($literal, $xpath->query('//aside/p')->item(0)->textContent, 'Core gate text changed.');
    same('must-survive', $xpath->query('//aside//input')->item(0)->getAttribute('value'), 'Core gate CSRF changed.');
    same($literal, $xpath->query('//section/p')->item(0)->textContent, 'Comments changed.');
    same(0, $xpath->query('//div[@class="photos"]')->length, 'Non-image content must not become a photo group.');
    same(0, $xpath->query('//*[contains(@class,"void-shortcode-notice")]')->length, 'Unclosed notice must remain literal.');
    check(str_contains($root->textContent, '[photos]') && str_contains($root->textContent, '[notice]'), 'Unmatched/invalid markers were silently removed.');
};

$failures = 0;
foreach ($tests as $label => $test) {
    reset_fixture();
    try {
        $test();
        fwrite(STDOUT, "PASS $label\n");
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL $label\n" . $error->getMessage() . "\n");
    }
}
fwrite(STDOUT, count($tests) . ' checks, ' . $failures . " failures.\n");
exit($failures === 0 ? 0 : 1);
