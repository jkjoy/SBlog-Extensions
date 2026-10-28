/* Argon for Simple PHP Blog. GPL-3.0-or-later. */
(() => {
  'use strict';
  const root = document.documentElement;
  const config = window.argonConfig || {};
  const $ = (selector, parent = document) => parent.querySelector(selector);
  const $$ = (selector, parent = document) => Array.from(parent.querySelectorAll(selector));
  const storageKey = 'argon.preferences';
  const readPreferences = () => {
    try {
      const value = JSON.parse(localStorage.getItem(storageKey) || '{}');
      return value && typeof value === 'object' && !Array.isArray(value) ? value : {};
    } catch (_) { return {}; }
  };
  let preferences = readPreferences();
  const persist = () => { try { localStorage.setItem(storageKey, JSON.stringify(preferences)); } catch (_) {} };
  const media = window.matchMedia('(prefers-color-scheme: dark)');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const mode = () => ['auto', 'light', 'dark'].includes(preferences.mode) ? preferences.mode : (config.darkmode || 'auto');

  function applyMode() {
    const value = mode();
    const dark = value === 'dark' || (value === 'auto' && media.matches);
    root.classList.toggle('darkmode', dark);
    root.style.colorScheme = dark ? 'dark' : 'light';
    $$('[data-argon-action="darkmode"], #fabtn_toggle_darkmode').forEach(button => {
      button.setAttribute('aria-pressed', String(dark));
      button.setAttribute('aria-label', dark ? '切换日间模式' : '切换夜间模式');
    });
    $$('[data-argon-mode]').forEach(input => { input.value = value; });
  }

  function applyColor(value) {
    if (!/^#[\da-f]{6}$/i.test(value || '')) return;
    const rgb = [1, 3, 5].map(index => parseInt(value.slice(index, index + 2), 16));
    const [r, g, b] = rgb.map(channel => channel / 255);
    const max = Math.max(r, g, b), min = Math.min(r, g, b), delta = max - min;
    const lightness = (max + min) / 2;
    let hue = 0, saturation = 0;
    if (delta) {
      saturation = delta / (1 - Math.abs(2 * lightness - 1));
      hue = max === r ? ((g - b) / delta) % 6 : max === g ? (b - r) / delta + 2 : (r - g) / delta + 4;
      hue = (hue * 60 + 360) % 360;
    }
    root.style.setProperty('--themecolor', value);
    root.style.setProperty('--argon-primary', value);
    ['R', 'G', 'B'].forEach((name, index) => root.style.setProperty(`--themecolor-${name}`, String(rgb[index])));
    root.style.setProperty('--themecolor-H', String(hue));
    root.style.setProperty('--themecolor-S', String(saturation * 100));
    root.style.setProperty('--themecolor-L', String(lightness * 100));
    root.classList.toggle('themecolor-toodark', rgb[0] * .299 + rgb[1] * .587 + rgb[2] * .114 < 50);
    $$('input[data-argon-color]').forEach(input => { input.value = value; });
    $$('button[data-argon-color]').forEach(button => button.setAttribute('aria-pressed', String(button.dataset.argonColor.toLowerCase() === value.toLowerCase())));
    const meta = $('meta[name="theme-color"]');
    if (meta) meta.content = value;
  }

  function applyPreferences() {
    applyMode();
    applyColor(preferences.color || config.primary_color || '#5e72e4');
    root.classList.toggle('readingmode', config.is_detail === true && preferences.reading === true);
    root.classList.toggle('use-serif', (preferences.font || config.font_family) === 'serif');
    root.classList.toggle('use-big-shadow', (preferences.shadow || config.card_shadow) === 'big');
    $$('[data-argon-action="readingmode"], #fabtn_readingmode').forEach(button => button.setAttribute('aria-pressed', String(root.classList.contains('readingmode'))));
    const radius = Number(preferences.radius ?? config.card_radius ?? 4);
    if (Number.isFinite(radius)) root.style.setProperty('--card-radius', `${Math.max(0, Math.min(24, radius))}px`);
    $$('[data-argon-radius]').forEach(input => { input.value = String(radius); });
    const floating = $('#float_action_buttons');
    if (floating) floating.classList.toggle('fabtns-float-left', preferences.fabSide === 'left');
  }
  root.classList.remove('no-js');
  root.classList.add('js');
  applyPreferences();
  media.addEventListener('change', applyMode);
  window.addEventListener('storage', event => {
    if (event.key === storageKey) { preferences = readPreferences(); applyPreferences(); }
  });

  function initialize() {
    applyPreferences();
    const floating = $('#float_action_buttons');
    floating?.classList.remove('fabtns-unloaded');
    const sidebar = $('#leftbar, #sidebar');
    const mask = $('#sidebar_mask, #sidebar-mask');
    let sidebarTrigger = null;
    function setSidebar(open) {
      root.classList.toggle('leftbar-opened', open);
      root.classList.toggle('sidebar-show', open);
      $$('[data-argon-action="sidebar"], #fabtn_open_sidebar').forEach(button => button.setAttribute('aria-expanded', String(open)));
      if (!open) sidebarTrigger?.focus({preventScroll: true});
    }
    function setSettings(open) {
      floating?.classList.toggle('blog_settings_opened', open);
      const panel = $('#fabtn_blog_settings_popup, [data-argon-settings-panel]');
      panel?.setAttribute('aria-hidden', String(!open));
      $$('[data-argon-action="customize"], #fabtn_toggle_blog_settings_popup').forEach(button => button.setAttribute('aria-expanded', String(open)));
    }

    let returnFocus = null;
    const search = $('#search-modal, #argon_search_modal');
    function openSearch(trigger) {
      if (!search) return;
      returnFocus = trigger;
      setSidebar(false);
      if (search.tagName === 'DIALOG') { if (!search.open) search.showModal(); }
      else { search.hidden = false; search.classList.add('show'); search.style.display = 'block'; search.setAttribute('aria-hidden', 'false'); }
      requestAnimationFrame(() => $('input[name="q"], input[type="search"]', search)?.focus());
    }
    function closeSearch() {
      if (!search) return;
      if (search.tagName === 'DIALOG') search.close();
      else { search.hidden = true; search.classList.remove('show'); search.style.display = ''; search.setAttribute('aria-hidden', 'true'); }
      returnFocus?.focus({preventScroll: true});
    }
    search?.addEventListener('click', event => { if (event.target === search) closeSearch(); });
    search?.addEventListener('close', () => returnFocus?.focus({preventScroll: true}));
    mask?.addEventListener('click', () => setSidebar(false));
    sidebar?.addEventListener('click', event => {
      if (event.target.closest('a[href]') && window.innerWidth <= 900) setSidebar(false);
    });

    const actions = {
      sidebar: event => { sidebarTrigger = event.currentTarget || event.target.closest('button'); setSidebar(!root.classList.contains('leftbar-opened')); },
      darkmode: () => { preferences.mode = root.classList.contains('darkmode') ? 'light' : 'dark'; persist(); applyMode(); },
      readingmode: () => { preferences.reading = !root.classList.contains('readingmode'); persist(); applyPreferences(); requestAnimationFrame(updateScroll); },
      backtop: () => window.scrollTo({top: 0, behavior: reducedMotion.matches ? 'instant' : 'smooth'}),
      search: event => openSearch(event.target.closest('button, a')),
      closeSearch,
      customize: () => setSettings(!floating?.classList.contains('blog_settings_opened')),
      closeCustomize: () => setSettings(false),
      reset: () => { preferences = {}; persist(); applyPreferences(); requestAnimationFrame(updateScroll); },
      sides: () => { preferences.fabSide = floating?.classList.contains('fabtns-float-left') ? 'right' : 'left'; persist(); applyPreferences(); },
      comments: () => $('#comments, #comment-form, .comments')?.scrollIntoView({behavior: reducedMotion.matches ? 'instant' : 'smooth'})
    };
    const legacy = {
      fabtn_open_sidebar: 'sidebar', fabtn_toggle_darkmode: 'darkmode', fabtn_readingmode: 'readingmode', fabtn_back_to_top: 'backtop',
      fabtn_reading_progress: 'backtop', fabtn_toggle_sides: 'sides', fabtn_go_to_comment: 'comments',
      leftbar_search_container: 'search', navbar_search_container: 'search', fabtn_toggle_blog_settings_popup: 'customize', close_blog_settings: 'closeCustomize'
    };
    document.addEventListener('click', event => {
      const control = event.target.closest('[data-argon-action], button[data-argon-color], [data-argon-font], [data-argon-shadow]') || event.target.closest(Object.keys(legacy).map(id => `#${id}`).join(', '));
      if (!control) return;
      const action = control.dataset.argonAction || legacy[control.id];
      if (action && actions[action]) { event.preventDefault(); actions[action]({...event, target: event.target, currentTarget: control}); }
      if (control.tagName === 'BUTTON' && control.dataset.argonColor) {
        preferences.color = control.dataset.argonColor; persist(); applyColor(preferences.color);
      }
      if (control.dataset.argonFont) { preferences.font = control.dataset.argonFont; persist(); applyPreferences(); }
      if (control.dataset.argonShadow) { preferences.shadow = control.dataset.argonShadow; persist(); applyPreferences(); }
    });
    document.addEventListener('input', event => {
      if (event.target.matches('input[data-argon-color]')) { preferences.color = event.target.value; persist(); applyColor(preferences.color); }
      if (event.target.matches('[data-argon-radius]')) { preferences.radius = Number(event.target.value); persist(); applyPreferences(); }
    });
    document.addEventListener('change', event => {
      if (event.target.matches('[data-argon-mode]')) { preferences.mode = event.target.value; persist(); applyMode(); }
    });
    document.addEventListener('keydown', event => {
      if (event.key === 'Escape') { setSidebar(false); setSettings(false); if (search?.open || search?.classList.contains('show')) closeSearch(); }
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') { event.preventDefault(); openSearch(document.activeElement); }
    });
    document.addEventListener('click', event => {
      if (floating?.classList.contains('blog_settings_opened') && !floating.contains(event.target)) setSettings(false);
    });

    // The original sidebar's tabs are kept without requiring Bootstrap JavaScript.
    $$('[data-toggle="tab"], [data-argon-tab]').forEach(tab => tab.addEventListener('click', event => {
      const id = tab.getAttribute('href') || tab.dataset.argonTab;
      if (!id?.startsWith('#')) return;
      const pane = document.getElementById(id.slice(1));
      if (!pane) return;
      event.preventDefault();
      const container = tab.closest('[role="tablist"], .nav');
      $$('[data-toggle="tab"], [data-argon-tab]', container || document).forEach(other => {
        other.classList.toggle('active', other === tab);
        other.classList.toggle('show', other === tab);
        other.setAttribute('aria-selected', String(other === tab));
      });
      $$('.tab-pane', pane.parentElement).forEach(other => { other.classList.toggle('active', other === pane); other.classList.toggle('show', other === pane); });
    }));

    const article = $('#post_content, .post-content');
    const toc = $('#leftbar_catalog, [data-argon-toc]');
    let headings = [], tocLinks = [];
    if (article && toc) {
      headings = $$('h2, h3, h4', article).filter(heading => heading.textContent.trim());
      const list = document.createElement('ul');
      list.className = 'index-subItem-box';
      const taken = new Set($$('[id]').map(node => node.id));
      headings.forEach((heading, index) => {
        if (!heading.id) {
          let id = `argon-heading-${index + 1}`;
          while (taken.has(id)) id += '-';
          heading.id = id; taken.add(id);
        }
        const item = document.createElement('li');
        item.className = `index-item argon-toc-level-${heading.tagName.slice(1)}`;
        const link = document.createElement('a');
        link.className = 'index-link';
        link.href = `#${encodeURIComponent(heading.id)}`;
        link.textContent = heading.textContent;
        link.addEventListener('click', event => {
          event.preventDefault();
          heading.scrollIntoView({behavior: reducedMotion.matches ? 'instant' : 'smooth', block: 'start'});
          history.replaceState(null, '', link.getAttribute('href'));
          if (window.innerWidth <= 900) setSidebar(false);
        });
        item.append(link); list.append(item); tocLinks.push(link);
      });
      toc.replaceChildren(list);
      if (!headings.length) {
        const empty = document.createElement('p'); empty.className = 'text-muted'; empty.textContent = '本文暂无目录'; toc.append(empty);
      }
    }

    const toolbar = $('#navbar-main');
    const bannerContainer = $('#banner_container');
    const banner = $('#banner');
    const content = $('#content');
    const sidebarPart2 = $('#leftbar_part2');
    const sidebarPlaceholder = sidebarPart2 ? document.createElement('div') : null;
    if (sidebarPlaceholder) {
      sidebarPlaceholder.className = 'argon-sidebar-placeholder';
      sidebarPlaceholder.hidden = true;
      sidebarPlaceholder.setAttribute('aria-hidden', 'true');
      sidebarPart2.before(sidebarPlaceholder);
    }

    function updateToolbar() {
      if (!toolbar || !content) return;
      const bannerHidden = !banner || banner.getClientRects().length === 0 || root.classList.contains('no-banner');
      const blur = root.classList.contains('toolbar-blur');
      const maxOpacity = blur ? .65 : .85;
      let ratio = 1;
      if (!bannerHidden && bannerContainer) {
        const start = bannerContainer.getBoundingClientRect().top + window.scrollY - 75;
        const end = content.getBoundingClientRect().top + window.scrollY - 75;
        ratio = end > start ? Math.max(0, Math.min(1, (window.scrollY - start) / (end - start))) : (window.scrollY >= end ? 1 : 0);
      }
      toolbar.style.setProperty('background-color', `rgba(var(--toolbar-color), ${ratio * maxOpacity})`, 'important');
      toolbar.style.setProperty('box-shadow', ratio === 0 ? 'none' : '');
      toolbar.classList.toggle('navbar-ontop', !bannerHidden && ratio === 0);
      toolbar.classList.toggle('navbar-no-blur', bannerHidden && window.scrollY < 30);
      if (blur) toolbar.style.setProperty('backdrop-filter', ratio > .3 ? 'blur(16px)' : 'blur(0px)');
    }

    function releaseSidebar() {
      if (!sidebarPart2) return;
      sidebarPart2.classList.remove('sticky');
      sidebarPart2.style.removeProperty('width');
      sidebarPart2.style.removeProperty('left');
      sidebarPlaceholder.hidden = true;
    }

    function updateSidebar() {
      if (!sidebarPart2 || !sidebar) return;
      if (window.innerWidth <= 900 || root.classList.contains('single-column') || root.classList.contains('readingmode') || sidebar.getClientRects().length === 0) {
        releaseSidebar();
        return;
      }
      const fixed = sidebarPart2.classList.contains('sticky');
      const naturalTop = (fixed ? sidebarPlaceholder : sidebarPart2).getBoundingClientRect().top;
      if (naturalTop > 85) { if (fixed) releaseSidebar(); return; }
      if (!fixed) {
        const style = getComputedStyle(sidebarPart2);
        sidebarPlaceholder.style.marginTop = style.marginTop;
        sidebarPlaceholder.style.marginBottom = style.marginBottom;
        sidebarPlaceholder.style.height = `${sidebarPart2.offsetHeight}px`;
        sidebarPlaceholder.hidden = false;
        sidebarPart2.classList.add('sticky');
      }
      // The placeholder remains in normal flow, so float height and scroll height do not change.
      const rect = sidebarPlaceholder.getBoundingClientRect();
      sidebarPart2.style.width = `${rect.width}px`;
      sidebarPart2.style.left = `${rect.left}px`;
      sidebarPlaceholder.style.height = `${sidebarPart2.offsetHeight}px`;
    }

    let scheduled = false;
    function updateScroll() {
      scheduled = false;
      updateToolbar();
      updateSidebar();
      const total = root.scrollHeight - window.innerHeight;
      const progress = total <= 0 ? 0 : Math.max(0, Math.min(100, Math.round(window.scrollY / total * 100)));
      const bar = $('#fabtn_reading_progress_bar');
      const label = $('#fabtn_reading_progress_details');
      if (bar) bar.style.width = `${progress}%`;
      if (label) label.textContent = `${progress}%`;
      root.style.setProperty('--reading-progress', `${progress}%`);
      $('#fabtn_back_to_top')?.classList.toggle('fabtn-hidden', window.scrollY < 200);
      let active = -1;
      headings.forEach((heading, index) => { if (heading.getBoundingClientRect().top <= 120) active = index; });
      if (active < 0 && headings.length) active = 0;
      tocLinks.forEach((link, index) => { link.classList.toggle('active', index === active); if (index === active) link.setAttribute('aria-current', 'location'); else link.removeAttribute('aria-current'); });
    }
    window.addEventListener('scroll', () => { if (!scheduled) { scheduled = true; requestAnimationFrame(updateScroll); } }, {passive: true});
    window.addEventListener('resize', updateScroll, {passive: true});
    window.addEventListener('load', updateScroll, {once: true});
    if (sidebarPart2 && typeof ResizeObserver !== 'undefined') {
      new ResizeObserver(() => {
        if (!scheduled) { scheduled = true; requestAnimationFrame(updateScroll); }
      }).observe(sidebarPart2);
    }
    updateScroll();

    if (article) {
      $$('pre', article).forEach(pre => {
        const code = $('code', pre);
        if (!code) return;
        pre.classList.add('argon-code-block');
        const copy = document.createElement('button');
        copy.type = 'button'; copy.className = 'argon-code-copy'; copy.textContent = '复制'; copy.setAttribute('aria-label', '复制代码');
        copy.addEventListener('click', async () => {
          try {
            if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(code.textContent);
            else {
              const input = document.createElement('textarea'); input.value = code.textContent; input.style.position = 'fixed'; input.style.opacity = '0'; document.body.append(input); input.select();
              const success = document.execCommand('copy'); input.remove(); if (!success) throw new Error('Copy unavailable');
            }
            copy.textContent = '已复制';
          } catch (_) { copy.textContent = '请手动复制'; }
          setTimeout(() => { copy.textContent = '复制'; }, 1800);
        });
        pre.append(copy);
      });
      let lightbox;
      $$('img', article).forEach(img => {
        if (img.closest('a') || img.classList.contains('no-zoom')) return;
        img.classList.add('argon-zoomable');
        img.tabIndex = 0;
        img.setAttribute('role', 'button');
        img.setAttribute('aria-label', img.alt ? `放大图片：${img.alt}` : '放大图片');
        function zoom() {
          if (typeof HTMLDialogElement === 'undefined') return;
          if (!lightbox) {
            lightbox = document.createElement('dialog'); lightbox.className = 'argon-lightbox'; lightbox.setAttribute('aria-label', '图片预览');
            const image = document.createElement('img');
            const close = document.createElement('button'); close.type = 'button'; close.className = 'argon-lightbox-close'; close.textContent = '×'; close.setAttribute('aria-label', '关闭图片预览');
            close.addEventListener('click', () => lightbox.close()); lightbox.addEventListener('click', event => { if (event.target === lightbox) lightbox.close(); });
            lightbox.append(close, image); document.body.append(lightbox);
          }
          const image = $('img', lightbox); image.src = img.currentSrc || img.src; image.alt = img.alt;
          lightbox.showModal();
        }
        img.addEventListener('click', zoom);
        img.addEventListener('keydown', event => { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); zoom(); } });
      });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, {once: true});
  else initialize();
})();
