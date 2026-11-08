(() => {
  'use strict';

  function init() {
    const body = document.body;
    if (!body.classList.contains('wcf-theme')) return;
    body.classList.add('wcf-ready');
    document.querySelectorAll('.post-content img').forEach((image) => {
      if (image.closest('.wcf-music')) return;
      const source = image.currentSrc || image.getAttribute('src');
      if (!source) return;
      const src = safeUrl(source);
      if (!src) return;
      const existingLink = image.closest('a');
      if (existingLink) {
        if (safeUrl(existingLink.href) === src) existingLink.setAttribute('data-wcf-image', '');
        return;
      }
      const link = document.createElement('a');
      link.href = src;
      link.setAttribute('data-wcf-image', '');
      link.setAttribute('aria-label', image.alt ? `查看图片：${image.alt}` : '查看图片');
      image.before(link);
      link.append(image);
    });
    const root = document.documentElement;
    const reducedMotion = matchMedia('(prefers-reduced-motion: reduce)');
    const systemDark = matchMedia('(prefers-color-scheme: dark)');
    const topbar = document.querySelector('.wcf-topbar');
    const topButton = document.querySelector('[data-wcf-top]');
    const profile = document.querySelector('.wcf-profile');
    const cover = document.querySelector('.wcf-cover');
    function updateProfileSpacing() {
      if (!profile || !cover) return;
      const baseline = matchMedia('(max-width: 768px)').matches ? 81 : 91;
      cover.style.marginBottom = `${Math.max(0, Math.ceil(profile.getBoundingClientRect().height - baseline))}px`;
    }
    updateProfileSpacing();
    if (profile && 'ResizeObserver' in window) {
      new ResizeObserver(updateProfileSpacing).observe(profile);
    }
    const storage = {
      get(key) { try { return localStorage.getItem(key); } catch (_) { return null; } },
      set(key, value) { try { localStorage.setItem(key, value); } catch (_) {} },
    };

    function setTheme(mode) {
      root.dataset.wcfTheme = mode;
      document.querySelectorAll('[data-wcf-theme-toggle]').forEach((button) => {
        button.setAttribute('aria-pressed', mode === 'dark' ? 'true' : 'false');
        button.setAttribute('aria-label', mode === 'dark' ? '切换到日间模式' : '切换到夜间模式');
      });
      document.querySelector('meta[name="theme-color"]')?.setAttribute('content', mode === 'dark' ? '#181818' : '#f0f0f0');
    }
    const storedTheme = storage.get('wcf-theme');
    const appearance = body.dataset.wcfAppearance || 'light';
    setTheme(storedTheme === 'dark' || storedTheme === 'light'
      ? storedTheme : (appearance === 'dark' || (appearance === 'auto' && systemDark.matches) ? 'dark' : 'light'));
    systemDark.addEventListener('change', () => {
      if (appearance === 'auto' && !storage.get('wcf-theme')) setTheme(systemDark.matches ? 'dark' : 'light');
    });

    let scrollFrame = 0;
    function updateScroll() {
      scrollFrame = 0;
      topbar?.classList.toggle('is-scrolled', window.scrollY > 264);
      if (topButton) topButton.hidden = window.scrollY < 400;
    }
    window.addEventListener('scroll', () => {
      if (!scrollFrame) scrollFrame = requestAnimationFrame(updateScroll);
    }, { passive: true });
    updateScroll();

    let savedOverflow = null;
    function syncDialogScroll() {
      const open = Boolean(document.querySelector('dialog[open]'));
      if (open && savedOverflow === null) {
        savedOverflow = body.style.overflow;
        body.style.overflow = 'hidden';
      } else if (!open && savedOverflow !== null) {
        body.style.overflow = savedOverflow;
        savedOverflow = null;
      }
    }
    function openDialog(dialog) {
      if (!(dialog instanceof HTMLDialogElement)) return;
      closeActions();
      if (!dialog.open) dialog.showModal();
      syncDialogScroll();
    }
    function closeDialog(dialog) {
      if (dialog instanceof HTMLDialogElement) dialog.close();
      syncDialogScroll();
    }
    document.addEventListener('close', syncDialogScroll, true);

    function closeActions(except = null) {
      document.querySelectorAll('.wcf-actions-menu').forEach((menu) => {
        if (menu !== except) menu.hidden = true;
      });
      document.querySelectorAll('[data-wcf-actions]').forEach((button) => {
        const menu = document.getElementById(button.getAttribute('aria-controls') || '');
        button.setAttribute('aria-expanded', menu && !menu.hidden ? 'true' : 'false');
      });
    }

    function initializeCollapses(scope = document) {
      scope.querySelectorAll('[data-wcf-collapse]').forEach((text) => {
        const button = text.closest('.wcf-post')?.querySelector('[data-wcf-expand]');
        if (!button) return;
        if (text.classList.contains('is-expanded')) {
          button.hidden = false;
          return;
        }
        button.hidden = text.scrollHeight <= text.clientHeight + 2;
      });
    }
    initializeCollapses();
    document.fonts?.ready.then(() => initializeCollapses());
    let resizeFrame = 0;
    window.addEventListener('resize', () => {
      cancelAnimationFrame(resizeFrame);
      resizeFrame = requestAnimationFrame(() => {
        initializeCollapses();
        updateProfileSpacing();
      });
    }, { passive: true });
    document.addEventListener('load', (event) => {
      if (event.target instanceof HTMLImageElement) {
        const post = event.target.closest('.wcf-post');
        if (post) initializeCollapses(post);
      }
    }, true);
    document.addEventListener('error', (event) => {
      if (!(event.target instanceof HTMLImageElement)) return;
      event.target.classList.add('is-broken');
      if (!event.target.alt) event.target.alt = '图片暂时无法加载';
    }, true);

    let searchItems = [];
    try {
      const data = JSON.parse(document.getElementById('wcf-search-data')?.textContent || '[]');
      if (Array.isArray(data)) searchItems = data;
    } catch (_) {}
    function safeUrl(value) {
      try {
        const url = new URL(String(value || ''), location.href);
        return ['http:', 'https:'].includes(url.protocol) ? url.href : '';
      } catch (_) { return ''; }
    }
    document.addEventListener('input', (event) => {
      if (!(event.target instanceof HTMLInputElement) || !event.target.matches('[data-wcf-search]')) return;
      const results = document.querySelector('[data-wcf-search-results]');
      if (!results) return;
      const query = event.target.value.trim().toLocaleLowerCase();
      results.replaceChildren();
      results.hidden = !query;
      if (!query) return;
      const matches = searchItems.filter((item) => (
        String(item.title || '') + ' ' + String(item.excerpt || '')
      ).toLocaleLowerCase().includes(query)).slice(0, 20);
      if (!matches.length) {
        const empty = document.createElement('p');
        empty.className = 'wcf-search-empty';
        empty.textContent = '没有找到相关动态';
        results.append(empty);
      }
      matches.forEach((item) => {
        const href = safeUrl(item.url);
        if (!href) return;
        const link = document.createElement('a');
        link.className = 'wcf-search-result';
        link.href = href;
        const title = document.createElement('strong');
        title.textContent = String(item.title || '未命名动态');
        const excerpt = document.createElement('span');
        excerpt.textContent = String(item.excerpt || '');
        link.append(title, excerpt);
        results.append(link);
      });
    });

    let lightbox = null;
    let lightboxItems = [];
    let lightboxIndex = 0;
    function createLightbox() {
      const dialog = document.createElement('dialog');
      dialog.className = 'wcf-lightbox';
      dialog.setAttribute('aria-label', '图片预览');
      const control = (className, label, symbol) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = className;
        button.setAttribute('aria-label', label);
        button.textContent = symbol;
        return button;
      };
      const close = control('wcf-lightbox-close', '关闭图片预览', '×');
      close.addEventListener('click', () => closeDialog(dialog));
      const prev = control('wcf-lightbox-prev', '上一张图片', '‹');
      prev.addEventListener('click', () => showLightboxImage(lightboxIndex - 1));
      const next = control('wcf-lightbox-next', '下一张图片', '›');
      next.addEventListener('click', () => showLightboxImage(lightboxIndex + 1));
      const image = document.createElement('img');
      image.className = 'wcf-lightbox-image';
      const caption = document.createElement('p');
      caption.className = 'wcf-lightbox-caption';
      caption.setAttribute('aria-live', 'polite');
      dialog.append(close, prev, image, next, caption);
      dialog.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
          event.preventDefault();
          showLightboxImage(lightboxIndex + (event.key === 'ArrowLeft' ? -1 : 1));
        }
      });
      body.append(dialog);
      return dialog;
    }
    function showLightboxImage(index) {
      if (!lightboxItems.length) return;
      lightboxIndex = (index + lightboxItems.length) % lightboxItems.length;
      const current = lightboxItems[lightboxIndex];
      const image = lightbox.querySelector('.wcf-lightbox-image');
      image.classList.remove('is-broken');
      image.src = current.href;
      image.alt = current.querySelector('img')?.alt || '动态图片';
      lightbox.querySelector('.wcf-lightbox-caption').textContent = `${lightboxIndex + 1} / ${lightboxItems.length}`;
      lightbox.querySelectorAll('.wcf-lightbox-prev, .wcf-lightbox-next').forEach((button) => {
        button.hidden = lightboxItems.length < 2;
      });
    }

    const commentForms = new WeakMap();
    function commentState(form) {
      if (!commentForms.has(form)) {
        const placeholder = document.createComment('wcf-comment-form');
        form.before(placeholder);
        commentForms.set(form, { placeholder, button: null });
      }
      return commentForms.get(form);
    }
    function replyTo(button) {
      const section = button.closest('.comments');
      const form = section?.querySelector('.comment-form');
      const item = button.closest('.comment-item');
      const id = button.dataset.commentId || '';
      if (!form || !item || !/^\d+$/.test(id)) return;
      const parentInput = form.querySelector('[data-comment-parent-id]');
      const name = form.querySelector('[data-comment-reply-name]');
      const state = form.querySelector('[data-comment-reply-state]');
      if (!parentInput || !name || !state) return;
      const saved = commentState(form);
      saved.button = button;
      parentInput.value = id;
      name.textContent = '@' + (button.dataset.commentAuthor || '');
      state.hidden = false;
      section.querySelectorAll('[data-comment-reply]').forEach((reply) => {
        reply.setAttribute('aria-pressed', reply === button ? 'true' : 'false');
      });
      const content = form.querySelector('[name="content"]');
      item.append(form);
      form.scrollIntoView({ behavior: reducedMotion.matches ? 'auto' : 'smooth', block: 'nearest' });
      content?.focus({ preventScroll: true });
    }
    function cancelReply(button) {
      const form = button.closest('.comment-form');
      if (!form) return;
      const section = form.closest('.comments');
      const saved = commentForms.get(form);
      const parent = form.querySelector('[data-comment-parent-id]');
      const name = form.querySelector('[data-comment-reply-name]');
      const state = form.querySelector('[data-comment-reply-state]');
      if (parent) parent.value = '';
      if (name) name.textContent = '';
      if (state) state.hidden = true;
      section?.querySelectorAll('[data-comment-reply]').forEach((reply) => reply.setAttribute('aria-pressed', 'false'));
      if (saved?.placeholder.isConnected) saved.placeholder.after(form);
      saved?.button?.focus();
      if (saved) saved.button = null;
    }
    document.querySelectorAll('.comment-form').forEach((form) => {
      commentState(form);
      const id = form.querySelector('[data-comment-parent-id]')?.value;
      const selected = [...(form.closest('.comments')?.querySelectorAll('[data-comment-reply]') || [])]
        .find((button) => button.dataset.commentId === id);
      if (selected) selected.closest('.comment-item')?.append(form);
    });

    document.addEventListener('click', (event) => {
      if (!(event.target instanceof Element)) return;
      const target = event.target;
      const open = target.closest('[data-wcf-dialog-open]');
      if (open) {
        event.preventDefault();
        openDialog(document.getElementById(open.dataset.wcfDialogOpen));
        return;
      }
      const close = target.closest('[data-wcf-dialog-close]');
      if (close) { closeDialog(close.closest('dialog')); return; }
      if (target instanceof HTMLDialogElement) { closeDialog(target); return; }
      if (target.closest('[data-wcf-theme-toggle]')) {
        const mode = root.dataset.wcfTheme === 'dark' ? 'light' : 'dark';
        storage.set('wcf-theme', mode);
        setTheme(mode);
        return;
      }
      const expand = target.closest('[data-wcf-expand]');
      if (expand) {
        const text = expand.closest('.wcf-post')?.querySelector('[data-wcf-collapse]');
        if (!text) return;
        const expanded = text.classList.toggle('is-expanded');
        expand.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        expand.textContent = expanded ? '收起' : '全文';
        return;
      }
      const imageLink = target.closest('[data-wcf-image]');
      if (imageLink instanceof HTMLAnchorElement && !event.ctrlKey && !event.metaKey && !event.shiftKey && !event.altKey) {
        const gallery = imageLink.closest('.wcf-gallery') || imageLink.closest('.post-content');
        if (!gallery) return;
        event.preventDefault();
        lightbox ||= createLightbox();
        lightboxItems = [...gallery.querySelectorAll('a[data-wcf-image]')].filter((link) => safeUrl(link.href));
        showLightboxImage(Math.max(0, lightboxItems.indexOf(imageLink)));
        openDialog(lightbox);
        return;
      }
      const actions = target.closest('[data-wcf-actions]');
      if (actions) {
        const menu = document.getElementById(actions.getAttribute('aria-controls') || '');
        if (!menu) return;
        const show = menu.hidden;
        closeActions();
        menu.hidden = !show;
        actions.setAttribute('aria-expanded', show ? 'true' : 'false');
        return;
      }
      if (!target.closest('.wcf-actions-menu')) closeActions();
      const reply = target.closest('[data-comment-reply]');
      if (reply) { event.preventDefault(); replyTo(reply); return; }
      const cancel = target.closest('[data-comment-reply-cancel]');
      if (cancel) { cancelReply(cancel); return; }
      if (target.closest('[data-wcf-top]')) {
        window.scrollTo({ top: 0, behavior: reducedMotion.matches ? 'auto' : 'smooth' });
      }
    });
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape') closeActions();
    });
    document.addEventListener('play', (event) => {
      if (!(event.target instanceof HTMLMediaElement)) return;
      document.querySelectorAll('.wcf-music audio').forEach((audio) => {
        if (audio !== event.target) audio.pause();
      });
    }, true);
    document.addEventListener('article-likes:updated', (event) => {
      const detail = event.detail;
      if (!detail || !Number.isSafeInteger(detail.postId) || detail.postId < 1
        || !Number.isSafeInteger(detail.count) || detail.count < 0 || typeof detail.liked !== 'boolean') return;
      document.querySelectorAll('article.wcf-post[data-post-id], article.wcf-detail-post[data-post-id]').forEach((post) => {
        if (post.dataset.postId !== String(detail.postId)) return;
        const main = post.querySelector('.wcf-post-main, .wcf-detail-main') || post;
        let reactions = main.querySelector('.wcf-reactions');
        let likes = reactions?.querySelector('.wcf-likes');
        if (detail.count === 0) {
          likes?.remove();
          if (reactions && !reactions.childElementCount) reactions.remove();
          return;
        }
        if (!reactions) {
          reactions = document.createElement('div');
          reactions.className = 'wcf-reactions';
          main.append(reactions);
        }
        if (!likes) {
          likes = document.createElement('div');
          likes.className = 'wcf-likes';
          const icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
          icon.setAttribute('viewBox', '0 0 24 24');
          icon.setAttribute('fill', 'none');
          icon.setAttribute('stroke', 'currentColor');
          icon.setAttribute('stroke-width', '1.8');
          icon.setAttribute('stroke-linecap', 'round');
          icon.setAttribute('stroke-linejoin', 'round');
          icon.setAttribute('aria-hidden', 'true');
          const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
          path.setAttribute('d', 'M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z');
          icon.append(path);
          likes.append(icon, document.createElement('span'));
          reactions.prepend(likes);
        }
        likes.querySelector('span').textContent = `${detail.count} 人赞过`;
        likes.setAttribute('aria-live', 'polite');
      });
    });

    const feed = document.querySelector('[data-wcf-feed]');
    const pagination = document.querySelector('[data-wcf-pagination]');
    if (!feed || !pagination || body.dataset.wcfPaginationMode !== 'load_more') return;
    pagination.hidden = true;
    let loading = false;
    const loaded = new Set([location.href]);
    const status = document.querySelector('[data-wcf-load-status]') || document.createElement('p');
    if (!status.isConnected) {
      status.className = 'wcf-load-status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      pagination.after(status);
    }
    const nextLink = () => pagination.querySelector('a[rel="next"]');
    function loadingState(busy) {
      loading = busy;
      feed.setAttribute('aria-busy', busy ? 'true' : 'false');
      document.querySelectorAll('[data-wcf-load-more]').forEach((button) => {
        button.disabled = busy;
        button.hidden = !nextLink();
      });
    }
    async function loadMore() {
      const next = nextLink();
      if (loading || !next) return;
      const url = new URL(next.href, location.href);
      if (url.origin !== location.origin || loaded.has(url.href)) return;
      loadingState(true);
      status.textContent = '正在加载…';
      const controller = new AbortController();
      const timeout = setTimeout(() => controller.abort(), 15000);
      try {
        const response = await fetch(url.href, { credentials: 'same-origin', signal: controller.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!response.ok) throw new Error('HTTP ' + response.status);
        const page = new DOMParser().parseFromString(await response.text(), 'text/html');
        const posts = [...page.querySelectorAll('[data-wcf-feed] .wcf-post')];
        if (!posts.length) throw new Error('Missing feed');
        const existingIds = new Set([...feed.querySelectorAll('[data-post-id]')].map((post) => post.dataset.postId));
        const added = [];
        posts.forEach((post) => {
          if (post.dataset.postId && existingIds.has(post.dataset.postId)) return;
          const imported = document.importNode(post, true);
          feed.append(imported);
          added.push(imported);
        });
        const newPagination = page.querySelector('[data-wcf-pagination]');
        pagination.replaceChildren(...(newPagination ? [...newPagination.childNodes].map((node) => document.importNode(node, true)) : []));
        loaded.add(url.href);
        status.textContent = nextLink() ? '' : '已经到底了';
        added.forEach((post) => initializeCollapses(post));
        document.dispatchEvent(new CustomEvent('wcf:posts-loaded', { detail: { posts: added } }));
      } catch (_) {
        status.textContent = '加载失败，请点击“加载更多”重试。';
      } finally {
        clearTimeout(timeout);
        loadingState(false);
      }
    }
    document.addEventListener('click', (event) => {
      if (event.target instanceof Element && event.target.closest('[data-wcf-load-more]')) {
        event.preventDefault();
        loadMore();
      }
    });
    loadingState(false);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
