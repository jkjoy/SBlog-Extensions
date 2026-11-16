(() => {
  'use strict';
  const clock = document.getElementById('retro-clock');
  if (!clock) return;
  const tick = () => {
    const now = new Date();
    clock.textContent = [now.getHours(), now.getMinutes(), now.getSeconds()]
      .map((value) => String(value).padStart(2, '0')).join(':');
    clock.dateTime = now.toISOString();
  };
  let timer;
  const resume = () => {
    clearInterval(timer);
    if (!document.hidden) {
      tick();
      timer = setInterval(tick, 1000);
    }
  };
  document.addEventListener('visibilitychange', resume);
  resume();
})();
