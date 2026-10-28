<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

$kratosConfig = kratos_config();
$kratosAction = (string)($GLOBALS['sblog_current_action'] ?? 'home');
$kratosDetail = in_array($kratosAction, ['post', 'page'], true) && $status < 400;
$kratosBoard = in_array($kratosAction, ['home', 'tag', 'category'], true);
$kratosHasSidebar = $status < 400 && ($kratosAction !== 'post' || $kratosConfig['article_sidebar']);
$kratosBanner = (bool)$kratosConfig['banner'];
$kratosBannerTitle = (string)$kratosConfig['banner_title'];
$kratosBannerDescription = (string)$kratosConfig['banner_description'];
if ($status < 400 && in_array($kratosAction, ['tag', 'category'], true)) {
    $kratosBannerTitle = $title;
    $kratosBannerDescription = $description;
}
$kratosBannerImage = kratos_image_url((string)$kratosConfig['banner_image']) ?: kratos_asset('assets/img/background.jpg');
$kratosLogo = kratos_image_url((string)$kratosConfig['logo']);
$kratosColor = static fn(string $color, string $default): string => preg_match('/^#[a-f0-9]{3}(?:[a-f0-9]{3})?$/i', $color) ? $color : $default;
$kratosContent = kratos_preview_links(kratos_content($content, $kratosAction, $status));
$kratosLinks = kratos_navigation($navPages, $active);
$kratosSearch = $kratosAction === 'home' && is_string($_GET['s'] ?? null) ? str_sub_u(trim($_GET['s']), 0, 100) : '';
if ($kratosSearch !== '') { $fullTitle = '搜索：' . $kratosSearch . ' · ' . $siteName; }
?>
<!doctype html>
<html lang="<?= h(sblog_i18n_locale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="format-detection" content="telephone=no,date=no,address=no,email=no">
  <meta name="theme-color" content="<?= h($kratosColor((string)$kratosConfig['theme_color'], '#282a2c')) ?>">
  <meta name="description" content="<?= h($description) ?>">
  <?php if ($keywords !== ''): ?><meta name="keywords" content="<?= h($keywords) ?>"><?php endif; ?>
  <title><?= h($fullTitle) ?></title>
  <meta property="og:site_name" content="<?= h($siteName) ?>">
  <meta property="og:title" content="<?= h($title) ?>">
  <meta property="og:description" content="<?= h($description) ?>">
  <meta property="og:type" content="<?= $kratosAction === 'post' && $kratosDetail ? 'article' : 'website' ?>">
  <meta property="og:image" content="<?= h(kratos_asset('assets/img/default.jpg')) ?>">
  <meta name="twitter:card" content="summary_large_image">
  <link rel="icon" href="<?= h(theme_favicon_url()) ?>">
  <link rel="alternate" type="application/rss+xml" href="<?= h(url_for('rss')) ?>" title="<?= h($siteName) ?>">
  <link rel="preload" href="<?= h(theme_asset_url('assets/fonts/iconfont/iconfont.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <?php $kratosStyles = ['bootstrap', 'iconfont'];
    if ($kratosConfig['animate']) { $kratosStyles[] = 'animate'; }
    if ($kratosConfig['fontawesome']) { $kratosStyles[] = 'fontawesome'; }
    if ($kratosDetail && $kratosConfig['lightbox']) { $kratosStyles[] = 'lightgallery'; }
    foreach ($kratosStyles as $css): ?><link rel="stylesheet" href="<?= h(kratos_asset('assets/css/' . $css . '.min.css')) ?>">
  <?php endforeach; ?>
  <?= sblog_i18n_head() ?>
  <?php theme_action('head', $themeContext); ?>
  <?php if ($customHeadCode !== ''): ?><?= $customHeadCode . "\n" ?><?php endif; ?>
