'use strict';

// Independent behavior tests against the shipped Sunny PJAX runtime.
// Run: node themes/sunny/tests/pjax.cjs [--core=/path/to/SBlog/assets/index.js]
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { JSDOM, VirtualConsole } = require('jsdom');
const runtime = fs.readFileSync(path.join(__dirname, '../pjax.js'), 'utf8');
const pageRuntime = fs.readFileSync(path.join(__dirname, '../script.js'), 'utf8');
const doubanCardsPath = path.join(__dirname, '../../../plugins/douban-showcase/assets/cards.js');
const doubanCardsRuntime = fs.existsSync(doubanCardsPath) ? fs.readFileSync(doubanCardsPath, 'utf8') : null;
const version = JSON.parse(fs.readFileSync(path.join(__dirname, '../theme.json'), 'utf8')).version;
const corePath = process.argv.slice(2).find((argument) => argument.startsWith('--core='))?.slice('--core='.length);
const coreRuntime = corePath ? fs.readFileSync(corePath, 'utf8') : null;
let passed = 0;
const cases = [];
const test = (name, run, options = {}) => cases.push({ name, run, ...options });
const pause = (ms = 0) => new Promise((resolve) => setTimeout(resolve, ms));
const until = async (predicate, message = 'condition', ms = 1500) => {
  const deadline = Date.now() + ms;
  while (Date.now() < deadline) {
    if (predicate()) return;
    await pause(10);
  }
  throw new Error(`Timed out waiting for ${message}`);
};
const html = (name, options = {}) => {
  const pageVersion = options.version || version;
  const theme = options.theme === false ? '' : ` class="sunny-theme sunny-view-${name}" data-sunny-pjax="${pageVersion}"`;
  const player = options.player === false ? '' : '<div id="sunny-player-root"><audio id="fixture-audio" src="https://music.163.com/song/media/outer/url?id=593469.mp3"></audio><dialog id="sunny-music-dialog" open>Playing</dialog><div data-music-dock>Dock</div><script type="application/json" id="sunny-music-data">{"tracks":[{"id":593469}]}</script></div>';
  const extra = options.extra || '';
  let scripts = '<script src="/assets/index.js?v=test" data-sunny-runtime="core" defer></script><script src="/themes/sunny/script.js?v=test" data-sunny-runtime="page" defer></script><script src="/themes/sunny/steam.js?v=test" data-sunny-runtime="steam" defer></script><script src="/themes/sunny/music.js?v=test" data-sunny-runtime="music" defer></script><script src="/themes/sunny/pjax.js?v=test" data-sunny-runtime="navigation" defer></script>';
  if (options.omitRuntime) scripts = scripts.replace(new RegExp(`<script[^>]+data-sunny-runtime="${options.omitRuntime}"[^>]*><\\/script>`), '');
  return `<!doctype html><html lang="zh-CN" data-sunny-theme="light"><head><meta charset="utf-8"><title>${name} | Sunny</title><meta name="description" content="${name} description"><meta name="keywords" content="${name}"><meta name="theme-color" content="#f7f7f7" id="sunny-theme-color"><link rel="canonical" href="https://sunny.test/${name}"><link rel="alternate" type="application/rss+xml" href="/${name}.rss"><link rel="icon" href="/${name}.svg"><link rel="stylesheet" href="/themes/sunny/style.css?v=test">${options.head || ''}</head><body${theme}><div class="sunny-shell"><main class="sunny-main" id="content" tabindex="-1"><h1>${name}</h1><a id="to-post" href="/post/fixture-post">Article</a><a id="to-home" href="/">Home</a><a id="to-steam" href="/steam">Steam</a><a id="to-archives" href="/archives">Archives</a><a id="to-anchor" href="/post/fixture-post#section-two">Post anchor</a><a id="same-anchor" href="#section-two">Same anchor</a><h2 id="section-two">Second</h2>${extra}</main></div>${player}<div id="sunny-pjax-status" role="status" aria-live="polite"></div><script type="application/json" id="sunny-search-data">[{"title":"${name}"}]</script>${scripts}${options.body || ''}</body></html>`
    .replaceAll('href="/', `href="${options.base || ''}/`).replaceAll('src="/', `src="${options.base || ''}/`);
};
const response = (name, options = {}) => ({
  ok: options.ok !== false,
  status: options.status || 200,
  url: options.url || `https://sunny.test/${name === 'home' ? '' : name}`,
  headers: { get: (key) => key.toLowerCase() === 'content-type' ? (options.contentType || 'text/html; charset=utf-8') : null },
  text: async () => options.raw ?? html(name, options),
});
const setup = (options = {}) => {
  const errors = [];
  const console = new VirtualConsole();
  console.on('jsdomError', (error) => errors.push(error));
  const dom = new JSDOM(html(options.name || 'home', options.html || {}), { url: options.url || 'https://sunny.test/', runScripts: 'outside-only', pretendToBeVisual: true, virtualConsole: console });
  const w = dom.window;
  if (options.historyPath) w.history.pushState(null, '', options.historyPath);
  const frames = [];
  if (options.manualFrames) w.requestAnimationFrame = (callback) => { frames.push(callback); return frames.length; };
  const fetches = [];
  const scrolls = [];
  const scrollInto = [];
  const events = [];
  const transitions = [];
  const root = w.document.querySelector('#sunny-player-root');
  const audio = w.document.querySelector('audio');
  let mediaCalls = 0;
  let y = 135;
  let x = 0;
  Object.defineProperty(w, 'scrollX', { configurable: true, get: () => x });
  Object.defineProperty(w, 'scrollY', { configurable: true, get: () => y });
  w.scrollTo = (first, second) => {
    const item = typeof first === 'object' ? first : { left: first, top: second };
    x = Number(item.left ?? x); y = Number(item.top ?? y);
    scrolls.push({ x, y, ...item });
  };
  w.HTMLElement.prototype.scrollIntoView = function (value) { scrollInto.push({ id: this.id, value }); };
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {} });
  w.HTMLMediaElement.prototype.pause = function () { mediaCalls++; };
  w.HTMLMediaElement.prototype.load = function () { mediaCalls++; };
  w.HTMLMediaElement.prototype.play = function () { mediaCalls++; return Promise.resolve(); };
  if (audio) { audio.currentTime = 42.75; Object.defineProperty(audio, 'paused', { configurable: true, get: () => false }); }
  w.fetch = async (url, init) => {
    fetches.push({ url: String(url), init });
    if (options.fetch) return options.fetch(String(url), init);
    const parsed = new URL(url, w.location.href);
    return response(parsed.pathname.startsWith('/post') ? 'post' : parsed.pathname.slice(1) || 'home', { url: parsed.href });
  };
  w.document.addEventListener('sunny:before-replace', (event) => events.push({ name: event.type, detail: event.detail }));
  w.document.addEventListener('sunny:page-load', (event) => events.push({ name: event.type, detail: event.detail }));
  new w.MutationObserver((records) => {
    for (const record of records) for (const node of record.removedNodes) {
      if (root && (node === root || node.contains?.(root))) transitions.push('player removed');
    }
  }).observe(w.document, { subtree: true, childList: true });
  // Styles in jsdom do not load. Deliver the browser's load callback to added stylesheet links.
  new w.MutationObserver((records) => {
    for (const record of records) for (const node of record.addedNodes) {
      const links = node.nodeType === 1 ? [node, ...node.querySelectorAll('link')] : [];
      if (!options.manualStyles) links.filter((link) => link.matches('link[rel="stylesheet"]')).forEach((link) => queueMicrotask(() => link.dispatchEvent(new w.Event('load'))));
    }
  }).observe(w.document.head, { childList: true, subtree: true });
  if (options.core) w.eval(coreRuntime);
  if (options.page) w.eval(pageRuntime);
  w.eval(runtime);
  w.document.dispatchEvent(new w.Event('DOMContentLoaded'));
  const click = (target, init = {}) => {
    const element = typeof target === 'string' ? w.document.querySelector(target) : target;
    assert.ok(element, `Missing click target ${target}`);
    const event = new w.MouseEvent('click', { bubbles: true, cancelable: true, button: 0, ...init });
    element.dispatchEvent(event);
    return event;
  };
  const link = (href, attrs = {}) => {
    const element = w.document.createElement('a'); element.href = href;
    for (const [key, value] of Object.entries(attrs)) element.setAttribute(key, value);
    element.textContent = 'Fixture'; w.document.querySelector('main').append(element);
    return element;
  };
  return { dom, w, frames, fetches, scrolls, scrollInto, events, transitions, errors, root, audio, click, link, mediaCalls: () => mediaCalls, close: () => dom.window.close() };
};

