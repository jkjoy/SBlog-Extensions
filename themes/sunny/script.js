(() => {
  'use strict';

  if (window.sunnyThemeScriptReady) return;
  window.sunnyThemeScriptReady = true;

  let disposePage = () => {};
  const initialize = () => {
    disposePage();
    if (!document.body.classList.contains('sunny-theme')) return;

    const controller = new AbortController();
    const { signal } = controller;
    const observers = [];
    const frames = new Set();
    const timers = new Set();
    const cleanups = [];
    const listen = (target, event, handler, options = {}) => target?.addEventListener(event, handler, { ...options, signal });
    const frame = (callback) => {
      if (signal.aborted) return 0;
      const id = requestAnimationFrame((time) => { frames.delete(id); if (!signal.aborted) callback(time); });
      frames.add(id);
      return id;
    };
    const later = (callback, delay) => {
      const id = setTimeout(() => { timers.delete(id); if (!signal.aborted) callback(); }, delay);
      timers.add(id);
      return id;
    };
    const cancelLater = (id) => { clearTimeout(id); timers.delete(id); };
    const root = document.documentElement;
    const body = document.body;
    const pageDialogs = [...document.querySelectorAll('dialog')].filter((dialog) => !dialog.closest('#sunny-player-root'));
    disposePage = () => {
      controller.abort();
      observers.forEach((observer) => observer.disconnect());
      frames.forEach((id) => cancelAnimationFrame(id));
      timers.forEach((id) => clearTimeout(id));
      cleanups.forEach((cleanup) => cleanup());
      pageDialogs.forEach((dialog) => { if (dialog.open) dialog.close(); });
      body.querySelector('.sunny-lightbox img')?.removeAttribute('src');
      body.classList.remove('sunny-menu-open', 'sunny-dialog-open');
      disposePage = () => {};
    };
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
    themeButtons.forEach((button) => listen(button, 'click', () => {
      savedTheme = root.dataset.sunnyTheme === 'dark' ? 'light' : 'dark';
      setTheme(savedTheme);
      try { localStorage.setItem('sunny-theme', savedTheme); } catch (_) {}
    }));
    listen(systemTheme, 'change', (event) => {
      if (savedTheme !== 'dark' && savedTheme !== 'light') setTheme(event.matches ? 'dark' : 'light');
    });

    document.querySelectorAll('.comments').forEach((comments) => {
      if (comments.classList.contains('sunny-comments-ready')) return;
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
    document.querySelectorAll('.sunny-comment-form-avatar img').forEach((image) => listen(image, 'error', () => image.remove()));

    const nav = document.querySelector('#sunny-nav');
    const menuButton = document.querySelector('[data-sunny-menu]');
    const dropdowns = [...document.querySelectorAll('.sunny-nav-dropdown')];
    const closeDropdowns = (except) => dropdowns.forEach((item) => {
      if (item !== except && !item.contains(except) && !except?.contains(item)) item.open = false;
    });
    const positionDropdowns = () => {
      nav?.querySelectorAll(':scope > .sunny-nav-dropdown').forEach((item) => {
        const panel = item.querySelector(':scope > .sunny-dropdown-panel');
        if (!panel) return;
        panel.style.left = '';
        if (!item.open || getComputedStyle(panel).position !== 'absolute') return;
        const bounds = panel.getBoundingClientRect();
        const shift = Math.min(0, window.innerWidth - 16 - bounds.right);
        panel.style.left = `${Math.max(16 - bounds.left, shift)}px`;
      });
    };
    const closeMenu = (restoreFocus = false) => {
      const wasOpen = body.classList.contains('sunny-menu-open');
      body.classList.remove('sunny-menu-open');
      menuButton?.setAttribute('aria-expanded', 'false');
      menuButton?.setAttribute('aria-label', '展开菜单');
      if (menuButton) menuButton.title = '展开菜单';
      closeDropdowns();
      if (wasOpen && restoreFocus) menuButton?.focus();
    };
    listen(menuButton, 'click', () => {
      if (body.classList.contains('sunny-menu-open')) {
        closeMenu(true);
        return;
      }
      body.classList.add('sunny-menu-open');
      menuButton.setAttribute('aria-expanded', 'true');
      menuButton.setAttribute('aria-label', '收起菜单');
      menuButton.title = '收起菜单';
      frame(() => nav?.querySelector('a, summary')?.focus());
    });
    listen(nav, 'click', (event) => { if (event.target.closest('a')) closeMenu(); });
    dropdowns.forEach((item) => listen(item, 'toggle', () => {
      if (item.open) closeDropdowns(item);
      positionDropdowns();
    }));
    listen(document, 'click', (event) => {
      if (!event.target.closest('.sunny-navbar')) closeMenu();
      else if (!event.target.closest('.sunny-nav-dropdown')) closeDropdowns();
    });
    listen(window, 'resize', () => {
      if (body.classList.contains('sunny-menu-open') && menuButton && getComputedStyle(menuButton).display === 'none') closeMenu();
      positionDropdowns();
    });
    listen(document, 'keydown', (event) => {
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
    const scheduleProgress = () => { if (!progressPending) { progressPending = true; frame(updateProgress); } };
    listen(window, 'scroll', scheduleProgress, { passive: true });
    listen(window, 'resize', scheduleProgress);
    listen(window, 'load', scheduleProgress);
    if ('ResizeObserver' in window) {
      const observer = new ResizeObserver(scheduleProgress);
      observer.observe(body);
      observers.push(observer);
    }
    updateProgress();
    document.querySelectorAll('[data-sunny-top]').forEach((button) => listen(button, 'click', () => {
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
    pageDialogs.forEach((dialog) => {
      listen(dialog, 'close', () => {
        if (!document.querySelector('dialog[open]')) body.classList.remove('sunny-dialog-open');
        const previous = modalState.get(dialog);
        if (previous?.isConnected) previous.focus({ preventScroll: true });
      });
      listen(dialog, 'click', (event) => {
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
      frame(() => searchInput?.focus());
    };
    document.querySelectorAll('[data-sunny-search-open]').forEach((button) => listen(button, 'click', openSearch));
    listen(document.querySelector('[data-sunny-search-close]'), 'click', () => searchDialog?.close());
    listen(searchInput, 'input', renderSearch);
    listen(searchInput, 'keydown', (event) => {
      if (event.key === 'Escape' && searchDialog?.open) {
        event.preventDefault();
        searchDialog.close();
      }
    });
    listen(document.querySelector('.sunny-search-form'), 'submit', (event) => event.preventDefault());
    listen(document, 'keydown', (event) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        if (searchDialog?.open) searchDialog.close();
        else if (!document.querySelector('dialog[open]')) openSearch();
      }
    });

    const lightbox = document.querySelector('.sunny-lightbox');
    const lightboxImage = lightbox?.querySelector('img');
    const lightboxCounter = lightbox?.querySelector('.sunny-lightbox-counter');
    const lightboxStatus = lightbox?.querySelector('.sunny-lightbox-status');
    const lightboxOriginal = lightbox?.querySelector('[data-sunny-lightbox-original]');
    const lightboxPrevious = lightbox?.querySelector('[data-sunny-lightbox-prev]');
    const lightboxNext = lightbox?.querySelector('[data-sunny-lightbox-next]');
    const canPreview = lightboxImage && typeof lightbox?.showModal === 'function';
    let lightboxItems = [];
    let lightboxIndex = 0;
    const renderLightbox = () => {
      const item = lightboxItems[lightboxIndex];
      if (!item || !lightboxImage) return;
      lightboxImage.hidden = false;
      if (lightboxStatus) lightboxStatus.hidden = true;
      lightboxImage.alt = item.alt || '文章图片';
      lightboxImage.src = item.url;
      if (lightboxOriginal) lightboxOriginal.href = item.url;
      if (lightboxCounter) lightboxCounter.textContent = `${lightboxIndex + 1} / ${lightboxItems.length}`;
      [lightboxPrevious, lightboxNext].forEach((button) => { if (button) button.hidden = lightboxItems.length < 2; });
    };
    const showImages = (items, index, trigger) => {
      if (!canPreview || !items.length) return;
      lightboxItems = items;
      lightboxIndex = Math.max(0, index);
      renderLightbox();
      openDialog(lightbox);
      modalState.set(lightbox, trigger);
    };
    const moveImage = (direction) => {
      if (!lightbox?.open || lightboxItems.length < 2) return;
      lightboxIndex = (lightboxIndex + direction + lightboxItems.length) % lightboxItems.length;
      renderLightbox();
    };

    document.querySelectorAll('[data-sunny-gallery]').forEach((gallery) => {
      const links = [...gallery.querySelectorAll('[data-sunny-gallery-image]')];
      const refreshGallery = () => {
        const available = links.filter((link) => !link.dataset.sunnyImageFailed);
        const visible = available.filter((link) => !link.hidden);
        gallery.hidden = !visible.length;
        gallery.dataset.count = String(visible.length);
        gallery.querySelectorAll('.sunny-post-gallery-more').forEach((badge) => badge.remove());
        available.forEach((link, index) => link.setAttribute('aria-label', `查看第 ${index + 1} 张图片，共 ${available.length} 张`));
        if (available.length > visible.length && visible.length) {
          const badge = document.createElement('span');
          badge.className = 'sunny-post-gallery-more';
          badge.setAttribute('aria-hidden', 'true');
          badge.textContent = `+${available.length - visible.length}`;
          visible[visible.length - 1].append(badge);
        }
      };
      links.forEach((link) => {
        const image = link.querySelector('img');
        if (image) {
          const failed = () => {
            link.dataset.sunnyImageFailed = 'true';
            link.hidden = true;
            refreshGallery();
          };
          listen(image, 'error', failed, { once: true });
          if (image.complete && image.naturalWidth === 0) failed();
        }
        listen(link, 'click', (event) => {
          if (!canPreview || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
          const items = links.filter((item) => !item.dataset.sunnyImageFailed).map((item) => ({
            link: item,
            url: safeUrl(item.href, false)?.href,
            alt: item.querySelector('img')?.alt || '文章图片',
          })).filter((item) => item.url);
          const index = items.findIndex((item) => item.link === link);
          if (index < 0) return;
          event.preventDefault();
          showImages(items, index, link);
        });
      });
    });

    document.querySelectorAll('.sunny-content .post-content img').forEach((image) => {
      if (!canPreview || image.closest('a, button')) return;
      image.classList.add('sunny-zoomable');
      image.tabIndex = 0;
      image.setAttribute('role', 'button');
      image.setAttribute('aria-label', `查看大图${image.alt ? `：${image.alt}` : ''}`);
      const showImage = () => {
        const url = safeUrl(image.currentSrc || image.src, false);
        if (url) showImages([{ url: url.href, alt: image.alt }], 0, image);
      };
      listen(image, 'click', showImage);
      listen(image, 'keydown', (event) => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); showImage(); }
      });
    });
    listen(document.querySelector('[data-sunny-lightbox-close]'), 'click', () => lightbox?.close());
    listen(lightboxPrevious, 'click', () => moveImage(-1));
    listen(lightboxNext, 'click', () => moveImage(1));
    listen(lightbox, 'keydown', (event) => {
      if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
        event.preventDefault();
        moveImage(event.key === 'ArrowLeft' ? -1 : 1);
      }
    });
    listen(lightboxImage, 'error', () => {
      if (!lightbox?.open || !lightboxImage.hasAttribute('src')) return;
      lightboxImage.hidden = true;
      if (lightboxStatus) lightboxStatus.hidden = false;
    });
    let swipeStart = null;
    listen(lightboxImage, 'pointerdown', (event) => {
      if (event.pointerType === 'touch') swipeStart = { x: event.clientX, y: event.clientY, id: event.pointerId };
    }, { passive: true });
    listen(lightboxImage, 'pointerup', (event) => {
      if (!swipeStart || swipeStart.id !== event.pointerId) return;
      const dx = event.clientX - swipeStart.x;
      const dy = event.clientY - swipeStart.y;
      swipeStart = null;
      if (Math.abs(dx) >= 48 && Math.abs(dx) > Math.abs(dy) * 1.2) moveImage(dx < 0 ? 1 : -1);
    }, { passive: true });
    listen(lightboxImage, 'pointercancel', () => { swipeStart = null; });
    listen(lightbox, 'close', () => {
      lightboxImage?.removeAttribute('src');
      lightboxItems = [];
      swipeStart = null;
    });

    const tocPanel = document.querySelector('.sunny-toc-panel');
    const toc = document.querySelector('.sunny-toc');
    toc?.replaceChildren();
    if (tocPanel) tocPanel.hidden = true;
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
          if (signal.aborted) return;
          const past = headings.filter((heading) => heading.getBoundingClientRect().top <= window.innerHeight * 0.3);
          const current = past[past.length - 1] || entries.find((entry) => entry.isIntersecting)?.target || headings[0];
          activateHeading(current);
        }, { rootMargin: '0px 0px -65% 0px', threshold: 0 });
        headings.forEach((heading) => observer.observe(heading));
        observers.push(observer);
      }
    }

    const historyHost = document.querySelector('[data-sunny-history]');
    historyHost?.replaceChildren();
    const historyPanel = historyHost?.closest('.sunny-history-panel');
    if (historyPanel) historyPanel.hidden = true;
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
      cleanups.push(() => {
        button.classList.remove('is-watered');
        const message = button.querySelector('.sunny-water-message');
        if (message) message.textContent = '';
      });
      listen(button, 'click', () => {
        cancelLater(resetTimer);
        button.classList.add('is-watered');
        const message = button.querySelector('.sunny-water-message');
        if (message) message.textContent = '谢谢你的浇水 +1';
        resetTimer = later(() => { button.classList.remove('is-watered'); if (message) message.textContent = ''; }, 2800);
      });
    });

    listen(document, 'article-likes:updated', (event) => {
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
      listen(image, 'error', () => hideBrokenImage(image), { once: true });
      if (image.complete && image.naturalWidth === 0) hideBrokenImage(image);
    });
  };

  document.addEventListener('sunny:before-replace', () => disposePage());
  document.addEventListener('sunny:page-load', initialize);
  initialize();
})();
