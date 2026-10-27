<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }
$voidConfig = sbvoid_config();
$voidPage = sbvoid_page($content, $title, $status);
$voidHome = $voidPage['type'] === 'home';
$voidBanner = $voidPage['banner'];
$voidCategories = sbvoid_categories();
$voidName = trim((string)$voidConfig['name']) ?: $siteName;
$voidTitle = trim((string)$voidConfig['index_title']) ?: $siteName;
$voidSubtitle = trim((string)$voidConfig['index_subtitle']) ?: setting('site_tagline');
$voidCanonical = absolute_url($voidPage['post'] ? content_permalink($voidPage['post']) : match ($voidPage['type']) {
    'home', 'search' => home_page_url(max(1, (int)($_GET['p'] ?? 1))),
    'tag', 'category' => url_for($voidPage['type'], ['slug' => (string)($_GET['slug'] ?? '')]),
    'archives', 'tags', 'categories', 'links' => url_for($voidPage['type']),
    default => url_for('home'),
});
if ($voidPage['type'] === 'search') { $voidCanonical .= (str_contains($voidCanonical, '?') ? '&' : '?') . 'void_search=' . rawurlencode(sbvoid_search_term()); }
$voidBodyClass = $bodyClass . ' void-theme' . ($voidConfig['serif'] ? ' serif' : '') . ($voidConfig['mac_code'] ? ' macStyleCodeBlock' : '') . ($voidConfig['index_style'] === 'single' ? ' single-col' : '');
$voidBoot = ['color' => $voidConfig['color_scheme'], 'font' => $voidConfig['font_size'], 'serif' => $voidConfig['serif'], 'system' => $voidConfig['follow_system'], 'start' => $voidConfig['dark_start'], 'end' => $voidConfig['dark_end']];
$voidPost = $voidPage['post'];
$voidPublicPost = $voidPost ? public_content_context($voidPost) : null;
$voidLocked = $voidPost && content_requires_password($voidPost) && !content_password_is_unlocked($voidPost);
?>
<!doctype html>
<html lang="<?= h(sblog_i18n_locale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= h($description) ?>">
  <?php if ($keywords !== ''): ?><meta name="keywords" content="<?= h($keywords) ?>"><?php endif; ?>
  <meta name="color-scheme" content="light dark">
  <meta name="theme-color" content="#111111">
  <title><?= h($fullTitle) ?></title>
  <?php if ($status < 400): ?><link rel="canonical" href="<?= h($voidCanonical) ?>"><?php else: ?><meta name="robots" content="noindex, nofollow"><?php endif; ?>
  <link rel="icon" href="<?= h(theme_favicon_url()) ?>">
  <link rel="alternate" type="application/rss+xml" title="<?= h($siteName) ?>" href="<?= h(url_for('rss')) ?>">
  <meta property="og:site_name" content="<?= h($siteName) ?>">
  <meta property="og:title" content="<?= h($fullTitle) ?>">
  <meta property="og:description" content="<?= h($description) ?>">
  <meta property="og:type" content="<?= $voidPost ? 'article' : 'website' ?>">
  <meta property="og:url" content="<?= h($voidCanonical) ?>">
  <?php if ($voidBanner !== ''): ?><meta property="og:image" content="<?= h(absolute_url($voidBanner)) ?>"><?php endif; ?>
  <?= sblog_i18n_head() ?>
  <link rel="stylesheet" href="<?= h(sbvoid_asset('assets/void.css')) ?>">
  <?php theme_action('head', $themeContext); ?>
  <style>:root{--void-banner-height:<?= max(15, min(100, (int)$voidConfig['banner_height'])) ?>vh;--void-mobile-banner-height:<?= max(15, min(100, (int)$voidConfig['mobile_banner_height'])) ?>vh}</style>
  <?php if ($customHeadCode !== ''): ?><?= $customHeadCode ?><?php endif; ?>
