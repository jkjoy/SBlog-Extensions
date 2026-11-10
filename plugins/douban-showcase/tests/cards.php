<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

define('PLUGINS_DIR', dirname(__DIR__, 2));
define('CACHE_DIR', sys_get_temp_dir() . '/sblog-douban-cards-' . bin2hex(random_bytes(8)));
mkdir(CACHE_DIR, 0700);
register_shutdown_function(static function (): void {
    foreach (glob(CACHE_DIR . '/*') ?: [] as $path) { if (is_file($path)) { unlink($path); } }
    rmdir(CACHE_DIR);
});
$database = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$filters = $themes = [];
function db(): PDO { return $GLOBALS['database']; }
function h(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function use_pretty_url(): bool { return false; }
function script_url(): string { return '/blog/index.php'; }
function url_with_query(string $url, array $params): string { return $url . '?' . http_build_query($params); }
function plugin_asset_url(string $slug, string $file): string { return '/blog/plugins/' . $slug . '/' . $file; }
function add_plugin_action(string $hook, callable $callback, int $priority = 10): void {}
function add_plugin_filter(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['filters'][$hook][] = $callback; }
function add_theme_action(string $hook, callable $callback, int $priority = 10): void { $GLOBALS['themes'][$hook][] = $callback; }
require dirname(__DIR__) . '/plugin.php';

$checks = 0;
function expect(bool $condition, string $message): void {
    $GLOBALS['checks']++;
    if (!$condition) { throw new RuntimeException($message); }
}

try {
    $embed = $filters['media_embed_html'][0];
    $compat = $filters['output_html'][1];
    expect(sblog_douban_config()['parse_links'] === true, 'New and existing installations enable link parsing by default.');
    expect(sblog_douban_config()['user_id'] === '', 'Cards must work without configuring a personal account.');
    foreach (['movie', 'book', 'music'] as $type) {
        $card = $embed(null, ['url' => 'https://' . $type . '.douban.com/subject/1292052/?from=search']);
        expect(is_string($card) && str_contains($card, 'data-subject-type="' . $type . '"'), 'All subject types render cards.');
        expect(!str_contains($card, '<img'), 'Uncached links render without upstream requests or invented covers.');
        expect(str_starts_with($card, '<figure class="media-embed media-embed--douban">'), 'The complete card stays in the media wrapper for gallery themes.');
        expect(str_contains($card, 'href="https://' . $type . '.douban.com/subject/1292052/"'), 'The fallback link is canonical.');
        expect(str_contains($card, '/blog/index.php?a=douban_showcase_subject'), 'Metadata endpoint preserves the deployment prefix.');
    }
    foreach (['https://evil.example/subject/1/', 'https://movie.douban.com.evil.example/subject/1/', 'https://movie.douban.com/people/1/'] as $url) {
        expect($embed(null, ['url' => $url]) === null, 'Other links retain their provider handling.');
    }
    expect($embed('<iframe>provider</iframe>', ['url' => 'https://movie.douban.com/subject/1/']) === '<iframe>provider</iframe>', 'Previously handled embeds are retained.');
    expect($themes['head'][1](['mode' => 'admin']) === '' && $themes['body_close'][1](['mode' => 'admin']) === '', 'Cards never inject admin assets.');
    expect(str_contains($themes['head'][1](['mode' => 'public']), 'cards.css'), 'Public pages receive card styles independently of the collection widget.');
    expect(str_contains($themes['body_close'][1](['mode' => 'public']), 'cards.js'), 'Public pages receive hydration.');

    $identity = sblog_douban_subject_identity('https://movie.douban.com/subject/1292052/');
    $subject = $identity + ['title' => '作品 <安全>', 'original_title' => '', 'year' => '1994', 'rating' => '9.7',
        'rating_count' => 42, 'meta' => ['剧情'], 'summary' => '简介',
        'cover_url' => 'https://img3.doubanio.com/view/photo/s_ratio_poster/public/p480747492.jpg'];
    sblog_douban_write_cache(sblog_douban_subject_cache_path('movie', '1292052'), [
        'version' => 1, 'updated_at' => time(), 'checked_at' => time(), 'next_retry_at' => 0, 'data' => $subject,
    ]);
    $cachedCard = $embed(null, ['url' => $identity['url']]);
    expect(str_contains($cachedCard, 'data-subject-cached="1"') && str_contains($cachedCard, 'data-subject-state="ready"'), 'Cached cards are usable immediately, including without JavaScript.');
    expect(str_contains($cachedCard, '<img src="/blog/index.php?') && !str_contains($cachedCard, 'https://img3.doubanio.com/'), 'Cached covers use the registered same-site proxy.');
    expect(str_contains($cachedCard, '>作品 &lt;安全&gt;</span>') && str_contains($cachedCard, 'class="sblog-douban-card__summary">简介</span>'), 'Cached title and summary are escaped and visible in the initial HTML.');
    expect(str_contains($cachedCard, 'class="sblog-douban-card__score">9.7</span>'), 'Cached ratings are visible before hydration.');
    [$status, $payload] = sblog_douban_subject_response(['type' => 'movie', 'id' => '1292052']);
    expect($status === 200 && $payload['ok'] && $payload['subject']['title'] === '作品 <安全>', 'Public API returns cached subject information without a configured user ID.');
    expect(str_starts_with($payload['subject']['cover_url'], '/blog/index.php?') && str_contains($payload['subject']['cover_url'], 'a=douban_showcase_cover'), 'API exposes only a registered same-site cover address.');
    foreach ([['type' => [], 'id' => '1'], ['type' => 'movie', 'id' => '../1'], ['type' => 'unknown', 'id' => '1'], ['type' => 'movie', 'id' => []]] as $input) {
        expect(sblog_douban_subject_response($input)[0] === 400, 'Invalid API parameters never reach an upstream URL.');
    }
    sblog_douban_write_cache(sblog_douban_subject_cache_path('book', '1'), [
        'version' => 1, 'updated_at' => 0, 'checked_at' => time(), 'next_retry_at' => time() + 300, 'data' => [],
    ]);
    expect(sblog_douban_subject_response(['type' => 'book', 'id' => '1'])[0] === 502, 'Failed metadata stays a clear failure and observes retry cooldown.');

    unset($GLOBALS['sblog_douban_embed_hook_used']);
    $old = '<aside class="media-link-card media-link-card--douban"><span>旧卡片</span><a href="https://movie.douban.com/subject/1292052/">查看</a></aside>';
    expect(str_contains($compat($old, ['action' => 'post']), 'data-douban-subject'), 'Old core cards receive the new presentation.');
    $plain = '<p><a href="https://movie.douban.com/subject/1292052/">文字链接</a></p>';
    $code = '<pre><code>' . h($old) . '</code></pre>';
    $comment = '<div class="comment-content">' . $plain . '</div>';
    expect($compat($plain . $code . $comment, ['action' => 'post']) === $plain . $code . $comment, 'Compatibility handling preserves normal links, code and comments.');
    expect($compat($old, ['action' => 'admin_edit']) === $old, 'Admin HTML is never transformed.');
    sblog_douban_save_config(array_merge(sblog_douban_config(), ['parse_links' => false]));
    expect($embed(null, ['url' => $identity['url']]) === '', 'Disabling cards retains ordinary standalone links without core network requests.');
    expect(sblog_douban_subject_response(['type' => 'movie', 'id' => '1292052'])[0] === 404, 'Disabled parsing disables its public metadata API.');
    expect($themes['head'][1](['mode' => 'public']) === '' && $themes['body_close'][1](['mode' => 'public']) === '', 'Disabled parsing loads no card assets.');
    unset($GLOBALS['sblog_douban_embed_hook_used']);
    $fallback = $compat($old, ['action' => 'post']);
    expect(!str_contains($fallback, 'data-douban-subject') && str_contains($fallback, h($identity['url'])), 'Older cores also retain a usable link when disabled.');
    fwrite(STDOUT, "PASS: {$checks} card integration checks. No requests sent to Douban.\n");
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL: ' . $error->getMessage() . "\n");
    exit(1);
}
