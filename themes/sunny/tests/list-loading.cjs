'use strict';

// Run: NODE_PATH=/path/to/node_modules node themes/sunny/tests/list-loading.cjs
// Uses the shipped runtime and inert HTML; all fetches and media are simulated.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const { JSDOM, VirtualConsole } = require('jsdom');
const runtime = fs.readFileSync(path.join(__dirname, '../script.js'), 'utf8');
const version = JSON.parse(fs.readFileSync(path.join(__dirname, '../theme.json'), 'utf8')).version;
const cases = [];
const test = (name, run) => cases.push([name, run]);
const tick = () => new Promise((resolve) => setTimeout(resolve, 0));
const until = async (condition) => {
  for (let index = 0; index < 100; index++) { if (condition()) return; await tick(); }
  throw new Error('The expected list state was not reached.');
};
const route = (key, page, plain = false) => {
  if (plain) return `/blog/index.php?${key === 'home' ? '' : `a=${key.split(':')[0]}&slug=${key.split(':')[1]}&`}p=${page}&theme_preview=sunny`;
  if (key === 'home') return `${page === 1 ? '/blog/' : `/blog/page/${page}`}?theme_preview=sunny`;
  const [type, slug] = key.split(':');
  return `/blog/${type}/${slug}?p=${page}&theme_preview=sunny`;
};
const card = (id, { gallery = false, script = false } = {}) => `<article class="sunny-post-card" data-sunny-post-id="${id}"><h2><a href="/blog/archive/article-${id}">Article ${id}</a></h2><span class="sunny-post-avatar"><img src="/avatar-${id}.png" alt=""></span>${gallery ? `<div data-sunny-gallery class="sunny-post-gallery" data-count="2"><a data-sunny-gallery-image href="/large-${id}-1.jpg"><img src="/thumb-${id}-1.jpg" alt="Image ${id}"></a><a data-sunny-gallery-image href="/large-${id}-2.jpg"><img src="/thumb-${id}-2.jpg" alt="Other ${id}"></a></div>` : ''}${script ? '<script>window.badResponseScript=true;</script>' : ''}</article>`;
const list = ({ key = 'home', page = 1, mode = 'button', ids = [1, 2], next = page + 1, plain = false, gallery = false, scripts = false } = {}) => `<div class="sunny-post-list" data-sunny-list data-list-key="${key}" data-load-mode="${mode}" data-page="${page}"><div class="sunny-post-grid">${ids.map((id) => card(id, { gallery, script: scripts })).join('')}</div>${next ? `<nav class="sunny-pagination"><a class="sunny-page-next" rel="next" href="${route(key, next, plain)}">下一页</a>${scripts ? '<script>window.badPagerScript=true;</script>' : ''}</nav>` : ''}${mode !== 'pagination' ? `<div class="sunny-load-more" data-sunny-load-controls hidden>${next ? `<a class="sunny-load-more-button" data-sunny-load-more data-no-pjax href="${route(key, next, plain)}">加载更多</a>` : ''}<p data-sunny-load-status role="status">${next ? '' : '已全部加载'}</p></div>` : ''}</div>`;
const html = (options = {}) => `<!doctype html><html><head><title>Sunny list fixture</title><meta name="theme-color" id="sunny-theme-color"></head><body class="sunny-theme" data-sunny-pjax="${options.version || version}"><main class="sunny-main sunny-content">${list(options)}</main><dialog class="sunny-lightbox"><button data-sunny-lightbox-close>Close</button><button data-sunny-lightbox-prev>Previous</button><img alt=""><span class="sunny-lightbox-counter"></span><p class="sunny-lightbox-status" hidden><a data-sunny-lightbox-original>Original</a></p><button data-sunny-lightbox-next>Next</button></dialog><div id="sunny-player-root"><audio src="https://example.invalid/music.mp3"></audio><dialog id="sunny-music-dialog" open>Music</dialog></div></body></html>`;
const response = (options = {}, extra = {}) => ({ ok: true, url: `https://sunny.test${route(options.key || 'home', options.page || 2, options.plain)}`, headers: { get: () => 'text/html; charset=utf-8' }, text: async () => html(options), ...extra });
const setup = (options = {}) => {
  const errors = [];
  const console = new VirtualConsole();
  console.on('jsdomError', (error) => errors.push(error));
  const dom = new JSDOM(options.raw || html(options), { url: `https://sunny.test${route(options.key || 'home', options.page || 1, options.plain)}`, runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: console });
  const w = dom.window;
  const doc = w.document;
  const fetches = [];
  const replies = [];
  const observers = [];
  const timeoutCallbacks = new Map();
  let mediaCalls = 0;
  let lifecycle = 0;
  const root = doc.querySelector('#sunny-player-root');
  const audio = doc.querySelector('audio');
  audio.currentTime = 37;
  for (const method of ['pause', 'play', 'load']) audio[method] = () => { mediaCalls++; return Promise.resolve(); };
  w.HTMLDialogElement.prototype.showModal = function () { this.open = true; };
  w.HTMLDialogElement.prototype.close = function () { this.open = false; this.dispatchEvent(new w.Event('close')); };
  w.scrollTo = () => { throw new Error('Loading more must not change scrolling.'); };
  w.matchMedia = () => Object.assign(new w.EventTarget(), { matches: false });
  Object.defineProperty(w.HTMLImageElement.prototype, 'complete', { configurable: true, get: () => false });
  w.fetch = async (url, init) => {
    fetches.push({ url, init });
    const item = replies.shift();
    if (!item) throw new Error('No local response queued.');
    return typeof item === 'function' ? item(url, init) : item;
  };
  if (options.noFetch) w.fetch = undefined;
  if (!options.noObserver) w.IntersectionObserver = class {
    constructor(callback, settings) { this.callback = callback; this.settings = settings; this.targets = new Set(); this.disconnected = false; observers.push(this); }
    observe(target) { this.targets.add(target); }
    unobserve(target) { this.targets.delete(target); }
    disconnect() { this.targets.clear(); this.disconnected = true; }
    trigger(target = [...this.targets][0]) { this.callback([{ target, isIntersecting: true }]); }
  };
  const setTimeout = w.setTimeout.bind(w);
  const clearTimeout = w.clearTimeout.bind(w);
  w.setTimeout = (fn, ms) => {
    if (ms === 20000) { const id = Math.random(); timeoutCallbacks.set(id, fn); return id; }
    return setTimeout(fn, ms);
  };
  w.clearTimeout = (id) => { timeoutCallbacks.delete(id); clearTimeout(id); };
  doc.addEventListener('sunny:page-load', () => lifecycle++);
  w.eval(runtime);
  const click = (selector = '[data-sunny-load-more]') => {
    const element = doc.querySelector(selector);
    assert.ok(element, `Missing ${selector}`);
    const event = new w.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
    element.dispatchEvent(event);
    return event;
  };
  const ids = () => [...doc.querySelectorAll('[data-sunny-list] > .sunny-post-grid > article')].map((item) => item.dataset.sunnyPostId);
  return { dom, w, doc, root, audio, fetches, replies, observers, errors, timeoutCallbacks, click, ids, lifecycle: () => lifecycle, mediaCalls: () => mediaCalls, close: () => dom.window.close() };
};

