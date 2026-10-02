(() => {
  'use strict';
  const receipt = document.querySelector('[data-paid-reading-status]');
  if (!receipt) return;
  const status = receipt.querySelector('[role="status"]');
  const endpoint = new URL(receipt.dataset.paidReadingStatus, window.location.href);
  if (endpoint.origin !== window.location.origin) return;
  let attempts = 0;
  const poll = async () => {
    if (++attempts > 120) {
      if (status) status.textContent = '确认时间较长，请稍后手动刷新订单状态。';
      return;
    }
    try {
      const response = await fetch(endpoint, { credentials: 'same-origin', cache: 'no-store' });
      if (!response.ok) return;
      const data = await response.json();
      if (['paid', 'revoked', 'expired'].includes(data.status)) {
        window.location.reload();
        return;
      }
    } catch (_) {
      // The manual refresh link remains available when a network request fails.
    }
    window.setTimeout(poll, 3000);
  };
  window.setTimeout(poll, 1500);
})();
