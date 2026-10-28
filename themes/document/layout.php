<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

$documentConfig = document_settings_values();
$documentAction = (string)($GLOBALS['sblog_current_action'] ?? 'home');
$documentDetail = in_array($documentAction, ['post', 'page'], true) && $status < 400;
$documentTitle = trim((string)$documentConfig['navbar_title']) ?: $siteName;
$documentQuery = $documentAction === 'home' ? document_search_query() : '';
$documentContent = document_preview_links(document_content($content, $documentAction, $status, $title, $documentConfig));
$documentLeft = $documentConfig['sidebar_left'] || ($documentDetail && $documentConfig['show_toc']);
$documentLogo = $documentConfig['logo_url'] !== '' ? $documentConfig['logo_url'] : theme_logo_url();
$documentLinks = [['label' => '首页', 'url' => url_for('home'), 'active' => $active === 'home'], ['label' => '文章归档', 'url' => url_for('archives'), 'active' => $active === 'archives'], ['label' => '标签', 'url' => url_for('tags'), 'active' => $active === 'tags'], ['label' => '友链', 'url' => url_for('links'), 'active' => $active === 'links']];
foreach ($navPages as $navPage) { $documentLinks[] = ['label' => $navPage['title'], 'url' => content_permalink($navPage), 'active' => $active === 'page:' . $navPage['slug']]; }
$documentMode = (string)$documentConfig['darkmode'];
$documentCustomCss = str_ireplace('</style', '< /style', (string)$documentConfig['custom_css']);
$documentIcp = trim((string)$documentConfig['icp_text']) ?: trim(setting('footer_beian'));
?>
<!doctype html>
<html lang="<?= h(sblog_i18n_locale()) ?>" data-theme="<?= $documentMode === 'dark' ? 'dark' : 'light' ?>" style="--document-primary:<?= h($documentConfig['primary_color']) ?>;--document-light-background:<?= h($documentConfig['background_color']) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="theme-color" content="<?= h($documentConfig['primary_color']) ?>">
  <meta name="description" content="<?= h($description) ?>">
  <?php if ($keywords !== ''): ?><meta name="keywords" content="<?= h($keywords) ?>"><?php endif; ?>
  <title><?= h($documentQuery !== '' ? '搜索：' . $documentQuery . ' · ' . $siteName : $fullTitle) ?></title>
  <meta property="og:title" content="<?= h($title) ?>">
  <meta property="og:description" content="<?= h($description) ?>">
  <meta property="og:site_name" content="<?= h($siteName) ?>">
  <meta property="og:type" content="<?= $documentDetail && $documentAction === 'post' ? 'article' : 'website' ?>">
  <link rel="icon" href="<?= h(theme_favicon_url()) ?>">
  <link rel="alternate" type="application/rss+xml" href="<?= h(url_for('rss')) ?>" title="<?= h($siteName) ?>">
  <script>try{const m=localStorage.getItem('document-mode')||<?= json_encode($documentMode, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;document.documentElement.dataset.theme=m==='dark'||m==='auto'&&matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light';}catch(e){}</script>
  <?= sblog_i18n_head() ?>
  <?php theme_action('head', $themeContext); ?>
  <?php if ($documentCustomCss !== ''): ?><style id="document-custom-css"><?= $documentCustomCss ?></style><?php endif; ?>
  <?php if ($customHeadCode !== ''): ?><?= $customHeadCode . "\n" ?><?php endif; ?>
</head>
<body class="<?= h($bodyClass) ?>" data-mode="<?= h($documentMode) ?>" data-toc-depth="<?= h($documentConfig['toc_depth']) ?>" data-toc-collapsed="<?= $documentConfig['toc_collapsed'] ? '1' : '0' ?>" data-show-navigation="<?= $documentConfig['show_post_navigation'] ? '1' : '0' ?>">
  <?php theme_action('body_open', $themeContext); ?>
  <a class="skip-link" href="#content">跳到正文</a>
  <?php theme_action('header_before', $themeContext); ?>
  <header class="main-header">
    <div class="left"><a class="logo" href="<?= h(document_url(url_for('home'))) ?>" aria-label="<?= h($siteName) ?>">
      <?php if ($documentConfig['show_logo']): ?><img src="<?= h($documentLogo) ?>" alt="" width="32" height="32"><?php endif; ?>
      <?php if ($documentConfig['show_title']): ?><span class="title"><?= h($documentTitle) ?></span><?php endif; ?>
      <?php if (!$documentConfig['show_logo'] && !$documentConfig['show_title']): ?><span class="sr-only"><?= h($siteName) ?></span><?php endif; ?>
    </a></div>
    <button class="document-menu-toggle" type="button" aria-label="展开导航" aria-controls="document-nav" aria-expanded="false">☰</button>
    <div class="right" id="document-nav">
      <?php if ($documentConfig['show_search']): ?>
      <form class="document-search" method="get" action="<?= h(script_url()) ?>" role="search">
        <input type="hidden" name="a" value="home">
        <?php if (($_GET['theme_preview'] ?? '') === 'document' && is_admin()): ?><input type="hidden" name="theme_preview" value="document"><?php endif; ?>
        <label class="sr-only" for="document-search">搜索文章</label><input id="document-search" name="q" type="search" value="<?= h($documentQuery) ?>" maxlength="100" placeholder="搜索笔记…"><button type="submit" aria-label="搜索">⌕</button>
      </form>
      <?php endif; ?>
      <nav class="document-nav" aria-label="主菜单">
        <?php if (function_exists('sblog_menu_is_managed') && sblog_menu_is_managed()): ?>
          <?= sblog_menu_render($themeContext, ['item_tag' => 'div', 'item_class' => 'document-nav-item', 'url' => static fn(string $url): string => document_url($url)]) ?>
        <?php else: ?>
          <?php foreach ($documentLinks as $link): ?><a href="<?= h(document_url($link['url'])) ?>"<?= $link['active'] ? ' aria-current="page"' : '' ?>><?= h($link['label']) ?></a><?php endforeach; ?>
        <?php endif; ?>
      </nav>
    </div>
  </header>
  <?php theme_action('header_after', $themeContext); ?>
  <main class="main-container<?= !$documentDetail ? ' index' : '' ?>" data-left="<?= $documentLeft ? '1' : '0' ?>" data-right="<?= $documentConfig['sidebar_right'] ? '1' : '0' ?>">
    <?php if ($documentLeft): ?>
    <aside class="sidebar-left" aria-label="<?= $documentDetail && $documentConfig['show_toc'] ? '文章目录' : '文章导航' ?>">
      <?php if ($documentDetail && $documentConfig['show_toc']): ?>
      <section class="document-widget document-toc">
        <h2 class="widget-title"><span>文章目录</span><button type="button" data-toc-toggle aria-label="折叠目录" aria-controls="document-toc-list" aria-expanded="true">⌃</button><button type="button" data-toc-expand aria-label="展开全部目录">☷</button></h2>
        <nav id="document-toc-list" data-document-toc aria-label="文章目录"><p class="document-toc-empty">阅读目录会随正文标题生成。</p></nav>
      </section>
      <?php endif; ?>
      <?php if ($documentConfig['sidebar_left']): ?>
      <section class="document-widget"><h2 class="widget-title">文章导航</h2>
        <nav class="document-categories" aria-label="文章分类"><a class="document-category" href="<?= h(document_url(url_for('home'))) ?>"<?= $documentAction === 'home' ? ' aria-current="page"' : '' ?>><span>全部文章</span><span><?= count_published_posts() ?></span></a>
          <?php foreach (document_categories() as $category): ?><a class="document-category" href="<?= h(document_url(url_for('category', ['slug' => (string)$category['slug']]))) ?>"<?= $documentAction === 'category' && ($_GET['slug'] ?? '') === $category['slug'] ? ' aria-current="page"' : '' ?>><span><?= h($category['name']) ?></span><span><?= (int)$category['post_count'] ?></span></a><?php endforeach; ?>
          <a class="document-category" href="<?= h(document_url(url_for('archives'))) ?>"><span>文章归档</span><span>↗</span></a>
        </nav>
      </section>
      <?php endif; ?>
    </aside>
    <?php endif; ?>
    <div class="main-main" id="content" tabindex="-1">
      <?php if ($flash): ?><div class="document-notice" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
      <?php theme_action('content_before', $themeContext); ?>
      <?php if ($documentAction === 'home' && $status < 400 && $documentQuery === '' && $documentConfig['show_home_banner']): ?>
      <section class="document-banner" data-mobile="<?= $documentConfig['banner_mobile'] ? '1' : '0' ?>">
        <?php if ($documentConfig['banner_image'] !== ''): ?><img src="<?= h($documentConfig['banner_image']) ?>" alt="" decoding="async"><?php endif; ?>
        <div><h2><?= h(trim((string)$documentConfig['banner_title']) ?: $siteName) ?></h2><p><?= nl2br(h(trim((string)$documentConfig['banner_subtitle']) ?: setting('site_tagline')), false) ?></p></div>
      </section>
      <?php endif; ?>
      <?= $documentContent ?>
      <?php theme_action('content_after', $themeContext); ?>
    </div>
    <?php if ($documentConfig['sidebar_right']): ?><aside class="sidebar-right" aria-label="站点信息"><?php document_sidebar($documentConfig); ?></aside><?php endif; ?>
  </main>
  <?php theme_action('footer_before', $themeContext); ?>
  <footer class="main-footer">
    <?php if ($documentConfig['footer_text'] !== ''): ?><p><?= nl2br(h($documentConfig['footer_text']), false) ?></p><?php endif; ?>
    <p>© <?= (int)$documentConfig['footer_since'] < (int)date('Y') ? (int)$documentConfig['footer_since'] . '–' : '' ?><?= h(date('Y')) ?> <?= h($siteName) ?> · Powered by <a href="https://github.com/jkjoy/Simple-PHP-Blog" target="_blank" rel="noopener noreferrer">SBlog</a> · <a href="https://github.com/friend-nicen/theme-document" target="_blank" rel="noopener noreferrer">Document</a></p>
    <?php if ($documentIcp !== ''): ?><a href="<?= h($documentConfig['icp_url'] ?: 'https://beian.miit.gov.cn/') ?>" target="_blank" rel="noopener noreferrer"><?= h($documentIcp) ?></a><?php endif; ?>
  </footer>
  <?php theme_action('footer_after', $themeContext); ?>
  <div class="document-tools" aria-label="阅读工具">
    <?php if ($documentConfig['show_theme_toggle']): ?><button type="button" data-theme-toggle aria-label="切换阅读模式" title="切换阅读模式">◐</button><?php endif; ?>
    <?php if ($documentConfig['show_color_picker']): ?>
    <button type="button" data-color-toggle aria-label="选择主题色" aria-expanded="false" aria-controls="document-colors" title="选择主题色">⚙</button>
    <div class="document-colors" id="document-colors" hidden><?php foreach ([$documentConfig['primary_color'], '#f5222d', '#fa541c', '#fadb14', '#3eaf7c', '#13c2c2', '#1890ff', '#722ed1', '#eb2f96'] as $color): ?><button type="button" data-color="<?= h($color) ?>" style="--swatch:<?= h($color) ?>" aria-label="主题色 <?= h($color) ?>"></button><?php endforeach; ?></div>
    <?php endif; ?>
    <span class="document-progress" aria-label="阅读进度">0%</span><span class="document-progress-number" data-document-percentage aria-hidden="true">0%</span><button type="button" data-back-top aria-label="回到顶部" title="回到顶部">↑</button>
  </div>
  <script src="<?= h(asset_url('assets/index.js')) ?>?v=<?= h(APP_VERSION) ?>" defer></script>
  <script src="<?= h(document_asset('assets/theme.js')) ?>" defer></script>
  <?php theme_action('body_close', $themeContext); ?>
</body>
</html>
