<?php
declare(strict_types=1);

$sunnyHome = $active === 'home' && $title === $siteName && (string)($_GET['a'] ?? 'home') === 'home';
$sunnyCategories = sunny_categories();
$sunnyRecent = fetch_published_posts(5, 0);
$sunnyArchives = fetch_archive_posts();
$sunnyAuthor = setting('author_name', $siteName);
$sunnyTagline = trim(setting('site_tagline')) ?: setting('site_description');
$sunnySocialDefinitions = social_profile_definitions();
$sunnySocialProfile = one('SELECT ' . implode(', ', array_column($sunnySocialDefinitions, 'column')) . ' FROM users ORDER BY id ASC LIMIT 1') ?? [];
$sunnySocialLinks = [];
foreach ($sunnySocialDefinitions as $sunnyNetwork => $sunnyDefinition) {
    $sunnySocialUrl = safe_link_url((string)($sunnySocialProfile[$sunnyDefinition['column']] ?? ''));
    if (preg_match('#^https?://#i', $sunnySocialUrl)) {
        $sunnySocialLinks[] = ['network' => $sunnyNetwork, 'label' => $sunnyDefinition['label'], 'url' => $sunnySocialUrl];
    }
}
$sunnyComments = all_rows(
    'SELECT c.id, c.author_name, c.author_email, c.content, c.created_at, p.slug, p.title, p.kind
     FROM comments c JOIN posts p ON p.id = c.post_id
     WHERE c.status = ? AND p.status = ? AND p.published_at <= ? AND COALESCE(p.content_password_hash, ?) = ?
     ORDER BY c.created_at DESC, c.id DESC LIMIT 5',
    ['approved', 'published', time(), '', '']
);
$sunnyMonth = date('Y-m');
$sunnyCalendar = [];
foreach ($sunnyArchives as $sunnyEntry) {
    if (date('Y-m', (int)$sunnyEntry['published_at']) === $sunnyMonth) {
        $sunnyCalendar[(int)date('j', (int)$sunnyEntry['published_at'])] ??= $sunnyEntry;
    }
}
$sunnyDays = (int)date('t');
$sunnyFirstDay = (int)date('w', strtotime(date('Y-m-01')));
$sunnyMonths = ['', '一', '二', '三', '四', '五', '六', '七', '八', '九', '十', '十一', '十二'];
$sunnyFeatured = $sunnyRecent[0] ?? null;
$sunnyFeaturedCover = $sunnyFeatured ? sunny_post_cover($sunnyFeatured) : '';
$sunnyNav = [
    ['home', '🏡', '首页'],
    ['archives', '🗃️', '时光机'],
    ['tags', '🍪', '更多'],
    ['links', '🧰', '链接'],
];
?>
<!doctype html>
<html lang="<?= h(sblog_i18n_locale()) ?>" data-sunny-theme="light">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="description" content="<?= h($description) ?>">
  <?php if ($keywords !== ''): ?><meta name="keywords" content="<?= h($keywords) ?>"><?php endif; ?>
  <meta name="theme-color" content="#f7f7f7" id="sunny-theme-color">
  <meta name="color-scheme" content="light dark">
  <title><?= h($fullTitle) ?></title>
  <link rel="icon" href="<?= h(theme_favicon_url()) ?>">
  <link rel="alternate" type="application/rss+xml" title="<?= h($siteName) ?>" href="<?= h(url_for('rss')) ?>">
  <script>
  (() => { try { const mode = localStorage.getItem('sunny-theme'); document.documentElement.dataset.sunnyTheme = mode === 'dark' || mode === 'light' ? mode : (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'); } catch (_) {} })();
  </script>
  <?php theme_action('head', $themeContext); ?>
  <?php if ($customHeadCode !== ''): ?><?= $customHeadCode ?><?php endif; ?>
</head>
<body class="<?= h($bodyClass) ?><?= $sunnyHome ? ' sunny-home' : '' ?>">
<?php theme_action('body_open', $themeContext); ?>
<a class="sunny-skip-link" href="#content">跳到正文</a>
<div class="sunny-shell">
  <?php theme_action('header_before', $themeContext); ?>
  <header class="sunny-hero">
    <?php if ($sunnySocialLinks): ?>
    <nav class="sunny-social" aria-label="社交链接">
      <?php foreach ($sunnySocialLinks as $sunnySocialLink): ?>
      <a href="<?= h($sunnySocialLink['url']) ?>" title="<?= h($sunnySocialLink['label']) ?>" aria-label="<?= h($sunnySocialLink['label']) ?>" target="_blank" rel="me noopener noreferrer"><svg class="sunny-social-icon" width="24" height="24" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><use href="<?= h(theme_asset_url('assets/social-icons.svg') . '#' . $sunnySocialLink['network']) ?>"></use></svg></a>
      <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <a class="sunny-hero-avatar" href="<?= h(sunny_url('home')) ?>" aria-label="<?= h($siteName) ?>首页"><img src="<?= h(theme_logo_url()) ?>" alt="<?= h($sunnyAuthor) ?>" width="136" height="136" decoding="async"></a>
    <a class="sunny-site-title" href="<?= h(sunny_url('home')) ?>"><?= h($siteName) ?></a>
    <?php if ($sunnyTagline !== ''): ?><p class="sunny-site-tagline"><?= h($sunnyTagline) ?></p><?php endif; ?>
  </header>

  <div class="sunny-navbar">
    <button class="sunny-icon-button sunny-mobile-toggle" type="button" data-sunny-menu aria-controls="sunny-nav" aria-expanded="false" aria-label="展开菜单" title="展开菜单"><?= sunny_icon('menu') ?></button>
    <nav class="sunny-nav" id="sunny-nav" aria-label="主菜单">
      <a class="sunny-nav-home" href="<?= h(sunny_url('home')) ?>"<?= $sunnyHome ? ' aria-current="page"' : '' ?>>🏡首页</a>
      <details class="sunny-nav-dropdown">
        <summary>🎣分类<?= sunny_icon('chevron-down', 12) ?></summary>
        <div class="sunny-dropdown-panel">
          <?php foreach ($sunnyCategories as $sunnyCategory): ?>
          <a href="<?= h(sunny_url('category', ['slug' => (string)$sunnyCategory['slug']])) ?>"><?= h($sunnyCategory['name']) ?><span><?= h($sunnyCategory['post_count']) ?></span></a>
          <?php endforeach; ?>
        </div>
      </details>
      <a href="<?= h(sunny_url('links')) ?>"<?= $active === 'links' ? ' aria-current="page"' : '' ?>>🧰链接</a>
      <details class="sunny-nav-dropdown">
        <summary>🍪更多<?= sunny_icon('chevron-down', 12) ?></summary>
        <div class="sunny-dropdown-panel">
          <a href="<?= h(sunny_url('categories')) ?>">🗂️ 全部分类</a>
          <a href="<?= h(sunny_url('archives')) ?>">🗃️ 文章归档</a>
          <a href="<?= h(sunny_url('tags')) ?>">🏷️ 标签小集</a>
        </div>
      </details>
      <?php foreach ($navPages as $sunnyPage): ?>
      <a href="<?= h(sunny_url('page', ['slug' => (string)$sunnyPage['slug']])) ?>"<?= $active === 'page:' . $sunnyPage['slug'] ? ' aria-current="page"' : '' ?>>🐱<?= h($sunnyPage['title']) ?></a>
      <?php endforeach; ?>
    </nav>
    <div class="sunny-tools">
      <button class="sunny-icon-button sunny-theme-toggle" type="button" data-sunny-theme-toggle aria-label="切换夜间模式" title="切换夜间模式" aria-pressed="false"><span class="sunny-moon"><?= sunny_icon('moon') ?></span><span class="sunny-sun"><?= sunny_icon('sun') ?></span></button>
      <button class="sunny-icon-button" type="button" data-sunny-search-open title="搜索文章" aria-label="搜索文章"><?= sunny_icon('search') ?></button>
      <a class="sunny-icon-button" href="<?= h(url_for($admin ? 'admin' : 'login')) ?>" title="<?= $admin ? '管理博客' : '登录' ?>" aria-label="<?= $admin ? '管理博客' : '登录' ?>"><?= sunny_icon('user') ?></a>
      <button class="sunny-icon-button sunny-top-progress" type="button" data-sunny-top title="回到顶部" aria-label="回到顶部"><span data-sunny-progress>0</span><small>%</small></button>
    </div>
  </div>
  <?php theme_action('header_after', $themeContext); ?>

  <div class="sunny-columns">
    <main class="sunny-main" id="content" tabindex="-1">
      <?php if ($flash): ?><div class="sunny-notice" role="status"><?= h((string)$flash['message']) ?></div><?php endif; ?>
      <?php theme_action('content_before', $themeContext); ?>
      <?php if ($sunnyHome): ?>
      <section class="sunny-panel sunny-announcement" aria-label="公告">
        <h2 class="sunny-label">公告</h2>
        <p><?= h(trim(setting('home_intro')) ?: '暂时没有新的内容') ?></p>
      </section>
      <?php endif; ?>
      <div class="sunny-content<?= !$sunnyHome && !in_array((string)($_GET['a'] ?? ''), ['category', 'tag'], true) ? ' sunny-content--page' : '' ?>"><?= $content ?></div>
      <?php theme_action('content_after', $themeContext); ?>
    </main>

    <aside class="sunny-sidebar" aria-label="博客侧栏">
      <div class="sunny-small-widgets">
        <a class="sunny-discover" href="<?= h($sunnyFeatured ? sunny_url('post', ['slug' => (string)$sunnyFeatured['slug']]) : sunny_url('archives')) ?>">
          <?php if ($sunnyFeaturedCover !== ''): ?><img src="<?= h($sunnyFeaturedCover) ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
          <span class="sunny-discover-icon"><?= sunny_icon('book-open', 18) ?></span>
          <span class="sunny-discover-text"><small>拾一篇文字</small><strong><?= h($sunnyFeatured['title'] ?? '时光里的小美好') ?></strong></span>
          <span class="sunny-discover-arrow"><?= sunny_icon('arrow-right', 16) ?></span>
        </a>
        <button class="sunny-plant" data-sunny-water type="button" aria-label="给小苗浇水" title="给小苗浇水">
          <span class="sunny-plant-sun"></span><span class="sunny-cloud sunny-cloud--one"></span><span class="sunny-cloud sunny-cloud--two"></span>
          <img src="<?= h(theme_asset_url('assets/plant.webp')) ?>" width="180" height="180" alt="一株正在长大的小苗">
          <span class="sunny-water-message" role="status" aria-live="polite"></span>
        </button>
      </div>

      <section class="sunny-panel sunny-profile" aria-label="博主信息">
        <a class="sunny-profile-avatar" href="<?= h(sunny_url('home')) ?>"><img src="<?= h(theme_logo_url()) ?>" width="88" height="88" alt="<?= h($sunnyAuthor) ?>"></a>
        <div class="sunny-profile-info"><strong><?= h($sunnyAuthor) ?></strong><span class="sunny-profile-detail"><span class="sunny-profile-badge">记录生活</span><span>文章 <?= count($sunnyArchives) ?></span></span></div>
        <span class="sunny-profile-flower" aria-hidden="true">🌻</span>
      </section>

      <section class="sunny-panel sunny-calendar-panel">
        <h2 class="sunny-label"><?= h($sunnyMonths[(int)date('n')]) ?>月历</h2>
        <table class="sunny-calendar" aria-label="<?= h(date('Y年n月')) ?>文章日历">
          <thead><tr><?php foreach (['日', '一', '二', '三', '四', '五', '六'] as $sunnyDayName): ?><th scope="col"><?= $sunnyDayName ?></th><?php endforeach; ?></tr></thead>
          <tbody><?php for ($sunnyCell = 0; $sunnyCell < (int)(ceil(($sunnyDays + $sunnyFirstDay) / 7) * 7); $sunnyCell++): ?>
          <?php if ($sunnyCell % 7 === 0): ?><tr><?php endif; ?>
          <?php $sunnyDay = $sunnyCell - $sunnyFirstDay + 1; ?>
          <td<?= $sunnyDay === (int)date('j') ? ' class="is-today" aria-current="date"' : '' ?>><?php if ($sunnyDay > 0 && $sunnyDay <= $sunnyDays): ?><?php if (isset($sunnyCalendar[$sunnyDay])): ?><a href="<?= h(sunny_url('post', ['slug' => (string)$sunnyCalendar[$sunnyDay]['slug']])) ?>" title="<?= h($sunnyCalendar[$sunnyDay]['title']) ?>"><?= $sunnyDay ?></a><?php else: ?><span><?= $sunnyDay ?></span><?php endif; ?><?php endif; ?></td>
          <?php if ($sunnyCell % 7 === 6): ?></tr><?php endif; ?>
          <?php endfor; ?></tbody>
        </table>
      </section>

      <div class="sunny-sidebar-sticky">
        <section class="sunny-panel sunny-toc-panel" hidden><h2 class="sunny-label">文章目录</h2><nav class="sunny-toc" aria-label="文章目录"></nav></section>
        <section class="sunny-panel sunny-history-panel" hidden><h2 class="sunny-label">我的足迹</h2><div data-sunny-history></div></section>
        <section class="sunny-panel sunny-recent-comments">
          <h2 class="sunny-label">近期评论</h2>
          <?php if (!$sunnyComments): ?><p class="sunny-muted sunny-widget-empty">期待你的第一条留言</p><?php endif; ?>
          <?php if ($sunnyComments): ?><ul class="sunny-recent-comment-list"><?php endif; ?>
          <?php foreach ($sunnyComments as $sunnyComment): ?>
          <li>
            <a class="sunny-recent-comment" href="<?= h(sunny_url($sunnyComment['kind'] === 'page' ? 'page' : 'post', ['slug' => (string)$sunnyComment['slug']])) ?>#comment-<?= (int)$sunnyComment['id'] ?>" title="<?= h('来自《' . (string)$sunnyComment['title'] . '》') ?>">
              <span class="sunny-recent-comment-avatar" aria-hidden="true"><?= h(str_sub_u((string)$sunnyComment['author_name'], 0, 1)) ?><img src="<?= h(gravatar_url((string)$sunnyComment['author_email'], 64)) ?>" width="32" height="32" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove()"></span>
              <span class="sunny-recent-comment-content"><span class="sunny-comment-byline"><strong><?= h($sunnyComment['author_name']) ?></strong><time datetime="<?= h(date(DATE_ATOM, (int)$sunnyComment['created_at'])) ?>"><?= h(friendly_public_date((int)$sunnyComment['created_at'])) ?></time></span><span class="sunny-comment-snippet"><span><?= h((string)$sunnyComment['content']) ?></span></span></span>
            </a>
          </li>
          <?php endforeach; ?>
          <?php if ($sunnyComments): ?></ul><?php endif; ?>
        </section>
        <section class="sunny-panel sunny-site-info">
          <h2 class="sunny-label">站点信息</h2>
          <div class="sunny-stats"><a href="<?= h(sunny_url('archives')) ?>"><strong><?= count($sunnyArchives) ?></strong><span>篇文章</span></a><a href="<?= h(sunny_url('tags')) ?>"><strong><?= count(tag_index_data()) ?></strong><span>个标签</span></a><a href="<?= h(sunny_url('categories')) ?>"><strong><?= count($sunnyCategories) ?></strong><span>个分类</span></a></div>
          <p><?= h(site_footer_text()) ?></p>
          <?php if (trim(setting('footer_beian')) !== ''): ?><p><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= h(setting('footer_beian')) ?></a></p><?php endif; ?>
          <p>Powered by <a href="https://github.com/jkjoy/Simple-PHP-Blog" target="_blank" rel="noopener noreferrer">SBlog</a> &amp; <a href="<?= h(sunny_url('home')) ?>">🌻 Sunny</a></p>
          <p><a href="<?= h(sunny_url('rss')) ?>">RSS 订阅</a><span aria-hidden="true"> · </span><a href="<?= h(sunny_url('sitemap')) ?>">站点地图</a></p>
        </section>
      </div>
    </aside>
  </div>

  <?php theme_action('footer_before', $themeContext); ?>
  <footer class="sunny-footer">
    <img src="<?= h(theme_asset_url('assets/footer.webp')) ?>" width="1200" height="368" alt="" loading="lazy">
    <div class="sunny-footer-info">
      <p><?= h($siteName) ?> · <?= h(site_footer_text()) ?></p>
      <?php if (trim(setting('footer_beian')) !== ''): ?><p><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= h(setting('footer_beian')) ?></a></p><?php endif; ?>
      <p>Powered by <a href="https://github.com/jkjoy/Simple-PHP-Blog" target="_blank" rel="noopener noreferrer">SBlog</a> &amp; <a href="<?= h(sunny_url('home')) ?>">🌻 Sunny</a></p>
      <p><a href="<?= h(sunny_url('rss')) ?>">RSS 订阅</a><span aria-hidden="true"> · </span><a href="<?= h(sunny_url('sitemap')) ?>">站点地图</a></p>
    </div>
  </footer>
  <?php theme_action('footer_after', $themeContext); ?>
</div>

<nav class="sunny-mobile-dock" aria-label="底部导航">
  <a href="<?= h(sunny_url('links')) ?>"><span>🍭</span>友链</a>
  <a href="<?= h(sunny_url('archives')) ?>"><span>📚</span>归档</a>
  <a class="sunny-dock-home" href="<?= h(sunny_url('home')) ?>" aria-label="首页"><img src="<?= h(theme_logo_url()) ?>" width="44" height="44" alt=""></a>
  <a href="<?= h(sunny_url('tags')) ?>"><span>🍪</span>标签</a>
  <button type="button" data-sunny-search-open><span>🔎</span>搜索</button>
</nav>

<dialog class="sunny-search-dialog" aria-labelledby="sunny-search-title">
  <div class="sunny-search-heading"><h2 id="sunny-search-title">拾文</h2><button class="sunny-icon-button" type="button" data-sunny-search-close aria-label="关闭搜索" title="关闭搜索"><?= sunny_icon('x') ?></button></div>
  <form class="sunny-search-form" role="search"><label for="sunny-search-input" class="sunny-sr-only">搜索文章</label><?= sunny_icon('search') ?><input id="sunny-search-input" type="search" placeholder="输入关键词，寻找一段时光…" autocomplete="off"><kbd>ESC</kbd></form>
  <p class="sunny-search-status" role="status" aria-live="polite">输入标题或摘要关键词</p><div class="sunny-search-results"></div>
  <div class="sunny-search-tags"><?php foreach (array_slice(tag_index_data(), 0, 12) as $sunnyTag): ?><a href="<?= h(sunny_url('tag', ['slug' => (string)$sunnyTag['slug']])) ?>">#<?= h($sunnyTag['label']) ?></a><?php endforeach; ?></div>
</dialog>
<dialog class="sunny-lightbox" aria-label="图片预览"><button class="sunny-icon-button" type="button" data-sunny-lightbox-close aria-label="关闭图片" title="关闭图片"><?= sunny_icon('x', 24) ?></button><img alt=""></dialog>
<script type="application/json" id="sunny-search-data"><?= json_encode(sunny_search_items(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?></script>
<script src="<?= h(asset_url('assets/index.js')) ?>?v=<?= h(APP_VERSION) ?>" defer></script>
<script src="<?= h(theme_asset_url('script.js')) ?>?v=<?= h((string)filemtime(__DIR__ . '/script.js')) ?>" defer></script>
<?php theme_action('body_close', $themeContext); ?>
</body>
</html>