test('pagination and missing fetch retain normal page links', async () => {
  for (const options of [{ mode: 'pagination' }, { noFetch: true }]) {
    const t = setup(options);
    try { assert.equal(t.doc.querySelector('.sunny-pagination').hidden, false); assert.equal(t.fetches.length, 0); if (options.noFetch) assert.equal(t.doc.querySelector('[data-sunny-load-controls]').hidden, true); }
    finally { t.close(); }
  }
});

test('button appends the next page once without changing location, audio or lifecycle', async () => {
  const t = setup();
  try {
    const initialURL = t.w.location.href;
    assert.equal(t.doc.querySelector('.sunny-pagination').hidden, true);
    assert.equal(t.doc.querySelector('[data-sunny-load-controls]').hidden, false);
    let resolve;
    t.replies.push(() => new Promise((done) => { resolve = done; }));
    t.click(); t.click(); assert.equal(t.fetches.length, 1); assert.equal(t.doc.querySelector('[data-sunny-list]').getAttribute('aria-busy'), 'true');
    resolve(response({ page: 2, ids: [3, 4], scripts: true }));
    await until(() => t.ids().length === 4);
    assert.deepEqual(t.ids(), ['1', '2', '3', '4']);
    assert.equal(t.doc.querySelector('[data-sunny-list]').dataset.page, '2');
    assert.equal(t.doc.querySelector('[data-sunny-load-more]').href, 'https://sunny.test/blog/page/3?theme_preview=sunny');
    assert.equal(t.w.location.href, initialURL); assert.equal(t.audio.currentTime, 37); assert.equal(t.doc.querySelector('audio'), t.audio); assert.equal(t.doc.querySelector('#sunny-player-root'), t.root); assert.equal(t.doc.querySelector('#sunny-music-dialog').open, true);
    assert.equal(t.mediaCalls(), 0); assert.equal(t.lifecycle(), 0); assert.equal(t.doc.querySelector('[data-sunny-list] script'), null); assert.equal(t.w.badResponseScript, undefined); assert.equal(t.w.badPagerScript, undefined);
    assert.equal(t.fetches[0].init.credentials, 'same-origin'); assert.equal(t.fetches[0].init.headers['X-Sunny-List'], '1');
  } finally { t.close(); }
});

