#!/usr/bin/env node
"use strict";

// Exercise the real admin script through DOM events, with no browser or third-party packages.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const source = fs.readFileSync(path.join(__dirname, "../plugins/gallery/assets/admin.js"), "utf8");

class Element {
  constructor(tag = "div") {
    this.tagName = tag.toUpperCase();
    this.dataset = {};
    this.attributes = {};
    this.children = [];
    this.style = {};
    this.listeners = {};
    this.disabled = false;
    this.hidden = false;
    this.isConnected = true;
    this.textContent = "";
    this.classes = new Set();
    this.classList = {
      add: (...values) => values.forEach((value) => this.classes.add(value)),
      remove: (...values) => values.forEach((value) => this.classes.delete(value)),
      contains: (value) => this.classes.has(value),
      toggle: (value, force) => {
        const enabled = force ?? !this.classes.has(value);
        enabled ? this.classes.add(value) : this.classes.delete(value);
        return enabled;
      },
    };
  }
  set className(value) { this.classes = new Set(value.split(/\s+/).filter(Boolean)); }
  get className() { return [...this.classes].join(" "); }
  dataKey(name) { return name.slice(5).replace(/-([a-z])/g, (_, character) => character.toUpperCase()); }
  setAttribute(name, value) {
    this.attributes[name] = String(value);
    if (name.startsWith("data-")) this.dataset[this.dataKey(name)] = String(value);
  }
  getAttribute(name) {
    return this.attributes[name] ?? (name.startsWith("data-") ? this.dataset[this.dataKey(name)] : null) ?? null;
  }
  hasAttribute(name) { return this.getAttribute(name) !== null; }
  removeAttribute(name) { delete this.attributes[name]; }
  appendChild(child) {
    if (child.fragment) { child.children.forEach((entry) => this.appendChild(entry)); return child; }
    child.parent = this;
    this.children.push(child);
    return child;
  }
  append(...children) { children.forEach((child) => this.appendChild(child)); }
  replaceChildren(...children) { this.children = []; this.append(...children); }
  remove() { this.parent.children = this.parent.children.filter((child) => child !== this); this.isConnected = false; }
  matches(selector) {
    if (selector.startsWith(".")) return this.classes.has(selector.slice(1));
    if (selector.startsWith("[")) {
      const match = selector.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);
      return Boolean(match) && (match[2] === undefined ? this.hasAttribute(match[1]) : this.getAttribute(match[1]) === match[2]);
    }
    return selector.toUpperCase() === this.tagName;
  }
  querySelectorAll(selector) {
    const alternatives = selector.split(",").map((part) => part.trim());
    const found = [];
    const visit = (element) => element.children.forEach((child) => {
      if (alternatives.some((part) => child.matches(part))) found.push(child);
      visit(child);
    });
    visit(this);
    return found;
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  closest(selector) { return this.matches(selector) ? this : this.parent?.closest(selector) || null; }
  addEventListener(type, callback) { (this.listeners[type] ??= []).push(callback); }
  async fire(type, extra = {}) {
    if (type === "click" && this.disabled) return;
    await Promise.all((this.listeners[type] || []).map((callback) => callback({
      type, target: this, preventDefault() {}, ...extra,
    })));
  }
  dispatchEvent(event) { (this.events ??= []).push(event); return true; }
  focus() { this.focusCalls = (this.focusCalls || 0) + 1; }
  getClientRects() { return [1]; }
}
class HTMLElement extends Element {}
class HTMLButtonElement extends HTMLElement { constructor() { super("button"); } }
class HTMLFormElement extends HTMLElement { constructor() { super("form"); } reset() {} }
class HTMLInputElement extends HTMLElement { constructor() { super("input"); this.value = ""; } }
class HTMLSelectElement extends HTMLElement { constructor() { super("select"); } }
class HTMLImageElement extends HTMLElement { constructor() { super("img"); this.complete = false; } }
class HTMLDialogElement extends HTMLElement {
  constructor() { super("dialog"); this.open = false; }
  showModal() { this.open = true; }
  close() { this.open = false; void this.fire("close"); }
}
class File { constructor(name, type = "image/jpeg", size = 100) { Object.assign(this, { name, type, size }); } }
class FormData {
  constructor(form) {
    this.values = form ? form.querySelectorAll("[name]").map((input) => [input.getAttribute("name"), input.value]) : [];
  }
  append(name, value) { this.values.push([name, value]); }
  get(name) { return this.values.find((entry) => entry[0] === name)?.[1] ?? null; }
  forEach(callback) { this.values.forEach(([name, value]) => callback(value, name)); }
}
class BrowserURL extends URL { static createObjectURL() { return "blob:preview"; } static revokeObjectURL() {} }

