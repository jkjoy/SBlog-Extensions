/* H-Siren interaction layer. No external runtime or PJAX dependency. */
(() => {
  'use strict';
  const body = document.body;
  if (!body.classList.contains('h-siren')) return;
  document.querySelectorAll('img[data-siren-fallback]').forEach(image => {
    const fallback = () => {
      if (!image.dataset.sirenFallback) return;
      const url = image.dataset.sirenFallback;
      delete image.dataset.sirenFallback;
      image.src = url;
    };
    image.addEventListener('error', fallback, { once: true });
    if (image.complete && image.naturalWidth === 0) fallback();
  });
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const header = document.querySelector('.site-header');
  const backTop = document.querySelector('.siren-back-top');
  let scrollPending = false;
  const updateScroll = () => {
    header?.classList.toggle('is-scrolled', window.scrollY > 20);
    backTop?.classList.toggle('is-visible', window.scrollY > 400);
    scrollPending = false;
  };
  window.addEventListener('scroll', () => {
    if (!scrollPending) {
      scrollPending = true;
      requestAnimationFrame(updateScroll);
    }
  }, { passive: true });
  updateScroll();
  backTop?.addEventListener('click', event => {
    event.preventDefault();
    window.scrollTo({ top: 0, behavior: reducedMotion.matches ? 'instant' : 'smooth' });
  });
  document.querySelector('.siren-scroll')?.addEventListener('click', event => {
    const target = document.getElementById('content');
    if (!target) return;
    event.preventDefault();
    target.scrollIntoView({ behavior: reducedMotion.matches ? 'instant' : 'smooth' });
  });

  const menuButton = document.querySelector('.siren-menu-toggle');
  const navigation = document.getElementById('siren-navigation');
  const setMenu = open => {
    navigation?.classList.toggle('is-open', open);
    menuButton?.setAttribute('aria-expanded', String(open));
  };
  menuButton?.addEventListener('click', () => setMenu(menuButton.getAttribute('aria-expanded') !== 'true'));
  navigation?.addEventListener('click', event => {
    if (event.target.closest('a')) setMenu(false);
  });
  document.addEventListener('click', event => {
    if (navigation?.classList.contains('is-open') && !navigation.contains(event.target) && !menuButton?.contains(event.target)) setMenu(false);
  });
  window.matchMedia('(min-width: 761px)').addEventListener('change', event => { if (event.matches) setMenu(false); });

  let activeOverlay = null;
  let previousFocus = null;
  let previousOverflow = '';
  const focusable = overlay => Array.from(overlay.querySelectorAll('a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),textarea:not([disabled]),select:not([disabled]),[tabindex="0"]')).filter(element => element.getClientRects().length);
  const openOverlay = overlay => {
    if (activeOverlay) closeOverlay();
    previousFocus = document.activeElement;
    previousOverflow = body.style.overflow;
    activeOverlay = overlay;
    overlay.hidden = false;
    body.style.overflow = 'hidden';
    if (overlay.matches('[data-siren-search]')) document.querySelectorAll('[data-siren-search-toggle]').forEach(button => button.setAttribute('aria-expanded', 'true'));
    (overlay.querySelector('input[type="search"]') || focusable(overlay)[0] || overlay).focus();
  };
  const closeOverlay = () => {
    if (!activeOverlay) return;
    if (activeOverlay.matches('[data-siren-search]')) document.querySelectorAll('[data-siren-search-toggle]').forEach(button => button.setAttribute('aria-expanded', 'false'));
    activeOverlay.hidden = true;
    activeOverlay = null;
    body.style.overflow = previousOverflow;
    if (previousFocus instanceof HTMLElement && previousFocus.isConnected) previousFocus.focus({ preventScroll: true });
    previousFocus = null;
  };
  const search = document.querySelector('[data-siren-search]');
  document.querySelectorAll('[data-siren-search-toggle]').forEach(button => button.addEventListener('click', () => {
    if (!search) return;
    setMenu(false);
    openOverlay(search);
  }));
  search?.querySelectorAll('[data-siren-search-close],.siren-search-close').forEach(button => button.addEventListener('click', closeOverlay));
  search?.addEventListener('click', event => { if (event.target === search) closeOverlay(); });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      if (activeOverlay) { event.preventDefault(); closeOverlay(); }
      if (navigation?.classList.contains('is-open')) { setMenu(false); menuButton?.focus(); }
    }
    if (event.key !== 'Tab' || !activeOverlay) return;
    const items = focusable(activeOverlay);
    if (!items.length) { event.preventDefault(); return; }
    const first = items[0];
    const last = items[items.length - 1];
    if (event.shiftKey && (document.activeElement === first || !activeOverlay.contains(document.activeElement))) {
      event.preventDefault(); last.focus();
    } else if (!event.shiftKey && (document.activeElement === last || !activeOverlay.contains(document.activeElement))) {
      event.preventDefault(); first.focus();
    }
  });

  if (body.dataset.lightbox === '1') {
    let lightbox;
    document.querySelectorAll('.post-content img').forEach(source => {
      source.addEventListener('click', event => {
        if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = source.closest('a');
        // Keep linked destinations that are not the image itself usable.
        if (link && link.href !== source.currentSrc && link.href !== source.src && !/\.(?:jpe?g|png|gif|webp|avif)(?:[?#]|$)/i.test(link.href)) return;
        event.preventDefault();
        if (!lightbox) {
          lightbox = document.createElement('div');
          lightbox.className = 'siren-lightbox';
          lightbox.setAttribute('role', 'dialog');
          lightbox.setAttribute('aria-modal', 'true');
          lightbox.setAttribute('aria-label', '图片预览');
          lightbox.hidden = true;
          const image = document.createElement('img');
          const close = document.createElement('button');
          close.type = 'button';
          close.textContent = '×';
          close.setAttribute('aria-label', '关闭图片预览');
          close.addEventListener('click', closeOverlay);
          lightbox.append(image, close);
          lightbox.addEventListener('click', click => { if (click.target === lightbox) closeOverlay(); });
          body.append(lightbox);
        }
        const image = lightbox.querySelector('img');
        image.src = source.currentSrc || source.src;
        image.alt = source.alt;
        openOverlay(lightbox);
      });
    });
  }

  document.querySelectorAll('[data-siren-start-date]').forEach(element => {
    const value = element.dataset.sirenStartDate;
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value || '')) return;
    const start = Date.parse(`${value}T00:00:00`);
    if (!Number.isFinite(start) || start > Date.now()) return;
    const days = Math.floor((Date.now() - start) / 86400000);
    element.textContent = `本站已运行 ${days.toLocaleString()} 天`;
  });

  if (body.dataset.clickEffects === '1') {
    document.addEventListener('click', event => {
      if (reducedMotion.matches || activeOverlay || event.target.closest('input,textarea,select,button,a') || event.detail === 0) return;
      for (let index = 0; index < 7; index++) {
        const spark = document.createElement('span');
        spark.className = 'siren-spark';
        spark.style.left = `${event.clientX}px`;
        spark.style.top = `${event.clientY}px`;
        body.append(spark);
        const angle = (index / 7) * Math.PI * 2;
        const distance = 25 + Math.random() * 35;
        const animation = spark.animate([
          { transform: 'translate(-50%,-50%) scale(1)', opacity: .9 },
          { transform: `translate(${Math.cos(angle) * distance}px,${Math.sin(angle) * distance}px) scale(0)`, opacity: 0 }
        ], { duration: 450 + Math.random() * 200, easing: 'cubic-bezier(.1,.7,.2,1)' });
        animation.addEventListener('finish', () => spark.remove(), { once: true });
      }
    });
  }
})();
