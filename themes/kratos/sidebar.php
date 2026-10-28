<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

function kratos_sidebar(): string
{
    $config = kratos_config();
    $author = kratos_author();
    ob_start();
    foreach ($config['sidebar'] as $widget):
        if ($widget === 'about'):
            $background = kratos_image_url((string)$config['about_background']) ?: kratos_asset('assets/img/about-background.png'); ?>
    <div class="widget w-about">
      <div class="background" style="background:url('<?= h($background) ?>') no-repeat center center;background-size:cover"></div>
      <div class="wrapper text-center"><a href="<?= h(url_for(is_admin() ? 'admin' : 'login')) ?>" aria-label="<?= is_admin() ? '管理博客' : '登录博客' ?>"><img src="<?= h($author['avatar_url']) ?>" alt="<?= h($author['name']) ?>" width="100" height="100" loading="lazy"></a></div>
      <div class="textwidget text-center"><p class="username"><?= h($author['name']) ?></p><p class="about"><?= nl2br(h($author['signature'])) ?></p></div>
    </div>
        <?php elseif ($widget === 'posts'):
            $limit = max(1, min(20, (int)$config['sidebar_posts']));
            $new = fetch_published_posts($limit, 0);
            $hot = all_rows('SELECT p.id, p.slug, p.title, p.kind FROM posts p LEFT JOIN comments c ON c.post_id = p.id AND c.status = ? WHERE p.kind = ? AND p.status = ? AND p.published_at > 0 AND p.published_at <= ? AND p.published_at >= ? GROUP BY p.id ORDER BY COUNT(c.id) DESC, p.views DESC, p.published_at DESC LIMIT ' . $limit, ['approved', 'post', 'published', time(), time() - 30 * 86400]);
            $random = all_rows('SELECT id, slug, title, kind FROM posts WHERE kind = ? AND status = ? AND published_at > 0 AND published_at <= ? ORDER BY RANDOM() LIMIT ' . $limit, ['post', 'published', time()]);
            $groups = ['new' => ['最新', 'tabnew', $new], 'hot' => ['热点', 'tabhot', $hot], 'random' => ['随机', 'tabrandom', $random]]; ?>
    <div class="widget w-recommended">
      <div class="nav nav-tabs" role="tablist" aria-label="文章聚合">
        <?php foreach ($groups as $key => [$label, $icon, $posts]): ?>
        <a class="nav-item nav-link<?= $key === 'hot' ? ' active' : '' ?>" id="nav-<?= h($key) ?>-tab" href="#nav-<?= h($key) ?>" role="tab" aria-controls="nav-<?= h($key) ?>" aria-selected="<?= $key === 'hot' ? 'true' : 'false' ?>"<?= $key === 'hot' ? '' : ' tabindex="-1"' ?> data-kratos-tab><i class="kicon i-<?= h($icon) ?> d-none d-xl-inline" aria-hidden="true"></i><?= h($label) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="tab-content">
        <?php foreach ($groups as $key => [$label, $icon, $posts]): ?>
        <div class="tab-pane fade<?= $key === 'hot' ? ' show active' : '' ?>" id="nav-<?= h($key) ?>" role="tabpanel" aria-labelledby="nav-<?= h($key) ?>-tab"<?= $key === 'hot' ? '' : ' hidden' ?>>
          <?php foreach ($posts as $post): ?><a class="bookmark-item" href="<?= h(kratos_url(content_permalink($post))) ?>" title="<?= h($post['title']) ?>"><i class="kicon i-book" aria-hidden="true"></i><?= h($post['title']) ?></a><?php endforeach; ?>
          <?php if (!$posts): ?><p class="mb-0 py-2">暂无文章</p><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
        <?php elseif ($widget === 'tags'):
            $tags = array_slice(tag_index_data(), 0, max(1, min(100, (int)$config['sidebar_tags']))); ?>
    <div class="widget w-tags"><div class="title">标签聚合</div><div class="item">
      <?php foreach ($tags as $tag): ?><a href="<?= h(kratos_url(url_for('tag', ['slug' => $tag['slug']]))) ?>" title="<?= h($tag['count']) ?> 篇文章"><?= h($tag['label']) ?></a><?php endforeach; ?>
      <?php if (!$tags): ?><span>暂无标签</span><?php endif; ?>
    </div></div>
        <?php elseif ($widget === 'categories'):
            $categories = all_rows('SELECT c.name, c.slug, COUNT(p.id) AS post_count FROM categories c JOIN posts p ON p.category_id = c.id AND p.kind = ? AND p.status = ? AND p.published_at > 0 AND p.published_at <= ? GROUP BY c.id ORDER BY c.sort_order, c.id DESC', ['post', 'published', time()]); ?>
    <div class="widget widget_categories"><div class="title">文章分类</div><ul>
      <?php foreach ($categories as $category): ?><li><a href="<?= h(kratos_url(url_for('category', ['slug' => $category['slug']]))) ?>"><?= h($category['name']) ?></a> (<?= (int)$category['post_count'] ?>)</li><?php endforeach; ?>
    </ul></div>
        <?php elseif ($widget === 'comments'):
            $limit = max(1, min(20, (int)($config['sidebar_comments'] ?? 5)));
            // Only comments whose parent content is publicly readable belong in
            // the sidebar; no drafts, future content or password-protected posts.
            $comments = all_rows(
                'SELECT c.id, c.author_name, c.author_email, c.content, c.created_at, p.slug, p.kind
                 FROM comments c JOIN posts p ON p.id = c.post_id
                 WHERE c.status = ? AND p.kind IN (?, ?) AND p.status = ?
                   AND p.published_at > 0 AND p.published_at <= ?
                   AND COALESCE(p.content_password_hash, ?) = ?
                 ORDER BY c.created_at DESC, c.id DESC LIMIT ' . $limit,
                ['approved', 'post', 'page', 'published', time(), '', '']
            ); ?>
    <div class="widget w-comments"><div class="title">最近评论</div><div class="comments">
      <?php foreach ($comments as $comment):
          $nickname = trim((string)$comment['author_name']) ?: '匿名';
          $message = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$comment['content'])) ?? '');
          if (str_len_u($message) > 50) { $message = str_sub_u($message, 0, 50) . '...'; }
          $avatar = kratos_image_url(gravatar_url((string)$comment['author_email'], 60)) ?: kratos_asset('assets/img/gravatar.png');
          $timestamp = (int)$comment['created_at']; ?>
      <a href="<?= h(kratos_url(content_permalink($comment)) . '#comment-' . (int)$comment['id']) ?>">
        <div class="meta clearfix">
          <div class="avatar float-left"><img src="<?= h($avatar) ?>" width="50" height="50" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer"></div>
          <div class="profile d-block"><span class="date"><?= h($nickname) ?> 发布于 <?= h(friendly_public_date($timestamp)) ?>（<?= h(date('m月d日', $timestamp)) ?>）</span><span class="message d-block"><?= h($message) ?></span></div>
        </div>
      </a>
      <?php endforeach; ?>
      <?php if (!$comments): ?><p class="mb-0">暂无评论</p><?php endif; ?>
    </div></div>
        <?php elseif ($widget === 'search'): ?>
    <div class="widget w-search"><div class="title">搜索</div><div class="item"><?= kratos_search_form('search-widgets', true) ?></div></div>
        <?php elseif ($widget === 'toc' && in_array($GLOBALS['sblog_current_action'] ?? '', ['post', 'page'], true)): ?>
    <div class="widget w-toc" data-kratos-toc hidden><div class="title">文章目录</div><div class="item"></div></div>
        <?php endif;
    endforeach;
    return (string)ob_get_clean();
}
