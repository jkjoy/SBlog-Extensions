(() => {
  "use strict";

  const dialog = document.querySelector("#sblog-lottery-editor");
  const content = document.querySelector("textarea#content");
  const openButtons = Array.from(document.querySelectorAll("[data-lottery-open]"));
  if (!(dialog instanceof HTMLDialogElement) || !(content instanceof HTMLTextAreaElement) || !openButtons.length) return;
  const form = dialog.querySelector("[data-lottery-form]");
  const fields = dialog.querySelector("[data-lottery-fields]");
  const errorBox = dialog.querySelector("[data-lottery-error]");
  const lockedBox = dialog.querySelector("[data-lottery-locked]");
  const submit = dialog.querySelector("[data-lottery-submit]");
  const kind = document.querySelector("#kind");
  const closeButtons = Array.from(dialog.querySelectorAll("[data-lottery-close]"));
  if (!(form instanceof HTMLFormElement) || !(fields instanceof HTMLFieldSetElement)) return;
  const control = (name) => form.elements.namedItem(name);
  const optionNames = ["unique_email", "roots_only", "auto_draw", "notify"];
  let selection = { start: 0, end: 0 };
  let busy = false;
  let locked = false;
  let trigger = null;
  let focusEditorOnClose = false;

  const shanghaiTime = (epoch) => {
    const parts = new Intl.DateTimeFormat("en-CA", {
      timeZone: "Asia/Shanghai", year: "numeric", month: "2-digit", day: "2-digit",
      hour: "2-digit", minute: "2-digit", hourCycle: "h23",
    }).formatToParts(new Date(epoch * 1000));
    const value = (type) => parts.find((part) => part.type === type)?.value || "";
    return `${value("year")}-${value("month")}-${value("day")}T${value("hour")}:${value("minute")}`;
  };

  const showError = (message) => {
    errorBox.textContent = message;
    errorBox.hidden = !message;
  };
  const setBusy = (value) => {
    busy = value;
    form.setAttribute("aria-busy", String(value));
    fields.disabled = value || locked;
    submit.disabled = value || locked;
    closeButtons.forEach((button) => { button.disabled = value; });
    submit.textContent = value ? "处理中…" : control("id").value ? "更新抽奖" : "插入抽奖";
  };

  const request = async (url, options = {}) => {
    const controller = new AbortController();
    const timeout = window.setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch(url, {
        ...options, credentials: "same-origin", headers: { Accept: "application/json" }, signal: controller.signal,
      });
      const payload = await response.json().catch(() => null);
      if (!response.ok || payload?.ok !== true || !payload.lottery) {
        const message = typeof payload?.error === "string" ? payload.error
          : typeof payload?.message === "string" ? payload.message
          : Array.isArray(payload?.errors) ? payload.errors.filter((item) => typeof item === "string").join(" ") : "";
        throw new Error(message || "无法保存或读取抽奖设置，请刷新页面后重试。");
      }
      return payload;
    } catch (error) {
      if (error.name === "AbortError") throw new Error("请求超时，请检查网络后重试。");
      if (error instanceof TypeError) throw new Error("网络连接失败，请稍后重试。");
      throw error;
    } finally {
      window.clearTimeout(timeout);
    }
  };

  const applyLottery = (lottery) => {
    if (!/^[a-f0-9]{32}$/.test(String(lottery.id || ""))) throw new Error("抽奖数据无效，请刷新页面后重试。");
    control("id").value = lottery.id;
    control("title").value = String(lottery.title || "评论抽奖");
    control("description").value = String(lottery.description || "");
    control("starts_at").value = shanghaiTime(Number(lottery.starts_at));
    control("ends_at").value = shanghaiTime(Number(lottery.ends_at));
    control("winners_count").value = String(lottery.winners_count || 1);
    optionNames.forEach((name) => { control(name).checked = lottery[name] === true || Number(lottery[name]) === 1; });
    locked = lottery.locked === true || Number(lottery.locked) === 1;
    lockedBox.hidden = !locked;
  };

  const activeMarkers = () => {
    // Keep inline and fenced examples out of the active module list, as the server does.
    const lines = content.value.split(/(\r\n|\r|\n)/);
    const markers = [];
    let inCode = false;
    let offset = 0;
    for (let index = 0; index < lines.length; index += 2) {
      const line = lines[index];
      if (/^```[\w-]*\s*$/.test(line)) inCode = !inCode;
      else if (!inCode) {
        const match = line.match(/^\s*\[comment-lottery id="([a-f0-9]{32})"\]\s*$/);
        if (match) {
          const shortcode = `[comment-lottery id="${match[1]}"]`;
          markers.push({ id: match[1], shortcode, index: offset + line.indexOf(shortcode) });
        }
      }
      offset += line.length + (lines[index + 1]?.length || 0);
    }
    return markers;
  };

  const currentToken = () => {
    const markers = activeMarkers();
    const selected = markers.find((match) => selection.start >= match.index && selection.end <= match.index + match.shortcode.length);
    if (selected) return selected.id;
    if (markers.length === 1) return markers[0].id;
    if (markers.length > 1) throw new Error("正文包含多个抽奖短代码，请先选中要编辑的短代码。");
    return dialog.dataset.existingId || "";
  };

  const syncKind = () => {
    const isPage = kind?.value === "page";
    openButtons.forEach((button) => { button.hidden = isPage; button.disabled = isPage; });
  };
  kind?.addEventListener("change", syncKind);
  syncKind();

  openButtons.forEach((button) => {
    button.addEventListener("click", async () => {
      if (busy || kind?.value === "page") return;
      if (typeof dialog.showModal !== "function") {
        window.alert("当前浏览器不支持抽奖设置窗口，请使用新版浏览器。");
        return;
      }
      trigger = button;
      focusEditorOnClose = false;
      selection = { start: content.selectionStart, end: content.selectionEnd };
      form.reset();
      locked = false;
      lockedBox.hidden = true;
      showError("");
      const now = Math.floor(Date.now() / 1000);
      control("starts_at").value = shanghaiTime(now);
      control("ends_at").value = shanghaiTime(now + 7 * 86400);
      setBusy(false);
      dialog.showModal();
      try {
        const token = currentToken();
        if (token) {
          setBusy(true);
          const url = new URL(form.action, window.location.href);
          url.searchParams.set("id", token);
          const payload = await request(url);
          applyLottery(payload.lottery);
        }
      } catch (error) {
        // Existing activity must load successfully before an update can be submitted.
        locked = true;
        showError(error.message || "读取抽奖设置失败，请关闭窗口后重试。");
      } finally {
        setBusy(false);
        if (!locked) control("title").focus();
      }
    });
  });

  closeButtons.forEach((button) => button.addEventListener("click", () => { if (!busy) dialog.close(); }));
  dialog.addEventListener("cancel", (event) => { if (busy) event.preventDefault(); });
  dialog.addEventListener("close", () => {
    if (focusEditorOnClose) content.focus();
    else trigger?.focus();
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (busy || locked || !form.reportValidity()) return;
    showError("");
    const starts = Date.parse(`${control("starts_at").value}+08:00`);
    const ends = Date.parse(`${control("ends_at").value}+08:00`);
    if (!Number.isFinite(starts) || !Number.isFinite(ends) || ends <= starts) {
      showError("结束时间必须晚于开始时间。");
      control("ends_at").focus();
      return;
    }
    const data = new FormData(form);
    optionNames.forEach((name) => data.set(name, control(name).checked ? "1" : "0"));
    setBusy(true);
    try {
      const payload = await request(form.action, { method: "POST", body: data });
      const id = String(payload.lottery.id || "");
      const shortcode = `[comment-lottery id="${id}"]`;
      if (!/^[a-f0-9]{32}$/.test(id) || payload.shortcode !== shortcode) throw new Error("抽奖短代码无效，请刷新页面后重试。");
      const alreadyInserted = activeMarkers().some((marker) => marker.id === id);
      if (!alreadyInserted) {
        const start = Math.min(selection.start, content.value.length);
        const end = Math.min(selection.end, content.value.length);
        const before = content.value.slice(0, start);
        const after = content.value.slice(end);
        const prefix = before && !before.endsWith("\n\n") ? before.endsWith("\n") ? "\n" : "\n\n" : "";
        const suffix = after && !after.startsWith("\n\n") ? after.startsWith("\n") ? "\n" : "\n\n" : "\n\n";
        content.setRangeText(prefix + shortcode + suffix, start, end, "end");
      }
      content.dispatchEvent(new Event("input", { bubbles: true }));
      dialog.dataset.existingId = id;
      focusEditorOnClose = true;
      dialog.close();
      content.focus();
    } catch (error) {
      showError(error.message || "保存抽奖设置失败，请稍后重试。");
    } finally {
      setBusy(false);
    }
  });
})();
