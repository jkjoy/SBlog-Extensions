'use strict';

// Browser behavior checks with fake audio and resolver responses; no account or music service is used.
// NODE_PATH=/temporary/node_modules node themes/sunny/tests/music-auth.cjs
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('playwright');
const script = fs.readFileSync(path.join(__dirname, '../music.js'), 'utf8');
const cases = [];
const test = (name, run) => cases.push({ name, run });
const waitFor = async (page, predicate) => page.waitForFunction(predicate, undefined, { timeout: 5000 });
const source = (id, extra = {}) => ({ ok: true, url: `https://m10.music.126.net/song-${id}.mp3`, expires_at: Math.floor(Date.now() / 1000) + 1200, ...extra });

let browser;
const setup = async ({ resolver = true, mode = 'list', count = 3, handle = (id) => source(id), config: extra = {}, playError = '', errorEvent = '' } = {}) => {
  const context = await browser.newContext();
  const page = await context.newPage();
  const calls = [];
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const config = {
    id: '123', mode, volume: .6,
    tracks: Array.from({ length: count }, (_, index) => ({ id: String(index + 1), name: `Song ${index + 1}`, artist: 'Fixture', url: `https://music.163.com/song/media/outer/url?id=${index + 1}.mp3`, cover: '', duration: 300 })),
    ...(resolver ? { resolver: { url: '/index.php?a=home&sunny_music_action=resolve', token: 'signed-fixture-token' } } : {}),
    ...extra,
  };
  await page.addInitScript(({ playError, errorEvent }) => {
    window.mediaCalls = { play: 0, load: 0, pause: 0 };
    window.nextPlayError = playError;
    window.nextErrorEvent = errorEvent;
    window.fakePaused = true;
    Object.defineProperty(HTMLMediaElement.prototype, 'paused', { get() { return window.fakePaused; } });
    Object.defineProperty(HTMLMediaElement.prototype, 'ended', { get() { return false; } });
    Object.defineProperty(HTMLMediaElement.prototype, 'duration', { get() { return 300; } });
    Object.defineProperty(HTMLMediaElement.prototype, 'currentTime', { get() { return this.fixtureTime || 0; }, set(value) { this.fixtureTime = value; } });
    HTMLMediaElement.prototype.load = function () { window.mediaCalls.load++; this.dispatchEvent(new Event('loadedmetadata')); };
    HTMLMediaElement.prototype.pause = function () { window.mediaCalls.pause++; window.fakePaused = true; this.dispatchEvent(new Event('pause')); };
    HTMLMediaElement.prototype.play = function () {
      window.mediaCalls.play++;
      if (window.nextPlayError) {
        const name = window.nextPlayError;
        const order = window.nextErrorEvent;
        window.nextPlayError = ''; window.nextErrorEvent = '';
        if (order === 'before') this.dispatchEvent(new Event('error'));
        if (order === 'after') setTimeout(() => this.dispatchEvent(new Event('error')), 0);
        return Promise.reject(new DOMException('Fixture', name));
      }
      window.fakePaused = false; this.dispatchEvent(new Event('play')); return Promise.resolve();
    };
  }, { playError, errorEvent });
  await page.route('**/*', async (route) => {
    const request = route.request();
    const url = new URL(request.url());
    if (url.origin !== 'https://sunny.test') return route.abort();
    if (url.searchParams.has('sunny_music_action')) {
      const body = new URLSearchParams(request.postData());
      const call = { id: body.get('id'), refresh: body.get('refresh'), token: body.get('token'), method: request.method() };
      calls.push(call);
      const response = await handle(call.id, call, calls.length);
      if (response?.delay) await new Promise((resolve) => setTimeout(resolve, response.delay));
      try { return await route.fulfill({ status: response?.status || 200, contentType: 'application/json', body: JSON.stringify(response?.body || response) }); } catch (_) { return; }
    }
    const controls = '<button data-music-toggle><span data-music-toggle-icon></span></button><button data-music-stop>Stop</button><button data-music-next>Next</button><button data-music-prev>Previous</button>';
    const tracks = config.tracks.map((_, index) => `<button data-music-track="${index}">Track ${index + 1}</button>`).join('');
    return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><body class="sunny-theme"><section id="page-controls"><strong data-music-name></strong>${controls}</section><audio id="sunny-music-audio"></audio><dialog id="sunny-music-dialog"><p data-music-notice></p>${tracks}</dialog><aside class="sunny-music-dock" hidden></aside><script id="sunny-music-data" type="application/json">${JSON.stringify(config)}</script></body></html>` });
  });
  await page.goto('https://sunny.test/');
  await page.addScriptTag({ content: script });
  const click = (selector) => page.locator(selector).first().click();
  const selected = () => page.locator('[data-music-track][aria-current="true"]').getAttribute('data-music-track');
  const notice = () => page.locator('[data-music-notice]').textContent();
  const close = async () => { assert.deepEqual(errors, [], 'No browser JavaScript errors'); await context.close(); };
  return { page, calls, click, selected, notice, close };
};

test('public playback keeps its direct source without calling the resolver', async () => {
  const t = await setup({ resolver: false });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => window.mediaCalls.play === 1);
    assert.equal(t.calls.length, 0);
    assert.equal(await t.page.locator('audio').getAttribute('src'), 'https://music.163.com/song/media/outer/url?id=1.mp3');
  } finally { await t.close(); }
});

test('authenticated playback requests only a signed song id and caches its temporary source', async () => {
  const t = await setup();
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => window.mediaCalls.play === 1);
    assert.deepEqual(t.calls, [{ id: '1', refresh: null, token: 'signed-fixture-token', method: 'POST' }]);
    assert.equal(await t.page.locator('audio').getAttribute('src'), source('1').url);
    await t.click('[data-music-toggle]');
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => window.mediaCalls.play === 2);
    assert.equal(t.calls.length, 1);
    await t.click('[data-music-next]');
    await waitFor(t.page, () => window.mediaCalls.play === 3);
    await t.click('[data-music-prev]');
    await waitFor(t.page, () => window.mediaCalls.play === 4);
    assert.deepEqual(t.calls.map((call) => call.id), ['1', '2']);
  } finally { await t.close(); }
});

test('audio failure refreshes the address once and restores the same progress', async () => {
  const t = await setup({ handle: (id, call) => source(id, { url: `https://m10.music.126.net/${id}-${call.refresh || 'first'}.mp3` }) });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => window.mediaCalls.play === 1);
    await t.page.locator('audio').evaluate((audio) => { audio.currentTime = 42.75; audio.dispatchEvent(new Event('error')); });
    await waitFor(t.page, () => window.mediaCalls.play === 2);
    assert.equal(t.calls.length, 2);
    assert.equal(t.calls[1].refresh, '1');
    assert.equal(await t.page.locator('audio').evaluate((audio) => audio.currentTime), 42.75);
    await t.page.locator('audio').evaluate((audio) => audio.dispatchEvent(new Event('error')));
    await waitFor(t.page, () => document.querySelector('[data-music-track="1"]').getAttribute('aria-current') === 'true');
    await waitFor(t.page, () => window.mediaCalls.play === 3);
    assert.deepEqual(t.calls.map((call) => call.id), ['1', '1', '2']);
  } finally { await t.close(); }
});

