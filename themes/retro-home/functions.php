<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

function retro_home_option(string $key, string $fallback = ''): string
{
    return theme_setting($key, $fallback, 'retro-home');
}

function retro_home_email(): string
{
    $email = trim(retro_home_option('contact_email'));
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : '';
}

/** Carry previews only onto local public pages, preserving anchors and existing queries. */
function retro_home_url(string $url): string
{
    if (($_GET['theme_preview'] ?? '') !== 'retro-home' || !is_admin() || $url === '' || $url[0] === '#') { return $url; }
    $parts = parse_url($url);
    if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || str_starts_with($url, '//')) { return $url; }
    parse_str((string)($parts['query'] ?? ''), $query);
    $action = $query['a'] ?? '';
    $path = (string)($parts['path'] ?? '');
    if (isset($query['theme_preview']) || !is_string($action)
        || ($action !== '' && !in_array($action, ['home', 'post', 'page', 'archives', 'tags', 'tag', 'categories', 'category', 'links'], true))
        || preg_match('#/(?:admin|login|logout|forgot-password|reset-password|write|edit)(?:/|$)#', $path)
        || (preg_match('/\.[a-z0-9]+$/i', $path) && basename($path) !== 'index.php')) { return $url; }
    $fragment = isset($parts['fragment']) ? '#' . $parts['fragment'] : '';
    if ($fragment !== '') { $url = substr($url, 0, -strlen($fragment)); }
    return $url . (str_contains($url, '?') ? '&' : '?') . 'theme_preview=retro-home' . $fragment;
}

