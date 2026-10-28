<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

$sirenAction = (string)($GLOBALS['sblog_current_action'] ?? 'home');
$sirenHome = $sirenAction === 'home' && $status < 400;
$sirenSearch = $sirenHome ? hsiren_search() : '';
$sirenHero = $sirenHome && $sirenSearch === '' && hsiren_option('show_hero', '1') === '1';
$sirenCover = hsiren_safe_url(hsiren_option('hero_image')) ?: hsiren_fallback_cover(hsiren_option('random_covers', '1') === '1' ? random_int(0, 4) : 0);
$sirenAvatar = hsiren_safe_url(hsiren_option('avatar_image')) ?: hsiren_asset('assets/images/avatar.jpg');
$sirenLogo = hsiren_safe_url(hsiren_option('logo_image'));
$sirenProfile = hsiren_option('profile_description') ?: setting('site_tagline');
$sirenContent = hsiren_content($content, $sirenAction, $status);
$sirenBanner = false;
$sirenMeta = '';
if ($status < 400 && hsiren_option('show_post_cover', '1') === '1' && in_array($sirenAction, ['post', 'page'], true)) {
    $sirenPost = fetch_content_by_identifier($sirenAction, trim((string)($_GET['slug'] ?? $_GET['id'] ?? '')), is_admin());
    if ($sirenPost) {
        $sirenBanner = true;
        $sirenCover = hsiren_post_cover($sirenPost);
        // Only move the core's leading title and metadata. Body, gates and comments stay intact.
        $sirenContent = preg_replace_callback('/\A(\s*<article\b[^>]*>)\s*<h1\b[^>]*class="post-title"[^>]*>.*?<\/h1>\s*(<div class="meta">.*?<\/div>\s*)?/s', static function (array $match) use (&$sirenMeta): string {
            $sirenMeta = $match[2] ?? '';
            return $match[1];
        }, $sirenContent, 1) ?? $sirenContent;
    }
}
$sirenLinks = [];
foreach (['home' => '首页', 'archives' => '归档', 'tags' => '标签', 'links' => '链接'] as $route => $label) {
    $sirenLinks[] = ['label' => sblog_t($label), 'url' => hsiren_url(url_for($route)), 'active' => $route === 'home' ? $active === 'home' : $active === $route];
}
foreach ($navPages as $navPage) { $sirenLinks[] = ['label' => $navPage['title'], 'url' => hsiren_url(content_permalink($navPage)), 'active' => $active === 'page:' . $navPage['slug']]; }
$sirenAccent = hsiren_option('accent_color', '#FE9600');
if (!preg_match('/^#[0-9a-f]{6}$/i', $sirenAccent)) { $sirenAccent = '#FE9600'; }
$sirenBlur = hsiren_option('background_style', 'simple') === 'blur';
if ($sirenSearch !== '') { $fullTitle = sblog_t('搜索') . '：' . $sirenSearch . ' · ' . $siteName; }
?>
<!doctype html>
<html lang="<?= h(sblog_i18n_locale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="<?= h($sirenAccent) ?>">
  <meta name="description" content="<?= h($description) ?>">
  <?php if ($keywords !== ''): ?><meta name="keywords" content="<?= h($keywords) ?>"><?php endif; ?>
  <title><?= h($fullTitle) ?></title>
  <meta property="og:title" content="<?= h($title) ?>">
  <meta property="og:description" content="<?= h($description) ?>">
  <meta property="og:site_name" content="<?= h($siteName) ?>">
  <meta property="og:type" content="<?= $sirenAction === 'post' && $status < 400 ? 'article' : 'website' ?>">
  <link rel="icon" href="<?= h(theme_favicon_url()) ?>">
  <link rel="alternate" type="application/rss+xml" href="<?= h(url_for('rss')) ?>" title="<?= h($siteName) ?>">
  <?= sblog_i18n_head() ?>
  <?php theme_action('head', $themeContext); ?>
  <?php if ($customHeadCode !== ''): ?><?= $customHeadCode . "\n" ?><?php endif; ?>