const element = (tag, attribute, value = "") => {
  const Constructor = { button: HTMLButtonElement, form: HTMLFormElement, input: HTMLInputElement, img: HTMLImageElement }[tag];
  const node = Constructor ? new Constructor() : new HTMLElement(tag);
  if (attribute) node.setAttribute(attribute, value);
  return node;
};
const settle = async () => { for (let index = 0; index < 4; index += 1) await new Promise((resolve) => setImmediate(resolve)); };
const image = (id, added = false) => ({ id, added, title: `图片 ${id}`, url: `/media/${id}.jpg` });

function fixture(options = {}) {
  const root = element("div", "data-sblog-gallery-admin");
  Object.assign(root.dataset, { mediaUrl: "/media", addUrl: "/add", uploadUrl: "/upload", csrf: "token", refreshOnAdd: "1" });
  if (options.cover) root.setAttribute("data-sblog-gallery-cover-picker", "");
  const dialog = options.native ? new HTMLDialogElement() : element("div");
  dialog.setAttribute("data-sblog-gallery-dialog", "");
  const results = element("div", "data-sblog-gallery-results");
  const status = element("div", "data-sblog-gallery-picker-status");
  const count = element("div", "data-sblog-gallery-selected-count");
  const add = element("button", "data-sblog-gallery-add");
  const pagination = element("div", "data-sblog-gallery-pagination");
  const close = element("button", "data-sblog-gallery-close");
  const tab = element("button", "data-sblog-gallery-picker-tab", "library");
  const panel = element("section", "data-sblog-gallery-picker-panel", "library");
  const search = element("form", "data-sblog-gallery-search");
  const query = element("input", "name", "q");
  search.append(query);
  panel.append(search, results, pagination);
  dialog.append(close, tab, panel, status, count, add);
  root.append(dialog);
  const forms = [];
  const openButtons = [];
  if (options.cover) {
    for (let index = 0; index < 2; index += 1) {
      const form = element("form");
      const draftName = element("input", "name", "name");
      draftName.value = `未保存的分类 ${index}`;
      const input = element("input", "data-sblog-gallery-cover-input");
      const preview = element("img", "data-sblog-gallery-cover-preview");
      preview.hidden = true;
      const open = element("button", "data-sblog-gallery-cover-open");
      const clear = element("button", "data-sblog-gallery-cover-clear");
      clear.hidden = true;
      form.append(draftName, input, preview, open, clear);
      root.append(form);
      forms.push({ form, draftName, input, preview, open, clear });
      openButtons.push(open);
    }
  } else {
    const open = element("button", "data-sblog-gallery-open");
    root.append(open);
    openButtons.push(open);
  }
  const upload = element("input", "data-sblog-gallery-upload");
  const uploads = element("div", "data-sblog-gallery-upload-status");
  if (options.upload) dialog.append(upload, uploads);
  const calls = [];
  const additions = [...(options.additions || [])];
  let reloads = 0;
  const document = {
    readyState: "complete", body: element("body"), documentElement: element("html"), activeElement: openButtons[0],
    querySelectorAll: (selector) => selector.split(",").some((part) => root.matches(part.trim())) ? [root] : [],
    createElement: (tag) => element(tag),
    createDocumentFragment: () => Object.assign(element("div"), { fragment: true }),
  };
  document.documentElement.clientWidth = 1000;
  const context = {
    document, Element, HTMLElement, HTMLButtonElement, HTMLFormElement, HTMLInputElement, HTMLSelectElement, HTMLImageElement,
    Image: HTMLImageElement, File, FormData, URL: BrowserURL, AbortController,
    CustomEvent: class CustomEvent { constructor(type, properties) { this.type = type; Object.assign(this, properties); } },
    window: {
      location: { href: "http://localhost/admin", reload: () => { reloads += 1; } },
      scrollY: 0, innerWidth: 1000, scrollTo() {}, requestAnimationFrame: (callback) => callback(),
      getComputedStyle: () => ({ display: "block", visibility: "visible" }),
    },
    fetch: async (url, request) => {
      const parsed = new URL(url, "http://localhost");
      calls.push({ path: parsed.pathname, query: parsed.searchParams, body: request.body?.values });
      let result;
      if (parsed.pathname === "/media") {
        result = options.media ? options.media(parsed.searchParams) : {
          ok: true, items: options.items || [image(23)], pagination: { page: 1, pages: 1, total: 1 },
        };
      } else if (parsed.pathname === "/upload") {
        result = { ok: true, files: [{ id: 23, is_image: true }] };
      } else {
        assert.ok(additions.length, "unexpected association request");
        result = additions.shift();
      }
      return { ok: true, json: async () => result };
    },
  };
  if (options.native) context.HTMLDialogElement = HTMLDialogElement;
  vm.runInNewContext(source, context);
  return {
    root, dialog, results, status, count, add, pagination, close, query, search, upload, uploads, forms, calls,
    reloads: () => reloads,
    open: async (index = 0) => { await openButtons[index].fire("click"); await settle(); },
    choose: async (id) => { const button = results.querySelector(`[data-media-id="${id}"]`); assert.ok(button, `media ${id} rendered`); await button.fire("click"); return button; },
  };
}