// Basic assertions are intentionally semantic: they check audible playback and public navigation behavior.
test('successful navigation preserves a continuously connected playing audio and open playlist', async () => {
  const t = setup();
  try {
    assert.equal(t.click('#to-post').defaultPrevented, true);
    await until(() => t.w.document.title === 'post | Sunny', 'article rendering');
    assert.equal(t.w.document.querySelector('#sunny-player-root'), t.root);
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.equal(t.audio.currentTime, 42.75);
    assert.equal(t.audio.paused, false);
    assert.equal(t.audio.getAttribute('src'), 'https://music.163.com/song/media/outer/url?id=593469.mp3');
    assert.equal(t.mediaCalls(), 0);
    assert.equal(t.w.document.querySelector('#sunny-music-dialog').open, true);
    assert.deepEqual(t.transitions, []);
    assert.equal(t.w.location.pathname, '/post/fixture-post');
    assert.equal(t.w.document.body.classList.contains('sunny-view-post'), true);
    assert.equal(t.w.document.querySelectorAll('#sunny-player-root').length, 1);
    assert.equal(t.w.document.querySelector('#sunny-search-data').textContent, '[{"title":"post"}]');
    assert.equal(t.events.filter((event) => event.name === 'sunny:before-replace').length, 1);
    assert.equal(t.events.filter((event) => event.name === 'sunny:page-load').length, 1);
    assert.equal(t.w.document.querySelector('meta[name="description"]').content, 'post description');
    assert.equal(t.w.document.querySelector('meta[name="keywords"]').content, 'post');
  } finally { t.close(); }
});

test('several page changes preserve one player and dispatch one lifecycle per change', async () => {
  const t = setup();
  try {
    for (const [link, name] of [['#to-steam', 'steam'], ['#to-archives', 'archives'], ['#to-home', 'home']]) {
      t.click(link); await until(() => t.w.document.title === `${name} | Sunny`, name);
      assert.equal(t.w.document.querySelector('audio'), t.audio);
    }
    assert.equal(t.fetches.length, 3);
    assert.equal(t.events.length, 6);
    assert.equal(t.mediaCalls(), 0);
    assert.deepEqual(t.transitions, []);
  } finally { t.close(); }
});

test('navigation works on pages without an enabled music player', async () => {
  const t = setup({ html: { player: false }, fetch: async (url) => response('post', { player: false, url }) });
  try { t.click('#to-post'); await until(() => t.w.document.title === 'post | Sunny'); assert.equal(t.w.document.querySelector('#sunny-player-root'), null); }
  finally { t.close(); }
});

test('a fetched page without music never disconnects the active player', async () => {
  const t = setup({ fetch: async (url) => response('post', { player: false, url }) });
  try { t.click('#to-post'); await until(() => t.w.document.title === 'post | Sunny'); assert.equal(t.w.document.querySelector('audio'), t.audio); assert.deepEqual(t.transitions, []); }
  finally { t.close(); }
});

for (const base of ['', '/sblog', '/themes/blog']) {
  test(`content slugs matching application directories keep audio connected under ${base || '/'}`, async () => {
    const t = setup({ url: `https://sunny.test${base}/`, html: { base }, fetch: async (url) => response('content', { url, base }) });
    try {
      const destinations = ['category/themes', 'category/plugins', 'tag/assets', 'archive/admin', 'page/api', 'post/login', 'category/admin_settings', 'category/rss', 'index.php?a=category&slug=themes'];
      for (const destination of destinations) {
        const target = `${base}/${destination}`;
        assert.equal(t.click(t.link(target)).defaultPrevented, true, `${target} must use PJAX`);
        await until(() => t.w.location.pathname + t.w.location.search === target && !t.w.document.body.hasAttribute('aria-busy'), `${target} rendering`);
        assert.equal(t.w.document.querySelector('audio'), t.audio);
        assert.equal(t.audio.currentTime, 42.75);
        assert.equal(t.audio.paused, false);
      }
      assert.equal(t.fetches.length, destinations.length);
      assert.equal(t.events.filter((event) => event.name === 'sunny:page-load').length, destinations.length);
      assert.equal(t.mediaCalls(), 0);
      assert.deepEqual(t.transitions, []);
      assert.deepEqual(t.errors, []);
    } finally { t.close(); }
  });

  test(`direct loading of a reserved content slug still initializes PJAX under ${base || '/'}`, async () => {
    const t = setup({ url: `https://sunny.test${base}/category/themes`, html: { base }, fetch: async (url) => response('post', { url, base }) });
    try {
      assert.equal(t.click('#to-post').defaultPrevented, true);
      await until(() => t.w.document.title === 'post | Sunny', 'article after direct category entry');
      assert.equal(t.w.location.pathname, `${base}/post/fixture-post`);
      assert.equal(t.w.document.querySelector('audio'), t.audio);
      assert.equal(t.mediaCalls(), 0);
      assert.deepEqual(t.transitions, []);
    } finally { t.close(); }
  });

  test(`real application directories and protected query actions keep browser navigation under ${base || '/'}`, async () => {
    const t = setup({ url: `https://sunny.test${base}/`, html: { base } });
    try {
      for (const destination of ['themes/sunny', 'plugins/example', 'admin', 'admin_settings', 'login', 'logout', 'uploads', 'assets', 'api', 'rest-api', 'rss', 'feed', 'sitemap.xml', 'install.php', 'index.php?a=admin', 'index.php?a=login', 'index.php?a=admin_settings']) {
        assert.equal(t.click(t.link(`${base}/${destination}`)).defaultPrevented, false, destination);
      }
      if (base) {
        const outside = t.click(t.link('/outside-this-app/post'));
        if (outside.defaultPrevented) await until(() => !t.w.document.body.hasAttribute('aria-busy'), 'unexpected outside-app navigation cleanup');
        assert.equal(outside.defaultPrevented, false);
      }
      await pause(25);
      assert.equal(t.fetches.length, 0);
      assert.equal(t.mediaCalls(), 0);
      assert.deepEqual(t.transitions, []);
    } finally { t.close(); }
  });
}

for (const [name, href, attrs, modifiers] of [
  ['external origin', 'https://outside.test/post', {}, {}],
  ['new browser tab', '/post/fixture-post', { target: '_blank' }, {}],
  ['download link', '/fixture.zip', { download: '' }, {}],
  ['email link', 'mailto:fixture@example.invalid', {}, {}],
  ['telephone link', 'tel:12345', {}, {}],
  ['Ctrl click', '/post/fixture-post', {}, { ctrlKey: true }],
  ['Meta click', '/post/fixture-post', {}, { metaKey: true }],
  ['Shift click', '/post/fixture-post', {}, { shiftKey: true }],
  ['Alt click', '/post/fixture-post', {}, { altKey: true }],
  ['middle click', '/post/fixture-post', {}, { button: 1 }],
  ['admin action', '/index.php?a=admin', {}, {}],
  ['login action', '/index.php?a=login', {}, {}],
  ['RSS link', '/rss', {}, {}],
  ['static media', '/cover.svg', {}, {}],
  ['explicit no-pjax link', '/post/fixture-post', { 'data-no-pjax': '' }, {}],
  ['explicit data-pjax=false link', '/post/fixture-post', { 'data-pjax': 'false' }, {}],
  ['external relation', '/post/fixture-post', { rel: 'external' }, {}],
]) test(`ordinary browser behavior is retained for ${name}`, async () => {
  const t = setup();
  try { const event = t.click(t.link(href, attrs), modifiers); await pause(25); assert.equal(event.defaultPrevented, false); assert.equal(t.fetches.length, 0); }
  finally { t.close(); }
});

