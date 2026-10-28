<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

function kratos_config(): array
{
    static $config;
    return $config ??= require __DIR__ . '/config.php';
}

function kratos_asset(string $path): string
{
    return theme_asset_url($path) . '?v=' . rawurlencode((string)filemtime(__DIR__ . '/' . $path));
}

function kratos_safe_url(string $url): string
{
    $url = trim($url);
    return valid_http_url($url) || (str_starts_with($url, '/') && !str_starts_with($url, '//')) ? $url : '';
}

function kratos_image_url(string $url): string
{
    $url = trim($url);
    if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) { return ''; }
    if ($safe = kratos_safe_url($url)) { return $safe; }
    if (preg_match('#^[a-z0-9_./%-]+(?:\?[^\s]*)?$#i', $url) && !str_contains($url, '..')) { return asset_url($url); }
    return '';
}

/** Preserve an authorized preview across navigation, without forwarding it externally. */
function kratos_url(string $url): string
{
    if (($_GET['theme_preview'] ?? '') !== 'kratos' || !is_admin() || $url === '' || $url[0] === '#') { return $url; }
    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || str_starts_with($url, '//')) { return $url; }
    $path = (string)($parts['path'] ?? '');
    parse_str((string)($parts['query'] ?? ''), $query);
    if (isset($query['theme_preview']) || in_array($query['a'] ?? '', ['rss', 'sitemap'], true)
        || (preg_match('/\.[a-z0-9]+$/i', $path) && basename($path) !== 'index.php')) { return $url; }
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    if ($fragment !== '') { $url = substr($url, 0, -strlen($fragment)); }
    return url_with_query($url, ['theme_preview' => 'kratos']) . $fragment;
}

