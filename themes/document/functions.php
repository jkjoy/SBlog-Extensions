<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

/** Read the core's native theme settings; all stored values initially are strings. */
function document_config(): array
{
    $values = theme_settings('document');
    foreach (theme_settings_schema('document') as $section) {
        foreach ($section['fields'] as $field) {
            $key = $field['key'];
            if ($field['type'] === 'checkbox') { $values[$key] = $values[$key] === '1'; }
            elseif ($field['type'] === 'url') { $values[$key] = document_safe_url($values[$key]); }
        }
    }
    foreach (['excerpt_length' => [30, 500, 120], 'recent_posts_count' => [0, 20, 5], 'footer_since' => [1970, (int)date('Y'), (int)date('Y')]] as $key => [$min, $max, $default]) {
        $value = (string)$values[$key];
        $values[$key] = preg_match('/^\d+$/D', $value) && (int)$value >= $min && (int)$value <= $max ? (int)$value : $default;
    }
    $values['social_email'] = filter_var($values['social_email'], FILTER_VALIDATE_EMAIL) !== false ? $values['social_email'] : '';
    return $values;
}

function document_asset(string $path): string
{
    return theme_asset_url($path) . '?v=' . rawurlencode((string)filemtime(__DIR__ . '/' . $path));
}

function document_safe_url(string $url): string
{
    $url = trim($url);
    if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return ''; }
    return valid_http_url($url) || (str_starts_with($url, '/') && !str_starts_with($url, '//')) ? $url : '';
}

/** Preserve authorized previews only on internal public navigation. */
function document_url(string $url): string
{
    if (($_GET['theme_preview'] ?? '') !== 'document' || !is_admin() || $url === '' || $url[0] === '#') { return $url; }
    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host'])) { return $url; }
    parse_str((string)($parts['query'] ?? ''), $query);
    $path = (string)($parts['path'] ?? '');
    if (isset($query['theme_preview']) || in_array($query['a'] ?? '', ['rss', 'sitemap'], true)
        || (preg_match('/\.[a-z0-9]+$/i', $path) && basename($path) !== 'index.php')) { return $url; }
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    $base = $fragment !== '' ? substr($url, 0, -strlen($fragment)) : $url;
    return $base . (str_contains($base, '?') ? '&' : '?') . 'theme_preview=document' . $fragment;
}

function document_preview_links(string $html): string
{
    if (($_GET['theme_preview'] ?? '') !== 'document' || !is_admin()) { return $html; }
    return preg_replace_callback('/\bhref="([^"]*)"/', static function (array $match): string {
        $url = html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return 'href="' . h(document_url($url)) . '"';
    }, $html) ?? $html;
}

function document_categories(): array
{
    static $categories;
    return $categories ??= all_rows(
        'SELECT c.id, c.name, c.slug, COUNT(p.id) AS post_count FROM categories c
         LEFT JOIN posts p ON p.category_id = c.id AND p.kind = ? AND p.status = ? AND p.published_at > 0 AND p.published_at <= ?
         GROUP BY c.id ORDER BY c.sort_order ASC, c.id DESC', ['post', 'published', time()]
    );
}

/** Always sanitize before deriving public search text, excerpts, or thumbnails. */
function document_public_posts(string $where = '', array $parameters = []): array
{
    return array_map('public_content_context', all_rows(
        'SELECT * FROM posts WHERE kind = ? AND status = ? AND published_at > 0 AND published_at <= ? '
        . $where . ' ORDER BY is_pinned DESC, published_at DESC, id DESC', ['post', 'published', time(), ...$parameters]
    ));
}

function document_search_query(): string
{
    return str_sub_u(trim(is_string($_GET['q'] ?? null) ? $_GET['q'] : ''), 0, 100);
}

function document_search_posts(string $query): array
{
    $needle = str_lower_u($query);
    return array_values(array_filter(document_public_posts(), static function (array $post) use ($needle): bool {
        $text = (string)$post['title'] . "\n" . (string)$post['excerpt'] . "\n" . markdown_to_plain((string)$post['content']);
        return str_contains(str_lower_u($text), $needle);
    }));
}