test('category, tag and plain home routes retain their exact filter and preview', async () => {
  for (const [key, plain] of [['category:life', false], ['tag:php', false], ['category:life', true], ['home', true]]) {
    const t = setup({ key, plain });
    try { t.replies.push(response({ key, plain, page: 2, ids: [3], next: null })); t.click(); await until(() => t.ids().length === 3); assert.equal(t.fetches[0].url, `https://sunny.test${route(key, 2, plain)}`); assert.equal(t.doc.querySelector('[data-sunny-load-status]').textContent, '已全部加载'); }
    finally { t.close(); }
  }
});

test('network failure permits a manual retry for the same page', async () => {
  const t = setup();
  try {
    t.replies.push(() => Promise.reject(new Error('network'))); t.click(); await until(() => t.doc.querySelector('[data-sunny-load-more]').textContent === '重试加载'); assert.deepEqual(t.ids(), ['1', '2']);
    t.replies.push(response({ page: 2, ids: [3], next: null })); t.click(); await until(() => t.ids().length === 3); assert.equal(t.fetches.length, 2); assert.equal(t.fetches[0].url, t.fetches[1].url); assert.equal(t.doc.querySelector('[data-sunny-load-more]').hidden, true);
  } finally { t.close(); }
});

test('twenty-second timeout aborts even a non-cooperating fetch and permits retry', async () => {
  const t = setup();
  try {
    let resolve;
    t.replies.push(() => new Promise((done) => { resolve = done; })); t.click();
    for (const fn of [...t.timeoutCallbacks.values()]) fn();
    await until(() => t.doc.querySelector('[data-sunny-load-more]').textContent === '重试加载'); assert.equal(t.fetches[0].init.signal.aborted, true);
    t.replies.push(response({ page: 2, ids: [3], next: null })); t.click(); await until(() => t.ids().length === 3);
    resolve(response({ page: 2, ids: [99], next: null })); await tick(); assert.deepEqual(t.ids(), ['1', '2', '3']);
  } finally { t.close(); }
});

