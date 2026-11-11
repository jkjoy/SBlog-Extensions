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
  replaceChildren(...nodes) { [...this.children].forEach(node => node.remove()); this.append(...nodes); }
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
  hidden.setAttribute("data-menu-id", "");
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
  for (const [name, tag, marker] of [["destination", "select", "data-menu-destination"], ["label", "input", "data-menu-label"], ["parent_id", "select", "data-menu-parent"], ["url", "input", "data-menu-url"]]) {
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
  const document = { querySelector: selector => root.matches(selector) ? root : null, createElement: tag => new Element(tag) };
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

const nested = fixture(5);
const [a, b, c, d, e] = nested.rows();
const idOf = node => node.querySelector('[data-menu-id]').value;
const parent = node => node.querySelector('[data-menu-parent]');
const setParent = (ui, node, value) => { parent(node).value = value; ui.form.fire('change', parent(node)); };
setParent(nested, b, idOf(a));
setParent(nested, c, idOf(b));
setParent(nested, d, idOf(a));
assert.equal(b.dataset.menuDepth, '1', 'children are visibly indented');
assert.equal(c.dataset.menuDepth, '2', 'grandchildren use the third level');
assert.equal(parent(a).children.find(option => option.value === idOf(c)).disabled, true, 'descendants cannot become their ancestor parent');
assert.equal(parent(b).children.find(option => option.value === idOf(d)).disabled, true, 'moving a whole subtree cannot exceed three levels');
setParent(nested, b, idOf(d));
assert.equal(parent(b).value, idOf(a), 'a programmatic invalid parent selection is rejected');
assert.equal(b.querySelector('[data-menu-move="up"]').disabled, true, 'first child cannot move above its parent');
nested.click(a.querySelector('[data-menu-move="down"]'));
assert.deepEqual(nested.rows(), [e, a, b, c, d], 'moving a root carries its entire subtree');
nested.click(d.querySelector('[data-menu-move="up"]'));
assert.deepEqual(nested.rows(), [e, a, d, b, c], 'moving among siblings keeps grandchildren with their parent');
nested.click(a.querySelector('[data-menu-remove]'));
assert.deepEqual(nested.rows(), [e, d, b, c], 'deleting a parent preserves the remaining rows');
assert.equal(parent(d).value, '', 'first child is promoted to the deleted parent level');
assert.equal(parent(b).value, '', 'all direct children are promoted');
assert.equal(parent(c).value, idOf(b), 'grandchildren retain their immediate parent');
assert.equal(c.dataset.menuDepth, '1', 'promoted subtree indentation refreshes');
d.querySelector('[data-menu-label]').value = 'Renamed parent';
nested.form.fire('input', d.querySelector('[data-menu-label]'));
assert.equal(parent(b).children.find(option => option.value === idOf(d)).textContent, 'Renamed parent', 'renaming refreshes parent choices');
setParent(nested, b, idOf(d));
assert.deepEqual(nested.rows(), [e, d, b, c], 'reparenting groups a subtree below its new parent');
assert.equal(c.dataset.menuDepth, '2', 'reparenting preserves the subtree levels');
console.log("Menu manager admin dependency-free tests passed.");

// Run the same editor through a real HTML DOM when jsdom is available locally.
// NODE_PATH=/temporary/node_modules node scripts/test-menu-manager-admin.cjs
let JSDOM;
try { ({ JSDOM } = require('jsdom')); } catch (error) { if (error.code !== 'MODULE_NOT_FOUND') throw error; }
if (JSDOM) {
  const rowHTML = (index, id, parentId = '') => `<li data-menu-row><input type="hidden" name="items[${index}][id]" value="${id}" data-menu-id><button type="submit" data-menu-move="up" name="operation" value="up:${id}">Up</button><button type="submit" data-menu-move="down" name="operation" value="down:${id}">Down</button><button type="submit" data-menu-remove name="operation" value="remove:${id}">Remove</button><label for="menu-destination-${index}">Destination</label><select id="menu-destination-${index}" name="items[${index}][destination]" data-menu-destination><option value="route:home">Home</option><option value="page:1">About</option><option value="custom">Custom</option></select><label for="menu-label-${index}">Label</label><input id="menu-label-${index}" name="items[${index}][label]" data-menu-label><label for="menu-parent-${index}">Parent</label><select id="menu-parent-${index}" name="items[${index}][parent_id]" data-menu-parent><option value="${parentId}" selected>${parentId}</option></select><div data-menu-url-field><label for="menu-url-${index}">URL</label><input id="menu-url-${index}" name="items[${index}][url]" data-menu-url></div><input type="checkbox" name="items[${index}][enabled]" value="1" checked><input type="checkbox" name="items[${index}][new_tab]" value="1" checked></li>`;
  const realFixture = (items) => {
    const dom = new JSDOM(`<section data-menu-manager><form data-menu-form data-menu-limit="100"><input name="csrf_token" value="fixture"><input name="items_present" value="1"><input name="managed" value="1"><ol data-menu-list>${items.map(([id, parentId], index) => rowHTML(index, id, parentId)).join('')}</ol><select name="new_destination" data-menu-new-destination><option value="route:home">Home</option><option value="custom">Custom</option></select><button type="submit" data-menu-add name="operation" value="add">Add</button><p data-menu-empty></p><p data-menu-status></p><span data-menu-unsaved hidden></span><input name="items_complete" value="1"></form><template data-menu-template>${rowHTML('__INDEX__', 'template')}</template></section>`, { url: 'https://menu.test/admin', runScripts: 'outside-only' });
    dom.window.eval(source);
    const document = dom.window.document;
    const getRows = () => [...document.querySelectorAll('[data-menu-row]')];
    const byId = id => getRows().find(node => node.querySelector('[data-menu-id]').value === id);
    const changeParent = (id, value) => { const field = byId(id).querySelector('[data-menu-parent]'); field.value = value; field.dispatchEvent(new dom.window.Event('change', { bubbles: true })); };
    const ids = () => getRows().map(node => node.querySelector('[data-menu-id]').value);
    return { dom, document, byId, ids, changeParent, rows: getRows };
  };
  const real = realFixture([['a', ''], ['b', 'a'], ['c', 'b'], ['d', 'a'], ['e', '']]);
  try {
    const invalid = real.byId('a').querySelector('[data-menu-parent] option[value="c"]');
    assert.equal(invalid.disabled, true, 'real select disables descendants');
    assert.equal(real.byId('b').querySelector('[data-menu-parent] option[value="d"]').disabled, true, 'real select accounts for descendant height');
    real.changeParent('a', 'c');
    assert.equal(real.byId('a').querySelector('[data-menu-parent]').value, '', 'real DOM rejects a forced cycle');
    real.byId('a').querySelector('[data-menu-move="down"]').click();
    assert.deepEqual(real.ids(), ['e', 'a', 'b', 'c', 'd']);
    real.byId('d').querySelector('[data-menu-move="up"]').click();
    assert.deepEqual(real.ids(), ['e', 'a', 'd', 'b', 'c']);
    real.byId('a').querySelector('[data-menu-remove]').click();
    assert.deepEqual(real.ids(), ['e', 'd', 'b', 'c']);
    assert.equal(real.byId('b').querySelector('[data-menu-parent]').value, '');
    assert.equal(real.byId('c').querySelector('[data-menu-parent]').value, 'b');
    real.changeParent('b', 'd');
    assert.equal(real.byId('c').dataset.menuDepth, '2');
    real.byId('d').querySelector('[data-menu-label]').value = '<Parent & name>';
    real.byId('d').querySelector('[data-menu-label]').dispatchEvent(new real.dom.window.Event('input', { bubbles: true }));
    assert.equal(real.byId('b').querySelector('[data-menu-parent] option[value="d"]').textContent, '<Parent & name>');
    assert.equal(real.document.querySelector('Parent'), null, 'labels remain text rather than injected HTML');
    real.document.querySelector('[data-menu-new-destination]').value = 'custom';
    real.document.querySelector('[data-menu-add]').click();
    const added = real.rows().at(-1);
    assert.equal(added.querySelector('[data-menu-parent]').value, '', 'new rows start as root items');
    assert.equal(real.document.activeElement, added.querySelector('[data-menu-label]'));
    added.querySelector('[data-menu-label]').value = 'New child';
    added.querySelector('[data-menu-url]').value = '/new';
    const newId = added.querySelector('[data-menu-id]').value;
    real.changeParent(newId, 'e');
    const data = new real.dom.window.FormData(real.document.querySelector('form'));
    const parentEntry = [...data].find(([key]) => key.includes('[parent_id]') && key.includes('items[5]'));
    assert.equal(parentEntry[1], 'e', 'FormData carries the new hierarchy');
    assert.equal(new Set(real.rows().flatMap(node => [...node.querySelectorAll('[name]')].map(field => field.name).filter(name => name.startsWith('items[')))).size, real.rows().length * 7, 'all row fields have unique submission names');
  } finally { real.dom.window.close(); }
  const full = realFixture(Array.from({ length: 99 }, (_, index) => [`row-${index}`, '']));
  try {
    full.document.querySelector('[data-menu-add]').click();
    assert.equal(full.rows().length, 100);
    const data = new full.dom.window.FormData(full.document.querySelector('form'));
    assert.equal([...data.keys()].filter(key => key.startsWith('items[')).length, 700, '100 full rows stay below PHP default input limit');
    assert.equal(data.get('items_complete'), '1', 'the trailing completeness marker survives a full menu');
    assert.equal(full.document.querySelector('[data-menu-add]').disabled, true);
  } finally { full.dom.window.close(); }
  console.log('Menu manager real DOM hierarchy tests passed.');
} else {
  console.log('SKIP optional real DOM checks: provide jsdom through NODE_PATH.');
}
