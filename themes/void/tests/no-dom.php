<?php

declare(strict_types=1);

// Run: php -n themes/void/tests/no-dom.php
// Exercise the real compatibility helper without booting index.php or opening a database.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
error_reporting(E_ALL);
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('APP_VERSION', 'void-no-dom-test');

// Registration is the only core runtime boundary reached when loading the theme.
function add_theme_filter(string $hook, callable $callback, int $priority = 10): void {}

function check(bool $value, string $message): void
{
    if (!$value) { throw new RuntimeException($message); }
}

function same(mixed $expected, mixed $actual, string $message): void
{
    check($expected === $actual, $message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
}

require dirname(__DIR__) . '/functions.php';

$tests = [];
$tests['fallback removes only the core heading and complete header metadata'] = static function (): void {
    $plugin = '<article class="plugin-card" id="plugin"><h1 class="post-title">插件标题</h1><div class="meta">插件元信息<div>插件嵌套信息</div></div></article>';
    $bodyMeta = '<div class="meta" id="body-meta">正文元信息<div>正文嵌套信息</div></div>';
    $bodyTitle = '<h1 class="post-title" id="body-title">正文标题</h1>';
    $wrappedMeta = '<header><div class="meta" id="wrapped-meta">非直属元信息</div></header>';
    $afterMeta = '<div class="meta" id="after-meta">正文之后独立元信息</div>';
    $comments = '<section id="comments"><div class="meta" id="comment-meta">评论元信息<div>评论嵌套信息</div></div><form id="comment-form"><input name="csrf_token" value="keep-token"><textarea name="content">保留评论草稿</textarea></form></section>';
    $navigation = '<ul class="pagination post-navigation"><li><a href="/previous" data-post-title="上一篇标题">上一篇</a></li><li><a href="/next" data-post-title="下一篇标题">下一篇</a></li></ul>';
    $html = $plugin . '<article class="post-detail"><h1 class="post-title" id="core-title">REMOVE_CORE_TITLE</h1>' . $wrappedMeta . '<div class="meta" id="core-meta">REMOVE_CORE_META<div>REMOVE_NESTED_META</div><span>REMOVE_META_TAIL</span></div><div class="post-content">' . $bodyTitle . $bodyMeta . '<p>保留正文</p></div>' . $afterMeta . '</article>' . $navigation . $comments;
    [$body, $meta, $movedComments] = sbvoid_detail_fallback($html, ['show_post_navigation' => true]);
    same('', $meta, 'Compatibility rendering should leave banner metadata empty.');
    same('', $movedComments, 'Compatibility rendering must leave comments in the complete response.');
    foreach (['REMOVE_CORE_TITLE', 'REMOVE_CORE_META', 'REMOVE_NESTED_META', 'REMOVE_META_TAIL'] as $removed) {
        check(!str_contains($body, $removed), 'Core header cleanup was incomplete: ' . $removed);
    }
    foreach ([$plugin, $wrappedMeta, $bodyTitle, $bodyMeta, $afterMeta, $navigation, $comments] as $retained) {
        check(str_contains($body, $retained), 'Core header cleanup changed unrelated content: ' . $retained);
    }
    check(str_contains($body, '保留正文'), 'Core header cleanup removed the article body.');
};
$tests['fallback recognizes class tokens quote styles and attribute order without crossing nested divs'] = static function (): void {
    $variants = [
        ['<article class="entry post-detail wide" id="core">', '<h1 id="title" class="heading post-title">', '<div id="meta" class="compact meta extra">', '<div id="content" class="rich post-content">'],
        ["<article id='core' class='wide post-detail entry'>", "<h1 class='post-title heading' id='title'>", "<div class='extra meta compact' id='meta'>", "<div class='post-content rich' id='content'>"],
        ["<article data-kind='post' class=\"post-detail\">", "<h1 data-kind='title' class=\"post-title\" id='title'>", "<div data-kind='header' id='meta' class=\"meta compact\">", "<div data-kind='body' class=\"post-content rich\">"],
        ["<article class='entry\tpost-detail\nwide'>", "<h1 class='heading\tpost-title'>", "<div class='compact\nmeta\textra'>", "<div class='rich\tpost-content'>"],
    ];
    foreach ($variants as $index => [$article, $heading, $metadata, $content]) {
        $bodyMeta = '<div class="meta body-extra" id="keep-body">KEEP_BODY_META<div>KEEP_BODY_NESTED</div></div>';
        $after = '<div class="meta" id="keep-after">KEEP_AFTER_CONTENT</div>';
        $plugin = '<article class="plugin"><h1 class="post-title">KEEP_PLUGIN_TITLE</h1><div class="meta">KEEP_PLUGIN_META</div></article>';
        $comments = "<section id='comments'><div class='meta extra'>KEEP_COMMENT_META<div>KEEP_COMMENT_NESTED</div></div></section>";
        $html = $plugin . $article . $heading . 'REMOVE_TITLE</h1>' . $metadata . 'REMOVE_META<div>REMOVE_META_NESTED<div>REMOVE_META_DEEP</div></div><span>REMOVE_META_AFTER_NESTED</span></div>' . $content . $bodyMeta . '<p>KEEP_BODY_END</p></div>' . $after . '</article>' . $comments;
        [$body, $meta, $movedComments] = sbvoid_detail_fallback($html, ['show_post_navigation' => true]);
        check(!str_contains($body, 'REMOVE_'), 'Header class or attribute variant was not cleaned completely: ' . $index);
        foreach ([$plugin, $bodyMeta, $after, $comments, 'KEEP_BODY_END'] as $retained) {
            check(str_contains($body, $retained), 'Nested cleanup crossed into preserved content in variant ' . $index . ': ' . $retained);
        }
        same(['', ''], [$meta, $movedComments], 'Compatibility return shape changed in variant ' . $index . '.');
    }
};
$tests['fallback preserves similarly named classes and non-core article markup'] = static function (): void {
    $plugin = '<article class="post-detailing" data-class="post-detail"><h1 class="post-title">KEEP_PLUGIN_TITLE</h1><div class="meta">KEEP_PLUGIN_META<div>KEEP_PLUGIN_NESTED</div></div><div class="post-content">KEEP_PLUGIN_BODY</div></article>';
    $before = '<div class="metadata">KEEP_METADATA</div><div class="post-meta">KEEP_POST_META</div><h1 class="post-titleish">KEEP_TITLEISH</h1>';
    $after = '<div class="meta">KEEP_META_AFTER</div>';
    [$body] = sbvoid_detail_fallback($plugin . '<article class="post-detail"><h1 class="post-title">REMOVE_TITLE</h1>' . $before . '<div class="meta">REMOVE_META</div><div class="post-content"><p>KEEP_BODY</p></div>' . $after . '</article>', ['show_post_navigation' => true]);
    check(str_contains($body, 'KEEP_PLUGIN_TITLE') && str_contains($body, 'KEEP_PLUGIN_META') && str_contains($body, 'KEEP_PLUGIN_NESTED') && str_contains($body, 'KEEP_PLUGIN_BODY'), 'A similar plugin class was mistaken for the core article.');
    foreach ([$before, $after, 'KEEP_BODY'] as $retained) { check(str_contains($body, $retained), 'A class substring was mistaken for a core header element.'); }
    check(!str_contains($body, 'REMOVE_'), 'The actual core header was not removed.');
};
$tests['fallback recognizes standalone pages without an article class and preserves plugin and nested headings'] = static function (): void {
    $plugin = '<article class="plugin"><h1 class="post-title">KEEP_PLUGIN_TITLE</h1><div class="meta">KEEP_PLUGIN_META</div><div class="post-content">KEEP_PLUGIN_CONTENT</div></article>';
    $bodyTitle = '<h1 class="post-title" id="page-body-title">KEEP_PAGE_BODY_TITLE</h1>';
    $bodyMeta = '<div class="meta" id="page-body-meta">KEEP_PAGE_BODY_META</div>';
    $comments = '<section id="comments"><h1 class="post-title">KEEP_COMMENT_TITLE</h1><div class="meta">KEEP_COMMENT_META</div><form id="comment-form"><textarea name="content">KEEP_COMMENT_DRAFT</textarea></form></section>';
    $html = $plugin . '<article><h1 class="post-title" id="page-title">REMOVE_PAGE_TITLE</h1><div id="page-content" class="post-content">' . $bodyTitle . $bodyMeta . '<p>KEEP_PAGE_BODY</p></div></article>' . $comments;
    [$body, $meta, $movedComments] = sbvoid_detail_fallback($html, ['show_post_navigation' => true]);
    check(!str_contains($body, 'REMOVE_PAGE_TITLE'), 'The standalone page heading remained duplicated in its body.');
    foreach ([$plugin, $bodyTitle, $bodyMeta, $comments, 'KEEP_PAGE_BODY'] as $retained) {
        check(str_contains($body, $retained), 'Standalone page cleanup changed unrelated markup: ' . $retained);
    }
    check((bool)preg_match('~<div\b[^>]*\bid="page-content"[^>]*\bclass="([^"]*)"[^>]*>~', $body, $match), 'The standalone page content container was removed.');
    $classes = preg_split('/\s+/', trim($match[1])) ?: [];
    foreach (['post-content', 'articleBody', 'full'] as $class) {
        check(in_array($class, $classes, true), 'The standalone page content is missing its typography class: ' . $class);
    }
    same(['', ''], [$meta, $movedComments], 'Standalone page compatibility rendering moved comments out of the body.');
};
$tests['fallback navigation toggle preserves comments forms and unrelated lists'] = static function (): void {
    $navigationVariants = [
        '<ul class="pagination post-navigation"><li><a href="/next" data-post-title="下一篇标题">下一篇</a></li></ul>',
        "<ul id='neighbors' class='post-navigation pagination'><li><a href='/previous' data-post-title='上一篇标题'>上一篇</a></li></ul>",
    ];
    $unrelated = '<ul class="post-navigation-widget"><li>KEEP_WIDGET</li></ul><ul class="pagination"><li>KEEP_PAGER</li></ul>';
    $comments = '<section id="comments"><div class="meta">KEEP_COMMENT_META</div><form id="comment-form"><input type="hidden" name="csrf_token" value="keep-token"><textarea name="content">KEEP_DRAFT</textarea></form></section>';
    foreach ($navigationVariants as $navigation) {
        $html = '<article class="post-detail"><h1 class="post-title">标题</h1><div class="meta">核心元信息</div><div class="post-content">KEEP_BODY</div></article>' . $navigation . $unrelated . $comments;
        [$shown] = sbvoid_detail_fallback($html, ['show_post_navigation' => true]);
        check(str_contains($shown, $navigation), 'Enabled neighbor navigation was changed or removed.');
        [$hidden, $meta, $movedComments] = sbvoid_detail_fallback($html, ['show_post_navigation' => false]);
        check(!str_contains($hidden, $navigation) && !str_contains($hidden, 'data-post-title='), 'Disabled neighbor navigation remained visible.');
        foreach ([$unrelated, $comments, 'KEEP_BODY'] as $retained) {
            check(str_contains($hidden, $retained), 'Disabling navigation removed unrelated content: ' . $retained);
        }
        same(['', ''], [$meta, $movedComments], 'Compatibility navigation toggle moved comments out of the body.');
    }
};

$failures = 0;
foreach ($tests as $label => $test) {
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
