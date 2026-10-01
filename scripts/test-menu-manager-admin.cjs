#!/usr/bin/env node
"use strict";

// Run the actual admin script through DOM events without browser dependencies.
const assert = require("node:assert/strict");
const fs = require("node:fs");
const path = require("node:path");
const vm = require("node:vm");
const crypto = require("node:crypto").webcrypto;
const source = fs.readFileSync(path.join(__dirname, "../plugins/menu-manager/assets/admin.js"), "utf8");

class Element {
  constructor(tag = "div") {
    this.tagName = tag.toUpperCase();
    this.attributes = {};
    this.dataset = {};
    this.children = [];
    this.listeners = {};
    this.value = "";
    this.textContent = "";
    this.disabled = false;
    this.hidden = false;
    this.required = false;
    this.parentElement = null;
  }
  setAttribute(name, value) {
    this.attributes[name] = String(value);
    if (name.startsWith("data-")) this.dataset[name.slice(5).replace(/-([a-z])/g, (_, char) => char.toUpperCase())] = String(value);
  }
  getAttribute(name) { return this.attributes[name] ?? null; }
  hasAttribute(name) { return Object.hasOwn(this.attributes, name); }
  matches(selector) {
    const match = selector.match(/^([a-z]+)?(?:\[([^=\]]+)(?:="([^"]*)")?\])?$/i);
    if (!match) return false;
    return (!match[1] || this.tagName === match[1].toUpperCase())
      && (!match[2] || this.hasAttribute(match[2]) && (match[3] === undefined || this.getAttribute(match[2]) === match[3]));
  }
  closest(selector) { return this.matches(selector) ? this : this.parentElement?.closest(selector) ?? null; }
  querySelectorAll(selector) {
    const selectors = selector.split(",").map(part => part.trim());
    const found = [];
    const visit = node => node.children.forEach(child => {
      if (selectors.some(part => child.matches(part))) found.push(child);
      visit(child);
    });
    visit(this);
    return found;
  }
  querySelector(selector) { return this.querySelectorAll(selector)[0] ?? null; }
  append(...nodes) {
    nodes.forEach(node => {
      if (node.fragment) {
        [...node.children].forEach(child => this.append(child));
      } else {
        node.remove();
        node.parentElement = this;
        this.children.push(node);
      }
    });
  }
  remove() {
    if (!this.parentElement) return;
    const parent = this.parentElement;
    parent.children.splice(parent.children.indexOf(this), 1);
    this.parentElement = null;
  }
  insertBefore(node, reference) {
    node.remove();
    const index = this.children.indexOf(reference);
    assert.ok(index >= 0, "insertBefore reference belongs to the parent");
    node.parentElement = this;
    this.children.splice(index, 0, node);
  }
  get previousElementSibling() {
    if (!this.parentElement) return null;
    return this.parentElement.children[this.parentElement.children.indexOf(this) - 1] ?? null;
  }
  get nextElementSibling() {
    if (!this.parentElement) return null;
    return this.parentElement.children[this.parentElement.children.indexOf(this) + 1] ?? null;
  }
  cloneNode(deep) {
    const clone = new Element(this.tagName);
    Object.entries(this.attributes).forEach(([name, value]) => clone.setAttribute(name, value));
    for (const property of ["value", "textContent", "disabled", "hidden", "required", "fragment"]) clone[property] = this[property];
    if (deep) this.children.forEach(child => clone.append(child.cloneNode(true)));
    return clone;
  }
  addEventListener(type, callback) { (this.listeners[type] ??= []).push(callback); }
  fire(type, target = this) {
    const event = { type, target, defaultPrevented: false, preventDefault() { this.defaultPrevented = true; } };
    (this.listeners[type] ?? []).forEach(callback => callback(event));
    return event;
  }
  focus() { this.focusCalls = (this.focusCalls ?? 0) + 1; }
}

function element(tag, attribute, value = "") {
  const node = new Element(tag);
  if (attribute) node.setAttribute(attribute, value);
  return node;
}
function row(index, id, destination = "route:home") {
  const node = element("li", "data-menu-row");
  const hidden = element("input", "type", "hidden");
  hidden.setAttribute("name", `items[${index}][id]`);
  hidden.value = id;
  node.append(hidden);
  for (const direction of ["up", "down"]) {
    const move = element("button", "data-menu-move", direction);
    move.value = `${direction}:${id}`;
    node.append(move);
  }
  const remove = element("button", "data-menu-remove");
  remove.value = `remove:${id}`;
  node.append(remove);
  for (const [name, tag, marker] of [["destination", "select", "data-menu-destination"], ["label", "input", "data-menu-label"], ["url", "input", "data-menu-url"]]) {
    const label = element("label", "for", `menu-${name}-${index}`);
    const field = element(tag, marker);
    field.setAttribute("name", `items[${index}][${name}]`);
    field.setAttribute("id", `menu-${name}-${index}`);
    field.value = name === "destination" ? destination : "";
    if (name === "url") {
      const wrapper = element("div", "data-menu-url-field");
      wrapper.append(label, field);
      node.append(wrapper);
    } else node.append(label, field);
  }
  return node;
}

function fixture(count = 2, limit = 100) {
  const root = element("section", "data-menu-manager");
  const form = element("form", "data-menu-form");
  form.setAttribute("data-menu-limit", limit);
  const list = element("ol", "data-menu-list");
  for (let index = 0; index < count; index++) list.append(row(String(index), `existing-${index}`));
  const template = element("template", "data-menu-template");
  template.content = Object.assign(element("fragment"), { fragment: true });
  template.content.append(row("__INDEX__", "template-id"));
  const add = element("button", "data-menu-add");
  add.textContent = "添加菜单项";
  const destination = element("select", "data-menu-new-destination");
  destination.value = "route:home";
  const empty = element("p", "data-menu-empty");
  const unsaved = element("span", "data-menu-unsaved");
  unsaved.hidden = true;
  const status = element("p", "data-menu-status");
  form.append(list, destination, add, empty, unsaved, status);
  root.append(form, template);
  const window = { crypto, listeners: {}, addEventListener(type, callback) { (this.listeners[type] ??= []).push(callback); } };
  const document = { querySelector: selector => root.matches(selector) ? root : null };
  vm.runInNewContext(source, { document, window, Uint8Array });
  const rows = () => [...list.children];
  const click = button => {
    if (button.disabled) return null;
    return root.fire("click", button);
  };
  const unload = () => {
    const event = { prevented: false, preventDefault() { this.prevented = true; } };
    (window.listeners.beforeunload ?? []).forEach(callback => callback(event));
    return event;
  };
  return { root, form, list, add, destination, empty, unsaved, status, rows, click, unload };
}

const ui = fixture();
assert.equal(ui.rows()[0].querySelector('[data-menu-move="up"]').disabled, true, "first item cannot move up");
assert.equal(ui.rows()[1].querySelector('[data-menu-move="down"]').disabled, true, "last item cannot move down");
assert.equal(ui.empty.hidden, true, "nonempty list hides the empty state");
assert.equal(ui.unload().prevented, false, "opening the editor does not mark it as changed");
ui.destination.value = "custom";
assert.equal(ui.click(ui.add).defaultPrevented, true, "adding an item stays in the editor");
const custom = ui.rows()[2];
assert.equal(custom.querySelector("[data-menu-destination]").value, "custom", "new row follows the selected destination");
assert.equal(custom.querySelector("[data-menu-url-field]").hidden, false, "custom destination shows URL input");
assert.equal(custom.querySelector("[data-menu-url]").required, true, "custom destination requires a URL");
assert.equal(custom.querySelector("[data-menu-label]").required, true, "custom destination requires a label");
assert.equal(custom.querySelector("[data-menu-label]").focusCalls, 1, "new row receives keyboard focus");
assert.equal(ui.unsaved.hidden, false, "adding an item reveals the unsaved notice");
assert.equal(ui.unload().prevented, true, "leaving with unsaved additions prompts the user");
assert.equal(ui.status.textContent, "添加菜单项", "adding an item updates live status");
const identity = custom.querySelector('input[type="hidden"]').value;
assert.match(identity, /^[a-f0-9]{32}$/, "client-created row has a random identity");
assert.notEqual(identity, "template-id", "client does not reuse the template identity");
assert.equal(custom.querySelector("[data-menu-remove]").value, `remove:${identity}`, "delete submitter uses the new row identity");
assert.equal(custom.querySelector('[data-menu-move="up"]').value, `up:${identity}`, "move submitter uses the new row identity");
assert.equal(custom.querySelector("[data-menu-label]").getAttribute("name"), "items[2][label]", "template field indices are replaced");
assert.equal(custom.querySelector('label[for="menu-label-2"]').getAttribute("for"), custom.querySelector("[data-menu-label]").getAttribute("id"), "cloned labels address their own input");

custom.querySelector("[data-menu-destination]").value = "page:1";
ui.form.fire("change", custom.querySelector("[data-menu-destination]"));
assert.equal(custom.querySelector("[data-menu-url-field]").hidden, true, "internal destination hides the custom URL field");
assert.equal(custom.querySelector("[data-menu-url]").required, false, "internal destination does not require a custom URL");
assert.equal(custom.querySelector("[data-menu-label]").required, false, "internal destination allows a title fallback");

const originalFirst = ui.rows()[0];
const originalSecond = ui.rows()[1];
ui.click(custom.querySelector('[data-menu-move="up"]'));
assert.deepEqual(ui.rows(), [originalFirst, custom, originalSecond], "moving up changes DOM submission order");
ui.click(custom.querySelector('[data-menu-move="up"]'));
assert.equal(ui.rows()[0], custom, "a second move reaches the first position");
assert.equal(custom.querySelector('[data-menu-move="up"]').disabled, true, "move boundaries update after sorting");
ui.click(custom.querySelector('[data-menu-move="down"]'));
assert.equal(ui.rows()[1], custom, "moving down preserves the same item and its fields");
const following = ui.rows()[2];
ui.click(custom.querySelector("[data-menu-remove]"));
assert.equal(ui.rows().length, 2, "remove deletes one row");
assert.equal(following.querySelector("[data-menu-destination]").focusCalls, 1, "remove moves keyboard focus to the next row");

ui.click(ui.add);
const addedAgain = ui.rows()[2];
assert.notEqual(addedAgain.querySelector('input[type="hidden"]').value, identity, "successive additions do not collide");
assert.equal(addedAgain.querySelector("[data-menu-label]").getAttribute("name"), "items[3][label]", "removed field indices are not reused");
const names = ui.rows().flatMap(node => node.querySelectorAll("[name]").map(input => input.getAttribute("name")));
assert.equal(new Set(names).size, names.length, "each item submits distinct field names");
ui.form.fire("submit");
assert.equal(ui.unload().prevented, false, "saving the form clears the unload warning");
ui.form.fire("input", addedAgain.querySelector("[data-menu-label]"));
assert.equal(ui.unload().prevented, true, "editing a saved form marks it changed again");

const capped = fixture(99);
capped.click(capped.add);
assert.equal(capped.rows().length, 100, "adding reaches the configured menu limit");
assert.equal(capped.add.disabled, true, "menu limit disables additions");
capped.root.fire("click", capped.add);
assert.equal(capped.rows().length, 100, "guard rejects additions even if an event bypasses a disabled button");
capped.click(capped.rows()[0].querySelector("[data-menu-remove]"));
assert.equal(capped.add.disabled, false, "removing an item permits another addition");

const emptied = fixture(1);
emptied.click(emptied.rows()[0].querySelector("[data-menu-remove]"));
assert.equal(emptied.rows().length, 0, "last row can be removed");
assert.equal(emptied.empty.hidden, false, "empty list shows its explanation");
assert.equal(emptied.add.focusCalls, 1, "removing the last row focuses Add");

vm.runInNewContext(source, { document: { querySelector: () => null } });
console.log("Menu manager admin tests passed.");