test('a rapid second navigation wins even when the first fetch ignores cancellation', async () => {
  const waits = new Map();
  const t = setup({ fetch: (url, init) => new Promise((resolve) => waits.set(new URL(url).pathname, { resolve, init })) });
  try {
    t.click('#to-post'); await until(() => waits.has('/post/fixture-post'));
    t.click('#to-steam'); await until(() => waits.has('/steam'));
    waits.get('/steam').resolve(response('steam', { url: 'https://sunny.test/steam' }));
    await until(() => t.w.document.title === 'steam | Sunny');
    waits.get('/post/fixture-post').resolve(response('post', { url: 'https://sunny.test/post/fixture-post' }));
    await pause(50);
    assert.equal(t.w.document.title, 'steam | Sunny');
    assert.equal(t.w.location.pathname, '/steam');
    assert.equal(t.events.filter((event) => event.name === 'sunny:page-load').length, 1);
    assert.equal(t.w.document.querySelector('audio'), t.audio);
  } finally { t.close(); }
});

test('cross-page anchors navigate then reach their requested heading', async () => {
  const t = setup();
  try {
    t.click('#to-anchor'); await until(() => t.w.document.title === 'post | Sunny'); await pause(50);
    assert.equal(t.w.location.hash, '#section-two');
    assert.ok(t.scrollInto.some((item) => item.id === 'section-two'));
    assert.equal(t.w.document.querySelector('audio'), t.audio);
  } finally { t.close(); }
});

test('Back and Forward fetch the correct pages without adding history entries', async () => {
  const t = setup();
  try {
    t.click('#to-post'); await until(() => t.w.document.title === 'post | Sunny');
    t.click('#to-steam'); await until(() => t.w.document.title === 'steam | Sunny');
    const entries = t.w.history.length;
    t.w.history.back(); await until(() => t.w.document.title === 'post | Sunny', 'Back article');
    assert.equal(t.w.location.pathname, '/post/fixture-post');
    t.w.history.forward(); await until(() => t.w.document.title === 'steam | Sunny', 'Forward Steam');
    assert.equal(t.w.history.length, entries);
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.deepEqual(t.transitions, []);
  } finally { t.close(); }
});

for (const [name, failingResponse] of [
  ['HTTP failure', () => response('post', { ok: false, status: 503 })],
  ['network rejection', () => Promise.reject(new TypeError('Synthetic network failure'))],
]) test(`${name} leaves the current page and audio usable with an explicit retry`, async () => {
  let attempts = 0;
  const t = setup({ fetch: async (url) => ++attempts === 1 ? failingResponse() : response('post', { url }) });
  try {
    t.click('#to-post'); await until(() => t.w.document.querySelector('#sunny-pjax-error'), 'visible failure notice');
    await pause(40);
    assert.equal(t.w.document.title, 'home | Sunny');
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.equal(t.mediaCalls(), 0);
    assert.deepEqual(t.transitions, []);
    const retry = t.w.document.querySelector('#sunny-pjax-error a, #sunny-pjax-error button');
    assert.ok(retry, 'Failure should offer a retry control');
    t.click(retry); await until(() => t.w.document.title === 'post | Sunny', 'successful retry');
    assert.equal(t.w.document.querySelector('audio'), t.audio);
  } finally { t.close(); }
});

for (const [name, changed] of [
  ['a different theme', { theme: false }],
  ['a different Sunny version', { version: `${version}-changed` }],
  ['an unfamiliar executable script', { body: '<script src="/plugins/unknown/frontend.js"></script>' }],
]) test(`${name} requests full navigation without destroying the current page first`, async () => {
  const t = setup({ fetch: async (url) => response('post', { ...changed, url }) });
  try {
    t.click('#to-post'); await until(() => t.errors.some((error) => /navigation/i.test(error.message)), 'full browser navigation');
    assert.equal(t.w.document.title, 'home | Sunny');
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.deepEqual(t.transitions, []);
    assert.equal(t.events.filter((event) => event.name === 'sunny:before-replace').length, 0);
  } finally { t.close(); }
});


test('Back restores the scroll position saved before leaving the article', async () => {
  const t = setup();
  try {
    t.click('#to-post'); await until(() => t.w.document.title === 'post | Sunny');
    await until(() => !t.w.document.body.hasAttribute('aria-busy'));
    t.w.scrollTo({ top: 678, left: 12 });
    t.click('#to-steam'); await until(() => t.w.document.title === 'steam | Sunny');
    await until(() => !t.w.document.body.hasAttribute('aria-busy'));
    t.w.history.back(); await until(() => t.w.document.title === 'post | Sunny');
    await until(() => !t.w.document.body.hasAttribute('aria-busy'));
    assert.equal(t.w.scrollY, 678);
    assert.equal(t.w.scrollX, 12);
    assert.equal(t.w.history.state.sunnyPjax.scroll.y, 678);
  } finally { t.close(); }
});

test('styles and page metadata update while the marked player stylesheet survives', async () => {
  const t = setup({
    html: { head: '<link rel="stylesheet" href="/themes/sunny/music.css" data-sunny-player-style><link rel="stylesheet" href="/old-page.css">' },
    fetch: async (url) => response('post', { url, head: '<link rel="stylesheet" href="/new-page.css"><meta property="og:title" content="Post card"><style>.post-page{color:red}</style>' }),
  });
  try {
    t.click('#to-post'); await until(() => t.w.document.title === 'post | Sunny');
    assert.equal(t.w.document.querySelector('link[href="/old-page.css"]'), null);
    assert.ok(t.w.document.querySelector('link[href="https://sunny.test/new-page.css"]'));
    assert.ok(t.w.document.querySelector('link[data-sunny-player-style]'));
    assert.equal(t.w.document.querySelector('link[rel="canonical"]').href, 'https://sunny.test/post');
    assert.equal(t.w.document.querySelector('link[rel="alternate"]').href, 'https://sunny.test/post.rss');
    assert.equal(t.w.document.querySelector('meta[property="og:title"]').content, 'Post card');
    assert.equal(t.w.document.querySelector('style').textContent, '.post-page{color:red}');
  } finally { t.close(); }
});

test('core comment stopPropagation still permits article links and replies after navigation', async () => {
  const comment = `<section class="comments"><a href="/archives" id="comment-link"><span>Archive inside comment</span></a><button type="button" data-comment-reply data-comment-id="21" data-comment-author="Visitor">Reply</button><form class="comment-form"><input data-comment-parent-id value=""><div data-comment-reply-state hidden><span data-comment-reply-name></span><button type="button" data-comment-reply-cancel>Cancel</button></div><textarea id="comment-content"></textarea></form></section>`;
  const t = setup({ core: true, fetch: async (url) => response(new URL(url).pathname.startsWith('/post') ? 'post' : 'archives', { url, extra: new URL(url).pathname.startsWith('/post') ? comment : '' }) });
  try {
    t.click('#to-post'); await until(() => t.w.document.title === 'post | Sunny');
    t.click('[data-comment-reply]');
    assert.equal(t.w.document.querySelector('[data-comment-parent-id]').value, '21');
    await until(() => t.w.document.querySelector('[data-comment-reply-name]').textContent === '@Visitor');
    t.click('[data-comment-reply-cancel]');
    assert.equal(t.w.document.querySelector('[data-comment-parent-id]').value, '');
    assert.equal(t.w.document.querySelector('[data-comment-reply-state]').hidden, true);
    const event = t.click('#comment-link span');
    assert.equal(event.defaultPrevented, true);
    await until(() => t.w.document.title === 'archives | Sunny');
    assert.equal(t.w.document.querySelector('audio'), t.audio);
  } finally { t.close(); }
}, { skip: coreRuntime ? '' : 'provide --core=/path/to/SBlog/assets/index.js' });