function retro_home_preview_links(string $html): string
{
    if (($_GET['theme_preview'] ?? '') !== 'retro-home' || !is_admin()) { return $html; }
    return preg_replace_callback('/\bhref="([^"]*)"/', static function (array $match): string {
        return 'href="' . h(retro_home_url(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'))) . '"';
    }, $html) ?? $html;
}

/** Public dates and counts only; do not count drafts or scheduled content. */
function retro_home_stats(): array
{
    static $stats;
    return $stats ??= one(
        'SELECT MIN(published_at) AS first_published, MAX(updated_at) AS last_updated,
         MAX(CASE WHEN kind = ? THEN published_at ELSE 0 END) AS latest_post,
         COALESCE(SUM(CASE WHEN kind = ? THEN views ELSE 0 END), 0) AS reads
         FROM posts WHERE status = ? AND published_at <= ?',
        ['post', 'post', 'published', time()]
    ) ?? [];
}

function retro_home_post_list(array $posts): string
{
    if (!$posts) { return '<p class="retro-empty">' . h(sblog_t('还没有已发布的文章。')) . '</p>'; }
    $latestTimestamp = (int)(retro_home_stats()['latest_post'] ?? 0);
    $newMarked = false;
    ob_start(); ?>
    <ul class="log">
      <?php foreach ($posts as $post): $timestamp = (int)$post['published_at']; ?>
      <li>
        <time class="date" datetime="<?= h(date('Y-m-d', $timestamp)) ?>"><?= h(date('Y.m.d', $timestamp)) ?></time>
        <a class="log-title" href="<?= h(retro_home_url(content_permalink($post))) ?>"><?= h($post['title']) ?></a>
        <?php if (!empty($post['is_pinned'])): ?><span class="tag"><?= h(sblog_t('置顶')) ?></span>
        <?php elseif (!$newMarked && $timestamp === $latestTimestamp && $timestamp >= time() - 7 * 86400): $newMarked = true; ?><span class="tag">NEW</span><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php return (string)ob_get_clean();
}

function retro_home_pagination(int $page, int $totalPages): string
{
    if ($totalPages <= 1) { return ''; }
    ob_start(); ?>
    <nav class="retro-pagination mono" aria-label="<?= h(sblog_t('文章分页')) ?>">
      <?php if ($page > 1): ?><a rel="prev" href="<?= h(retro_home_url(home_page_url($page - 1))) ?>" aria-label="<?= h(sblog_t('上一页')) ?>" title="<?= h(sblog_t('上一页')) ?>">←</a><?php endif; ?>
      <span aria-label="<?= h(sblog_t('第 {page} / {pages} 页', ['page' => $page, 'pages' => $totalPages])) ?>"><?= $page ?> / <?= $totalPages ?></span>
      <?php if ($page < $totalPages): ?><a rel="next" href="<?= h(retro_home_url(home_page_url($page + 1))) ?>" aria-label="<?= h(sblog_t('下一页')) ?>" title="<?= h(sblog_t('下一页')) ?>">→</a><?php endif; ?>
    </nav>
    <?php return (string)ob_get_clean();
}

function retro_home_index(): string
{
    $page = max(1, (int)($_GET['p'] ?? 1));
    $perPage = max(1, (int)setting('posts_per_page', '6'));
    $posts = fetch_published_posts($perPage, ($page - 1) * $perPage);
    $totalPages = max(1, (int)ceil(count_published_posts() / $perPage));
    $email = retro_home_email();
    ob_start(); ?>
    <?php if ($page === 1 && (retro_home_option('welcome_title') !== '' || retro_home_option('welcome_text') !== '' || retro_home_option('welcome_note') !== '')): ?>
    <section class="retro-section" aria-labelledby="welcome-heading">
      <h2 id="welcome-heading"><span class="num mono">00</span>欢迎</h2>
      <p class="welcome"><span class="lead"><?= h(retro_home_option('welcome_title')) ?></span><?= nl2br(h(retro_home_option('welcome_text')), false) ?></p>
      <?php if (retro_home_option('welcome_note') !== ''): ?><p><?= nl2br(h(retro_home_option('welcome_note')), false) ?></p><?php endif; ?>
    </section>
    <?php endif; ?>
    <section class="retro-section" id="log" aria-labelledby="log-heading">
      <div class="retro-section-heading">
        <h2 id="log-heading"><span class="num mono">01</span><?= $page === 1 ? '最近更新' : '往日记录' ?></h2>
        <?= retro_home_pagination($page, $totalPages) ?>
      </div>
      <?= retro_home_post_list($posts) ?>
    </section>
    <?php if ($page === 1 && retro_home_option('show_profile', '1') === '1'): ?>
    <section class="retro-section" id="about" aria-labelledby="about-heading">
      <h2 id="about-heading"><span class="num mono">02</span>关于我</h2>
      <table class="profile-table" aria-label="个人资料">
        <tbody>
          <tr><th scope="row">网名</th><td><?= h(trim(retro_home_option('profile_name')) ?: setting('site_name')) ?></td></tr>
          <?php foreach (['profile_location' => '坐标', 'profile_doing' => '在做', 'profile_likes' => '喜欢'] as $key => $label): ?>
          <?php if (trim(retro_home_option($key)) !== ''): ?><tr><th scope="row"><?= h($label) ?></th><td><?= h(retro_home_option($key)) ?></td></tr><?php endif; ?>
          <?php endforeach; ?>
          <?php if ($email !== ''): ?><tr><th scope="row">联系</th><td><a href="mailto:<?= h($email) ?>"><?= h($email) ?></a></td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>
    <?php endif; ?>
    <?php if ($page === 1 && retro_home_option('show_links', '1') === '1'):
        $links = all_rows('SELECT name, url, description FROM links ORDER BY sort_order ASC, id DESC LIMIT 7');
        $hasMoreLinks = count($links) > 6; ?>
    <section class="retro-section" id="links" aria-labelledby="links-heading">
      <h2 id="links-heading"><span class="num mono">03</span>友情链接 / 常去的地方</h2>
      <?php if ($links): ?>
      <ul class="links">
        <?php foreach (array_slice($links, 0, 6) as $link): $href = safe_link_url((string)$link['url']); ?>
        <li><?php if ($href !== '#'): ?><a href="<?= h($href) ?>" target="_blank" rel="noopener noreferrer"><?= h($link['name']) ?></a><?php else: ?><?= h($link['name']) ?><?php endif; ?><?php if (trim((string)$link['description']) !== ''): ?><span class="desc">— <?= h($link['description']) ?></span><?php endif; ?></li>
        <?php endforeach; ?>
        <?php if ($hasMoreLinks): ?><li><a href="<?= h(retro_home_url(url_for('links'))) ?>">所有友情链接</a><span class="desc">— 更多常去的地方</span></li><?php endif; ?>
        <?php if ($email !== ''): ?><li><a href="#guestbook">交换链接</a><span class="desc">— 你也有主页？给我留个地址吧</span></li><?php endif; ?>
      </ul>
      <?php else: ?><p class="retro-empty"><?= h(sblog_t('还没有添加友情链接。')) ?></p><?php endif; ?>
    </section>
    <?php endif; ?>
    <?php return (string)ob_get_clean();
}

/** Leave the core's gated detail content, forms and plugin comment identity HTML intact. */
function retro_home_content(string $content, string $action, int $status): string
{
    if ($status >= 400) { return $content; }
    if ($action === 'home') { return retro_home_index(); }
    if ($action === 'archives') {
        $groups = [];
        foreach (fetch_archive_posts() as $post) { $groups[date('Y', (int)$post['published_at'])][] = $post; }
        $html = '<h1 class="post-title">' . h(sblog_t('归档')) . '</h1>';
        foreach ($groups as $year => $posts) {
            $html .= '<section class="retro-section"><h2><span class="num mono">' . h($year) . '</span>更新日志</h2>' . retro_home_post_list($posts) . '</section>';
        }
        return $html . (!$groups ? '<p class="retro-empty">' . h(sblog_t('归档还是空的。')) . '</p>' : '');
    }
    return $content;
}
