/* Document for SBlog. No WordPress or jQuery runtime required. */
(() => {
  'use strict';
  const body = document.body;
  if (!body.classList.contains('document-theme')) return;
  const root = document.documentElement;
  body.classList.add('document-js');
  const read = key => { try { return localStorage.getItem(key); } catch (_) { return null; } };
  const write = (key, value) => { try { localStorage.setItem(key, value); } catch (_) {} };
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
  const defaultMode = ['light', 'dark', 'auto'].includes(body.dataset.mode) ? body.dataset.mode : 'auto';
  let choice = read('document-mode');
  if (!['light', 'dark'].includes(choice)) choice = null;
  const modeButton = document.querySelector('[data-theme-toggle]');
  function applyTheme() {
    const dark = (choice || defaultMode) === 'dark' || ((choice || defaultMode) === 'auto' && systemTheme.matches);
    root.dataset.theme = dark ? 'dark' : 'light';
    if (modeButton) {
      modeButton.setAttribute('aria-pressed', String(dark));
      modeButton.setAttribute('aria-label', dark ? '切换到浅色模式' : '切换到深色模式');
      modeButton.title = dark ? '切换到浅色模式' : '切换到深色模式';
    }
  }
  applyTheme();
  if (systemTheme.addEventListener) systemTheme.addEventListener('change', applyTheme);
  modeButton?.addEventListener('click', () => {
    choice = root.dataset.theme === 'dark' ? 'light' : 'dark';
    write('document-mode', choice);
    applyTheme();
  });

  const menuButton = document.querySelector('.document-menu-toggle');
  const menu = document.getElementById(menuButton?.getAttribute('aria-controls') || 'document-nav');
  const closeMenu = () => { menu?.classList.remove('is-open'); menuButton?.setAttribute('aria-expanded', 'false'); };
  menuButton?.addEventListener('click', () => {
    const open = menu?.classList.toggle('is-open') || false;
    menuButton.setAttribute('aria-expanded', String(open));
  });
  menu?.addEventListener('click', event => { if (event.target.closest('a')) closeMenu(); });
  document.addEventListener('click', event => {
    if (menu && !menu.contains(event.target) && !menuButton?.contains(event.target)) closeMenu();
  });
  window.matchMedia('(min-width: 768px)').addEventListener?.('change', closeMenu);

  const palette = document.querySelector('.document-colors');
  const paletteButton = document.querySelector('[data-color-toggle]');
  const colors = Array.from(document.querySelectorAll('[data-color]'));
  function applyColor(value, persist = false) {
    if (!/^#[a-f\d]{6}$/i.test(value || '')) return;
    root.style.setProperty('--document-primary', value);
    colors.forEach(button => button.setAttribute('aria-pressed', String(button.dataset.color.toLowerCase() === value.toLowerCase())));
    if (persist) write('document-color', value);
  }
  applyColor(read('document-color'));
  colors.forEach(button => button.addEventListener('click', () => applyColor(button.dataset.color, true)));
  paletteButton?.addEventListener('click', () => {
    if (!palette) return;
    palette.hidden = !palette.hidden;
    paletteButton.setAttribute('aria-expanded', String(!palette.hidden));
  });
  document.addEventListener('click', event => {
    if (palette && !palette.contains(event.target) && !paletteButton?.contains(event.target)) {
      palette.hidden = true;
      paletteButton?.setAttribute('aria-expanded', 'false');
    }
  });

  const article = document.querySelector('.document-detail .post-content');
  const toc = document.querySelector('[data-document-toc]');
  const tocWidget = toc?.closest('.document-widget');
  const depth = Math.min(4, Math.max(2, Number(body.dataset.tocDepth) || 3));
  let tocLinks = [];
  let tocHeadings = [];
  let branchCollapsed = body.dataset.tocCollapsed === '1';
  const expandButton = document.querySelector('[data-toc-expand]');
  const tocButton = document.querySelector('[data-toc-toggle]');
  const locked = article?.querySelector('.content-gate--password');
  if (article && toc && !locked) {
    tocHeadings = Array.from(article.querySelectorAll('h1, h2, h3, h4')).filter(heading =>
      Number(heading.tagName.slice(1)) <= depth && heading.textContent.trim() && !heading.closest('.content-gate')
    );
    if (!toc.querySelector('a[href^="#"]') && tocHeadings.length) {
      toc.replaceChildren();
      const list = document.createElement('ol');
      tocHeadings.forEach((heading, index) => {
        if (!heading.id) {
          let id = `document-heading-${index + 1}`;
          while (document.getElementById(id)) id += '-';
          heading.id = id;
        }
        const item = document.createElement('li');
        item.dataset.level = heading.tagName.slice(1);
        const link = document.createElement('a');
        link.href = `#${encodeURIComponent(heading.id)}`;
        link.textContent = heading.textContent.trim();
        item.append(link);
        list.append(item);
      });
      toc.append(list);
    }
    tocLinks = Array.from(toc.querySelectorAll('a[href^="#"]'));
    tocHeadings = tocLinks.map(link => {
      try { return document.getElementById(decodeURIComponent(link.hash.slice(1))); } catch (_) { return null; }
    }).filter(Boolean);
    if (!tocLinks.length && tocWidget) tocWidget.hidden = true;
    function collapseBranches() {
      const levels = tocHeadings.map(heading => Number(heading.tagName.slice(1)));
      const firstLevel = Math.min(...levels);
      tocLinks.forEach(link => {
        const heading = (() => { try { return document.getElementById(decodeURIComponent(link.hash.slice(1))); } catch (_) { return null; } })();
        const item = link.closest('li');
        if (heading && item) {
          item.dataset.level = heading.tagName.slice(1);
          item.hidden = branchCollapsed && Number(heading.tagName.slice(1)) > firstLevel;
        }
      });
      if (expandButton) {
        expandButton.setAttribute('aria-expanded', String(!branchCollapsed));
        expandButton.setAttribute('aria-label', branchCollapsed ? '展开全部目录层级' : '收起子目录');
        expandButton.title = branchCollapsed ? '展开全部目录层级' : '收起子目录';
      }
    }
    collapseBranches();
    expandButton?.addEventListener('click', () => { branchCollapsed = !branchCollapsed; collapseBranches(); });
    tocButton?.addEventListener('click', () => {
      toc.hidden = !toc.hidden;
      tocButton.setAttribute('aria-expanded', String(!toc.hidden));
    });
    tocLinks.forEach(link => link.addEventListener('click', event => {
      const target = (() => { try { return document.getElementById(decodeURIComponent(link.hash.slice(1))); } catch (_) { return null; } })();
      if (!target) return;
      event.preventDefault();
      target.scrollIntoView({ behavior: reducedMotion.matches ? 'instant' : 'smooth', block: 'start' });
      history.replaceState(null, '', link.hash);
    }));
  } else if (tocWidget && !toc?.querySelector('a')) tocWidget.hidden = true;

  const progress = document.querySelector('.document-progress');
  const percentageLabel = document.querySelector('[data-document-percentage]');
  if (progress) {
    progress.setAttribute('role', 'progressbar');
    progress.setAttribute('aria-valuemin', '0');
    progress.setAttribute('aria-valuemax', '100');
  }
  let scrollScheduled = false;
  let currentHeading = null;
  function updateScroll() {
    const max = root.scrollHeight - window.innerHeight;
    const percentage = max > 0 ? Math.min(100, Math.max(0, window.scrollY / max * 100)) : 0;
    if (progress) {
      progress.style.width = `${percentage}%`;
      progress.setAttribute('aria-valuenow', String(Math.round(percentage)));
    }
    if (percentageLabel) percentageLabel.textContent = `${Math.round(percentage)}%`;
    let active = tocHeadings[0];
    for (const heading of tocHeadings) {
      if (heading.getBoundingClientRect().top <= 115) active = heading;
      else break;
    }
    if (active && active !== currentHeading) {
      currentHeading = active;
      tocLinks.forEach(link => {
        let selected = false;
        try { selected = decodeURIComponent(link.hash.slice(1)) === active.id; } catch (_) {}
        link.classList.toggle('active', selected);
        if (selected) link.setAttribute('aria-current', 'location');
        else link.removeAttribute('aria-current');
      });
    }
    scrollScheduled = false;
  }
  window.addEventListener('scroll', () => {
    if (!scrollScheduled) { scrollScheduled = true; requestAnimationFrame(updateScroll); }
  }, { passive: true });
  window.addEventListener('resize', updateScroll);
  window.addEventListener('load', updateScroll);
  updateScroll();
  document.querySelector('[data-back-top]')?.addEventListener('click', () => window.scrollTo({ top: 0, behavior: reducedMotion.matches ? 'instant' : 'smooth' }));

  if (navigator.clipboard && window.isSecureContext) {
    article?.querySelectorAll('pre').forEach(block => {
      const code = block.querySelector('code');
      if (!code || block.querySelector('.document-copy')) return;
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'document-copy';
      button.textContent = '复制';
      button.setAttribute('aria-label', '复制代码');
      button.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(code.textContent);
          button.textContent = '已复制';
          setTimeout(() => { button.textContent = '复制'; }, 1800);
        } catch (_) { button.textContent = '复制失败'; }
      });
      block.append(button);
    });
  }

  if ('HTMLDialogElement' in window && article) {
    let lightbox;
    let previousFocus;
    function openImage(image) {
      if (!lightbox) {
        lightbox = document.createElement('dialog');
        lightbox.className = 'document-lightbox';
        lightbox.setAttribute('aria-label', '图片预览');
        const close = document.createElement('button');
        close.type = 'button';
        close.textContent = '×';
        close.setAttribute('aria-label', '关闭图片预览');
        const preview = document.createElement('img');
        lightbox.append(close, preview);
        close.addEventListener('click', () => lightbox.close());
        lightbox.addEventListener('click', event => { if (event.target === lightbox) lightbox.close(); });
        lightbox.addEventListener('close', () => previousFocus?.focus());
        body.append(lightbox);
      }
      previousFocus = document.activeElement;
      const preview = lightbox.querySelector('img');
      preview.src = image.currentSrc || image.src;
      preview.alt = image.alt || '文章图片';
      lightbox.showModal();
    }
    article.querySelectorAll('img').forEach(image => {
      if (image.closest('a, button, .content-gate')) return;
      image.classList.add('document-image-button');
      image.tabIndex = 0;
      image.setAttribute('role', 'button');
      image.setAttribute('aria-label', image.alt ? `放大图片：${image.alt}` : '放大文章图片');
      image.addEventListener('click', () => openImage(image));
      image.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); openImage(image); }
      });
    });
  }
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    if (menu?.classList.contains('is-open')) { closeMenu(); menuButton?.focus(); }
    if (palette && !palette.hidden) { palette.hidden = true; paletteButton?.setAttribute('aria-expanded', 'false'); paletteButton?.focus(); }
  });
})();
