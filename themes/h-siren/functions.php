<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

function hsiren_option(string $key, string $fallback = ''): string
{
    return theme_setting($key, $fallback, 'h-siren');
}

function hsiren_asset(string $path): string
{
    $file = __DIR__ . '/' . $path;
    return theme_asset_url($path) . (is_file($file) ? '?v=' . rawurlencode((string)filemtime($file)) : '');
}

function hsiren_safe_url(string $url): string
{
    $url = trim($url);
    return $url !== '' && safe_link_url($url) !== '#' ? $url : '';
}

/** Only local public navigation should carry an administrator's theme preview. */
function hsiren_url(string $url): string
{
    if (($_GET['theme_preview'] ?? '') !== 'h-siren' || !is_admin() || $url === '' || $url[0] === '#') { return $url; }
    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || str_starts_with($url, '//')) { return $url; }
    parse_str((string)($parts['query'] ?? ''), $query);
    $action = (string)($query['a'] ?? '');
    $path = (string)($parts['path'] ?? '');
    if (isset($query['theme_preview']) || in_array($action, ['rss', 'sitemap', 'login', 'logout', 'write', 'edit'], true)
        || str_starts_with($action, 'admin') || str_starts_with($action, 'download')
        || preg_match('#/(?:admin|login|logout|write|edit)(?:/|$)#', $path)
        || (preg_match('/\.[a-z0-9]+$/i', $path) && basename($path) !== 'index.php')) { return $url; }
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    if ($fragment !== '') { $url = substr($url, 0, -strlen($fragment)); }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'theme_preview=h-siren' . $fragment;
}

