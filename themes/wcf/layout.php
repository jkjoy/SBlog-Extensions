<?php
declare(strict_types=1);
if (!defined('THEMES_DIR')) { http_response_code(403); exit; }
$wcfHome = $active === 'home' && $title === $siteName && ($_GET['a'] ?? 'home') === 'home';
$wcfName = trim(theme_setting('profile_name')) ?: $siteName;
$wcfDescription = trim(theme_setting('profile_description')) ?: (setting('site_description') ?: setting('site_tagline'));
$wcfCover = wcf_media_url(theme_setting('top_image'));
$wcfVideo = wcf_media_url(theme_setting('top_video'));
$wcfAvatarLink = safe_link_url(theme_setting('avatar_link'));
$wcfAppearance = theme_setting('appearance', 'light');
$wcfSearch = array_map(static fn(array $post): array => [
    'title' => (string)$post['title'], 'url' => wcf_preview_url(content_permalink($post)), 'excerpt' => (string)($post['excerpt'] ?? ''),
], fetch_published_posts(2000, 0));
$wcfBeian = trim(theme_setting('beian_info')) ?: trim(setting('footer_beian'));
?>
<!doctype html>
<html lang="<?= h(sblog_i18n_locale()) ?>" data-wcf-theme="<?= $wcfAppearance === 'dark' ? 'dark' : 'light' ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="description" content="<?= h($description) ?>">
  <?php if ($keywords !== ''): ?><meta name="keywords" content="<?= h($keywords) ?>"><?php endif; ?>
  <meta name="color-scheme" content="light dark">
  <meta name="theme-color" content="#f0f0f0" id="wcf-theme-color">
  <title><?= h($fullTitle) ?></title>
  <link rel="icon" href="<?= h(theme_favicon_url()) ?>">
  <link rel="alternate" type="application/rss+xml" title="<?= h($siteName) ?>" href="<?= h(url_for('rss')) ?>">
  <script>(()=>{try{const s=localStorage.getItem('wcf-theme'),d=<?= json_encode($wcfAppearance) ?>;document.documentElement.dataset.wcfTheme=s==='light'||s==='dark'?s:d==='auto'?(matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light'):d;}catch(_){}})();</script>
  <?php theme_action('head', $themeContext); ?>
  <?php if (trim(theme_setting('custom_css')) !== ''): ?><style><?= str_ireplace('</style', '<\/style', theme_setting('custom_css')) ?></style><?php endif; ?>
  <?php if ($customHeadCode !== ''): ?><?= $customHeadCode ?><?php endif; ?>
</head>
<body class="<?= h($bodyClass) ?><?= $wcfHome ? ' wcf-home' : '' ?>" data-wcf-appearance="<?= h($wcfAppearance) ?>" data-wcf-pagination-mode="<?= h(theme_setting('pagination_mode', 'load_more')) ?>">
<?php theme_action('body_open', $themeContext); ?>
<a class="wcf-skip-link" href="#content">跳到正文</a>
<div class="wcf-shell">
  <?php theme_action('header_before', $themeContext); ?>
  <header class="wcf-cover">
    <?php if ($wcfVideo !== ''): ?><video class="wcf-cover-video" src="<?= h($wcfVideo) ?>"<?= $wcfCover !== '' ? ' poster="' . h($wcfCover) . '"' : '' ?> autoplay muted loop playsinline aria-label="朋友圈封面视频"></video><?php elseif ($wcfCover !== ''): ?><img class="wcf-cover-image" src="<?= h($wcfCover) ?>" alt="" fetchpriority="high"><?php endif; ?>
    <nav class="wcf-topbar" aria-label="朋友圈工具">
      <div class="wcf-topbar-left">
        <?php if (!$wcfHome): ?><a class="wcf-icon-button" href="<?= h(wcf_url('home')) ?>" aria-label="返回朋友圈" title="返回朋友圈"><?= wcf_icon('back') ?></a><?php endif; ?>
        <a class="wcf-icon-button" href="<?= h(url_for($admin ? 'admin' : 'login')) ?>" aria-label="<?= $admin ? '管理博客' : '登录' ?>" title="<?= $admin ? '管理博客' : '登录' ?>"><?= wcf_icon('user') ?></a>
        <button class="wcf-icon-button" type="button" data-wcf-dialog-open="wcf-links" aria-label="朋友们" title="朋友们"><?= wcf_icon('users') ?></button>
      </div>
      <div class="wcf-topbar-right">
        <?php if ($admin): ?><a class="wcf-icon-button" href="<?= h(safe_link_url(theme_setting('edit_page_url')) !== '#' ? safe_link_url(theme_setting('edit_page_url')) : url_for('write')) ?>" aria-label="发表动态" title="发表动态"><?= wcf_icon('edit') ?></a><?php endif; ?>
        <button class="wcf-icon-button" type="button" data-wcf-dialog-open="wcf-settings" aria-label="打开设置" title="设置"><?= wcf_icon('settings') ?></button>
      </div>
    </nav>
    <div class="wcf-profile">
      <div class="wcf-profile-user">
        <a class="wcf-profile-name" href="<?= h(wcf_url('home')) ?>"><?= h($wcfName) ?></a>
        <?php if ($wcfAvatarLink !== '#'): ?><a class="wcf-profile-avatar" href="<?= h($wcfAvatarLink) ?>" aria-label="<?= h($wcfName) ?>"><?php else: ?><span class="wcf-profile-avatar"><?php endif; ?><img src="<?= h(wcf_avatar()) ?>" alt="<?= h($wcfName) ?>" width="60" height="60"><?php if ($wcfAvatarLink !== '#'): ?></a><?php else: ?></span><?php endif; ?>
      </div>
      <?php if ($wcfDescription !== ''): ?><p class="wcf-profile-description"><?= nl2br(h($wcfDescription), false) ?></p><?php endif; ?>
    </div>
  </header>
  <?php theme_action('header_after', $themeContext); ?>
  <main class="wcf-content" id="content" tabindex="-1">
    <?php if ($flash): ?><div class="wcf-notice" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
    <?php theme_action('content_before', $themeContext); ?>
    <?= $content ?>
    <?php theme_action('content_after', $themeContext); ?>
  </main>
  <?php theme_action('footer_before', $themeContext); ?>
  <footer class="wcf-footer"><a href="<?= h(wcf_url('archives')) ?>">时光足迹</a><span aria-hidden="true"> · </span><a href="<?= h(url_for('rss')) ?>">RSS</a></footer>
  <?php theme_action('footer_after', $themeContext); ?>
</div>
<dialog class="wcf-dialog" id="wcf-settings" aria-labelledby="wcf-settings-title">
  <div class="wcf-dialog-header"><h2 id="wcf-settings-title">设置</h2><button class="wcf-icon-button" type="button" data-wcf-dialog-close aria-label="关闭设置"><?= wcf_icon('close') ?></button></div>
  <div class="wcf-dialog-body">
    <section class="wcf-setting-section"><h3><?= wcf_icon('search', 16) ?> 搜索</h3><label class="wcf-sr-only" for="wcf-search">输入关键词搜索动态</label><input class="wcf-search-input" id="wcf-search" data-wcf-search type="search" placeholder="输入关键词搜索" autocomplete="off"><ul class="wcf-search-results" data-wcf-search-results aria-live="polite"></ul></section>
    <section class="wcf-setting-section"><h3>热门标签</h3><div class="wcf-tag-cloud"><?php foreach (array_slice(tag_index_data(), 0, 20) as $tag): ?><a href="<?= h(wcf_url('tag', ['slug' => $tag['slug']])) ?>">#<?= h($tag['label']) ?></a><?php endforeach; ?></div></section>
    <section class="wcf-setting-section"><h3>主题模式</h3><button class="wcf-mode-button" type="button" data-wcf-theme-toggle aria-pressed="false"><?= wcf_icon('moon') ?><span>切换日间 / 夜间</span></button></section>
    <section class="wcf-setting-section"><h3>发现</h3><nav class="wcf-page-links" aria-label="站点导航"><a href="<?= h(wcf_url('home')) ?>">朋友圈</a><a href="<?= h(wcf_url('archives')) ?>">时光足迹</a><a href="<?= h(wcf_url('categories')) ?>">分类</a><a href="<?= h(wcf_url('tags')) ?>">标签</a><a href="<?= h(wcf_url('links')) ?>">朋友们</a><?php foreach ($navPages as $page): ?><a href="<?= h(wcf_url('page', ['slug' => $page['slug']])) ?>"><?= h($page['title']) ?></a><?php endforeach; ?></nav></section>
    <div class="wcf-copyright">© <?= h(date('Y')) ?> <?= h($siteName) ?><br>Theme <a href="https://github.com/jkjoy">wcf by jkjoy</a> · Powered by <a href="https://github.com/jkjoy/Simple-PHP-Blog">SBlog</a><?php if ($wcfBeian !== ''): ?><br><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= h($wcfBeian) ?></a><?php endif; ?></div>
  </div>
</dialog>
<dialog class="wcf-dialog" id="wcf-links" aria-labelledby="wcf-links-title"><div class="wcf-dialog-header"><h2 id="wcf-links-title">朋友们</h2><button class="wcf-icon-button" type="button" data-wcf-dialog-close aria-label="关闭朋友列表"><?= wcf_icon('close') ?></button></div><div class="wcf-dialog-body"><?= wcf_links_html() ?><p><a href="<?= h(wcf_url('links')) ?>">查看全部朋友</a></p></div></dialog>
<button class="wcf-back-top" type="button" data-wcf-top hidden aria-label="回到顶部" title="回到顶部"><?= wcf_icon('up', 24) ?></button>
<noscript><p class="wcf-noscript">可通过动态时间查看全文，通过页尾链接翻页。</p><nav class="wcf-page-links"><a href="<?= h(wcf_url('links')) ?>">朋友们</a><a href="<?= h(wcf_url('tags')) ?>">标签</a><a href="<?= h(wcf_url('archives')) ?>">时光足迹</a></nav><style>.wcf-theme .wcf-post-text[data-wcf-collapse]{max-height:none;overflow:visible}</style></noscript>
<script type="application/json" id="wcf-search-data"><?= json_encode($wcfSearch, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<?php theme_action('body_close', $themeContext); ?>
<script src="<?= h(theme_asset_url('script.js')) ?>?v=<?= h((string)filemtime(__DIR__ . '/script.js')) ?>" defer></script>
<?php if (trim(theme_setting('custom_js')) !== ''): ?><script><?= str_ireplace('</script', '<\/script', theme_setting('custom_js')) ?></script><?php endif; ?>
<?php if (trim(theme_setting('analytics')) !== ''): ?><?= theme_setting('analytics') ?><?php endif; ?>
</body>
</html>
