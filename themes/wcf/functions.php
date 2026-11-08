<?php
declare(strict_types=1);

if (!defined('THEMES_DIR')) { http_response_code(403); exit; }

function wcf_icon(string $name, int $size = 20): string
{
    $icons = [
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
        'users' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2m20 0v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/><circle cx="9" cy="7" r="4"/>',
        'settings' => '<path d="m9 3-1 3-3 1-2 3 2 2-1 3 3 2 1 3h4l1-3 3-1 3-3-2-2 1-3-3-2-1-3Z"/><circle cx="11" cy="12" r="3"/>',
        'edit' => '<path d="m16 3 5 5-12 12-6 1 1-6ZM14 5l5 5"/>',
        'back' => '<path d="m15 18-6-6 6-6"/>',
        'up' => '<path d="m6 15 6-6 6 6"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="7"/><path d="m16 16 5 5"/>',
        'moon' => '<path d="M21 12.8A9 9 0 0 1 11.2 3 9 9 0 1 0 21 12.8Z"/>',
        'heart' => '<path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>',
        'comment' => '<path d="M21 15a3 3 0 0 1-3 3h-7l-5 4v-4H5a3 3 0 0 1-3-3V6a3 3 0 0 1 3-3h13a3 3 0 0 1 3 3Z"/>',
        'more' => '<circle cx="6" cy="12" r="2" fill="currentColor" stroke="none"/><circle cx="18" cy="12" r="2" fill="currentColor" stroke="none"/>',
    ];
    return '<svg class="wcf-icon" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($icons[$name] ?? $icons['comment']) . '</svg>';
}

function wcf_preview_url(string $url): string
{
    return ($_GET['theme_preview'] ?? '') === 'wcf' && is_admin() ? url_with_query($url, ['theme_preview' => 'wcf']) : $url;
}

function wcf_url(string $route, array $params = []): string
{
    return wcf_preview_url(url_for($route, $params));
}

function wcf_media_url(string $value): string
{
    $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($value === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $value)) { return ''; }
    if (!str_starts_with($value, '/') && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) {
        if (str_contains($value, '..')) { return ''; }
        $value = asset_url(preg_replace('#^\./#', '', $value) ?? $value);
    }
    $value = safe_link_url($value);
    return $value === '#' || str_starts_with($value, '#') ? '' : $value;
}

function wcf_avatar(): string
{
    return wcf_media_url(theme_setting('logo_url')) ?: theme_logo_url();
}

function wcf_author(int $id = 0): array
{
    static $authors = [];
    if (!isset($authors[$id])) {
        $row = $id > 0 ? one('SELECT username, nickname, email FROM users WHERE id = ?', [$id])
            : one('SELECT username, nickname, email FROM users ORDER BY id LIMIT 1');
        $authors[$id] = [
            'name' => trim((string)($row['nickname'] ?? '')) ?: (string)($row['username'] ?? setting('author_name', setting('site_name'))),
            'email' => (string)($row['email'] ?? ''),
        ];
    }
    return $authors[$id];
}

function wcf_post_options(array $post): array
{
    static $overrides;
    if ($overrides === null) {
        $decoded = json_decode(theme_setting('post_overrides'), true);
        $overrides = is_array($decoded) ? $decoded : [];
    }
    $entry = $overrides[(string)($post['id'] ?? '')] ?? $overrides[(string)($post['slug'] ?? '')] ?? [];
    $entry = is_array($entry) ? $entry : [];
    return [
        'position' => is_string($entry['position'] ?? null) ? $entry['position'] : theme_setting('default_location'),
        'position_url' => safe_link_url(is_string($entry['position_url'] ?? null) ? $entry['position_url'] : theme_setting('default_location_url')),
        'advertise' => in_array($entry['advertise'] ?? false, [true, 1, '1'], true),
    ];
}