for (const errorEvent of ['before', 'after']) test(`an audio error ${errorEvent} play rejection triggers only one recovery`, async () => {
  const t = await setup({ playError: 'NotSupportedError', errorEvent });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => window.mediaCalls.play === 2 && !window.fakePaused);
    assert.deepEqual(t.calls.map((call) => [call.id, call.refresh]), [['1', null], ['1', '1']]);
    assert.equal(await t.selected(), '0');
    assert.equal(await t.page.locator('audio').getAttribute('src'), source('1').url);
  } finally { await t.close(); }
});

test('a nearly expired address is fetched again on resume', async () => {
  const t = await setup({ handle: (id, _call, number) => source(id, { expires_at: Math.floor(Date.now() / 1000) + (number === 1 ? 10 : 1200) }) });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => window.mediaCalls.play === 1);
    await t.page.locator('audio').evaluate((audio) => { audio.currentTime = 42.75; });
    await t.click('[data-music-toggle]');
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => window.mediaCalls.play === 2);
    assert.equal(t.calls.length, 2);
    assert.equal(await t.page.locator('audio').evaluate((audio) => audio.currentTime), 42.75);
  } finally { await t.close(); }
});

test('every audio source failing stops after one refresh per song', async () => {
  const t = await setup({ count: 2 });
  try {
    await t.click('[data-music-toggle]');
    for (let attempt = 1; attempt <= 4; attempt++) {
      await waitFor(t.page, () => !window.fakePaused);
      await t.page.locator('audio').evaluate((audio) => audio.dispatchEvent(new Event('error')));
      if (attempt < 4) await t.page.waitForFunction((count) => window.mediaCalls.play > count, attempt);
    }
    await waitFor(t.page, () => document.querySelector('[data-music-notice]').textContent.includes('均暂时无法播放'));
    assert.deepEqual(t.calls.map((call) => [call.id, call.refresh]), [['1', null], ['1', '1'], ['2', null], ['2', '1']]);
    assert.equal(await t.page.locator('audio').getAttribute('src'), null);
  } finally { await t.close(); }
});

