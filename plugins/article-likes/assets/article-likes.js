(() => {
  'use strict';
  if (window.articleLikesReady) return;
  window.articleLikesReady = true;

  const pending = new Set();
  let status = document.querySelector('[data-article-likes-status]');
  let statusTimer;
  const buttonsFor = (postId) => Array.from(document.querySelectorAll('[data-article-like]'))
    .filter((button) => button.dataset.postId === postId);

  const announce = (message) => {
    if (!status) {
      status = document.createElement('p');
      status.className = 'article-likes-status';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      document.body.append(status);
    }
    clearTimeout(statusTimer);
    status.textContent = message;
    statusTimer = setTimeout(() => { status.textContent = ''; }, 6000);
  };

  const update = (postId, state) => {
    buttonsFor(postId).forEach((button) => {
      button.setAttribute('aria-pressed', state.liked ? 'true' : 'false');
      button.setAttribute('aria-label', state.liked ? '已点赞这篇文章' : '点赞这篇文章');
      const label = button.querySelector('[data-like-label]');
      const count = button.querySelector('[data-like-count]');
      if (label) label.textContent = state.liked ? '已点赞' : '点赞';
      if (count) count.textContent = String(state.count);
    });
    document.dispatchEvent(new CustomEvent('article-likes:updated', {
      detail: { postId: Number(postId), count: state.count, liked: state.liked },
    }));
  };

  document.addEventListener('click', async (event) => {
    const button = event.target instanceof Element ? event.target.closest('[data-article-like]') : null;
    if (!button || button.disabled) return;
    event.preventDefault();
    const postId = button.dataset.postId;
    if (!/^[1-9]\d*$/.test(postId || '') || pending.has(postId)
      || button.getAttribute('aria-pressed') === 'true') return;

    pending.add(postId);
    const previousDisabled = new Map();
    buttonsFor(postId).forEach((item) => {
      previousDisabled.set(item, item.disabled);
      item.disabled = true;
      item.setAttribute('aria-busy', 'true');
    });
    try {
      const response = await fetch(button.dataset.likeEndpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8', Accept: 'application/json' },
        body: new URLSearchParams({ post_id: postId, csrf_token: button.dataset.likeCsrf || '' }),
      });
      const result = await response.json().catch(() => null);
      if (!response.ok || !result?.ok) {
        throw new Error(typeof result?.error === 'string' ? result.error : '点赞未能保存，请稍后重试。');
      }
      if (Number(result.post_id) !== Number(postId) || !Number.isSafeInteger(result.count)
        || result.count < 0 || result.liked !== true) {
        throw new Error('点赞未能保存，请刷新页面后重试。');
      }
      update(postId, result);
    } catch (error) {
      announce(error instanceof Error && !(error instanceof TypeError) ? error.message : '网络连接失败，请稍后重试。');
    } finally {
      pending.delete(postId);
      previousDisabled.forEach((disabled, item) => {
        item.disabled = disabled;
        item.removeAttribute('aria-busy');
      });
    }
  });
})();
