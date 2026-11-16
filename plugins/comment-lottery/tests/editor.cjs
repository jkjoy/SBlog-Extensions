#!/usr/bin/env node
"use strict";

// Exercise the real editor script through DOM events without browser dependencies.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const source = fs.readFileSync(path.join(__dirname, "../assets/editor.js"), "utf8");
const firstId = "a".repeat(32);
const secondId = "b".repeat(32);
const newId = "c".repeat(32);
const marker = id => `[comment-lottery id="${id}"]`;

class Element {
  constructor() {
    this.listeners = {};
    this.attributes = {};
    this.dataset = {};
    this.value = "";
    this.checked = false;
    this.disabled = false;
    this.hidden = false;
    this.textContent = "";
  }
  addEventListener(type, callback) { (this.listeners[type] ??= []).push(callback); }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  async fire(type) {
    const event = { type, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    await Promise.all((this.listeners[type] ?? []).map(callback => callback(event)));
    return event;
  }
  dispatchEvent(event) {
    (this.listeners[event.type] ?? []).forEach(callback => callback(event));
    return true;
  }
  focus() { this.focusCalls = (this.focusCalls ?? 0) + 1; }
}
class Textarea extends Element {
  constructor(value) {
    super();
    this.value = value;
    this.selectionStart = 0;
    this.selectionEnd = 0;
  }
  setRangeText(value, start, end) {
    this.value = this.value.slice(0, start) + value + this.value.slice(end);
    this.selectionStart = this.selectionEnd = start + value.length;
  }
}
class Form extends Element {
  constructor() {
    super();
    this.controls = {};
    const defaults = {
      id: "", post_id: "1", csrf_token: "fixture-csrf", title: "评论抽奖", description: "",
      starts_at: "", ends_at: "", winners_count: "1",
      unique_email: "1", roots_only: "1", auto_draw: "1", notify: "1",
    };
    for (const [name, value] of Object.entries(defaults)) {
      const input = new Element();
      input.value = input.defaultValue = value;
      input.checkbox = ["unique_email", "roots_only", "auto_draw", "notify"].includes(name);
      input.checked = input.defaultChecked = input.checkbox && name !== "roots_only";
      this.controls[name] = input;
    }
    this.elements = { namedItem: name => this.controls[name] };
    this.action = "https://blog.example/index.php?a=lottery_editor";
  }
  reset() {
    Object.values(this.controls).forEach(input => {
      input.value = input.defaultValue;
      input.checked = input.defaultChecked;
    });
  }
  reportValidity() { return true; }
}
class Fieldset extends Element {}
class Dialog extends Element {
  querySelector(selector) { return this.nodes[selector] ?? null; }
  querySelectorAll(selector) { return selector === "[data-lottery-close]" ? this.closeButtons : []; }
  showModal() { this.open = true; }
  close() { this.open = false; this.dispatchEvent({ type: "close" }); }
}
class FormDataMock {
  constructor(form) {
    this.values = new Map(Object.entries(form.controls)
      .filter(([, input]) => !input.checkbox || input.checked)
      .map(([name, input]) => [name, input.value]));
  }
  set(name, value) { this.values.set(name, value); }
  get(name) { return this.values.get(name) ?? null; }
}

function lottery(id, locked = false) {
  return {
    id, locked, title: `Round ${id[0]}`, description: "Original prize", starts_at: 1893456000,
    ends_at: 1893542400, winners_count: 2, unique_email: 1, roots_only: 0, auto_draw: 1, notify: 1,
  };
}
function response(payload, ok = true) { return { ok, json: async () => payload }; }
function fixture(text, options = {}) {
  const content = new Textarea(text);
  const form = new Form();
  const fields = new Fieldset();
  const dialog = new Dialog();
  const open = new Element();
  const create = new Element();
  const submit = new Element();
  const error = new Element();
  const locked = new Element();
  const choice = new Element();
  const kind = new Element();
  kind.value = "post";
  const close = new Element();
  dialog.closeButtons = [close];
  dialog.nodes = {
    "[data-lottery-form]": form, "[data-lottery-fields]": fields, "[data-lottery-error]": error,
    "[data-lottery-locked]": locked, "[data-lottery-choice]": choice, "[data-lottery-new]": create,
    "[data-lottery-submit]": submit,
  };
  // A leftover server attribute must not resurrect a marker deleted in the editor.
  dialog.dataset.existingId = options.existingId ?? "";
  const requests = [];
  const activities = new Map((options.activities ?? [lottery(firstId), lottery(secondId)]).map(item => [item.id, item]));
  const fetchMock = async (url, settings) => {
    const request = { method: settings.method ?? "GET", url: String(url), body: settings.body };
    requests.push(request);
    if (options.fetch) return options.fetch(request);
    const id = request.method === "POST" ? request.body.get("id") || newId : new URL(url).searchParams.get("id");
    const activity = activities.get(id) ?? lottery(id);
    return response({ ok: true, lottery: activity, shortcode: marker(id) });
  };
  const document = {
    querySelector: selector => ({ "#sblog-lottery-editor": dialog, "textarea#content": content, "#kind": kind })[selector] ?? null,
    querySelectorAll: selector => selector === "[data-lottery-open]" ? [open] : [],
  };
  const window = { location: { href: "https://blog.example/index.php?a=edit&id=1" }, setTimeout, clearTimeout, alert() {} };
  vm.runInNewContext(source, {
    document, window, fetch: fetchMock, URL, AbortController, FormData: FormDataMock,
    HTMLDialogElement: Dialog, HTMLTextAreaElement: Textarea, HTMLFormElement: Form, HTMLFieldSetElement: Fieldset,
    Event: class { constructor(type) { this.type = type; } },
  });
  const select = (start, end = start) => { content.selectionStart = start; content.selectionEnd = end; };
  return { content, form, fields, dialog, open, create, submit, error, locked, choice, close, requests, select, activities };
}

const tests = [];
const test = (name, run) => tests.push({ name, run });

test("a locked round can create an independent round without replacing its selected marker", async () => {
  const text = `Before\n\n${marker(firstId)}\n\nAfter`;
  const original = lottery(firstId, true);
  const ui = fixture(text, { activities: [original] });
  const start = text.indexOf(marker(firstId));
  ui.select(start, start + marker(firstId).length);
  await ui.open.fire("click");
  assert.equal(ui.form.controls.id.value, firstId);
  assert.equal(ui.fields.disabled, true);
  assert.equal(ui.submit.disabled, true);
  assert.equal(ui.create.disabled, false);
  await ui.create.fire("click");
  assert.equal(ui.form.controls.id.value, "", "new round does not reuse the locked identity");
  assert.equal(ui.fields.disabled, false);
  assert.equal(ui.locked.hidden, true);
  assert.equal(ui.requests.length, 1, "choosing New does not create a server activity");
  await ui.form.fire("submit");
  assert.equal(ui.requests[1].body.get("id"), "");
  assert.equal(original.id, firstId);
  assert.equal(original.title, "Round a");
  assert.equal(ui.content.value, `Before\n\n${marker(firstId)}\n\n${marker(newId)}\n\nAfter`);
});

test("a cursor inside a marker inserts the next round after that whole marker", async () => {
  const text = `${marker(firstId)}\n\nAfter`;
  const ui = fixture(text, { activities: [lottery(firstId, true)] });
  ui.select(15);
  await ui.open.fire("click");
  await ui.create.fire("click");
  await ui.form.fire("submit");
  assert.equal(ui.content.value, `${marker(firstId)}\n\n${marker(newId)}\n\nAfter`);
});

test("multiple unselected modules can create a round and explain how to edit one", async () => {
  const text = `Introduction\n\n${marker(firstId)}\n\n${marker(secondId)}`;
  const ui = fixture(text);
  ui.select(0, "Introduction".length);
  await ui.open.fire("click");
  assert.equal(ui.requests.length, 0, "opening does not pick an arbitrary old round");
  assert.equal(ui.choice.hidden, false);
  assert.equal(ui.submit.disabled, false);
  await ui.create.fire("click");
  await ui.form.fire("submit");
  assert.equal(ui.requests.length, 1);
  assert.equal(ui.requests[0].method, "POST");
  assert.equal(ui.requests[0].body.get("id"), "");
  assert.equal(ui.content.value, `Introduction\n\n${marker(newId)}\n\n${marker(firstId)}\n\n${marker(secondId)}`);
});

test("removing the old marker without saving lets a new round start immediately", async () => {
  const ui = fixture("Article text", { existingId: firstId });
  ui.select(ui.content.value.length);
  await ui.open.fire("click");
  assert.equal(ui.requests.length, 0, "deleted marker is not loaded through stale server state");
  assert.equal(ui.form.controls.id.value, "");
  await ui.form.fire("submit");
  assert.equal(ui.requests[0].body.get("id"), "");
  assert.equal(ui.content.value, `Article text\n\n${marker(newId)}\n\n`);
});

test("selecting one of several modules edits only that round without changing the article", async () => {
  const text = `${marker(firstId)}\n\n${marker(secondId)}`;
  const ui = fixture(text);
  const start = text.indexOf(marker(secondId));
  ui.select(start, start + marker(secondId).length);
  await ui.open.fire("click");
  assert.equal(new URL(ui.requests[0].url).searchParams.get("id"), secondId);
  assert.equal(ui.choice.hidden, true);
  assert.equal(ui.form.controls.id.value, secondId);
  ui.form.controls.title.value = "Updated second round";
  await ui.form.fire("submit");
  assert.equal(ui.requests[1].body.get("id"), secondId);
  assert.equal(ui.requests[1].body.get("title"), "Updated second round");
  assert.equal(ui.content.value, text);
});

test("new insertion preserves selected prose", async () => {
  const ui = fixture("Text to keep, with a following sentence.");
  ui.select(0, "Text to keep".length);
  await ui.open.fire("click");
  await ui.form.fire("submit");
  assert.equal(ui.content.value, `Text to keep\n\n${marker(newId)}\n\n, with a following sentence.`);
});

test("loading disables New and a bypassed click cannot reset the pending request", async () => {
  let resolve;
  const pending = new Promise(done => { resolve = done; });
  const ui = fixture(marker(firstId), { fetch: () => pending });
  const opening = ui.open.fire("click");
  assert.equal(ui.create.disabled, true);
  await ui.create.fire("click");
  assert.equal(ui.create.disabled, true);
  resolve(response({ ok: true, lottery: lottery(firstId, true), shortcode: marker(firstId) }));
  await opening;
  assert.equal(ui.form.controls.id.value, firstId);
  assert.equal(ui.create.disabled, false);
  assert.equal(ui.submit.disabled, true);
});

test("a load failure still allows an explicit fresh round", async () => {
  const ui = fixture(marker(firstId), {
    fetch: request => request.method === "GET"
      ? response({ ok: false, error: "Cannot read old round" }, false)
      : response({ ok: true, lottery: lottery(newId), shortcode: marker(newId) }),
  });
  await ui.open.fire("click");
  assert.equal(ui.submit.disabled, true, "a failed load cannot update unknown settings");
  assert.equal(ui.create.disabled, false);
  assert.equal(ui.error.hidden, false);
  await ui.create.fire("click");
  assert.equal(ui.error.hidden, true);
  assert.equal(ui.submit.disabled, false);
  await ui.form.fire("submit");
  assert.equal(ui.requests[1].body.get("id"), "");
  assert.equal(ui.content.value, `${marker(firstId)}\n\n${marker(newId)}\n\n`);
});

test("canceling a fresh round does not create an activity or alter the article", async () => {
  const text = marker(firstId);
  const ui = fixture(text);
  await ui.open.fire("click");
  await ui.create.fire("click");
  await ui.close.fire("click");
  assert.equal(ui.dialog.open, false);
  assert.equal(ui.requests.length, 1);
  assert.equal(ui.requests[0].method, "GET");
  assert.equal(ui.content.value, text);
});

test("inline and fenced examples do not select old activities", async () => {
  const ui = fixture(`Example ${marker(firstId)}\n\n\x60\x60\x60text\n${marker(secondId)}\n\x60\x60\x60`);
  await ui.open.fire("click");
  assert.equal(ui.requests.length, 0);
  assert.equal(ui.form.controls.id.value, "");
});

(async () => {
  for (const { name, run } of tests) {
    await run();
    console.log(`[PASS] ${name}`);
  }
  vm.runInNewContext(source, { document: { querySelector: () => null, querySelectorAll: () => [] }, HTMLDialogElement: Dialog });
  console.log(`${tests.length} editor tests passed.`);
})().catch(error => { console.error(error); process.exitCode = 1; });
