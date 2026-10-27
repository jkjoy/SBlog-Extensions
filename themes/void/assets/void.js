/* VOID for Simple PHP Blog. Native interactions; original design © AlanDecode, MIT. */
(() => {
  'use strict';
  const body = document.body;
  if (!body || window.__voidReady) return;
  window.__voidReady = true;
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const systemDark = matchMedia('(prefers-color-scheme: dark)');
  const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
  const storage = {
    get(key) { try { return localStorage.getItem(key); } catch (_) { return null; } },
    set(key, value) { try { value === null ? localStorage.removeItem(key) : localStorage.setItem(key, value); } catch (_) {} },
  };
  let observer;
  let navigation;
  let toastTimer;
  let focusedBeforePanel;
  let lightboxImages = [];
  let lightboxIndex = 0;
  let lightboxTrigger;
  let loginRequest;
  let loginTrigger;
  let previousScroll = scrollY;
  let scrollFrame;
  let historyTimer;
  const behavior = () => reducedMotion.matches ? 'auto' : 'smooth';

  function automaticDark() {
    const start = Number(body.dataset.darkStart || 22);
    const end = Number(body.dataset.darkEnd || 7);
    const now = new Date();
    const hour = now.getHours() + now.getMinutes() / 60;
    const duringNight = start === end ? false : start < end ? hour >= start && hour < end : hour >= start || hour < end;
    return duringNight || (body.dataset.followSystem !== '0' && systemDark.matches);
  }

  function notify(message) {
    let toast = $('#void-message');
    if (!toast) {
      toast = document.createElement('div');
      toast.id = 'void-message';
      toast.className = 'void-toast';
      toast.setAttribute('role', 'status');
      toast.setAttribute('aria-live', 'polite');
      body.append(toast);
    }
    clearTimeout(toastTimer);
    toast.textContent = message;
    toast.hidden = false;
    toastTimer = setTimeout(() => { toast.hidden = true; }, 3200);
  }

  function applyTheme(mode = storage.get('void-color-scheme') || body.dataset.colorScheme || 'auto') {
    const dark = mode === 'dark' || (mode === 'auto' && automaticDark());
    body.classList.toggle('theme-dark', dark);
    $$('[data-void-theme]').filter(button => button !== body).forEach(button => {
      button.setAttribute('aria-pressed', String(dark));
      button.setAttribute('aria-label', dark ? '切换到日间模式' : '切换到夜间模式');
      button.title = dark ? '切换到日间模式' : '切换到夜间模式';
    });
    $$('[data-void-auto-theme]').forEach(button => button.setAttribute('aria-pressed', String(mode === 'auto')));
    $('meta[name="theme-color"]')?.setAttribute('content', dark ? '#1a1a1a' : '#ffffff');
  }

  function applyFont() {
    const configuredSize = Number(storage.get('void-font-size') || body.getAttribute('fontsize') || 3);
    const size = Number.isFinite(configuredSize) ? Math.min(5, Math.max(1, configuredSize)) : 3;
    body.setAttribute('fontsize', String(size));
    const serif = storage.get('void-font') ? storage.get('void-font') === 'serif' : body.classList.contains('serif');
    body.classList.toggle('serif', serif);
    $$('[data-void-font]').forEach(button => {
      const checked = (button.dataset.voidFont === 'serif') === serif;
      button.classList.toggle('checked', checked);
      button.setAttribute('aria-pressed', String(checked));
    });
    const sizeLabel = $('#current_textsize');
    if (sizeLabel) sizeLabel.setAttribute('aria-label', `字号 ${12 + size * 2} 像素`);
  }

  function panel(selector, className, toggleSelector, open, focus = true) {
    const target = $(selector);
    if (!target) return;
    const wasOpen = !target.hidden;
    const nextOpen = open === undefined ? !wasOpen : open;
    if (nextOpen && !wasOpen) focusedBeforePanel = document.activeElement;
    target.hidden = !nextOpen;
    if (selector === '#nav-mobile' || selector === '.mobile-search-form') target.classList.toggle('opened', nextOpen);
    body.classList.toggle(className, nextOpen);
    $$(toggleSelector).forEach(button => {
      button.setAttribute('aria-expanded', String(nextOpen));
      if (toggleSelector === '[data-void-menu]') button.classList.toggle('pushed', nextOpen);
    });
    if (nextOpen && focus) $('input, button, a, [tabindex="0"]', target)?.focus({ preventScroll: true });
    if (!nextOpen && wasOpen && focus) focusedBeforePanel?.focus({ preventScroll: true });
  }

  function closePanels(restoreFocus = false) {
    panel('#setting-panel', 'setting-panel-show', '[data-void-settings]', false, restoreFocus);
    panel('#nav-mobile', 'nav-show', '[data-void-menu]', false, restoreFocus);
    panel('.mobile-search-form', 'search-show', '[data-void-search]', false, restoreFocus);
  }

  function buildTOC() {
    observer?.disconnect();
    const list = $('#toc-content');
    const headings = $$('.articleBody h2, .articleBody h3, .articleBody h4');
    const sidebar = $('#toc');
    if (!list) return;
    list.replaceChildren();
    const used = new Set($$('[id]').filter(node => !headings.includes(node)).map(node => node.id));
    headings.forEach((heading, index) => {
      const original = heading.id || `heading-${index + 1}`;
      let id = original;
      let suffix = 1;
      while (used.has(id)) id = `${original}-${suffix++}`;
      heading.id = id;
      used.add(id);
      const link = document.createElement('a');
      link.href = `#${encodeURIComponent(id)}`;
      link.textContent = heading.textContent;
      link.className = `toc-link toc-level-${heading.tagName.slice(1)}`;
      link.dataset.level = heading.tagName.slice(1);
      list.append(link);
    });
    $$('[data-void-toc]').forEach(button => {
      button.hidden = headings.length === 0;
      const item = button.closest('#toggle-toc');
      if (item) item.hidden = headings.length === 0;
    });
    if (!headings.length) {
      if (sidebar) sidebar.hidden = true;
      body.classList.remove('sidebar-show');
      return;
    }
    observer = new IntersectionObserver(entries => {
      const visible = entries.filter(entry => entry.isIntersecting).sort((a, b) => a.boundingClientRect.top - b.boundingClientRect.top)[0];
      if (!visible) return;
      $$('.toc-link', list).forEach(link => {
        const active = decodeURIComponent(link.hash.slice(1)) === visible.target.id;
        link.classList.toggle('is-active-link', active);
        active ? link.setAttribute('aria-current', 'location') : link.removeAttribute('aria-current');
      });
    }, { rootMargin: '-70px 0px -65% 0px' });
    headings.forEach(heading => observer.observe(heading));
  }

  function updateLightbox() {
    const source = lightboxImages[lightboxIndex];
    const dialog = $('#void-lightbox');
    const image = dialog && $('img', dialog);
    if (!source || !image) return;
    image.src = source.currentSrc || source.src;
    image.alt = source.alt || '';
    const caption = $('.void-lightbox-caption', dialog);
    if (caption) caption.textContent = `${source.alt || source.title || ''}${lightboxImages.length > 1 ? ` · ${lightboxIndex + 1} / ${lightboxImages.length}` : ''}`;
    $$('[data-void-lightbox-prev], [data-void-lightbox-next]', dialog).forEach(button => { button.hidden = lightboxImages.length < 2; });
  }

  function openLightbox(source) {
    const dialog = $('#void-lightbox');
    if (!dialog?.showModal) return;
    lightboxIndex = lightboxImages.indexOf(source);
    lightboxTrigger = document.activeElement;
    updateLightbox();
    dialog.showModal();
  }

  function closeLightbox() {
    const dialog = $('#void-lightbox');
    if (dialog?.open) dialog.close();
    lightboxTrigger?.focus({ preventScroll: true });
  }

  async function copy(text) {
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    const input = document.createElement('textarea');
    input.value = text;
    input.style.cssText = 'position:fixed;opacity:0;top:0;left:0';
    body.append(input);
    input.select();
    const copied = document.execCommand('copy');
    input.remove();
    if (!copied) throw new Error('copy');
  }

  function enhanceContent() {
    const main = $('#pjax-container');
    if (!main) return;
    lightboxImages = $$('.articleBody img', main).filter(image => {
      if (image.closest('[data-no-lightbox], .no-lightbox')) return false;
      const anchor = image.closest('a');
      if (anchor && anchor.href !== image.src && anchor.href !== image.currentSrc) return false;
      return true;
    });
    lightboxImages.forEach(image => {
      image.loading = 'lazy';
      image.decoding = 'async';
      image.classList.add('void-zoomable');
      image.tabIndex = 0;
      image.setAttribute('role', 'button');
      image.setAttribute('aria-label', `放大图片${image.alt ? `：${image.alt}` : ''}`);
    });
    $$('.articleBody pre > code', main).forEach(code => {
      const language = code.className.match(/(?:language-|lang-)([\w-]+)/)?.[1] || 'none';
      code.classList.add(`language-${language}`);
      code.parentElement.classList.add(`language-${language}`);
      code.parentElement.dataset.lang = language === 'none' ? 'CODE' : language.toUpperCase();
    });
    if (window.Prism) window.Prism.highlightAllUnder(main);
    $$('.articleBody pre', main).forEach(pre => {
      if ($('[data-void-copy]', pre)) return;
      const code = $('code', pre);
      if (!code) return;
      if (body.dataset.lineNumbers === '1' && !$('.line-numbers-rows', pre)) {
        pre.classList.add('line-numbers');
        const lines = document.createElement('span');
        lines.className = 'line-numbers-rows';
        lines.setAttribute('aria-hidden', 'true');
        const count = code.textContent.replace(/\n$/, '').split('\n').length;
        for (let i = 0; i < count; i++) lines.append(document.createElement('span'));
        pre.append(lines);
      }
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'void-copy void-code-copy';
      button.dataset.voidCopy = '';
      button.textContent = '复制';
      button.setAttribute('aria-label', '复制代码');
      pre.append(button);
    });
    buildTOC();
    enhanceComments(main);
    if (body.dataset.math === '1') renderMath(main);
    document.dispatchEvent(new CustomEvent('void:load', { detail: { container: main } }));
  }

  async function renderMath(container) {
    const text = $$('.articleBody', container).map(article => {
      const walker = document.createTreeWalker(article, NodeFilter.SHOW_TEXT, {
        acceptNode(node) {
          return node.parentElement?.closest('pre, code, script, style, textarea, .MathJax, mjx-container')
            ? NodeFilter.FILTER_REJECT : NodeFilter.FILTER_ACCEPT;
        },
      });
      const parts = [];
      while (walker.nextNode()) parts.push(walker.currentNode.textContent);
      return parts.join('\n');
    }).join('\n');
    if (!/(^|[^\\])\$\$[\s\S]+?\$\$|\\\([\s\S]+?\\\)|\\\[[\s\S]+?\\\]|(^|[^\\])\$(?!\s)(?:\\.|[^$\n])*[^\s$\\]\$|\\begin\{(?:equation\*?|align\*?|gather\*?|math|displaymath)\}/.test(text)) return;
    try {
      if (!window.MathJax && body.dataset.mathUrl && !window.__voidMathLoading) {
        window.MathJax = { tex: { inlineMath: [['$', '$'], ['\\(', '\\)']] }, options: { skipHtmlTags: ['script', 'noscript', 'style', 'textarea', 'pre', 'code'] } };
        window.__voidMathLoading = new Promise((resolve, reject) => {
          const script = document.createElement('script');
          script.src = body.dataset.mathUrl;
          script.async = true;
          script.onload = resolve;
          script.onerror = reject;
          document.head.append(script);
        });
      }
      if (window.__voidMathLoading) await window.__voidMathLoading;
      await window.MathJax?.startup?.promise;
      if (window.MathJax?.typesetPromise) await window.MathJax.typesetPromise([container]);
    } catch (_) { /* Formulas remain readable if the optional renderer is unavailable. */ }
  }

  function enhanceComments(container) {
    const form = $('#comment-form', container);
    const textarea = form && $('#comment-content', form);
    if (!textarea || $('[data-void-emojis]', form)) return;
    const toolbar = document.createElement('div');
    toolbar.className = 'void-comment-tools';
    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'btn btn-narrow';
    toggle.dataset.voidEmojis = '';
    toggle.textContent = 'OωO';
    toggle.setAttribute('aria-label', '选择评论表情');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-controls', 'void-comment-emojis');
    const grid = document.createElement('div');
    grid.id = 'void-comment-emojis';
    grid.className = 'comment-emojis';
    grid.hidden = true;
    grid.setAttribute('role', 'group');
    grid.setAttribute('aria-label', '评论表情');
    ['😊', '😂', '🥰', '😎', '🤔', '🙌', '👍', '❤️', '🎉', '🌸', '(｡･ω･｡)', '(๑•̀ㅂ•́)و✧', '(/ω＼)', '(╯°□°）╯︵ ┻━┻'].forEach(emoji => {
      const button = document.createElement('button');
      button.type = 'button';
      button.dataset.voidEmoji = emoji;
      button.textContent = emoji;
      button.setAttribute('aria-label', `插入 ${emoji}`);
      grid.append(button);
    });
    toolbar.append(toggle, grid);
    const actions = $('.comment-form__actions', form);
    if (actions) actions.before(toolbar);
    else textarea.after(toolbar);
  }

  function safeNavigation(anchor) {
    if (body.dataset.pjax !== '1' || !anchor || anchor.hasAttribute('download') || anchor.hasAttribute('no-pjax') || anchor.closest('[data-no-pjax]')) return null;
    if (anchor.target && anchor.target !== '_self') return null;
    const url = new URL(anchor.href, location.href);
    if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin) return null;
    if (url.pathname === location.pathname && url.search === location.search && url.hash) return null;
    if (anchor.getAttribute('rel')?.split(/\s+/).includes('external')) return null;
    if (/(?:^|\/)(?:admin|login|logout|write|edit|install\.php|installer\.php|update\.php|uploads|assets|themes|plugins|rss\.xml|sitemap\.xml)(?:\/|$)/i.test(url.pathname)) return null;
    if (/\.(?:xml|json|zip|pdf|png|jpe?g|gif|webp|svg|mp[34]|woff2?|css|js)$/i.test(url.pathname)) return null;
    const action = url.searchParams.get('a');
    if (action && !['home', 'post', 'page', 'archives', 'tags', 'tag', 'categories', 'category', 'links'].includes(action)) return null;
    return url;
  }

  function syncMetadata(next) {
    document.title = next.title;
    ['meta[name="description"]', 'link[rel="canonical"]', 'meta[property="og:title"]', 'meta[property="og:description"]', 'meta[property="og:url"]', 'meta[property="og:image"]'].forEach(selector => {
      const incoming = $(selector, next);
      const current = $(selector);
      if (incoming) current ? current.replaceWith(incoming.cloneNode(true)) : document.head.append(incoming.cloneNode(true));
      else current?.remove();
    });
    $$('script[type="application/ld+json"][data-void-schema]').forEach(node => node.remove());
    $$('script[type="application/ld+json"][data-void-schema]', next.head).forEach(node => document.head.append(node.cloneNode(true)));
    const oldHeader = $('body > header');
    const newHeader = $('body > header', next);
    if (oldHeader && newHeader) {
      oldHeader.classList.toggle('force-dark', newHeader.classList.contains('force-dark'));
      oldHeader.classList.toggle('no-banner', newHeader.classList.contains('no-banner'));
      $$('a[aria-current]', oldHeader).forEach(link => link.removeAttribute('aria-current'));
      $$('a[aria-current]', newHeader).forEach(link => {
        $$('a', oldHeader).filter(item => item.getAttribute('href') === link.getAttribute('href')).forEach(item => item.setAttribute('aria-current', 'page'));
      });
    }
    [...body.classList].filter(name => /^void-(?:home|post|page|archive|search|index|error)/.test(name)).forEach(name => body.classList.remove(name));
    [...next.body.classList].filter(name => /^void-(?:home|post|page|archive|search|index|error)/.test(name)).forEach(name => body.classList.add(name));
  }

  async function navigate(url, { pop = false, scroll = 0 } = {}) {
    navigation?.abort();
    const controller = new AbortController();
    navigation = controller;
    body.classList.add('void-loading');
    body.dataset.navigating = 'true';
    body.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, { credentials: 'same-origin', headers: { 'X-VOID-PJAX': '1' }, signal: controller.signal, cache: 'no-store' });
      const responseURL = new URL(response.url);
      if (responseURL.origin !== location.origin || !response.headers.get('content-type')?.includes('text/html')) throw new Error('navigation');
      const next = new DOMParser().parseFromString(await response.text(), 'text/html');
      const main = $('#pjax-container', next);
      if (!main || !next.body.classList.contains('void-theme') || $('script:not([type="application/ld+json"]):not([type="application/json"])', main)) throw new Error('document');
      if (controller.signal.aborted) return;
      if (!pop) history.replaceState({ ...history.state, voidScroll: scrollY }, '', location.href);
      closePanels();
      panel('#toc', 'sidebar-show', '[data-void-toc]', false, false);
      window.MathJax?.typesetClear?.([$('#pjax-container')]);
      $('#pjax-container').replaceWith(main);
      syncMetadata(next);
      const destination = new URL(response.url);
      destination.hash = new URL(url, location.href).hash;
      if (!pop) history.pushState({ voidPage: true, voidScroll: 0 }, '', destination);
      enhanceContent();
      if (typeof window.initComments === 'function') window.initComments();
      if (destination.hash) {
        let target;
        try { target = document.getElementById(decodeURIComponent(destination.hash.slice(1))); } catch (_) {}
        target?.scrollIntoView({ block: 'start' });
      } else window.scrollTo({ top: pop ? scroll : 0, behavior: 'instant' });
      main.setAttribute('tabindex', '-1');
      main.focus({ preventScroll: true });
    } catch (error) {
      if (error.name !== 'AbortError') location.assign(url);
    } finally {
      if (navigation === controller) {
        body.classList.remove('void-loading');
        delete body.dataset.navigating;
        body.removeAttribute('aria-busy');
      }
    }
  }

  async function submitComment(form) {
    if (form.dataset.submitting === '1') return;
    form.dataset.submitting = '1';
    const button = $('[type="submit"]', form);
    const originalLabel = button?.textContent;
    if (button) { button.disabled = true; button.textContent = '正在提交…'; }
    try {
      const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', cache: 'no-store' });
      const next = new DOMParser().parseFromString(await response.text(), 'text/html');
      const comments = $('#comments', next);
      if (!comments || new URL(response.url).origin !== location.origin) throw new Error('comment');
      const main = $('#pjax-container', next);
      const postId = new FormData(form).get('post_id');
      const incomingPostId = $('[name="post_id"]', comments)?.value;
      // Refresh the article too: an approved reply may unlock reply-hidden content.
      const samePost = postId && incomingPostId === postId && main
        && next.body.classList.contains('void-theme')
        && ['post', 'page'].includes(main.dataset.page)
        && !$('script:not([type="application/ld+json"]):not([type="application/json"])', main);
      if (samePost) {
        window.MathJax?.typesetClear?.([$('#pjax-container')]);
        $('#pjax-container').replaceWith(main);
        syncMetadata(next);
        enhanceContent();
      } else {
        $('#comments').replaceWith(comments);
        enhanceComments(comments.parentElement);
      }
      if (typeof window.initComments === 'function') window.initComments();
      const error = $('#comment-errors, .comment-notice--error', comments);
      const notice = error || $('.comment-notice', comments) || comments;
      notice.scrollIntoView({ block: 'center', behavior: behavior() });
      const focus = error ? $('[aria-invalid="true"]', comments) : $('#comment-content', comments);
      focus?.focus({ preventScroll: true });
      document.dispatchEvent(new CustomEvent('void:comments-updated', { detail: { container: comments } }));
    } catch (_) {
      notify('评论提交失败，请保留内容后重试。');
    } finally {
      delete form.dataset.submitting;
      if (button) { button.disabled = false; button.textContent = originalLabel; }
    }
  }

  // The core comments handler stops bubbling; handle only emoji controls during capture.
  document.addEventListener('click', event => {
    if (!(event.target instanceof Element)) return;
    const control = event.target.closest('[data-void-emojis], [data-void-emoji]');
    if (!control) return;
    const form = control.closest('#comment-form');
    const textarea = form && $('#comment-content', form);
    const grid = form && $('#void-comment-emojis', form);
    const toggle = form && $('[data-void-emojis]', form);
    if (!textarea || !grid || !toggle) return;
    event.preventDefault();
    if (control.hasAttribute('data-void-emojis')) {
      grid.hidden = !grid.hidden;
      toggle.setAttribute('aria-expanded', String(!grid.hidden));
      if (!grid.hidden) $('button', grid)?.focus();
      return;
    }
    const emoji = control.dataset.voidEmoji;
    const start = textarea.selectionStart;
    const end = textarea.selectionEnd;
    if (textarea.maxLength > 0 && textarea.value.length - (end - start) + emoji.length > textarea.maxLength) {
      notify('评论内容已达到字数限制。');
      return;
    }
    textarea.setRangeText(emoji, start, end, 'end');
    textarea.dispatchEvent(new Event('input', { bubbles: true }));
    grid.hidden = true;
    toggle.setAttribute('aria-expanded', 'false');
    textarea.focus();
  }, true);

  async function submitLike(form) {
    const button = $('button', form);
    if (!button || button.disabled) return;
    button.disabled = true;
    try {
      const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const result = await response.json();
      if (!response.ok || !result.ok) throw new Error('like');
      button.setAttribute('aria-pressed', String(Boolean(result.liked)));
      button.classList.toggle('done', Boolean(result.liked));
      const count = $('[data-like-count]', form);
      if (count) count.textContent = String(result.count);
    } catch (_) { notify('暂时无法点赞，请稍后重试。'); }
    finally { button.disabled = false; }
  }

  function renderLoginForm(next, responseURL, username = '') {
    const panelBody = $('.auth-panel .panel__body', next);
    const incoming = panelBody && $('form[action]', panelBody);
    const content = $('#void-login-content');
    if (!incoming || !content || incoming.method.toLowerCase() !== 'post'
      || !$('input[name="username"]', incoming) || !$('input[name="password"][type="password"]', incoming)
      || !$('input[name="csrf_token"]', incoming)) return false;
    const action = new URL(incoming.getAttribute('action'), responseURL);
    if (action.origin !== location.origin || !/^https?:$/.test(action.protocol)) return false;
    const form = incoming.cloneNode(true);
    form.action = action.href;
    form.classList.add('void-login-form');
    form.dataset.noPjax = '';
    $$('script, .auth-password-toggle', form).forEach(node => node.remove());
    $$('[autofocus]', form).forEach(node => node.removeAttribute('autofocus'));
    const name = $('input[name="username"]', form);
    if (!name.value && username) name.value = username;
    const alerts = $$('[role="alert"]', panelBody).filter(node => !incoming.contains(node)).map(node => node.cloneNode(true));
    content.replaceChildren(...alerts, form);
    (alerts.length ? $('input[name="password"]', form) : name)?.focus({ preventScroll: true });
    return true;
  }

  async function synchronizeLogin() {
    // Authentication rotates the session and CSRF token; reload the same blog page.
    try { await fetch(location.href, { credentials: 'same-origin', cache: 'no-store' }); }
    finally { location.reload(); }
  }

  async function openLogin(link) {
    const dialog = $('#void-login');
    const content = $('#void-login-content');
    const url = new URL(link.href, location.href);
    if (!dialog?.showModal || !content || url.origin !== location.origin) { location.assign(link.href); return; }
    loginRequest?.abort();
    const controller = new AbortController();
    loginRequest = controller;
    loginTrigger = link;
    closePanels(false);
    content.textContent = '正在加载登录表单…';
    if (!dialog.open) dialog.showModal();
    dialog.setAttribute('aria-busy', 'true');
    try {
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: controller.signal });
      if (new URL(response.url).origin !== location.origin || !response.headers.get('content-type')?.includes('text/html')) throw new Error('login');
      const next = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (controller.signal.aborted) return;
      if (renderLoginForm(next, response.url)) return;
      if (response.ok && $('#admin-sidebar', next) && !next.body.classList.contains('theme-admin--guest')) { await synchronizeLogin(); return; }
      throw new Error('login');
    } catch (error) {
      if (error.name !== 'AbortError') {
        dialog.close();
        location.assign(link.href);
      }
    } finally {
      if (loginRequest === controller) dialog.removeAttribute('aria-busy');
    }
  }

  function closeLogin() {
    const dialog = $('#void-login');
    if (dialog?.open) dialog.close();
  }

  async function submitLogin(form) {
    if (form.dataset.submitting === '1') return;
    const action = new URL(form.action, location.href);
    if (action.origin !== location.origin || !/^https?:$/.test(action.protocol)) return;
    form.dataset.submitting = '1';
    const button = $('[type="submit"]', form);
    const label = button?.textContent;
    const username = $('input[name="username"]', form)?.value || '';
    const payload = new FormData(form);
    if (button) { button.disabled = true; button.textContent = '正在登录…'; }
    try {
      const response = await fetch(action, { method: 'POST', body: payload, credentials: 'same-origin', cache: 'no-store' });
      if (new URL(response.url).origin !== location.origin || !response.headers.get('content-type')?.includes('text/html')) throw new Error('login');
      const next = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (!form.isConnected && $('.auth-panel form', next)) return;
      if (renderLoginForm(next, response.url, username)) return;
      if (response.ok && $('#admin-sidebar', next) && !next.body.classList.contains('theme-admin--guest')) { await synchronizeLogin(); return; }
      throw new Error('login');
    } catch (_) { if (form.isConnected) notify('登录请求失败，请保留输入后重试，或打开完整登录页。'); }
    finally {
      delete form.dataset.submitting;
      if (button) { button.disabled = false; button.textContent = label; }
    }
  }

  $('#void-login')?.addEventListener('close', () => {
    loginRequest?.abort();
    $('#void-login-content')?.replaceChildren();
    if (loginTrigger?.isConnected) loginTrigger.focus({ preventScroll: true });
  });

  document.addEventListener('click', async event => {
    if (!(event.target instanceof Element)) return;
    const target = event.target;
    const loginLink = target.closest('a[data-void-login]');
    if (loginLink && !event.defaultPrevented && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) {
      event.preventDefault(); openLogin(loginLink); return;
    }
    const control = target.closest('[data-void-menu], [data-void-search], [data-void-settings], [data-void-theme], [data-void-auto-theme], [data-void-font], [data-void-font-size], [data-void-top], [data-void-toc], [data-void-archive], [data-void-copy], [data-void-share], [data-void-lightbox-close], [data-void-lightbox-prev], [data-void-lightbox-next], [data-void-login-close]');
    if (control && control !== body) {
      event.preventDefault();
      if (control.hasAttribute('data-void-menu')) panel('#nav-mobile', 'nav-show', '[data-void-menu]');
      else if (control.hasAttribute('data-void-search')) panel('.mobile-search-form', 'search-show', '[data-void-search]');
      else if (control.hasAttribute('data-void-settings')) panel('#setting-panel', 'setting-panel-show', '[data-void-settings]');
      else if (control.hasAttribute('data-void-theme')) {
        const mode = body.classList.contains('theme-dark') ? 'light' : 'dark';
        storage.set('void-color-scheme', mode); applyTheme(mode);
      } else if (control.hasAttribute('data-void-auto-theme')) { storage.set('void-color-scheme', 'auto'); applyTheme('auto'); }
      else if (control.hasAttribute('data-void-font')) { storage.set('void-font', control.dataset.voidFont); applyFont(); }
      else if (control.hasAttribute('data-void-font-size')) {
        storage.set('void-font-size', String(Math.min(5, Math.max(1, Number(body.getAttribute('fontsize')) + Number(control.dataset.voidFontSize)))));
        applyFont();
      } else if (control.hasAttribute('data-void-top')) window.scrollTo({ top: 0, behavior: behavior() });
      else if (control.hasAttribute('data-void-toc')) panel('#toc', 'sidebar-show', '[data-void-toc]');
      else if (control.hasAttribute('data-void-archive')) {
        const section = document.getElementById(control.getAttribute('aria-controls'));
        if (section) {
          section.hidden = !section.hidden;
          section.classList.toggle('shrink', section.hidden);
          control.setAttribute('aria-expanded', String(!section.hidden));
          control.textContent = section.hidden ? '+' : '−';
        }
      } else if (control.hasAttribute('data-void-copy')) {
        try { await copy($('code', control.closest('pre')).textContent); control.textContent = '已复制'; }
        catch (_) { notify('复制失败，请手动选择代码。'); }
        setTimeout(() => { control.textContent = '复制'; }, 1800);
      } else if (control.hasAttribute('data-void-share')) {
        try {
          if (navigator.share) await navigator.share({ title: document.title, url: location.href });
          else { await copy(location.href); notify('文章链接已复制。'); }
        } catch (error) { if (error.name !== 'AbortError') notify('分享失败，请复制地址栏链接。'); }
      } else if (control.hasAttribute('data-void-login-close')) closeLogin();
      else if (control.hasAttribute('data-void-lightbox-close')) closeLightbox();
      else if (control.hasAttribute('data-void-lightbox-prev') || control.hasAttribute('data-void-lightbox-next')) {
        lightboxIndex = (lightboxIndex + (control.hasAttribute('data-void-lightbox-prev') ? -1 : 1) + lightboxImages.length) % lightboxImages.length;
        updateLightbox();
      }
      return;
    }
    if (target.matches('.void-zoomable')) { event.preventDefault(); openLightbox(target); return; }
    if (target === $('#void-lightbox')) { closeLightbox(); return; }
    if (target === $('#void-login')) {
      const bounds = target.getBoundingClientRect();
      if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeLogin();
      return;
    }
    if (!target.closest('#setting-panel, [data-void-settings]')) panel('#setting-panel', 'setting-panel-show', '[data-void-settings]', false, false);
    const anchor = target.closest('a[href]');
    if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    const url = safeNavigation(anchor);
    if (url) { event.preventDefault(); navigate(url.href); }
  });

  document.addEventListener('submit', event => {
    if (!(event.target instanceof HTMLFormElement) || event.defaultPrevented) return;
    const form = event.target;
    if (form.matches('.void-login-form')) {
      event.preventDefault(); submitLogin(form);
    } else if (form.id === 'comment-form' && body.dataset.ajaxComments !== '0') {
      event.preventDefault(); submitComment(form);
    } else if (form.matches('.void-like')) {
      event.preventDefault(); submitLike(form);
    } else if (body.dataset.pjax === '1' && form.matches('[data-void-search-form]')) {
      event.preventDefault();
      const url = new URL(form.action, location.href);
      for (const [key, value] of new FormData(form)) url.searchParams.set(key, value);
      navigate(url.href);
    }
  });

  document.addEventListener('keydown', event => {
    const dialog = $('#void-lightbox');
    if (event.key === 'Escape' && $('#void-login')?.open) {
      event.preventDefault(); closeLogin(); return;
    }
    if (event.key === 'Escape') {
      if (dialog?.open) closeLightbox();
      closePanels(true);
      panel('#toc', 'sidebar-show', '[data-void-toc]', false, true);
      const emojiGrid = $('#void-comment-emojis');
      if (emojiGrid && !emojiGrid.hidden) {
        emojiGrid.hidden = true;
        const toggle = $('[data-void-emojis]');
        toggle?.setAttribute('aria-expanded', 'false');
        toggle?.focus();
      }
    } else if (event.key === 'Tab' && $('#setting-panel') && !$('#setting-panel').hidden && !dialog?.open && !$('#void-login')?.open) {
      const settings = $('#setting-panel');
      const focusable = $$('a[href], button:not([disabled]), input:not([disabled]), [tabindex="0"]', settings).filter(node => node.getClientRects().length);
      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      if (focusable.length && (event.shiftKey ? document.activeElement === first : document.activeElement === last)) {
        event.preventDefault();
        (event.shiftKey ? last : first).focus();
      }
    } else if (dialog?.open && ['ArrowLeft', 'ArrowRight'].includes(event.key)) {
      event.preventDefault();
      lightboxIndex = (lightboxIndex + (event.key === 'ArrowLeft' ? -1 : 1) + lightboxImages.length) % lightboxImages.length;
      updateLightbox();
    } else if (event.target instanceof Element && event.target.matches('.void-zoomable') && ['Enter', ' '].includes(event.key)) {
      event.preventDefault(); openLightbox(event.target);
    } else if (event.key === ' ' && event.target instanceof Element && event.target.matches('a[role="button"]')) {
      event.preventDefault(); event.target.click();
    }
  });

  window.addEventListener('popstate', event => {
    if (body.dataset.pjax === '1') navigate(location.href, { pop: true, scroll: event.state?.voidScroll || 0 });
  });
  window.addEventListener('scroll', () => {
    if (body.dataset.pjax === '1' && !body.classList.contains('void-loading')) {
      clearTimeout(historyTimer);
      historyTimer = setTimeout(() => history.replaceState({ ...history.state, voidScroll: scrollY }, '', location.href), 200);
    }
    if (scrollFrame) return;
    scrollFrame = requestAnimationFrame(() => {
      scrollFrame = 0;
      const header = $('body > header');
      const current = scrollY;
      header?.classList.toggle('headroom--unpinned', header.classList.contains('header-mode-0') && current > 160 && current > previousScroll && !body.classList.contains('nav-show'));
      header?.classList.toggle('headroom--pinned', current < previousScroll || current < 160);
      header?.classList.toggle('void-header-scrolled', current > 30);
      header?.classList.toggle('pull-up', current > 30);
      $('#go-top')?.classList.toggle('show', current > 300);
      previousScroll = current;
      const progress = $('[data-void-progress]');
      if (progress) progress.style.width = `${Math.min(100, current / Math.max(1, document.documentElement.scrollHeight - innerHeight) * 100)}%`;
    });
  }, { passive: true });
  systemDark.addEventListener('change', () => { if ((storage.get('void-color-scheme') || body.dataset.colorScheme || 'auto') === 'auto') applyTheme('auto'); });
  setInterval(() => { if ((storage.get('void-color-scheme') || body.dataset.colorScheme || 'auto') === 'auto') applyTheme('auto'); }, 60000);
  if (body.dataset.pjax === '1') {
    history.scrollRestoration = 'manual';
    history.replaceState({ ...history.state, voidPage: true, voidScroll: scrollY }, '', location.href);
  }
  applyTheme();
  applyFont();
  enhanceContent();
})();