function kratos_preview_links(string $html): string
{
    if (($_GET['theme_preview'] ?? '') !== 'kratos' || !is_admin()) { return $html; }
    return preg_replace_callback('/\bhref="([^"]*)"/', static fn(array $m): string => 'href="' . h(kratos_url(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) . '"', $html) ?? $html;
}

function kratos_author(array $post = []): array
{
    static $authors = [];
    $id = (int)($post['author_id'] ?? 0);
    if (!isset($authors[$id])) {
        $author = $id > 0 ? one('SELECT * FROM users WHERE id = ?', [$id]) : one('SELECT * FROM users ORDER BY id LIMIT 1');
        // Return only public profile fields; credentials never enter the template context.
        $author = array_intersect_key($author ?? [], array_flip(['id', 'username', 'nickname', 'avatar_url', 'website_url', 'signature', 'github_url', 'qq_url', 'wechat_url', 'weibo_url', 'x_url', 'telegram_url', 'mastodon_url', 'bilibili_url', 'instagram_url', 'tiktok_url']));
        $author['name'] = trim((string)($author['nickname'] ?? '')) ?: (string)($author['username'] ?? 'Admin');
        $author['avatar_url'] = kratos_image_url((string)($author['avatar_url'] ?? '')) ?: kratos_asset('assets/img/gravatar.png');
        $author['signature'] = trim((string)($author['signature'] ?? '')) ?: '这个人很懒，什么都没留下';
        $authors[$id] = $author;
    }
    return $authors[$id];
}

/** Search sanitized public content so protected text cannot affect results. */
function kratos_search_posts(string $term, int $limit, int $offset): array
{
    $term = str_sub_u(trim($term), 0, 100);
    $posts = [];
    foreach (all_rows('SELECT * FROM posts WHERE kind = ? AND status = ? AND published_at > 0 AND published_at <= ? ORDER BY is_pinned DESC, published_at DESC, id DESC', ['post', 'published', time()]) as $row) {
        $post = public_content_context($row);
        $text = $post['title'] . ' ' . $post['excerpt'] . ' ' . strip_tags((string)$post['content']);
        $match = function_exists('mb_stripos') ? mb_stripos($text, $term, 0, 'UTF-8') : stripos($text, $term);
        if ($match !== false) { $posts[] = $post; }
    }
    return ['posts' => array_slice($posts, max(0, $offset), max(1, $limit)), 'total' => count($posts)];
}

function kratos_navigation(array $navPages, string $active): array
{
    $links = [];
    foreach (kratos_config()['navigation'] as $link) {
        $url = kratos_safe_url((string)($link['url'] ?? ''));
        if ($url !== '' && trim((string)($link['name'] ?? '')) !== '') {
            $links[] = ['name' => $link['name'], 'url' => kratos_url($url), 'active' => false];
        }
    }
    if ($links) { return $links; }
    foreach (['home' => '首页', 'archives' => '归档', 'categories' => '分类', 'tags' => '标签', 'links' => '链接'] as $route => $label) {
        $links[] = ['name' => sblog_t($label), 'url' => kratos_url(url_for($route)), 'active' => $active === $route];
    }
    foreach ($navPages as $page) {
        $links[] = ['name' => $page['title'], 'url' => kratos_url(content_permalink($page)), 'active' => $active === 'page:' . $page['slug']];
    }
    return $links;
}

function kratos_socials(): array
{
    $links = kratos_config()['socials'];
    if (!$links) {
        $author = kratos_author();
        foreach (['github' => 'GitHub', 'qq' => 'QQ', 'weibo' => '微博', 'x' => 'X', 'telegram' => 'Telegram', 'bilibili' => '哔哩哔哩', 'instagram' => 'Instagram'] as $key => $label) {
            if (!empty($author[$key . '_url'])) { $links[] = ['icon' => $key === 'x' ? 'twitter' : $key, 'name' => $label, 'url' => $author[$key . '_url']]; }
        }
    }
    $links = array_values(array_filter($links, static fn(array $link): bool => kratos_safe_url((string)($link['url'] ?? '')) !== '' && preg_match('/^[a-z0-9-]+$/', (string)($link['icon'] ?? ''))));
    $icons = ['douban', 'wechat', 'telegram', 'twitter', 'youtube', 'stackflow', 'sina', 'github', 'coding', 'bilibili', 'linkedin', 'email', 'gitee', 'url'];
    foreach ($links as &$link) {
        if ($link['icon'] === 'weibo') { $link['icon'] = 'sina'; }
        if (!in_array($link['icon'], $icons, true)) { $link['icon'] = 'url'; }
    }
    unset($link);
    return $links;
}

function kratos_search_form(string $id, bool $widget = false): string
{
    ob_start(); ?>
    <form role="search" method="get" class="<?= $widget ? 'searchform' : 'search-form' ?>" action="<?= h(url_for('home')) ?>">
      <?php // Keep action parameters on installations with query-string URLs. ?>
      <?php parse_str((string)(parse_url(url_for('home'), PHP_URL_QUERY) ?? ''), $query); foreach ($query as $key => $value): ?>
      <input type="hidden" name="<?= h($key) ?>" value="<?= h($value) ?>">
      <?php endforeach; ?>
      <?php if (($_GET['theme_preview'] ?? '') === 'kratos' && is_admin()): ?><input type="hidden" name="theme_preview" value="kratos"><?php endif; ?>
      <?php if ($widget): ?><div class="input-group mt-2 mb-2"><?php endif; ?>
      <label class="sr-only" for="<?= h($id) ?>">搜索文章</label>
      <input type="search" name="s" id="<?= h($id) ?>"<?= $widget ? ' class="form-control"' : '' ?> placeholder="搜点什么呢?" value="<?= h(is_string($_GET['s'] ?? null) ? str_sub_u($_GET['s'], 0, 100) : '') ?>" maxlength="100" required<?= $widget ? '' : ' style="display:none"' ?>>
      <?php if ($widget): ?><div class="input-group-append"><button class="btn btn-primary btn-search" type="submit">搜索</button></div></div><?php endif; ?>
    </form>
    <?php return (string)ob_get_clean();
}

require_once __DIR__ . '/content.php';
require_once __DIR__ . '/sidebar.php';

add_theme_filter('comments_labels', static function (array $labels): array {
    return array_replace($labels, ['form_title' => '发表评论', 'submit' => '提交评论']);
});