test('same-page anchors retain browser behavior and never request a document', async () => {
  const t = setup();
  try { const event = t.click('#same-anchor'); await pause(30); assert.equal(event.defaultPrevented, false); assert.equal(t.fetches.length, 0); assert.equal(t.w.location.hash, '#section-two'); }
  finally { t.close(); }
});

test('a runtime version change uses a full load before changing the document', async () => {
  const t = setup({ fetch: async (url) => response('post', { url, raw: html('post').replace('/themes/sunny/script.js?v=test', '/themes/sunny/script.js?v=changed') }) });
  try { t.click('#to-post'); await until(() => t.errors.some((error) => /navigation/i.test(error.message))); assert.equal(t.w.document.title, 'home | Sunny'); assert.deepEqual(t.transitions, []); }
  finally { t.close(); }
});


const finishFrames = async (t, predicate, message) => {
  const deadline = Date.now() + 1500;
  while (Date.now() < deadline) {
    while (t.frames.length) { t.frames.shift()(); await pause(10); }
    if (predicate()) return;
    await pause(10);
  }
  throw new Error(`Timed out settling animation frames: ${message}`);
};

test('Back during the commit frame keeps the existing history branch and displayed page aligned', async () => {
  const t = setup({ name: 'a', url: 'https://sunny.test/previous', historyPath: '/a', manualFrames: true });
  try {
    t.click('#to-post');
    await until(() => t.frames.length === 1, 'paused commit frame');
    t.w.history.back();
    await until(() => t.w.location.pathname === '/previous', 'Back location');
    assert.equal(t.w.history.length, 2);
    await finishFrames(t, () => t.w.document.title === 'previous | Sunny' && !t.w.document.body.hasAttribute('aria-busy'), 'Back before commit');
    assert.equal(t.w.location.pathname, '/previous');
    assert.equal(t.w.history.length, 2, 'Canceled navigation must not append a replacement history entry');
    assert.equal(t.events.filter((event) => event.name === 'sunny:page-load').length, 1);
    t.w.history.forward();
    await finishFrames(t, () => t.w.location.pathname === '/a' && t.w.document.title === 'a | Sunny' && !t.w.document.body.hasAttribute('aria-busy'), 'Forward original page');
    t.w.history.back();
    await finishFrames(t, () => t.w.location.pathname === '/previous' && t.w.document.title === 'previous | Sunny' && !t.w.document.body.hasAttribute('aria-busy'), 'Back original page');
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.deepEqual(t.transitions, []);
  } finally { t.close(); }
});

test('Back during a plugin load preserves the previous, article and destination history entries', async () => {
  const plugin = '/plugins/gallery/assets/public.js';
  const t = setup({
    name: 'a', url: 'https://sunny.test/previous', historyPath: '/a', manualFrames: true,
    fetch: async (url) => response(new URL(url).pathname.slice(1), { url, body: new URL(url).pathname.startsWith('/post') ? `<script src="${plugin}"></script>` : '' }),
  });
  try {
    t.click('#to-post');
    await finishFrames(t, () => t.w.document.querySelector(`script[src="https://sunny.test${plugin}"]`)?.onload, 'plugin asset request');
    const pending = t.w.document.querySelector(`script[src="https://sunny.test${plugin}"]`);
    assert.equal(t.w.location.pathname, '/post/fixture-post');
    t.w.history.back();
    await until(() => t.w.location.pathname === '/a', 'Back during asset load');
    pending.onload();
    await finishFrames(t, () => t.w.document.title === 'a | Sunny' && !t.w.document.body.hasAttribute('aria-busy'), 'queued Back after asset');
    assert.equal(t.w.location.pathname, '/a');
    assert.equal(t.w.history.length, 3);
    t.w.history.back();
    await finishFrames(t, () => t.w.location.pathname === '/previous' && t.w.document.title === 'previous | Sunny' && !t.w.document.body.hasAttribute('aria-busy'), 'Back to previous');
    t.w.history.forward();
    await finishFrames(t, () => t.w.location.pathname === '/a' && t.w.document.title === 'a | Sunny' && !t.w.document.body.hasAttribute('aria-busy'), 'Forward to article');
    t.w.history.forward();
    await finishFrames(t, () => t.w.document.querySelector(`script[src="https://sunny.test${plugin}"]`)?.onload, 'Forward destination plugin');
    t.w.document.querySelector(`script[src="https://sunny.test${plugin}"]`).onload();
    await finishFrames(t, () => !t.w.document.body.hasAttribute('aria-busy'), 'Forward destination completion');
    assert.equal(t.w.location.pathname, '/post/fixture-post');
    assert.equal(t.w.document.title, 'post/fixture-post | Sunny');
    assert.equal(t.w.history.length, 3);
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.deepEqual(t.transitions, []);
  } finally { t.close(); }
});

test('the article likes live region keeps its original node and never duplicates', async () => {
  const status = '<div class="article-likes-status" data-article-likes-status role="status">Plugin notice</div>';
  const t = setup({ html: { body: status }, fetch: async (url) => response(new URL(url).pathname.slice(1) || 'home', { url, body: status }) });
  try {
    const original = t.w.document.querySelector('[data-article-likes-status]');
    let clicks = 0;
    original.addEventListener('click', () => clicks++);
    for (const [selector, name] of [['#to-post', 'post/fixture-post'], ['#to-steam', 'steam'], ['#to-home', 'home']]) {
      t.click(selector); await until(() => t.w.document.title === `${name} | Sunny`);
      assert.equal(t.w.document.querySelector('[data-article-likes-status]'), original);
      assert.equal(t.w.document.querySelectorAll('.article-likes-status, [data-article-likes-status]').length, 1);
    }
    t.click(original);
    assert.equal(clicks, 1, 'The loaded plugin must retain its live region handlers');
  } finally { t.close(); }
});

for (const [name, markup] of [
  ['unknown external script already present at initial load', '<script src="/plugins/unknown/frontend.js"></script>'],
  ['unknown inline script already present at initial load', '<script>window.fixtureUnknownPageBinding=true;</script>'],
]) test(`${name} still requires full navigation`, async () => {
  const t = setup({ html: { body: markup }, fetch: async (url) => response('post', { url, body: markup }) });
  try {
    t.click('#to-post');
    await until(() => t.errors.some((error) => /navigation/i.test(error.message)), 'unsupported page script fallback');
    assert.equal(t.w.document.title, 'home | Sunny');
    assert.equal(t.events.length, 0);
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.deepEqual(t.transitions, []);
  } finally { t.close(); }
});

test('imported body keeps JSON data and applies translations without inserting executable scripts', async () => {
  const messages = '<script>window.sblogI18n=Object.assign({},window.sblogI18n||{},{"fixture_message":"Translated"});</script>';
  const t = setup({ fetch: async (url) => response('post', { url, body: messages }) });
  try {
    t.click('#to-post'); await until(() => t.w.document.title === 'post | Sunny');
    await until(() => !t.w.document.body.hasAttribute('aria-busy'));
    assert.equal(t.w.sblogI18n.fixture_message, 'Translated');
    assert.equal(t.w.document.body.querySelectorAll('script:not([type="application/json"])').length, 0);
    assert.equal(t.w.document.querySelector('#sunny-search-data').textContent, '[{"title":"post"}]');
    assert.equal(t.w.document.querySelector('#sunny-music-data').textContent, '{"tracks":[{"id":593469}]}');
  } finally { t.close(); }
});


