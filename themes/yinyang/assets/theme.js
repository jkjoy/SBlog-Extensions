/* YinYang appearance: initialize before paint, then bind the original moon/sun toggle. */
(() => {
  const root = document.documentElement;
  const system = window.matchMedia('(prefers-color-scheme: dark)');
  const key = 'yinyang-theme';
  let preference = null;
  try { preference = localStorage.getItem(key); } catch (_) { /* Storage is optional. */ }
  if (preference !== 'light' && preference !== 'dark') preference = null;

  const apply = () => {
    const dark = preference ? preference === 'dark' : system.matches;
    if (dark) root.dataset.theme = 'dark';
    else root.removeAttribute('data-theme');
    const button = document.getElementById('theme-toggle');
    if (button) {
      button.textContent = dark ? '☀️' : '🌙';
      button.setAttribute('aria-pressed', String(dark));
      const label = dark ? button.dataset.lightLabel : button.dataset.darkLabel;
      button.setAttribute('aria-label', label);
      button.title = label;
    }
    document.getElementById('yinyang-theme-color')?.setAttribute('content', dark ? '#1a1a1a' : '#ffffff');
  };
  apply();
  document.addEventListener('DOMContentLoaded', () => {
    apply();
    document.getElementById('theme-toggle')?.addEventListener('click', () => {
      preference = root.dataset.theme === 'dark' ? 'light' : 'dark';
      try { localStorage.setItem(key, preference); } catch (_) { /* Keep the choice in memory. */ }
      apply();
    });
  });
  system.addEventListener('change', () => { if (!preference) apply(); });
  window.addEventListener('storage', (event) => {
    if (event.key !== key && event.key !== null) return;
    preference = event.newValue === 'dark' || event.newValue === 'light' ? event.newValue : null;
    apply();
  });
})();