for (const [name, options, extra] of [
  ['wrong version', { page: 2, ids: [3], version: '99.0.0' }],
  ['cross-filter key', { key: 'category:elsewhere', page: 2, ids: [3] }, { url: 'https://sunny.test/blog/page/2?theme_preview=sunny' }],
  ['non-continuous page', { page: 4, ids: [3] }, { url: 'https://sunny.test/blog/page/2?theme_preview=sunny' }],
  ['unsafe next page', { page: 2, ids: [3], next: 8 }],
  ['cross-origin redirect', { page: 2, ids: [3] }, { url: 'https://elsewhere.invalid/blog/page/2?theme_preview=sunny' }],
  ['wrong content type', { page: 2, ids: [3] }, { headers: { get: () => 'application/json' } }],
  ['empty response cards', { page: 2, ids: [] }],
]) test(`${name} is rejected without appending articles`, async () => {
  const t = setup();
  try { t.replies.push(response(options, extra)); t.click(); await until(() => t.doc.querySelector('[data-sunny-load-more]').textContent === '重试加载'); assert.deepEqual(t.ids(), ['1', '2']); assert.equal(t.doc.querySelector('[data-sunny-list]').dataset.page, '1'); }
  finally { t.close(); }
});

test('deduplicated and duplicate-only pages still advance exactly once', async () => {
  const t = setup();
  try {
    t.replies.push(response({ page: 2, ids: [2, 3, 3] })); t.click(); await until(() => t.ids().length === 3); assert.deepEqual(t.ids(), ['1', '2', '3']);
    t.replies.push(response({ page: 3, ids: [3], next: 4 })); t.click(); await until(() => t.doc.querySelector('[data-sunny-list]').dataset.page === '3'); assert.deepEqual(t.ids(), ['1', '2', '3']);
    t.replies.push(response({ page: 4, ids: [4], next: null })); t.click(); await until(() => t.ids().length === 4); assert.equal(t.fetches.length, 3);
  } finally { t.close(); }
});

test('automatic mode pauses on errors until an explicit retry and stops at the end', async () => {
  const t = setup({ mode: 'auto' });
  try {
    const observer = t.observers[0]; assert.equal(observer.settings.rootMargin, '0px 0px 320px 0px');
    t.replies.push(() => Promise.reject(new Error('offline'))); observer.trigger(); await until(() => t.doc.querySelector('[data-sunny-load-more]').textContent === '重试加载');
    observer.trigger(t.doc.querySelector('[data-sunny-load-controls]')); await tick(); assert.equal(t.fetches.length, 1);
    t.replies.push(response({ mode: 'auto', page: 2, ids: [3], next: null })); t.click(); await until(() => t.ids().length === 3); assert.equal(observer.targets.size, 0);
    observer.trigger(t.doc.querySelector('[data-sunny-load-controls]')); await tick(); assert.equal(t.fetches.length, 2); assert.equal(t.doc.querySelector('[data-sunny-load-status]').textContent, '已全部加载');
  } finally { t.close(); }
});

test('automatic mode degrades to the button without IntersectionObserver', async () => {
  const t = setup({ mode: 'auto', noObserver: true });
  try { t.replies.push(response({ mode: 'auto', page: 2, ids: [3], next: null })); t.click(); await until(() => t.ids().length === 3); assert.equal(t.observers.length, 0); }
  finally { t.close(); }
});

test('navigation cancels the active request and ignores its late response', async () => {
  const t = setup();
  try {
    let resolve; t.replies.push(() => new Promise((done) => { resolve = done; })); t.click();
    t.doc.dispatchEvent(new t.w.CustomEvent('sunny:before-replace')); assert.equal(t.fetches[0].init.signal.aborted, true);
    t.doc.querySelector('main').innerHTML = '<article class="post-detail">Detail page</article>'; t.w.history.pushState({}, '', '/blog/archive/new'); t.doc.dispatchEvent(new t.w.CustomEvent('sunny:page-load'));
    resolve(response({ page: 2, ids: [99] })); await tick(); await tick(); assert.equal(t.doc.querySelector('[data-sunny-post-id="99"]'), null); assert.equal(t.doc.querySelector('main').textContent, 'Detail page'); assert.equal(t.mediaCalls(), 0);
  } finally { t.close(); }
});