test('a failed Back request with a hash restores the URL of the page still on screen', async () => {
  let rejectRequest;
  const t = setup({
    name: 'steam', url: 'https://sunny.test/archives#older-section', historyPath: '/steam#current-section',
    fetch: () => new Promise((resolve, reject) => { rejectRequest = reject; }),
  });
  try {
    const sequence = [];
    t.w.addEventListener('popstate', () => sequence.push('popstate'));
    t.w.addEventListener('hashchange', () => sequence.push('hashchange'));
    t.w.history.back();
    await until(() => rejectRequest && sequence.includes('hashchange'), 'Back popstate followed by hashchange');
    assert.deepEqual(sequence.slice(0, 2), ['popstate', 'hashchange']);
    assert.equal(t.w.location.href, 'https://sunny.test/archives#older-section');
    rejectRequest(new TypeError('Synthetic Back network failure'));
    await until(() => t.w.document.querySelector('#sunny-pjax-error'), 'failed Back notice');
    assert.equal(t.w.location.href, 'https://sunny.test/steam#current-section');
    assert.equal(t.w.history.state.sunnyPjax.url, 'https://sunny.test/steam#current-section');
    assert.equal(t.w.document.title, 'steam | Sunny');
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.equal(t.audio.currentTime, 42.75);
    assert.equal(t.mediaCalls(), 0);
    assert.deepEqual(t.transitions, []);
    assert.equal(t.events.length, 0);
  } finally { t.close(); }
});

test('a new runtime load failure restores working page controls and allows a successful retry', async () => {
  const controls = '<button type="button" data-sunny-theme-toggle>Change theme</button><section class="comments"><a href="/archives" id="recovered-comment-link">Archives inside comment</a><button type="button" data-comment-reply data-comment-id="21" data-comment-author="Visitor">Reply</button><form class="comment-form"><input data-comment-parent-id value=""><div data-comment-reply-state hidden><span data-comment-reply-name></span><button type="button" data-comment-reply-cancel>Cancel</button></div><textarea id="comment-content"></textarea></form></section>';
  const t = setup({
    page: true, core: Boolean(coreRuntime), html: { omitRuntime: 'steam', extra: controls },
    fetch: async (url) => response(new URL(url).pathname.startsWith('/post') ? 'post' : 'archives', { url, extra: controls }),
  });
  try {
    assert.equal(t.w.document.querySelector('[data-sunny-runtime="steam"]'), null);
    t.click('#to-post');
    await until(() => t.w.document.querySelector('body > script[data-sunny-runtime="steam"]')?.onerror, 'new runtime loader');
    assert.equal(t.w.document.title, 'post | Sunny', 'The new body has already committed');
    t.w.document.querySelector('body > script[data-sunny-runtime="steam"]').onerror();
    await until(() => t.w.document.querySelector('#sunny-pjax-error'), 'runtime failure notice');
    assert.equal(t.w.document.querySelector('.comments').classList.contains('sunny-comments-ready'), true);
    const theme = t.w.document.documentElement.dataset.sunnyTheme;
    t.click('[data-sunny-theme-toggle]');
    assert.notEqual(t.w.document.documentElement.dataset.sunnyTheme, theme, 'Actual theme script must bind the replacement controls');
    if (coreRuntime) {
      t.click('[data-comment-reply]');
      assert.equal(t.w.document.querySelector('[data-comment-parent-id]').value, '21');
      t.click('[data-comment-reply-cancel]');
      assert.equal(t.w.document.querySelector('[data-comment-parent-id]').value, '');
    }
    assert.equal(t.events.filter((event) => event.name === 'sunny:page-load').length, 1);
    const attempts = t.fetches.length;
    t.click('#sunny-pjax-error a');
    await until(() => t.fetches.length > attempts && t.w.document.querySelector('body > script[data-sunny-runtime="steam"]')?.onload, 'retry runtime loader');
    t.w.document.querySelector('body > script[data-sunny-runtime="steam"]').onload();
    await until(() => !t.w.document.body.hasAttribute('aria-busy'), 'retry completion');
    assert.equal(t.w.document.querySelector('#sunny-pjax-error'), null);
    assert.equal(t.click('#recovered-comment-link').defaultPrevented, true);
    await until(() => t.w.document.title === 'archives | Sunny', 'recovered comment navigation');
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.equal(t.audio.currentTime, 42.75);
    assert.equal(t.mediaCalls(), 0);
    assert.deepEqual(t.transitions, []);
    assert.equal(t.errors.length, 0, t.errors.map((error) => error.stack).join('\n'));
  } finally { t.close(); }
});


for (const kind of ['external', 'inline']) test(`unchanged initial head ${kind} script survives two navigations with changing nonces`, async () => {
  const markup = (nonce) => kind === 'external'
    ? `<script defer src="https://analytics.example.test/stats.js" data-website-id="imsunpw" nonce="${nonce}"></script>`
    : `<script data-fixture-head="inline" nonce="${nonce}">window.fixtureHeadExecutions=(window.fixtureHeadExecutions||0)+1;</script>`;
  let request = 0;
  const t = setup({
    html: { head: markup('initial-nonce') },
    fetch: async (url) => response(new URL(url).pathname.startsWith('/post') ? 'post' : 'steam', { url, head: markup(`response-nonce-${++request}`) }),
  });
  try {
    const selector = kind === 'external' ? 'script[src="https://analytics.example.test/stats.js"]' : 'script[data-fixture-head="inline"]';
    const original = t.w.document.head.querySelector(selector);
    assert.ok(original);
    if (kind === 'inline') t.w.eval(original.textContent);
    const mutations = [];
    new t.w.MutationObserver((records) => {
      for (const record of records) {
        for (const node of [...record.addedNodes, ...record.removedNodes]) {
          if (node.nodeType === 1 && (node.matches(selector) || node.querySelector(selector))) mutations.push(node);
        }
      }
    }).observe(t.w.document, { childList: true, subtree: true });
    for (const [link, title] of [['#to-post', 'post'], ['#to-steam', 'steam']]) {
      t.click(link);
      await until(() => t.w.document.title === `${title} | Sunny`, `${kind} head script permits ${title}`);
      await until(() => !t.w.document.body.hasAttribute('aria-busy'));
      assert.equal(t.w.document.head.querySelector(selector), original, 'The script initialized on first load must keep its node');
      assert.equal(t.w.document.querySelectorAll(selector).length, 1);
      assert.equal(original.isConnected, true);
    }
    assert.deepEqual(mutations, [], 'Persistent scripts must never be removed, reinserted or copied');
    if (kind === 'inline') assert.equal(t.w.fixtureHeadExecutions, 1);
    assert.equal(t.errors.length, 0, 'Ordinary navigation must not request a full document load');
    assert.equal(t.fetches.length, 2);
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.equal(t.mediaCalls(), 0);
    assert.deepEqual(t.transitions, []);
  } finally { t.close(); }
});

for (const [name, initial, incoming] of [
  ['src', '<script defer src="https://analytics.example.test/stats.js" data-website-id="imsunpw"></script>', '<script defer src="https://analytics.example.test/stats-v2.js" data-website-id="imsunpw"></script>'],
  ['data attribute', '<script defer src="https://analytics.example.test/stats.js" data-website-id="imsunpw"></script>', '<script defer src="https://analytics.example.test/stats.js" data-website-id="another-site"></script>'],
  ['inline text', '<script data-fixture-head="inline">window.fixtureSite="imsunpw";</script>', '<script data-fixture-head="inline">window.fixtureSite="another-site";</script>'],
]) test(`an initial head script with changed ${name} requires full navigation`, async () => {
  const t = setup({ html: { head: initial }, fetch: async (url) => response('post', { url, head: incoming }) });
  try {
    const original = [...t.w.document.head.querySelectorAll('script')];
    t.click('#to-post');
    await until(() => t.errors.some((error) => /navigation/i.test(error.message)), 'changed head script fallback');
    assert.equal(t.w.document.title, 'home | Sunny');
    assert.deepEqual([...t.w.document.head.querySelectorAll('script')], original);
    assert.equal(t.events.length, 0);
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.deepEqual(t.transitions, []);
  } finally { t.close(); }
});