function response(added = [], existing = [], invalid = []) {
  return {
    ok: true, added: added.length, existing: existing.length, invalid: invalid.length,
    added_media_ids: added, existing_media_ids: existing, invalid_media_ids: invalid,
  };
}

async function invalidSelection() {
  const ui = fixture({ additions: [response([], [], [23])] });
  await ui.open();
  const button = await ui.choose(23);
  await ui.add.fire("click");
  assert.equal(ui.status.dataset.state, "error");
  assert.match(ui.status.textContent, /失效/);
  assert.equal(button.disabled, false, "invalid media must stay selectable");
  assert.equal(button.classList.contains("is-added"), false);
  assert.equal(button.getAttribute("aria-pressed"), "true", "failed selection must remain for correction");
  assert.equal(ui.count.textContent, "已选择 1 张图片");
  assert.equal(ui.root.events?.length || 0, 0, "no added event without confirmed media");
  await ui.close.fire("click");
  assert.equal(ui.reloads(), 0);
}

async function partialSelection() {
  const ui = fixture({ items: [image(23), image(24), image(25)], additions: [response([23], [24], [25]), response([], [], [25])] });
  await ui.open();
  const buttons = [];
  for (const id of [23, 24, 25]) buttons.push(await ui.choose(id));
  await ui.add.fire("click");
  assert.deepEqual(buttons.map((button) => button.disabled), [true, true, false]);
  assert.equal(ui.count.textContent, "已选择 1 张图片");
  assert.equal(ui.status.dataset.state, "error");
  assert.match(ui.status.textContent, /2 张图片在图库中.*1 张未能加入/);
  assert.deepEqual(Array.from(ui.root.events[0].detail.ids), [23, 24], "event must exclude failed media");
  await ui.add.fire("click");
  const associationCalls = ui.calls.filter((call) => call.path === "/add");
  assert.deepEqual(associationCalls[1].body.filter(([name]) => name === "media_ids[]").map(([, id]) => id), ["25"], "retry should submit only the failed selection");
  assert.equal(ui.status.dataset.state, "error");
  await ui.close.fire("click");
  assert.equal(ui.reloads(), 1, "confirmed new media must refresh the gallery after close");
}

async function existingSelection() {
  const ui = fixture({ additions: [response([], [23])] });
  await ui.open();
  const button = await ui.choose(23);
  await ui.add.fire("click");
  assert.equal(ui.status.dataset.state, "success");
  assert.match(ui.status.textContent, /已经在图库中/);
  assert.equal(button.disabled, true);
  assert.equal(ui.count.textContent, "尚未选择图片");
}

