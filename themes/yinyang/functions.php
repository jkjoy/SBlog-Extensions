<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

function yinyang_config(): array
{
    static $config;
    return $config ??= require __DIR__ . '/config.php';
}

function yinyang_asset(string $path): string
{
    return theme_asset_url($path) . '?v=' . rawurlencode((string)filemtime(__DIR__ . '/' . $path));
}

function yinyang_url(string $url): string
{
    if ($url === '' || str_starts_with($url, '#')) { return $url; }
    if (($_GET['theme_preview'] ?? '') !== 'yinyang' || !is_admin()) { return $url; }
    $parts = parse_url($url);
    // Never pass preview parameters to external links, downloads or XML endpoints.
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || str_starts_with($url, '//')) { return $url; }
    $path = (string)($parts['path'] ?? '');
    parse_str((string)($parts['query'] ?? ''), $query);
    if (in_array($query['a'] ?? '', ['rss', 'sitemap'], true)
        || (preg_match('/\.[a-z0-9]+$/i', $path) && basename($path) !== 'index.php')) { return $url; }
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    $url = $fragment !== '' ? substr($url, 0, -strlen($fragment)) : $url;
    return $url . (str_contains($url, '?') ? '&' : '?') . 'theme_preview=yinyang' . $fragment;
}

function yinyang_safe_url(string $url): string
{
    $url = trim($url);
    return valid_http_url($url) || (str_starts_with($url, '/') && !str_starts_with($url, '//')) ? $url : '';
}

/** The same year/date/title/category grid as Hugo YinYang's index.html. */
function yinyang_post_list(array $posts, bool $categories = true): string
{
    $groups = [];
    foreach ($posts as $post) {
        $groups[date('Y', (int)$post['published_at'])][] = $post;
    }
    // Pinned posts may come from an earlier year; keep each year together.
    krsort($groups, SORT_NUMERIC);
    $categoryMap = [];
    if ($categories) {
        foreach (all_rows('SELECT id, name, slug FROM categories') as $category) {
            $categoryMap[(int)$category['id']] = $category;
        }
    }
    ob_start(); ?>
    <div id="posts-list">
      <?php foreach ($groups as $year => $items): ?>
      <section>
        <h2 class="site-date-catalog"><?= h($year) ?></h2>
        <?php foreach ($items as $post):
            $timestamp = (int)$post['published_at'];
            $category = $categoryMap[(int)($post['category_id'] ?? 0)] ?? null;
        ?>
        <div class="row posts-line">
          <div class="posts-date <?= $categories ? 'col-xs-3 col-sm-2' : 'col-xs-2' ?>">
            <time datetime="<?= h(date(DATE_ATOM, $timestamp)) ?>"><?= h(date('M d', $timestamp)) ?></time>
          </div>
          <div class="posts-title <?= $categories ? 'col-xs-9 col-sm-10' : 'col-xs-10' ?>">
            <div class="row">
              <div class="<?= $categories ? 'col-xs-11 col-sm-10' : 'col-xs-12' ?>">
                <a href="<?= h(yinyang_url(content_permalink($post))) ?>"><?= h($post['title']) ?></a>
              </div>
              <?php if ($categories): ?>
              <div class="col-xs-1 col-sm-2 posts-categories">
                <?php if ($category): ?><div class="posts-category"><a href="<?= h(yinyang_url(url_for('category', ['slug' => $category['slug']]))) ?>"><strong><?= h($category['name']) ?></strong></a></div><?php endif; ?>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </section>
      <?php endforeach; ?>
      <?php if (!$groups): ?><div class="empty-notice"><p><?= h(sblog_t('还没有已发布的文章。')) ?></p></div><?php endif; ?>
    </div>
    <?php return (string)ob_get_clean();
}

function yinyang_pagination(int $page, int $totalPages): string
{
    if ($totalPages <= 1) { return ''; }
    ob_start(); ?>
    <nav aria-label="<?= h(sblog_t('文章分页')) ?>">
      <ul class="pagination yinyang-pagination">
        <li class="page-previous"><?php if ($page > 1): ?><a rel="prev" href="<?= h(yinyang_url(home_page_url($page - 1))) ?>">← <?= h(sblog_t('上一页')) ?></a><?php endif; ?></li>
        <li class="page-count"><?= $page ?> / <?= $totalPages ?></li>
        <li class="page-next"><?php if ($page < $totalPages): ?><a rel="next" href="<?= h(yinyang_url(home_page_url($page + 1))) ?>"><?= h(sblog_t('下一页')) ?> →</a><?php endif; ?></li>
      </ul>
    </nav>
    <?php return (string)ob_get_clean();
}

