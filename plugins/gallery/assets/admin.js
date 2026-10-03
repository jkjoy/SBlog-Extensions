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

  const createElement = (tagName, className = "", content = "") => {
    const element = document.createElement(tagName);
    if (className) element.className = className;
    if (content !== "") element.textContent = content;
    return element;
  };

  const responseJson = async (response, fallbackMessage) => {
    let result;
    try {
      result = await response.json();
    } catch (error) {
      throw new Error(fallbackMessage);
    }

    if (!response.ok || !result || result.ok === false) {
      throw new Error(String(result?.error || fallbackMessage));
    }

    return result;
  };

  const isAddedValue = (value) => value === true || value === 1 || value === "1" || value === "true";

  const mediaIds = (values) => Array.from(new Set(
    (Array.isArray(values) ? values : []).map(Number).filter((id) => Number.isInteger(id) && id > 0),
  ));

  const focusableElements = (container) => Array.from(container.querySelectorAll(
    'a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
  )).filter((element) => {
    if (!(element instanceof HTMLElement) || element.hidden) return false;
    const style = window.getComputedStyle(element);
    return style.display !== "none" && style.visibility !== "hidden" && element.getClientRects().length > 0;
  });

  const initBulkSelection = (root) => {
    const form = root.querySelector("[data-sblog-gallery-bulk-form]");
    if (!(form instanceof HTMLFormElement) || form.dataset.sblogGalleryBulkReady === "1") return;
    form.dataset.sblogGalleryBulkReady = "1";

    const selectAll = form.querySelector("[data-sblog-gallery-select-all]");
    const countLabel = form.querySelector("[data-sblog-gallery-bulk-count]");
    const move = form.querySelector("[data-sblog-gallery-bulk-move]");
    const remove = form.querySelector("[data-sblog-gallery-bulk-remove]");
    const items = Array.from(root.querySelectorAll("[data-sblog-gallery-item-select]"))
      .filter((checkbox) => checkbox instanceof HTMLInputElement && !checkbox.disabled);
    let submitting = false;

    const selectedItems = () => items.filter((checkbox) => checkbox.checked);
    const update = () => {
      const count = selectedItems().length;
      if (selectAll instanceof HTMLInputElement) {
        selectAll.checked = count > 0 && count === items.length;
        selectAll.indeterminate = count > 0 && count < items.length;
        selectAll.disabled = items.length === 0;
      }
      if (countLabel instanceof HTMLElement) {
        countLabel.textContent = count > 0
          ? text("gallery_selected_count", "已选择 {count} 张图片", { count })
          : text("gallery_none_selected", "尚未选择图片");
      }
      [move, remove].forEach((button) => {
        if (button instanceof HTMLButtonElement) button.disabled = count === 0;
      });
      items.forEach((checkbox) => checkbox.closest("article")?.classList.toggle("is-selected", checkbox.checked));
    };

    selectAll?.addEventListener("change", () => {
      items.forEach((checkbox, index) => { checkbox.checked = selectAll.checked && index < 100; });
      update();
    });
    items.forEach((checkbox) => checkbox.addEventListener("change", () => {
      if (selectedItems().length > 100) {
        checkbox.checked = false;
        window.alert(text("gallery_selection_limit", "一次最多选择 {count} 张图片。", { count: 100 }));
      }
      update();
    }));

    form.addEventListener("submit", (event) => {
      const count = selectedItems().length;
      const operation = event.submitter instanceof HTMLButtonElement ? event.submitter.value : "";
      if (submitting || count === 0 || count > 100 || !["move", "remove"].includes(operation)) {
        event.preventDefault();
        return;
      }
      if (operation === "remove" && !window.confirm(text(
        "gallery_bulk_remove_confirm",
        "从图库移除所选 {count} 张图片？媒体库中的原文件会保留。",
        { count },
      ))) {
        event.preventDefault();
        return;
      }
      // Keep the successful submitter enabled so its operation is included in the POST.
      submitting = true;
      form.setAttribute("aria-busy", "true");
    });

    window.addEventListener("pageshow", () => {
      submitting = false;
      form.setAttribute("aria-busy", "false");
      update();
    });
    update();
  };

  const initGalleryAdmin = (root) => {
    if (!(root instanceof HTMLElement) || root.dataset.sblogGalleryReady === "1") return;
    const coverMode = root.hasAttribute("data-sblog-gallery-cover-picker");
    if (!coverMode && root.querySelector("[data-sblog-gallery-cover-picker]")) return;
    root.dataset.sblogGalleryReady = "1";
    initBulkSelection(root);

    const mediaUrl = root.dataset.mediaUrl || "";
    const addUrl = root.dataset.addUrl || "";
    const uploadUrl = root.dataset.uploadUrl || "";
    const csrf = root.dataset.csrf || "";
    const selectionLimit = coverMode ? 1 : Math.max(1, Number(root.dataset.selectionLimit) || 100);
    const dialog = root.querySelector("[data-sblog-gallery-dialog]");
    const openButtons = Array.from(root.querySelectorAll(coverMode
      ? "[data-sblog-gallery-cover-open]"
      : "[data-sblog-gallery-open]"));
    const closeButtons = dialog ? Array.from(dialog.querySelectorAll("[data-sblog-gallery-close]")) : [];
    const searchForm = dialog?.querySelector("[data-sblog-gallery-search]") || null;
    const results = dialog?.querySelector("[data-sblog-gallery-results]") || null;
    const pagination = dialog?.querySelector("[data-sblog-gallery-pagination]") || null;
    const pickerStatus = dialog?.querySelector("[data-sblog-gallery-picker-status]") || null;
    const addButton = dialog?.querySelector("[data-sblog-gallery-add]") || null;
    const selectedCount = dialog?.querySelector("[data-sblog-gallery-selected-count]") || null;
    const uploadInput = dialog?.querySelector("[data-sblog-gallery-upload]") || null;
    const uploadStatus = dialog?.querySelector("[data-sblog-gallery-upload-status]") || null;
    const uploadZone = dialog?.querySelector(
      "[data-sblog-gallery-upload-zone], .sblog-gallery-admin__upload-zone, .attachment-drop",
    ) || null;
    const categorySelect = dialog?.querySelector("[data-sblog-gallery-category]") || null;
    const categoryFixed = categorySelect instanceof HTMLSelectElement && categorySelect.disabled;
    const tabs = dialog ? Array.from(dialog.querySelectorAll("[data-sblog-gallery-picker-tab]")) : [];
    const panels = dialog ? Array.from(dialog.querySelectorAll("[data-sblog-gallery-picker-panel]")) : [];
    const nativeDialog = typeof HTMLDialogElement !== "undefined" && dialog instanceof HTMLDialogElement;

    const state = {
      addBusy: false,
      changed: false,
      controller: null,
      coverForm: null,
      currentItems: new Map(),
      loaded: false,
      loading: false,
      open: false,
      page: 1,
      pages: 1,
      previousFocus: null,
      reloadOnClose: !coverMode && root.dataset.refreshOnAdd === "1",
      retryButtons: new Set(),
      scrollLock: null,
      selected: new Map(),
      uploading: false,
    };

    const setStatus = (message, status = "info") => {
      if (!(pickerStatus instanceof HTMLElement)) return;
      pickerStatus.textContent = message;
      pickerStatus.dataset.state = status;
      pickerStatus.hidden = message === "";
      pickerStatus.setAttribute("role", status === "error" ? "alert" : "status");
    };

    const updateSelection = () => {
      const count = state.selected.size;
      if (selectedCount instanceof HTMLElement) {
        selectedCount.textContent = count > 0
          ? text("gallery_selected_count", "已选择 {count} 张图片", { count })
          : text("gallery_none_selected", "尚未选择图片");
      }
      if (addButton instanceof HTMLButtonElement) {
        addButton.disabled = count === 0 || state.addBusy || state.uploading;
      }
    };

    const setUploading = (busy) => {
      state.uploading = busy;
      if (uploadInput instanceof HTMLInputElement) uploadInput.disabled = busy;
      updateBusyControls();
      updateSelection();
    };

    const updateBusyControls = () => {
      const busy = state.uploading || state.addBusy;
      if (dialog instanceof HTMLElement) dialog.setAttribute("aria-busy", busy ? "true" : "false");
      if (uploadInput instanceof HTMLInputElement) uploadInput.disabled = busy;
      if (categorySelect instanceof HTMLSelectElement) categorySelect.disabled = busy || categoryFixed;
      state.retryButtons.forEach((button) => {
        if (!button.isConnected) state.retryButtons.delete(button);
        else button.disabled = busy;
      });
      closeButtons.forEach((button) => {
        if (button instanceof HTMLButtonElement) button.disabled = busy;
      });
      tabs.forEach((tab) => {
        if (tab instanceof HTMLButtonElement) tab.disabled = busy;
      });
    };

    const markButtonSelection = (button, selected) => {
      if (!(button instanceof HTMLButtonElement)) return;
      const title = button.dataset.mediaTitle || text("gallery_untitled_image", "未命名图片");
      button.setAttribute("aria-pressed", selected ? "true" : "false");
      button.setAttribute(
        "aria-label",
        selected
          ? text("gallery_deselect_image", "取消选择：{title}", { title })
          : text("gallery_select_image", "选择图片：{title}", { title }),
      );
    };

    const setImageFallback = (container, image) => {
      if (!(container instanceof HTMLElement) || !(image instanceof HTMLImageElement)) return;
      container.closest(".sblog-gallery-admin__picker-item")?.classList.add("is-image-error");
      if (container.querySelector(".sblog-gallery-admin__image-fallback")) return;
      const fallback = createElement(
        "span",
        "sblog-gallery-admin__image-fallback",
        text("gallery_image_unavailable", "图片暂时无法加载"),
      );
      fallback.setAttribute("aria-hidden", "true");
      container.appendChild(fallback);
    };

    const normalizeItem = (item) => ({
      added: !coverMode && isAddedValue(item?.added),
      height: Math.max(0, Number(item?.height) || 0),
      id: Math.max(0, Number(item?.id) || 0),
      title: String(item?.title || item?.original_name || item?.alt_text || text("gallery_untitled_image", "未命名图片")),
      url: String(item?.url || ""),
      width: Math.max(0, Number(item?.width) || 0),
    });

    const renderItems = (rawItems) => {
      if (!(results instanceof HTMLElement)) return;
      results.replaceChildren();
      state.currentItems.clear();

      const items = rawItems.map(normalizeItem).filter((item) => item.id > 0 && item.url !== "");
      if (!items.length) {
        const empty = createElement("div", "sblog-gallery-admin__picker-empty");
        const query = searchForm instanceof HTMLFormElement
          ? String(new FormData(searchForm).get("q") || "").trim()
          : "";
        empty.appendChild(createElement(
          "p",
          "",
          query
            ? text("gallery_no_matching_media", "没有找到匹配的图片。")
            : text("gallery_media_empty", "媒体库中还没有可用图片。"),
        ));
        if (query && searchForm instanceof HTMLFormElement) {
          const clear = createElement("button", "button button--secondary", text("gallery_clear_search", "清除搜索"));
          clear.type = "button";
          clear.addEventListener("click", () => {
            searchForm.reset();
            void loadMedia(1, { restoreFocus: true });
          });
          empty.appendChild(clear);
        }
        results.appendChild(empty);
        return;
      }

      const fragment = document.createDocumentFragment();
      items.forEach((item) => {
        state.currentItems.set(item.id, item);
        if (item.added) state.selected.delete(item.id);

        const button = createElement("button", "sblog-gallery-admin__picker-item");
        button.type = "button";
        button.dataset.mediaId = String(item.id);
        button.dataset.mediaTitle = item.title;

        const media = createElement("span", "sblog-gallery-admin__picker-media");
        const image = new Image();
        image.alt = item.title;
        image.loading = "lazy";
        image.decoding = "async";
        if (item.width > 0) image.width = item.width;
        if (item.height > 0) image.height = item.height;
        image.addEventListener("error", () => setImageFallback(media, image), { once: true });
        image.src = item.url;
        media.appendChild(image);

        if (item.added) {
          button.classList.add("is-added");
          button.disabled = true;
          button.setAttribute(
            "aria-label",
            text("gallery_image_already_added", "{title}，已加入图库", { title: item.title }),
          );
          media.appendChild(createElement(
            "span",
            "sblog-gallery-admin__picker-badge",
            text("gallery_added", "已加入"),
          ));
        } else {
          const check = createElement("span", "sblog-gallery-admin__picker-check", "✓");
          check.setAttribute("aria-hidden", "true");
          media.appendChild(check);
          markButtonSelection(button, state.selected.has(item.id));
          button.addEventListener("click", () => {
            if (state.selected.has(item.id)) {
              state.selected.delete(item.id);
              markButtonSelection(button, false);
            } else {
              if (coverMode) {
                state.selected.clear();
                results.querySelectorAll("[data-media-id]").forEach((candidate) => {
                  markButtonSelection(candidate, false);
                });
              }
              if (state.selected.size >= selectionLimit) {
                setStatus(text(
                  "gallery_selection_limit",
                  "一次最多选择 {count} 张图片。",
                  { count: selectionLimit },
                ), "error");
                return;
              }
              state.selected.set(item.id, item);
              markButtonSelection(button, true);
            }
            updateSelection();
          });
        }

        const copy = createElement("span", "sblog-gallery-admin__picker-copy");
        const title = createElement("strong", "", item.title);
        title.title = item.title;
        copy.appendChild(title);
        if (item.width > 0 && item.height > 0) {
          copy.appendChild(createElement("span", "", `${item.width} × ${item.height}`));
        }

        button.append(media, copy);
        fragment.appendChild(button);

        if (image.complete && image.naturalWidth === 0) setImageFallback(media, image);
      });
      results.appendChild(fragment);
      updateSelection();
    };

    const renderLoading = () => {
      if (!(results instanceof HTMLElement)) return;
      results.replaceChildren();
      const fragment = document.createDocumentFragment();
      for (let index = 0; index < 6; index += 1) {
        const skeleton = createElement("span", "sblog-gallery-admin__picker-skeleton");
        skeleton.setAttribute("aria-hidden", "true");
        fragment.appendChild(skeleton);
      }
      results.appendChild(fragment);
    };

    const renderLoadError = (message, retryPage = state.page) => {
      if (!(results instanceof HTMLElement)) return null;
      results.replaceChildren();
      const error = createElement("div", "sblog-gallery-admin__picker-empty");
      error.appendChild(createElement("p", "", message));
      const retry = createElement("button", "button button--secondary", text("gallery_retry", "重试"));
      retry.type = "button";
      retry.addEventListener("click", () => void loadMedia(retryPage, { restoreFocus: true }));
      error.appendChild(retry);
      results.appendChild(error);
      return retry;
    };

    const pageButton = (label, page, options = {}) => {
      const button = createElement("button", "sblog-gallery-admin__page", label);
      button.type = "button";
      button.disabled = Boolean(options.disabled);
      if (options.current) button.setAttribute("aria-current", "page");
      if (options.label) button.setAttribute("aria-label", options.label);
      button.addEventListener("click", () => void loadMedia(page, { restoreFocus: true }));
      return button;
    };

    const renderPagination = (rawPagination = {}) => {
      if (!(pagination instanceof HTMLElement)) return;
      const page = Math.max(1, Number(rawPagination.page) || 1);
      const pages = Math.max(1, Number(rawPagination.pages) || 1);
      state.page = Math.min(page, pages);
      state.pages = pages;
      pagination.replaceChildren();
      pagination.hidden = pages <= 1;
      if (pages <= 1) return;

      pagination.appendChild(pageButton(
        "‹",
        Math.max(1, state.page - 1),
        {
          disabled: state.page <= 1,
          label: text("gallery_previous_page", "上一页"),
        },
      ));

      const pageNumbers = Array.from(new Set([
        1,
        state.page - 1,
        state.page,
        state.page + 1,
        pages,
      ].filter((candidate) => candidate >= 1 && candidate <= pages))).sort((left, right) => left - right);

      let previous = 0;
      pageNumbers.forEach((pageNumber) => {
        if (previous > 0 && pageNumber - previous > 1) {
          const gap = createElement("span", "sblog-gallery-admin__page-gap", "…");
          gap.setAttribute("aria-hidden", "true");
          pagination.appendChild(gap);
        }
        pagination.appendChild(pageButton(
          String(pageNumber),
          pageNumber,
          {
            current: pageNumber === state.page,
            label: text("gallery_go_to_page", "转到第 {page} 页", { page: pageNumber }),
          },
        ));
        previous = pageNumber;
      });

      pagination.appendChild(pageButton(
        "›",
        Math.min(pages, state.page + 1),
        {
          disabled: state.page >= pages,
          label: text("gallery_next_page", "下一页"),
        },
      ));
    };

    const mediaRequestUrl = (page) => {
      const url = new URL(mediaUrl, window.location.href);
      if (searchForm instanceof HTMLFormElement) {
        const fields = new FormData(searchForm);
        fields.forEach((value, name) => {
          if (typeof value !== "string" || name === "page") return;
          if (value === "") url.searchParams.delete(name);
          else url.searchParams.set(name, value);
        });
      }
      url.searchParams.set("page", String(page));
      return url;
    };

    const loadMedia = async (page = 1, options = {}) => {
      if (!(results instanceof HTMLElement)) return;
      const requestedPage = Math.max(1, Number(page) || 1);
      if (!mediaUrl) {
        const message = text("gallery_media_endpoint_missing", "媒体库接口不可用。请刷新页面后重试。");
        setStatus(message, "error");
        const retry = renderLoadError(message, requestedPage);
        if (options.restoreFocus && retry instanceof HTMLElement) {
          retry.focus({ preventScroll: true });
        }
        return;
      }

      state.controller?.abort();
      const controller = new AbortController();
      state.controller = controller;
      state.loading = true;
      results.setAttribute("aria-busy", "true");
      renderLoading();
      setStatus(text("gallery_loading_media", "正在加载媒体库…"));

      try {
        const response = await fetch(mediaRequestUrl(requestedPage), {
          credentials: "same-origin",
          headers: { Accept: "application/json" },
          signal: controller.signal,
        });
        const result = await responseJson(
          response,
          text("gallery_load_media_failed", "媒体库加载失败，请重试。"),
        );
        if (controller.signal.aborted) return;

        const items = Array.isArray(result.items) ? result.items : [];
        const pageData = result.pagination && typeof result.pagination === "object"
          ? result.pagination
          : result;
        renderItems(items);
        renderPagination(pageData);
        if (options.restoreFocus) {
          const currentPage = pagination?.querySelector('[aria-current="page"]');
          const focusTarget = currentPage || results.querySelector("button, [href]") || results;
          if (focusTarget instanceof HTMLElement) {
            if (focusTarget === results) focusTarget.tabIndex = -1;
            focusTarget.focus({ preventScroll: true });
          }
        }
        state.loaded = true;
        const total = Math.max(0, Number(pageData.total) || items.length);
        setStatus(items.length
          ? text("gallery_media_total", "媒体库共 {count} 张图片", { count: total })
          : text("gallery_no_available_media", "没有可添加的图片。"));
      } catch (error) {
        if (error?.name === "AbortError") return;
        const message = error instanceof Error
          ? error.message
          : text("gallery_load_media_failed", "媒体库加载失败，请重试。");
        setStatus(message, "error");
        const retry = renderLoadError(message, requestedPage);
        if (options.restoreFocus && retry instanceof HTMLElement) {
          retry.focus({ preventScroll: true });
        }
      } finally {
        if (state.controller === controller) {
          state.controller = null;
          state.loading = false;
          results.setAttribute("aria-busy", "false");
        }
      }
    };

    const markAdded = (ids) => {
      ids.forEach((id) => {
        state.selected.delete(id);
        const item = state.currentItems.get(id);
        if (item) item.added = true;
        const button = Array.from(dialog?.querySelectorAll("[data-media-id]") || [])
          .find((candidate) => Number(candidate.dataset.mediaId) === id);
        if (!(button instanceof HTMLButtonElement)) return;
        button.classList.add("is-added");
        button.disabled = true;
        button.removeAttribute("aria-pressed");
        const title = button.dataset.mediaTitle || text("gallery_untitled_image", "未命名图片");
        button.setAttribute(
          "aria-label",
          text("gallery_image_already_added", "{title}，已加入图库", { title }),
        );
        button.querySelector(".sblog-gallery-admin__picker-check")?.remove();
        const media = button.querySelector(".sblog-gallery-admin__picker-media");
        if (media instanceof HTMLElement && !media.querySelector(".sblog-gallery-admin__picker-badge")) {
          media.appendChild(createElement(
            "span",
            "sblog-gallery-admin__picker-badge",
            text("gallery_added", "已加入"),
          ));
        }
      });
      updateSelection();
    };

    const addMedia = async (rawIds, options = {}) => {
      const ids = mediaIds(rawIds);
      if (!ids.length) return { added: 0 };
      if (!addUrl || !csrf) {
        throw new Error(text("gallery_add_endpoint_missing", "图库接口不可用。请刷新页面后重试。"));
      }

      const data = new FormData();
      data.append("csrf_token", csrf);
      ids.forEach((id) => data.append("media_ids[]", String(id)));
      if (categorySelect instanceof HTMLSelectElement) {
        data.append("category_id", String(Math.max(0, Number(categorySelect.value) || 0)));
      }
      const response = await fetch(addUrl, {
        method: "POST",
        body: data,
        credentials: "same-origin",
        headers: { Accept: "application/json" },
      });
      const result = await responseJson(
        response,
        text("gallery_add_failed", "图片加入图库失败，请重试。"),
      );

      const added = Array.isArray(result.added)
        ? result.added.length
        : Math.max(0, Number(result.added) || 0);
      const hasConfirmedIds = Array.isArray(result.added_media_ids) && Array.isArray(result.existing_media_ids);
      const confirmedIds = hasConfirmedIds
        ? mediaIds([...result.added_media_ids, ...result.existing_media_ids]).filter((id) => ids.includes(id))
        : Number(result.invalid) === 0 && added + Math.max(0, Number(result.existing) || 0) >= ids.length
          ? ids
          : [];
      const pendingIds = ids.filter((id) => !confirmedIds.includes(id));
      const invalid = Math.max(0, Number(result.invalid) || 0, pendingIds.length);
      markAdded(confirmedIds);
      state.changed = state.changed || added > 0;
      if (confirmedIds.length) {
        root.dispatchEvent(new CustomEvent("sblog:gallery-items-added", {
          bubbles: true,
          detail: { added, ids: confirmedIds, result },
        }));
      }

      if (invalid > 0) {
        const error = new Error(confirmedIds.length
          ? text("gallery_add_partial_failed", "已确认 {count} 张图片在图库中，但 {invalid} 张未能加入。请刷新媒体库后重试。", {
            count: confirmedIds.length,
            invalid,
          })
          : text("gallery_add_invalid_media", "{count} 张图片已失效，未能加入图库。请刷新媒体库后重试。", { count: invalid }));
        error.galleryResult = result;
        error.galleryPendingIds = pendingIds;
        throw error;
      }

      if (options.announce !== false) {
        setStatus(
          added > 0
            ? text("gallery_add_success", "已将 {count} 张图片加入图库。", { count: added })
            : text("gallery_images_already_added", "所选图片已经在图库中。"),
          "success",
        );
      }
      return { ...result, added };
    };

    const setAddBusy = (busy) => {
      state.addBusy = busy;
      if (addButton instanceof HTMLButtonElement) {
        addButton.setAttribute("aria-busy", busy ? "true" : "false");
      }
      updateBusyControls();
      updateSelection();
    };

    const uploadRow = (file) => {
      const row = createElement("div", "sblog-gallery-admin__upload-row");
      const preview = createElement("div", "sblog-gallery-admin__upload-preview");
      const image = new Image();
      image.alt = "";
      const objectUrl = URL.createObjectURL(file);
      image.addEventListener("load", () => URL.revokeObjectURL(objectUrl), { once: true });
      image.addEventListener("error", () => URL.revokeObjectURL(objectUrl), { once: true });
      image.src = objectUrl;
      preview.appendChild(image);

      const copy = createElement("div", "sblog-gallery-admin__upload-copy");
      const name = createElement("strong", "", file.name);
      name.title = file.name;
      const status = createElement("span", "", text("gallery_waiting_to_upload", "等待上传"));
      copy.append(name, status);
      row.append(preview, copy);
      uploadStatus?.appendChild(row);
      return { row, status };
    };

    const validImageFile = (file) => file.type.startsWith("image/")
      || /\.(?:jpe?g|png|gif|webp)$/i.test(file.name);

    const uploadFiles = async (files) => {
      if (!(uploadInput instanceof HTMLInputElement) || !(uploadStatus instanceof HTMLElement)) return;
      const selectedFiles = Array.from(files || []).filter((file) => file instanceof File);
      if (!selectedFiles.length || state.uploading || state.addBusy) return;

      setUploading(true);
      uploadStatus.setAttribute("aria-busy", "true");
      const maximumSize = 30 * 1024 * 1024;
      let completed = 0;

      const offerAssociationRetry = (item, uploaded) => {
        item.row.classList.add("is-error");
        item.status.textContent = text(
          "gallery_uploaded_add_failed",
          "图片已上传到媒体库，但未能加入图库。",
        );
        const retry = createElement(
          "button",
          "button button--secondary",
          text("gallery_retry_add", "重试加入图库"),
        );
        retry.type = "button";
        retry.disabled = state.uploading;
        retry.addEventListener("click", async () => {
          if (state.uploading || state.addBusy) return;
          setAddBusy(true);
          retry.disabled = true;
          item.status.textContent = text("gallery_retrying_add", "正在加入图库…");
          try {
            await addMedia(uploaded.map((entry) => Number(entry.id)), { announce: false });
            state.changed = true;
            item.row.classList.remove("is-error");
            item.row.classList.add("is-done");
            item.status.textContent = text("gallery_uploaded_and_added", "已上传并加入图库");
            state.retryButtons.delete(retry);
            retry.remove();
            setStatus(text("gallery_retry_add_success", "图片已加入图库。"), "success");
          } catch (error) {
            item.status.textContent = text(
              "gallery_uploaded_add_failed",
              "图片已上传到媒体库，但未能加入图库。",
            );
          } finally {
            setAddBusy(false);
            if (retry.isConnected) retry.disabled = false;
          }
        });
        item.row.appendChild(retry);
        state.retryButtons.add(retry);
      };

      for (const file of selectedFiles) {
        const item = uploadRow(file);
        if (!validImageFile(file)) {
          item.row.classList.add("is-error");
          item.status.textContent = text("gallery_image_type_required", "请选择 JPEG、PNG、GIF 或 WebP 图片。");
          continue;
        }
        if (file.size > maximumSize) {
          item.row.classList.add("is-error");
          item.status.textContent = text("gallery_file_too_large", "图片超过 30M");
          continue;
        }
        if (!uploadUrl || !csrf) {
          item.row.classList.add("is-error");
          item.status.textContent = text("gallery_upload_endpoint_missing", "上传接口不可用。请刷新页面后重试。");
          continue;
        }

        item.status.textContent = text("gallery_uploading", "正在上传…");
        const data = new FormData();
        data.append("csrf_token", csrf);
        data.append("attachments[]", file);

        let uploaded = [];
        try {
          const response = await fetch(uploadUrl, {
            method: "POST",
            body: data,
            credentials: "same-origin",
            headers: { Accept: "application/json" },
          });
          const result = await responseJson(
            response,
            text("gallery_upload_failed", "图片上传失败，请重试。"),
          );
          uploaded = Array.isArray(result.files)
            ? result.files.filter((entry) => isAddedValue(entry?.is_image) && Number(entry?.id) > 0)
            : [];
          if (!uploaded.length) {
            const uploadError = result.errors?.[0]?.error || text("gallery_upload_invalid", "上传结果中没有可用图片。");
            throw new Error(String(uploadError));
          }
        } catch (error) {
          item.row.classList.add("is-error");
          item.status.textContent = error instanceof Error
            ? error.message
            : text("gallery_upload_failed", "图片上传失败，请重试。");
          continue;
        }

        try {
          await addMedia(uploaded.map((entry) => Number(entry.id)), { announce: false });
          state.changed = true;
          item.row.classList.add("is-done");
          item.status.textContent = text("gallery_uploaded_and_added", "已上传并加入图库");
          completed += uploaded.length;
        } catch (error) {
          const pending = Array.isArray(error?.galleryPendingIds)
            ? uploaded.filter((entry) => error.galleryPendingIds.includes(Number(entry.id)))
            : uploaded;
          offerAssociationRetry(item, pending);
        }
      }

      setUploading(false);
      uploadInput.value = "";
      uploadStatus.setAttribute("aria-busy", "false");
      if (completed > 0) {
        setStatus(
          text("gallery_upload_success", "已上传并加入 {count} 张图片。", { count: completed }),
          "success",
        );
      }
    };

    const activateTab = (name, moveFocus = false) => {
      const selectedTab = tabs.find((tab) => tab.dataset.sblogGalleryPickerTab === name) || tabs[0];
      if (!selectedTab) return;
      const selectedName = selectedTab.dataset.sblogGalleryPickerTab || "library";

      tabs.forEach((tab) => {
        const active = tab === selectedTab;
        tab.setAttribute("aria-selected", active ? "true" : "false");
        tab.tabIndex = active ? 0 : -1;
        tab.classList.toggle("is-active", active);
      });
      panels.forEach((panel) => {
        const active = panel.dataset.sblogGalleryPickerPanel === selectedName;
        panel.hidden = !active;
        panel.classList.toggle("is-active", active);
      });

      if (moveFocus && selectedTab instanceof HTMLElement) selectedTab.focus();
      if (state.open && selectedName === "library" && !state.loaded && !state.loading) void loadMedia(1);
    };

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
      body.classList.add("sblog-gallery-picker-open");
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
      body.classList.remove("sblog-gallery-picker-open");
      body.style.overflow = saved.bodyOverflow;
      body.style.paddingRight = saved.bodyPaddingRight;
      body.style.position = saved.bodyPosition;
      body.style.top = saved.bodyTop;
      body.style.width = saved.bodyWidth;
      documentElement.style.overflow = saved.htmlOverflow;
      window.scrollTo(0, saved.scrollY);
    };

    const finishClose = () => {
      if (!(dialog instanceof HTMLElement) || !state.open) return;
      state.open = false;
      dialog.classList.remove("is-open");
      dialog.setAttribute("aria-hidden", "true");
      dialog.hidden = true;
      unlockPage();

      if (state.reloadOnClose && state.changed) {
        window.location.reload();
        return;
      }
      if (state.previousFocus instanceof HTMLElement && state.previousFocus.isConnected) {
        state.previousFocus.focus({ preventScroll: true });
      }
      state.previousFocus = null;
    };

    const closeDialog = () => {
      if (!(dialog instanceof HTMLElement) || !state.open) return;
      if (state.uploading || state.addBusy) {
        setStatus(state.uploading
          ? text("gallery_upload_in_progress", "图片正在上传，请等待队列完成。")
          : text("gallery_add_in_progress", "图片正在加入图库，请等待操作完成。"));
        return;
      }
      if (nativeDialog && dialog.open) dialog.close();
      else finishClose();
    };

    const openDialog = (trigger) => {
      if (!(dialog instanceof HTMLElement) || state.open) return;
      if (coverMode) {
        const form = trigger instanceof HTMLElement ? trigger.closest("form") : null;
        if (!(form instanceof HTMLFormElement) || !(form.querySelector("[data-sblog-gallery-cover-input]") instanceof HTMLInputElement)) return;
        state.coverForm = form;
        state.selected.clear();
        results?.querySelectorAll("[data-media-id]").forEach((button) => markButtonSelection(button, false));
        updateSelection();
        state.loaded = false;
      }
      state.open = true;
      state.previousFocus = trigger instanceof HTMLElement ? trigger : document.activeElement;
      dialog.hidden = false;
      dialog.classList.add("is-open");
      dialog.setAttribute("aria-hidden", "false");
      if (!dialog.hasAttribute("aria-modal")) dialog.setAttribute("aria-modal", "true");
      if (!nativeDialog && !dialog.hasAttribute("role")) dialog.setAttribute("role", "dialog");
      dialog.classList.toggle("is-fallback", !nativeDialog);
      lockPage();

      if (nativeDialog && !dialog.open) {
        try {
          dialog.showModal();
        } catch (error) {
          dialog.classList.add("is-fallback");
          dialog.setAttribute("open", "");
        }
      }
      const activeTab = tabs.find((tab) => tab.getAttribute("aria-selected") === "true") || tabs[0];
      activateTab(activeTab?.dataset.sblogGalleryPickerTab || "library");
      window.requestAnimationFrame(() => {
        const initialFocus = closeButtons[0] || activeTab || focusableElements(dialog)[0];
        if (initialFocus instanceof HTMLElement) initialFocus.focus({ preventScroll: true });
      });
    };

    if (!(dialog instanceof HTMLElement)) {
      openButtons.forEach((button) => {
        if (button instanceof HTMLButtonElement) button.disabled = true;
      });
      return;
    }

    dialog.setAttribute("aria-hidden", nativeDialog ? (dialog.open ? "false" : "true") : (dialog.hidden ? "true" : "false"));
    uploadStatus?.setAttribute("aria-live", "polite");
    selectedCount?.setAttribute("aria-live", "polite");
    results?.setAttribute("aria-live", "polite");

    tabs.forEach((tab, index) => {
      if (tab instanceof HTMLButtonElement) tab.type = "button";
      tab.setAttribute("role", "tab");
      if (!tab.id) tab.id = `sblog-gallery-picker-tab-${index + 1}`;
      const name = tab.dataset.sblogGalleryPickerTab || "";
      const panel = panels.find((candidate) => candidate.dataset.sblogGalleryPickerPanel === name);
      if (panel) {
        if (!panel.id) panel.id = `sblog-gallery-picker-panel-${index + 1}`;
        tab.setAttribute("aria-controls", panel.id);
        panel.setAttribute("role", "tabpanel");
        panel.setAttribute("aria-labelledby", tab.id);
      }
      tab.addEventListener("click", () => activateTab(name));
      tab.addEventListener("keydown", (event) => {
        if (!["ArrowLeft", "ArrowRight", "Home", "End"].includes(event.key)) return;
        event.preventDefault();
        let targetIndex = index;
        if (event.key === "ArrowLeft") targetIndex = (index - 1 + tabs.length) % tabs.length;
        if (event.key === "ArrowRight") targetIndex = (index + 1) % tabs.length;
        if (event.key === "Home") targetIndex = 0;
        if (event.key === "End") targetIndex = tabs.length - 1;
        const next = tabs[targetIndex];
        activateTab(next?.dataset.sblogGalleryPickerTab || name, true);
      });
    });

    const initialTab = tabs.find((tab) => tab.getAttribute("aria-selected") === "true") || tabs[0];
    if (initialTab) activateTab(initialTab.dataset.sblogGalleryPickerTab || "library");

    openButtons.forEach((button) => {
      button.addEventListener("click", () => openDialog(button));
    });
    if (coverMode) {
      root.querySelectorAll("[data-sblog-gallery-cover-clear]").forEach((button) => {
        button.addEventListener("click", () => {
          const form = button.closest("form");
          const input = form?.querySelector("[data-sblog-gallery-cover-input]");
          const preview = form?.querySelector("[data-sblog-gallery-cover-preview]");
          if (input instanceof HTMLInputElement) input.value = "";
          if (preview instanceof HTMLImageElement) {
            preview.removeAttribute("src");
            preview.hidden = true;
          }
          button.hidden = true;
          const open = form?.querySelector("[data-sblog-gallery-cover-open]");
          if (open instanceof HTMLElement) open.focus({ preventScroll: true });
        });
      });
    }
    closeButtons.forEach((button) => button.addEventListener("click", closeDialog));

    dialog.addEventListener("cancel", (event) => {
      event.preventDefault();
      closeDialog();
    });
    if (nativeDialog) dialog.addEventListener("close", finishClose);
    dialog.addEventListener("click", (event) => {
      const target = event.target;
      if (target === dialog || (target instanceof Element && target.matches("[data-sblog-gallery-dialog-backdrop]"))) {
        closeDialog();
      }
    });
    dialog.addEventListener("keydown", (event) => {
      if (event.key === "Escape") {
        event.preventDefault();
        closeDialog();
        return;
      }
      if (event.key !== "Tab") return;
      const focusable = focusableElements(dialog);
      if (!focusable.length) {
        event.preventDefault();
        dialog.focus();
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

    searchForm?.addEventListener("submit", (event) => {
      event.preventDefault();
      void loadMedia(1);
    });

    addButton?.addEventListener("click", async () => {
      if (state.addBusy || !state.selected.size) return;
      if (coverMode) {
        const item = state.selected.values().next().value;
        const input = state.coverForm?.querySelector("[data-sblog-gallery-cover-input]");
        const preview = state.coverForm?.querySelector("[data-sblog-gallery-cover-preview]");
        if (!(input instanceof HTMLInputElement) || !item) return;
        input.value = String(item.id);
        if (preview instanceof HTMLImageElement) {
          preview.src = item.url;
          preview.alt = item.title;
          preview.hidden = false;
        }
        const clear = state.coverForm?.querySelector("[data-sblog-gallery-cover-clear]");
        if (clear instanceof HTMLElement) clear.hidden = false;
        root.dispatchEvent(new CustomEvent("sblog:gallery-cover-selected", {
          bubbles: true,
          detail: { mediaId: item.id, form: state.coverForm },
        }));
        closeDialog();
        return;
      }
      setAddBusy(true);
      setStatus(text("gallery_adding_images", "正在加入图库…"));
      try {
        await addMedia(Array.from(state.selected.keys()));
      } catch (error) {
        setStatus(
          error instanceof Error ? error.message : text("gallery_add_failed", "图片加入图库失败，请重试。"),
          "error",
        );
      } finally {
        setAddBusy(false);
      }
    });

    if (uploadInput instanceof HTMLInputElement) {
      uploadInput.addEventListener("change", () => void uploadFiles(uploadInput.files));
    }
    if (uploadZone instanceof HTMLElement) {
      ["dragenter", "dragover"].forEach((eventName) => {
        uploadZone.addEventListener(eventName, (event) => {
          event.preventDefault();
          uploadZone.classList.add("is-dragging");
        });
      });
      ["dragleave", "drop"].forEach((eventName) => {
        uploadZone.addEventListener(eventName, (event) => {
          event.preventDefault();
          uploadZone.classList.remove("is-dragging");
        });
      });
      uploadZone.addEventListener("drop", (event) => {
        void uploadFiles(event.dataTransfer?.files);
      });
    }

    updateSelection();
  };

  const init = () => {
    document.querySelectorAll("[data-sblog-gallery-admin], [data-sblog-gallery-cover-picker]").forEach(initGalleryAdmin);
  };

  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", init, { once: true });
  else init();
})();
