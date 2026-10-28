<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

/** Public presentation data only; article bodies always remain core-rendered. */
function &kratos_stats_cache(): array
{
    static $cache = [];
    return $cache;
}

function kratos_prime_post_stats(array $posts): void
{
    $cache = &kratos_stats_cache();
    $ids = [];
    foreach ($posts as $post) {
        $id = (int)($post['id'] ?? 0);
        if ($id > 0 && !isset($cache[$id])) { $ids[$id] = $id; }
    }
    if (!$ids) { return; }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    foreach (all_rows(
        'SELECT p.id, p.views, c.name AS category_name, c.slug AS category_slug,
            (SELECT COUNT(*) FROM comments WHERE post_id = p.id AND status = ?) AS comments,
            (SELECT COUNT(*) FROM post_likes WHERE post_id = p.id) AS likes
         FROM posts p LEFT JOIN categories c ON c.id = p.category_id
         WHERE p.id IN (' . $placeholders . ')',
        array_merge(['approved'], array_values($ids))
    ) as $row) { $cache[(int)$row['id']] = $row; }
}

function kratos_post_stats(array $post): array
{
    kratos_prime_post_stats([$post]);
    $cache = &kratos_stats_cache();
    return $cache[(int)($post['id'] ?? 0)] ?? ['views' => 0, 'comments' => 0, 'likes' => 0, 'category_name' => '', 'category_slug' => ''];
}