test('a temporary resolver error retries once before skipping', async () => {
  const t = await setup({ handle: (id) => id === '1' ? { ok: false, code: 'temporary', skip: true } : source(id) });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => window.mediaCalls.play === 1);
    assert.deepEqual(t.calls.map((call) => [call.id, call.refresh]), [['1', null], ['1', '1'], ['2', null]]);
    assert.equal(await t.selected(), '1');
  } finally { await t.close(); }
});

test('unavailable and trial-only songs skip even in single-repeat mode and stop after one failed round', async () => {
  const t = await setup({ mode: 'single', handle: (id) => ({ ok: false, code: id === '1' ? 'trial_only' : 'unavailable', skip: true }) });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => document.querySelector('[data-music-notice]').textContent.includes('均暂时无法播放'));
    assert.deepEqual(t.calls.map((call) => call.id), ['1', '2', '3']);
    assert.equal(await t.page.locator('audio').getAttribute('src'), null);
    assert.equal(await t.page.evaluate(() => window.mediaCalls.play), 0);
  } finally { await t.close(); }
});

test('random failure traversal never requests the same rejected song twice', async () => {
  const t = await setup({ mode: 'random', count: 5, handle: () => ({ ok: false, code: 'unavailable', skip: true }) });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => document.querySelector('[data-music-notice]').textContent.includes('均暂时无法播放'));
    assert.equal(t.calls.length, 5);
    assert.equal(new Set(t.calls.map((call) => call.id)).size, 5);
  } finally { await t.close(); }
});

for (const code of ['login_expired', 'stale_token', 'rate_limited']) test(`${code} stops without falling back to a public source`, async () => {
  const t = await setup({ handle: () => ({ ok: false, code, skip: false, message: 'Do not display arbitrary server text' }) });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => document.querySelector('[data-music-notice]').textContent !== '');
    assert.equal(t.calls.length, 1);
    assert.equal(await t.page.locator('audio').getAttribute('src'), null);
    assert.equal(await t.page.evaluate(() => window.mediaCalls.play), 0);
    assert.ok(!(await t.notice()).includes('arbitrary'));
  } finally { await t.close(); }
});

for (const [code, expected] of [
  ['invalid_cookie', '网易云 Cookie 无效，请站长重新填写。'],
  ['not_configured', '尚未配置网易云 Cookie，请站长在主题设置中填写。'],
]) test(`${code} displays its fixed notice and stops even if the server requests skipping`, async () => {
  const t = await setup({ handle: () => ({ ok: false, code, skip: true, message: 'Do not display arbitrary server text' }) });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => document.querySelector('[data-music-notice]').textContent !== '');
    assert.equal(await t.notice(), expected);
    assert.equal(t.calls.length, 1);
    assert.equal(await t.selected(), '0');
    assert.equal(await t.page.locator('audio').getAttribute('src'), null);
    assert.equal(await t.page.evaluate(() => window.mediaCalls.play), 0);
  } finally { await t.close(); }
});

test('browser autoplay rejection waits for another click instead of skipping songs', async () => {
  const t = await setup({ playError: 'NotAllowedError' });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => document.querySelector('[data-music-notice]').textContent.includes('点击播放按钮'));
    assert.equal(t.calls.length, 1);
    assert.equal(await t.selected(), '0');
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => window.fakePaused === false);
    assert.equal(t.calls.length, 1);
  } finally { await t.close(); }
});

