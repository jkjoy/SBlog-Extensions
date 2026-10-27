<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

$yinyangConfig = yinyang_config();
$yinyangAction = (string)($GLOBALS['sblog_current_action'] ?? 'home');
$yinyangDetail = in_array($yinyangAction, ['post', 'page'], true) && $status < 400;
$yinyangHeadTitle = trim((string)$yinyangConfig['head_title']) ?: $siteName;
$yinyangSubtitle = trim((string)$yinyangConfig['description']) ?: setting('site_tagline');
$yinyangContent = yinyang_preview_links(yinyang_content($content, $yinyangAction, $status));
$yinyangLinks = [];
foreach ($yinyangConfig['socials'] as $social) {
    $link = yinyang_safe_url((string)($social['link'] ?? ''));
    if ($link !== '' && trim((string)($social['name'] ?? '')) !== '') {
        $yinyangLinks[] = ['name' => $social['name'], 'link' => yinyang_url($link), 'active' => false];
    }
}
if (!$yinyangLinks) {
    foreach (['archives' => '归档', 'tags' => '标签', 'links' => '链接'] as $route => $label) {
        $yinyangLinks[] = ['name' => sblog_t($label), 'link' => yinyang_url(url_for($route)), 'active' => $active === $route];
    }
    foreach ($navPages as $navPage) {
        $yinyangLinks[] = ['name' => $navPage['title'], 'link' => yinyang_url(content_permalink($navPage)), 'active' => $active === 'page:' . $navPage['slug']];
    }
    $yinyangLinks[] = ['name' => 'RSS', 'link' => url_for('rss'), 'active' => false];
}
?>
<!doctype html>
<html lang="<?= h(sblog_i18n_locale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="theme-color" content="#ffffff" id="yinyang-theme-color">
  <meta name="description" content="<?= h($description) ?>">
  <?php if ($keywords !== ''): ?><meta name="keywords" content="<?= h($keywords) ?>"><?php endif; ?>
  <title><?= h($fullTitle) ?></title>
  <meta property="og:title" content="<?= h($title) ?>">
  <meta property="og:description" content="<?= h($description) ?>">
  <meta property="og:site_name" content="<?= h($siteName) ?>">
  <meta property="og:type" content="<?= $yinyangDetail && $yinyangAction === 'post' ? 'article' : 'website' ?>">
  <link rel="icon" href="<?= h(theme_favicon_url()) ?>">
  <link rel="alternate" type="application/rss+xml" href="<?= h(url_for('rss')) ?>" title="<?= h($siteName) ?>">
  <link rel="preload" href="<?= h(theme_asset_url('assets/fonts/BreeSerif-Regular.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="<?= h(theme_asset_url('assets/fonts/BungeeShade-Regular.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <script src="<?= h(yinyang_asset('assets/theme.js')) ?>"></script>
  <?= sblog_i18n_head() ?>
  <?php theme_action('head', $themeContext); ?>
  <link rel="stylesheet" href="<?= h(yinyang_asset('assets/flexboxgrid.min.css')) ?>">
  <?php if ($customHeadCode !== ''): ?><?= $customHeadCode . "\n" ?><?php endif; ?>
</head>
<body class="<?= h($bodyClass) ?> yinyang">
  <?php theme_action('body_open', $themeContext); ?>
  <a class="skip-link" href="#content"><?= h(sblog_t('跳到正文')) ?></a>
  <article class="<?= $yinyangDetail ? 'post' : 'posts' ?>"<?= $yinyangDetail ? ' id="article"' : '' ?>>
    <div class="row">
      <div class="col-xs-12">
        <?php theme_action('header_before', $themeContext); ?>
        <div class="site-header">
          <header>
            <?php if ($yinyangAction === 'home' && $status < 400): ?>
            <h1 class="header-title"><a href="<?= h(yinyang_url(url_for('home'))) ?>"><?= h($yinyangHeadTitle) ?></a></h1>
            <?php else: ?>
            <div class="header-title"><a href="<?= h(yinyang_url(url_for('home'))) ?>"><?= h($yinyangHeadTitle) ?></a></div>
            <?php endif; ?>
            <div class="header-subtitle"><?= h($yinyangSubtitle) ?></div>
          </header>
          <nav class="row end-md header-items" aria-label="<?= h(sblog_t('主菜单')) ?>">
            <div class="header-item-left">
              <button id="theme-toggle" class="theme-toggle" type="button" aria-label="<?= h(sblog_t('切换到深色模式')) ?>" title="<?= h(sblog_t('切换到深色模式')) ?>" aria-pressed="false" data-dark-label="<?= h(sblog_t('切换到深色模式')) ?>" data-light-label="<?= h(sblog_t('切换到浅色模式')) ?>">🌙</button>
            </div>
            <?php if (function_exists('sblog_menu_is_managed') && sblog_menu_is_managed()): ?>
              <?= sblog_menu_render($themeContext, ['item_tag' => 'div', 'item_class' => 'header-item', 'url' => static fn(string $url): string => yinyang_url($url)]) ?>
              <div class="header-item"><a href="<?= h(url_for('rss')) ?>">RSS</a></div>
            <?php else: ?>
            <?php foreach ($yinyangLinks as $link): ?>
            <div class="header-item"><a href="<?= h($link['link']) ?>"<?= $link['active'] ? ' aria-current="page"' : '' ?><?= preg_match('#^https?://#i', $link['link']) ? ' target="_blank" rel="noopener noreferrer"' : '' ?>><?= h($link['name']) ?></a></div>
            <?php endforeach; ?>
            <?php endif; ?>
          </nav>
          <div class="header-line"></div>
        </div>
        <?php theme_action('header_after', $themeContext); ?>
        <main id="content"<?= $yinyangDetail ? '' : ' class="page-content"' ?>>
          <?php if ($flash): ?><div class="text-notice" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
          <?php theme_action('content_before', $themeContext); ?>
          <?= $yinyangContent ?>
          <?php theme_action('content_after', $themeContext); ?>
        </main>
        <?php theme_action('footer_before', $themeContext); ?>
        <footer class="site-footer">
          <?php if ($yinyangConfig['show_footer']): ?><span class="site-footer-item"><?= h(site_footer_text()) ?></span><?php endif; ?>
          <?php $yinyangBeian = trim(setting('footer_beian')); if ($yinyangBeian !== ''): ?><a class="site-footer-item" href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= h($yinyangBeian) ?></a><?php endif; ?>
        </footer>
        <?php theme_action('footer_after', $themeContext); ?>
      </div>
    </div>
  </article>
  <script src="<?= h(asset_url('assets/index.js')) ?>?v=<?= h(APP_VERSION) ?>" defer></script>
  <?php theme_action('body_close', $themeContext); ?>
</body>
</html>