/** Call with public_content_context() data so protected images cannot leak. */
function kratos_thumbnail(array $post): string
{
    $body = (string)($post['content'] ?? '');
    $images = [];
    if (preg_match('/<img\b[^>]*\bsrc\s*=\s*([\'"])(.*?)\1/is', $body, $match, PREG_OFFSET_CAPTURE)) {
        $images[(int)$match[0][1]] = html_entity_decode($match[2][0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if (preg_match('/!\[[^\]]*\]\(\s*(?:<([^>]+)>|([^\s)]+))[^)]*\)/', $body, $match, PREG_OFFSET_CAPTURE)) {
        $images[(int)$match[0][1]] = (string)(($match[1][0] ?? '') !== '' ? $match[1][0] : ($match[2][0] ?? ''));
    }
    ksort($images, SORT_NUMERIC);
    foreach ($images as $image) {
        $url = kratos_image_url($image);
        if ($url !== '') { return $url; }
    }
    return kratos_asset('assets/img/default.jpg');
}

function kratos_excerpt(array $post): string
{
    $length = max(1, (int)(kratos_config()['excerpt_length'] ?? 260));
    $body = (string)($post['content'] ?? '');
    $excerpt = trim((string)($post['excerpt'] ?? ''));
    // The public API supplies a generated 140-character excerpt. Expand that
    // from its already-sanitized body to match Kratos' default excerpt length.
    if ($body !== '' && ($excerpt === '' || $excerpt === derive_excerpt($body))) {
        return derive_excerpt($body, $length);
    }
    return str_len_u($excerpt) > $length ? rtrim(str_sub_u($excerpt, 0, $length)) . '…' : $excerpt;
}

function kratos_cards(array $posts): string
{
    if (!$posts) { return kratos_nothing(); }
    kratos_prime_post_stats($posts);
    $config = kratos_config();
    ob_start();
    foreach ($posts as $post):
        $stats = kratos_post_stats($post);
        $author = kratos_author($post);
        $name = trim((string)($author['nickname'] ?? '')) ?: (string)($author['username'] ?? 'Admin');
        $url = kratos_url(content_permalink($post));
        $thumbnail = (bool)($config['thumbnail'] ?? true);
        $date = (int)($post['published_at'] ?? 0);
    ?>
    <article class="article-panel">
      <span class="a-card d-none d-md-block d-lg-block" aria-hidden="true">
        <?php if (!empty($post['is_pinned'])): ?><i class="kicon i-card-top"></i><?php elseif ((int)$stats['comments'] >= 20 || (int)$stats['likes'] >= 200): ?><i class="kicon i-card-hot"></i><?php endif; ?>
      </span>
      <?php if ($thumbnail): ?>
      <div class="a-thumb"><a href="<?= h($url) ?>" tabindex="-1" aria-hidden="true"><img src="<?= h(kratos_thumbnail($post)) ?>" alt="" loading="lazy" decoding="async"></a></div>
      <?php endif; ?>
      <div class="a-post<?= $thumbnail ? '' : ' a-none' ?>">
        <div class="header">
          <?php if ((string)($stats['category_name'] ?? '') !== ''): ?><a class="label" href="<?= h(kratos_url(url_for('category', ['slug' => $stats['category_slug']]))) ?>"><?= h($stats['category_name']) ?><i class="label-arrow" aria-hidden="true"></i></a><?php else: ?><span class="label"><?= h(sblog_t('未分类')) ?><i class="label-arrow" aria-hidden="true"></i></span><?php endif; ?>
          <h3 class="title"><a href="<?= h($url) ?>"><?= h($post['title']) ?></a></h3>
        </div>
        <div class="content"><p><?= h(kratos_excerpt($post)) ?></p></div>
      </div>
      <div class="a-meta">
        <span class="float-left d-none d-md-block">
          <span class="mr-2"><i class="kicon i-calendar" aria-hidden="true"></i><time datetime="<?= h(date(DATE_ATOM, $date)) ?>"><?= h(date('Y-m-d', $date)) ?></time></span>
          <?php if ($config['show_comments'] ?? true): ?><span class="mr-2"><i class="kicon i-comments" aria-hidden="true"></i><?= (int)$stats['comments'] ?>条评论</span><?php endif; ?>
        </span>
        <span class="float-left d-block">
          <?php if ($config['show_views'] ?? true): ?><span class="mr-2"><i class="kicon i-hot" aria-hidden="true"></i><?= (int)$stats['views'] ?>点热度</span><?php endif; ?>
          <?php if ($config['show_likes'] ?? true): ?><span class="mr-2"><i class="kicon i-good" aria-hidden="true"></i><?= (int)$stats['likes'] ?>人点赞</span><?php endif; ?>
          <?php if ($config['show_author'] ?? true): ?><span class="mr-2"><i class="kicon i-author" aria-hidden="true"></i><?= h($name) ?></span><?php endif; ?>
        </span>
        <span class="float-right"><a href="<?= h($url) ?>">阅读全文<i class="kicon i-rightbutton" aria-hidden="true"></i></a></span>
      </div>
    </article>
    <?php endforeach;
    return (string)ob_get_clean();
}

function kratos_nothing(): string
{
    return '<div class="article-panel"><div class="nothing"><img src="' . h(kratos_asset('assets/img/nothing.svg')) . '" alt=""><div class="sorry">很抱歉，没有找到任何内容</div></div></div>';
}

function kratos_pagination(int $page, int $pages, string $search = ''): string
{
    if ($pages <= 1) { return ''; }
    $url = static function (int $number) use ($search): string {
        $url = home_page_url($number);
        if ($search !== '') { $url = url_with_query($url, ['s' => $search]); }
        return kratos_url($url);
    };
    $numbers = [1, $pages];
    for ($i = max(1, $page - 2); $i <= min($pages, $page + 2); $i++) { $numbers[] = $i; }
    $numbers = array_unique($numbers);
    sort($numbers, SORT_NUMERIC);
    ob_start(); ?>
    <nav class="paginations" aria-label="<?= h(sblog_t('文章分页')) ?>">
      <?php if ($page > 1): ?><a class="prev" rel="prev" href="<?= h($url($page - 1)) ?>" aria-label="<?= h(sblog_t('上一页')) ?>"><i class="kicon i-larrows" aria-hidden="true"></i></a><?php endif; ?>
      <?php $last = 0; foreach ($numbers as $number): ?>
      <?php if ($last && $number > $last + 1): ?><span class="page-numbers dots">…</span><?php endif; ?>
      <?php if ($number === $page): ?><span class="page-numbers current" aria-current="page"><?= $number ?></span><?php else: ?><a href="<?= h($url($number)) ?>"><?= $number ?></a><?php endif; ?>
      <?php $last = $number; endforeach; ?>
      <?php if ($page < $pages): ?><a class="next" rel="next" href="<?= h($url($page + 1)) ?>" aria-label="<?= h(sblog_t('下一页')) ?>"><i class="kicon i-rarrows" aria-hidden="true"></i></a><?php endif; ?>
    </nav>
    <?php return (string)ob_get_clean();
}

/** Change the shell around the core-rendered body, preserving all gates/hooks. */
function kratos_detail(string $content, string $action): string
{
    $post = fetch_content_by_identifier($action, trim((string)($_GET['slug'] ?? $_GET['id'] ?? '')), is_admin());
    if (!$post) { return $content; }
    $isPost = $action === 'post';
    $stats = kratos_post_stats($post);
    $config = kratos_config();
    $locked = content_requires_password($post) && !content_password_is_unlocked($post);
    $timestamp = (int)($post['published_at'] ?: $post['updated_at'] ?: $post['created_at']);
    ob_start(); ?>
    <article class="article<?= $isPost ? '' : ' py-4' ?>">
      <?php if ($isPost): ?>
      <div class="breadcrumb-box"><ol class="breadcrumb"><li class="breadcrumb-item"><a class="text-dark" href="<?= h(kratos_url(url_for('home'))) ?>">首页</a></li><?php if (!empty($stats['category_slug'])): ?><li class="breadcrumb-item"><a class="text-dark" href="<?= h(kratos_url(url_for('category', ['slug' => $stats['category_slug']]))) ?>"><?= h($stats['category_name']) ?></a></li><?php endif; ?><li class="breadcrumb-item active" aria-current="page">正文</li></ol></div>
      <?php endif; ?>
      <header class="header<?= $isPost ? '' : ' text-center' ?>">
        <h1 class="title<?= $isPost ? '' : ' m-0' ?>"><?= h($post['title']) ?></h1>
        <?php if ($isPost): ?>
        <div class="meta">
          <span><time datetime="<?= h(date(DATE_ATOM, $timestamp)) ?>"><?= h(date('Y-m-d', $timestamp)) ?></time></span>
          <?php if ($config['show_views'] ?? true): ?><span><?= (int)$stats['views'] ?>点热度</span><?php endif; ?>
          <?php if ($config['show_likes'] ?? true): ?><span><span data-kratos-like-count><?= (int)$stats['likes'] ?></span>人点赞</span><?php endif; ?>
          <?php if ($config['show_comments'] ?? true): ?><span><a href="#comments"><?= (int)$stats['comments'] ?>条评论</a></span><?php endif; ?>
          <?php if (is_admin()): ?><span><a href="<?= h(url_for('edit', ['id' => $post['id']])) ?>">编辑文章</a></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (!is_live_content($post) && is_admin()): $state = post_state($post); ?><p class="meta"><?= h(sblog_t('{state}预览', ['state' => (string)$state['label']])) ?></p><?php endif; ?>
      </header>
      <div class="content post-content" id="lightgallery" itemprop="articleBody">
    <?php $opening = (string)ob_get_clean();
    // This pattern matches only the generated opening, never headings in a body.
    $replaced = 0;
    $content = preg_replace_callback(
        '/\A\s*<article\b[^>]*>\s*<h1\b[^>]*class="post-title"[^>]*>.*?<\/h1>\s*(?:<div class="meta">.*?<\/div>\s*)?<div class="post-content"[^>]*>\s*/s',
        static fn(array $match): string => $opening,
        $content, 1, $replaced
    ) ?? $content;
    if ($replaced !== 1) { return $content; }
    // The outer article closes after its core-rendered body and before the
    // navigation/comments. Comment content is escaped and cannot add articles.
    $end = strrpos($content, '</article>');
    if ($end === false) { return $content; }
    $body = substr($content, 0, $end);
    $tail = substr($content, $end + strlen('</article>'));
    $body = preg_replace('/\s*<div class="post-tags">\s*<nav class="nav tags">.*?<\/nav>\s*<\/div>\s*$/s', '', $body, 1) ?? $body;
    $footer = '';
    if ($isPost && !$locked) {
        $tags = tag_descriptors($post);
        $footer = '<footer class="footer clearfix"><div class="tags float-left"><span>标签：</span>';
        foreach ($tags as $tag) { $footer .= '<a href="' . h(kratos_url(url_for('tag', ['slug' => $tag['slug']]))) . '">' . h($tag['label']) . '</a>'; }
        if (!$tags) { $footer .= '<a>暂无</a>'; }
        $footer .= '</div><div class="tool float-right d-none d-lg-block"><span>最后更新：' . h(date('Y-m-d', (int)($post['updated_at'] ?: $timestamp))) . '</span></div></footer>';
    }
    if ($isPost) {
        $tail = preg_replace('/\A\s*<ul class="pagination post-navigation">.*?<\/ul>\s*/s', '', $tail, 1) ?? $tail;
        $tools = !$locked ? kratos_toolbar($post) . kratos_post_navigation($post) : '';
        return $body . $footer . '</article>' . $tools . $tail;
    }
    return $body . '</article>' . $tail;
}

function kratos_toolbar(array $post): string
{
    $author = kratos_author($post);
    $name = trim((string)($author['nickname'] ?? '')) ?: (string)($author['username'] ?? 'Admin');
    $avatar = kratos_image_url((string)($author['avatar_url'] ?? '')) ?: kratos_asset('assets/img/gravatar.png');
    $signature = trim((string)($author['signature'] ?? '')) ?: '这个人很懒，什么都没留下';
    $liked = visitor_liked_post((int)$post['id']);
    ob_start(); ?>
    <div class="toolbar clearfix">
      <div class="meta float-md-left"><img src="<?= h($avatar) ?>" alt="" loading="lazy"><p class="name"><?= h($name) ?></p><p class="motto mb-0"><?= h($signature) ?></p></div>
      <div class="share float-md-right text-center"><button type="button" class="btn btn-thumbs<?= $liked ? ' done' : '' ?>" data-kratos-like data-url="<?= h(url_for('like_post')) ?>" data-post-id="<?= (int)$post['id'] ?>" data-token="<?= h(csrf_token()) ?>" aria-pressed="<?= $liked ? 'true' : 'false' ?>"<?= $liked ? ' disabled' : '' ?>><i class="kicon i-like" aria-hidden="true"></i><span class="ml-1"><?= $liked ? '已点赞' : '点赞' ?></span></button><span class="kratos-like-message sr-only" aria-live="polite"></span></div>
    </div>
    <?php return (string)ob_get_clean();
}

function kratos_post_navigation(array $post): string
{
    $neighbors = post_neighbors($post);
    if (!$neighbors['newer'] && !$neighbors['older']) { return ''; }
    ob_start(); ?>
    <nav class="navigation post-navigation clearfix" aria-label="文章导航">
      <?php if ($neighbors['newer']): ?><div class="nav-previous clearfix"><a rel="prev" title="<?= h($neighbors['newer']['title']) ?>" href="<?= h(kratos_url(content_permalink($neighbors['newer']))) ?>">&lt; 上一篇</a></div><?php endif; ?>
      <?php if ($neighbors['older']): ?><div class="nav-next"><a rel="next" title="<?= h($neighbors['older']['title']) ?>" href="<?= h(kratos_url(content_permalink($neighbors['older']))) ?>">下一篇 &gt;</a></div><?php endif; ?>
    </nav>
    <?php return (string)ob_get_clean();
}

function kratos_error(string $content, int $status): string
{
    if ($status !== 404) { return kratos_page_shell($content); }
    return '<div class="page404"><div class="thumbnail" style="background-image:url(' . h(kratos_asset('assets/img/404.jpg')) . ')"><div class="overlay"></div></div><div class="content text-center"><h1 class="title pt-4">很抱歉，你访问的页面不存在</h1><div class="subtitle pt-4">可能是输入地址有误或该地址已被删除</div><div class="action pt-4"><button type="button" data-kratos-back class="btn btn-outline-primary back-prevpage">返回上页</button><a href="' . h(kratos_url(url_for('home'))) . '" class="btn btn-outline-primary ml-3 back-index">返回主页</a></div></div></div>';
}

function kratos_page_shell(string $content): string
{
    $heading = '';
    $content = preg_replace_callback('/\A\s*(?:<article\b[^>]*>\s*)?<h1\b[^>]*class="post-title"[^>]*>(.*?)<\/h1>\s*/s', static function (array $match) use (&$heading): string {
        $heading = '<header class="header text-center"><h1 class="title m-0">' . $match[1] . '</h1></header>';
        return '';
    }, $content, 1) ?? $content;
    // Links are the only built-in index wrapped in an outer article.
    if (preg_match('/<\/article>\s*$/s', $content)) { $content = preg_replace('/<\/article>\s*$/s', '', $content, 1) ?? $content; }
    return '<article class="article py-4">' . $heading . '<div class="content kratos-page-content">' . $content . '</div></article>';
}

function kratos_content(string $content, string $action, int $status): string
{
    if ($status >= 400) { return kratos_error($content, $status); }
    if ($action === 'home') {
        $page = max(1, (int)($_GET['p'] ?? 1));
        $perPage = max(1, (int)setting('posts_per_page', '6'));
        $search = is_string($_GET['s'] ?? null) ? str_sub_u(trim($_GET['s']), 0, 100) : '';
        if ($search !== '') {
            $result = kratos_search_posts($search, $perPage, ($page - 1) * $perPage);
            $posts = $result['posts'];
            $total = (int)$result['total'];
            $title = '<div class="article-panel"><div class="search-title">搜索内容：' . h($search) . '</div></div>';
        } else {
            $posts = fetch_published_posts($perPage, ($page - 1) * $perPage);
            $total = count_published_posts();
            $title = '';
        }
        return $title . kratos_cards($posts) . kratos_pagination($page, max(1, (int)ceil($total / $perPage)), $search);
    }
    if (in_array($action, ['post', 'page'], true)) { return kratos_detail($content, $action); }
    if (in_array($action, ['tag', 'category'], true)) {
        $slug = trim((string)($_GET['slug'] ?? ''));
        if ($action === 'tag') { $posts = fetch_posts_by_tag_slug($slug); }
        else {
            $posts = all_rows('SELECT p.* FROM posts p JOIN categories c ON c.id = p.category_id WHERE c.slug = ? AND p.kind = ? AND p.status = ? AND p.published_at > 0 AND p.published_at <= ? ORDER BY p.is_pinned DESC, p.published_at DESC, p.id DESC', [$slug, 'post', 'published', time()]);
            $posts = array_map('public_content_context', $posts);
        }
        preg_match('/<h1\b[^>]*class="post-title"[^>]*>(.*?)<\/h1>/s', $content, $heading);
        $title = '<div class="article-panel"><div class="search-title">' . ($heading[1] ?? '') . '</div></div>';
        return $title . kratos_cards($posts);
    }
    return kratos_page_shell($content);
}
