(() => {
  "use strict";

  const text = (key, fallback, variables = {}) => {
    if (typeof window.sblogText === "function") {
      return window.sblogText(key, fallback, variables);
    }

    return Object.entries(variables).reduce(
      (message, [name, value]) => message.replaceAll(`{${name}}`, String(value)),
      fallback,
    );
  };

  const gallery = document.querySelector("#sblog-gallery");
  if (!(gallery instanceof HTMLElement)) return;

  gallery.querySelectorAll(".sblog-gallery__album .sblog-gallery__image").forEach((thumbnail) => {
    if (!(thumbnail instanceof HTMLImageElement)) return;
    const showError = () => {
      thumbnail.closest(".sblog-gallery__album")?.classList.add("is-image-error");
      const fallback = thumbnail.parentElement?.querySelector(".sblog-gallery__image-error");
      if (fallback instanceof HTMLElement) fallback.hidden = false;
    };
    thumbnail.addEventListener("error", showError, { once: true });
    if (thumbnail.complete && thumbnail.naturalWidth === 0) showError();
  });

  const lightbox = document.querySelector("[data-sblog-gallery-lightbox]");
  if (!(lightbox instanceof HTMLElement)) return;

  const openers = Array.from(gallery.querySelectorAll("[data-sblog-gallery-open-item]"));
  if (!openers.length) return;

  const nativeDialog = typeof HTMLDialogElement !== "undefined" && lightbox instanceof HTMLDialogElement;
  const dialogShell = lightbox.querySelector(".sblog-gallery-lightbox__dialog");
  const figure = lightbox.querySelector("figure");
  const media = lightbox.querySelector(".sblog-gallery-lightbox__media");
  const image = lightbox.querySelector("[data-sblog-gallery-lightbox-image]");
  const errorMessage = lightbox.querySelector("[data-sblog-gallery-lightbox-error]");
  const title = lightbox.querySelector("[data-sblog-gallery-lightbox-title]");
  const description = lightbox.querySelector("[data-sblog-gallery-lightbox-description]");
  const original = lightbox.querySelector("[data-sblog-gallery-lightbox-original]");
  const counter = lightbox.querySelector("[data-sblog-gallery-lightbox-counter]");
  const closeButton = lightbox.querySelector("[data-sblog-gallery-lightbox-close]");
  const previousButton = lightbox.querySelector("[data-sblog-gallery-lightbox-previous]");
  const nextButton = lightbox.querySelector("[data-sblog-gallery-lightbox-next]");

  if (!(image instanceof HTMLImageElement) || !(dialogShell instanceof HTMLElement)) return;

  const items = openers.map((opener, index) => {
    const thumbnail = opener.querySelector(".sblog-gallery__image, img");
    const source = opener instanceof HTMLAnchorElement
      ? opener.href
      : String(opener.dataset.url || thumbnail?.currentSrc || thumbnail?.src || "");
    const itemTitle = String(opener.dataset.title || "").trim();
    const alt = thumbnail instanceof HTMLImageElement ? thumbnail.alt.trim() : "";
    return {
      alt,
      description: String(opener.dataset.description || "").trim(),
      index,
      opener,
      source,
      thumbnail,
      title: itemTitle,
    };
  }).filter((item) => item.source !== "");

  if (!items.length) return;

  const state = {
    index: 0,
    loadToken: 0,
    open: false,
    previousFocus: null,
    scrollLock: null,
  };
  const preloaded = new Set();

  const focusableElements = () => Array.from(lightbox.querySelectorAll(
    'a[href], button:not([disabled]):not([hidden]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
  )).filter((element) => {
    if (!(element instanceof HTMLElement) || element.hidden) return false;
    const style = window.getComputedStyle(element);
    return style.display !== "none" && style.visibility !== "hidden" && element.getClientRects().length > 0;
  });

  const setThumbnailError = (item) => {
    if (!(item.thumbnail instanceof HTMLImageElement)) return;
    const card = item.opener.closest(".sblog-gallery__item");
    const fallback = item.opener.querySelector(".sblog-gallery__image-error");
    card?.classList.add("is-image-error");
    if (fallback instanceof HTMLElement) fallback.hidden = false;
  };

  items.forEach((item) => {
    item.opener.setAttribute("aria-haspopup", "dialog");
    if (lightbox.id) item.opener.setAttribute("aria-controls", lightbox.id);
    if (item.thumbnail instanceof HTMLImageElement) {
      item.thumbnail.addEventListener("error", () => setThumbnailError(item), { once: true });
      if (item.thumbnail.complete && item.thumbnail.naturalWidth === 0) setThumbnailError(item);
    }
  });

  const lockPage = () => {
    if (state.scrollLock) return;
    const body = document.body;
    const documentElement = document.documentElement;
    const scrollY = window.scrollY;
    state.scrollLock = {
      bodyOverflow: body.style.overflow,
      bodyPaddingRight: body.style.paddingRight,
      bodyPosition: body.style.position,
      bodyTop: body.style.top,
      bodyWidth: body.style.width,
      htmlOverflow: documentElement.style.overflow,
      scrollY,
    };

    const scrollbar = Math.max(0, window.innerWidth - documentElement.clientWidth);
    body.classList.add("sblog-gallery-lightbox-open");
    body.style.position = "fixed";
    body.style.top = `${-scrollY}px`;
    body.style.width = "100%";
    body.style.overflow = "hidden";
    if (scrollbar > 0) body.style.paddingRight = `${scrollbar}px`;
    documentElement.style.overflow = "hidden";
  };

  const unlockPage = () => {
    if (!state.scrollLock) return;
    const body = document.body;
    const documentElement = document.documentElement;
    const saved = state.scrollLock;
    state.scrollLock = null;
    body.classList.remove("sblog-gallery-lightbox-open");
    body.style.overflow = saved.bodyOverflow;
    body.style.paddingRight = saved.bodyPaddingRight;
    body.style.position = saved.bodyPosition;
    body.style.top = saved.bodyTop;
    body.style.width = saved.bodyWidth;
    documentElement.style.overflow = saved.htmlOverflow;
    window.scrollTo(0, saved.scrollY);
  };

  const preloadAdjacent = () => {
    if (items.length < 2) return;
    const indexes = [
      (state.index - 1 + items.length) % items.length,
      (state.index + 1) % items.length,
    ];
    indexes.forEach((index) => {
      const source = items[index]?.source;
      if (!source || preloaded.has(source)) return;
      preloaded.add(source);
      const preload = new Image();
      preload.decoding = "async";
      preload.src = source;
    });
  };

  const showImageError = (token) => {
    if (token !== state.loadToken) return;
    lightbox.classList.remove("is-loading");
    lightbox.classList.add("is-error");
    media?.setAttribute("aria-busy", "false");
    image.hidden = true;
    if (errorMessage instanceof HTMLElement) {
      errorMessage.hidden = false;
      errorMessage.setAttribute("role", "status");
    }
  };

  const showLoadedImage = (token) => {
    if (token !== state.loadToken) return;
    lightbox.classList.remove("is-loading", "is-error");
    media?.setAttribute("aria-busy", "false");
    image.hidden = false;
    if (errorMessage instanceof HTMLElement) errorMessage.hidden = true;
  };

  const renderItem = (index) => {
    state.index = (index + items.length) % items.length;
    const item = items[state.index];
    const displayTitle = item.title || item.alt || text(
      "gallery_image_number",
      "图片 {count}",
      { count: state.index + 1 },
    );
    const token = state.loadToken + 1;
    state.loadToken = token;

    if (title instanceof HTMLElement) title.textContent = displayTitle;
    if (description instanceof HTMLElement) {
      description.textContent = item.description;
      description.hidden = item.description === "";
    }
    if (original instanceof HTMLAnchorElement) original.href = item.source;
    if (counter instanceof HTMLElement) counter.textContent = `${state.index + 1} / ${items.length}`;

    lightbox.classList.add("is-loading");
    lightbox.classList.remove("is-error");
    media?.setAttribute("aria-busy", "true");
    image.hidden = false;
    image.alt = item.alt || displayTitle;
    if (errorMessage instanceof HTMLElement) errorMessage.hidden = true;
    image.onload = () => showLoadedImage(token);
    image.onerror = () => showImageError(token);
    image.src = item.source;
    if (image.complete) {
      if (image.naturalWidth > 0) showLoadedImage(token);
      else showImageError(token);
    }

    const multiple = items.length > 1;
    if (previousButton instanceof HTMLButtonElement) {
      previousButton.disabled = !multiple;
      previousButton.hidden = !multiple;
    }
    if (nextButton instanceof HTMLButtonElement) {
      nextButton.disabled = !multiple;
      nextButton.hidden = !multiple;
    }
    preloadAdjacent();
  };

  const finishClose = () => {
    if (!state.open) {
      lightbox.hidden = true;
      lightbox.setAttribute("aria-hidden", "true");
      return;
    }

    state.open = false;
    state.loadToken += 1;
    image.onload = null;
    image.onerror = null;
    image.removeAttribute("src");
    image.hidden = false;
    lightbox.classList.remove("is-open", "is-loading", "is-error");
    lightbox.setAttribute("aria-hidden", "true");
    lightbox.hidden = true;
    if (errorMessage instanceof HTMLElement) errorMessage.hidden = true;
    unlockPage();

    if (state.previousFocus instanceof HTMLElement && state.previousFocus.isConnected) {
      state.previousFocus.focus({ preventScroll: true });
    }
    state.previousFocus = null;
  };

  const closeLightbox = () => {
    if (!state.open) return;
    if (nativeDialog && lightbox.open) lightbox.close();
    else finishClose();
  };

  const openLightbox = (index, trigger) => {
    if (state.open) {
      renderItem(index);
      return;
    }

    state.open = true;
    state.previousFocus = trigger instanceof HTMLElement ? trigger : document.activeElement;
    renderItem(index);
    lightbox.hidden = false;
    lightbox.classList.add("is-open");
    lightbox.setAttribute("aria-hidden", "false");
    lightbox.setAttribute("aria-modal", "true");
    if (!nativeDialog) lightbox.setAttribute("role", "dialog");
    lockPage();

    if (nativeDialog && !lightbox.open) {
      try {
        lightbox.showModal();
      } catch (error) {
        lightbox.setAttribute("open", "");
      }
    }
    window.requestAnimationFrame(() => {
      const initialFocus = closeButton || focusableElements()[0];
      if (initialFocus instanceof HTMLElement) initialFocus.focus({ preventScroll: true });
    });
  };

  const showPrevious = () => renderItem(state.index - 1);
  const showNext = () => renderItem(state.index + 1);

  if (lightbox.parentElement !== document.body) document.body.appendChild(lightbox);
  lightbox.hidden = true;
  lightbox.setAttribute("aria-hidden", "true");
  media?.setAttribute("aria-live", "polite");

  items.forEach((item, index) => {
    item.opener.addEventListener("click", (event) => {
      if (
        event.defaultPrevented
        || event.button !== 0
        || event.metaKey
        || event.ctrlKey
        || event.shiftKey
        || event.altKey
      ) return;
      event.preventDefault();
      openLightbox(index, item.opener);
    });
  });

  closeButton?.addEventListener("click", closeLightbox);
  previousButton?.addEventListener("click", showPrevious);
  nextButton?.addEventListener("click", showNext);

  lightbox.addEventListener("cancel", (event) => {
    event.preventDefault();
    closeLightbox();
  });
  if (nativeDialog) lightbox.addEventListener("close", finishClose);

  lightbox.addEventListener("click", (event) => {
    if (
      event.target === lightbox
      || event.target === dialogShell
      || event.target === figure
      || event.target === media
    ) closeLightbox();
  });

  lightbox.addEventListener("keydown", (event) => {
    if (event.key === "Escape") {
      event.preventDefault();
      closeLightbox();
      return;
    }
    if (event.key === "ArrowLeft") {
      event.preventDefault();
      showPrevious();
      return;
    }
    if (event.key === "ArrowRight") {
      event.preventDefault();
      showNext();
      return;
    }
    if (event.key === "Home") {
      event.preventDefault();
      renderItem(0);
      return;
    }
    if (event.key === "End") {
      event.preventDefault();
      renderItem(items.length - 1);
      return;
    }
    if (event.key !== "Tab") return;

    const focusable = focusableElements();
    if (!focusable.length) {
      event.preventDefault();
      lightbox.focus();
      return;
    }
    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    }
  });
})();
