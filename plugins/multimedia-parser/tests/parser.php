<?php

declare(strict_types=1);

// Run: php plugins/multimedia-parser/tests/parser.php [--core path/to/sblog/index.php]. No upstream requests are made.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

define('PLUGINS_DIR', dirname(__DIR__, 2));
$filters = [];
$themes = [];
$catalogs = [];
$locale = 'zh-CN';
$multimediaEnabled = true;
$checks = 0;
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function sblog_i18n_register(string $locale, array $messages): void { $GLOBALS['catalogs'][$locale] = array_replace($GLOBALS['catalogs'][$locale] ?? [], $messages); }
function sblog_t(string $message, array $parameters = []): string
{
    $message = $GLOBALS['catalogs'][$GLOBALS['locale']][$message] ?? $message;
    foreach ($parameters as $key => $value) { $message = str_replace('{' . $key . '}', (string)$value, $message); }
    return $message;
}
function plugin_asset_url(string $slug, string $file): string { return '/blog/plugins/' . $slug . '/' . $file; }
function add_plugin_filter(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['filters'][$hook][] = $callback; }
function add_theme_action(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['themes'][$hook][] = $callback; }
function plugin_filter(string $hook, mixed $value, array $context = []): mixed
{
    if (!$GLOBALS['multimediaEnabled']) { return $value; }
    foreach ($GLOBALS['filters'][$hook] ?? [] as $callback) { $value = $callback($value, $context); }
    return $value;
}
function theme_filter(string $hook, mixed $value, array $context = []): mixed { return $value; }
function expect(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) { throw new RuntimeException($message); }
}

