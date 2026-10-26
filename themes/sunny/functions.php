<?php
declare(strict_types=1);

function sunny_icon(string $name, int $size = 20): string
{
    $icons = [
        'home' => '<path d="m3 10 9-7 9 7v10a1 1 0 0 1-1 1h-5v-7H9v7H4a1 1 0 0 1-1-1Z"/>',
        'folder' => '<path d="M3 7V5a2 2 0 0 1 2-2h4l2 3h8a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
        'archive' => '<rect x="3" y="3" width="18" height="4" rx="1"/><path d="M5 7v14h14V7M10 11h4"/>',
        'tags' => '<path d="m20 13-7 7a2 2 0 0 1-3 0l-7-7V3h10l7 7a2 2 0 0 1 0 3Z"/><circle cx="7.5" cy="7.5" r=".5"/>',
        'tag' => '<path d="m20 13-7 7a2 2 0 0 1-3 0l-7-7V3h10l7 7a2 2 0 0 1 0 3Z"/><circle cx="7.5" cy="7.5" r=".5"/>',
        'link' => '<path d="m10 13 4-4M8 16l-1 1a4.2 4.2 0 0 1-6-6l4-4a4.2 4.2 0 0 1 6 0m2 1 1-1a4.2 4.2 0 0 1 6 6l-4 4a4.2 4.2 0 0 1-6 0"/>',
        'rss' => '<circle cx="5" cy="19" r="1"/><path d="M4 4a16 16 0 0 1 16 16M4 11a9 9 0 0 1 9 9"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="7.5"/><path d="m16 16 5 5"/>',
        'moon' => '<path d="M20.9 13A9 9 0 0 1 11 3.1 9 9 0 1 0 20.9 13Z"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4m0-14.2-1.4 1.4M6.3 17.7l-1.4 1.4"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'x' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'chevron-left' => '<path d="m15 6-6 6 6 6"/>',
        'arrow-up' => '<path d="M12 20V4m-6 6 6-6 6 6"/>',
        'arrow-right' => '<path d="M4 12h16m-6-6 6 6-6 6"/>',
        'book-open' => '<path d="M12 7c-2-2-6-3-10-2v15c4-1 8 0 10 2 2-2 6-3 10-2V5c-4-1-8 0-10 2v15"/>',
        'post-kind' => '<path stroke="none" fill="currentColor" d="M20 22H4a1 1 0 0 1-1-1V3a1 1 0 0 1 1-1h16a1 1 0 0 1 1 1v18a1 1 0 0 1-1 1Zm-1-2V4H5v16ZM7 6h4v4H7Zm0 6h10v2H7Zm0 4h10v2H7Zm6-9h4v2h-4Z"/>',
        'library' => '<rect width="8" height="18" x="3" y="3" rx="1"/><path d="M7 3v18m13.4-2.1c.2.5-.1 1.1-.6 1.3l-1.9.7c-.5.2-1.1-.1-1.3-.6L11.1 5.1c-.2-.5.1-1.1.6-1.3l1.9-.7c.5-.2 1.1.1 1.3.6Z"/>',
        'comment-square' => '<path stroke="none" fill="currentColor" d="M2 8.994A6 6 0 0 1 8 3h8a6 6 0 0 1 6 5.994V21H8a6 6 0 0 1-6-5.994ZM20 19V8.994A4 4 0 0 0 16 5H8a4 4 0 0 0-4 3.994v6.012A4 4 0 0 0 8 19Zm-6-8h2v2h-2Zm-6 0h2v2H8Z"/>',
        'message-circle' => '<path d="M21 11.5a8.5 8.5 0 0 1-12.6 7.4L3 21l2.1-5.4A8.5 8.5 0 1 1 21 11.5Z"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 11h18"/>',
        'external-link' => '<path d="M15 3h6v6m-12 6L21 3M9 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-4"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8" cy="8" r="1.5"/><path d="m21 15-6-6L3 21"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m2 6 10 7L22 6"/>',
        'settings' => '<path d="m9 3-1 3-3 1-2 3 2 2-1 3 3 2 1 3h4l1-3 3-1 3-3-2-2 1-3-3-2-1-3Z"/><circle cx="11" cy="12" r="3"/>',
        'copy' => '<rect x="8" y="8" width="13" height="13" rx="2"/><path d="M16 8V3H3v13h5"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'pin' => '<path d="m16 3 5 5-4 1-4 4v4l-3-3-6 6 6-6-3-3h4l4-4Z"/>',
        'eye' => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
    ];
    $size = max(12, min(64, $size));
    return '<svg class="sunny-icon" xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($icons[$name] ?? $icons['book-open']) . '</svg>';
}

