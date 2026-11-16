<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

$retroAction = (string)($GLOBALS['sblog_current_action'] ?? 'home');
$retroIsHome = $retroAction === 'home' && $status < 400;
$retroFirstPage = $retroIsHome && max(1, (int)($_GET['p'] ?? 1)) === 1;
$retroStats = retro_home_stats();
$retroReads = str_pad((string)max(0, (int)($retroStats['reads'] ?? 0)), 6, '0', STR_PAD_LEFT);
$retroSince = trim(retro_home_option('since_date'));
if ($retroSince === '' && !empty($retroStats['first_published'])) { $retroSince = date('Y-m-d', (int)$retroStats['first_published']); }
$retroSubtitle = trim(retro_home_option('subtitle')) ?: setting('site_tagline');
$retroEmail = retro_home_email();
$retroFooter = trim(setting('site_footer')) !== '' ? site_footer_text() : '© ' . date('Y') . ' ' . $siteName;
$retroHomeUrl = retro_home_url(url_for('home'));
$retroContent = retro_home_preview_links(retro_home_content($content, $retroAction, $status));
$retroNav = [['label' => '首页', 'url' => $retroHomeUrl, 'current' => $retroIsHome]];
if (retro_home_option('show_profile', '1') === '1') {
    $retroNav[] = ['label' => '关于我', 'url' => $retroFirstPage ? '#about' : $retroHomeUrl . '#about', 'current' => false];
}
foreach (['archives' => '更新日志', 'links' => '友情链接'] as $route => $label) {
    $retroNav[] = ['label' => $label, 'url' => retro_home_url(url_for($route)), 'current' => $active === $route];
}
foreach ($navPages as $navPage) {
    $retroNav[] = ['label' => $navPage['title'], 'url' => retro_home_url(content_permalink($navPage)), 'current' => $active === 'page:' . $navPage['slug']];
}
?>
<!doctype html>
<html lang="<?= h(sblog_i18n_locale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <meta name="theme-color" content="#e7e3d7">
  <title><?= h($fullTitle) ?></title>
  <meta name="description" content="<?= h($description) ?>">
  <?php if ($keywords !== ''): ?><meta name="keywords" content="<?= h($keywords) ?>"><?php endif; ?>
  <meta property="og:title" content="<?= h($title) ?>">
  <meta property="og:description" content="<?= h($description) ?>">
  <meta property="og:site_name" content="<?= h($siteName) ?>">
  <meta property="og:type" content="<?= $retroAction === 'post' && $status < 400 ? 'article' : 'website' ?>">
  <link rel="icon" href="<?= h(theme_favicon_url()) ?>">
  <link rel="alternate" type="application/rss+xml" href="<?= h(url_for('rss')) ?>" title="<?= h($siteName) ?>">
  <?= sblog_i18n_head() ?>
  <?php theme_action('head', $themeContext); ?>
  <?php if ($customHeadCode !== ''): ?><?= $customHeadCode . "\n" ?><?php endif; ?>