</head>
<body class="<?= h($bodyClass) ?> kratos" data-kratos-lightbox="<?= $kratosConfig['lightbox'] ? '1' : '0' ?>">
  <?php theme_action('body_open', $themeContext); ?>
  <a class="skip-link" href="#content">跳到正文</a>
  <?php theme_action('header_before', $themeContext); ?>
  <header class="k-header">
    <nav class="k-nav navbar navbar-expand-lg navbar-light fixed-top" aria-label="主菜单"<?= $kratosBanner ? '' : ' style="background:' . h($kratosColor((string)$kratosConfig['navigation_color'], '#24292e')) . '"' ?>>
      <div class="container">
        <a class="navbar-brand" href="<?= h(kratos_url(url_for('home'))) ?>"><?php if ($kratosLogo !== ''): ?><img src="<?= h($kratosLogo) ?>" alt="<?= h($siteName) ?>"><?php else: ?><h1><?= h($siteName) ?></h1><?php endif; ?></a>
        <button class="navbar-toggler navbar-toggler-right" id="navbutton" type="button" aria-controls="navbarResponsive" aria-expanded="false" aria-label="展开菜单"><span class="line first-line"></span><span class="line second-line"></span><span class="line third-line"></span></button>
        <div class="collapse navbar-collapse" id="navbarResponsive">
          <ul class="navbar-nav ml-auto">
            <?php if (function_exists('sblog_menu_is_managed') && sblog_menu_is_managed()): ?>
            <?= sblog_menu_render($themeContext, ['item_tag' => 'li', 'item_class' => 'nav-item', 'link_class' => 'nav-link', 'url' => static fn(string $url): string => kratos_url($url)]) ?>
            <?php else: foreach ($kratosLinks as $link): ?>
            <li class="nav-item<?= $link['active'] ? ' active' : '' ?>"><a class="nav-link" href="<?= h($link['url']) ?>"<?= $link['active'] ? ' aria-current="page"' : '' ?>><?= h($link['name']) ?></a></li>
            <?php endforeach; endif; ?>
          </ul>
        </div>
      </div>
    </nav>
    <?php if ($kratosBanner): ?>
    <div class="banner"><div class="overlay"></div><div class="content text-center" style="background-image:url('<?= h($kratosBannerImage) ?>')"><div class="introduce animate__animated animate__fadeInUp"><div class="title"><?= h($kratosBannerTitle) ?></div><div class="mate"><?= h($kratosBannerDescription) ?></div></div></div></div>
    <?php endif; ?>
  </header>
  <?php theme_action('header_after', $themeContext); ?>
  <main class="k-main <?= $kratosBanner ? 'banner' : 'color' ?>" id="content" style="background:<?= h($status >= 400 ? '#ffffff' : $kratosColor((string)$kratosConfig['background'], '#f5f5f5')) ?>">
    <div class="container"><div class="row">
      <div class="<?= $kratosHasSidebar ? 'col-lg-8' : 'col-12' ?> <?= $kratosBoard ? 'board' : 'details' ?>">
        <?php if ($flash): ?><div class="comment-notice" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
        <?php theme_action('content_before', $themeContext); ?>
        <?= $kratosContent ?>
        <?php theme_action('content_after', $themeContext); ?>
      </div>
      <?php if ($kratosHasSidebar): ?><aside class="col-lg-4 sidebar sticky-sidebar d-none d-lg-block" aria-label="侧边栏"><?= kratos_sidebar() ?></aside><?php endif; ?>
    </div></div>
  </main>
  <?php theme_action('footer_before', $themeContext); ?>
  <footer class="k-footer">
    <div class="f-toolbox">
      <div class="gotop"><button class="gotop-btn" type="button" aria-label="返回顶部"><span class="kicon i-up" aria-hidden="true"></span></button></div>
      <div class="search"><button type="button" data-kratos-search aria-controls="search-footer" aria-expanded="false" aria-label="搜索文章"><span class="kicon i-find" aria-hidden="true"></span></button><?= kratos_search_form('search-footer') ?></div>
    </div>
    <div class="container"><div class="row"><div class="col-12 text-center">
      <p class="social"><?php foreach (kratos_socials() as $link): ?><a href="<?= h(kratos_safe_url((string)$link['url'])) ?>" target="_blank" rel="noopener noreferrer nofollow" aria-label="<?= h($link['name'] ?? $link['icon']) ?>"><i class="kicon i-<?= h($link['icon']) ?>" aria-hidden="true"></i></a><?php endforeach; ?></p>
      <p><?= h(trim((string)$kratosConfig['copyright']) ?: 'COPYRIGHT © ' . date('Y') . ' ' . $siteName . '. ALL RIGHTS RESERVED.') ?></p>
      <p>Theme <a href="https://github.com/seatonjiang/kratos" target="_blank" rel="noopener noreferrer nofollow">Kratos</a> Made By <a href="https://seatonjiang.com" target="_blank" rel="noopener noreferrer nofollow">Seaton Jiang</a></p>
      <?php $kratosBeian = trim(setting('footer_beian')); if ($kratosBeian !== ''): ?><p><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer nofollow"><?= h($kratosBeian) ?></a></p><?php endif; ?>
    </div></div></div>
  </footer>
  <?php theme_action('footer_after', $themeContext); ?>
  <script src="<?= h(asset_url('assets/index.js')) ?>?v=<?= h(APP_VERSION) ?>" defer></script>
  <?php if ($kratosDetail && $kratosConfig['lightbox']): ?><script src="<?= h(kratos_asset('assets/lightgallery.min.js')) ?>" defer></script><?php endif; ?>
  <script src="<?= h(kratos_asset('assets/theme.js')) ?>" defer></script>
  <?php theme_action('body_close', $themeContext); ?>
</body>
</html>