function sunny_preview_url(string $url): string
{
    return (string)($_GET['theme_preview'] ?? '') === 'sunny' && is_admin()
        ? url_with_query($url, ['theme_preview' => 'sunny'])
        : $url;
}

function sunny_url(string $action, array $params = []): string
{
    return sunny_preview_url(url_for($action, $params));
}

function sunny_home_posts(): array
{
    static $posts;
    if ($posts === null) {
        $perPage = max(1, (int)setting('posts_per_page', '6'));
        $page = max(1, (int)($_GET['p'] ?? 1));
        $posts = fetch_published_posts($perPage, ($page - 1) * $perPage);
    }
    return $posts;
}

function sunny_categories(): array
{
    static $categories;
    return $categories ??= all_rows(
        'SELECT c.*, COUNT(p.id) AS post_count FROM categories c LEFT JOIN posts p ON p.category_id = c.id AND p.kind = ? AND p.status = ? AND p.published_at <= ? GROUP BY c.id ORDER BY c.sort_order ASC, c.id DESC',
        ['post', 'published', time()]
    );
}

function sunny_image_url(string $url): string
{
    $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) {
        return '';
    }
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    } elseif (!str_starts_with($url, '/') && !preg_match('/^[a-z][a-z\d+.-]*:/i', $url)) {
        if (str_contains($url, '..')) {
            return '';
        }
        $url = asset_url(preg_replace('#^\./#', '', $url) ?? $url);
    }
    $url = safe_link_url($url);
    return $url !== '#' && !str_starts_with($url, '#') ? $url : '';
}

function sunny_post_cover(array $post): string
{
    $post = public_content_context($post);
    foreach (['cover_url', 'cover', 'thumbnail', 'image'] as $key) {
        $image = sunny_image_url((string)($post[$key] ?? ''));
        if ($image !== '') {
            return $image;
        }
    }
    $content = preg_replace('/```.*?```/s', '', (string)($post['content'] ?? '')) ?? '';
    $patterns = [
        '/!\[[^\]]*\]\(\s*<?([^\s)>]+)>?(?:\s+["\'][^"\']*["\'])?\s*\)/u',
        '/<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i',
        '#(https?://[^\s<>"\']+\.(?:jpe?g|png|webp|gif|avif)(?:\?[^\s<>"\']*)?)#i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[1] as $candidate) {
                $image = sunny_image_url((string)$candidate);
                if ($image !== '') {
                    return $image;
                }
            }
        }
    }
    return '';
}

function sunny_post_counts(array $posts): array
{
    $ids = array_values(array_filter(array_map(static fn(array $post): int => (int)($post['id'] ?? 0), $posts)));
    if ($ids === []) {
        return [];
    }
    $counts = [];
    $idList = implode(',', $ids);
    foreach (all_rows('SELECT post_id, COUNT(*) AS total FROM comments WHERE status = ? AND post_id IN (' . $idList . ') GROUP BY post_id', ['approved']) as $row) {
        $counts[(int)$row['post_id']]['comments'] = (int)$row['total'];
    }
    if (sunny_likes_available()) {
        foreach (article_likes_states($ids) as $id => $state) {
            $counts[$id]['likes'] = (int)$state['count'];
            $counts[$id]['liked'] = (bool)$state['liked'];
        }
    }
    return $counts;
}

function sunny_likes_available(): bool
{
    return in_array('article-likes', $GLOBALS['sblog_loaded_plugins'] ?? [], true)
        && function_exists('article_likes_states') && function_exists('article_likes_button');
}

function sunny_card_comments(array $posts): array
{
    $ids = array_values(array_filter(array_map(static fn(array $post): int => (int)($post['id'] ?? 0), $posts), static fn(int $id): bool => $id > 0));
    if ($ids === []) {
        return [];
    }
    $comments = [];
    $rows = all_rows(
        "SELECT * FROM (
            SELECT c.id, c.post_id, c.parent_id, c.reply_to_name, c.author_name, c.author_email, c.content, c.created_at,
                ROW_NUMBER() OVER (PARTITION BY c.post_id ORDER BY c.created_at DESC, c.id DESC) AS preview_rank
            FROM comments c JOIN posts p ON p.id = c.post_id
            WHERE c.status = ? AND p.kind = ? AND p.status = ? AND p.published_at > 0 AND p.published_at <= ?
                AND COALESCE(p.content_password_hash, '') = '' AND c.post_id IN (" . implode(',', $ids) . ")
        ) WHERE preview_rank <= 3 ORDER BY created_at DESC, id DESC",
        ['approved', 'post', 'published', time()]
    );
    foreach ($rows as $comment) {
        unset($comment['preview_rank']);
        $comments[(int)$comment['post_id']][] = $comment;
    }
    return $comments;
}