</head>
<body class="<?= h($bodyClass) ?> retro-home">
  <?php theme_action('body_open', $themeContext); ?>
  <a class="skip-link" href="#main-content"><?= h(sblog_t('跳到正文')) ?></a>
  <div class="page" id="top">
    <div class="topbar">
      <span>欢迎光临<?php if (retro_home_option('show_counter', '1') === '1'): ?> · 文章已被阅读 <?= h($retroReads) ?> 次<?php endif; ?></span>
      <?php if ($retroSince !== '' || !empty($retroStats['last_updated'])): ?>
      <span><?php if ($retroSince !== ''): ?>EST. <?= h(substr($retroSince, 0, 4)) ?><?php endif; ?><?php if ($retroSince !== '' && !empty($retroStats['last_updated'])): ?> &nbsp;|&nbsp; <?php endif; ?><?php if (!empty($retroStats['last_updated'])): ?>LAST UPDATE <time datetime="<?= h(date('Y-m-d', (int)$retroStats['last_updated'])) ?>"><?= h(date('Y.m.d', (int)$retroStats['last_updated'])) ?></time><?php endif; ?></span>
      <?php endif; ?>
    </div>
    <?php theme_action('header_before', $themeContext); ?>
    <header class="masthead">
      <a class="logo-link" href="<?= h($retroHomeUrl) ?>" aria-label="<?= h($siteName) ?> · 首页">
        <svg class="logo" viewBox="0 0 16 16" shape-rendering="crispEdges" aria-hidden="true" focusable="false">
          <rect width="16" height="16" fill="#3a3a34"/>
          <rect x="1" y="1" width="14" height="14" fill="#1b3f96"/>
          <rect x="5" y="1" width="6" height="6" fill="#d9d2bd"/>
          <rect x="8" y="1" width="3" height="5" fill="#8d8775"/>
          <rect x="3" y="9" width="10" height="6" fill="#fbf9f1"/>
          <rect x="4" y="10" width="8" height="1" fill="#a6442e"/>
          <rect x="4" y="12" width="6" height="1" fill="#c9c2ae"/>
          <rect x="11" y="1" width="1" height="6" fill="#3a3a34"/>
        </svg>
      </a>
      <div>
        <?php if ($retroIsHome): ?><h1 class="site-title"><a href="<?= h($retroHomeUrl) ?>"><?= h($siteName) ?></a></h1>
        <?php else: ?><div class="site-title"><a href="<?= h($retroHomeUrl) ?>"><?= h($siteName) ?></a></div><?php endif; ?>
        <?php if ($retroSubtitle !== ''): ?><p class="subtitle mono"><?= h($retroSubtitle) ?><?php if ((int)($retroStats['latest_post'] ?? 0) >= time() - 7 * 86400): ?><span class="blink">✦ NEW!</span><?php endif; ?></p><?php endif; ?>
      </div>
    </header>
    <nav class="retro-nav" aria-label="<?= h(sblog_t('主菜单')) ?>">
      <?php if (function_exists('sblog_menu_is_managed') && sblog_menu_is_managed()): ?>
        <?= sblog_menu_render($themeContext, ['item_tag' => 'span', 'item_class' => 'retro-nav-item', 'url' => static fn(string $url): string => retro_home_url($url)]) ?>
      <?php else: ?>
        <?php foreach ($retroNav as $item): ?><a href="<?= h($item['url']) ?>"<?= $item['current'] ? ' aria-current="page"' : '' ?>><?= h($item['label']) ?></a><?php endforeach; ?>
      <?php endif; ?>
      <span class="nav-note mono">◆ 最佳浏览分辨率 800 × 600</span>
    </nav>
    <?php theme_action('header_after', $themeContext); ?>
    <div class="layout">
      <main id="main-content" tabindex="-1">
        <?php if ($flash): ?><div class="text-notice" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
        <?php theme_action('content_before', $themeContext); ?>
        <?= $retroContent ?>
        <?php theme_action('content_after', $themeContext); ?>
      </main>
      <aside class="retro-sidebar" aria-label="小站侧栏">
        <?php if (retro_home_option('status_text') !== '' || retro_home_option('now_playing') !== ''): ?>
        <div class="card">
          <h3 class="mono">STATUS / 状态</h3>
          <?php if (retro_home_option('status_text') !== ''): ?><p class="status mono"><?= h(retro_home_option('status_text')) ?></p><?php endif; ?>
          <?php if (retro_home_option('now_playing') !== ''): ?><p class="now-playing mono">NOW PLAYING: <?= h(retro_home_option('now_playing')) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (retro_home_option('show_clock', '1') === '1'): ?>
        <div class="card">
          <h3 class="mono">LOCAL TIME / 本地时间</h3>
          <time class="clock" id="retro-clock">--:--:--</time>
        </div>
        <?php endif; ?>
        <?php if (retro_home_option('show_counter', '1') === '1'): ?>
        <div class="card">
          <h3 class="mono">READS / 文章阅读</h3>
          <div class="counter" role="img" aria-label="文章累计阅读 <?= h($retroReads) ?> 次">
            <?php foreach (str_split($retroReads) as $digit): ?><span aria-hidden="true"><?= h($digit) ?></span><?php endforeach; ?>
          </div>
          <?php if ($retroSince !== ''): ?><p class="counter-note mono">SINCE <?= h(str_replace('-', '.', $retroSince)) ?></p><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if (retro_home_option('show_badges', '1') === '1'): ?>
        <div class="card">
          <h3 class="mono">BADGES / 徽章</h3>
          <div class="badges">
            <span class="badge">BEST VIEWED<br>800 × 600</span>
            <span class="badge alt">MADE<br>BY HAND</span>
            <span class="badge">NO<br>FRAMES</span>
            <span class="badge alt">POWERED<br>BY ♥</span>
          </div>
        </div>
        <?php endif; ?>
        <?php if (retro_home_option('quote') !== ''): ?>
        <div class="card">
          <h3 class="mono">WORDS / 摘句</h3>
          <blockquote><?= nl2br(h(retro_home_option('quote')), false) ?><?php if (retro_home_option('quote_author') !== ''): ?><cite>— <?= h(retro_home_option('quote_author')) ?></cite><?php endif; ?></blockquote>
        </div>
        <?php endif; ?>
      </aside>
    </div>
    <?php theme_action('footer_before', $themeContext); ?>
    <footer class="foot" id="guestbook">
      <div class="rule" aria-hidden="true">· ◆ ·</div>
      <?php if ($retroEmail !== ''): ?><div>想说点什么？写信到 <a href="mailto:<?= h($retroEmail) ?>"><?= h($retroEmail) ?></a>，我会认真回。</div><?php endif; ?>
      <div><?= h($retroFooter) ?> &nbsp;·&nbsp; <a href="<?= h(url_for('rss')) ?>">RSS</a> &nbsp;·&nbsp; <a href="<?= h(retro_home_url(url_for('tags'))) ?>">标签</a> &nbsp;·&nbsp; <a href="<?= h(retro_home_url(url_for('categories'))) ?>">分类</a> &nbsp;·&nbsp; <a href="#top">↑ 回到顶部</a></div>
      <?php if (trim(setting('footer_beian')) !== ''): ?><div><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= h(setting('footer_beian')) ?></a></div><?php endif; ?>
    </footer>
    <?php theme_action('footer_after', $themeContext); ?>
  </div>
  <script src="<?= h(theme_asset_url('assets/theme.js')) ?>?v=<?= h((string)filemtime(__DIR__ . '/assets/theme.js')) ?>" defer></script>
  <script src="<?= h(asset_url('assets/index.js')) ?>?v=<?= h(APP_VERSION) ?>" defer></script>
  <?php theme_action('body_close', $themeContext); ?>
</body>
</html>
