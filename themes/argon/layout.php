<?php

declare(strict_types=1);

if (!defined('APP_VERSION')) { http_response_code(403); exit; }

$argon = argon_options();
$argonAction = (string)($GLOBALS['sblog_current_action'] ?? 'home');
$argonDetail = in_array($argonAction, ['post', 'page'], true) && $status < 400;
$argonContent = argon_preview_links(argon_content($content, $argonAction, $status));
$argonSubtitle = $argon['banner_subtitle'] ?: setting('site_tagline');
$argonAuthor = one('SELECT nickname, username FROM users ORDER BY id LIMIT 1') ?? [];
$argonAuthorName = $argon['author_name'] ?: (trim((string)($argonAuthor['nickname'] ?? '')) ?: (string)($argonAuthor['username'] ?? $siteName));
$argonTags = tag_index_data();
$argonClasses = 'article-header-style-default' . (!$argon['sidebar_left'] ? ' single-column' : ($argon['sidebar_right'] ? ' triple-column' : ''));
foreach (['immersion_color' => 'immersion-color', 'amoled_dark' => 'amoled-dark'] as $key => $class) { if ($argon[$key]) { $argonClasses .= ' ' . $class; } }
if ($argonAction === 'home') { $argonClasses .= ' is-home'; }
$argonNav = [
    ['title' => sblog_t('首页'), 'url' => url_for('home'), 'icon' => 'home', 'active' => $argonAction === 'home'],
    ['title' => sblog_t('归档'), 'url' => url_for('archives'), 'icon' => 'archive', 'active' => $argonAction === 'archives'],
    ['title' => sblog_t('标签'), 'url' => url_for('tags'), 'icon' => 'tags', 'active' => in_array($argonAction, ['tag', 'tags'], true)],
    ['title' => sblog_t('链接'), 'url' => url_for('links'), 'icon' => 'link', 'active' => $argonAction === 'links'],
];
foreach ($navPages as $page) { $argonNav[] = ['title' => $page['title'], 'url' => content_permalink($page), 'icon' => 'file-text-o', 'active' => $active === 'page:' . $page['slug']]; }
$argonConfig = array_intersect_key($argon, array_flip(['primary_color', 'darkmode', 'card_radius', 'font_family', 'card_shadow']));
$argonConfig['is_detail'] = $argonDetail;
$argonRGB = sscanf($argon['primary_color'], '#%02x%02x%02x');
$argonBannerStyle = $argon['banner_background'] !== '' ? 'background-image:url(' . json_encode($argon['banner_background'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ')' : '';
?>
<!doctype html>
<html lang="<?= h(sblog_i18n_locale()) ?>" class="no-js <?= h($argonClasses) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light dark">
  <meta name="theme-color" content="<?= h($argon['primary_color']) ?>">
  <meta name="description" content="<?= h($description) ?>">
  <?php if ($keywords !== ''): ?><meta name="keywords" content="<?= h($keywords) ?>"><?php endif; ?>
  <title><?= h($fullTitle) ?></title>
  <meta property="og:title" content="<?= h($title) ?>">
  <meta property="og:description" content="<?= h($description) ?>">
  <meta property="og:site_name" content="<?= h($siteName) ?>">
  <meta property="og:type" content="<?= $argonDetail && $argonAction === 'post' ? 'article' : 'website' ?>">
  <link rel="icon" href="<?= h(theme_favicon_url()) ?>">
  <link rel="alternate" type="application/rss+xml" href="<?= h(url_for('rss')) ?>" title="<?= h($siteName) ?>">
  <link rel="preload" href="<?= h(theme_asset_url('assets/fonts/open-sans-latin-variable.woff2')) ?>" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= h(argon_asset('assets/css/argon.min.css')) ?>">
  <link rel="stylesheet" href="<?= h(argon_asset('assets/vendor/font-awesome/css/font-awesome.min.css')) ?>">
  <link rel="stylesheet" href="<?= h(argon_asset('assets/vendor/nucleo/css/nucleo.css')) ?>">
  <link rel="stylesheet" href="<?= h(argon_asset('assets/fonts.css')) ?>">
  <link rel="stylesheet" href="<?= h(argon_asset('assets/argon.css')) ?>">
  <?php theme_action('head', $themeContext); ?>
  <link rel="stylesheet" href="<?= h(argon_asset('assets/interaction.css')) ?>">
  <style>
    :root { --themecolor: <?= h($argon['primary_color']) ?>; --themecolor-R: <?= (int)$argonRGB[0] ?>; --themecolor-G: <?= (int)$argonRGB[1] ?>; --themecolor-B: <?= (int)$argonRGB[2] ?>; --card-radius: <?= (int)$argon['card_radius'] ?>px; }
    html:not(.darkmode):not(.immersion-color) body { --color-background: <?= h($argon['background_color']) ?>; }
    <?php if ($argon['banner_height']): ?>
    #banner { height: <?= $argon['banner_height'] ?>px; }
    #banner_container { height: <?= max(80, $argon['banner_height'] - 220) ?>px !important; }
    #content { margin-top: -100px; }
    @media (max-width: 900px) { #content { margin-top: -35px; } }
    <?php endif; ?>
  </style>
  <script>window.argonConfig = <?= json_encode($argonConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>
  <script src="<?= h(argon_asset('assets/theme.js')) ?>"></script>
  <?= sblog_i18n_head() ?>
  <?php if ($customHeadCode !== ''): ?><?= $customHeadCode . "\n" ?><?php endif; ?>
  <?php if ($argon['custom_css'] !== ''): ?><style id="argon-custom-css"><?= str_replace('<', '\\3C ', $argon['custom_css']) ?></style><?php endif; ?>
</head>
<body class="<?= h($bodyClass) ?> argon <?= $argonDetail ? ($argonAction === 'post' ? 'single' : 'page') : 'blog' ?>">
<?php theme_action('body_open', $themeContext); ?>
<a class="skip-link" href="#main"><?= h(sblog_t('跳到正文')) ?></a>
<?php theme_action('header_before', $themeContext); ?>
<div id="toolbar">
  <header class="header-global">
    <nav id="navbar-main" class="navbar navbar-main navbar-expand-lg navbar-transparent navbar-light bg-primary headroom--pinned navbar-ontop" aria-label="<?= h(sblog_t('主菜单')) ?>">
      <div class="container">
        <button id="open_sidebar" class="navbar-toggler" type="button" data-argon-action="sidebar" aria-controls="leftbar" aria-expanded="false" aria-label="<?= h(sblog_t('打开菜单')) ?>"><span class="navbar-toggler-icon"></span></button>
        <div class="navbar-brand mr-0"><a class="navbar-brand navbar-title" href="<?= h(argon_url(url_for('home'))) ?>"><?= h($argon['navbar_title'] ?: $siteName) ?></a></div>
        <div class="navbar-collapse collapse" id="navbar_global">
          <ul class="navbar-nav navbar-nav-hover align-items-lg-center">
            <?php if (function_exists('sblog_menu_is_managed') && sblog_menu_is_managed()): ?>
              <?= sblog_menu_render($themeContext, ['item_tag' => 'li', 'item_class' => 'nav-item', 'link_class' => 'nav-link', 'url' => static fn(string $url): string => argon_url($url)]) ?>
            <?php else: foreach ($argonNav as $link): ?>
              <li class="nav-item"><a class="nav-link" href="<?= h(argon_url($link['url'])) ?>"<?= $link['active'] ? ' aria-current="page"' : '' ?>><span class="nav-link-inner--text"><?= h($link['title']) ?></span></a></li>
            <?php endforeach; endif; ?>
          </ul>
          <ul class="navbar-nav align-items-lg-center ml-lg-auto"><li class="nav-item"><button id="navbar_search_container" class="argon-navbar-search nav-link" type="button" data-argon-action="search" aria-label="<?= h(sblog_t('搜索')) ?>"><?= argon_icon('search') ?></button></li></ul>
        </div>
        <button class="navbar-toggler argon-mobile-search" type="button" data-argon-action="search" aria-label="<?= h(sblog_t('搜索')) ?>"><?= argon_icon('search') ?></button>
      </div>
    </nav>
  </header>
</div>
<section id="banner" class="banner section section-lg section-shaped"<?= $argonBannerStyle !== '' ? ' style="' . h($argonBannerStyle) . '"' : '' ?> aria-label="<?= h($siteName) ?>">
  <div class="shape <?= $argon['banner_shape'] ? 'shape-style-1 ' : '' ?>shape-primary"<?= $argon['banner_background'] !== '' ? ' style="opacity:.55"' : '' ?>><?php for ($i = 0; $i < 9; $i++): ?><span></span><?php endfor; ?></div>
  <div id="banner_container" class="banner-container container text-center">
    <div class="banner-title text-white"><span class="banner-title-inner"><?= h($argon['banner_title'] ?: $siteName) ?></span><?php if ($argonSubtitle !== ''): ?><span class="banner-subtitle d-block"><?= h($argonSubtitle) ?></span><?php endif; ?></div>
  </div>
</section>
<?php theme_action('header_after', $themeContext); ?>
<div id="sidebar_mask"></div>
<div id="content" class="site-content">
  <aside id="leftbar" class="leftbar widget-area" aria-label="<?= h(sblog_t('侧边栏')) ?>">
    <?php if ($argon['announcement'] !== ''): ?><div id="leftbar_announcement" class="card bg-white shadow-sm border-0"><div class="leftbar-announcement-body"><div class="leftbar-announcement-title text-white"><?= h(sblog_t('公告')) ?></div><div class="leftbar-announcement-content text-white"><?= nl2br(h($argon['announcement']), false) ?></div></div></div><?php endif; ?>
    <div id="leftbar_part1" class="widget card bg-white shadow-sm border-0">
      <div class="leftbar-banner card-body"><span class="leftbar-banner-title text-white"><?= h($siteName) ?></span><?php if ($argonSubtitle !== ''): ?><span class="leftbar-banner-subtitle text-white"><?= h($argonSubtitle) ?></span><?php endif; ?></div>
      <nav aria-label="<?= h(sblog_t('站点导航')) ?>"><ul id="leftbar_part1_menu" class="leftbar-menu">
        <?php if (function_exists('sblog_menu_is_managed') && sblog_menu_is_managed()): ?>
          <?= sblog_menu_render($themeContext, ['item_tag' => 'li', 'item_class' => 'leftbar-menu-item', 'url' => static fn(string $url): string => argon_url($url)]) ?>
        <?php else: foreach ($argonNav as $link): ?><li class="leftbar-menu-item<?= $link['active'] ? ' current' : '' ?>"><a href="<?= h(argon_url($link['url'])) ?>"<?= $link['active'] ? ' aria-current="page"' : '' ?>><?= argon_icon($link['icon']) ?> <?= h($link['title']) ?></a></li><?php endforeach; endif; ?>
      </ul></nav>
      <div class="card-body text-center leftbar-search-button"><button id="leftbar_search_container" class="btn btn-secondary btn-lg active btn-sm btn-block border-0" type="button" data-argon-action="search"><?= argon_icon('search') ?> <?= h(sblog_t('搜索')) ?></button></div>
    </div>
    <div id="leftbar_part2" class="widget card bg-white shadow-sm border-0">
      <div id="leftbar_part2_inner" class="card-body">
        <div class="nav-wrapper"><ul class="nav nav-pills nav-fill" role="tablist" aria-label="<?= h(sblog_t('侧边栏')) ?>">
          <?php if ($argonDetail && $argon['show_toc']): ?><li class="nav-item sidebar-tab-switcher"><a class="active show" id="leftbar_tab_catalog_btn" data-toggle="tab" href="#leftbar_tab_catalog" role="tab" aria-controls="leftbar_tab_catalog" aria-selected="true"><?= h(sblog_t('文章目录')) ?></a></li><?php endif; ?>
          <li class="nav-item sidebar-tab-switcher"><a class="<?= !$argonDetail || !$argon['show_toc'] ? 'active show' : '' ?>" id="leftbar_tab_overview_btn" data-toggle="tab" href="#leftbar_tab_overview" role="tab" aria-controls="leftbar_tab_overview" aria-selected="<?= !$argonDetail || !$argon['show_toc'] ? 'true' : 'false' ?>"><?= h(sblog_t('站点概览')) ?></a></li>
        </ul></div>
        <div class="tab-content argon-sidebar-tabs">
          <?php if ($argonDetail && $argon['show_toc']): ?><div class="tab-pane fade active show" id="leftbar_tab_catalog" role="tabpanel" aria-labelledby="leftbar_tab_catalog_btn"><div id="leftbar_catalog"></div></div><?php endif; ?>
          <div class="tab-pane fade <?= !$argonDetail || !$argon['show_toc'] ? 'active show' : '' ?>" id="leftbar_tab_overview" role="tabpanel" aria-labelledby="leftbar_tab_overview_btn">
            <img id="leftbar_overview_author_image" class="rounded-circle" src="<?= h($argon['avatar'] ?: theme_asset_url('assets/default-avatar.svg')) ?>" alt="<?= h($argonAuthorName) ?>" width="100" height="100" loading="lazy">
            <div id="leftbar_overview_author_name" class="text-center"><?= h($argonAuthorName) ?></div>
            <?php if ($argon['author_description'] !== ''): ?><div id="leftbar_overview_author_description" class="text-center"><?= nl2br(h($argon['author_description']), false) ?></div><?php endif; ?>
            <div class="site-state">
              <?php foreach ([['archives', count_published_posts(), '文章'], ['categories', count(array_filter(argon_categories(), static fn(array $c): bool => (int)$c['post_count'] > 0)), '分类'], ['tags', count($argonTags), '标签']] as [$route, $count, $label]): ?><div class="site-state-item"><a href="<?= h(argon_url(url_for($route))) ?>"><span class="site-state-item-count"><?= $count ?></span><span class="site-state-item-name"><?= h(sblog_t($label)) ?></span></a></div><?php endforeach; ?>
            </div>
            <div class="site-author-links">
              <?php if ($argon['social_github'] !== ''): ?><div class="site-author-links-item"><a href="<?= h($argon['social_github']) ?>" target="_blank" rel="noopener noreferrer"><?= argon_icon('github') ?> GitHub</a></div><?php endif; ?>
              <?php if (filter_var($argon['social_email'], FILTER_VALIDATE_EMAIL)): ?><div class="site-author-links-item"><a href="mailto:<?= h($argon['social_email']) ?>"><?= argon_icon('envelope') ?> E-Mail</a></div><?php endif; ?>
              <?php if ($argon['social_rss']): ?><div class="site-author-links-item"><a href="<?= h(url_for('rss')) ?>"><?= argon_icon('rss') ?> RSS</a></div><?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
    <noscript><form class="card card-body" method="get" action="<?= h(url_for('home')) ?>"><label for="argon-noscript-search"><?= h(sblog_t('搜索')) ?></label><input id="argon-noscript-search" name="q" type="search" class="form-control"><button class="btn btn-primary mt-2" type="submit"><?= h(sblog_t('搜索')) ?></button></form></noscript>
  </aside>
  <div id="primary" class="content-area">
    <main id="main" class="site-main <?= $argonDetail ? 'article-detail' : 'article-list' ?>" tabindex="-1">
      <?php if ($flash): ?><div class="card card-body argon-notice" role="status"><?= h($flash['message']) ?></div><?php endif; ?>
      <?php theme_action('content_before', $themeContext); ?>
      <?= $argonContent ?>
      <?php theme_action('content_after', $themeContext); ?>
      <?php theme_action('footer_before', $themeContext); ?>
      <footer id="footer" class="site-footer card shadow-sm border-0">
        <?php if ($argon['footer_text'] !== ''): ?><div><?= nl2br(h($argon['footer_text']), false) ?></div><?php else: ?><div>© <?= $argon['footer_since'] < (int)date('Y') ? $argon['footer_since'] . '–' : '' ?><?= date('Y') ?> <?= h($siteName) ?></div><?php endif; ?>
        <div>Powered by <a href="https://github.com/jkjoy/Simple-PHP-Blog" target="_blank" rel="noopener noreferrer">SBlog</a> · Theme <a href="https://github.com/solstice23/argon-theme" target="_blank" rel="noopener noreferrer"><strong>Argon</strong></a> By solstice23</div>
        <?php if (trim(setting('footer_beian')) !== ''): ?><div><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= h(setting('footer_beian')) ?></a></div><?php endif; ?>
      </footer>
      <?php theme_action('footer_after', $themeContext); ?>
    </main>
  </div>
  <?php if ($argon['sidebar_left'] && $argon['sidebar_right']): ?>
  <aside id="rightbar" class="widget-area" aria-label="<?= h(sblog_t('文章分类')) ?>">
    <div class="card shadow-sm border-0"><h6><?= argon_icon('folder-open-o') ?> <?= h(sblog_t('分类')) ?></h6><ul><?php foreach (argon_categories() as $category): if (!(int)$category['post_count']) { continue; } ?><li><a href="<?= h(argon_url(url_for('category', ['slug' => $category['slug']]))) ?>"><?= h($category['name']) ?> <span class="tag-num">(<?= (int)$category['post_count'] ?>)</span></a></li><?php endforeach; ?></ul></div>
    <div class="card shadow-sm border-0"><h6><?= argon_icon('tags') ?> <?= h(sblog_t('标签')) ?></h6><div><?php foreach ($argonTags as $tag): ?><a class="tag badge badge-secondary post-meta-detail-tag" href="<?= h(argon_url(url_for('tag', ['slug' => $tag['slug']]))) ?>"><?= h($tag['label']) ?> <span class="tag-num"><?= (int)$tag['count'] ?></span></a><?php endforeach; ?></div></div>
  </aside>
  <?php endif; ?>
</div>
<dialog id="search-modal" class="argon-search-dialog" aria-labelledby="argon-search-title">
  <div class="modal-header"><h2 id="argon-search-title" class="modal-title"><?= h(sblog_t('搜索')) ?></h2><button type="button" class="close" data-argon-action="closeSearch" aria-label="<?= h(sblog_t('关闭')) ?>">×</button></div>
  <form method="get" action="<?= h(url_for('home')) ?>" class="modal-body">
    <?php if (($_GET['theme_preview'] ?? '') === 'argon' && is_admin()): ?><input type="hidden" name="theme_preview" value="argon"><?php endif; ?>
    <label class="sr-only" for="argon-search-query"><?= h(sblog_t('搜索')) ?></label>
    <div class="input-group input-group-alternative"><div class="input-group-prepend"><span class="input-group-text"><?= argon_icon('search') ?></span></div><input id="argon-search-query" name="q" type="search" class="form-control" placeholder="<?= h(sblog_t('搜索什么...')) ?>" maxlength="100" value="<?= h(is_string($_GET['q'] ?? null) ? str_sub_u($_GET['q'], 0, 100) : '') ?>" required><button class="btn btn-primary" type="submit"><?= h(sblog_t('搜索')) ?></button></div>
    <p class="argon-search-hint">Ctrl / ⌘ K</p>
  </form>
</dialog>
<div id="float_action_buttons" class="float-action-buttons">
  <button id="fabtn_toggle_sides" class="btn btn-icon btn-neutral fabtn shadow-sm" type="button" data-argon-action="sides" aria-label="<?= h(sblog_t('移动工具栏')) ?>"><span class="fabtn-show-on-right"><?= argon_icon('caret-left') ?></span><span class="fabtn-show-on-left"><?= argon_icon('caret-right') ?></span></button>
  <button id="fabtn_back_to_top" class="btn btn-icon btn-neutral fabtn shadow-sm" type="button" data-argon-action="backtop" aria-label="<?= h(sblog_t('回到顶部')) ?>" tooltip="<?= h(sblog_t('回到顶部')) ?>"><?= argon_icon('angle-up') ?></button>
  <?php if ($argon['sidebar_left']): ?><button id="fabtn_open_sidebar" class="btn btn-icon btn-neutral fabtn shadow-sm" type="button" data-argon-action="sidebar" aria-controls="leftbar" aria-expanded="false" aria-label="<?= h(sblog_t('打开菜单')) ?>" tooltip="<?= h(sblog_t('侧边栏')) ?>"><?= argon_icon('bars') ?></button><?php endif; ?>
  <?php if ($argonDetail): ?><button id="fabtn_go_to_comment" class="btn btn-icon btn-neutral fabtn shadow-sm" type="button" data-argon-action="comments" aria-label="<?= h(sblog_t('评论')) ?>" tooltip="<?= h(sblog_t('评论')) ?>"><?= argon_icon('comment-o') ?></button><button id="fabtn_readingmode" class="btn btn-icon btn-neutral fabtn shadow-sm" type="button" data-argon-action="readingmode" aria-label="<?= h(sblog_t('阅读模式')) ?>" aria-pressed="false" tooltip="<?= h(sblog_t('阅读模式')) ?>"><?= argon_icon('book') ?></button><?php endif; ?>
  <button id="fabtn_toggle_darkmode" class="btn btn-icon btn-neutral fabtn shadow-sm" type="button" data-argon-action="darkmode" aria-pressed="false" aria-label="<?= h(sblog_t('夜间模式')) ?>" tooltip-darkmode="<?= h(sblog_t('夜间模式')) ?>" tooltip-lightmode="<?= h(sblog_t('日间模式')) ?>"><?= argon_icon('moon-o') ?><?= argon_icon('lightbulb-o') ?></button>
  <button id="fabtn_toggle_blog_settings_popup" class="btn btn-icon btn-neutral fabtn shadow-sm" type="button" data-argon-action="customize" aria-controls="fabtn_blog_settings_popup" aria-expanded="false" aria-label="<?= h(sblog_t('设置')) ?>" tooltip="<?= h(sblog_t('设置')) ?>"><?= argon_icon('cog') ?></button>
  <div id="fabtn_blog_settings_popup" class="card shadow-sm" aria-hidden="true">
    <button id="close_blog_settings" class="argon-panel-close" type="button" data-argon-action="closeCustomize" aria-label="<?= h(sblog_t('关闭')) ?>"><?= argon_icon('close') ?></button>
    <div class="blog-setting-item mt-3"><label for="argon-display-mode"><?= h(sblog_t('显示模式')) ?></label><select id="argon-display-mode" class="form-control form-control-sm" data-argon-mode><option value="auto"><?= h(sblog_t('跟随系统')) ?></option><option value="light"><?= h(sblog_t('日间模式')) ?></option><option value="dark"><?= h(sblog_t('夜间模式')) ?></option></select></div>
    <div class="blog-setting-item mt-3"><span><?= h(sblog_t('字体')) ?></span><div><button id="blog_setting_font_sans_serif" class="btn btn-outline-primary blog-setting-font blog-setting-selector-left" type="button" data-argon-font="sans">Sans Serif</button><button id="blog_setting_font_serif" class="btn btn-outline-primary blog-setting-font blog-setting-selector-right" type="button" data-argon-font="serif">Serif</button></div></div>
    <div class="blog-setting-item mt-3"><span><?= h(sblog_t('阴影')) ?></span><div><button id="blog_setting_shadow_small" class="btn btn-outline-primary blog-setting-shadow blog-setting-selector-left" type="button" data-argon-shadow="small"><?= h(sblog_t('浅阴影')) ?></button><button id="blog_setting_shadow_big" class="btn btn-outline-primary blog-setting-shadow blog-setting-selector-right" type="button" data-argon-shadow="big"><?= h(sblog_t('深阴影')) ?></button></div></div>
    <div class="blog-setting-item mt-3"><label for="argon-radius"><?= h(sblog_t('圆角')) ?></label><input id="argon-radius" type="range" min="0" max="24" value="<?= $argon['card_radius'] ?>" data-argon-radius></div>
    <div class="blog-setting-item mt-3"><label for="argon-color"><?= h(sblog_t('主题色')) ?></label><input id="argon-color" type="color" value="<?= h($argon['primary_color']) ?>" data-argon-color></div>
    <div class="argon-color-presets"><?php foreach (['#5e72e4', '#11cdef', '#2dce89', '#fb6340', '#f5365c', '#8965e0'] as $color): ?><button type="button" style="background:<?= $color ?>" data-argon-color="<?= $color ?>" aria-label="<?= h(sblog_t('主题色')) ?> <?= $color ?>"></button><?php endforeach; ?></div>
    <button class="btn btn-secondary btn-sm btn-block mt-3" type="button" data-argon-action="reset"><?= h(sblog_t('恢复默认')) ?></button>
  </div>
  <button id="fabtn_reading_progress" class="btn btn-icon btn-neutral fabtn shadow-sm" type="button" data-argon-action="backtop" aria-label="<?= h(sblog_t('阅读进度')) ?>"><span id="fabtn_reading_progress_bar"></span><span id="fabtn_reading_progress_details">0%</span></button>
</div>
<script src="<?= h(asset_url('assets/index.js')) ?>?v=<?= h(APP_VERSION) ?>" defer></script>
<?php theme_action('body_close', $themeContext); ?>
</body>
</html>
