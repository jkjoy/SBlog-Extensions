(() => {
  'use strict';

  const root = document.documentElement;
  const body = document.body;
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const systemTheme = window.matchMedia('(prefers-color-scheme: dark)');
  const themeButtons = document.querySelectorAll('[data-sunny-theme-toggle]');
  let savedTheme = null;
  try { savedTheme = localStorage.getItem('sunny-theme'); } catch (_) {}

  const setTheme = (mode) => {
    const dark = mode === 'dark';
    root.dataset.sunnyTheme = dark ? 'dark' : 'light';
    document.querySelector('#sunny-theme-color')?.setAttribute('content', dark ? '#292e38' : '#f7f7f7');
    themeButtons.forEach((button) => {
      const label = dark ? '切换日间模式' : '切换夜间模式';
      button.setAttribute('aria-pressed', String(dark));
      button.setAttribute('aria-label', label);
      button.title = label;
    });
  };
  setTheme(savedTheme === 'dark' || savedTheme === 'light' ? savedTheme : (systemTheme.matches ? 'dark' : 'light'));
  themeButtons.forEach((button) => button.addEventListener('click', () => {
    savedTheme = root.dataset.sunnyTheme === 'dark' ? 'light' : 'dark';
    setTheme(savedTheme);
    try { localStorage.setItem('sunny-theme', savedTheme); } catch (_) {}
  }));
  systemTheme.addEventListener('change', (event) => {
    if (savedTheme !== 'dark' && savedTheme !== 'light') setTheme(event.matches ? 'dark' : 'light');
  });

  document.querySelectorAll('.comments').forEach((comments) => {
    let data = {};
    try { data = JSON.parse(document.querySelector('#sunny-comments-data')?.textContent || '{}'); } catch (_) {}
    const records = new Map((Array.isArray(data.comments) ? data.comments : []).map((item) => [String(item.id), item]));
    const list = comments.querySelector(':scope > .comment-list');
    const items = new Map([...list?.children || []].filter((item) => item.matches('.comment-item')).map((item) => [item.id.replace(/^comment-/, ''), item]));
    items.forEach((item, id) => {
      const meta = item.querySelector(':scope > .comment-item__meta');
      const content = item.querySelector(':scope > .comment-item__body');
      if (!meta || !content) return;
      const message = document.createElement('div');
      message.className = 'sunny-comment-message';
      item.prepend(message);
      message.append(meta, content);
      item.classList.toggle('sunny-comment--owner', records.get(id)?.owner === true);
      const avatar = meta.querySelector('.comment-item__avatar');
      if (avatar) {
        const avatarWrap = document.createElement('span');
        avatarWrap.className = 'sunny-comment-avatar';
        meta.prepend(avatarWrap);
        avatarWrap.append(avatar);
        const reply = meta.querySelector('[data-comment-reply]');
        if (reply) avatarWrap.append(reply);
      }
      const identity = meta.querySelector('.comment-item__identity');
      const replyTarget = content.querySelector('.comment-item__reply-target');
      if (identity && replyTarget) identity.append(replyTarget);
      const info = document.createElement('div');
      info.className = 'sunny-comment-info';
      const time = meta.querySelector('.comment-item__time');
      if (time) info.append(time);
      // Keep plugin nodes and their event handlers while placing metadata below the bubble.
      meta.querySelectorAll('.comment-enhancer-meta').forEach((node) => info.append(node));
      meta.append(info);
    });
    items.forEach((item, id) => {
      const parentId = String(records.get(id)?.parent || '');
      const parent = items.get(parentId);
      if (!parent || parent === item) return;
      const ancestors = new Set([id]);
      let ancestorId = parentId;
      while (items.has(ancestorId)) {
        if (ancestors.has(ancestorId)) return;
        ancestors.add(ancestorId);
        ancestorId = String(records.get(ancestorId)?.parent || '');
      }
      let children = parent.querySelector(':scope > .sunny-comment-children');
      if (!children) {
        children = document.createElement('ol');
        children.className = 'sunny-comment-children';
        children.setAttribute('aria-label', '评论回复');
        parent.append(children);
      }
      children.append(item);
    });

    const form = comments.querySelector('.comment-form');
    const heading = comments.querySelector('.comments__head');
    if (heading && Number.isInteger(data.total)) {
      const count = heading.querySelector('.comments__count');
      if (count) count.textContent = data.total > items.size ? `最新 ${items.size} / 共 ${data.total} 条` : `共 ${data.total} 条`;
    }
    if (form) {
      comments.prepend(form);
      if (heading) {
        form.prepend(heading);
        form.setAttribute('aria-labelledby', 'comments-title');
      }
      const grid = form.querySelector('.comment-form__grid');
      if (grid) {
        const avatar = document.createElement('span');
        avatar.className = 'sunny-comment-form-avatar';
        avatar.setAttribute('aria-hidden', 'true');
        const icon = document.querySelector('.sunny-tools a .sunny-icon');
        if (icon) avatar.append(icon.cloneNode(true));
        if (typeof data.avatar === 'string' && data.avatar) {
          const image = document.createElement('img');
          image.alt = '';
          image.src = data.avatar;
          image.referrerPolicy = 'no-referrer';
          image.addEventListener('error', () => image.remove());
          avatar.append(image);
        }
        const fields = ['author_email', 'author_name', 'author_url'].map((name) => grid.querySelector(`[name="${name}"]`)?.closest('.comment-field')).filter(Boolean);
        grid.prepend(avatar, ...fields);
        fields.forEach((field) => {
          const input = field.querySelector('input');
          const label = field.querySelector('label');
          if (input && label) input.placeholder = label.textContent.trim();
        });
      }
      const textarea = form.querySelector('#comment-content');
      if (textarea) textarea.placeholder = '注意文明发言哦！';
      const replyText = form.querySelector('.comment-reply-state__text');
      if (replyText?.firstChild?.nodeType === Node.TEXT_NODE) replyText.firstChild.textContent = '回复 ';
    }
    comments.classList.add('sunny-comments-ready');
  });

  const nav = document.querySelector('#sunny-nav');
  const menuButton = document.querySelector('[data-sunny-menu]');
  const dropdowns = [...document.querySelectorAll('.sunny-nav-dropdown')];
  const closeDropdowns = (except) => dropdowns.forEach((item) => { if (item !== except) item.open = false; });
  const closeMenu = (restoreFocus = false) => {
    const wasOpen = body.classList.contains('sunny-menu-open');
    body.classList.remove('sunny-menu-open');
    menuButton?.setAttribute('aria-expanded', 'false');
    menuButton?.setAttribute('aria-label', '展开菜单');
    if (menuButton) menuButton.title = '展开菜单';
    closeDropdowns();
    if (wasOpen && restoreFocus) menuButton?.focus();
  };
  menuButton?.addEventListener('click', () => {
    if (body.classList.contains('sunny-menu-open')) {
      closeMenu(true);
      return;
    }
    body.classList.add('sunny-menu-open');
    menuButton.setAttribute('aria-expanded', 'true');
    menuButton.setAttribute('aria-label', '收起菜单');
    menuButton.title = '收起菜单';
    requestAnimationFrame(() => nav?.querySelector('a, summary')?.focus());
  });
  nav?.addEventListener('click', (event) => { if (event.target.closest('a')) closeMenu(); });
  dropdowns.forEach((item) => item.addEventListener('toggle', () => { if (item.open) closeDropdowns(item); }));
  document.addEventListener('click', (event) => {
    if (!event.target.closest('.sunny-navbar')) closeMenu();
    else if (!event.target.closest('.sunny-nav-dropdown')) closeDropdowns();
  });
  window.addEventListener('resize', () => {
    if (menuButton && getComputedStyle(menuButton).display === 'none') closeMenu();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      if (body.classList.contains('sunny-menu-open')) closeMenu(true);
      else closeDropdowns();
    }
    if (event.key !== 'Tab' || !body.classList.contains('sunny-menu-open')) return;
    const focusable = [menuButton, ...nav.querySelectorAll('a, summary')].filter((item) => item && item.getClientRects().length);
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
  });

  let progressPending = false;
  const updateProgress = () => {
    const maximum = Math.max(0, root.scrollHeight - window.innerHeight);
    const progress = maximum > 0 ? Math.min(100, Math.max(0, Math.round((window.scrollY / maximum) * 100))) : 0;
    body.classList.toggle('sunny-scrolled', window.scrollY > 420);
    document.querySelectorAll('[data-sunny-progress]').forEach((item) => { item.textContent = String(progress); });
    document.querySelectorAll('[data-sunny-top]').forEach((button) => button.style.setProperty('--sunny-progress', `${progress}%`));
    progressPending = false;
  };
  const scheduleProgress = () => { if (!progressPending) { progressPending = true; requestAnimationFrame(updateProgress); } };
  window.addEventListener('scroll', scheduleProgress, { passive: true });
  window.addEventListener('resize', scheduleProgress);
  window.addEventListener('load', scheduleProgress);
  if ('ResizeObserver' in window) new ResizeObserver(scheduleProgress).observe(body);
  updateProgress();
  document.querySelectorAll('[data-sunny-top]').forEach((button) => button.addEventListener('click', () => {
    window.scrollTo({ top: 0, behavior: reducedMotion.matches ? 'instant' : 'smooth' });
  }));

  const safeUrl = (value, sameOrigin = true) => {
    try {
      const url = new URL(value, location.href);
      if (!['http:', 'https:'].includes(url.protocol) || (sameOrigin && url.origin !== location.origin) || url.username || url.password) return null;
      return url;
    } catch (_) { return null; }
  };
  const modalState = new WeakMap();
  const openDialog = (dialog) => {
    if (!dialog || typeof dialog.showModal !== 'function' || dialog.open) return;
    closeMenu();
    modalState.set(dialog, document.activeElement);
    dialog.showModal();
    body.classList.add('sunny-dialog-open');
  };
  document.querySelectorAll('dialog').forEach((dialog) => {
    dialog.addEventListener('close', () => {
      if (!document.querySelector('dialog[open]')) body.classList.remove('sunny-dialog-open');
      const previous = modalState.get(dialog);
      if (previous?.isConnected) previous.focus({ preventScroll: true });
    });
    dialog.addEventListener('click', (event) => {
      if (event.target !== dialog) return;
      const bounds = dialog.getBoundingClientRect();
      if (dialog.classList.contains('sunny-lightbox') || event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) dialog.close();
    });
  });

  const searchDialog = document.querySelector('.sunny-search-dialog');
  const searchInput = document.querySelector('#sunny-search-input');
  const searchResults = document.querySelector('.sunny-search-results');
  const searchStatus = document.querySelector('.sunny-search-status');
  let searchItems = [];
  try {
    const data = JSON.parse(document.querySelector('#sunny-search-data')?.textContent || '[]');
    if (Array.isArray(data)) searchItems = data.slice(0, 2000).filter((item) => item && typeof item.title === 'string' && typeof item.url === 'string' && safeUrl(item.url));
  } catch (_) {}
  const normalize = (text) => text.normalize('NFKC').toLocaleLowerCase();
  const renderSearch = () => {
    if (!searchResults || !searchStatus || !searchInput) return;
    const keywords = normalize(searchInput.value.trim()).split(/\s+/).filter(Boolean);
    const matches = keywords.length ? searchItems.filter((item) => {
      const text = normalize(`${item.title} ${typeof item.excerpt === 'string' ? item.excerpt : ''}`);
      return keywords.every((word) => text.includes(word));
    }) : searchItems;
    searchResults.replaceChildren();
    searchStatus.textContent = keywords.length
      ? (matches.length ? `找到 ${matches.length} 篇文章${matches.length > 30 ? '，显示前 30 篇' : ''}` : '没有找到相关内容，换个关键词试试吧。')
      : (matches.length ? '最近发布的文章' : '还没有已发布的文章');
    const fragment = document.createDocumentFragment();
    matches.slice(0, keywords.length ? 30 : 8).forEach((item) => {
      const link = document.createElement('a');
      link.className = 'sunny-search-result';
      link.href = safeUrl(item.url).href;
      const title = document.createElement('strong');
      title.textContent = item.title;
      link.append(title);
      if (typeof item.excerpt === 'string' && item.excerpt.trim()) {
        const excerpt = document.createElement('span');
        excerpt.textContent = item.excerpt;
        link.append(excerpt);
      }
      fragment.append(link);
    });
    searchResults.append(fragment);
  };
  const openSearch = () => {
    renderSearch();
    openDialog(searchDialog);
    requestAnimationFrame(() => searchInput?.focus());
  };
  document.querySelectorAll('[data-sunny-search-open]').forEach((button) => button.addEventListener('click', openSearch));
  document.querySelector('[data-sunny-search-close]')?.addEventListener('click', () => searchDialog?.close());
  searchInput?.addEventListener('input', renderSearch);
  searchInput?.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && searchDialog?.open) {
      event.preventDefault();
      searchDialog.close();
    }
  });
  document.querySelector('.sunny-search-form')?.addEventListener('submit', (event) => event.preventDefault());
  document.addEventListener('keydown', (event) => {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
      event.preventDefault();
      if (searchDialog?.open) searchDialog.close();
      else if (!document.querySelector('dialog[open]')) openSearch();
    }
  });

  const lightbox = document.querySelector('.sunny-lightbox');
  const lightboxImage = lightbox?.querySelector('img');
  const showImage = (image) => {
    if (!lightboxImage) return;
    const url = safeUrl(image.currentSrc || image.src, false);
    if (!url) return;
    lightboxImage.src = url.href;
    lightboxImage.alt = image.alt || '文章图片';
    openDialog(lightbox);
  };
  document.querySelectorAll('.sunny-content .post-content img').forEach((image) => {
    if (image.closest('a, button')) return;
    image.classList.add('sunny-zoomable');
    image.tabIndex = 0;
    image.setAttribute('role', 'button');
    image.setAttribute('aria-label', `查看大图${image.alt ? `：${image.alt}` : ''}`);
    image.addEventListener('click', () => showImage(image));
    image.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); showImage(image); }
    });
  });
  document.querySelector('[data-sunny-lightbox-close]')?.addEventListener('click', () => lightbox?.close());
  lightbox?.addEventListener('close', () => lightboxImage?.removeAttribute('src'));

  const tocPanel = document.querySelector('.sunny-toc-panel');
  const toc = document.querySelector('.sunny-toc');
  const headings = [...document.querySelectorAll('.sunny-content .post-content h2, .sunny-content .post-content h3')];
  if (tocPanel && toc && headings.length >= 2) {
    const tocLinks = new Map();
    headings.forEach((heading, index) => {
      if (!heading.id) {
        let id = `sunny-heading-${index + 1}`;
        while (document.getElementById(id)) id += '-section';
        heading.id = id;
      }
      const link = document.createElement('a');
      link.href = `#${encodeURIComponent(heading.id)}`;
      link.textContent = heading.textContent;
      if (heading.tagName === 'H3') link.className = 'sunny-toc-sub';
      toc.append(link);
      tocLinks.set(heading, link);
    });
    const activateHeading = (heading) => tocLinks.forEach((link, item) => {
      link.classList.toggle('is-current', item === heading);
      if (item === heading) link.setAttribute('aria-current', 'location');
      else link.removeAttribute('aria-current');
    });
    activateHeading(headings[0]);
    tocPanel.hidden = false;
    if ('IntersectionObserver' in window) {
      const observer = new IntersectionObserver((entries) => {
        const past = headings.filter((heading) => heading.getBoundingClientRect().top <= window.innerHeight * 0.3);
        const current = past[past.length - 1] || entries.find((entry) => entry.isIntersecting)?.target || headings[0];
        activateHeading(current);
      }, { rootMargin: '0px 0px -65% 0px', threshold: 0 });
      headings.forEach((heading) => observer.observe(heading));
    }
  }

  const historyHost = document.querySelector('[data-sunny-history]');
  const historyKey = 'sunny-reading-history';
  const normalizeHistoryUrl = (value) => {
    const url = safeUrl(value);
    if (!url) return null;
    url.hash = '';
    [...url.searchParams.keys()].forEach((key) => { if (!['a', 'slug'].includes(key)) url.searchParams.delete(key); });
    return url.pathname + url.search;
  };
  let history = [];
  try {
    const stored = JSON.parse(localStorage.getItem(historyKey) || '[]');
    if (Array.isArray(stored)) {
      const seen = new Set();
      stored.slice(0, 20).forEach((item) => {
        if (!item || typeof item.title !== 'string' || typeof item.url !== 'string') return;
        const url = normalizeHistoryUrl(item.url);
        if (!url || seen.has(url)) return;
        seen.add(url);
        history.push({ title: item.title.trim().slice(0, 200), url });
      });
    }
  } catch (_) {}
  const articleTitle = document.querySelector('.sunny-content .post-detail .post-title');
  const currentUrl = normalizeHistoryUrl(location.href);
  if (articleTitle && currentUrl && !new URL(location.href).searchParams.has('preview')) {
    history = [{ title: articleTitle.textContent.trim().slice(0, 200), url: currentUrl }, ...history.filter((item) => item.url !== currentUrl)].slice(0, 5);
    try { localStorage.setItem(historyKey, JSON.stringify(history)); } catch (_) {}
  }
  if (historyHost && history.length) {
    history.slice(0, 5).forEach((item) => {
      const link = document.createElement('a');
      const url = safeUrl(item.url);
      if (new URL(location.href).searchParams.get('theme_preview') === 'sunny') url.searchParams.set('theme_preview', 'sunny');
      link.href = url.pathname + url.search;
      link.textContent = item.title || '无标题文章';
      link.className = 'sunny-history-link';
      historyHost.append(link);
    });
    const panel = historyHost.closest('.sunny-history-panel');
    if (panel) panel.hidden = false;
  }

  document.querySelectorAll('[data-sunny-water]').forEach((button) => {
    let resetTimer;
    button.addEventListener('click', () => {
      clearTimeout(resetTimer);
      button.classList.add('is-watered');
      const message = button.querySelector('.sunny-water-message');
      if (message) message.textContent = '谢谢你的浇水 +1';
      resetTimer = setTimeout(() => { button.classList.remove('is-watered'); if (message) message.textContent = ''; }, 2800);
    });
  });

  document.addEventListener('article-likes:updated', (event) => {
    const { postId, count } = event.detail || {};
    if (!Number.isSafeInteger(postId) || !Number.isSafeInteger(count) || count < 0) return;
    document.querySelectorAll('[data-sunny-like-total]').forEach((item) => {
      if (item.dataset.sunnyLikeTotal === String(postId)) item.textContent = String(count);
    });
  });

  const hideBrokenImage = (image) => {
    const cover = image.closest('.sunny-post-cover');
    if (cover) { cover.classList.add('sunny-post-cover--empty'); image.remove(); }
    else { image.hidden = true; image.parentElement?.classList.add('has-image-error'); }
  };
  document.querySelectorAll('.sunny-hero-avatar img, .sunny-post-avatar img, .sunny-post-cover img, .sunny-discover > img, .sunny-dock-home img').forEach((image) => {
    image.addEventListener('error', () => hideBrokenImage(image), { once: true });
    if (image.complete && image.naturalWidth === 0) hideBrokenImage(image);
  });
})();
