<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

function argon_options(): array
{
    static $options;
    if (isset($options)) { return $options; }
    $defaults = [
        'primary_color' => '#5e72e4', 'background_color' => '#f4f5f7', 'darkmode' => 'auto',
        'banner_title' => '', 'banner_subtitle' => '', 'banner_background' => '',
        'banner_height' => 0, 'banner_shape' => true, 'avatar' => '', 'author_name' => '',
        'author_description' => '', 'announcement' => '', 'sidebar_left' => true,
        'sidebar_right' => false, 'show_excerpt' => true, 'excerpt_length' => 120,
        'show_reading_time' => true, 'show_post_navigation' => true, 'show_toc' => true,
        'card_radius' => 4, 'navbar_title' => '', 'footer_text' => '', 'footer_since' => (int)date('Y'),
        'custom_css' => '', 'social_github' => '', 'social_email' => '', 'social_rss' => true,
        'font_family' => 'sans', 'card_shadow' => 'small', 'immersion_color' => false, 'amoled_dark' => false,
    ];
    // Native settings are authoritative after the first save. Keep old plugin
    // values readable until then, without writing during public requests.
    $stored = function_exists('theme_settings') ? theme_settings('argon') : [];
    if (!is_array(json_decode(setting('theme_settings_argon', ''), true))) {
        $legacy = json_decode(setting('argon_options', '{}'), true);
        if (is_array($legacy) && $legacy) { $stored = $legacy; }
    }
    $options = $defaults;
    if (!is_array($stored)) { return $options; }
    foreach ($defaults as $key => $default) {
        $value = $stored[$key] ?? $default;
        if (is_bool($default)) { $options[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN); }
        elseif (is_int($default)) { $options[$key] = is_numeric($value) ? (int)$value : $default; }
        else { $options[$key] = is_string($value) ? $value : $default; }
    }
    foreach (['primary_color', 'background_color'] as $key) {
        if (!preg_match('/^#[a-f0-9]{6}$/i', $options[$key])) { $options[$key] = $defaults[$key]; }
    }
    foreach (['banner_background', 'avatar', 'social_github'] as $key) { $options[$key] = argon_safe_url($options[$key]); }
    $options['darkmode'] = in_array($options['darkmode'], ['auto', 'light', 'dark'], true) ? $options['darkmode'] : 'auto';
    $options['font_family'] = in_array($options['font_family'], ['sans', 'serif'], true) ? $options['font_family'] : 'sans';
    $options['card_shadow'] = in_array($options['card_shadow'], ['small', 'big'], true) ? $options['card_shadow'] : 'small';
    $options['banner_height'] = $options['banner_height'] === 0 ? 0 : max(200, min(900, $options['banner_height']));
    $options['card_radius'] = max(0, min(24, $options['card_radius']));
    $options['excerpt_length'] = max(40, min(500, $options['excerpt_length']));
    $options['footer_since'] = max(1970, min((int)date('Y'), $options['footer_since']));
    return $options;
}

function argon_safe_url(string $url): string
{
    $url = trim($url);
    if (preg_match('/[\x00-\x20\\\\]/', $url)) { return ''; }
    return valid_http_url($url) || (str_starts_with($url, '/') && !str_starts_with($url, '//')) ? $url : '';
}

function argon_asset(string $path): string
{
    return theme_asset_url($path) . '?v=' . rawurlencode((string)filemtime(__DIR__ . '/' . $path));
}

/** Keep an administrator's theme preview when navigating within this blog. */
function argon_url(string $url): string
{
    if (($_GET['theme_preview'] ?? '') !== 'argon' || !is_admin() || $url === '' || $url[0] === '#') { return $url; }
    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || str_starts_with($url, '//')) { return $url; }
    parse_str($parts['query'] ?? '', $query);
    if (isset($query['theme_preview']) || in_array($query['a'] ?? '', ['rss', 'sitemap', 'submit_comment', 'unlock_content'], true)) { return $url; }
    $path = (string)($parts['path'] ?? '');
    if (preg_match('/\.[a-z0-9]+$/i', $path) && basename($path) !== 'index.php') { return $url; }
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    if ($fragment !== '') { $url = substr($url, 0, -strlen($fragment)); }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'theme_preview=argon' . $fragment;
}

