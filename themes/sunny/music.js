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

  audio.dataset.sunnyMusicReady = 'true';
  audio.preload = 'none';
  audio.loop = false;
  const all = (selector) => Array.from(document.querySelectorAll(selector));
  const names = all('[data-music-name]');
  const artists = all('[data-music-artist]');
  const covers = all('[data-music-cover]');
  const toggles = all('[data-music-toggle]');
  const progress = all('[data-music-progress]');
  const currentTimes = all('[data-music-current-time]');
  const durations = all('[data-music-duration]');
  const volumes = all('[data-music-volume]');
  const modeButtons = all('[data-music-mode]');
  const notices = all('[data-music-notice]');
  const trackButtons = all('[data-music-track]');
  const dock = document.querySelector('.sunny-music-dock');
  const modes = ['list', 'single', 'random'];
  const modeLabels = { list: '列表循环', single: '单曲循环', random: '随机播放' };
  const storageKey = 'sunny-music-state';
  const playlistId = String(config.id || '');
  const initialNotice = notices.map((notice) => notice.textContent);
  let mode = modes.includes(config.mode) ? config.mode : 'list';
  let index = 0;
  let restoreTime = 0;
  let loadedIndex = -1;
  let request = 0;
  let lastSaved = 0;
  let opener = null;
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
  const setNotice = (text = '') => notices.forEach((notice, position) => { notice.textContent = text || initialNotice[position]; });
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
    currentTimes.forEach((element) => { element.textContent = timeText(current); });
    durations.forEach((element) => { element.textContent = timeText(duration || tracks[index].duration); });
    progress.forEach((input) => {
      input.disabled = duration === 0;
      input.value = duration > 0 ? String(clamp(current / duration * 100, 0, 100)) : '0';
      input.setAttribute('aria-valuetext', `${timeText(current)} / ${timeText(duration || tracks[index].duration)}`);
    });
  };
  const syncPlaying = () => {
    const active = playing();
    document.body.classList.toggle('sunny-music-playing', active);
    toggles.forEach((button) => {
      const icon = button.querySelector('[data-music-toggle-icon]');
      if (icon) icon.textContent = active ? 'Ⅱ' : '▶';
      button.setAttribute('aria-label', active ? '暂停播放' : '播放音乐');
      button.setAttribute('title', active ? '暂停播放' : '播放音乐');
      button.setAttribute('aria-pressed', String(active));
    });
    try { if ('mediaSession' in navigator) navigator.mediaSession.playbackState = active ? 'playing' : 'paused'; } catch (_) {}
  };
  const syncMode = () => modeButtons.forEach((button) => {
    button.textContent = modeLabels[mode];
    button.setAttribute('aria-label', `${modeLabels[mode]}，点击切换播放模式`);
    button.setAttribute('title', '切换播放模式');
  });
  const syncTrack = () => {
    const track = tracks[index];
    names.forEach((element) => { element.textContent = track.name; element.title = track.name; });
    artists.forEach((element) => { element.textContent = track.artist; element.title = track.artist; });
    covers.forEach((image) => {
      image.hidden = !track.cover;
      if (track.cover) { image.src = track.cover; } else { image.removeAttribute('src'); }
    });
    trackButtons.forEach((button) => {
      const current = Number(button.dataset.musicTrack) === track.sourceIndex;
      if (current) button.setAttribute('aria-current', 'true'); else button.removeAttribute('aria-current');
      button.disabled = !tracks.some((item) => item.sourceIndex === Number(button.dataset.musicTrack));
    });
    try {
      if ('mediaSession' in navigator && typeof MediaMetadata === 'function') {
        navigator.mediaSession.metadata = new MediaMetadata({ title: track.name, artist: track.artist, album: String(config.name || ''), artwork: track.cover ? [{ src: track.cover }] : [] });
      }
    } catch (_) {}
    syncTime();
  };

  const applyRestoreTime = () => {
    if (loadedIndex !== index || restoreTime <= 0 || !Number.isFinite(audio.duration) || audio.duration <= 0) return;
    try { audio.currentTime = clamp(restoreTime, 0, Math.max(0, audio.duration - .1)); restoreTime = 0; } catch (_) {}
  };
  const play = async () => {
    const token = ++request;
    if (loadedIndex !== index) {
      audio.src = tracks[index].url;
      loadedIndex = index;
      audio.load();
    }
    applyRestoreTime();
    if (dock) dock.hidden = false;
    setNotice();
    try {
      await audio.play();
      if (token !== request) return;
      syncPlaying();
    } catch (error) {
      if (token !== request) return;
      if (error?.name === 'AbortError') return;
      audio.pause();
      setNotice(error?.name === 'NotAllowedError' ? '请点击播放按钮开始播放。' : '此歌曲暂时无法播放，可切换下一首或在网易云查看');
      syncPlaying();
    }
  };
  const pause = () => {
    request++;
    audio.pause();
    syncPlaying();
    persist(true);
  };
  const selectTrack = (next, autoplay = true) => {
    request++;
    audio.pause();
    index = clamp(next, 0, tracks.length - 1);
    loadedIndex = -1;
    restoreTime = 0;
    audio.removeAttribute('src');
    audio.load();
    syncTrack();
    syncPlaying();
    persist(true);
    if (autoplay) void play();
  };
  const randomIndex = () => tracks.length < 2 ? index : (index + 1 + Math.floor(Math.random() * (tracks.length - 1))) % tracks.length;
  const advance = (direction = 1, automatic = false) => {
    const next = automatic && mode === 'single' ? index : mode === 'random' ? randomIndex() : (index + direction + tracks.length) % tracks.length;
    selectTrack(next);
  };
  const stop = () => {
    request++;
    audio.pause();
    loadedIndex = -1;
    restoreTime = 0;
    audio.removeAttribute('src');
    audio.load();
    if (dock) dock.hidden = true;
    setNotice();
    syncTime();
    syncPlaying();
    persist(true);
  };

  all('[data-music-open]').forEach((button) => button.addEventListener('click', () => {
    if (dialog.open) return;
    opener = document.activeElement;
    if (typeof dialog.showModal === 'function') dialog.showModal(); else dialog.setAttribute('open', '');
  }));
  const closeDialog = () => {
    if (typeof dialog.close === 'function') dialog.close(); else { dialog.removeAttribute('open'); opener?.focus({ preventScroll: true }); }
  };
  all('[data-music-close]').forEach((button) => button.addEventListener('click', closeDialog));
  dialog.addEventListener('click', (event) => {
    if (event.target !== dialog) return;
    const bounds = dialog.getBoundingClientRect();
    if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) closeDialog();
  });
  dialog.addEventListener('close', () => {
    if (opener?.isConnected) opener.focus({ preventScroll: true });
  });
  toggles.forEach((button) => button.addEventListener('click', () => { if (playing()) pause(); else void play(); }));
  all('[data-music-prev]').forEach((button) => button.addEventListener('click', () => advance(-1)));
  all('[data-music-next]').forEach((button) => button.addEventListener('click', () => advance(1)));
  all('[data-music-stop]').forEach((button) => button.addEventListener('click', stop));
  modeButtons.forEach((button) => button.addEventListener('click', () => { mode = modes[(modes.indexOf(mode) + 1) % modes.length]; syncMode(); persist(true); }));
  trackButtons.forEach((button) => button.addEventListener('click', () => {
    const next = tracks.findIndex((track) => track.sourceIndex === Number(button.dataset.musicTrack));
    if (next >= 0) selectTrack(next);
  }));
  progress.forEach((input) => input.addEventListener('input', () => {
    if (loadedIndex !== index || !Number.isFinite(audio.duration) || audio.duration <= 0) return;
    audio.currentTime = audio.duration * clamp(Number(input.value) || 0, 0, 100) / 100;
    restoreTime = 0;
    syncTime();
    persist(true);
  }));
  volumes.forEach((input) => {
    input.value = String(Math.round(volume * 100));
    input.addEventListener('input', () => {
      audio.volume = clamp((Number(input.value) || 0) / 100, 0, 1);
      volumes.forEach((other) => { other.value = input.value; });
      persist(true);
    });
  });
  [...new Set([...covers, ...dialog.querySelectorAll('img')])].forEach((image) => {
    image.addEventListener('error', () => { image.hidden = true; });
    if (image.complete && image.getAttribute('src') && image.naturalWidth === 0) image.hidden = true;
  });
  audio.addEventListener('loadedmetadata', () => { applyRestoreTime(); syncTime(); });
  audio.addEventListener('durationchange', syncTime);
  audio.addEventListener('timeupdate', () => { syncTime(); persist(); });
  audio.addEventListener('play', syncPlaying);
  audio.addEventListener('playing', syncPlaying);
  audio.addEventListener('pause', () => { syncPlaying(); persist(true); });
  audio.addEventListener('ended', () => { syncPlaying(); advance(1, true); });
  audio.addEventListener('error', () => {
    if (loadedIndex < 0) return;
    request++;
    audio.pause();
    setNotice('此歌曲暂时无法播放，可切换下一首或在网易云查看');
    syncPlaying();
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
})();