function multimedia_test_load_core(string $path): void
{
    $names = array_fill_keys([
        'str_lower_u', 'valid_http_url', 'safe_link_url', 'split_bare_url_suffix', 'render_inline',
        'markdown_fence_line', 'parse_reply_hidden_blocks', 'strip_reply_hidden_blocks',
        'media_url_from_paragraph', 'media_embed_html', 'markdown_table_cells', 'markdown_table_alignments',
        'markdown_safe_url', 'markdown_sanitize_html', 'markdown_to_html',
    ], true);
    $loaded = [];
    $tokens = token_get_all((string)file_get_contents($path));
    for ($i = 0, $count = count($tokens); $i < $count; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
        $name = $tokens[$i + 2][1] ?? '';
        if (!isset($names[$name])) { continue; }
        $code = '';
        $depth = 0;
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            $code .= is_array($token) ? ($token[0] === T_DIR ? var_export(dirname($path), true) : $token[1]) : $token;
            if ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) { $depth++; }
            elseif ($token === '}' && --$depth === 0) { break; }
        }
        eval($code);
        $loaded[$name] = true;
    }
    expect(isset($loaded['markdown_to_html'], $loaded['media_embed_html']), 'Core integration must exercise the actual Markdown and media entry points.');
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {
    require dirname(__DIR__) . '/plugin.php';
    $embed = $filters['media_embed_html'][0];
    $assets = $filters['output_html'][0];
    $fixtures = [
        ['https://www.bilibili.com/video/BV1xx411c7mD', 'player.bilibili.com/player.html?bvid=BV1xx411c7mD', 'video'],
        ['https://www.bilibili.com/video/av170001?p=2', 'aid=170001&amp;page=2', 'video'],
        ['https://m.bilibili.com/video/BV1xx411c7mD?p=3', 'bvid=BV1xx411c7mD&amp;page=3', 'video'],
        ['https://www.youtube.com/watch?v=dQw4w9WgXc', 'youtube-nocookie.com/embed/dQw4w9WgXc', 'video'],
        ['https://youtu.be/dQw4w9WgXc?t=20', 'youtube-nocookie.com/embed/dQw4w9WgXc', 'video'],
        ['https://www.youtube.com/embed/dQw4w9WgXc', 'youtube-nocookie.com/embed/dQw4w9WgXc', 'video'],
        ['https://www.youtube.com/shorts/dQw4w9WgXc', 'youtube-nocookie.com/embed/dQw4w9WgXc', 'video'],
        ['https://www.youtube.com/live/dQw4w9WgXc', 'youtube-nocookie.com/embed/dQw4w9WgXc', 'video'],
        ['https://music.163.com/song?id=347230', 'outchain/player?type=2&amp;id=347230', 'audio'],
        ['https://music.163.com/playlist?id=123456', 'outchain/player?type=0&amp;id=123456', 'audio'],
        ['https://music.163.com/album?id=123456', 'outchain/player?type=1&amp;id=123456', 'audio'],
        ['https://music.163.com/#/song?id=347230', 'outchain/player?type=2&amp;id=347230', 'audio'],
        ['https://music.163.com/#/playlist?id=123456', 'outchain/player?type=0&amp;id=123456', 'audio'],
        ['https://music.163.com/#/album?id=123456', 'outchain/player?type=1&amp;id=123456', 'audio'],
    ];
    foreach ($fixtures as [$url, $source, $kind]) {
        $html = $embed(null, ['url' => $url]);
        expect(is_string($html) && str_contains($html, '<iframe '), 'Supported URL must render a player: ' . $url);
        expect(str_contains($html, $source), 'Player must target the expected provider and identity: ' . $url);
        expect(str_contains($html, 'media-embed--' . $kind), 'Player must retain its media layout: ' . $url);
        expect(str_contains($html, 'data-multimedia-parser'), 'Generated players must carry the plugin asset marker.');
        expect(str_contains($html, 'loading="lazy"') && str_contains($html, 'referrerpolicy="strict-origin-when-cross-origin"'), 'Players must preserve browser loading and referrer settings.');
        if ($kind === 'video') { expect(str_contains($html, 'allowfullscreen'), 'Video must retain fullscreen support.'); }
        if (str_contains($url, '/song') || str_contains($url, '#/song')) {
            expect(str_contains($html, '--media-height:86px'), 'Songs must retain the compact player height.');
        } elseif ($kind === 'audio') {
            expect(str_contains($html, '--media-height:450px'), 'Playlists and albums must retain their full player height.');
        }
    }

    foreach ([
        'https://example.com/watch?v=dQw4w9WgXc',
        'https://www.youtube.com.evil.example/watch?v=dQw4w9WgXc',
        'https://www.bilibili.com.evil.example/video/BV1xx411c7mD',
        'https://music.163.com.evil.example/song?id=347230',
        'https://music.163.com/song?id=not-a-number',
        'https://music.163.com/song?id[]=347230',
        'https://music.163.com/#/playlist?id[]=123456',
        'https://www.youtube.com/watch?v[]=dQw4w9WgXc',
        'https://www.youtube.com/watch?v=bad%22%3E%3Cscript%3E',
        'https://youtu.be/bad',
        'https://www.bilibili.com/video/BV1xx411c7mD?p[]=2',
        'https://www.bilibili.com/not-a-video',
        'https://movie.douban.com/subject/1292052/',
        'javascript:alert(1)',
        'ftp://music.163.com/song?id=347230',
        '/video/BV1xx411c7mD',
        '',
    ] as $url) {
        expect($embed(null, ['url' => $url]) === null, 'Unsupported or malformed URLs must leave normal-link handling available: ' . $url);
    }
    expect($embed(null, ['url' => []]) === null && $embed(null, []) === null, 'Malformed hook context must not produce warnings or players.');
    foreach (['<aside>another provider</aside>', '', false, ['handled' => true]] as $previous) {
        expect($embed($previous, ['url' => $fixtures[0][0]]) === $previous, 'Existing filter results must survive unchanged, including suppression with an empty string.');
    }

    $hint = $filters['editor_content_hint'][0];
    $hintKey = '支持 Markdown；将网易云音乐、哔哩哔哩或 YouTube 链接单独放在一段可自动解析。';
    expect($hint('支持 Markdown。', []) === $hintKey, 'Enabled parsing must describe both music and video support in the editor.');
    foreach (['en', 'ru'] as $language) {
        expect(isset($catalogs[$language][$hintKey]), 'The plugin must supply its own translated editor hint.');
        foreach (['网易云音乐', '哔哩哔哩视频', 'YouTube 视频', 'plugin.multimedia-parser.name', 'plugin.multimedia-parser.description'] as $key) {
            expect(isset($catalogs[$language][$key]), 'The plugin must own translated provider labels and metadata: ' . $key);
        }
        $locale = $language;
        expect($hint('Markdown', []) === $catalogs[$language][$hintKey], 'Editor hints must use the active language supplied by the plugin.');
        $translated = $embed(null, ['url' => $fixtures[3][0]]);
        expect(str_contains($translated, 'title="' . h($catalogs[$language]['YouTube 视频']) . '"'), 'Iframe titles must use plugin-owned provider translations.');
    }
    $locale = 'zh-CN';

    $player = $embed(null, ['url' => $fixtures[3][0]]);
    $document = '<!doctype html><html><head><title>Media</title></head><body>' . $player . '</body></html>';
    $head = $themes['head'][0];
    $headStyle = $head(['mode' => 'public']);
    expect(str_contains($headStyle, '/blog/plugins/multimedia-parser/assets/style.css?v=1.0.0') && str_contains($headStyle, 'data-multimedia-parser-style'), 'Public theme heads must preload media CSS before any player appears, including AJAX pagination entry pages.');
    expect($head([]) === $headStyle, 'Theme heads without an explicit mode must default to public styling.');
    foreach (['admin', 'login', 'install'] as $mode) {
        expect($head(['mode' => $mode]) === '', 'Non-public theme heads must not load player CSS.');
    }
    $withThemeStyle = str_replace('</head>', $headStyle . '</head>', $document);
    expect($assets($withThemeStyle, ['action' => 'post']) === $withThemeStyle, 'Output fallback must preserve styles already emitted by the theme head without duplication.');
    $styled = $assets($document, ['action' => 'post', 'content_type' => 'Content-Type: text/html; charset=utf-8']);
    expect(str_contains($styled, '/blog/plugins/multimedia-parser/assets/style.css?v=1.0.0'), 'Rendered player pages must load plugin-owned CSS and preserve deployment prefixes.');
    expect(substr_count($styled, 'data-multimedia-parser-style') === 1, 'Styles must be injected once.');
    expect($assets($styled, ['action' => 'post']) === $styled, 'Repeated output filtering must not duplicate styles.');
    expect(str_contains($assets($document, []), 'data-multimedia-parser-style'), 'Themes without explicit content-type headers must still receive player CSS.');
    $plain = '<html><head></head><body><p><a href="' . h($fixtures[3][0]) . '">Video link</a></p></body></html>';
    expect($assets($plain, ['action' => 'post']) === $plain, 'Output fallback must leave documents without generated players unchanged.');
    expect($assets($document, ['content_type' => 'Content-Type: application/json']) === $document, 'Non-HTML responses must remain untouched.');
    expect($assets($player, ['content_type' => 'text/html']) === $player, 'HTML fragments without a head must remain intact.');

    if (($argv[1] ?? '') === '--core') {
        multimedia_test_load_core($argv[2] ?? '');
        foreach ($fixtures as [$url]) {
            $multimediaEnabled = false;
            $ordinary = markdown_to_html($url);
            expect(!str_contains($ordinary, '<iframe') && str_contains($ordinary, '<a href="' . h($url) . '"'), 'Disabled parsing must leave a clickable link through the real core renderer.');
            $multimediaEnabled = true;
            expect(str_contains(markdown_to_html($url), 'data-multimedia-parser'), 'Enabled parsing must render players through the actual core hook.');
        }
        $videoUrl = $fixtures[3][0];
        foreach (['[' . '播放' . '](' . $videoUrl . ')', '<' . $videoUrl . '>'] as $markdown) {
            expect(str_contains(markdown_to_html($markdown), '<iframe'), 'Standalone Markdown links and autolinks must retain player support.');
        }
        foreach (['说明 ' . $videoUrl, '`' . $videoUrl . '`', "```\n" . $videoUrl . "\n```"] as $markdown) {
            expect(!str_contains(markdown_to_html($markdown), '<iframe'), 'Inline links and code examples must not become players.');
        }
        $hidden = markdown_to_html("[reply]\n" . $videoUrl . "\n[/reply]");
        expect(!str_contains($hidden, '<iframe') && !str_contains($hidden, $videoUrl), 'Reply-protected content must not leak through media embeds.');
        fwrite(STDOUT, "PASS: Real core integration covers enabled and disabled parsing, Markdown links, code, and hidden content.\n");
    }

    fwrite(STDOUT, 'PASS: ' . $checks . " multimedia parsing and asset checks. No network requests.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
} finally {
    restore_error_handler();
}