test('the core empty translation array permits navigation and preserves existing messages', async () => {
  const emptyMessages = '<script>window.sblogI18n=Object.assign({},window.sblogI18n||{},[]);</script>';
  const t = setup({ fetch: async (url) => response('post', { url, head: emptyMessages }) });
  try {
    t.w.sblogI18n = { existing_message: 'Keep this translation' };
    t.click('#to-post');
    await until(() => t.w.document.title === 'post | Sunny', 'empty core translation catalog');
    await until(() => !t.w.document.body.hasAttribute('aria-busy'));
    assert.equal(t.w.sblogI18n.existing_message, 'Keep this translation');
    assert.equal(t.errors.length, 0);
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.deepEqual(t.transitions, []);
  } finally { t.close(); }
});


test('the visit tracker records each destination without delaying navigation or replacing its original head node', async () => {
  const tracker = '<script defer src="https://0tz.top/tracker.min.js" data-website-id="imsunpw"></script>';
  const t = setup({ html: { head: tracker }, fetch: async (url) => response(new URL(url).pathname.startsWith('/post') ? 'post' : new URL(url).pathname.slice(1), { url, head: tracker }) });
  try {
    const original = t.w.document.head.querySelector('script[src="https://0tz.top/tracker.min.js"]');
    const requested = [];
    const completed = [];
    t.w.document.addEventListener('sunny:pjax-complete', (event) => completed.push(event.detail.url));
    new t.w.MutationObserver((records) => {
      for (const record of records) for (const node of record.addedNodes) {
        if (node.nodeType === 1 && node.matches('script[src="https://0tz.top/tracker.min.js"]')) requested.push(node);
      }
    }).observe(t.w.document.body, { childList: true });
    t.click('#to-post');
    await until(() => completed.length === 1, 'article completion while analytics remains pending');
    assert.equal(requested.length, 1);
    assert.equal(typeof requested[0].onload, 'function', 'The pending tracker is deliberately not completed');
    assert.equal(t.w.document.body.hasAttribute('aria-busy'), false);
    t.click('#to-steam');
    await until(() => completed.length === 2, 'next page while previous analytics remains pending');
    assert.equal(requested.length, 2);
    assert.notEqual(requested[1], requested[0]);
    requested[0].onerror();
    requested[1].onerror();
    await pause(25);
    assert.equal(t.w.document.querySelector('#sunny-pjax-error'), null, 'An optional tracker failure must not become a page failure');
    t.click('#to-archives');
    await until(() => completed.length === 3, 'navigation after analytics failure');
    assert.equal(requested.length, 3, 'Each successful destination must request the tracker exactly once');
    requested[2].onload();
    await pause(10);
    assert.deepEqual(completed, ['https://sunny.test/post/fixture-post', 'https://sunny.test/steam', 'https://sunny.test/archives']);
    assert.equal(t.w.document.head.querySelector('script[src="https://0tz.top/tracker.min.js"]'), original);
    assert.equal(original.isConnected, true);
    assert.equal(t.w.document.head.querySelectorAll('script[src="https://0tz.top/tracker.min.js"]').length, 1);
    assert.equal(requested.every((script) => script.getAttribute('data-website-id') === 'imsunpw'), true);
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.equal(t.audio.currentTime, 42.75);
    assert.equal(t.mediaCalls(), 0);
    assert.deepEqual(t.transitions, []);
    assert.equal(t.errors.length, 0);
  } finally { t.close(); }
});

test('a visit tracker first encountered on the destination uses PJAX and completes before analytics', async () => {
  const tracker = '<script defer src="https://0tz.top/tracker.min.js" data-website-id="imsunpw"></script>';
  const t = setup({ fetch: async (url) => response('post', { url, head: tracker }) });
  try {
    let completed = false;
    t.w.document.addEventListener('sunny:pjax-complete', () => { completed = true; });
    t.click('#to-post');
    await until(() => completed, 'destination with newly encountered tracker');
    const script = t.w.document.querySelector('body > script[src="https://0tz.top/tracker.min.js"]');
    assert.ok(script && typeof script.onload === 'function');
    assert.equal(t.w.document.title, 'post | Sunny');
    assert.equal(t.w.location.pathname, '/post/fixture-post');
    script.onerror();
    await pause(20);
    assert.equal(t.w.document.querySelector('#sunny-pjax-error'), null);
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.deepEqual(t.transitions, []);
    assert.equal(t.errors.length, 0);
  } finally { t.close(); }
});

for (const initiallyLoaded of [true, false]) test(`Douban cards ${initiallyLoaded ? 'already loaded on home' : 'first encountered on an article'} scan PJAX pages with one runtime and observer`, async () => {
  const cardsScript = '<script defer src="/plugins/douban-showcase/assets/cards.js?v=1.1.1"></script>';
  const card = (id) => `<figure class="media-embed media-embed--douban"><a class="sblog-douban-card" data-douban-subject data-subject-type="movie" data-subject-id="${id}" data-subject-endpoint="/index.php?a=douban_showcase_subject" href="https://movie.douban.com/subject/${id}/" target="_blank"><span class="sblog-douban-card__body"><span class="sblog-douban-card__title">Pending</span></span></a></figure>`;
  const subjectRequests = [];
  const t = setup({
    core: Boolean(coreRuntime),
    html: initiallyLoaded ? { body: cardsScript, extra: card('1001') + card('1001') } : {},
    fetch: async (url) => {
      const parsed = new URL(url);
      if (parsed.searchParams.get('a') === 'douban_showcase_subject') {
        const id = parsed.searchParams.get('id');
        subjectRequests.push(id);
        return { ok: true, json: async () => ({ ok: true, subject: { id, type: 'movie', title: `Movie ${id}`, year: '2026', rating: '8.5', rating_count: 123, meta: ['Fixture director'], summary: 'Fixture summary', cover_url: '' } }) };
      }
      const name = parsed.pathname.startsWith('/post') ? 'post' : parsed.pathname.slice(1) || 'home';
      const id = name === 'post' ? '1002' : name === 'archives' ? '1003' : '1001';
      return response(name, { url, body: cardsScript, extra: card(id) + card(id) });
    },
  });
  try {
    // Preserve jsdom's native observer for the harness; count only the plugin's observers.
    const NativeObserver = t.w.MutationObserver;
    let observers = 0;
    t.w.MutationObserver = class extends NativeObserver {
      constructor(callback) { super(callback); observers++; }
    };
    const scriptLoads = [];
    new NativeObserver((records) => {
      for (const record of records) for (const node of record.addedNodes) {
        if (node.nodeType !== 1 || !node.matches('script[src]') || new URL(node.src).pathname !== '/plugins/douban-showcase/assets/cards.js') continue;
        scriptLoads.push(node.src);
        // Execute the shipped plugin source, as a successfully fetched script would.
        t.w.eval(doubanCardsRuntime);
        node.onload();
      }
    }).observe(t.w.document.body, { childList: true });
    await until(() => t.w.document.readyState !== 'loading', 'initial document readiness');
    if (initiallyLoaded) {
      t.w.eval(doubanCardsRuntime);
      await until(() => [...t.w.document.querySelectorAll('[data-douban-subject]')].every((item) => item.dataset.subjectState === 'ready'), 'initial cards');
      assert.deepEqual(subjectRequests, ['1001'], 'Duplicate cards share one subject request');
    }
    for (const [selector, name, id] of [['#to-post', 'post', '1002'], ['#to-archives', 'archives', '1003'], ['#to-home', 'home', '1001']]) {
      assert.equal(t.click(selector).defaultPrevented, true);
      await until(() => t.w.document.title === `${name} | Sunny` && !t.w.document.body.hasAttribute('aria-busy'), `${name} with Douban cards`);
      await until(() => [...t.w.document.querySelectorAll('[data-douban-subject]')].every((item) => item.dataset.subjectState === 'ready'), `${name} card hydration`);
      assert.deepEqual([...t.w.document.querySelectorAll('.sblog-douban-card__title')].map((item) => item.textContent), [`Movie ${id}`, `Movie ${id}`]);
      assert.equal(t.w.document.querySelector('audio'), t.audio);
      assert.equal(t.audio.currentTime, 42.75);
      assert.equal(t.audio.paused, false);
      assert.equal(observers, 1, 'New pages reuse the plugin observer');
      assert.equal(scriptLoads.length, initiallyLoaded ? 0 : 1, 'The card runtime loads at most once');
    }
    const requestCount = subjectRequests.length;
    // Both the plugin's explicit rescan and its own render mutations must stay idempotent.
    t.w.document.dispatchEvent(new t.w.CustomEvent('wcf:posts-loaded', { detail: { posts: [t.w.document.querySelector('main')] } }));
    await pause(25);
    assert.equal(subjectRequests.length, requestCount);
    assert.deepEqual([...new Set(subjectRequests)].sort(), ['1001', '1002', '1003']);
    assert.equal(subjectRequests.length, 3, 'Subjects remain cached across page replacements');
    assert.equal(t.events.filter((event) => event.name === 'sunny:page-load').length, 3);
    assert.equal(t.mediaCalls(), 0);
    assert.deepEqual(t.transitions, []);
    assert.equal(t.errors.length, 0, t.errors.map((error) => error.stack).join('\n'));
  } finally { t.close(); }
}, { skip: doubanCardsRuntime ? '' : 'install douban-showcase beside themes to run its real card runtime integration' });