function hsiren_preview_links(string $html): string
{
    if (($_GET['theme_preview'] ?? '') !== 'h-siren' || !is_admin()) { return $html; }
    return preg_replace_callback('/\bhref="([^"]*)"/', static function (array $match): string {
        return 'href="' . h(hsiren_url(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) . '"';
    }, $html) ?? $html;
}

function hsiren_fallback_cover(int $seed = 0): string
{
    $number = hsiren_option('random_covers', '1') === '1' ? (abs($seed) % 5) + 1 : 1;
    return hsiren_asset('assets/images/cover-' . $number . '.jpg');
}

/** Use only the public portion of content, even for administrator previews. */
function hsiren_post_cover(array $post): string
{
    $post = public_content_context($post);
    $markdown = (string)($post['content'] ?? '');
    if (preg_match('/!\[[^\]\r\n]*\]\(\s*(?:<([^>\r\n]+)>|([^\s)]+))(?:\s+["\'][^\r\n]*["\'])?\s*\)/u', $markdown, $match)) {
        $url = html_entity_decode((string)(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // The editor also accepts relative media paths. Resolve them against the application root.
        if ($url !== '' && !preg_match('#^(?:[a-z][a-z0-9+.-]*:|/|\#)#i', $url)
            && !str_contains($url, '\\') && !preg_match('/[\x00-\x20\x7F]/', $url)) {
            $url = asset_url($url);
        }
        $safe = hsiren_safe_url($url);
        if ($safe !== '' && !str_starts_with($safe, '#')) { return $safe; }
    }
    return hsiren_fallback_cover((int)($post['id'] ?? 0));
}

function hsiren_search(): string
{
    return hsiren_option('show_search', '1') === '1' && is_string($_GET['q'] ?? null)
        ? str_sub_u(trim($_GET['q']), 0, 100) : '';
}

function hsiren_page_url(int $page): string
{
    $url = home_page_url($page);
    if (hsiren_search() !== '') { $url = url_with_query($url, ['q' => hsiren_search()]); }
    return hsiren_url($url);
}

function hsiren_post_list(array $posts): string
{
    $categories = [];
    foreach (all_rows('SELECT id, name, slug FROM categories') as $category) { $categories[(int)$category['id']] = $category; }
    $commentCounts = [];
    $ids = array_values(array_filter(array_map(static fn(array $post): int => (int)$post['id'], $posts)));
    if ($ids) {
        foreach (all_rows('SELECT post_id, COUNT(*) AS total FROM comments WHERE status = ? AND post_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY post_id', ['approved', ...$ids]) as $row) {
            $commentCounts[(int)$row['post_id']] = (int)$row['total'];
        }
    }
    ob_start(); ?>
    <div class="siren-list<?= hsiren_option('list_style', 'cards') === 'text' ? ' siren-list--text' : '' ?>">
    <?php foreach ($posts as $post):
        $post = public_content_context($post);
        $link = hsiren_url(content_permalink($post));
        $category = $categories[(int)($post['category_id'] ?? 0)] ?? null;
        $timestamp = (int)$post['published_at'];
    ?>
      <article class="post-list-thumb">
        <a class="post-thumb" href="<?= h($link) ?>" tabindex="-1" aria-hidden="true"><img src="<?= h(hsiren_post_cover($post)) ?>" data-siren-fallback="<?= h(hsiren_fallback_cover((int)$post['id'])) ?>" alt="" loading="lazy" decoding="async"></a>
        <div class="post-content-wrap">
          <div class="post-date"><time datetime="<?= h(date(DATE_ATOM, $timestamp)) ?>"><?= h(public_date($timestamp)) ?></time><?php if (!empty($post['is_pinned'])): ?> · <span class="pinned-badge"><?= h(sblog_t('置顶')) ?></span><?php endif; ?></div>
          <h2 class="post-title"><a href="<?= h($link) ?>"><?= h($post['title']) ?></a></h2>
          <div class="post-meta">
            <span><?= h(sblog_tn('浏览：{count}', (int)($post['views'] ?? 0))) ?></span>
            <a href="<?= h($link . '#comments') ?>"><?= h(sblog_tn('{count} 条评论', $commentCounts[(int)$post['id']] ?? 0)) ?></a>
            <?php if ($category): ?><a href="<?= h(hsiren_url(url_for('category', ['slug' => $category['slug']]))) ?>"><?= h($category['name']) ?></a><?php endif; ?>
          </div>
          <p class="float-content"><?= h(str_sub_u((string)$post['excerpt'], 0, 160)) ?></p>
          <a class="siren-read-more" href="<?= h($link) ?>" aria-label="<?= h(sblog_t('阅读文章') . '：' . $post['title']) ?>">•••</a>
        </div>
      </article>
    <?php endforeach; ?>
    <?php if (!$posts): ?><div class="empty-notice"><p><?= h(hsiren_search() !== '' ? sblog_t('没有匹配的文章。') : sblog_t('还没有已发布的文章。')) ?></p></div><?php endif; ?>
    </div>
    <?php return (string)ob_get_clean();
}

function hsiren_home_content(): string
{
    $page = max(1, (int)($_GET['p'] ?? 1));
    $perPage = max(1, (int)setting('posts_per_page', '6'));
    $search = hsiren_search();
    if ($search !== '') {
        // Titles are public. Searching raw bodies could reveal password or reply-only text.
        $where = "kind = ? AND status = ? AND published_at > 0 AND published_at <= ? AND title LIKE ? ESCAPE '\\'";
        $params = ['post', 'published', time(), '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%'];
        $total = (int)val('SELECT COUNT(*) FROM posts WHERE ' . $where, $params);
        if ($page > max(1, (int)ceil($total / $perPage))) {
            simple_error_page(sblog_t('页面不存在'), sblog_t('分页超出了范围。'), 404);
        }
        $posts = array_map('public_content_context', all_rows('SELECT * FROM posts WHERE ' . $where . ' ORDER BY is_pinned DESC, published_at DESC, id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params));
    } else {
        $total = count_published_posts();
        $posts = fetch_published_posts($perPage, ($page - 1) * $perPage);
    }
    $pages = max(1, (int)ceil($total / $perPage));
    ob_start(); ?>
    <?php if ($search === '' && ($notice = hsiren_option('notice')) !== ''): ?><aside class="notice"><span aria-hidden="true">♧</span><p><?= nl2br(h($notice)) ?></p></aside><?php endif; ?>
    <?php if ($search === '' && hsiren_option('show_features') === '1'):
        $features = [];
        for ($i = 1; $i <= 3; $i++) {
            $label = hsiren_option('feature' . $i . '_title');
            $url = hsiren_safe_url(hsiren_option('feature' . $i . '_url'));
            if ($label !== '' && $url !== '') { $features[] = ['label' => $label, 'url' => $url, 'image' => hsiren_safe_url(hsiren_option('feature' . $i . '_image')) ?: hsiren_fallback_cover($i - 1)]; }
        }
        if ($features): ?>
    <section class="top-feature"><h2 class="main-title"><?= h(hsiren_option('feature_title', '聚焦')) ?></h2><div class="feature-content">
      <?php foreach ($features as $feature): ?><a class="feature-item" href="<?= h(hsiren_url($feature['url'])) ?>"><img src="<?= h($feature['image']) ?>" alt="" loading="lazy"><span><?= h($feature['label']) ?></span></a><?php endforeach; ?>
    </div></section>
    <?php endif; endif; ?>
    <h1 class="main-title"><?= h($search !== '' ? sblog_t('搜索') . '：' . $search : sblog_t('文章')) ?></h1>
    <?= hsiren_post_list($posts) ?>
    <?php if ($pages > 1): ?><nav class="siren-pagination" aria-label="<?= h(sblog_t('文章分页')) ?>">
      <?php if ($page > 1): ?><a rel="prev" href="<?= h(hsiren_page_url($page - 1)) ?>">← <?= h(sblog_t('上一页')) ?></a><?php endif; ?>
      <span><?= $page ?> / <?= $pages ?></span>
      <?php if ($page < $pages): ?><a rel="next" href="<?= h(hsiren_page_url($page + 1)) ?>"><?= h(sblog_t('下一页')) ?> →</a><?php endif; ?>
    </nav><?php endif; ?>
    <?php return (string)ob_get_clean();
}

function hsiren_content(string $content, string $action, int $status): string
{
    if ($status >= 400) { return $content; }
    if ($action === 'home') { return hsiren_home_content(); }
    if (!in_array($action, ['tag', 'category'], true)) { return $content; }
    $slug = trim((string)($_GET['slug'] ?? ''));
    $posts = $action === 'tag' ? fetch_posts_by_tag_slug($slug) : array_map('public_content_context', all_rows(
        'SELECT p.* FROM posts p JOIN categories c ON c.id = p.category_id WHERE c.slug = ? AND p.kind = ? AND p.status = ? AND p.published_at > 0 AND p.published_at <= ? ORDER BY p.is_pinned DESC, p.published_at DESC, p.id DESC',
        [$slug, 'post', 'published', time()]
    ));
    preg_match('/\A\s*(<h1\b[^>]*class="post-title"[^>]*>.*?<\/h1>)/s', $content, $match);
    return ($match[1] ?? '') . hsiren_post_list($posts);
}
