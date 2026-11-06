(() => {
  'use strict';

  const payload = document.getElementById('sunny-music-data');
  const audio = document.getElementById('sunny-music-audio');
  const dialog = document.getElementById('sunny-music-dialog');
  if (!document.body.classList.contains('sunny-theme') || !payload || !audio || !dialog || audio.dataset.sunnyMusicReady === 'true') return;

  let config;
  try { config = JSON.parse(payload.textContent); } catch (_) { return; }
  if (!config || !Array.isArray(config.tracks)) return;

  const audioUrl = (value) => typeof value === 'string' && /^https:\/\/music\.163\.com\/song\/media\/outer\/url\?id=[1-9]\d{0,19}\.mp3$/.test(value) ? value : '';
  const coverUrl = (value) => {
    if (typeof value !== 'string' || /[\x00-\x20\x7f]/.test(value)) return '';
    try {
      const url = new URL(value);
      return url.protocol === 'https:' && !url.username && !url.password && !url.port
        && (url.hostname === 'music.126.net' || url.hostname.endsWith('.music.126.net')) ? url.href : '';
    } catch (_) { return ''; }
  };
  const tracks = config.tracks.slice(0, 200).map((track, sourceIndex) => {
    if (!track || !audioUrl(track.url)) return null;
    return { sourceIndex, id: track.url.match(/\?id=(\d+)\.mp3$/)[1], name: String(track.name || '未命名歌曲'), artist: String(track.artist || '未知歌手'), url: track.url, cover: coverUrl(track.cover), duration: Math.max(0, Number(track.duration) || 0) };
  }).filter(Boolean);
  if (tracks.length === 0) return;
  const authenticated = Object.prototype.hasOwnProperty.call(config, 'resolver');
  let resolver = null;
  if (authenticated && config.resolver && typeof config.resolver.url === 'string' && config.resolver.url.trim() !== ''
    && typeof config.resolver.token === 'string' && config.resolver.token.length > 0 && config.resolver.token.length < 4096) {
    try {
      const url = new URL(config.resolver.url, location.href);
      if (url.origin === location.origin && /^https?:$/.test(url.protocol) && !url.username && !url.password) resolver = { url: url.href, token: config.resolver.token };
    } catch (_) {}
  }

  audio.dataset.sunnyMusicReady = 'true';
  audio.preload = 'none';
  audio.loop = false;
  const all = (selector) => Array.from(document.querySelectorAll(selector));
  const dock = document.querySelector('.sunny-music-dock');
  const modes = ['list', 'single', 'random'];
  const modeLabels = { list: '列表循环', single: '单曲循环', random: '随机播放' };
  const storageKey = 'sunny-music-state';
  const playlistId = String(config.id || '');
  const initialNotices = new WeakMap();
  const failedCovers = new WeakMap();
  let noticeText = '';
  let mode = modes.includes(config.mode) ? config.mode : 'list';
  let index = 0;
  let restoreTime = 0;
  let loadedIndex = -1;
  let request = 0;
  let lastSaved = 0;
  let opener = null;
  let pending = null;
  let loadedExpires = 0;
  let progressStart = 0;
  const sources = new Map();
  const failedTracks = new Set();
  const refreshedTracks = new Set();
  const clamp = (value, minimum, maximum) => Math.min(maximum, Math.max(minimum, value));
  let volume = Number.isFinite(Number(config.volume)) ? clamp(Number(config.volume), 0, 1) : .6;

  try {
    const raw = localStorage.getItem(storageKey);
    const saved = raw && raw.length < 2000 ? JSON.parse(raw) : null;
    if (saved && String(saved.id) === playlistId) {
      const savedIndex = typeof saved.songId === 'string'
        ? tracks.findIndex((track) => track.id === saved.songId)
        : Number.isInteger(saved.index) && saved.index >= 0 && saved.index < tracks.length ? saved.index : -1;
      if (savedIndex >= 0) {
        index = savedIndex;
        if (Number.isFinite(saved.time) && saved.time > 0) restoreTime = Math.min(saved.time, 86400);
      }
      if (Number.isFinite(saved.volume)) volume = clamp(saved.volume, 0, 1);
      if (modes.includes(saved.mode)) mode = saved.mode;
    }
  } catch (_) {}
  audio.volume = volume;

  const playing = () => !audio.paused && !audio.ended && loadedIndex >= 0;
  const cancelPending = () => { pending?.abort(); pending = null; };
  const resetFailures = () => { failedTracks.clear(); refreshedTracks.clear(); };
  const setNotice = (text = '') => {
    noticeText = text;
    all('[data-music-notice]').forEach((notice) => {
      if (!initialNotices.has(notice)) initialNotices.set(notice, notice.textContent);
      notice.textContent = text || initialNotices.get(notice);
    });
  };
  const timeText = (seconds) => {
    const total = Number.isFinite(seconds) ? Math.max(0, Math.floor(seconds)) : 0;
    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
  };
  const persist = (force = false) => {
    if (!force && Date.now() - lastSaved < 5000) return;
    lastSaved = Date.now();
    const time = loadedIndex === index && Number.isFinite(audio.currentTime) ? audio.currentTime : restoreTime;
    try { localStorage.setItem(storageKey, JSON.stringify({ id: playlistId, index, songId: tracks[index].id, time, volume: audio.volume, mode })); } catch (_) {}
  };
  const syncTime = () => {
    const duration = loadedIndex === index && Number.isFinite(audio.duration) && audio.duration > 0 ? audio.duration : 0;
    const current = loadedIndex === index && Number.isFinite(audio.currentTime) ? audio.currentTime : restoreTime;
    all('[data-music-current-time]').forEach((element) => { element.textContent = timeText(current); });
    all('[data-music-duration]').forEach((element) => { element.textContent = timeText(duration || tracks[index].duration); });
    all('[data-music-progress]').forEach((input) => {
      input.disabled = duration === 0;
      input.value = duration > 0 ? String(clamp(current / duration * 100, 0, 100)) : '0';
      input.setAttribute('aria-valuetext', `${timeText(current)} / ${timeText(duration || tracks[index].duration)}`);
    });
  };
  const syncPlaying = () => {
    const active = playing();
    document.body.classList.toggle('sunny-music-playing', active);
    all('[data-music-toggle]').forEach((button) => {
      const icon = button.querySelector('[data-music-toggle-icon]');
      if (icon) icon.textContent = active ? 'Ⅱ' : '▶';
      button.setAttribute('aria-label', pending ? '取消加载' : active ? '暂停播放' : '播放音乐');
      button.setAttribute('title', pending ? '取消加载' : active ? '暂停播放' : '播放音乐');
      button.setAttribute('aria-pressed', String(active));
      button.setAttribute('aria-busy', String(Boolean(pending)));
    });
    try { if ('mediaSession' in navigator) navigator.mediaSession.playbackState = active ? 'playing' : 'paused'; } catch (_) {}
  };
  const syncMode = () => all('[data-music-mode]').forEach((button) => {
    button.textContent = modeLabels[mode];
    button.setAttribute('aria-label', `${modeLabels[mode]}，点击切换播放模式`);
    button.setAttribute('title', '切换播放模式');
  });
  const syncVolume = () => all('[data-music-volume]').forEach((input) => { input.value = String(Math.round(audio.volume * 100)); });
  const syncTrack = (updateMetadata = true) => {
    const track = tracks[index];
    all('[data-music-name]').forEach((element) => { element.textContent = track.name; element.title = track.name; });
    all('[data-music-artist]').forEach((element) => { element.textContent = track.artist; element.title = track.artist; });
    all('[data-music-cover]').forEach((image) => {
      image.hidden = !track.cover || failedCovers.get(image) === track.cover;
      if (track.cover) {
        if (image.getAttribute('src') !== track.cover) image.src = track.cover;
      } else { image.removeAttribute('src'); }
    });
    all('[data-music-track]').forEach((button) => {
      const current = Number(button.dataset.musicTrack) === track.sourceIndex;
      if (current) button.setAttribute('aria-current', 'true'); else button.removeAttribute('aria-current');
      button.disabled = !tracks.some((item) => item.sourceIndex === Number(button.dataset.musicTrack));
    });
    try {
      if (updateMetadata && 'mediaSession' in navigator && typeof MediaMetadata === 'function') {
        navigator.mediaSession.metadata = new MediaMetadata({ title: track.name, artist: track.artist, album: String(config.name || ''), artwork: track.cover ? [{ src: track.cover }] : [] });
      }
    } catch (_) {}
    syncTime();
  };

  const applyRestoreTime = () => {
    if (loadedIndex !== index || restoreTime <= 0 || !Number.isFinite(audio.duration) || audio.duration <= 0) return;
    try { audio.currentTime = clamp(restoreTime, 0, Math.max(0, audio.duration - .1)); restoreTime = 0; } catch (_) {}
  };
  const playbackError = (code, message, skip = true) => Object.assign(new Error(message), { code, skip });
  const sourceFor = async (track, refresh, controller) => {
    if (!authenticated) return { url: track.url, expires: Infinity };
    if (!resolver) throw playbackError('stale_token', '播放授权无效，请刷新页面后重试。', false);
    const cached = sources.get(track.id);
    if (!refresh && cached && cached.expires > Date.now() + 30000) return cached;
    const body = new URLSearchParams({ id: track.id, token: resolver.token });
    if (refresh) body.set('refresh', '1');
    const timeout = setTimeout(() => controller.abort(), 10000);
    try {
      const response = await fetch(resolver.url, { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', headers: { Accept: 'application/json' }, body, signal: controller.signal });
      if (!response.headers.get('content-type')?.includes('application/json')) throw new Error('Invalid playback response');
      const text = await response.text();
      if (text.length > 16384) throw new Error('Oversized playback response');
      const result = JSON.parse(text);
      if (!result || result.ok !== true) {
        const messages = {
          login_expired: '网易云登录已失效，请站长更新 Cookie。',
          invalid_cookie: '网易云 Cookie 无效，请站长重新填写。',
          not_configured: '尚未配置网易云 Cookie，请站长在主题设置中填写。',
          stale_token: '播放授权已过期，请刷新页面后重试。',
          trial_only: '此歌曲仅提供试听，正在切换下一首。',
          unavailable: '此歌曲暂时无法播放，正在切换下一首。',
          rate_limited: '播放请求过于频繁，请稍后再试。',
          temporary: '暂时无法获取播放地址。',
        };
        const code = typeof result?.code === 'string' ? result.code : 'temporary';
        throw playbackError(code, messages[code] || messages.temporary, !['login_expired', 'invalid_cookie', 'not_configured', 'stale_token', 'rate_limited'].includes(code) && result?.skip !== false);
      }
      const url = coverUrl(result.url);
      const expires = Number(result.expires_at) * 1000;
      if (!response.ok || !url || !Number.isFinite(expires) || expires <= Date.now() + 1000 || expires > Date.now() + 86400000) throw new Error('Invalid playback address');
      const source = { url, expires };
      sources.set(track.id, source);
      return source;
    } finally { clearTimeout(timeout); }
  };
  const play = async ({ automatic = false, refresh = false } = {}) => {
    if (!automatic) resetFailures();
    const token = ++request;
    cancelPending();
    if (dock) dock.hidden = false;
    if (!automatic) setNotice();
    try {
      if (loadedIndex !== index || refresh || (authenticated && loadedExpires <= Date.now() + 30000)) {
        if (loadedIndex === index && Number.isFinite(audio.currentTime)) restoreTime = audio.currentTime;
        loadedIndex = -1;
        const controller = new AbortController();
        pending = controller;
        syncPlaying();
        const source = await sourceFor(tracks[index], refresh, controller);
        if (token !== request || controller.signal.aborted) return;
        pending = null;
        audio.src = source.url;
        loadedExpires = source.expires;
        loadedIndex = index;
        audio.load();
      }
      applyRestoreTime();
      await audio.play();
      if (token !== request) return;
      pending = null;
      progressStart = Number.isFinite(audio.currentTime) ? audio.currentTime : 0;
      syncPlaying();
    } catch (error) {
      if (token !== request) return;
      pending = null;
      if (error?.name === 'NotAllowedError') {
        audio.pause();
        setNotice('请点击播放按钮开始播放。');
        syncPlaying();
        return;
      }
      if (error?.name === 'AbortError' && !authenticated) return;
      failTrack(error);
    }
  };
  const failTrack = (error) => {
    const code = typeof error?.code === 'string' ? error.code : '';
    const message = error?.message && code ? error.message : '此歌曲暂时无法播放，正在切换下一首。';
    if (error?.skip === false) {
      request++;
      cancelPending();
      audio.pause();
      setNotice(message);
      syncPlaying();
      return;
    }
    if (authenticated && (!code || code === 'temporary') && !refreshedTracks.has(index)) {
      refreshedTracks.add(index);
      sources.delete(tracks[index].id);
      void play({ automatic: true, refresh: true });
      return;
    }
    failedTracks.add(index);
    sources.delete(tracks[index].id);
    const candidates = tracks.map((_, item) => (index + item + 1) % tracks.length).filter((item) => !failedTracks.has(item));
    if (!candidates.length) {
      stop();
      setNotice('当前歌单的歌曲均暂时无法播放，请稍后重试或在网易云查看。');
      return;
    }
    setNotice(message);
    selectTrack(mode === 'random' ? candidates[Math.floor(Math.random() * candidates.length)] : candidates[0], true, true);
  };
  const pause = () => {
    request++;
    cancelPending();
    audio.pause();
    syncPlaying();
    persist(true);
  };
  const selectTrack = (next, autoplay = true, automatic = false) => {
    request++;
    cancelPending();
    if (!automatic) resetFailures();
    audio.pause();
    index = clamp(next, 0, tracks.length - 1);
    loadedIndex = -1;
    loadedExpires = 0;
    restoreTime = 0;
    audio.removeAttribute('src');
    audio.load();
    syncTrack();
    syncPlaying();
    persist(true);
    if (autoplay) void play({ automatic: true });
  };
  const randomIndex = () => tracks.length < 2 ? index : (index + 1 + Math.floor(Math.random() * (tracks.length - 1))) % tracks.length;
  const advance = (direction = 1, automatic = false) => {
    resetFailures();
    const next = automatic && mode === 'single' ? index : mode === 'random' ? randomIndex() : (index + direction + tracks.length) % tracks.length;
    selectTrack(next);
  };
  const stop = () => {
    request++;
    cancelPending();
    resetFailures();
    audio.pause();
    loadedIndex = -1;
    loadedExpires = 0;
    restoreTime = 0;
    audio.removeAttribute('src');
    audio.load();
    if (dock) dock.hidden = true;
    setNotice();
    syncTime();
    syncPlaying();
    persist(true);
  };

  const closeDialog = () => {
    if (typeof dialog.close === 'function') dialog.close(); else { dialog.removeAttribute('open'); opener?.focus({ preventScroll: true }); }
  };
  dialog.addEventListener('click', (event) => {
    if (event.target !== dialog) return;
    const bounds = dialog.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeDialog();
  });
  dialog.addEventListener('close', () => {
    if (opener?.isConnected) opener.focus({ preventScroll: true });
  });
  // Page navigation replaces the sidebar and toolbar; delegate their controls once.
  document.addEventListener('click', (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-music-open], [data-music-close], [data-music-toggle], [data-music-prev], [data-music-next], [data-music-stop], [data-music-mode], [data-music-track]') : null;
    if (!button || button.disabled) return;
    if (button.hasAttribute('data-music-open')) {
      if (dialog.open) return;
      opener = button;
      if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
    } else if (button.hasAttribute('data-music-close')) closeDialog();
    else if (button.hasAttribute('data-music-toggle')) { if (playing() || pending) pause(); else void play(); }
    else if (button.hasAttribute('data-music-prev')) advance(-1);
    else if (button.hasAttribute('data-music-next')) advance(1);
    else if (button.hasAttribute('data-music-stop')) stop();
    else if (button.hasAttribute('data-music-mode')) {
      mode = modes[(modes.indexOf(mode) + 1) % modes.length]; syncMode(); persist(true);
    } else {
      const next = tracks.findIndex((track) => track.sourceIndex === Number(button.dataset.musicTrack));
      if (next >= 0) selectTrack(next);
    }
  });
  document.addEventListener('input', (event) => {
    const input = event.target;
    if (!(input instanceof HTMLInputElement)) return;
    if (input.hasAttribute('data-music-progress')) {
      if (loadedIndex !== index || !Number.isFinite(audio.duration) || audio.duration <= 0) return;
      audio.currentTime = audio.duration * clamp(Number(input.value) || 0, 0, 100) / 100;
      restoreTime = 0; syncTime(); persist(true);
    } else if (input.hasAttribute('data-music-volume')) {
      audio.volume = clamp((Number(input.value) || 0) / 100, 0, 1); syncVolume(); persist(true);
    }
  });
  document.addEventListener('error', (event) => {
    const image = event.target;
    if (!(image instanceof HTMLImageElement) || (!image.hasAttribute('data-music-cover') && !dialog.contains(image))) return;
    failedCovers.set(image, image.getAttribute('src'));
    image.hidden = true;
  }, true);
  const syncPage = () => {
    // The persistent audio is never reloaded or paused by a page refresh.
    if (opener && !opener.isConnected) opener = null;
    syncTrack(false); syncMode(); syncPlaying(); syncVolume(); setNotice(noticeText);
    all('[data-music-cover], #sunny-music-dialog img').forEach((image) => {
      if (image.complete && image.getAttribute('src') && image.naturalWidth === 0) image.hidden = true;
    });
  };
  document.addEventListener('sunny:page-load', syncPage);
  audio.addEventListener('loadedmetadata', () => { applyRestoreTime(); syncTime(); });
  audio.addEventListener('durationchange', syncTime);
  audio.addEventListener('timeupdate', () => {
    if (loadedIndex === index && audio.currentTime > progressStart + 1) failedTracks.clear();
    syncTime(); persist();
  });
  audio.addEventListener('play', syncPlaying);
  audio.addEventListener('playing', syncPlaying);
  audio.addEventListener('pause', () => { syncPlaying(); persist(true); });
  audio.addEventListener('ended', () => { syncPlaying(); advance(1, true); });
  audio.addEventListener('error', () => {
    if (loadedIndex < 0) return;
    audio.pause();
    failTrack(playbackError('temporary', '播放地址已失效，正在重新获取。'));
  });
  window.addEventListener('pagehide', () => persist(true));
  try {
    if ('mediaSession' in navigator) {
      const actions = { play: () => void play(), pause, stop, previoustrack: () => advance(-1), nexttrack: () => advance(1), seekto: (details) => {
        if (loadedIndex === index && Number.isFinite(audio.duration) && Number.isFinite(details.seekTime)) {
          audio.currentTime = clamp(details.seekTime, 0, audio.duration); syncTime(); persist(true);
        }
      } };
      Object.entries(actions).forEach(([action, handler]) => { try { navigator.mediaSession.setActionHandler(action, handler); } catch (_) {} });
    }
  } catch (_) {}
  syncTrack();
  syncMode();
  syncPlaying();
  syncVolume();
  setNotice();
  all('[data-music-cover], #sunny-music-dialog img').forEach((image) => {
    if (image.complete && image.getAttribute('src') && image.naturalWidth === 0) image.hidden = true;
  });
})();
