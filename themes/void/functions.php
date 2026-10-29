<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }
require_once __DIR__ . '/content.php';

function sbvoid_config(): array
{
    static $config;
    return $config ??= require __DIR__ . '/config.php';
}

function sbvoid_url(string $url): string
{
    if (($_GET['theme_preview'] ?? '') === 'void' && is_admin()) {
        return $url . (str_contains($url, '?') ? '&' : '?') . 'theme_preview=void';
    }
    return $url;
}

function sbvoid_asset(string $path): string
{
    return theme_asset_url($path) . '?v=' . rawurlencode((string)filemtime(__DIR__ . '/' . $path));
}

function sbvoid_search_term(): string
{
    return str_sub_u(trim(is_string($_GET['void_search'] ?? null) ? $_GET['void_search'] : ''), 0, 120);
}

function sbvoid_image_url(string $url): string
{
    $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if (preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { return ''; }
    return valid_http_url($url) || (str_starts_with($url, '/') && safe_link_url($url) !== '#') ? $url : '';
}

function sbvoid_cover(array $post, ?array $config = null): string
{
    if (empty(($config ?? sbvoid_config())['auto_cover'])) { return ''; }
    // Never infer images from password-protected or reply-hidden source text.
    $post = public_content_context($post);
    $content = (string)($post['content'] ?? '');
    if (preg_match('/!\[[^\]]*\]\(\s*<?([^\s)>]+)>?(?:\s+["\'][^"\']*["\'])?\s*\)/u', $content, $match)
        || preg_match('/<img\b[^>]*\bsrc=["\']([^"\']+)["\']/i', $content, $match)) {
        $url = sbvoid_image_url($match[1]);
        if ($url !== '') { return $url; }
        if (!str_contains($match[1], ':') && !str_starts_with($match[1], '//')) {
            return sbvoid_image_url(asset_url(ltrim($match[1], '/')));
        }
    }
    return '';
}

/** A self-contained title illustration; never reads article content or calls a remote service. */
function sbvoid_title_cover(string $title, bool $showTitle = true): string
{
    $title = trim(preg_replace('/[\x00-\x1f\x7f\s]+/u', ' ', $title) ?? '');
    if ($title === '') { $title = '未命名文章'; }
    // Bound pathological combining sequences while keeping the string valid UTF-8.
    if (preg_match('/\A.{0,512}/us', $title, $bounded)) { $title = $bounded[0]; }
    $hash = hash('sha256', $title);
    $palettes = [
        ['#e7eeeb', '#243e35', '#8ba99a'],
        ['#eee7df', '#503b2d', '#c09c7c'],
        ['#e4ebf2', '#293d54', '#8ea9c2'],
        ['#efe5e8', '#593d47', '#c297a5'],
        ['#eae6f0', '#483956', '#aa9cbd'],
        ['#e9ecdf', '#3d482e', '#a2ad7b'],
    ];
    [$background, $ink, $accent] = $palettes[hexdec(substr($hash, 0, 2)) % count($palettes)];
    $escape = static fn(string $text): string => htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_DISALLOWED | ENT_XML1, 'UTF-8');
    $lines = [];
    $line = '';
    $width = 0;
    preg_match_all('/\X/u', $title, $characters);
    $characters = array_slice($characters[0], 0, 180);
    $shortTitle = implode('', $characters);
    foreach ($characters as $character) {
        $advance = preg_match('/^[MWmw@#%&]$/', $character) ? 1
            : (preg_match('/^[A-Z]$/', $character) ? .75 : (preg_match('/^[\x20-\x7e]$/', $character) ? .6 : 1));
        if ($width + $advance > 13 && $line !== '') {
            $lines[] = trim($line);
            if (count($lines) === 3) {
                $lines[2] = (preg_replace('/\X$/u', '', $lines[2]) ?? $lines[2]) . '…';
                $line = '';
                break;
            }
            $line = '';
            $width = 0;
        }
        $line .= $character;
        $width += $advance;
    }
    if (trim($line) !== '') { $lines[] = trim($line); }
    $text = '';
    if ($showTitle) {
        foreach ($lines as $index => $part) {
            $text .= '<text x="72" y="' . (270 + $index * 80) . '">' . $escape($part) . '</text>';
        }
    }
    $circleY = 70 + hexdec(substr($hash, 2, 2));
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="960" height="600" viewBox="0 0 960 600">'
        . '<title>' . $escape($shortTitle) . '</title>'
        . '<rect width="960" height="600" fill="' . $background . '"/>'
        . '<g fill="' . $accent . '"><circle cx="940" cy="' . $circleY . '" r="270" opacity=".28"/>'
        . '<circle cx="840" cy="600" r="200" opacity=".16"/></g>'
        . '<circle cx="940" cy="' . $circleY . '" r="195" fill="none" stroke="' . $background . '" stroke-width="2"/>'
        . '<path d="M72 168h52" stroke="' . $ink . '" stroke-width="5"/>'
        . '<g fill="' . $ink . '" font-family="PingFang SC,Microsoft YaHei,Noto Sans CJK SC,sans-serif">'
        . '<text x="72" y="86" font-size="20" letter-spacing="5" opacity=".6">VOID / NOTES</text>'
        . '<g font-size="60" font-weight="600">' . $text . '</g>'
        . '<path d="M72 522h816" stroke="' . $ink . '" opacity=".15"/>'
        . '<text x="72" y="560" font-size="18" letter-spacing="3" opacity=".55">READ &amp; REFLECT</text>'
        . '<text x="888" y="560" text-anchor="end" font-size="18" font-family="monospace" opacity=".55">'
        . strtoupper(substr($hash, 0, 6)) . '</text></g></svg>';
    return 'data:image/svg+xml;base64,' . base64_encode($svg);
}