function sunny_render_card_comments(array $post, array $comments, int $total): string
{
    if ($comments === []) {
        return '';
    }
    $url = sunny_preview_url(content_permalink($post));
    ob_start();
    ?>
    <div class="sunny-card-comments<?= $total > count($comments) ? ' sunny-card-comments--more' : '' ?>" aria-label="近期评论预览">
      <ul>
        <?php foreach ($comments as $comment): ?>
        <li class="sunny-card-comment">
          <span class="sunny-card-comment-line"><span class="sunny-card-comment-author"><a class="sunny-card-comment-name" href="<?= h($url . '#comment-' . (int)$comment['id']) ?>"><span class="sunny-card-comment-avatar" aria-hidden="true"><?= sunny_icon('user', 20) ?><img src="<?= h(gravatar_url((string)$comment['author_email'], 40)) ?>" width="20" height="20" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove()"></span><strong><?= h((string)$comment['author_name']) ?></strong></a><?php if ((int)($comment['parent_id'] ?? 0) > 0 && trim((string)($comment['reply_to_name'] ?? '')) !== ''): ?><a class="sunny-card-comment-reply" href="<?= h($url . '#comment-' . (int)$comment['parent_id']) ?>">＠<?= h((string)$comment['reply_to_name']) ?></a><?php endif; ?></span> <a class="sunny-card-comment-text" href="<?= h($url . '#comment-' . (int)$comment['id']) ?>"><?= h(str_sub_u((string)$comment['content'], 0, 320)) ?><?= str_len_u((string)$comment['content']) > 320 ? '…' : '' ?></a></span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php if ($total > count($comments)): ?><a class="sunny-card-comments-more" href="<?= h($url . '#comments') ?>" aria-label="查看全部 <?= $total ?> 条评论">MORE</a><?php endif; ?>
    </div>
    <?php
    return (string)ob_get_clean();
}

function sunny_render_card(array $post, array $counts = [], array $comments = []): string
{
    $post = public_content_context($post);
    $url = sunny_preview_url(content_permalink($post));
    $cover = sunny_post_cover($post);
    $title = (string)($post['title'] ?? '');
    $excerpt = trim(strip_tags((string)($post['excerpt'] ?? '')));
    $date = (int)($post['published_at'] ?? 0);
    $category = null;
    foreach (sunny_categories() as $item) {
        if ((int)$item['id'] === (int)($post['category_id'] ?? 0)) {
            $category = $item;
            break;
        }
    }
    $tags = array_slice(tag_descriptors($post), 0, 3);
    ob_start();
    ?>
    <article class="sunny-post-card<?= $cover !== '' ? ' sunny-post-card--image' : '' ?>" itemscope itemtype="https://schema.org/BlogPosting">
      <header class="sunny-post-author">
        <a class="sunny-post-avatar" href="<?= h(sunny_url('home')) ?>" aria-label="<?= h(setting('author_name', setting('site_name'))) ?>"><img src="<?= h(theme_logo_url()) ?>" alt="" loading="lazy" decoding="async"></a>
        <div class="sunny-post-author-info"><a class="sunny-post-author-name" href="<?= h(sunny_url('home')) ?>"><?= h(setting('author_name', setting('site_name'))) ?></a><div class="sunny-post-date"><time datetime="<?= h(date(DATE_ATOM, $date)) ?>" title="<?= h(public_date($date)) ?>"><?= h(friendly_public_date($date)) ?></time><?php if ($category): ?><span aria-hidden="true"> · </span><a href="<?= h(sunny_url('category', ['slug' => (string)$category['slug']])) ?>"><?= h((string)$category['name']) ?></a><?php endif; ?></div></div>
        <?php if (!empty($post['is_pinned'])): ?><span class="sunny-pinned" title="置顶文章"><?= sunny_icon('pin', 15) ?><span>置顶</span></span><?php endif; ?>
      </header>
      <div class="sunny-post-body">
        <h2 class="sunny-post-title" itemprop="headline"><a href="<?= h($url) ?>" itemprop="url"><?= h($title) ?></a></h2>
        <?php if ($excerpt !== ''): ?><a class="sunny-post-excerpt" href="<?= h($url) ?>" itemprop="description"><?= h($excerpt) ?></a><?php endif; ?>
        <?php if ($cover !== ''): ?><a class="sunny-post-cover" href="<?= h($url) ?>" aria-label="<?= h($title) ?>"><img src="<?= h($cover) ?>" alt="<?= h($title) ?>" loading="lazy" decoding="async" onerror="this.closest('.sunny-post-cover').classList.add('sunny-post-cover--empty');this.remove()"></a><?php endif; ?>
        <?php if ($tags !== []): ?><div class="sunny-post-tags"><?php foreach ($tags as $tag): ?><a href="<?= h(sunny_url('tag', ['slug' => (string)$tag['slug']])) ?>">#<?= h((string)$tag['label']) ?></a><?php endforeach; ?></div><?php endif; ?>
      </div>
      <footer class="sunny-post-meta"><a class="sunny-post-kind" href="<?= h($url) ?>"><?= sunny_icon('post-kind', 16) ?><span>文章</span></a><span title="阅读次数"><?= sunny_icon('library', 16) ?><span>阅读</span><b><?= h((string)(int)($post['views'] ?? 0)) ?></b></span><a href="<?= h($url . '#comments') ?>" title="查看评论"><?= sunny_icon('comment-square', 16) ?><span>评论</span><b><?= h((string)(int)($counts['comments'] ?? 0)) ?></b></a><?php if (sunny_likes_available()): ?><?= article_likes_button((int)$post['id'], ['count' => (int)($counts['likes'] ?? 0), 'liked' => (bool)($counts['liked'] ?? false)]) ?><?php endif; ?></footer>
      <?= sunny_render_card_comments($post, $comments, (int)($counts['comments'] ?? 0)) ?>
    </article>
    <?php
    return (string)ob_get_clean();
}

