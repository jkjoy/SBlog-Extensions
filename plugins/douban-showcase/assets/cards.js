(() => {
  'use strict';
  if (window.sblogDoubanCardsReady) return;
  window.sblogDoubanCardsReady = true;

  const selector = 'a.sblog-douban-card[data-douban-subject]';
  const labels = { movie: '电影', book: '图书', music: '音乐' };
  const discovered = new WeakSet();
  const requests = new Map();
  const queue = [];
  let active = 0;

  function textValue(value, limit = 2000) {
    if (Array.isArray(value)) return value.map((item) => textValue(item, limit)).filter(Boolean).join(' / ').slice(0, limit);
    return typeof value === 'string' || typeof value === 'number' ? String(value).trim().slice(0, limit) : '';
  }

  function localUrl(value) {
    if (typeof value !== 'string' || !value.trim()) return null;
    try {
      const url = new URL(value, location.href);
      return ['http:', 'https:'].includes(url.protocol) && url.origin === location.origin && !url.username && !url.password ? url : null;
    } catch (_) { return null; }
  }

  function slot(card, name, parent = card) {
    let element = card.querySelector(`.sblog-douban-card__${name}`);
    if (!element) {
      element = document.createElement('span');
      element.className = `sblog-douban-card__${name}`;
      parent.append(element);
    }
    return element;
  }

  function setText(card, name, value, parent) {
    const element = slot(card, name, parent);
    element.textContent = value;
    element.hidden = !value;
    return element;
  }

  function fallback(card) {
    card.dataset.subjectState = 'error';
    card.setAttribute('aria-busy', 'false');
    const body = slot(card, 'body');
    setText(card, 'footer', '前往豆瓣查看', body);
    card.querySelectorAll('[data-douban-loading]').forEach((element) => { element.hidden = true; });
    document.dispatchEvent(new CustomEvent('sblog:douban-card-updated', { detail: { card } }));
  }

  function render(card, subject, type) {
    const label = labels[type];
    const title = textValue(subject.title);
    const body = slot(card, 'body');
    const year = textValue(subject.year, 30);
    setText(card, 'eyebrow', `豆瓣${label}${year ? ` · ${year}` : ''}`, body);
    setText(card, 'title', title, body);
    const original = textValue(subject.original_title);
    setText(card, 'original', original !== title ? original : '', body);
    const rating = Number(subject.rating);
    const hasRating = Number.isFinite(rating) && rating > 0 && rating <= 10;
    const ratingElement = slot(card, 'rating', body);
    ratingElement.replaceChildren();
    if (hasRating) {
      const star = document.createElement('span');
      star.className = 'sblog-douban-card__star';
      star.setAttribute('aria-hidden', 'true');
      star.textContent = '★';
      const score = document.createElement('span');
      score.className = 'sblog-douban-card__score';
      score.textContent = rating.toFixed(1);
      ratingElement.append(star, score);
      const count = Number(subject.rating_count);
      if (Number.isSafeInteger(count) && count > 0) {
        const countElement = document.createElement('span');
        countElement.className = 'sblog-douban-card__count';
        countElement.textContent = `${count.toLocaleString('zh-CN')} 人评价`;
        ratingElement.append(countElement);
      }
      ratingElement.setAttribute('aria-label', `豆瓣评分 ${rating.toFixed(1)}，满分 10 分`);
    } else {
      ratingElement.textContent = '暂无评分';
      ratingElement.removeAttribute('aria-label');
    }
    ratingElement.hidden = false;
    setText(card, 'meta', textValue(subject.meta), body);
    setText(card, 'summary', textValue(subject.summary, 4000), body);
    setText(card, 'footer', '前往豆瓣查看', body);

    const cover = slot(card, 'cover');
    const coverUrl = localUrl(subject.cover_url);
    const existingImage = cover.querySelector('img');
    existingImage?.remove();
    card.dataset.subjectCover = 'missing';
    if (coverUrl) {
      const image = document.createElement('img');
      image.alt = '';
      image.loading = 'lazy';
      image.decoding = 'async';
      image.referrerPolicy = 'no-referrer';
      image.addEventListener('load', () => {
        card.dataset.subjectCover = 'ready';
      }, { once: true });
      image.addEventListener('error', () => {
        image.hidden = true;
        card.dataset.subjectCover = 'error';
      }, { once: true });
      image.src = coverUrl.href;
      cover.append(image);
    }
    card.setAttribute('aria-label', `在豆瓣查看《${title}》`);
    card.setAttribute('aria-busy', 'false');
    card.dataset.subjectState = 'ready';
    card.querySelectorAll('[data-douban-loading]').forEach((element) => { element.hidden = true; });
    document.dispatchEvent(new CustomEvent('sblog:douban-card-updated', { detail: { card } }));
  }

  function pump() {
    while (active < 3 && queue.length) {
      const task = queue.shift();
      active++;
      task().finally(() => {
        active--;
        pump();
      });
    }
  }

  function requestSubject(endpoint, type, id) {
    const key = `${type}:${id}`;
    if (requests.has(key)) return requests.get(key);
    const promise = new Promise((resolve, reject) => {
      queue.push(async () => {
        const controller = new AbortController();
        const timeout = setTimeout(() => controller.abort(), 12000);
        try {
          const url = new URL(endpoint.href);
          url.hash = '';
          url.searchParams.set('type', type);
          url.searchParams.set('id', id);
          const response = await fetch(url.href, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
          });
          if (!response.ok) throw new Error('Subject request failed');
          const result = await response.json();
          const subject = result?.subject;
          if (!result?.ok || !subject || subject.type !== type || String(subject.id) !== id || !textValue(subject.title)) {
            throw new Error('Invalid subject data');
          }
          resolve(subject);
        } catch (error) {
          reject(error);
        } finally {
          clearTimeout(timeout);
        }
      });
      pump();
    });
    requests.set(key, promise);
    return promise;
  }

  async function hydrate(card) {
    const type = card.dataset.subjectType;
    const id = card.dataset.subjectId || '';
    const endpoint = localUrl(card.dataset.subjectEndpoint);
    if (!Object.hasOwn(labels, type) || !/^[1-9]\d{0,17}$/.test(id)
      || !endpoint || endpoint.searchParams.get('a') !== 'douban_showcase_subject') {
      fallback(card);
      return;
    }
    card.dataset.subjectState = 'loading';
    card.setAttribute('aria-busy', 'true');
    try {
      const subject = await requestSubject(endpoint, type, id);
      render(card, subject, type);
    } catch (_) {
      fallback(card);
    }
  }

  const observer = 'IntersectionObserver' in window ? new IntersectionObserver((entries) => {
    entries.forEach((entry) => {
      if (!entry.isIntersecting) return;
      observer.unobserve(entry.target);
      hydrate(entry.target);
    });
  }, { rootMargin: '200px' }) : null;

  function register(card) {
    if (discovered.has(card)) return;
    discovered.add(card);
    card.dataset.subjectState = 'pending';
    if (observer) observer.observe(card);
    else hydrate(card);
  }

  function scan(scope) {
    if (!(scope instanceof Element) && !(scope instanceof Document)) return;
    if (scope instanceof Element && scope.matches(selector)) register(scope);
    scope.querySelectorAll(selector).forEach(register);
  }

  function initialize() {
    scan(document);
    if ('MutationObserver' in window && document.body) {
      new MutationObserver((records) => {
        records.forEach((record) => record.addedNodes.forEach(scan));
      }).observe(document.body, { childList: true, subtree: true });
    }
    document.addEventListener('wcf:posts-loaded', (event) => {
      const posts = event.detail?.posts;
      if (Array.isArray(posts)) posts.forEach(scan);
      else scan(document);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
  else initialize();
})();