/** List-only fallbacks leave the article header and private-content projection unchanged. */
function sbvoid_list_cover(array $post, ?array $config = null): array
{
    $config ??= sbvoid_config();
    if ((int)($config['cover_style'] ?? 1) === 0) { return ['src' => '', 'fallback' => '']; }
    $default = sbvoid_image_url((string)($config['default_cover'] ?? ''));
    $generated = ($config['title_cover'] ?? true)
        ? sbvoid_title_cover((string)($post['title'] ?? ''), (int)($config['cover_style'] ?? 1) !== 2)
        : '';
    $cover = sbvoid_cover($post, $config);
    $fallback = $default !== '' ? $default : $generated;
    if ($cover !== '') { return ['src' => $cover, 'fallback' => $cover !== $fallback ? $fallback : $generated]; }
    return ['src' => $fallback, 'fallback' => $default !== '' && $default !== $generated ? $generated : ''];
}

function sbvoid_word_count(array $post): int
{
    $post = public_content_context($post);
    $text = trim(strip_tags((string)($post['content'] ?? '')));
    preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}]|[\p{L}\p{N}]+/u', $text, $words);
    return count($words[0]);
}

function sbvoid_categories(): array
{
    return all_rows('SELECT c.*, COUNT(p.id) AS post_count FROM categories c LEFT JOIN posts p ON p.category_id = c.id AND p.kind = ? AND p.status = ? AND p.published_at > 0 AND p.published_at <= ? GROUP BY c.id ORDER BY c.sort_order ASC, c.id DESC', ['post', 'published', time()]);
}

function sbvoid_cards(array $posts): string
{
    $config = sbvoid_config();
    ob_start(); ?>
    <section id="index-list" class="float-up"><ul id="masonry">
    <?php foreach ($posts as $post):
        $post = public_content_context($post);
        $cover = sbvoid_list_cover($post, $config);
        $style = $cover['src'] === '' ? 0 : max(0, min(2, (int)$config['cover_style']));
        $time = (int)($post['published_at'] ?: $post['created_at']);
        $excerpt = trim((string)($post['excerpt'] ?? '')) ?: derive_excerpt((string)($post['content'] ?? ''), 100);
        $excerpt = preg_replace('/(?:^|\s)#{1,6}\s+/u', ' ', $excerpt) ?? $excerpt;
        $excerpt = derive_excerpt($excerpt, 80);
        $wordCount = sbvoid_word_count($post);
        ?>
        <li id="p-<?= (int)$post['id'] ?>" class="masonry-item style-<?= $style ?> done">
          <a href="<?= h(sbvoid_url(content_permalink($post))) ?>">
            <article class="yue">
              <?php if ($cover['src'] !== '' && $style !== 0): ?><div class="banner"><img src="<?= h($cover['src']) ?>"<?php if ($cover['fallback'] !== ''): ?> data-void-cover-fallback="<?= h($cover['fallback']) ?>"<?php endif; ?> width="960" height="600" alt="" loading="lazy" decoding="async"></div><?php endif; ?>
              <div class="content-wrap">
                <div class="post-meta-index"><time datetime="<?= h(date(DATE_ATOM, $time)) ?>"><?= h(date('M d, Y', $time)) ?></time><?php if ($config['show_word_count'] && $wordCount > 0): ?><span class="word-count"> + <?= $wordCount ?> 字</span><?php endif; ?><?php if (!empty($post['is_pinned'])): ?><span class="void-pinned"> · 置顶</span><?php endif; ?></div>
                <h1 class="title"><?= h($post['title']) ?></h1>
                <div class="articleBody"><p><?= h($excerpt) ?></p></div>
              </div>
            </article>
          </a>
        </li>
    <?php endforeach; ?>
    </ul></section>
    <?php return (string)ob_get_clean();
}