async function uploadedAssociationRetry() {
  const ui = fixture({ upload: true, additions: [response([], [], [23]), response([], [], [23]), response([23])] });
  await ui.open();
  ui.upload.files = [new File("photo.jpg")];
  await ui.upload.fire("change");
  await settle();
  const row = ui.uploads.children[0];
  assert.equal(row.classList.contains("is-error"), true);
  assert.equal(row.classList.contains("is-done"), false, "invalid association must not announce upload completion");
  const retry = row.querySelector("button");
  assert.ok(retry, "uploaded media must offer association retry");
  await retry.fire("click");
  assert.equal(row.classList.contains("is-done"), false, "failed association retry must keep reporting failure");
  assert.equal(row.classList.contains("is-error"), true);
  await retry.fire("click");
  assert.equal(row.classList.contains("is-done"), true);
  assert.equal(row.classList.contains("is-error"), false);
  assert.equal(ui.calls.filter((call) => call.path === "/upload").length, 1, "retry must not upload the file again");
  assert.equal(ui.calls.filter((call) => call.path === "/add").length, 3);
}

async function paginatedCategoryCovers() {
  const ui = fixture({ cover: true, native: true, media: (params) => {
    const page = Number(params.get("page"));
    return { ok: true, items: page === 2 ? [image(24, true), image(25, true)] : [image(23, true)], pagination: { page, pages: 2, total: 3 } };
  } });
  await ui.open(0);
  const first = await ui.choose(23);
  assert.equal(first.disabled, false, "a gallery image can also serve as category cover");
  const nextPage = ui.pagination.children.find((button) => button.textContent === "2");
  await nextPage.fire("click");
  await settle();
  await ui.choose(24);
  const second = await ui.choose(25);
  assert.equal(ui.results.querySelector('[data-media-id="24"]').getAttribute("aria-pressed"), "false");
  assert.equal(second.getAttribute("aria-pressed"), "true");
  assert.equal(ui.count.textContent, "已选择 1 张图片", "cover selection must replace the previous image");
  await ui.add.fire("click");
  assert.equal(ui.forms[0].input.value, "25");
  assert.equal(ui.forms[0].preview.src, "/media/25.jpg");
  assert.equal(ui.forms[0].preview.hidden, false);
  assert.equal(ui.forms[0].clear.hidden, false, "a newly chosen cover must expose its clear button");
  assert.equal(ui.forms[1].input.value, "", "choosing a cover must not alter another category");
  assert.equal(ui.dialog.hidden, true);
  assert.equal(ui.dialog.open, false);
  assert.ok(ui.forms[0].open.focusCalls > 0, "native dialog close must return focus to the category trigger");
  assert.equal(ui.forms[0].draftName.value, "未保存的分类 0", "cover selection must preserve unsaved category fields");
  assert.equal(ui.reloads(), 0, "cover selection must preserve the unsaved form");
  assert.equal(ui.calls.some((call) => call.path === "/add"), false, "cover selection should only update the category form");
  await ui.open(1);
  assert.equal(ui.count.textContent, "尚未选择图片", "each category starts an independent selection");
  ui.query.value = "风景";
  await ui.search.fire("submit");
  await settle();
  assert.ok(ui.calls.some((call) => call.path === "/media" && call.query.get("q") === "风景"), "cover search must query the media endpoint");
  await ui.choose(23);
  await ui.add.fire("click");
  assert.equal(ui.forms[1].input.value, "23");
  assert.equal(ui.forms[0].input.value, "25");
  await ui.forms[0].clear.fire("click");
  assert.equal(ui.forms[0].input.value, "");
  assert.equal(ui.forms[0].preview.hidden, true);
  assert.equal(ui.forms[0].clear.hidden, true);
  assert.equal(ui.forms[1].input.value, "23");
  assert.ok(ui.calls.some((call) => call.path === "/media" && call.query.get("page") === "2"), "cover picker must request media by page");
}

(async () => {
  for (const test of [invalidSelection, partialSelection, existingSelection, uploadedAssociationRetry, paginatedCategoryCovers]) {
    await test();
    process.stdout.write(`PASS ${test.name}\n`);
  }
})().catch((error) => { process.stderr.write(`${error.stack}\n`); process.exitCode = 1; });