function document_thumbnail(array $post): string
{
    // The caller passes a public context: password bodies and reply-only blocks have been removed.
    $content = (string)($post['content'] ?? '');
    if (preg_match('/!\[[^\]]*\]\(<?([^\s)>]+)>?(?:\s+["\'][^"\']*["\'])?\)/', $content, $match)
        || preg_match('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $content, $match)) {
        return document_safe_url(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return '';
}

function document_post_list(array $posts, array $config): string
{
    if ($posts === []) { return '<div class="empty-notice"><p>' . h(sblog_t('还没有已发布的文章。')) . '</p></div>'; }
    $categories = [];
    foreach (document_categories() as $category) { $categories[(int)$category['id']] = $category; }
    $ids = array_map(static fn(array $post): int => (int)$post['id'], $posts);
    $counts = [];
    foreach (all_rows('SELECT post_id, COUNT(*) AS total FROM comments WHERE status = ? AND post_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY post_id', ['approved', ...$ids]) as $row) {
        $counts[(int)$row['post_id']] = (int)$row['total'];
    }
    ob_start(); ?>
    <section class="main-content document-post-list" data-columns="<?= h($config['list_columns']) ?>" data-thumbnail="<?= h($config['thumbnail_position']) ?>" aria-label="文章列表">
      <?php foreach ($posts as $post):
          $post = public_content_context($post);
          $url = document_url(content_permalink($post));
          $image = $config['thumbnail_position'] === 'none' ? '' : document_thumbnail($post);
          $category = $categories[(int)($post['category_id'] ?? 0)] ?? null;
          $excerpt = (string)$post['excerpt'];
          $limit = (int)$config['excerpt_length'];
          if (str_len_u($excerpt) > $limit) { $excerpt = rtrim(str_sub_u($excerpt, 0, $limit)) . '…'; }
      ?>
      <article class="article-list<?= $image !== '' ? ' has-thumbnail' : '' ?>">
        <?php if ($image !== ''): ?><a class="document-thumbnail" href="<?= h($url) ?>" tabindex="-1" aria-hidden="true"><img src="<?= h($image) ?>" alt="" loading="lazy" decoding="async"></a><?php endif; ?>
        <div class="article-list-content">
          <h2 class="article-title"><a href="<?= h($url) ?>"><?php if (!empty($post['is_pinned'])): ?><span class="pinned-badge">置顶</span><?php endif; ?><?= h($post['title']) ?></a></h2>
          <?php if ($config['show_excerpt']): ?><p class="article-excerpt"><?= h($excerpt) ?></p><?php endif; ?>
          <div class="article-meta">
            <?php if ($category): ?><a href="<?= h(document_url(url_for('category', ['slug' => (string)$category['slug']]))) ?>"><?= h($category['name']) ?></a><?php endif; ?>
            <time datetime="<?= h(date(DATE_ATOM, (int)$post['published_at'])) ?>"><?= h(public_date((int)$post['published_at'])) ?></time>
            <span><?= (int)($post['views'] ?? 0) ?> 阅读</span>
            <a href="<?= h($url) ?>#comments"><?= $counts[(int)$post['id']] ?? 0 ?> 评论</a>
          </div>
        </div>
      </article>
      <?php endforeach; ?>
    </section>
    <?php return (string)ob_get_clean();
}

function document_pagination(int $page, int $pages, string $query = ''): string
{
    if ($pages <= 1) { return ''; }
    $makeUrl = static function (int $number) use ($query): string {
        $url = home_page_url($number);
        if ($query !== '') { $url .= (str_contains($url, '?') ? '&' : '?') . 'q=' . rawurlencode($query); }
        return document_url($url);
    };
    ob_start(); ?>
    <nav class="document-pagination" aria-label="文章分页">
      <?php if ($page > 1): ?><a href="<?= h($makeUrl($page - 1)) ?>" rel="prev">上一页</a><?php endif; ?>
      <?php for ($number = max(1, $page - 2); $number <= min($pages, $page + 2); $number++): ?>
        <?php if ($number === $page): ?><span class="current" aria-current="page"><?= $number ?></span><?php else: ?><a href="<?= h($makeUrl($number)) ?>"><?= $number ?></a><?php endif; ?>
      <?php endfor; ?>
      <?php if ($page < $pages): ?><a href="<?= h($makeUrl($page + 1)) ?>" rel="next">下一页</a><?php endif; ?>
    </nav>
    <?php return (string)ob_get_clean();
}

function document_detail_footer(array $post, array $config): string
{
    if (content_requires_password($post) && !content_password_is_unlocked($post)) { return ''; }
    $author = one('SELECT username, nickname FROM users WHERE id = ?', [(int)$post['author_id']]) ?? [];
    $authorName = trim((string)($author['nickname'] ?? '')) ?: (string)($author['username'] ?? $config['author_name']);
    $copyright = strtr((string)$config['copyright_text'], ['{title}' => (string)$post['title'], '{url}' => absolute_url(content_permalink($post)), '{author}' => $authorName]);
    ob_start(); ?>
    <?php if ($config['show_copyright'] && $copyright !== ''): ?><aside class="document-copyright" aria-label="版权信息"><?= nl2br(h($copyright), false) ?></aside><?php endif; ?>
    <?php if ($config['donation_image'] !== ''): ?><details class="document-donation"><summary>赞赏支持</summary><img src="<?= h($config['donation_image']) ?>" alt="赞赏收款码" loading="lazy"></details><?php endif; ?>
    <?php return (string)ob_get_clean();
}

/** Reuse core detail rendering, including content gates and plugin/comment hooks. */
function document_content(string $content, string $action, int $status, string $title, array $config): string
{
    if ($status >= 400) { return '<section class="main-content document-page">' . $content . '</section>'; }
    $page = max(1, (int)($_GET['p'] ?? 1));
    $perPage = max(1, (int)setting('posts_per_page', '6'));
    if ($action === 'home') {
        $query = document_search_query();
        if ($query !== '') {
            $allPosts = document_search_posts($query);
            $total = count($allPosts);
            $posts = array_slice($allPosts, ($page - 1) * $perPage, $perPage);
            $heading = '<h1 class="document-list-header">搜索：' . h($query) . '<small>' . $total . ' 篇文章</small></h1>';
        } else {
            $total = count_published_posts();
            $posts = fetch_published_posts($perPage, ($page - 1) * $perPage);
            $heading = '<h1 class="document-list-header">最新文章</h1>';
        }
        return $heading . ($query !== '' && $posts === [] ? '<section class="main-content document-page empty-notice"><p>没有找到匹配的文章。</p></section>' : document_post_list($posts, $config))
            . document_pagination($page, max(1, (int)ceil($total / $perPage)), $query);
    }
    if (in_array($action, ['tag', 'category'], true)) {
        $slug = trim((string)($_GET['slug'] ?? ''));
        $posts = $action === 'tag' ? fetch_posts_by_tag_slug($slug) : document_public_posts('AND category_id = (SELECT id FROM categories WHERE slug = ?)', [$slug]);
        return '<h1 class="document-list-header">' . h($title) . '<small>' . count($posts) . ' 篇文章</small></h1>' . document_post_list($posts, $config);
    }
    if (in_array($action, ['post', 'page'], true)) {
        if ($action === 'post') {
            $post = fetch_content_by_identifier($action, trim((string)($_GET['slug'] ?? $_GET['id'] ?? '')), is_admin());
            if ($post) {
                $footer = document_detail_footer($post, $config);
                // Core emits one outer article followed by navigation and comments. Split at its closing tag.
                $end = strpos($content, '</article>');
                if ($end !== false && $footer !== '') { $content = substr_replace($content, $footer, $end, 0); }
            }
        }
        return '<section class="main-content document-detail"><nav class="document-breadcrumb" aria-label="面包屑"><a href="' . h(document_url(url_for('home'))) . '">首页</a><span aria-hidden="true"> / </span><span>' . h($title) . '</span></nav>' . $content . '</section>';
    }
    return '<section class="main-content document-page">' . $content . '</section>';
}

function document_sidebar(array $config): void
{
    $name = trim((string)$config['author_name']) ?: setting('site_name');
    $description = trim((string)$config['author_description']) ?: setting('site_tagline');
    $avatar = $config['avatar'] !== '' ? $config['avatar'] : theme_asset_url('assets/images/avatar.svg');
    $statistics = one('SELECT COUNT(*) AS posts, COALESCE(SUM(views), 0) AS views FROM posts WHERE kind = ? AND status = ? AND published_at > 0 AND published_at <= ?', ['post', 'published', time()]) ?? [];
    $commentCount = (int)val('SELECT COUNT(*) FROM comments c JOIN posts p ON p.id = c.post_id WHERE c.status = ? AND p.status = ? AND p.published_at > 0 AND p.published_at <= ?', ['approved', 'published', time()]);
    ?>
    <section class="document-widget document-author">
      <div class="document-author-cover"></div>
      <img class="author-avatar" src="<?= h($avatar) ?>" alt="<?= h($name) ?>" width="80" height="80" loading="lazy">
      <h2 class="author-name"><?= h($name) ?></h2>
      <?php if ($description !== ''): ?><p class="author-description"><?= nl2br(h($description), false) ?></p><?php endif; ?>
      <div class="document-stats"><a href="<?= h(document_url(url_for('archives'))) ?>"><strong><?= (int)($statistics['posts'] ?? 0) ?></strong><span>文章</span></a><span><strong><?= $commentCount ?></strong><span>评论</span></span><span><strong><?= (int)($statistics['views'] ?? 0) ?></strong><span>阅读</span></span></div>
      <div class="document-socials">
        <?php if ($config['social_github'] !== ''): ?><a href="<?= h($config['social_github']) ?>" target="_blank" rel="noopener noreferrer">GitHub</a><?php endif; ?>
        <?php if ($config['social_email'] !== ''): ?><a href="mailto:<?= h($config['social_email']) ?>">邮箱</a><?php endif; ?>
        <?php if ($config['social_rss']): ?><a href="<?= h(url_for('rss')) ?>">RSS</a><?php endif; ?>
      </div>
    </section>
    <?php if ($config['announcement'] !== ''): ?><section class="document-widget document-announcement"><h2 class="widget-title">公告</h2><p><?= nl2br(h($config['announcement']), false) ?></p></section><?php endif; ?>
    <?php if ((int)$config['recent_posts_count'] > 0): ?>
    <section class="document-widget document-recent"><h2 class="widget-title">最新文章</h2><ul>
      <?php foreach (fetch_published_posts((int)$config['recent_posts_count'], 0) as $recent): ?><li><a href="<?= h(document_url(content_permalink($recent))) ?>"><?= h($recent['title']) ?></a><time datetime="<?= h(date(DATE_ATOM, (int)$recent['published_at'])) ?>"><?= h(public_date((int)$recent['published_at'])) ?></time></li><?php endforeach; ?>
    </ul></section>
    <?php endif;
}

add_theme_filter('body_class', static fn(string $classes): string => trim($classes . ' document-theme'));