</head>
<body class="<?= h($voidBodyClass) ?>" fontsize="<?= max(1, min(5, (int)$voidConfig['font_size'])) ?>" data-void-theme="void" data-pjax="<?= $voidConfig['pjax'] ? '1' : '0' ?>" data-line-numbers="<?= $voidConfig['line_numbers'] ? '1' : '0' ?>" data-math="<?= $voidConfig['math'] ? '1' : '0' ?>" data-math-url="<?= h($voidConfig['math_url']) ?>" data-color-scheme="<?= h($voidConfig['color_scheme']) ?>" data-dark-start="<?= h($voidConfig['dark_start']) ?>" data-dark-end="<?= h($voidConfig['dark_end']) ?>" data-follow-system="<?= $voidConfig['follow_system'] ? '1' : '0' ?>">
  <script>
  (() => {
    const defaults = <?= sbvoid_json($voidBoot) ?>;
    let color = defaults.color, font = defaults.font, serif = defaults.serif;
    try { color = localStorage.getItem('void-color-scheme') || color; font = localStorage.getItem('void-font-size') || font; const saved = localStorage.getItem('void-font'); if (saved) serif = saved === 'serif'; } catch (_) {}
    const hour = new Date().getHours() + new Date().getMinutes() / 60;
    const night = defaults.start > defaults.end ? hour >= defaults.start || hour < defaults.end : hour >= defaults.start && hour < defaults.end;
    const dark = color === 'dark' || (color !== 'light' && (night || (defaults.system && matchMedia('(prefers-color-scheme: dark)').matches)));
    document.body.classList.toggle('theme-dark', dark);
    document.body.classList.toggle('serif', !!serif);
    document.body.setAttribute('fontsize', String(Math.max(1, Math.min(5, Number(font) || 3))));
  })();
  </script>
  <a class="skip-link" href="#pjax-container">跳到正文</a>
  <?php theme_action('body_open', $themeContext); theme_action('header_before', $themeContext); ?>
  <header class="header-mode-1<?= $voidBanner === '' ? ' force-dark no-banner' : '' ?>">
    <div class="container wider"><nav aria-label="主导航">
      <a class="toggle" href="#nav-mobile" role="button" data-void-menu aria-controls="nav-mobile" aria-expanded="false" aria-label="展开导航"><span></span></a>
      <a class="brand" href="<?= h(sbvoid_url(url_for('home'))) ?>"><?= h($voidName) ?></a>
      <?php foreach (['home' => '首页', 'archives' => '归档', 'tags' => '标签', 'links' => '友链'] as $route => $label): ?><a href="<?= h(sbvoid_url(url_for($route))) ?>"<?= $active === $route ? ' aria-current="page"' : '' ?>><?= h($label) ?></a><?php endforeach; ?>
      <?php foreach ($navPages as $navPage): ?><a href="<?= h(sbvoid_url(content_permalink($navPage))) ?>"<?= $active === 'page:' . $navPage['slug'] ? ' aria-current="page"' : '' ?>><?= h($navPage['title']) ?></a><?php endforeach; ?>
      <?php if ($voidCategories): ?><span class="dropdown" tabindex="0">分类<ul><?php foreach ($voidCategories as $category): ?><li><a href="<?= h(sbvoid_url(url_for('category', ['slug' => $category['slug']]))) ?>"><?= h($category['name']) ?><small><?= (int)$category['post_count'] ?></small></a></li><?php endforeach; ?></ul></span><?php endif; ?>
      <?php foreach ($voidConfig['navigation'] as $group): ?><span class="dropdown" tabindex="0"><?= h($group['name'] ?? '') ?><ul><?php foreach ($group['items'] ?? [] as $item): ?><li><a href="<?= h(safe_link_url((string)($item['url'] ?? ''))) ?>"><?= h($item['title'] ?? '') ?></a></li><?php endforeach; ?></ul></span><?php endforeach; ?>
      <form class="hidden-xs search-form-desktop" role="search" action="<?= h(url_for('home')) ?>" method="get" data-void-search-form><label for="search">搜索</label><input id="search" type="search" name="void_search" value="<?= h(sbvoid_search_term()) ?>" placeholder="输入关键词" maxlength="120" required><?php if (($_GET['theme_preview'] ?? '') === 'void' && $admin): ?><input type="hidden" name="theme_preview" value="void"><?php endif; ?><button type="submit" aria-label="搜索"><i class="voidicon-search" aria-hidden="true"></i></button></form>
      <a id="toggle-mobile-search" href="#mobile-search" role="button" data-void-search aria-label="展开搜索" aria-controls="mobile-search" aria-expanded="false"><i class="voidicon-search" aria-hidden="true"></i></a>
      <a id="toggle-setting" href="#setting-panel" role="button" data-void-settings aria-label="阅读设置" aria-controls="setting-panel" aria-expanded="false"><i class="voidicon-cog" aria-hidden="true"></i></a>
    </nav></div>
    <form class="mobile-search-form" id="mobile-search" role="search" action="<?= h(url_for('home')) ?>" method="get" data-void-search-form hidden><label for="search-mobile">搜索</label><input id="search-mobile" type="search" name="void_search" placeholder="输入内容然后 Go!" maxlength="120" required><button type="submit">Go!</button></form>
  </header>
  <?php theme_action('header_after', $themeContext); ?>
  <div id="nav-mobile" hidden>
    <section id="pages" data-title="PAGES"><nav><?php foreach (['home' => '首页', 'archives' => '归档', 'tags' => '标签', 'links' => '友链'] as $route => $label): ?><a href="<?= h(sbvoid_url(url_for($route))) ?>"><?= h($label) ?></a><?php endforeach; ?><?php foreach ($navPages as $navPage): ?><a href="<?= h(sbvoid_url(content_permalink($navPage))) ?>"><?= h($navPage['title']) ?></a><?php endforeach; ?></nav></section>
    <?php if ($voidCategories): ?><section data-title="CATEGORIES"><nav><?php foreach ($voidCategories as $category): ?><a href="<?= h(sbvoid_url(url_for('category', ['slug' => $category['slug']]))) ?>"><?= h($category['name']) ?></a><?php endforeach; ?></nav></section><?php endif; ?>
    <?php foreach ($voidConfig['navigation'] as $group): ?><section data-title="<?= h($group['name'] ?? '') ?>"><nav><?php foreach ($group['items'] ?? [] as $item): ?><a href="<?= h(safe_link_url((string)($item['url'] ?? ''))) ?>"><?= h($item['title'] ?? '') ?></a><?php endforeach; ?></nav></section><?php endforeach; ?>
  </div>
  <main id="pjax-container" data-page="<?= h($voidPage['type']) ?>" data-status="<?= $status ?>" tabindex="-1">
    <script type="application/ld+json" data-void-jsonld><?= sbvoid_json($voidPost ? [
        '@context' => 'https://schema.org', '@type' => $voidPage['type'] === 'post' ? 'BlogPosting' : 'WebPage',
        'headline' => $voidPost['title'], 'description' => public_content_description($voidPost), 'url' => $voidCanonical,
        'datePublished' => date(DATE_ATOM, (int)($voidPost['published_at'] ?: $voidPost['created_at'])),
        'dateModified' => date(DATE_ATOM, (int)$voidPost['updated_at']),
        'author' => ['@type' => 'Person', 'name' => $siteName],
        'publisher' => ['@type' => 'Organization', 'name' => $siteName],
    ] : ['@context' => 'https://schema.org', '@type' => 'Blog', 'name' => $siteName, 'description' => $description, 'url' => $voidCanonical]) ?></script>
    <div class="lazy-wrap<?= $voidBanner === '' ? ' no-banner' : ' dark' ?><?= $voidHome ? ' index' : '' ?>">
      <?php if ($voidBanner !== ''): ?><div id="banner"><img src="<?= h($voidBanner) ?>" alt="" fetchpriority="high" decoding="async"></div><?php endif; ?>
      <div class="banner-title<?= $voidHome ? ' index' . ($voidBanner !== '' ? ' force-normal' : '') : '' ?>">
        <?php if ($voidHome): ?><h1 class="post-title"><span class="brand"><span><?= h($voidTitle) ?></span></span><br><span class="subtitle"><?= h($voidSubtitle) ?></span></h1>
        <?php else: ?><h1 class="post-title"><?= h($voidPage['title']) ?></h1><?php if ($voidPage['meta'] !== ''): ?><div class="post-meta"><?= $voidPage['meta'] ?></div><?php endif; ?><?php endif; ?>
      </div>
    </div>
    <div class="wrapper container <?= $voidPage['wide'] ? 'wide' : ($voidPost ? '' : 'narrow') ?>">
      <?php if ($flash): ?><div class="void-notice" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
      <?php theme_action('content_before', $themeContext); ?>
      <?php if ($voidPost): ?>
        <div class="contents-wrap"><section id="post" class="float-up"><?= $voidPage['body'] ?>
        <?php if (!$voidLocked): ?>
          <div class="social-button">
            <?php if ($voidPage['type'] === 'post' && is_live_post($voidPost) && $voidConfig['show_likes']): ?><form class="void-like" method="post" action="<?= h(url_for('like_post')) ?>"><?= csrf_field() ?><input type="hidden" name="post_id" value="<?= (int)$voidPost['id'] ?>"><button class="btn btn-highlight<?= visitor_liked_post((int)$voidPost['id']) ? ' done' : '' ?>" type="submit" aria-label="喜欢这篇文章" aria-pressed="<?= visitor_liked_post((int)$voidPost['id']) ? 'true' : 'false' ?>">ENJOY <span aria-hidden="true">♡</span> <span data-like-count><?= post_like_count((int)$voidPost['id']) ?></span></button></form><?php endif; ?>
            <?php $voidReward = sbvoid_image_url((string)$voidConfig['reward_image']); if ($voidReward !== ''): ?><details class="void-reward"><summary class="btn">赞赏</summary><img src="<?= h($voidReward) ?>" alt="赞赏二维码" loading="lazy"></details><?php endif; ?>
            <button class="btn void-share" type="button" data-void-share>分享 <i class="voidicon-link" aria-hidden="true"></i></button>
            <?php if ($admin): ?><a class="btn" href="<?= h(url_for('edit', ['id' => $voidPost['id']])) ?>">编辑</a><?php endif; ?>
          </div>
        <?php endif; ?>
        </section></div>
      <?php else: ?><div class="void-page<?= !in_array($voidPage['type'], ['home', 'search', 'tag', 'category', 'archives'], true) ? ' yue' : '' ?>"><?= $voidPage['body'] ?></div><?php endif; ?>
      <?php theme_action('content_after', $themeContext); ?>
    </div>
    <?php if ($voidPage['comments'] !== ''): ?><div class="void-comments container"><?= $voidPage['comments'] ?></div><?php endif; ?>
  </main>
  <?php theme_action('footer_before', $themeContext); ?>
  <footer><div class="container wide"><section><p>© <?= date('Y') ?> <span class="brand"><?= h($siteName) ?></span></p><?php $voidStart = $voidConfig['start_date'] !== '' ? strtotime($voidConfig['start_date']) : false; if ($voidStart !== false && $voidStart <= time()): ?><p>感谢陪伴：<?= (int)floor((time() - $voidStart) / 86400) ?> 天</p><?php else: ?><p><?= h(setting('site_tagline')) ?></p><?php endif; ?></section><section><p>Powered by <a href="https://github.com/jkjoy/Simple-PHP-Blog" target="_blank" rel="noopener">SBlog</a> • <a href="https://github.com/AlanDecode/Typecho-Theme-VOID" target="_blank" rel="noopener">Theme VOID</a></p><p><a href="<?= h(url_for('rss')) ?>">RSS</a> · <a href="<?= h(url_for('sitemap')) ?>">Sitemap</a><?php if (trim(setting('footer_beian')) !== ''): ?> · <a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener"><?= h(setting('footer_beian')) ?></a><?php endif; ?></p><?php if (trim(setting('site_footer')) !== ''): ?><p><?= h(site_footer_text()) ?></p><?php endif; ?></section></div></footer>
  <?php theme_action('footer_after', $themeContext); ?>
  <aside id="ctrler-panel" aria-label="阅读工具"><div class="ctrler-item" id="go-top"><a href="#" data-void-top aria-label="返回顶部"><i class="voidicon-up" aria-hidden="true"></i></a></div><?php if ($admin): ?><div class="ctrler-item hidden-xs"><a href="<?= h(url_for('admin')) ?>" aria-label="进入后台"><i class="voidicon-login" aria-hidden="true"></i></a></div><?php endif; ?><div class="ctrler-item hidden-xs"><a href="#setting-panel" data-void-settings aria-label="阅读设置" aria-controls="setting-panel" aria-expanded="false"><i class="voidicon-cog" aria-hidden="true"></i></a></div><div class="ctrler-item" id="toggle-toc" hidden><a href="#toc" data-void-toc aria-label="文章目录" aria-controls="toc" aria-expanded="false"><i class="voidicon-left" aria-hidden="true"></i></a></div></aside>
  <aside id="setting-panel" aria-label="阅读设置" hidden>
    <section><div id="toggle-night"><a href="#" data-void-theme role="button" aria-label="切换日间或夜间模式" aria-pressed="false"><i aria-hidden="true"></i></a></div><div id="adjust-text-container"><div class="adjust-text-item"><a href="#" data-void-font-size="-1" role="button" aria-label="减小字号"><i class="voidicon-font" aria-hidden="true"></i>−</a><span id="current_textsize" aria-label="当前字号"></span><a href="#" data-void-font-size="1" role="button" aria-label="增大字号"><i class="voidicon-font" aria-hidden="true"></i>+</a></div><div class="adjust-text-item"><a href="#" data-void-font="sans" role="button">Sans</a><a href="#" data-void-font="serif" role="button">Serif</a></div></div></section>
    <section class="void-setting-auto"><button type="button" class="btn btn-narrow" data-void-auto-theme>跟随系统与时间</button></section>
    <section id="links"><a<?= !$admin ? ' data-void-login' : '' ?> href="<?= h(url_for($admin ? 'admin' : 'login')) ?>" title="<?= $admin ? '管理后台' : '登录' ?>" aria-label="<?= $admin ? '管理后台' : '登录' ?>"><i class="voidicon-user" aria-hidden="true"></i></a><a href="<?= h(url_for('rss')) ?>" title="RSS" aria-label="订阅 RSS"><i class="voidicon-rss" aria-hidden="true"></i></a><?php foreach ($voidConfig['social'] as $social): ?><a href="<?= h(safe_link_url((string)($social['url'] ?? ''))) ?>" target="_blank" rel="noopener noreferrer" title="<?= h($social['name'] ?? '') ?>" aria-label="<?= h($social['name'] ?? '') ?>"><i class="voidicon-<?= h(preg_replace('/[^a-z0-9-]/', '', (string)($social['icon'] ?? 'link'))) ?>" aria-hidden="true"></i></a><?php endforeach; ?></section>
  </aside>
  <aside id="toc" class="TOC" aria-label="文章目录" hidden><h2>文章目录</h2><nav id="toc-content"></nav></aside>
  <dialog id="void-lightbox" aria-label="图片预览"><button type="button" class="void-lightbox-close" data-void-lightbox-close aria-label="关闭图片">×</button><button type="button" class="void-lightbox-prev" data-void-lightbox-prev aria-label="上一张">‹</button><figure><img src="" alt=""><figcaption class="void-lightbox-caption"></figcaption></figure><button type="button" class="void-lightbox-next" data-void-lightbox-next aria-label="下一张">›</button></dialog>
  <dialog id="void-login" class="void-login-dialog" aria-labelledby="void-login-title"><button type="button" data-void-login-close aria-label="关闭登录">×</button><h2 id="void-login-title">登录</h2><div id="void-login-content"></div></dialog>
  <script src="<?= h(asset_url('assets/index.js')) ?>?v=<?= h(APP_VERSION) ?>" defer></script>
  <script src="<?= h(sbvoid_asset('assets/prism.js')) ?>" defer data-manual></script>
  <script src="<?= h(sbvoid_asset('assets/void.js')) ?>" defer></script>
  <?php theme_action('body_close', $themeContext); ?>
</body>
</html>