function sunny_render_grid(array $posts): string
{
    if ($posts === []) {
        return '<div class="sunny-empty">' . sunny_icon('book-open', 30) . '<p>这里还没有已发布的文章。</p></div>';
    }
    $counts = sunny_post_counts($posts);
    $comments = sunny_card_comments($posts);
    $html = '<div class="sunny-post-grid">';
    foreach ($posts as $post) {
        $html .= sunny_render_card($post, $counts[(int)$post['id']] ?? [], $comments[(int)$post['id']] ?? []);
    }
    return $html . '</div>';
}

function sunny_pagination(): string
{
    $page = max(1, (int)($_GET['p'] ?? 1));
    $perPage = max(1, (int)setting('posts_per_page', '6'));
    $totalPages = max(1, (int)ceil(count_published_posts() / $perPage));
    if ($totalPages < 2) {
        return '';
    }
    $html = '<nav class="sunny-pagination" aria-label="文章分页">';
    if ($page > 1) {
        $html .= '<a class="sunny-page-prev" href="' . h(sunny_preview_url(home_page_url($page - 1))) . '" aria-label="上一页">' . sunny_icon('chevron-left', 18) . '</a>';
    }
    $shown = [];
    foreach (array_merge([1], range(max(1, $page - 2), min($totalPages, $page + 2)), [$totalPages]) as $number) {
        if (in_array($number, $shown, true)) {
            continue;
        }
        if ($shown !== [] && $number > end($shown) + 1) {
            $html .= '<span class="sunny-page-gap">…</span>';
        }
        $shown[] = $number;
        $html .= $number === $page
            ? '<span class="is-current" aria-current="page">' . $number . '</span>'
            : '<a href="' . h(sunny_preview_url(home_page_url($number))) . '">' . $number . '</a>';
    }
    if ($page < $totalPages) {
        $html .= '<a class="sunny-page-next" href="' . h(sunny_preview_url(home_page_url($page + 1))) . '" aria-label="下一页">' . sunny_icon('chevron-right', 18) . '</a>';
    }
    return $html . '</nav>';
}

function sunny_search_items(): array
{
    return array_map(static fn(array $post): array => [
        'title' => (string)$post['title'],
        'url' => sunny_preview_url(content_permalink($post)),
        'excerpt' => derive_excerpt((string)($post['excerpt'] ?? ''), 100),
    ], fetch_published_posts(2000, 0));
}

