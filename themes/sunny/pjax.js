(() => {
  'use strict';

  if (window.sunnyPjaxReady || !document.body.dataset.sunnyPjax || !window.fetch || !window.AbortController) return;
  window.sunnyPjaxReady = true;

  const body = document.body;
  const version = body.dataset.sunnyPjax;
  const navigationScript = document.querySelector('script[data-sunny-runtime="navigation"][src]');
  const basePath = navigationScript ? new URL('../../', navigationScript.src).pathname : '/';
  const runtimes = new Map();
  const loaded = new Set();
  const inlineScripts = new Set();
  const persistentHeadScripts = new Set();
  const iconBindings = new WeakSet();
  const handledClicks = new WeakSet();
  const actions = new Set(['home', 'post', 'page', 'archives', 'tags', 'tag', 'categories', 'category', 'links', 'steam', 'douban', 'gallery', 'gallery_album', 'search']);
  let navigation = null;
  let committedURL = new URL(location.href);
  let scrollTimer;
  let committing = false;
  let queued = null;

  const dataScript = (script) => /^(?:application\/(?:ld\+)?json|text\/plain)$/i.test(script.type);
  const absolute = (value, base = location.href) => new URL(value, base).href;
  const headScriptKey = (script, base) => JSON.stringify([
    [...script.attributes]
      .filter((attr) => attr.name !== 'nonce')
      .map((attr) => [attr.name, attr.name === 'src' ? absolute(attr.value, base) : attr.value])
      .sort(([a], [b]) => a.localeCompare(b)),
    script.textContent.trim(),
  ]);
  const stateObject = () => history.state && typeof history.state === 'object' ? history.state : {};
  const saveScroll = () => {
    if (navigation || committing) return;
    history.replaceState({ ...stateObject(), sunnyPjax: { url: location.href, scroll: { x: scrollX, y: scrollY } } }, '', location.href);
  };
  const status = (message) => {
    const live = document.getElementById('sunny-pjax-status');
    if (live) live.textContent = message;
  };
  const pageURL = (value) => {
    let url;
    try { url = new URL(value, location.href); } catch (_) { return null; }
    if (!/^https?:$/.test(url.protocol) || url.origin !== location.origin || url.username || url.password) return null;
    if (!url.pathname.startsWith(basePath)) return null;
    if (/^(?:admin(?:_[^/]*)?|login|logout|write|edit|install(?:er)?\.php|update\.php|forgot-password|reset-password|api|rest-api|uploads|assets|themes|plugins|rss(?:\.xml)?|feed|sitemap(?:\.xml)?)(?:\/|$)/i.test(url.pathname.slice(basePath.length))) return null;
    if (/\.(?:xml|json|zip|pdf|png|jpe?g|gif|webp|avif|svg|mp[34]|ogg|wav|flac|woff2?|css|js|txt)$/i.test(url.pathname)) return null;
    const action = url.searchParams.get('a');
    return action && !actions.has(action) ? null : url;
  };
  const samePage = (a, b) => a.pathname === b.pathname && a.search === b.search;
  const pluginKind = (url) => {
    const path = new URL(url).pathname;
    if (/\/plugins\/article-likes\/assets\/article-likes\.js$/.test(path)) return 'once';
    if (/\/plugins\/comment-enhancer\/assets\/comment-enhancer\.js$/.test(path)) return 'comments';
    if (/\/plugins\/(?:steam-showcase|douban-showcase)\/assets\/script\.js$/.test(path)
      || /\/plugins\/gallery\/assets\/public\.js$/.test(path)) return 'page';
    return '';
  };
  // This tracker reads location only when it executes; it has no SPA listener.
  const visitTracker = (script, base) => Boolean(script.getAttribute('data-website-id')?.trim())
    && script.hasAttribute('src') && absolute(script.getAttribute('src'), base) === 'https://0tz.top/tracker.min.js';
  const unsupported = () => Object.assign(new Error('A full document navigation is required.'), { fullNavigation: true });

  const inspectScripts = (next, base) => {
    const scripts = [];
    for (const script of next.querySelectorAll('script')) {
      if (dataScript(script)) continue;
      // Shared Head code (for example analytics) already runs in this document.
      // Keep its original node and listeners instead of falling back to a reload.
      const analytics = visitTracker(script, base);
      const eachPage = script.dataset.sunnyPjax === 'page';
      if (!analytics && !eachPage && next.head.contains(script) && persistentHeadScripts.has(headScriptKey(script, base))) continue;
      if (script.src) {
        const url = absolute(script.getAttribute('src'), base);
        const runtime = script.dataset.sunnyRuntime;
        if (runtime) {
          if (!['core', 'page', 'steam', 'music', 'navigation'].includes(runtime)
            || (runtimes.has(runtime) && runtimes.get(runtime) !== url)) throw unsupported();
          scripts.push({ script, url, runtime, kind: 'once' });
        } else {
          const kind = analytics ? 'analytics' : (pluginKind(url) || (eachPage ? 'page' : ''));
          if (!kind) throw unsupported();
          scripts.push({ script, url, kind });
        }
      } else {
        const source = script.textContent.trim();
        if (!source || (script.hasAttribute('data-sunny-appearance') && inlineScripts.has(source))) continue;
        const messages = source.match(/^window\.sblogI18n=Object\.assign\(\{\},window\.sblogI18n\|\|\{\},([\s\S]*)\);$/);
        if (!messages) throw unsupported();
        const values = JSON.parse(messages[1]);
        if (!values || typeof values !== 'object' || (Array.isArray(values) && values.length !== 0)) throw unsupported();
        scripts.push({ messages: values });
      }
    }
    return scripts;
  };

  const loadScript = ({ script, url, runtime, transient = false }) => new Promise((resolve, reject) => {
    const element = document.createElement('script');
    for (const attr of script.attributes) {
      if (!['src', 'defer', 'async'].includes(attr.name)) element.setAttribute(attr.name, attr.value);
    }
    element.src = url;
    element.async = false;
    const timeout = setTimeout(() => { element.remove(); reject(new Error('Script load timed out.')); }, 15000);
    element.onload = () => { clearTimeout(timeout); loaded.add(url); if (runtime) runtimes.set(runtime, url); if (transient) element.remove(); resolve(); };
    element.onerror = () => { clearTimeout(timeout); element.remove(); reject(new Error('Script could not be loaded.')); };
    body.append(element);
  });

  const prepareStyles = async (next, base, signal) => {
    const selector = 'link[rel="stylesheet"], style';
    const key = (node, url) => JSON.stringify([
      node.tagName,
      [...node.attributes]
        .filter((attr) => attr.name !== 'nonce')
        .map((attr) => [attr.name, attr.name === 'href' ? absolute(attr.value, url) : attr.value])
        .sort(([a], [b]) => a.localeCompare(b)),
      node.textContent,
    ]);
    const incoming = [...next.head.querySelectorAll(selector)];
    const existing = [...document.head.querySelectorAll(selector)];
    const identities = existing.map((node) => key(node, location.href));
    const retained = new Set();
    const added = [];
    const pending = [];
    const cleanup = [];
    let before = existing.length;
    let anchor = null;
    try {
      // Keep shared CSS nodes connected: moving an already loaded <link> makes
      // browsers discard and reload its stylesheet, even within the same head.
      // Prepare additions in their final cascade order while the old page stays styled.
      for (const source of [...incoming].reverse()) {
        const identity = key(source, base);
        const index = identities.lastIndexOf(identity, before - 1);
        if (before > 0 && index !== -1) {
          anchor = existing[index];
          retained.add(anchor);
          before = index;
          continue;
        }
        const node = document.importNode(source, true);
        const media = source.getAttribute('media');
        node.media = 'not all';
        if (node.tagName === 'LINK') {
          node.href = absolute(source.getAttribute('href'), base);
          pending.push(new Promise((resolve, reject) => {
            const release = () => {
              clearTimeout(timeout);
              signal.removeEventListener('abort', cancel);
              node.onload = node.onerror = null;
            };
            const finish = (error) => { release(); error ? reject(error) : resolve(); };
            const cancel = () => finish(new DOMException('Aborted', 'AbortError'));
            const timeout = setTimeout(() => finish(new Error('Stylesheet load timed out.')), 15000);
            signal.addEventListener('abort', cancel, { once: true });
            node.onload = () => finish();
            node.onerror = () => finish(new Error('Stylesheet could not be loaded.'));
            cleanup.push(release);
            if (signal.aborted) cancel();
          }));
        }
        added.push({ node, media });
        document.head.insertBefore(node, anchor);
        anchor = node;
      }
      await Promise.all(pending);
      if (signal.aborted) throw new DOMException('Aborted', 'AbortError');
      return {
        commit() {
          for (const { node, media } of added) {
            if (media === null) node.removeAttribute('media');
            else node.setAttribute('media', media);
          }
          for (const node of existing) {
            const keepPlayerStyle = node.hasAttribute('data-sunny-player-style')
              && !incoming.some((item) => item.hasAttribute('data-sunny-player-style'));
            if (!retained.has(node) && !keepPlayerStyle) node.remove();
          }
        },
        rollback() { added.forEach(({ node }) => node.remove()); },
      };
    } catch (error) {
      cleanup.forEach((release) => release());
      added.forEach(({ node }) => node.remove());
      throw error;
    }
  };

  const syncHead = (next, base) => {
    document.title = next.title;
    const selector = 'meta[name]:not([name="viewport"]):not([name="theme-color"]):not([name="sunny-pjax-version"]), meta[property], link[rel="canonical"], link[rel="alternate"], script[type="application/ld+json"]';
    document.head.querySelectorAll(selector).forEach((node) => node.remove());
    next.head.querySelectorAll(selector).forEach((node) => {
      const copy = document.importNode(node, true);
      if (copy.tagName === 'LINK') copy.href = absolute(node.getAttribute('href'), base);
      document.head.append(copy);
    });
    document.documentElement.lang = next.documentElement.lang;
    document.documentElement.dir = next.documentElement.dir;
  };

  const enhanceCommentIcons = () => document.querySelectorAll('.comment-enhancer-meta__icon').forEach((icon) => {
    if (iconBindings.has(icon)) return;
    iconBindings.add(icon);
    const reset = () => { delete icon.dataset.tooltipDismissed; };
    icon.addEventListener('pointerleave', reset);
    icon.addEventListener('focusin', reset);
    icon.addEventListener('focusout', () => { if (!icon.matches(':hover')) reset(); });
  });

  const scrollToPage = (url, position) => {
    if (position) {
      window.scrollTo({ left: position.x || 0, top: position.y || 0, behavior: 'instant' });
      return;
    }
    let target;
    try { target = url.hash && document.getElementById(decodeURIComponent(url.hash.slice(1))); } catch (_) {}
    if (target) target.scrollIntoView({ block: 'start', behavior: 'instant' });
    else window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
  };

  const showError = (url) => {
    document.getElementById('sunny-pjax-error')?.remove();
    const notice = document.createElement('p');
    notice.id = 'sunny-pjax-error';
    notice.className = 'sunny-notice';
    notice.setAttribute('role', 'alert');
    notice.append('页面暂时无法打开，音乐会继续播放。');
    const retry = document.createElement('a');
    retry.href = url;
    retry.dataset.sunnyRetry = '';
    retry.textContent = '重试';
    notice.append(' ', retry);
    document.querySelector('.sunny-main')?.prepend(notice);
    status('页面暂时无法打开，请重试。');
  };

  async function navigate(value, { pop = false, position = null } = {}) {
    const requested = new URL(value, location.href);
    if (committing) { queued = { value: requested.href, options: { pop, position } }; return; }
    if (!pop) saveScroll();
    navigation?.abort();
    const controller = new AbortController();
    navigation = controller;
    let styles;
    let timedOut = false;
    let replaced = false;
    let initialized = false;
    const timeout = setTimeout(() => { timedOut = true; controller.abort(); }, 20000);
    body.classList.add('sunny-pjax-loading');
    body.setAttribute('aria-busy', 'true');
    document.getElementById('sunny-pjax-error')?.remove();
    status('正在打开页面…');
    try {
      const response = await fetch(requested.href, { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'text/html', 'X-Sunny-PJAX': '1' }, signal: controller.signal });
      if (!response.ok) throw new Error('Page request failed.');
      const destination = pageURL(response.url || requested.href);
      if (!destination || !response.headers.get('content-type')?.includes('text/html')) throw unsupported();
      const next = new DOMParser().parseFromString(await response.text(), 'text/html');
      if (!next.body.classList.contains('sunny-theme') || !next.querySelector('.sunny-main') || next.body.dataset.sunnyPjax !== version
        || next.querySelector('[data-paid-reading-status]')) {
        throw unsupported();
      }
      const scripts = inspectScripts(next, destination.href);
      styles = await prepareStyles(next, destination.href, controller.signal);
      if (controller.signal.aborted || navigation !== controller) return;
      committing = true;
      clearTimeout(timeout);
      // Let native dialogs release their scroll locks before removing their page.
      document.querySelectorAll('dialog[open]').forEach((dialog) => {
        if (!dialog.closest('#sunny-player-root') && typeof dialog.close === 'function') dialog.close();
      });
      await new Promise((resolve) => requestAnimationFrame(resolve));
      if (controller.signal.aborted || queued) return;
      document.dispatchEvent(new CustomEvent('sunny:before-replace'));
      const player = document.getElementById('sunny-player-root');
      const keep = new Set([player, ...document.querySelectorAll('body > .article-likes-status, body > [data-article-likes-status]')].filter(Boolean));
      const fragment = document.createDocumentFragment();
      for (const node of [...next.body.childNodes]) {
        if (player && node.nodeType === Node.ELEMENT_NODE && node.id === 'sunny-player-root') continue;
        if (keep.size > (player ? 1 : 0) && node.nodeType === Node.ELEMENT_NODE && node.matches('.article-likes-status, [data-article-likes-status]')) continue;
        fragment.append(document.importNode(node, true));
      }
      fragment.querySelectorAll('script').forEach((script) => { if (!dataScript(script)) script.remove(); });
      const activeMusic = body.classList.contains('sunny-music-playing');
      for (const node of [...body.childNodes]) { if (!keep.has(node)) node.remove(); }
      for (const attr of [...body.attributes]) body.removeAttribute(attr.name);
      for (const attr of next.body.attributes) body.setAttribute(attr.name, attr.value);
      if (activeMusic) body.classList.add('sunny-music-playing');
      body.classList.add('sunny-pjax-loading');
      body.setAttribute('aria-busy', 'true');
      destination.hash = requested.hash;
      if (!pop) history.pushState({ sunnyPjax: { url: destination.href, scroll: { x: 0, y: 0 } } }, '', destination.href);
      else if (destination.href !== location.href) history.replaceState({ ...stateObject(), sunnyPjax: { url: destination.href, scroll: position || { x: 0, y: 0 } } }, '', destination.href);
      body.insertBefore(fragment, player || null);
      replaced = true;
      committedURL = destination;
      styles.commit();
      syncHead(next, destination.href);
      for (const asset of scripts) {
        if (asset.messages) window.sblogI18n = { ...window.sblogI18n, ...asset.messages };
        else if (asset.runtime && !loaded.has(asset.url)) await loadScript(asset);
      }
      if (typeof window.initComments === 'function') window.initComments();
      document.dispatchEvent(new CustomEvent('sunny:page-load', { detail: { url: destination.href } }));
      initialized = true;
      for (const asset of scripts) {
        if (!asset.url || asset.runtime || asset.kind === 'analytics') continue;
        if (asset.kind === 'page' || !loaded.has(asset.url)) await loadScript(asset);
      }
      enhanceCommentIcons();
      bindCommentLinks();
      if (!queued) {
        scrollToPage(destination, pop ? position : null);
        document.querySelector('.sunny-main')?.focus({ preventScroll: true });
      }
      status(`已打开：${document.title}`);
      document.dispatchEvent(new CustomEvent('sunny:pjax-complete', { detail: { url: destination.href, pop } }));
      // Optional analytics must not delay a page or turn its failure into a page error.
      if (!queued) for (const asset of scripts) {
        if (asset.kind === 'analytics') void loadScript({ ...asset, transient: true }).catch(() => {});
      }
    } catch (error) {
      if (navigation !== controller || (controller.signal.aborted && !timedOut)) return;
      if (error.fullNavigation) location.assign(requested.href);
      else {
        if (replaced && !initialized) {
          if (typeof window.initComments === 'function') window.initComments();
          document.dispatchEvent(new CustomEvent('sunny:page-load', { detail: { url: committedURL.href } }));
          bindCommentLinks();
        }
        if (pop && !replaced) history.replaceState({ ...stateObject(), sunnyPjax: { url: committedURL.href, scroll: position || { x: 0, y: 0 } } }, '', committedURL.href);
        showError(requested.href);
      }
    } finally {
      clearTimeout(timeout);
      if (!replaced) styles?.rollback();
      if (navigation === controller) {
        navigation = null;
        committing = false;
        body.classList.remove('sunny-pjax-loading');
        body.removeAttribute('aria-busy');
        if (queued) {
          const pending = queued;
          queued = null;
          void navigate(pending.value, pending.options);
        } else saveScroll();
      }
    }
  }

  function clickLink(event) {
    if (handledClicks.has(event) || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.altKey || event.shiftKey) return;
    const anchor = event.target instanceof Element ? event.target.closest('a[href]') : null;
    if (!anchor || anchor.hasAttribute('download') || (anchor.target && anchor.target.toLowerCase() !== '_self')
      || anchor.closest('[data-no-pjax], [data-pjax="false"], [no-pjax], #sunny-player-root')
      || anchor.rel.split(/\s+/).includes('external')) return;
    const url = pageURL(anchor.href);
    if (!url || (samePage(url, new URL(location.href)) && url.hash)) return;
    handledClicks.add(event);
    event.preventDefault();
    if (samePage(url, committedURL) && !navigation && !committing && !anchor.hasAttribute('data-sunny-retry')) {
      window.scrollTo({ top: 0, left: 0, behavior: 'instant' });
      return;
    }
    void navigate(url.href);
  }
  function bindCommentLinks() {
    // Core comment replies stop bubbling at .comments; preserve normal links there too.
    document.querySelectorAll('.comments').forEach((comments) => comments.addEventListener('click', clickLink));
  }

  const initialize = () => {
    if (!pageURL(location.href) || document.querySelector('[data-paid-reading-status]')) return;
    document.querySelectorAll('script').forEach((script) => {
      if (dataScript(script)) return;
      if (document.head.contains(script) && !script.dataset.sunnyRuntime) persistentHeadScripts.add(headScriptKey(script, location.href));
      if (script.src) {
        loaded.add(script.src);
        if (script.dataset.sunnyRuntime) runtimes.set(script.dataset.sunnyRuntime, script.src);
      } else if (script.hasAttribute('data-sunny-appearance')) inlineScripts.add(script.textContent.trim());
    });
    document.querySelectorAll('.comment-enhancer-meta__icon').forEach((icon) => iconBindings.add(icon));
    history.scrollRestoration = 'manual';
    saveScroll();
    document.addEventListener('click', clickLink);
    bindCommentLinks();
    window.addEventListener('scroll', () => { clearTimeout(scrollTimer); scrollTimer = setTimeout(saveScroll, 150); }, { passive: true });
    window.addEventListener('pagehide', saveScroll);
    window.addEventListener('hashchange', () => {
      if (!navigation && !committing) { committedURL = new URL(location.href); saveScroll(); }
    });
    window.addEventListener('popstate', (event) => {
      const url = new URL(location.href);
      if (samePage(url, committedURL) && !navigation && !committing) {
        committedURL = url;
        scrollToPage(url, event.state?.sunnyPjax?.scroll || null);
      } else void navigate(url.href, { pop: true, position: event.state?.sunnyPjax?.scroll || { x: 0, y: 0 } });
    });
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
  else initialize();
})();
