(() => {
  'use strict';
  document.querySelectorAll('[data-push-qr]').forEach((container) => {
    try {
      const qr = qrcode(0, 'M');
      qr.addData(container.dataset.pushQr, 'Byte');
      qr.make();
      container.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 16, scalable: true });
      const svg = container.querySelector('svg');
      svg.setAttribute('role', 'img');
      svg.setAttribute('aria-label', '微信登录二维码');
      svg.style.display = 'block';
      svg.style.width = '100%';
      svg.style.maxWidth = '320px';
      svg.style.height = 'auto';
    } catch {
      container.textContent = '无法生成二维码，请重新获取，或打开下方微信官方登录链接。';
    }
  });
})();