function sunny_render_archives(): string
{
    $posts = fetch_archive_posts();
    $groups = [];
    foreach ($posts as $post) {
        $groups[date('Y', (int)$post['published_at'])][] = $post;
    }
    $html = '<section class="sunny-archive"><header class="sunny-section-title"><h1>' . sunny_icon('archive', 24) . '文章归档</h1><p>共 ' . count($posts) . ' 篇文章，记录每一刻。</p></header>';
    foreach ($groups as $year => $entries) {
        $html .= '<section class="sunny-archive-year"><h2>' . h((string)$year) . '<small>' . count($entries) . ' 篇</small></h2><ul>';
        foreach ($entries as $post) {
            $html .= '<li><time datetime="' . h(date('Y-m-d', (int)$post['published_at'])) . '">' . h(date('m-d', (int)$post['published_at'])) . '</time><a href="' . h(sunny_preview_url(content_permalink($post))) . '">' . h((string)$post['title']) . '</a></li>';
        }
        $html .= '</ul></section>';
    }
    return $html . ($posts === [] ? '<div class="sunny-empty"><p>归档还是空的。</p></div>' : '') . '</section>';
}

function sunny_render_tags(): string
{
    $tags = tag_index_data();
    $html = '<section class="sunny-tag-index"><header class="sunny-section-title"><h1>' . sunny_icon('tags', 24) . '标签</h1><p>从一个标签，发现更多日常。</p></header><div class="sunny-tag-cloud">';
    foreach ($tags as $tag) {
        $html .= '<a href="' . h(sunny_url('tag', ['slug' => (string)$tag['slug']])) . '"><span># ' . h((string)$tag['label']) . '</span><b>' . (int)$tag['count'] . '</b></a>';
    }
    return $html . ($tags === [] ? '<p class="sunny-empty">还没有标签。</p>' : '') . '</div></section>';
}

add_theme_filter('body_class', static function (string $classes, array $context): string {
    $active = (string)($context['active'] ?? '');
    $action = (string)($_GET['a'] ?? 'home');
    $listing = ($active === 'home' && in_array($action, ['home', 'category'], true)) || ($active === 'tags' && $action === 'tag');
    return trim($classes . ' sunny-theme ' . ($listing ? 'sunny-view-list' : 'sunny-view-content'));
});

add_theme_filter('content', static function (string $content, array $context): string {
    $active = (string)($context['active'] ?? '');
    $action = (string)($_GET['a'] ?? 'home');
    if ($action === 'post' && sunny_likes_available() && str_contains($content, '<article class="post-detail">')) {
        $post = fetch_post_by_identifier(trim((string)($_GET['slug'] ?? $_GET['id'] ?? '')));
        if ($post && is_live_post($post) && (!content_requires_password($post) || content_password_is_unlocked($post))) {
            $postId = (int)$post['id'];
            $state = article_likes_states([$postId])[$postId];
            $likeFooter = '<div class="sunny-detail-likes"><div class="sunny-detail-like-actions">' . article_likes_button($postId, $state) . '</div>'
                . '<p class="sunny-detail-like-stats">现在已有 ' . (int)($post['views'] ?? 0) . ' 次阅读，' . approved_comment_count($postId) . ' 条评论，<b data-sunny-like-total="' . $postId . '">' . (int)$state['count'] . '</b> 人点赞</p></div>';
            $marker = str_contains($content, '<div class="post-tags">') ? '<div class="post-tags">' : '</article>';
            $position = strpos($content, $marker);
            if ($position !== false) {
                $content = substr_replace($content, $likeFooter, $position, 0);
            }
        }
        return $content;
    }
    if ($active === 'home' && $action === 'home' && (string)($context['title'] ?? '') === (string)($context['site_name'] ?? '')) {
        return sunny_render_grid(sunny_home_posts()) . sunny_pagination();
    }
    if ($active === 'home' && $action === 'category') {
        $category = one('SELECT * FROM categories WHERE slug = ?', [trim((string)($_GET['slug'] ?? ''))]);
        if (!$category) {
            return $content;
        }
        $posts = array_map('public_content_context', all_rows('SELECT * FROM posts WHERE kind = ? AND category_id = ? AND status = ? AND published_at <= ? ORDER BY is_pinned DESC, published_at DESC, id DESC', ['post', (int)$category['id'], 'published', time()]));
        return '<header class="sunny-section-title"><h1>' . sunny_icon('folder', 24) . h((string)$category['name']) . '</h1><p>' . h((string)$category['description']) . '</p></header>' . sunny_render_grid($posts);
    }
    if ($active === 'tags' && $action === 'tag') {
        $posts = fetch_posts_by_tag_slug(trim((string)($_GET['slug'] ?? '')));
        return '<header class="sunny-section-title"><h1>' . sunny_icon('tags', 24) . h((string)($context['title'] ?? '标签')) . '</h1><p>共 ' . count($posts) . ' 篇文章</p></header>' . sunny_render_grid($posts);
    }
    if ($active === 'archives') {
        return sunny_render_archives();
    }
    if ($active === 'tags') {
        return sunny_render_tags();
    }
    return $content;
});
