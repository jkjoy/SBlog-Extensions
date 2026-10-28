/* Kratos for Simple PHP Blog. GPL-3.0; original theme by Seaton Jiang. */
(() => {
  'use strict';
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const toggle = document.getElementById('navbutton');
  const menu = document.getElementById('navbarResponsive');
  const setMenu = open => {
    if (!toggle || !menu) return;
    menu.classList.toggle('show', open);
    toggle.classList.toggle('nav-close', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.setAttribute('aria-label', open ? '收起菜单' : '展开菜单');
  };
  toggle?.addEventListener('click', () => setMenu(toggle.getAttribute('aria-expanded') !== 'true'));
  document.addEventListener('keydown', event => { if (event.key === 'Escape') setMenu(false); });
  document.querySelector('[data-kratos-back]')?.addEventListener('click', () => {
    if (window.history.length > 1) window.history.back();
    else window.location.assign(document.querySelector('.back-index').href);
  });
  document.querySelectorAll('.navbar-nav .dropdown-toggle').forEach(link => {
    link.addEventListener('click', event => {
      if (window.innerWidth >= 992) return;
      event.preventDefault();
      const open = link.getAttribute('aria-expanded') !== 'true';
      link.setAttribute('aria-expanded', String(open));
      link.nextElementSibling?.classList.toggle('show', open);
    });
  });

  const gotop = document.querySelector('.gotop');
  const updateScroll = () => gotop?.classList.toggle('active', window.scrollY > 200);
  updateScroll();
  window.addEventListener('scroll', updateScroll, { passive: true });
  gotop?.querySelector('button')?.addEventListener('click', () => window.scrollTo({ top: 0, behavior: reducedMotion ? 'instant' : 'smooth' }));

  const search = document.querySelector('.f-toolbox .search');
  const searchToggle = search?.querySelector('[data-kratos-search]');
  const searchForm = search?.querySelector('form');
  const searchInput = searchForm?.querySelector('input[type="search"]');
  const setSearch = open => {
    if (!search || !searchForm || !searchInput) return;
    search.classList.toggle('is-open', open);
    searchForm.style.width = open ? '200px' : '0';
    searchInput.style.display = open ? 'block' : 'none';
    searchToggle.setAttribute('aria-expanded', String(open));
    if (open) searchInput.focus();
  };
  searchToggle?.addEventListener('click', () => setSearch(!search.classList.contains('is-open')));
  document.addEventListener('click', event => { if (search && !search.contains(event.target)) setSearch(false); });
  search?.addEventListener('keydown', event => {
    if (event.key === 'Escape') { setSearch(false); searchToggle.focus(); }
  });

  const tabs = [...document.querySelectorAll('[data-kratos-tab]')];
  const selectTab = tab => {
    tabs.forEach(item => {
      const selected = item === tab;
      item.classList.toggle('active', selected);
      item.setAttribute('aria-selected', String(selected));
      item.tabIndex = selected ? 0 : -1;
      const panel = document.getElementById(item.getAttribute('aria-controls'));
      if (panel) {
        panel.hidden = !selected;
        panel.classList.toggle('show', selected);
        panel.classList.toggle('active', selected);
      }
    });
  };
  tabs.forEach((tab, index) => {
    tab.addEventListener('click', event => { event.preventDefault(); selectTab(tab); });
    tab.addEventListener('keydown', event => {
      if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
      selectTab(tabs[next]); tabs[next].focus();
    });
  });

  let toastTimer;
  const toast = message => {
    let status = document.querySelector('.kratos-toast');
    if (!status) {
      status = document.createElement('div');
      status.className = 'kratos-toast';
      status.setAttribute('role', 'status');
      status.setAttribute('aria-live', 'polite');
      document.body.append(status);
    }
    clearTimeout(toastTimer);
    status.textContent = message;
    status.hidden = false;
    toastTimer = setTimeout(() => { status.hidden = true; }, 2600);
  };
  document.querySelectorAll('[data-kratos-like]').forEach(button => {
    button.addEventListener('click', async () => {
      if (button.classList.contains('done')) { toast('您已经赞过这篇文章了'); return; }
      if (button.disabled) return;
      button.disabled = true;
      try {
        const response = await fetch(button.dataset.url, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8', 'Accept': 'application/json' },
          body: new URLSearchParams({ post_id: button.dataset.postId, csrf_token: button.dataset.token })
        });
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(result.error || '暂时无法点赞，请稍后重试');
        button.classList.add('done');
        button.setAttribute('aria-pressed', 'true');
        button.querySelector('.ml-1').textContent = '已点赞';
        document.querySelectorAll('[data-kratos-like-count]').forEach(count => { count.textContent = result.count; });
        toast(result.created ? '感谢您的支持！' : '您已经赞过这篇文章了');
      } catch (error) { toast(error.message || '网络连接失败，请稍后重试'); }
      finally { button.disabled = false; }
    });
  });

  document.querySelectorAll('.accordion .acheader').forEach(header => header.addEventListener('click', event => {
    event.preventDefault();
    const accordion = header.closest('.accordion');
    const open = accordion.classList.toggle('active');
    header.setAttribute('aria-expanded', String(open));
    const contents = accordion.querySelector('.contents');
    if (contents) contents.hidden = !open;
  }));

  const article = document.querySelector('.article .content');
  const toc = document.querySelector('[data-kratos-toc]');
  if (article && toc) {
    const headings = [...article.querySelectorAll('h2,h3,h4')];
    const list = document.createElement('ul');
    list.className = 'ul-toc';
    headings.forEach((heading, index) => {
      if (!heading.id) heading.id = `kratos-section-${index + 1}`;
      const item = document.createElement('li');
      item.className = `li-${heading.tagName.slice(1)}`;
      const link = document.createElement('a');
      link.href = `#${heading.id}`; link.textContent = heading.textContent;
      item.style.paddingLeft = `${(Number(heading.tagName.slice(1)) - 2) * 12}px`;
      item.append(link); list.append(item);
    });
    if (headings.length) { toc.querySelector('.item').append(list); toc.hidden = false; }
  }

  if (article && document.body.dataset.kratosLightbox === '1') {
    const images = [...article.querySelectorAll('img')].filter(img => {
      const link = img.closest('a');
      return !link || /\.(?:jpe?g|png|gif|webp|avif|bmp)(?:[?#]|$)/i.test(link.href);
    });
    if (!images.length) return;
    if (typeof window.lightGallery === 'function') {
      images.forEach(img => {
        let link = img.closest('a');
        if (!link) {
          link = document.createElement('a');
          link.href = img.currentSrc || img.src;
          img.before(link); link.append(img);
        }
        link.dataset.kratosGallery = '';
        link.setAttribute('aria-label', img.alt ? `预览：${img.alt}` : '预览图片');
      });
      window.lightGallery(article, { selector: '[data-kratos-gallery]' });
      return;
    }
    if (typeof HTMLDialogElement === 'undefined') return;
    const dialog = document.createElement('dialog');
    dialog.className = 'kratos-lightbox';
    dialog.setAttribute('aria-label', '图片预览');
    const picture = document.createElement('img');
    const caption = document.createElement('p');
    const controls = [['close', '关闭图片预览', '×'], ['prev', '上一张图片', '‹'], ['next', '下一张图片', '›']];
    controls.forEach(([action, label, symbol]) => {
      const button = document.createElement('button');
      button.type = 'button'; button.dataset[`lightbox${action[0].toUpperCase()}${action.slice(1)}`] = '';
      button.setAttribute('aria-label', label); button.textContent = symbol;
      dialog.append(button);
    });
    dialog.append(picture, caption); document.body.append(dialog);
    let current = 0;
    const show = index => {
      current = (index + images.length) % images.length;
      const img = images[current];
      picture.src = img.closest('a')?.href || img.currentSrc || img.src;
      picture.alt = img.alt;
      caption.textContent = `${img.alt ? img.alt + ' · ' : ''}${current + 1} / ${images.length}`;
      if (!dialog.open) dialog.showModal();
    };
    images.forEach((img, index) => {
      img.style.cursor = 'zoom-in';
      const target = img.closest('a') || img;
      if (target === img) { img.tabIndex = 0; img.setAttribute('role', 'button'); img.setAttribute('aria-label', img.alt ? `预览：${img.alt}` : '预览图片'); }
      target.addEventListener('click', event => {
        if (event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); show(index);
      });
      if (target === img) img.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); show(index); }
      });
    });
    dialog.querySelector('[data-lightbox-close]').addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-lightbox-prev]').addEventListener('click', () => show(current - 1));
    dialog.querySelector('[data-lightbox-next]').addEventListener('click', () => show(current + 1));
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    dialog.addEventListener('keydown', event => {
      if (event.key === 'ArrowLeft') show(current - 1);
      if (event.key === 'ArrowRight') show(current + 1);
    });
  }
})();
