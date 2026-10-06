(() => {
  'use strict';

  document.querySelectorAll('[data-steam-showcase]').forEach((showcase) => {
    showcase.querySelectorAll('img').forEach((img) => {
      const hideFailedImage = () => { img.hidden = true; };
      img.addEventListener('error', hideFailedImage, { once: true });
      if (img.complete && img.naturalWidth === 0) hideFailedImage();
    });

    showcase.querySelectorAll('[data-steam-library]').forEach((library) => {
      if (library.dataset.steamReady === 'true') return;
      const controls = library.querySelector('[data-steam-controls]');
      const search = library.querySelector('[data-steam-search]');
      const sort = library.querySelector('[data-steam-sort]');
      const games = library.querySelector('[data-steam-games]');
      const more = library.querySelector('[data-steam-more]');
      const result = library.querySelector('[data-steam-result]');
      const noResults = library.querySelector('[data-steam-no-results]');
      const pagination = library.querySelector('[data-steam-pagination]');
      if (!controls || !search || !sort || !games || !more || !result || !noResults || !pagination) return;

      const cards = Array.from(games.querySelectorAll('[data-steam-game]'));
      const batchSize = Math.max(1, Math.min(24, Number(library.dataset.pageSize) || 12));
      let shown = batchSize;
      let lastMatchCount = cards.length;
      const compareName = (a, b) => (a.dataset.name || '').localeCompare(b.dataset.name || '', 'zh-CN');
      const render = () => {
        const query = search.value.trim().toLocaleLowerCase();
        const matches = cards.filter((card) => (card.dataset.name || '').toLocaleLowerCase().includes(query));
        matches.sort((a, b) => {
          if (sort.value === 'name') return compareName(a, b);
          const key = sort.value === 'recent' ? 'recent' : 'minutes';
          return Number(b.dataset[key] || 0) - Number(a.dataset[key] || 0) || compareName(a, b);
        });
        cards.forEach((card) => { card.hidden = true; });
        const fragment = document.createDocumentFragment();
        matches.forEach((card, index) => {
          card.hidden = index >= shown;
          fragment.appendChild(card);
        });
        games.appendChild(fragment);
        const visible = Math.min(shown, matches.length);
        result.textContent = query ? `找到 ${matches.length} 款，已显示 ${visible} 款` : `已显示 ${visible} / ${cards.length} 款`;
        more.hidden = visible >= matches.length;
        noResults.hidden = matches.length !== 0;
        games.hidden = matches.length === 0;
        lastMatchCount = matches.length;
      };

      search.addEventListener('input', () => { shown = batchSize; render(); });
      sort.addEventListener('change', () => { shown = batchSize; render(); });
      more.addEventListener('click', () => {
        const previousShown = shown;
        shown += batchSize;
        render();
        // Keep keyboard focus inside the new results when the last batch removes the button.
        if (shown >= lastMatchCount) {
          const visibleCards = Array.from(games.querySelectorAll('[data-steam-game]:not([hidden])'));
          const firstNewLink = visibleCards[previousShown]?.querySelector('a');
          if (firstNewLink) firstNewLink.focus({ preventScroll: true });
        }
      });
      library.dataset.steamReady = 'true';
      controls.hidden = false;
      pagination.hidden = false;
      render();
    });
  });
})();
