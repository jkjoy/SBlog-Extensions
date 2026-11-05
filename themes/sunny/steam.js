(() => {
  'use strict';

  const initialize = () => {
    if (!document.body.classList.contains('sunny-theme')) return;

    document.querySelectorAll('[data-sunny-steam-avatar] img').forEach((image) => {
      if (image.dataset.sunnySteamAvatarReady === 'true') return;
      image.dataset.sunnySteamAvatarReady = 'true';
      const hideFailedImage = () => { image.hidden = true; };
      image.addEventListener('error', hideFailedImage, { once: true });
      if (image.complete && image.naturalWidth === 0) hideFailedImage();
    });

    document.querySelectorAll('[data-steam-showcase]').forEach((showcase) => {
      const profile = showcase.querySelector('.steam-showcase__profile');
      const stats = profile?.nextElementSibling;
      if (stats?.classList.contains('steam-showcase__stats') && !profile.closest('.sunny-steam-hero')) {
        const hero = document.createElement('div');
        hero.className = 'sunny-steam-hero';
        profile.before(hero);
        hero.append(profile, stats);
      }
    });

    document.querySelectorAll('[data-steam-library]').forEach((library) => {
      if (library.dataset.steamReady === 'true' || library.closest('.steam-showcase--compact')) return;

      const controls = library.querySelector('[data-steam-controls]');
      const search = library.querySelector('[data-steam-search]');
      const sort = library.querySelector('[data-steam-sort]');
      const games = library.querySelector('[data-steam-games]');
      const more = library.querySelector('[data-steam-more]');
      const result = library.querySelector('[data-steam-result]');
      const noResults = library.querySelector('[data-steam-no-results]');
      const pagination = library.querySelector('[data-steam-pagination]');
      if (!controls || !search || !sort || !games || !result || !noResults || !pagination) return;

      const pageSize = 48;
      const cards = Array.from(games.querySelectorAll('[data-steam-game]'));
      const sortOptions = [['minutes', '总时长'], ['recent', '近两周'], ['name', '名称']];
      let sortMode = sortOptions.some(([value]) => value === sort.value) ? sort.value : 'minutes';
      let page = 1;

      const sortGroup = document.createElement('div');
      sortGroup.className = 'sunny-steam-sort';
      sortGroup.setAttribute('role', 'group');
      sortGroup.setAttribute('aria-label', '游戏排序');
      const sortButtons = sortOptions.map(([value, label]) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.sunnySteamSort = value;
        button.textContent = label;
        sortGroup.appendChild(button);
        return button;
      });

      const pages = document.createElement('nav');
      pages.className = 'sunny-steam-pages';
      pages.setAttribute('aria-label', '游戏收藏分页');

      const pageButton = (value, label, options = {}) => {
        const button = document.createElement('button');
        button.type = 'button';
        button.dataset.sunnySteamPage = String(value);
        button.textContent = label;
        button.disabled = Boolean(options.disabled);
        if (options.step) button.dataset.sunnySteamStep = options.step;
        if (options.current) button.setAttribute('aria-current', 'page');
        if (!options.step) button.setAttribute('aria-label', `第 ${value} 页`);
        return button;
      };

      const renderPages = (totalPages, matchCount) => {
        const fragment = document.createDocumentFragment();
        if (matchCount > 0) {
          fragment.appendChild(pageButton(Math.max(1, page - 1), '上一页', { step: 'previous', disabled: page === 1 }));
          const visible = new Set([1, totalPages]);
          for (let value = Math.max(1, page - 2); value <= Math.min(totalPages, page + 2); value++) visible.add(value);
          let previous = 0;
          Array.from(visible).sort((a, b) => a - b).forEach((value) => {
            if (previous > 0 && value - previous > 1) {
              const ellipsis = document.createElement('span');
              ellipsis.className = 'sunny-steam-pages-gap';
              ellipsis.textContent = '…';
              ellipsis.setAttribute('aria-hidden', 'true');
              fragment.appendChild(ellipsis);
            }
            fragment.appendChild(pageButton(value, String(value), { current: value === page }));
            previous = value;
          });
          fragment.appendChild(pageButton(Math.min(totalPages, page + 1), '下一页', { step: 'next', disabled: page === totalPages }));
        }
        pages.replaceChildren(fragment);
        pages.hidden = totalPages < 2 || matchCount === 0;
      };

      const number = (card, key) => {
        const value = Number(card.dataset[key]);
        return Number.isFinite(value) ? Math.max(0, value) : 0;
      };

      const render = () => {
        const query = search.value.trim().toLocaleLowerCase();
        const matches = cards.filter((card) => (card.dataset.name || '').toLocaleLowerCase().includes(query));
        matches.sort((a, b) => {
          const byName = () => (a.dataset.name || '').localeCompare(b.dataset.name || '', 'zh-CN');
          if (sortMode === 'name') return byName();
          const key = sortMode === 'recent' ? 'recent' : 'minutes';
          return number(b, key) - number(a, key) || byName();
        });
        const totalPages = Math.max(1, Math.ceil(matches.length / pageSize));
        page = Math.max(1, Math.min(page, totalPages));
        const first = (page - 1) * pageSize;
        const last = Math.min(first + pageSize, matches.length);
        const fragment = document.createDocumentFragment();
        cards.forEach((card) => { card.hidden = true; });
        matches.forEach((card, index) => {
          card.hidden = index < first || index >= last;
          fragment.appendChild(card);
        });
        games.appendChild(fragment);
        games.hidden = matches.length === 0;
        noResults.hidden = matches.length !== 0;
        result.textContent = matches.length === 0 ? '没有找到匹配的游戏' : `${query ? `找到 ${matches.length} 款` : `共 ${cards.length} 款游戏`} · 显示 ${first + 1}–${last} 款`;
        sort.value = sortMode;
        sortButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.sunnySteamSort === sortMode)));
        renderPages(totalPages, matches.length);
      };

      search.addEventListener('input', () => { page = 1; render(); });
      sortGroup.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-sunny-steam-sort]');
        if (!button || !sortGroup.contains(button)) return;
        sortMode = button.dataset.sunnySteamSort;
        page = 1;
        render();
      });
      pages.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-sunny-steam-page]');
        if (!button || !pages.contains(button) || button.disabled) return;
        const targetPage = Number(button.dataset.sunnySteamPage);
        if (!Number.isInteger(targetPage) || targetPage < 1 || targetPage === page) return;
        const step = button.dataset.sunnySteamStep;
        page = targetPage;
        render();
        // The pager is rebuilt, so restore focus to the same control or numeric page.
        const nextFocus = (step && pages.querySelector(`button[data-sunny-steam-step="${step}"]:not(:disabled)`))
          || pages.querySelector(`button[data-sunny-steam-page="${page}"][aria-current="page"]`);
        nextFocus?.focus({ preventScroll: true });
        controls.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
      });

      controls.appendChild(sortGroup);
      pagination.appendChild(pages);
      render();
      (sort.closest('label') || sort).hidden = true;
      if (more) more.hidden = true;
      library.dataset.steamReady = 'true';
      controls.hidden = false;
      pagination.hidden = false;
    });
  };

  document.addEventListener('sunny:page-load', initialize);
  initialize();
})();