function argon_preview_links(string $html): string
{
    if (($_GET['theme_preview'] ?? '') !== 'argon' || !is_admin()) { return $html; }
    return preg_replace_callback('/\bhref="([^"]*)"/', static fn(array $m): string => 'href="' . h(argon_url(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) . '"', $html) ?? $html;
}

function argon_icon(string $name): string
{
    return '<i class="fa fa-' . h($name) . '" aria-hidden="true"></i>';
}

function argon_categories(): array
{
    static $categories;
    return $categories ??= all_rows('SELECT c.*, COUNT(p.id) AS post_count FROM categories c LEFT JOIN posts p ON p.category_id = c.id AND p.kind = ? AND p.status = ? AND p.published_at <= ? GROUP BY c.id ORDER BY c.id', ['post', 'published', time()]);
}

function argon_plain_text(string $text): string
{
    return trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function argon_post_meta(array $post): string
{
    $items = [];
    if (!empty($post['is_pinned'])) { $items[] = argon_icon('thumb-tack') . ' ' . h(sblog_t('置顶')); }
    $timestamp = (int)(($post['published_at'] ?? 0) ?: ($post['updated_at'] ?? $post['created_at'] ?? 0));
    $items[] = argon_icon('clock-o') . ' <time datetime="' . h(date(DATE_ATOM, $timestamp)) . '">' . h(date('Y-n-j G:i', $timestamp)) . '</time>';
    $items[] = argon_icon('eye') . ' ' . (int)($post['views'] ?? 0);
    $comments = (int)($post['comment_count'] ?? 0);
    $items[] = '<a href="' . h(argon_url(content_permalink($post)) . '#comments') . '">' . argon_icon('comments-o') . ' ' . $comments . '</a>';
    foreach (argon_categories() as $category) {
        if ((int)$category['id'] !== (int)($post['category_id'] ?? 0)) { continue; }
        $items[] = argon_icon('bookmark-o') . ' <a href="' . h(argon_url(url_for('category', ['slug' => $category['slug']]))) . '">' . h($category['name']) . '</a>';
        break;
    }
    if (argon_options()['show_reading_time'] && ($post['content'] ?? '') !== '') {
        $minutes = max(1, (int)ceil(str_len_u(argon_plain_text((string)$post['content'])) / 400));
        $items[] = argon_icon('clock-o') . ' ' . h(sblog_t('{count} 分钟', ['count' => $minutes]));
    }
    return '<div class="post-meta">' . implode(' <span class="post-meta-devide" aria-hidden="true">|</span> ', array_map(static fn(string $item): string => '<span class="post-meta-detail">' . $item . '</span>', $items)) . '</div>';
}

function argon_cover(array $post): string
{
    // Only ever inspect an already-redacted public content context.
    $body = (string)($post['content'] ?? '');
    if (preg_match('/!\[[^\]]*\]\(\s*(<?)([^\s)>]+)>?(?:\s+[^)]*)?\)/', $body, $match)) {
        $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (!preg_match('#^(?:[a-z]+:|//|/)#i', $url)) { $url = asset_url($url); }
        return argon_safe_url($url);
    }
    return '';
}

function argon_posts(array $posts): string
{
    if (!$posts) { return '<div class="post card bg-white shadow-sm border-0 empty-notice">' . h(sblog_t('还没有已发布的文章。')) . '</div>'; }
    $options = argon_options();
    $ids = array_map(static fn(array $post): int => (int)$post['id'], $posts);
    $counts = [];
    foreach (all_rows('SELECT post_id, COUNT(*) AS total FROM comments WHERE status = ? AND post_id IN (' . implode(',', $ids) . ') GROUP BY post_id', ['approved']) as $row) { $counts[(int)$row['post_id']] = (int)$row['total']; }
    ob_start();
    foreach ($posts as $post):
        $post['comment_count'] = $counts[(int)$post['id']] ?? 0;
        $cover = argon_cover($post);
        ?>
        <article class="post post-preview card bg-white shadow-sm border-0" id="post-<?= (int)$post['id'] ?>">
          <header class="post-header text-center<?= $cover !== '' ? ' post-header-with-thumbnail' : '' ?>">
            <?php if ($cover !== ''): ?><img class="post-thumbnail" src="<?= h($cover) ?>" alt="" loading="lazy" decoding="async"><div class="post-header-text-container"><?php endif; ?>
            <h2 class="argon-list-title"><a class="post-title" href="<?= h(argon_url(content_permalink($post))) ?>"><?= h($post['title']) ?></a></h2>
            <?= argon_post_meta($post) ?>
            <?php if ($cover !== ''): ?></div><?php endif; ?>
          </header>
          <?php if ($options['show_excerpt']): $excerpt = argon_plain_text((string)($post['excerpt'] ?? '')); ?><div class="post-content"><?= h(str_sub_u($excerpt, 0, $options['excerpt_length']) . (str_len_u($excerpt) > $options['excerpt_length'] ? '…' : '')) ?></div><?php endif; ?>
          <?php $tags = tag_descriptors($post); if ($tags): ?>
          <div class="post-tags"><?= argon_icon('tags') ?>
            <?php foreach ($tags as $tag): ?><a class="tag badge badge-secondary post-meta-detail-tag" href="<?= h(argon_url(url_for('tag', ['slug' => $tag['slug']]))) ?>"><?= h($tag['label']) ?></a><?php endforeach; ?>
          </div>
          <?php endif; ?>
        </article>
    <?php endforeach;
    return (string)ob_get_clean();
}

function argon_pagination(int $page, int $total, string $query = ''): string
{
    if ($total <= 1) { return ''; }
    $url = static function (int $number) use ($query): string {
        $url = home_page_url($number);
        if ($query !== '') { $url .= (str_contains($url, '?') ? '&' : '?') . 'q=' . rawurlencode($query); }
        return argon_url($url);
    };
    $pages = array_unique([1, max(1, $page - 1), $page, min($total, $page + 1), $total]);
    sort($pages);
    $html = '<nav aria-label="' . h(sblog_t('文章分页')) . '"><ul class="pagination argon-pagination justify-content-center">';
    if ($page > 1) { $html .= '<li class="page-item"><a class="page-link" rel="prev" aria-label="' . h(sblog_t('上一页')) . '" href="' . h($url($page - 1)) . '">‹</a></li>'; }
    $last = 0;
    foreach ($pages as $number) {
        if ($last && $number > $last + 1) { $html .= '<li class="page-item disabled"><span class="page-link">…</span></li>'; }
        $html .= '<li class="page-item' . ($number === $page ? ' active' : '') . '"><a class="page-link"' . ($number === $page ? ' aria-current="page"' : '') . ' href="' . h($url($number)) . '">' . $number . '</a></li>';
        $last = $number;
    }
    if ($page < $total) { $html .= '<li class="page-item"><a class="page-link" rel="next" aria-label="' . h(sblog_t('下一页')) . '" href="' . h($url($page + 1)) . '">›</a></li>'; }
    return $html . '</ul></nav>';
}

function argon_search_posts(string $query): array
{
    // Search only redacted public fields, so protected text never affects matches.
    $posts = array_map('public_content_context', all_rows('SELECT * FROM posts WHERE kind = ? AND status = ? AND published_at <= ? ORDER BY is_pinned DESC, published_at DESC, id DESC', ['post', 'published', time()]));
    $needle = str_lower_u($query);
    return array_values(array_filter($posts, static fn(array $post): bool => str_contains(str_lower_u(argon_plain_text($post['title'] . '\n' . $post['excerpt'] . '\n' . $post['content'])), $needle)));
}

function argon_detail(string $content, string $action): string
{
    $post = fetch_content_by_identifier($action, trim((string)($_GET['slug'] ?? $_GET['id'] ?? '')), is_admin());
    if (!$post) { return $content; }
    $public = public_content_context($post);
    $public['comment_count'] = approved_comment_count((int)$post['id']);
    $header = '<header class="post-header text-center"><h1 class="post-title">' . h($post['title']) . '</h1>';
    if ($action === 'post') { $header .= argon_post_meta($public); }
    if (!is_live_content($post) && is_admin()) { $header .= '<p class="post-meta-detail">' . h(sblog_t('{state}预览', ['state' => post_state($post)['label']])) . '</p>'; }
    $header .= '</header>';
    $content = preg_replace_callback('/\A\s*<article\b[^>]*>\s*<h1\b[^>]*class="post-title"[^>]*>.*?<\/h1>\s*(?:<div class="meta">.*?<\/div>\s*)?/s', static fn(): string => '<article class="post post-full card bg-white shadow-sm border-0">' . $header, $content, 1) ?? $content;
    $content = preg_replace('/class="post-content"/', 'class="post-content" id="post_content"', $content, 1) ?? $content;
    if (!argon_options()['show_post_navigation']) { $content = preg_replace('/<ul class="pagination post-navigation">.*?<\/ul>/s', '', $content, 1) ?? $content; }
    // Keep one .comments root for core reply handling while matching Argon's two cards.
    return preg_replace_callback('/(<section class="comments" id="comments"[^>]*>)(.*?)(<\/section>)/s', static function (array $match): string {
        $formPosition = strpos($match[2], '<form class="comment-form"');
        $list = $formPosition === false ? $match[2] : substr($match[2], 0, $formPosition);
        $form = $formPosition === false ? '' : substr($match[2], $formPosition);
        return $match[1] . '<div class="argon-comment-list-card card shadow-sm border-0"><div class="card-body">' . $list . '</div></div>'
            . ($form !== '' ? '<div id="post_comment" class="card shadow-sm border-0"><div class="card-body">' . $form . '</div></div>' : '') . $match[3];
    }, $content, 1) ?? $content;
}

function argon_archive(): string
{
    ob_start(); ?>
    <article class="post post-full card bg-white shadow-sm border-0">
      <header class="post-header text-center"><h1 class="post-title"><?= h(sblog_t('归档')) ?></h1><div class="post-meta-detail"><?= h(sblog_t('{count} 篇文章', ['count' => count_published_posts()])) ?></div></header>
      <div class="post-content"><div class="argon-timeline archive-timeline">
      <?php $year = ''; foreach (fetch_archive_posts() as $post): $date = (int)$post['published_at']; ?>
        <?php if ($year !== date('Y', $date)): $year = date('Y', $date); ?><div class="argon-timeline-node"><h2 class="argon-timeline-time archive-timeline-year"><?= h($year) ?></h2><div class="argon-timeline-card archive-timeline-title"></div></div><?php endif; ?>
        <div class="argon-timeline-node"><time class="argon-timeline-time" datetime="<?= h(date('Y-m-d', $date)) ?>"><?= h(date('m-d', $date)) ?></time><div class="argon-timeline-card card bg-gradient-secondary archive-timeline-title"><a href="<?= h(argon_url(content_permalink($post))) ?>"><?= h($post['title']) ?></a></div></div>
      <?php endforeach; ?>
      </div></div>
    </article>
    <?php return (string)ob_get_clean();
}

function argon_content(string $content, string $action, int $status): string
{
    if ($status >= 400) { return '<div class="argon-core-page post card bg-white shadow-sm border-0">' . $content . '</div>'; }
    if ($action === 'home') {
        $page = max(1, (int)($_GET['p'] ?? 1));
        $perPage = max(1, (int)setting('posts_per_page', '6'));
        $query = is_string($_GET['q'] ?? null) ? str_sub_u(trim($_GET['q']), 0, 100) : '';
        if ($query !== '') {
            $posts = argon_search_posts($query);
            $title = '<div class="page-information-card card shadow-sm border-0"><h1>' . argon_icon('search') . ' ' . h(sblog_t('搜索')) . '：' . h($query) . '</h1><p>' . count($posts) . ' ' . h(sblog_t('篇文章')) . '</p></div>';
            return $title . argon_posts(array_slice($posts, ($page - 1) * $perPage, $perPage)) . argon_pagination($page, max(1, (int)ceil(count($posts) / $perPage)), $query);
        }
        return argon_posts(fetch_published_posts($perPage, ($page - 1) * $perPage)) . argon_pagination($page, max(1, (int)ceil(count_published_posts() / $perPage)));
    }
    if (in_array($action, ['post', 'page'], true)) { return argon_detail($content, $action); }
    if ($action === 'archives') { return argon_archive(); }
    if ($action === 'categories') {
        $html = '<article class="argon-core-page post post-full card bg-white shadow-sm border-0"><h1 class="post-title">' . h(sblog_t('分类')) . '</h1><div class="category-list">';
        foreach (argon_categories() as $category) {
            $html .= '<div class="category-item"><h2><a href="' . h(argon_url(url_for('category', ['slug' => $category['slug']]))) . '">' . argon_icon('folder-open-o') . ' ' . h($category['name']) . '</a></h2><p>' . (int)$category['post_count'] . ' ' . h(sblog_t('篇文章')) . '</p></div>';
        }
        return $html . '</div></article>';
    }
    if (in_array($action, ['tag', 'category'], true)) {
        $slug = trim((string)($_GET['slug'] ?? ''));
        $posts = $action === 'tag' ? fetch_posts_by_tag_slug($slug) : array_map('public_content_context', all_rows('SELECT p.* FROM posts p JOIN categories c ON c.id = p.category_id WHERE c.slug = ? AND p.kind = ? AND p.status = ? AND p.published_at <= ? ORDER BY p.is_pinned DESC, p.published_at DESC, p.id DESC', [$slug, 'post', 'published', time()]));
        preg_match('/<h1\b[^>]*class="post-title"[^>]*>(.*?)<\/h1>/s', $content, $heading);
        return '<div class="page-information-card card shadow-sm border-0"><h1>' . ($heading[1] ?? '') . '</h1><p>' . count($posts) . ' ' . h(sblog_t('篇文章')) . '</p></div>' . argon_posts($posts);
    }
    return '<article class="argon-core-page post post-full card bg-white shadow-sm border-0">' . $content . '</article>';
}