function sbvoid_pager(int $page, int $totalPages, string $search = ''): string
{
    if ($totalPages <= 1) { return ''; }
    $url = static function (int $number) use ($search): string {
        $url = home_page_url($number);
        if ($search !== '') { $url .= (str_contains($url, '?') ? '&' : '?') . 'void_search=' . rawurlencode($search); }
        return sbvoid_url($url);
    };
    $html = '<nav aria-label="文章分页"><ul class="pager">';
    if ($page > 1) { $html .= '<li><a rel="prev" aria-label="上一页" href="' . h($url($page - 1)) . '">←</a></li>'; }
    $last = 0;
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($i !== 1 && $i !== $totalPages && abs($i - $page) > 2) { continue; }
        if ($last > 0 && $i - $last > 1) { $html .= '<li><span>…</span></li>'; }
        $html .= '<li' . ($i === $page ? ' class="current"' : '') . '><a' . ($i === $page ? ' aria-current="page"' : '') . ' href="' . h($url($i)) . '">' . $i . '</a></li>';
        $last = $i;
    }
    if ($page < $totalPages) { $html .= '<li><a rel="next" aria-label="下一页" href="' . h($url($page + 1)) . '">→</a></li>'; }
    return $html . '</ul></nav>';
}

function sbvoid_archives(): string
{
    $groups = [];
    foreach (fetch_archive_posts() as $post) { $groups[date('Y', (int)$post['published_at'])][] = $post; }
    ob_start(); ?>
    <div class="tag-cloud yue float-up"><h2>Tags</h2><?php foreach (tag_index_data() as $tag): ?><a class="tag-item" href="<?= h(sbvoid_url(url_for('tag', ['slug' => $tag['slug']]))) ?>" title="<?= (int)$tag['count'] ?> 篇文章"><?= h($tag['label']) ?></a><?php endforeach; ?></div>
    <section id="archive-list" class="yue float-up">
    <?php $index = 0; foreach ($groups as $year => $posts): ?>
      <h2><?= h($year) ?> <span class="num-posts"><?= count($posts) ?> 篇</span><button type="button" class="toggle-archive" data-void-archive aria-expanded="<?= $index === 0 ? 'true' : 'false' ?>" aria-controls="year-<?= h($year) ?>" aria-label="展开或收起 <?= h($year) ?> 年文章"><?= $index === 0 ? '−' : '+' ?></button></h2>
      <section class="year" id="year-<?= h($year) ?>"<?= $index > 0 ? ' hidden' : '' ?>><ul><?php foreach ($posts as $post): ?><li><a class="archive-title" href="<?= h(sbvoid_url(content_permalink($post))) ?>"><span class="date"><?= h(date('m-d', (int)$post['published_at'])) ?></span><?= h($post['title']) ?></a></li><?php endforeach; ?></ul></section>
    <?php $index++; endforeach; ?>
    <?php if (!$groups): ?><p class="empty-notice">归档还是空的。</p><?php endif; ?>
    </section>
    <?php return (string)ob_get_clean();
}