test('new article galleries support lightbox and failed images without reinitializing the page', async () => {
  const t = setup();
  try {
    t.replies.push(response({ page: 2, ids: [3], gallery: true, next: null })); t.click(); await until(() => t.ids().length === 3);
    const gallery = t.doc.querySelector('[data-sunny-post-id="3"] [data-sunny-gallery]'); const link = gallery.querySelector('[data-sunny-gallery-image]');
    const click = new t.w.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }); link.dispatchEvent(click); assert.equal(click.defaultPrevented, true); assert.equal(t.doc.querySelector('.sunny-lightbox').open, true); assert.match(t.doc.querySelector('.sunny-lightbox img').src, /large-3-1.jpg$/); assert.equal(t.doc.querySelector('.sunny-lightbox-counter').textContent, '1 / 2');
    t.click('[data-sunny-lightbox-next]'); assert.match(t.doc.querySelector('.sunny-lightbox img').src, /large-3-2.jpg$/);
    const image = link.querySelector('img'); image.dispatchEvent(new t.w.Event('error')); assert.equal(link.hidden, true); assert.equal(gallery.dataset.count, '1');
    const avatar = t.doc.querySelector('[data-sunny-post-id="3"] .sunny-post-avatar img'); avatar.dispatchEvent(new t.w.Event('error')); assert.equal(avatar.hidden, true); assert.equal(avatar.parentElement.classList.contains('has-image-error'), true); assert.equal(t.lifecycle(), 0); assert.equal(t.mediaCalls(), 0);
  } finally { t.close(); }
});

test('returning from detail restores loaded cards in memory and keeps the next page', async () => {
  const t = setup();
  try {
    const firstURL = t.w.location.href;
    t.replies.push(response({ page: 2, ids: [3], gallery: true })); t.click(); await until(() => t.ids().length === 3);
    t.doc.dispatchEvent(new t.w.CustomEvent('sunny:before-replace')); t.doc.querySelector('main').innerHTML = '<article>Detail</article>'; t.w.history.pushState({}, '', '/blog/archive/detail'); t.doc.dispatchEvent(new t.w.CustomEvent('sunny:page-load'));
    t.doc.dispatchEvent(new t.w.CustomEvent('sunny:before-replace')); t.doc.querySelector('main').innerHTML = list(); t.w.history.pushState({}, '', firstURL); t.doc.dispatchEvent(new t.w.CustomEvent('sunny:page-load'));
    assert.deepEqual(t.ids(), ['1', '2', '3']); assert.equal(t.doc.querySelector('[data-sunny-list]').dataset.page, '2');
    t.replies.push(response({ page: 3, ids: [4], next: null })); t.click(); await until(() => t.ids().length === 4); assert.match(t.fetches[1].url, /\/page\/3\?/);
    const stored = [...Array(t.w.localStorage.length)].map((_, index) => t.w.localStorage.getItem(t.w.localStorage.key(index))).join(''); assert.equal(stored.includes('sunny-post-card'), false); assert.equal(t.mediaCalls(), 0);
  } finally { t.close(); }
});

test('list snapshots do not preserve a temporarily disabled like button', async () => {
  const t = setup();
  try {
    const firstURL = t.w.location.href;
    t.replies.push(response({ page: 2, ids: [3] })); t.click(); await until(() => t.ids().length === 3);
    const button = t.doc.createElement('button'); button.dataset.articleLike = ''; button.disabled = true; button.setAttribute('aria-busy', 'true');
    t.doc.querySelector('[data-sunny-post-id="3"]').append(button);
    t.doc.dispatchEvent(new t.w.CustomEvent('sunny:before-replace')); t.doc.querySelector('main').innerHTML = '<article>Detail</article>'; t.w.history.pushState({}, '', '/blog/archive/detail'); t.doc.dispatchEvent(new t.w.CustomEvent('sunny:page-load'));
    t.doc.dispatchEvent(new t.w.CustomEvent('sunny:before-replace')); t.doc.querySelector('main').innerHTML = list(); t.w.history.pushState({}, '', firstURL); t.doc.dispatchEvent(new t.w.CustomEvent('sunny:page-load'));
    const restored = t.doc.querySelector('[data-article-like]'); assert.ok(restored); assert.equal(restored.disabled, false); assert.equal(restored.hasAttribute('aria-busy'), false);
  } finally { t.close(); }
});