test('an external script explicitly marked for each page executes once on every destination', async () => {
  const pageScript = '<script defer src="https://analytics.example.test/page-hook.js" data-sunny-pjax="page" data-fixture-hook="active"></script>';
  const t = setup({ html: { head: pageScript }, fetch: async (url) => response(new URL(url).pathname.startsWith('/post') ? 'post' : 'steam', { url, head: pageScript }) });
  try {
    const original = t.w.document.head.querySelector('script[data-sunny-pjax="page"]');
    const requested = [];
    let completed = 0;
    t.w.document.addEventListener('sunny:pjax-complete', () => completed++);
    for (const [index, selector, title] of [[0, '#to-post', 'post'], [1, '#to-steam', 'steam']]) {
      t.click(selector);
      await until(() => {
        const script = t.w.document.querySelector('body > script[data-sunny-pjax="page"]');
        return script?.onload && !requested.includes(script);
      }, 'per-page script request');
      const script = t.w.document.querySelector('body > script[data-sunny-pjax="page"]');
      assert.equal(completed, index, 'The destination must initialize its explicitly requested page script');
      assert.equal(script.src, 'https://analytics.example.test/page-hook.js');
      assert.equal(script.dataset.fixtureHook, 'active');
      requested.push(script);
      // jsdom does not fetch scripts in this harness; emulate their successful execution.
      t.w.eval('window.fixturePageExecutions=(window.fixturePageExecutions||0)+1;');
      script.onload();
      await until(() => completed === index + 1, 'per-page script completion');
      assert.equal(t.w.document.title, `${title} | Sunny`);
      assert.equal(t.w.document.head.querySelector('script[data-sunny-pjax="page"]'), original);
    }
    assert.equal(t.w.fixturePageExecutions, 2);
    assert.equal(new Set(requested).size, 2, 'A previously loaded page script must execute again on the next page');
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.deepEqual(t.transitions, []);
    assert.equal(t.errors.length, 0);
  } finally { t.close(); }
});


const cssNodes = (document) => [...document.head.querySelectorAll('link[rel="stylesheet"], style')];
const cssOrder = (document) => cssNodes(document).map((node) => node.tagName === 'LINK' ? new URL(node.href).pathname : `style#${node.id}:${node.textContent}`);
const observeCss = (t) => {
  const mutations = [];
  const observer = new t.w.MutationObserver((records) => {
    for (const record of records) for (const action of ['addedNodes', 'removedNodes']) {
      for (const node of record[action]) if (node.nodeType === 1 && node.matches('link[rel="stylesheet"], style')) mutations.push({ node, action });
    }
  });
  observer.observe(t.w.document.head, { childList: true });
  return mutations;
};

test('shared stylesheets and inline fonts remain continuously connected in the same cascade order', async () => {
  const head = '<link rel="stylesheet" href="/shared-widget.css"><style id="fixture-font-face">@font-face{font-family:FixtureFont;src:url(/fixture-font.woff2)}body{font-family:FixtureFont}</style><link rel="stylesheet" href="/shared-colors.css">';
  const t = setup({ html: { head }, fetch: async (url) => response(new URL(url).pathname.startsWith('/post') ? 'post' : 'steam', { url, head }) });
  try {
    const originals = cssNodes(t.w.document);
    const order = cssOrder(t.w.document);
    const mutations = observeCss(t);
    for (const [link, title] of [['#to-post', 'post'], ['#to-steam', 'steam']]) {
      t.click(link);
      await until(() => t.w.document.title === `${title} | Sunny` && !t.w.document.body.hasAttribute('aria-busy'), 'navigation with unchanged CSS');
      assert.deepEqual(cssNodes(t.w.document), originals, 'The same styles must retain their original nodes and order');
      assert.deepEqual(cssOrder(t.w.document), order);
      assert.equal(originals.every((node) => node.isConnected), true);
    }
    assert.deepEqual(mutations, [], 'Shared CSS must not be removed, reinserted or cloned during navigation');
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.deepEqual(t.transitions, []);
  } finally { t.close(); }
});

test('a destination waits for its new stylesheet before replacing the visible page', async () => {
  const t = setup({ manualStyles: true, fetch: async (url) => response('post', { url, head: '<link rel="stylesheet" href="/post-only.css">' }) });
  try {
    const main = t.w.document.querySelector('main');
    const shared = t.w.document.querySelector('link[rel="stylesheet"]');
    const mutations = observeCss(t);
    t.click('#to-post');
    await until(() => t.w.document.querySelector('link[href="https://sunny.test/post-only.css"]'), 'new stylesheet request');
    const pending = t.w.document.querySelector('link[href="https://sunny.test/post-only.css"]');
    assert.equal(t.w.document.querySelector('main'), main);
    assert.equal(t.w.document.title, 'home | Sunny');
    assert.equal(t.w.location.pathname, '/');
    assert.equal(t.events.length, 0, 'Page lifecycle must wait until its CSS loads');
    assert.equal(shared.isConnected, true);
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    pending.dispatchEvent(new t.w.Event('load'));
    await until(() => t.w.document.title === 'post | Sunny' && !t.w.document.body.hasAttribute('aria-busy'), 'styled article commit');
    assert.notEqual(t.w.document.querySelector('main'), main);
    assert.equal(t.w.document.querySelector('link[href="/themes/sunny/style.css?v=test"]'), shared);
    assert.equal(mutations.some(({ node }) => node === shared), false, 'Loading an added stylesheet must not move an unchanged one');
    assert.equal(t.mediaCalls(), 0);
    assert.deepEqual(t.transitions, []);
  } finally { t.close(); }
});

test('a stylesheet failure rolls back added CSS and preserves the old page, fonts and audio', async () => {
  const head = '<link rel="stylesheet" href="/current-page.css"><style id="fixture-font-face">@font-face{font-family:FixtureFont;src:url(/fixture-font.woff2)}</style>';
  const t = setup({ manualStyles: true, html: { head }, fetch: async (url) => response('post', { url, head: '<link rel="stylesheet" href="/loaded-but-uncommitted.css"><link rel="stylesheet" href="/broken-page.css">' }) });
  try {
    const main = t.w.document.querySelector('main');
    const originalStyles = cssNodes(t.w.document);
    const mutations = observeCss(t);
    t.click('#to-post');
    await until(() => t.w.document.querySelector('link[href="https://sunny.test/broken-page.css"]'), 'both destination stylesheet requests');
    t.w.document.querySelector('link[href="https://sunny.test/loaded-but-uncommitted.css"]').dispatchEvent(new t.w.Event('load'));
    assert.equal(t.w.document.querySelector('main'), main);
    t.w.document.querySelector('link[href="https://sunny.test/broken-page.css"]').dispatchEvent(new t.w.Event('error'));
    await until(() => t.w.document.querySelector('#sunny-pjax-error'), 'stylesheet failure notice');
    assert.equal(t.w.document.querySelector('main'), main);
    assert.equal(t.w.document.title, 'home | Sunny');
    assert.equal(t.w.location.pathname, '/');
    assert.deepEqual(cssNodes(t.w.document), originalStyles);
    assert.equal(mutations.some(({ node }) => originalStyles.includes(node)), false);
    assert.equal(t.events.length, 0);
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.equal(t.audio.currentTime, 42.75);
    assert.equal(t.audio.paused, false);
    assert.equal(t.mediaCalls(), 0);
    assert.deepEqual(t.transitions, []);
  } finally { t.close(); }
});