for (const selector of ['[data-music-stop]', '[data-music-toggle]']) test(`a pending request cannot start playback after ${selector}`, async () => {
  const t = await setup({ handle: (id) => ({ delay: 250, body: source(id) }) });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => document.querySelector('[data-music-toggle]').getAttribute('aria-busy') === 'true');
    await t.click(selector);
    await t.page.waitForTimeout(400);
    assert.equal(await t.page.evaluate(() => window.mediaCalls.play), 0);
    assert.equal(await t.page.locator('audio').getAttribute('src'), null);
  } finally { await t.close(); }
});

test('a newer song selection wins against a delayed resolver reply', async () => {
  const t = await setup({ handle: (id) => ({ delay: id === '1' ? 250 : 0, body: source(id) }) });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => document.querySelector('[data-music-toggle]').getAttribute('aria-busy') === 'true');
    await t.click('[data-music-next]');
    await waitFor(t.page, () => window.mediaCalls.play === 1);
    await t.page.waitForTimeout(350);
    assert.equal(await t.page.locator('audio').getAttribute('src'), source('2').url);
    assert.equal(await t.selected(), '1');
    assert.equal(await t.page.evaluate(() => window.mediaCalls.play), 1);
  } finally { await t.close(); }
});

test('PJAX page initialization synchronizes new controls without resolving or reloading audio', async () => {
  const t = await setup();
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => window.mediaCalls.play === 1);
    const before = await t.page.evaluate(() => {
      const audio = document.querySelector('audio'); window.originalAudio = audio; audio.currentTime = 42.75;
      return { ...window.mediaCalls };
    });
    await t.page.evaluate(() => {
      document.querySelector('#page-controls').innerHTML = '<strong data-music-name></strong><button data-music-toggle><span data-music-toggle-icon></span></button>';
      document.dispatchEvent(new Event('sunny:page-load'));
    });
    assert.equal(await t.page.locator('#page-controls [data-music-name]').textContent(), 'Song 1');
    assert.deepEqual(await t.page.evaluate(() => ({ ...window.mediaCalls })), before);
    assert.equal(await t.page.evaluate(() => document.querySelector('audio') === window.originalAudio && window.originalAudio.currentTime === 42.75), true);
    assert.equal(t.calls.length, 1);
  } finally { await t.close(); }
});

for (const url of ['https://evil.example/track.mp3', 'http://m10.music.126.net/track.mp3', 'https://m10.music.126.net.evil.example/track.mp3', 'https://user:password@m10.music.126.net/track.mp3']) test(`a resolver cannot inject the unsafe media URL ${url}`, async () => {
  const t = await setup({ count: 1, handle: () => source('1', { url }) });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => document.querySelector('[data-music-notice]').textContent.includes('均暂时无法播放'));
    assert.equal(await t.page.locator('audio').getAttribute('src'), null);
    assert.equal(await t.page.evaluate(() => window.mediaCalls.play), 0);
    assert.equal(t.calls.length, 2);
  } finally { await t.close(); }
});

test('an untrusted resolver configuration never leaks its access token or falls back', async () => {
  const t = await setup({ config: { resolver: { url: 'https://evil.example/resolve', token: 'signed-fixture-token' } } });
  try {
    await t.click('[data-music-toggle]');
    await waitFor(t.page, () => document.querySelector('[data-music-notice]').textContent.includes('播放授权'));
    assert.equal(t.calls.length, 0);
    assert.equal(await t.page.locator('audio').getAttribute('src'), null);
  } finally { await t.close(); }
});

(async () => {
  browser = await chromium.launch({ headless: true, ...(process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE ? { executablePath: process.env.PLAYWRIGHT_CHROMIUM_EXECUTABLE } : {}) });
  let passed = 0;
  try {
    for (const { name, run } of cases) {
      try { await run(); passed++; console.log(`PASS ${name}`); }
      catch (error) { console.error(`FAIL ${name}\n${error.stack}`); process.exitCode = 1; }
    }
  } finally { await browser.close(); }
  console.log(`${passed}/${cases.length} authenticated playback cases passed; no real music or account requests.`);
})().catch((error) => { console.error(error); process.exitCode = 1; });
