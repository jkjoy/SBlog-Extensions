(() => {
  'use strict';

  const normalize = (value) => String(value).normalize('NFKC').toLocaleLowerCase();

  const initialize = () => {
    document.querySelectorAll('[data-douban-showcase]').forEach((showcase) => {
      showcase.querySelectorAll('img').forEach((image) => {
        if (image.dataset.doubanFallbackReady === 'true') return;
        const showFallback = () => {
          image.hidden = true;
          const placeholder = image.parentElement?.querySelector('[data-douban-cover-placeholder]');
          if (placeholder) placeholder.textContent = '封面暂缺';
        };
        image.addEventListener('error', showFallback, { once: true });
        if (image.complete && image.naturalWidth === 0) showFallback();
        image.dataset.doubanFallbackReady = 'true';
      });

      showcase.querySelectorAll('[data-douban-list]').forEach((list) => {
        if (list.dataset.doubanReady === 'true') return;
        const controls = list.querySelector('[data-douban-controls]');
        const search = list.querySelector('[data-douban-search]');
        const items = list.querySelector('[data-douban-items]');
        const pagination = list.querySelector('[data-douban-pagination]');
        const more = list.querySelector('[data-douban-more]');
        const result = list.querySelector('[data-douban-result]');
        const noResults = list.querySelector('[data-douban-no-results]');
        if (!controls || !search || !items || !pagination || !more || !result || !noResults) return;

        const records = Array.from(items.querySelectorAll('[data-douban-item]'));
        const searchable = records.map((element) => ({ element, text: normalize(element.dataset.search || '') }));
        const pageSize = Math.max(1, Math.min(60, Number(list.dataset.pageSize) || 12));
        let visibleLimit = pageSize;
        let matches = searchable;

        const render = () => {
          const query = normalize(search.value.trim());
          matches = searchable.filter((record) => record.text.includes(query));
          records.forEach((record) => { record.hidden = true; });
          matches.slice(0, visibleLimit).forEach((record) => { record.element.hidden = false; });
          const visibleCount = Math.min(visibleLimit, matches.length);
          result.textContent = query
            ? `找到 ${matches.length} 条，已显示 ${visibleCount} 条`
            : `已显示 ${visibleCount} / ${records.length} 条已同步记录`;
          more.hidden = visibleCount >= matches.length;
          noResults.hidden = matches.length !== 0;
          items.hidden = matches.length === 0;
        };

        search.addEventListener('input', () => {
          visibleLimit = pageSize;
          render();
        });
        more.addEventListener('click', () => {
          const previousCount = Math.min(visibleLimit, matches.length);
          const shouldMoveFocus = document.activeElement === more;
          visibleLimit += pageSize;
          render();
          // A disappearing final-batch button must not discard keyboard focus.
          if (more.hidden && shouldMoveFocus) {
            const firstNewItem = matches[previousCount]?.element;
            const focusTarget = firstNewItem?.querySelector('a') || firstNewItem || search;
            focusTarget.focus({ preventScroll: true });
          }
        });

        render();
        list.dataset.doubanReady = 'true';
        controls.hidden = false;
        pagination.hidden = false;
      });
    });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initialize, { once: true });
  } else {
    initialize();
  }
})();