test('changed first-page contents invalidate an older list snapshot', async () => {
  const t = setup();
  try {
    t.replies.push(response({ page: 2, ids: [3] })); t.click(); await until(() => t.ids().length === 3);
    t.doc.dispatchEvent(new t.w.CustomEvent('sunny:before-replace')); t.doc.querySelector('main').innerHTML = list({ ids: [9, 1] }); t.doc.dispatchEvent(new t.w.CustomEvent('sunny:page-load'));
    assert.deepEqual(t.ids(), ['9', '1']); assert.equal(t.doc.querySelector('[data-sunny-list]').dataset.page, '1');
  } finally { t.close(); }
});

test('memory snapshots are limited to five listing URLs', async () => {
  const t = setup();
  try {
    const firstURL = t.w.location.href;
    t.replies.push(response({ page: 2, ids: [3] })); t.click(); await until(() => t.ids().length === 3);
    for (let index = 0; index < 5; index++) {
      const key = `category:section-${index}`;
      t.doc.dispatchEvent(new t.w.CustomEvent('sunny:before-replace')); t.doc.querySelector('main').innerHTML = list({ key }); t.w.history.pushState({}, '', route(key, 1)); t.doc.dispatchEvent(new t.w.CustomEvent('sunny:page-load'));
    }
    t.doc.dispatchEvent(new t.w.CustomEvent('sunny:before-replace')); t.doc.querySelector('main').innerHTML = list(); t.w.history.pushState({}, '', firstURL); t.doc.dispatchEvent(new t.w.CustomEvent('sunny:page-load'));
    assert.deepEqual(t.ids(), ['1', '2']); assert.equal(t.fetches.length, 1);
  } finally { t.close(); }
});

test('a last page needs no request and keeps its terminal message', async () => {
  const t = setup({ page: 3, next: null });
  try { assert.equal(t.doc.querySelector('[data-sunny-load-controls]').hidden, false); assert.equal(t.doc.querySelector('[data-sunny-load-status]').textContent, '已全部加载'); assert.equal(t.fetches.length, 0); }
  finally { t.close(); }
});

test('the shipped PHP list renderer and JavaScript agree across all real fixture pages', async () => {
  const fixture = path.join(__dirname, 'list-loading.php');
  const php = 'define("SUNNY_LIST_FIXTURE_ONLY", true); require $argv[1]; $GLOBALS["list_settings"]["posts_per_page"]="2"; $GLOBALS["list_theme_settings"]["list_load_mode"]="button"; $GLOBALS["list_admin"]=true; $_GET=["theme_preview"=>"sunny"]; $pages=[]; for($page=1;$page<=4;$page++) { $pages[]=list_render("home","",$page); } echo json_encode($pages, JSON_THROW_ON_ERROR);';
  const pages = JSON.parse(execFileSync('php', ['-r', php, fixture], { encoding: 'utf8' }));
  const raw = (markup) => html().replace(list(), markup);
  const t = setup({ raw: raw(pages[0]) });
  try {
    assert.deepEqual(t.ids(), ['5', '1']);
    for (let page = 2; page <= 4; page++) {
      t.replies.push(response({ page }, { text: async () => raw(pages[page - 1]) }));
      t.click(); await until(() => t.doc.querySelector('[data-sunny-list]').dataset.page === String(page));
    }
    assert.deepEqual(t.ids(), ['5', '1', '2', '3', '4', '6', '7']);
    assert.equal(t.doc.querySelector('[data-sunny-load-status]').textContent, '已全部加载');
    assert.equal(t.mediaCalls(), 0); assert.equal(t.lifecycle(), 0);
  } finally { t.close(); }
});

(async () => {
  let passed = 0;
  for (const [name, run] of cases) { await run(); passed++; console.log(`PASS ${name}`); }
  console.log(`${passed} Sunny list-loading checks passed; no network or real media.`);
})().catch((error) => { console.error(error); process.exitCode = 1; });