test('a newly loaded stylesheet takes its destination cascade position without moving shared CSS', async () => {
  const head = '<link rel="stylesheet" href="/shared-before.css"><style id="fixture-font-face">@font-face{font-family:FixtureFont;src:url(/fixture-font.woff2)}</style><link rel="stylesheet" href="/shared-after.css">';
  const nextHead = head.replace('<style', '<link rel="stylesheet" href="/inserted-page.css"><style');
  const t = setup({ manualStyles: true, html: { head }, fetch: async (url) => response('post', { url, head: nextHead }) });
  try {
    const originals = cssNodes(t.w.document);
    const mutations = observeCss(t);
    t.click('#to-post');
    await until(() => t.w.document.querySelector('link[href="https://sunny.test/inserted-page.css"]'), 'inserted stylesheet request');
    t.w.document.querySelector('link[href="https://sunny.test/inserted-page.css"]').dispatchEvent(new t.w.Event('load'));
    await until(() => t.w.document.title === 'post | Sunny' && !t.w.document.body.hasAttribute('aria-busy'), 'article cascade commit');
    assert.deepEqual(cssOrder(t.w.document), ['/themes/sunny/style.css', '/shared-before.css', '/inserted-page.css', 'style#fixture-font-face:@font-face{font-family:FixtureFont;src:url(/fixture-font.woff2)}', '/shared-after.css']);
    assert.equal(originals.every((node) => node.isConnected), true);
    assert.equal(mutations.some(({ node }) => originals.includes(node)), false, 'An insertion can satisfy the new cascade without touching shared CSS');
    assert.equal(t.w.document.querySelector('audio'), t.audio);
  } finally { t.close(); }
});

test('a changed cascade preloads replacements while the current stylesheet nodes stay active', async () => {
  const initial = '<link rel="stylesheet" href="/cascade-a.css"><link rel="stylesheet" href="/cascade-b.css">';
  const next = '<link rel="stylesheet" href="/cascade-b.css"><link rel="stylesheet" href="/cascade-a.css">';
  const t = setup({ manualStyles: true, html: { head: initial }, fetch: async (url) => response('post', { url, head: next }) });
  try {
    const main = t.w.document.querySelector('main');
    const originals = cssNodes(t.w.document);
    const stablePrefix = originals[0];
    const mutations = observeCss(t);
    t.click('#to-post');
    await until(() => cssNodes(t.w.document).some((node) => !originals.includes(node)), 'cascade replacement preload');
    assert.equal(t.w.document.querySelector('main'), main, 'The old page must remain until reordered styles are ready');
    assert.equal(originals.every((node) => node.isConnected), true);
    assert.equal(mutations.some(({ node }) => originals.includes(node)), false, 'Do not detach a stylesheet while it still styles the visible page');
    for (const node of cssNodes(t.w.document).filter((node) => !originals.includes(node) && node.tagName === 'LINK')) node.dispatchEvent(new t.w.Event('load'));
    await until(() => t.w.document.title === 'post | Sunny' && !t.w.document.body.hasAttribute('aria-busy'), 'reordered cascade commit');
    assert.deepEqual(cssOrder(t.w.document), ['/themes/sunny/style.css', '/cascade-b.css', '/cascade-a.css']);
    assert.equal(cssNodes(t.w.document)[0], stablePrefix, 'A stable prefix does not need to be replaced or moved');
    assert.equal(mutations.some(({ node }) => node === stablePrefix), false);
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.deepEqual(t.transitions, []);
  } finally { t.close(); }
});


test('updated or reordered player CSS replaces old rules without duplicating the persistent player style', async () => {
  const before = '<link rel="stylesheet" href="/shared-before.css">';
  const after = '<link rel="stylesheet" href="/shared-after.css">';
  const player = (version) => `<link rel="stylesheet" href="/themes/sunny/music.css?v=${version}" data-sunny-player-style>`;
  const t = setup({
    manualStyles: true, html: { head: before + player('old') + after },
    fetch: async (url) => response(new URL(url).pathname.startsWith('/post') ? 'post' : 'steam', { url, head: new URL(url).pathname.startsWith('/post') ? before + player('new') + after : player('new') + before + after }),
  });
  try {
    const originalPlayerStyle = t.w.document.querySelector('[data-sunny-player-style]');
    t.click('#to-post');
    await until(() => t.w.document.querySelector('link[href="https://sunny.test/themes/sunny/music.css?v=new"]'), 'updated player stylesheet request');
    const updated = t.w.document.querySelector('link[href="https://sunny.test/themes/sunny/music.css?v=new"]');
    assert.equal(originalPlayerStyle.isConnected, true, 'Playing audio keeps its old stylesheet until the replacement loads');
    updated.dispatchEvent(new t.w.Event('load'));
    await until(() => t.w.document.title === 'post | Sunny' && !t.w.document.body.hasAttribute('aria-busy'), 'updated player stylesheet commit');
    assert.equal(originalPlayerStyle.isConnected, false);
    assert.equal(t.w.document.querySelectorAll('[data-sunny-player-style]').length, 1);
    assert.equal(t.w.document.querySelector('[data-sunny-player-style]'), updated);
    t.click('#to-steam');
    await until(() => [...t.w.document.querySelectorAll('[data-sunny-player-style]')].some((node) => node !== updated), 'reordered player stylesheet preload');
    const reordered = [...t.w.document.querySelectorAll('[data-sunny-player-style]')].find((node) => node !== updated);
    assert.equal(updated.isConnected, true);
    reordered.dispatchEvent(new t.w.Event('load'));
    await until(() => t.w.document.title === 'steam | Sunny' && !t.w.document.body.hasAttribute('aria-busy'), 'reordered player stylesheet commit');
    assert.equal(updated.isConnected, false, 'The displaced copy must not leave old player rules in the cascade');
    assert.equal(t.w.document.querySelectorAll('[data-sunny-player-style]').length, 1);
    assert.equal(t.w.document.querySelector('[data-sunny-player-style]'), reordered);
    assert.deepEqual(cssOrder(t.w.document), ['/themes/sunny/style.css', '/themes/sunny/music.css', '/shared-before.css', '/shared-after.css']);
    assert.equal(reordered.href, 'https://sunny.test/themes/sunny/music.css?v=new');
    assert.equal(t.w.document.querySelector('audio'), t.audio);
    assert.equal(t.audio.currentTime, 42.75);
    assert.equal(t.mediaCalls(), 0);
    assert.deepEqual(t.transitions, []);
  } finally { t.close(); }
});

(async () => {
  const failures = [];
  let skipped = 0;
  for (const { name, run, skip } of cases) {
    if (skip) { skipped++; console.log(`SKIP ${name}: ${skip}`); continue; }
    try { await run(); passed++; console.log(`PASS ${name}`); }
    catch (error) { failures.push({ name, error }); console.error(`FAIL ${name}\n${error.stack}`); }
  }
  console.log(`\n${passed}/${cases.length - skipped} cases passed${skipped ? `; ${skipped} optional integration skipped` : ''}`);
  if (failures.length) process.exitCode = 1;
})();