/** Rearrange rendered HTML so core gates, feedback and plugin output survive intact. */
function sbvoid_detail(string $html): array
{
    if (!class_exists(DOMDocument::class)) {
        // Preserve the complete core response on hosts without the optional DOM extension.
        $html = preg_replace('/<h1\b[^>]*class="post-title"[^>]*>.*?<\/h1>/s', '', $html, 1) ?? $html;
        $metaHtml = '';
        // Move only the leading core metadata; leave body markup and content gates intact.
        $html = preg_replace_callback('/\A(\s*<article\b[^>]*>\s*)(<div\b[^>]*\sclass="(?:[^"]*\s)?meta(?:\s[^"]*)?"[^>]*>(?:(?!<\/?div\b).)*<\/div>)/s', static function (array $match) use (&$metaHtml): string {
            $metaHtml = $match[2];
            return $match[1];
        }, $html, 1) ?? $html;
        $html = str_replace('class="post-content"', 'class="post-content articleBody full"', $html);
        return ['<div class="post yue">' . $html . '</div>', $metaHtml, ''];
    }
    $document = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $document->loadHTML('<?xml encoding="UTF-8"><div id="void-fragment">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($document);
    $root = $document->getElementById('void-fragment');
    $metaHtml = '';
    $firstTitle = $xpath->query('.//h1[contains(concat(" ", normalize-space(@class), " "), " post-title ")]', $root)->item(0);
    if ($firstTitle) { $firstTitle->parentNode->removeChild($firstTitle); }
    $article = $xpath->query('.//article[1]', $root)->item(0);
    if ($article instanceof DOMElement) {
        $article->setAttribute('class', trim($article->getAttribute('class') . ' post yue'));
        $meta = $xpath->query('./div[contains(concat(" ", normalize-space(@class), " "), " meta ")]', $article)->item(0);
        if ($meta) {
            $metaHtml = $document->saveHTML($meta);
            $meta->parentNode->removeChild($meta);
        }
    }
    foreach ($xpath->query('.//*[contains(concat(" ", normalize-space(@class), " "), " post-content ")]', $root) as $node) {
        $node->setAttribute('class', $node->getAttribute('class') . ' articleBody full');
    }
    foreach ($xpath->query('.//ul[contains(concat(" ", normalize-space(@class), " "), " post-navigation ")]//a', $root) as $link) {
        $label = trim($link->textContent);
        $postTitle = $link->getAttribute('data-post-title');
        if ($postTitle === '') {
            // Some core versions only include the neighbor title in the accessible label.
            foreach (['：', ':'] as $separator) {
                $prefix = $label . $separator;
                if ($label !== '' && str_starts_with($link->getAttribute('aria-label'), $prefix)) {
                    $postTitle = ltrim(substr($link->getAttribute('aria-label'), strlen($prefix)));
                    break;
                }
            }
        }
        if ($postTitle === '') { continue; }
        $link->setAttribute('class', trim($link->getAttribute('class') . ' void-post-nav-link'));
        while ($link->firstChild) { $link->removeChild($link->firstChild); }
        foreach (['void-post-nav-label' => $label, 'void-post-nav-title' => $postTitle] as $class => $text) {
            $span = $document->createElement('span');
            $span->setAttribute('class', $class);
            $span->appendChild($document->createTextNode($text));
            $link->appendChild($span);
        }
    }
    sbvoid_enhance_body($root);
    $comments = $xpath->query('.//section[@id="comments"]', $root)->item(0);
    $commentsHtml = $comments ? $document->saveHTML($comments) : '';
    if ($comments) { $comments->parentNode->removeChild($comments); }
    $body = '';
    foreach ($root->childNodes as $child) { $body .= $document->saveHTML($child); }
    return [$body, $metaHtml, $commentsHtml];
}

function sbvoid_page(string $content, string $title, int $status = 200): array
{
    $action = (string)($GLOBALS['sblog_current_action'] ?? 'home');
    $config = sbvoid_config();
    $page = ['type' => $action, 'title' => $title, 'body' => $content, 'meta' => '', 'comments' => '', 'post' => null, 'banner' => sbvoid_image_url((string)$config['banner']), 'wide' => false];
    if ($status >= 400) {
        $page['type'] = 'error';
        $page['banner'] = '';
        return $page;
    }
    if ($action === 'home') {
        $search = sbvoid_search_term();
        $number = max(1, (int)($_GET['p'] ?? 1));
        $perPage = max(1, (int)setting('posts_per_page', '6'));
        $total = count_published_posts();
        if ($search !== '') {
            // Match only the public projection, including stripping reply-hidden blocks.
            $posts = array_map('public_content_context', all_rows('SELECT * FROM posts WHERE kind = ? AND status = ? AND published_at > 0 AND published_at <= ? ORDER BY is_pinned DESC, published_at DESC, id DESC', ['post', 'published', time()]));
            $needle = str_lower_u($search);
            $posts = array_values(array_filter($posts, static fn(array $post): bool => str_contains(str_lower_u($post['title'] . ' ' . ($post['excerpt'] ?? '') . ' ' . strip_tags((string)($post['content'] ?? ''))), $needle)));
            $total = count($posts);
            $posts = array_slice($posts, ($number - 1) * $perPage, $perPage);
            $page['type'] = 'search';
            $page['title'] = '包含关键字 “' . $search . '” 的文章';
            $page['meta'] = '<span>' . $total . ' 篇文章</span>';
        } else {
            $posts = fetch_published_posts($perPage, ($number - 1) * $perPage);
        }
        $page['wide'] = $config['index_style'] !== 'single';
        $page['body'] = $posts ? sbvoid_cards($posts) : '<section class="void-empty"><p>' . ($search !== '' ? '没有找到相关文章，换个关键词试试。' : '还没有已发布的文章。') . '</p><a class="btn" href="' . h(sbvoid_url(url_for('home'))) . '">返回首页</a></section>';
        $page['body'] .= sbvoid_pager($number, max(1, (int)ceil($total / $perPage)), $search);
    } elseif (in_array($action, ['post', 'page'], true)) {
        [$page['body'], $page['meta'], $page['comments']] = sbvoid_detail($content);
        $post = fetch_content_by_identifier($action, trim((string)($_GET['slug'] ?? '')), is_admin());
        $page['post'] = $post;
        $page['banner'] = $post && $config['post_banner'] ? sbvoid_cover($post) : '';
        if ($post) {
            $author = one('SELECT username, nickname FROM users WHERE id = ?', [(int)($post['author_id'] ?? 0)]) ?? [];
            $authorName = trim((string)($author['nickname'] ?? '')) ?: (string)($author['username'] ?? setting('site_name'));
            $timestamp = (int)($post['published_at'] ?: $post['created_at']);
            $page['meta'] = '<span>' . h($authorName) . '</span><span aria-hidden="true">•</span><time datetime="' . h(date(DATE_ATOM, $timestamp)) . '">' . h(date('Y-m-d', $timestamp)) . '</time>';
            if ($page['comments'] !== '') {
                $page['meta'] .= '<span aria-hidden="true">•</span><a href="#comments">' . approved_comment_count((int)$post['id']) . ' 评论</a>';
            }
            $page['meta'] .= '<span aria-hidden="true">•</span><span>' . (int)($post['views'] ?? 0) . ' 阅读</span>';
            if (is_admin() && !is_live_content($post)) { $page['meta'] .= '<span>预览</span>'; }
        }
        if ($post && !(content_requires_password($post) && !content_password_is_unlocked($post))) {
            $words = sbvoid_word_count($post);
            if ($config['show_reading_time']) { $page['meta'] .= '<span class="void-reading-time">' . $words . ' 字 · 约 ' . max(1, (int)ceil($words / 300)) . ' 分钟</span>'; }
        }
    } elseif ($action === 'archives') {
        $page['body'] = sbvoid_archives();
        $page['meta'] = count(sbvoid_categories()) . ' 分类 × ' . count_published_posts() . ' 文章 × ' . count(tag_index_data()) . ' 标签';
    } elseif ($action === 'tag' || $action === 'category') {
        $slug = trim((string)($_GET['slug'] ?? ''));
        if ($action === 'tag') { $posts = fetch_posts_by_tag_slug($slug); }
        else {
            $posts = array_map('public_content_context', all_rows('SELECT p.* FROM posts p JOIN categories c ON p.category_id = c.id WHERE c.slug = ? AND p.kind = ? AND p.status = ? AND p.published_at > 0 AND p.published_at <= ? ORDER BY p.is_pinned DESC, p.published_at DESC, p.id DESC', [$slug, 'post', 'published', time()]));
        }
        $page['wide'] = $config['index_style'] !== 'single';
        $page['meta'] = count($posts) . ' 篇文章';
        $page['body'] = $posts ? sbvoid_cards($posts) : '<section class="void-empty">还没有文章。</section>';
    } else {
        // The banner already displays this heading.
        $page['body'] = preg_replace('/<h1\b[^>]*class="post-title"[^>]*>.*?<\/h1>/s', '', $content, 1) ?? $content;
    }
    return $page;
}

function sbvoid_json(array $data): string
{
    return (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

add_theme_filter('document_title', static function (string $title): string {
    $search = sbvoid_search_term();
    return $search !== '' && ($GLOBALS['sblog_current_action'] ?? '') === 'home' ? '搜索：' . $search . ' · ' . setting('site_name') : $title;
});
