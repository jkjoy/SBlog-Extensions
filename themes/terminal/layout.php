<?php

declare(strict_types=1);

$keywords = trim(setting('site_keywords'));
$customHeadCode = trim(setting('custom_head_code'));
$scriptFile = __DIR__ . '/script.js';
$scriptVersion = is_file($scriptFile) ? (string)filemtime($scriptFile) : (string)($theme['version'] ?? '1.0.0');
$beian = trim(setting('footer_beian'));
?>
<!doctype html>
<html lang="<?= h(sblog_i18n_locale()) ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="<?= h($description) ?>">
  <?php if ($keywords !== ''): ?><meta name="keywords" content="<?= h($keywords) ?>"><?php endif; ?>
  <title><?= h($fullTitle) ?></title>
  <link rel="icon" href="<?= h(theme_favicon_url()) ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@300;400;500;600;700&display=swap" rel="stylesheet">
  <?= sblog_i18n_head() ?>
  <?php theme_action('head', $themeContext); ?>
  <?php if ($customHeadCode !== ''): ?><?= $customHeadCode . "\n" ?><?php endif; ?>
</head>
<body class="<?= h($bodyClass) ?>">
  <?php theme_action('body_open', $themeContext); ?>
  <div class="crt-turn-on" id="turn-on"></div>
  <div class="crt-vignette"></div>
  <div class="scanlines" id="scanlines"></div>
  <div class="crt-flicker"></div>
  <div class="terminal" data-home="<?= h(url_for('home')) ?>" data-tags="<?= h(url_for('tags')) ?>" data-links="<?= h(url_for('links')) ?>" data-archives="<?= h(url_for('archives')) ?>">
    <?php theme_action('header_before', $themeContext); ?>
    <header class="terminal-header">
      <div class="window-controls" aria-hidden="true"><span class="dot red"></span><span class="dot yellow"></span><span class="dot green"></span></div>
      <div class="title">visitor@<?= h($siteName) ?>: ~ — devlog-sh 0.9</div>
      <div class="info"><span class="signal"></span><span id="term-info">80×24</span></div>
    </header>
    <?php theme_action('header_after', $themeContext); ?>
    <main class="output" id="output" aria-live="polite">
      <div class="boot-banner"><b><?= h($siteName) ?> <?= h(APP_VERSION) ?> — <?= h(public_quote()) ?></b><br><span>type "help" to begin · type "ls" to look around</span></div>
      <nav class="terminal-menu" aria-label="<?= h(sblog_t('主菜单')) ?>">
        <span class="terminal-menu__label">menu:</span>
        <a class="<?= $active === 'home' ? 'is-active' : '' ?>" href="<?= h(url_for('home')) ?>">[<?= h(sblog_t('首页')) ?>]</a>
        <a class="<?= $active === 'tags' ? 'is-active' : '' ?>" href="<?= h(url_for('tags')) ?>">[<?= h(sblog_t('标签')) ?>]</a>
        <a class="<?= $active === 'archives' ? 'is-active' : '' ?>" href="<?= h(url_for('archives')) ?>">[<?= h(sblog_t('归档')) ?>]</a>
        <a class="<?= $active === 'links' ? 'is-active' : '' ?>" href="<?= h(url_for('links')) ?>">[<?= h(sblog_t('链接')) ?>]</a>
        <?php foreach ($navPages as $page): ?>
          <a class="<?= $active === 'page:' . $page['slug'] ? 'is-active' : '' ?>" href="<?= h(content_permalink($page)) ?>">[<?= h((string)$page['title']) ?>]</a>
        <?php endforeach; ?>
        <?php if ($admin): ?><a href="<?= h(url_for('admin')) ?>">[<?= h(sblog_t('管理')) ?>]</a><?php endif; ?>
      </nav>
      <div class="cmd-echo"><span class="prompt-part">visitor@<?= h($siteName) ?></span><span class="path-part">:~</span>$ cat <?= h(strtolower(str_replace(' ', '-', $title))) ?>.md</div>
      <?php if ($flash): ?><div class="line amber"><?= h((string)$flash['message']) ?></div><?php endif; ?>
      <?php theme_action('content_before', $themeContext); ?>
      <section class="md-content"><?= $content ?></section>
      <?php theme_action('content_after', $themeContext); ?>
      <div class="line dim">-- EOF --</div>
      <?php theme_action('footer_before', $themeContext); ?>
      <footer class="terminal-footer">
        <span><?= h(site_footer_text()) ?></span>
        <?php if ($beian !== ''): ?><span class="terminal-footer__separator">·</span><a href="https://beian.miit.gov.cn/" target="_blank" rel="noopener noreferrer"><?= h($beian) ?></a><?php endif; ?>
        <span class="terminal-footer__separator">·</span><a href="<?= h(url_for('rss')) ?>"><?= h(sblog_t('RSS')) ?></a>
        <span class="terminal-footer__separator">·</span><a href="<?= h(url_for('sitemap')) ?>"><?= h(sblog_t('Sitemap')) ?></a>
      </footer>
      <?php theme_action('footer_after', $themeContext); ?>
    </main>
    <footer class="prompt-line">
      <span class="prompt"><span>visitor@<?= h($siteName) ?></span><span class="path" id="prompt-path">:~</span><span class="symbol">$</span>&nbsp;</span>
      <span class="input-text" id="input-text"></span><span class="cursor"></span><span class="ghost-text" id="ghost-text"></span>
      <input id="input" type="text" autofocus autocomplete="off" spellcheck="false" aria-label="<?= h(sblog_t('终端输入')) ?>">
    </footer>
  </div>
  <script src="<?= h(asset_url('assets/index.js')) ?>?v=<?= h(APP_VERSION) ?>" defer></script>
  <script src="<?= h(theme_asset_url('script.js')) ?>?v=<?= h($scriptVersion) ?>" defer></script>
  <?php theme_action('body_close', $themeContext); ?>
</body>
</html>