</head>
<body class="<?= h($bodyClass) ?> h-siren siren-background-<?= $sirenBlur ? 'blur' : 'simple' ?><?= $sirenHero ? ' has-hero' : ($sirenBanner ? '' : ' siren-no-hero') ?>" style="--siren-accent:<?= h($sirenAccent) ?>" data-lightbox="<?= hsiren_option('image_lightbox', '1') === '1' ? '1' : '0' ?>" data-click-effects="<?= hsiren_option('click_effects') === '1' ? '1' : '0' ?>">
  <?php theme_action('body_open', $themeContext); ?>
  <a class="skip-link" href="#content"><?= h(sblog_t('跳到正文')) ?></a>
  <?php if ($sirenBlur): ?><div class="siren-wallpaper" aria-hidden="true"><img src="<?= h($sirenCover) ?>" alt=""></div><?php endif; ?>
  <?php theme_action('header_before', $themeContext); ?>
  <header class="site-header"><div class="site-top">
    <div class="site-branding"><div class="site-title"><a href="<?= h(hsiren_url(url_for('home'))) ?>"><?php if ($sirenLogo !== ''): ?><img src="<?= h($sirenLogo) ?>" alt="<?= h($siteName) ?>"><?php else: ?><?= h($siteName) ?><?php endif; ?></a></div></div>
    <div class="lower"><nav id="siren-navigation" aria-label="<?= h(sblog_t('主菜单')) ?>">
      <?php if (function_exists('sblog_menu_is_managed') && sblog_menu_is_managed()): ?>
        <?= sblog_menu_render($themeContext, ['url' => static fn(string $url): string => hsiren_url($url)]) ?>
      <?php else: ?><ul><?php foreach ($sirenLinks as $link): ?><li><a href="<?= h($link['url']) ?>"<?= $link['active'] ? ' aria-current="page"' : '' ?>><?= h($link['label']) ?></a></li><?php endforeach; ?></ul><?php endif; ?>
    </nav></div>
    <?php if (hsiren_option('show_search', '1') === '1'): ?><button class="siren-search-toggle" type="button" data-siren-search-toggle aria-label="<?= h(sblog_t('搜索')) ?>" aria-controls="siren-search-dialog" aria-expanded="false"><svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/></svg></button><?php endif; ?>
    <button class="siren-menu-toggle" type="button" aria-label="<?= h(sblog_t('主菜单')) ?>" aria-controls="siren-navigation" aria-expanded="false"><span aria-hidden="true">☰</span></button>
  </div></header>
  <?php theme_action('header_after', $themeContext); ?>
  <?php if ($sirenHero): ?>
  <section class="headertop siren-hero" aria-label="<?= h($siteName) ?>">
    <img class="siren-hero-image" src="<?= h($sirenCover) ?>" alt="" fetchpriority="high">
    <div class="siren-hero-shade"></div>
    <div class="siren-profile">
      <a class="siren-avatar" href="<?= h(hsiren_url(url_for('home'))) ?>"><img src="<?= h($sirenAvatar) ?>" alt="<?= h($siteName) ?>" width="130" height="130"></a>
      <?php if ($sirenProfile !== ''): ?><div class="siren-description"><p><?= nl2br(h($sirenProfile)) ?></p></div><?php endif; ?>
      <div class="siren-socials">
        <?php foreach (['github' => 'GitHub', 'bilibili' => '哔哩哔哩', 'weibo' => '微博', 'twitter' => 'X / Twitter', 'mastodon' => 'Mastodon'] as $key => $label): $socialUrl = hsiren_safe_url(hsiren_option($key . '_url')); if ($socialUrl === '') { continue; } ?>
        <a href="<?= h($socialUrl) ?>" target="_blank" rel="me noopener noreferrer" aria-label="<?= h($label) ?>"><?php if (is_file(__DIR__ . '/assets/images/social/' . $key . '.png')): ?><img src="<?= h(hsiren_asset('assets/images/social/' . $key . '.png')) ?>" alt="" width="28" height="28"><?php else: ?><?= h($label) ?><?php endif; ?></a>
        <?php endforeach; ?>
        <a href="<?= h(url_for('rss')) ?>" aria-label="RSS"><svg viewBox="0 0 24 24" width="25" height="25" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><circle cx="5" cy="19" r="1" fill="currentColor"/><path d="M4 11a9 9 0 0 1 9 9M4 4a16 16 0 0 1 16 16"/></svg></a>
      </div>
    </div>
    <a class="siren-scroll" href="#content" aria-label="<?= h(sblog_t('跳到正文')) ?>"><span aria-hidden="true">⌄</span></a>
  </section>
  <?php elseif ($sirenBanner): ?>
  <div class="pattern-center"><div class="pattern-attachment-img"><img src="<?= h($sirenCover) ?>" alt="" fetchpriority="high"></div><header class="pattern-header"><h1><?= h($title) ?></h1><?= $sirenMeta ?></header></div>
  <?php endif; ?>
  <main id="content" class="site-content" tabindex="-1">
    <?php if ($flash): ?><div class="text-notice" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
    <?php theme_action('content_before', $themeContext); ?>
    <?= hsiren_preview_links($sirenContent) ?>
    <?php theme_action('content_after', $themeContext); ?>
  </main>
  <?php theme_action('footer_before', $themeContext); ?>
  <footer class="site-footer"><div class="site-info">
    <img class="foo-logo" src="<?= h(hsiren_asset('assets/images/footer-logo.png')) ?>" alt="" loading="lazy">
    <p><?= nl2br(h(hsiren_option('footer_text') ?: site_footer_text())) ?></p>
    <?php if (hsiren_option('show_credit', '1') === '1'): ?><p>Theme <a href="https://github.com/galnetwen/H-Siren" target="_blank" rel="noopener noreferrer">H-Siren</a> · Powered by <a href="https://github.com/jkjoy/Simple-PHP-Blog" target="_blank" rel="noopener noreferrer">SBlog</a></p><?php endif; ?>
    <?php $sirenBeian = trim(setting('footer_beian')); if ($sirenBeian !== ''): ?><p><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= h($sirenBeian) ?></a></p><?php endif; ?>
    <?php $sirenStart = hsiren_option('start_date'); if ($sirenStart !== ''): $sirenDays = max(0, (int)floor((strtotime(date('Y-m-d') . ' UTC') - strtotime($sirenStart . ' UTC')) / 86400)); ?><p data-siren-start-date="<?= h($sirenStart) ?>"><?= h(sblog_t('本站已运行 {days} 天', ['days' => $sirenDays])) ?></p><?php endif; ?>
  </div></footer>
  <?php theme_action('footer_after', $themeContext); ?>
  <a class="siren-back-top" href="#" aria-label="<?= h(sblog_t('返回顶部')) ?>">↑</a>
  <?php if (hsiren_option('show_search', '1') === '1'): ?>
  <div id="siren-search-dialog" class="siren-search" data-siren-search role="dialog" aria-modal="true" aria-labelledby="siren-search-title" hidden>
    <button class="siren-search-close" data-siren-search-close type="button" aria-label="<?= h(sblog_t('关闭')) ?>">×</button>
    <form method="get" action="<?= h(script_url()) ?>">
      <h2 id="siren-search-title"><?= h(sblog_t('搜索文章标题')) ?></h2><input type="hidden" name="a" value="home">
      <?php if (($_GET['theme_preview'] ?? '') === 'h-siren' && is_admin()): ?><input type="hidden" name="theme_preview" value="h-siren"><?php endif; ?>
      <label class="sr-only" for="siren-search-input"><?= h(sblog_t('搜索文章标题')) ?></label><input id="siren-search-input" type="search" name="q" maxlength="100" value="<?= h($sirenSearch) ?>" placeholder="<?= h(sblog_t('输入关键词后按回车…')) ?>" required><button type="submit"><?= h(sblog_t('搜索')) ?></button>
    </form>
  </div>
  <?php endif; ?>
  <script src="<?= h(asset_url('assets/index.js')) ?>?v=<?= h(APP_VERSION) ?>" defer></script>
  <script src="<?= h(hsiren_asset('assets/theme.js')) ?>" defer></script>
  <?php theme_action('body_close', $themeContext); ?>
</body>
</html>