/** Restyle only the core's opening heading/metadata; keep gated body and comments intact. */
function yinyang_detail(string $content, string $action): string
{
    $post = fetch_content_by_identifier($action, trim((string)($_GET['slug'] ?? $_GET['id'] ?? '')), is_admin());
    if (!$post) { return $content; }
    $config = yinyang_config();
    $description = '';
    if ($action === 'post') {
        $author = one('SELECT username, nickname FROM users WHERE id = ?', [(int)$post['author_id']]) ?? [];
        $authorName = trim((string)$config['author_name']) ?: (trim((string)($author['nickname'] ?? '')) ?: (string)($author['username'] ?? 'Admin'));
        $authorUrl = yinyang_safe_url((string)$config['author_homepage']);
        $timestamp = (int)($post['published_at'] ?: $post['updated_at'] ?: $post['created_at']);
        $authorHtml = $authorUrl !== ''
            ? '<a href="' . h($authorUrl) . '" target="_blank" rel="noopener noreferrer">@' . h($authorName) . '</a>'
            : '@' . h($authorName);
        $description = '<div class="row post-desc"><div class="col-xs-6"><time class="post-date" datetime="' . h(date(DATE_ATOM, $timestamp)) . '">' . h(date('d M Y', $timestamp)) . '</time></div><div class="col-xs-6"><div class="post-author">' . $authorHtml . '</div></div></div>';
    }
    if (!is_live_content($post) && is_admin()) {
        $state = post_state($post);
        $description .= '<p class="meta">' . h(sblog_t('{state}预览', ['state' => (string)$state['label']])) . '</p>';
    }
    // This pattern is anchored to core markup, never to headings inside the article body.
    $content = preg_replace_callback(
        '/\A(\s*<article\b[^>]*>)\s*(<h1\b[^>]*class="post-title"[^>]*>.*?<\/h1>)\s*(?:<div class="meta">.*?<\/div>\s*)?/s',
        static fn(array $match): string => $match[1] . '<header class="post-header">' . $match[2] . $description . '</header>',
        $content,
        1
    ) ?? $content;
    $content = preg_replace('/class="post-content"/', 'class="post-content markdown-body"', $content, 1) ?? $content;
    $content = preg_replace_callback('/<div class="post-tags">\s*<nav class="nav tags">(.*?)<\/nav>\s*<\/div>/s', static function (array $match): string {
        return str_replace('class="post-tag"', 'class="post-tags"', $match[1]);
    }, $content, 1) ?? $content;
    return $content;
}

function yinyang_content(string $content, string $action, int $status): string
{
    if ($status >= 400) { return $content; }
    if ($action === 'home') {
        $page = max(1, (int)($_GET['p'] ?? 1));
        $perPage = max(1, (int)setting('posts_per_page', '6'));
        return yinyang_post_list(fetch_published_posts($perPage, ($page - 1) * $perPage))
            . yinyang_pagination($page, max(1, (int)ceil(count_published_posts() / $perPage)));
    }
    if ($action === 'archives') { return yinyang_post_list(fetch_archive_posts(), false); }
    if (in_array($action, ['post', 'page'], true)) { return yinyang_detail($content, $action); }
    if (in_array($action, ['tag', 'category'], true)) {
        $slug = trim((string)($_GET['slug'] ?? ''));
        if ($action === 'tag') { $posts = fetch_posts_by_tag_slug($slug); }
        else {
            $posts = all_rows('SELECT p.id, p.slug, p.title, p.kind, p.published_at FROM posts p JOIN categories c ON c.id = p.category_id WHERE c.slug = ? AND p.kind = ? AND p.status = ? AND p.published_at > 0 AND p.published_at <= ? ORDER BY p.is_pinned DESC, p.published_at DESC, p.id DESC', [$slug, 'post', 'published', time()]);
        }
        // Keep the core's escaped taxonomy heading.
        preg_match('/<h1\b[^>]*class="post-title"[^>]*>(.*?)<\/h1>/s', $content, $heading);
        return '<h1 class="taxonomy-term">' . ($heading[1] ?? '') . '</h1>' . yinyang_post_list($posts, false);
    }
    return preg_replace('/class="post-title"/', 'class="taxonomy-term"', $content, 1) ?? $content;
}

/** Keep administrator previews while following links in untouched core HTML. */
function yinyang_preview_links(string $content): string
{
    if (($_GET['theme_preview'] ?? '') !== 'yinyang' || !is_admin()) { return $content; }
    return preg_replace_callback('/\bhref="([^"]*)"/', static function (array $match): string {
        $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
        if ($url === '' || $url[0] === '#' || str_contains($url, 'theme_preview=')
            || preg_match('/\.[a-z0-9]+$/i', $path) && basename($path) !== 'index.php') { return $match[0]; }
        return 'href="' . h(yinyang_url($url)) . '"';
    }, $content) ?? $content;
}