function wcf_music_card(string $shortcode): string
{
    $shortcode = html_entity_decode(strip_tags($shortcode), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    preg_match_all('/\b(title|artist|cover|src)\s*=\s*(["\'])(.*?)\2/isu', $shortcode, $matches, PREG_SET_ORDER);
    $values = [];
    foreach ($matches as $match) { $values[$match[1]] = $match[3]; }
    $src = wcf_media_url($values['src'] ?? '');
    if ($src === '') { return h($shortcode); }
    $cover = wcf_media_url($values['cover'] ?? '');
    return '<span class="wcf-music">' . ($cover !== '' ? '<img src="' . h($cover) . '" alt="" loading="lazy">' : '')
        . '<span class="wcf-music-info"><strong>' . h($values['title'] ?? '音乐') . '</strong><span>' . h($values['artist'] ?? '')
        . '</span><audio controls preload="none" src="' . h($src) . '">浏览器不支持音频播放。</audio></span></span>';
}

function wcf_music_html(string $html, ?array &$media = null): string
{
    // Consume code blocks and HTML tags first so attribute values remain untouched.
    // A shortcode can contain URL links inserted by SBlog's inline renderer.
    return preg_replace_callback('~<(pre|code|script|style)\b[^>]*>.*?</\1\s*>|<[^>]+>|\[music\s+[^\]\r\n]+\]~isu',
        static function (array $match) use (&$media): string {
            if (!str_starts_with($match[0], '[')) { return $match[0]; }
            $card = wcf_music_card($match[0]);
            if ($media !== null && str_starts_with($card, '<span class="wcf-music">')) { $media[] = $card; return ''; }
            return $card;
        }, $html) ?? $html;
}

function wcf_detail_music(string $content): string
{
    // Change only article bodies; comments, form values and plugin HTML are preserved.
    preg_match_all('~</?div\b[^>]*>~i', $content, $tags, PREG_OFFSET_CAPTURE);
    $depth = 0;
    $bodyDepth = null;
    $start = 0;
    $replacements = [];
    foreach ($tags[0] as [$tag, $offset]) {
        if (str_starts_with($tag, '</')) {
            if ($bodyDepth === $depth) {
                $replacements[] = [$start, $offset - $start, wcf_music_html(substr($content, $start, $offset - $start))];
                $bodyDepth = null;
            }
            $depth--;
        } else {
            $depth++;
            if ($bodyDepth === null && preg_match('~\bclass=["\'][^"\']*\bpost-content\b[^"\']*["\']~i', $tag)) {
                $bodyDepth = $depth;
                $start = $offset + strlen($tag);
            }
        }
    }
    foreach (array_reverse($replacements) as [$offset, $length, $html]) { $content = substr_replace($content, $html, $offset, $length); }
    return $content;
}

/** Build the gallery from the core's already filtered public HTML. */
function wcf_post_body(array $post): array
{
    $html = render_content_html($post);
    $images = [];
    $media = [];
    $html = preg_replace_callback('~<(pre|code)\b[^>]*>.*?</\1\s*>|<figure\b[^>]*class="media-embed[^>"]*"[^>]*>.*?</figure\s*>|<(video|audio)\b[^>]*>.*?</\2\s*>~is',
        static function (array $match) use (&$media): string {
            if (!empty($match[1])) { return $match[0]; }
            $media[] = $match[0];
            return '';
        }, $html) ?? $html;
    // Code examples must remain text, including image syntax displayed inside code blocks.
    $html = preg_replace_callback('~(<(?:pre|code)\b[^>]*>.*?</(?:pre|code)\s*>)|<img\b[^>]*\bsrc="([^"]+)"[^>]*>~is', static function (array $match) use (&$images): string {
        if ($match[1] !== '') { return $match[1]; }
        $url = wcf_media_url($match[2] ?? '');
        if ($url !== '') { $images[$url] = $url; }
        return '';
    }, $html) ?? $html;
    $html = wcf_music_html($html, $media);
    $html = preg_replace('~<(p|a)\b[^>]*>(?:\s|<br\s*/?>)*</\1>~i', '', $html) ?? $html;
    return [$html, array_values($images), $media];
}

function wcf_likes_available(): bool
{
    return in_array('article-likes', $GLOBALS['sblog_loaded_plugins'] ?? [], true)
        && function_exists('article_likes_states') && function_exists('article_likes_button');
}

function wcf_feed_data(array $posts): array
{
    $ids = array_filter(array_map(static fn(array $post): int => (int)$post['id'], $posts));
    if ($ids === []) { return [[], []]; }
    $counts = [];
    $comments = [];
    $idList = implode(',', $ids);
    foreach (all_rows('SELECT post_id, COUNT(*) AS total FROM comments WHERE status = ? AND post_id IN (' . $idList . ') GROUP BY post_id', ['approved']) as $row) {
        $counts[(int)$row['post_id']]['comments'] = (int)$row['total'];
    }
    if (wcf_likes_available()) {
        foreach (article_likes_states($ids) as $id => $state) { $counts[$id]['likes'] = $state; }
    }
    $limit = max(0, min(10, (int)theme_setting('comment_preview_count', '5')));
    if (theme_setting('show_comments', '1') !== '1' || $limit === 0) { return [$counts, $comments]; }
    $rows = all_rows("SELECT * FROM (
        SELECT c.*, ROW_NUMBER() OVER (PARTITION BY c.post_id ORDER BY c.created_at DESC, c.id DESC) AS preview_rank
        FROM comments c JOIN posts p ON p.id = c.post_id
        WHERE c.status = ? AND p.kind = ? AND p.status = ? AND p.published_at <= ?
          AND COALESCE(p.content_password_hash, '') = '' AND c.post_id IN ($idList)
        ) WHERE preview_rank <= $limit ORDER BY created_at ASC, id ASC", ['approved', 'post', 'published', time()]);
    foreach ($rows as $row) { $comments[(int)$row['post_id']][] = $row; }
    return [$counts, $comments];
}

function wcf_gallery(array $images, string $title): string
{
    if ($images === []) { return ''; }
    $total = count($images);
    $html = '<div class="wcf-gallery" data-count="' . min(9, $total) . '" data-total="' . $total . '" aria-label="动态图片">';
    foreach ($images as $index => $image) {
        $html .= '<a class="wcf-gallery-item" href="' . h($image) . '" data-wcf-image aria-label="查看第 ' . ($index + 1) . ' 张图片"' . ($index >= 9 ? ' hidden' : '') . '>'
            . ($index < 9 ? '<img src="' . h($image) . '" alt="' . h($title . ' · ' . ($index + 1)) . '" loading="lazy" decoding="async">' : '')
            . ($index === 8 && $total > 9 ? '<span class="wcf-gallery-more">+' . ($total - 9) . '</span>' : '') . '</a>';
    }
    return $html . '</div>';
}

function wcf_render_post(array $post, array $counts, array $comments): string
{
    $post = public_content_context($post);
    $author = wcf_author((int)($post['author_id'] ?? 0));
    $url = wcf_preview_url(content_permalink($post));
    $id = (int)$post['id'];
    $date = (int)$post['published_at'];
    $config = wcf_post_options($post);
    [$html, $images, $media] = wcf_post_body($post);
    $emptyBody = trim((string)($post['content'] ?? '')) === '';
    $collapse = theme_setting('auto_collapse', '1') === '1';
    $likes = $counts['likes'] ?? null;
    ob_start(); ?>
    <article class="wcf-post" data-post-id="<?= $id ?>">
      <a class="wcf-post-avatar" href="<?= h(wcf_url('home')) ?>" aria-label="<?= h($author['name']) ?>"><img src="<?= h(wcf_avatar()) ?>" width="36" height="36" alt="" loading="lazy" decoding="async"></a>
      <div class="wcf-post-main">
        <a class="wcf-post-author" href="<?= h($url) ?>"><?= h($author['name']) ?></a>
        <?php if (!empty($post['is_pinned'])): ?><span class="wcf-pin">置顶</span><?php endif; ?>
        <?php if ($config['advertise']): ?><span class="wcf-ad">广告</span><?php endif; ?>
        <?php if (theme_setting('show_post_title', '0') === '1'): ?><h2 class="wcf-post-title"><a href="<?= h($url) ?>"><?= h($post['title']) ?></a></h2><?php endif; ?>
        <div class="wcf-post-text" id="wcf-text-<?= $id ?>"<?= $collapse ? ' data-wcf-collapse' : '' ?>><?php if ($emptyBody): ?><p><a href="<?= h($url) ?>"><?= h($post['excerpt'] ?: $post['title']) ?></a></p><?php else: ?><?= $html ?><?php endif; ?></div>
        <?php if ($collapse): ?><button class="wcf-expand" type="button" data-wcf-expand aria-controls="wcf-text-<?= $id ?>" aria-expanded="false" hidden>全文</button><?php endif; ?>
        <?php if ($media !== []): ?><div class="wcf-post-media"><?= implode('', $media) ?></div><?php endif; ?>
        <?= wcf_gallery($images, (string)$post['title']) ?>
        <?php if (theme_setting('show_tags', '0') === '1'): ?><div class="wcf-post-tags"><?php foreach (tag_descriptors($post) as $tag): ?><a href="<?= h(wcf_url('tag', ['slug' => $tag['slug']])) ?>">#<?= h($tag['label']) ?></a><?php endforeach; ?></div><?php endif; ?>
        <?php if ($config['position'] !== ''): ?><div class="wcf-post-location"><?php if ($config['position_url'] !== '#'): ?><a href="<?= h($config['position_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($config['position']) ?></a><?php else: ?><span><?= h($config['position']) ?></span><?php endif; ?></div><?php endif; ?>
        <footer class="wcf-post-meta">
          <a class="wcf-post-time" href="<?= h($url) ?>" title="<?= h($post['title'] . ' · ' . pretty_date($date, true)) ?>"><time datetime="<?= h(date(DATE_ATOM, $date)) ?>"><?= h(friendly_public_date($date)) ?></time></a>
          <?php if (theme_setting('show_category', '0') === '1'): ?><?php foreach (fetch_categories() as $category): ?><?php if ((int)$category['id'] === (int)$post['category_id']): ?><a class="wcf-post-category" href="<?= h(wcf_url('category', ['slug' => $category['slug']])) ?>"><?= h($category['name']) ?></a><?php endif; ?><?php endforeach; ?><?php endif; ?>
          <div class="wcf-post-actions-wrap"><button type="button" class="wcf-actions-toggle" data-wcf-actions aria-controls="wcf-actions-<?= $id ?>" aria-expanded="false" aria-label="点赞与评论"><?= wcf_icon('more', 16) ?></button><div class="wcf-actions-menu" id="wcf-actions-<?= $id ?>" hidden><?php if ($likes !== null): ?><?= article_likes_button($id, $likes) ?><?php endif; ?><a href="<?= h($url . '#comments') ?>"><?= wcf_icon('comment', 16) ?>评论</a></div></div>
        </footer>
        <?php if ($comments !== [] || ($likes !== null && $likes['count'] > 0)): ?>
        <div class="wcf-reactions">
          <?php if ($likes !== null && $likes['count'] > 0): ?><div class="wcf-likes"><?= wcf_icon('heart', 14) ?><span><?= (int)$likes['count'] ?> 人赞过</span></div><?php endif; ?>
          <?php if ($comments !== []): ?><ul class="wcf-comment-preview"><?php foreach ($comments as $comment): ?><li><a class="wcf-comment-author" href="<?= h($url . '#comment-' . (int)$comment['id']) ?>"><?= h($comment['author_name']) ?></a><?= render_comment_identity($comment, ['post' => $post, 'comments' => $comments]) ?><span class="wcf-comment-meta"><?= render_comment_meta($comment, ['post' => $post, 'comments' => $comments]) ?></span><?php if (trim((string)$comment['reply_to_name']) !== ''): ?> 回复 <a class="wcf-comment-author" href="<?= h($url . '#comment-' . (int)$comment['parent_id']) ?>"><?= h($comment['reply_to_name']) ?></a><?php endif; ?>：<a class="wcf-comment-text" href="<?= h($url . '#comment-' . (int)$comment['id']) ?>"><?= nl2br(h(str_sub_u((string)$comment['content'], 0, 320)), false) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
          <?php if ((int)($counts['comments'] ?? 0) > count($comments) && $comments !== []): ?><a class="wcf-comments-more" href="<?= h($url . '#comments') ?>">查看全部 <?= (int)$counts['comments'] ?> 条评论</a><?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </article>
    <?php return (string)ob_get_clean();
}

function wcf_render_feed(array $posts): string
{
    [$counts, $comments] = wcf_feed_data($posts);
    $html = '<div class="wcf-feed" data-wcf-feed>';
    foreach ($posts as $post) { $html .= wcf_render_post($post, $counts[(int)$post['id']] ?? [], $comments[(int)$post['id']] ?? []); }
    return $html . ($posts === [] ? '<p class="wcf-empty">还没有动态，期待下一次分享。</p>' : '') . '</div>';
}

function wcf_pagination(): string
{
    $page = max(1, (int)($_GET['p'] ?? 1));
    $pages = max(1, (int)ceil(count_published_posts() / max(1, (int)setting('posts_per_page', '6'))));
    if ($pages <= 1) { return ''; }
    $numbered = theme_setting('pagination_mode', 'load_more') === 'pagination';
    $html = '<nav class="wcf-pagination" data-wcf-pagination aria-label="动态分页">';
    if ($page > 1) { $html .= '<a rel="prev" href="' . h(wcf_preview_url(home_page_url($page - 1))) . '">上一页</a>'; }
    if ($numbered) {
        $numbers = array_unique(array_merge([1], range(max(1, $page - 2), min($pages, $page + 2)), [$pages]));
        $previous = 0;
        foreach ($numbers as $number) {
            if ($previous > 0 && $number > $previous + 1) { $html .= '<span aria-hidden="true">…</span>'; }
            $html .= $number === $page ? '<span aria-current="page">' . $number . '</span>'
                : '<a href="' . h(wcf_preview_url(home_page_url($number))) . '" aria-label="第 ' . $number . ' 页">' . $number . '</a>';
            $previous = $number;
        }
    } else {
        $html .= '<span>' . $page . ' / ' . $pages . '</span>';
    }
    if ($page < $pages) { $html .= '<a rel="next" href="' . h(wcf_preview_url(home_page_url($page + 1))) . '">下一页</a>'; }
    $html .= '</nav>';
    if ($numbered) { return $html; }
    if ($page < $pages) { $html .= '<button class="wcf-load-more" type="button" data-wcf-load-more hidden>加载更多</button>'; }
    return $html . '<p class="wcf-load-status" data-wcf-load-status role="status" aria-live="polite">' . ($page === $pages ? '已经到底了' : '') . '</p>';
}

function wcf_links_html(): string
{
    $html = '<div class="wcf-links-grid">';
    $links = all_rows('SELECT * FROM links ORDER BY sort_order ASC, id DESC');
    foreach ($links as $link) {
        $url = safe_link_url((string)$link['url']);
        if ($url === '#') { continue; }
        $icon = wcf_media_url((string)$link['icon_url']);
        $html .= '<a class="wcf-friend" href="' . h($url) . '" target="_blank" rel="noopener noreferrer"><span class="wcf-friend-avatar">' . h(str_sub_u((string)$link['name'], 0, 1))
            . ($icon !== '' ? '<img src="' . h($icon) . '" width="40" height="40" alt="" loading="lazy">' : '')
            . '</span><span><strong>' . h($link['name']) . '</strong><small>' . h($link['description']) . '</small></span></a>';
    }
    return $html . ($links === [] ? '<p class="wcf-empty">还没有添加朋友。</p>' : '') . '</div>';
}

function wcf_detail_header(string $content): string
{
    $post = fetch_post_by_identifier((string)($_GET['slug'] ?? ''), is_admin());
    if ($post === null) { return $content; }
    $author = wcf_author((int)($post['author_id'] ?? 0));
    $options = wcf_post_options($post);
    $id = (int)$post['id'];
    $opening = '<article class="post-detail wcf-detail-post" data-post-id="' . $id . '"><a class="wcf-post-avatar wcf-detail-avatar" href="' . h(wcf_url('home')) . '" aria-label="' . h($author['name']) . '"><img src="' . h(wcf_avatar()) . '" alt="" width="36" height="36"></a>'
        . '<div class="wcf-detail-main" data-wcf-titles="' . h(theme_setting('show_post_title', '0')) . '"><div class="wcf-detail-author"><a class="wcf-post-author" href="' . h(wcf_url('home')) . '">' . h($author['name']) . '</a>'
        . (!empty($post['is_pinned']) ? '<span class="wcf-pin">置顶</span>' : '')
        . ($options['advertise'] ? '<span class="wcf-ad">广告</span>' : '') . '</div>';
    $content = preg_replace_callback('~<article class="post-detail">~', static fn(): string => $opening, $content, 1) ?? $content;
    $public = !content_requires_password($post) || content_password_is_unlocked($post);
    $likes = $public && wcf_likes_available() ? (article_likes_states([$id])[$id] ?? null) : null;
    if ($public) {
        $actions = '<div class="wcf-post-actions-wrap"><button class="wcf-actions-toggle" type="button" data-wcf-actions aria-controls="wcf-detail-actions-' . $id . '" aria-expanded="false" aria-label="点赞与评论">' . wcf_icon('more', 16) . '</button>'
            . '<div class="wcf-actions-menu" id="wcf-detail-actions-' . $id . '" hidden>' . ($likes !== null ? article_likes_button($id, $likes) : '') . '<a href="#comments">' . wcf_icon('comment', 16) . '评论</a></div></div>';
        $content = preg_replace_callback('~(<div class="meta">)(.*?)(</div>)~s', static fn(array $match): string => $match[1] . $match[2] . $actions . $match[3], $content, 1) ?? $content;
    }
    $location = $options['position'] !== '' ? '<div class="wcf-post-location wcf-detail-location">'
        . ($options['position_url'] !== '#' ? '<a href="' . h($options['position_url']) . '" target="_blank" rel="noopener noreferrer">' . h($options['position']) . '</a>' : h($options['position'])) . '</div>' : '';
    $reaction = $likes !== null && $likes['count'] > 0 ? '<div class="wcf-reactions wcf-detail-reactions"><div class="wcf-likes">' . wcf_icon('heart', 14) . '<span>' . (int)$likes['count'] . ' 人赞过</span></div></div>' : '';
    return preg_replace_callback('~</article>~', static fn(): string => $location . $reaction . '</div></article>', $content, 1) ?? $content;
}

add_theme_filter('body_class', static fn(string $classes): string => $classes . ' wcf-theme');
add_theme_filter('content', static function (string $content, array $context): string {
    $action = (string)($_GET['a'] ?? 'home');
    if ($context['active'] === 'home' && $context['title'] === $context['site_name'] && $action === 'home') {
        $perPage = max(1, (int)setting('posts_per_page', '6'));
        return wcf_render_feed(fetch_published_posts($perPage, (max(1, (int)($_GET['p'] ?? 1)) - 1) * $perPage)) . wcf_pagination();
    }
    if ($action === 'tag' || $action === 'category') {
        if ($action === 'tag') { $posts = fetch_posts_by_tag_slug((string)($_GET['slug'] ?? '')); }
        else {
            $category = one('SELECT id FROM categories WHERE slug = ?', [(string)($_GET['slug'] ?? '')]);
            $posts = all_rows('SELECT * FROM posts WHERE kind = ? AND category_id = ? AND status = ? AND published_at <= ? ORDER BY is_pinned DESC, published_at DESC, id DESC', ['post', (int)($category['id'] ?? 0), 'published', time()]);
            $posts = array_map('public_content_context', $posts);
        }
        return '<header class="wcf-list-heading"><h1>' . h($context['title']) . '</h1><p>' . count($posts) . ' 条动态</p></header>' . wcf_render_feed($posts);
    }
    if ($context['active'] === 'links') { return '<header class="wcf-list-heading"><h1>朋友们</h1></header>' . wcf_links_html(); }
    // Keep the core's password/reply gates, comment feedback and plugin output intact.
    $content = wcf_detail_music($content);
    $content = preg_replace_callback('~(<img\b[^>]*class="comment-item__avatar"[^>]*src=")([^"]+)(")~i', static function (array $match): string {
        $base = wcf_media_url(theme_setting('gravatar_url'));
        $old = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($base !== '' && preg_match('~/avatar/([a-f0-9]{32})(\?[^"\s]*)?~i', $old, $avatar)) {
            return $match[1] . h(rtrim($base, '/') . '/avatar/' . $avatar[1] . ($avatar[2] ?? '?s=72&d=identicon')) . $match[3];
        }
        return $match[0];
    }, $content) ?? $content;
    $isPost = $action === 'post' && str_contains($content, '<article class="post-detail">');
    if ($isPost) { $content = wcf_detail_header($content); }
    return '<div class="wcf-page-content' . ($isPost ? ' wcf-page-content--post' : '') . '">' . $content . '</div>';
});
