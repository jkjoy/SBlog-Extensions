(() => {
  'use strict';
  const root = document.querySelector('[data-menu-manager]');
  if (!root) return;
  const form = root.querySelector('[data-menu-form]');
  const list = root.querySelector('[data-menu-list]');
  const template = root.querySelector('[data-menu-template]');
  const status = root.querySelector('[data-menu-status]');
  const unsaved = root.querySelector('[data-menu-unsaved]');
  const add = root.querySelector('[data-menu-add]');
  const rows = () => Array.from(list.querySelectorAll('[data-menu-row]'));
  const identity = row => row.querySelector('[data-menu-id]').value;
  const parentOf = row => row.querySelector('[data-menu-parent]').value;
  const hierarchy = current => {
    const byId = new Map(current.map(item => [identity(item), item]));
    const ancestors = new Map();
    const heights = new Map(current.map(row => [row, 0]));
    current.forEach(row => {
      const chain = new Set();
      let parent = parentOf(row);
      while (parent && byId.has(parent) && !chain.has(parent) && parent !== identity(row)) {
        chain.add(parent); parent = parentOf(byId.get(parent));
      }
      ancestors.set(row, chain);
    });
    current.forEach(row => ancestors.get(row).forEach(id => {
      const parent = byId.get(id);
      heights.set(parent, Math.max(heights.get(parent), ancestors.get(row).size - ancestors.get(parent).size));
    }));
    return { ancestors, heights };
  };
  const canParent = (row, candidate, tree) => row !== candidate && !tree.ancestors.get(candidate).has(identity(row))
    && tree.ancestors.get(candidate).size + 1 + tree.heights.get(row) <= 2;
  const reorder = () => {
    const current = rows();
    const ordered = [];
    const seen = new Set();
    const visit = parent => current.filter(row => parentOf(row) === parent).forEach(row => {
      if (seen.has(row)) return;
      seen.add(row); ordered.push(row); visit(identity(row));
    });
    visit('');
    current.filter(row => !seen.has(row)).forEach(row => ordered.push(row));
    if (ordered.some((row, index) => row !== current[index])) list.append(...ordered);
  };
  let sequence = rows().length;
  let dirty = false;
  const changed = () => { dirty = true; unsaved.hidden = false; };
  const syncRow = row => {
    const custom = row.querySelector('[data-menu-destination]').value === 'custom';
    row.querySelector('[data-menu-url-field]').hidden = !custom;
    row.querySelector('[data-menu-url]').required = custom;
    row.querySelector('[data-menu-label]').required = custom;
  };
  const sync = () => {
    reorder();
    const current = rows();
    const tree = hierarchy(current);
    root.querySelector('[data-menu-empty]').hidden = current.length > 0;
    add.disabled = current.length >= Number(form.dataset.menuLimit);
    current.forEach(row => {
      const parent = parentOf(row);
      const siblings = current.filter(item => parentOf(item) === parent);
      const index = siblings.indexOf(row);
      row.querySelector('[data-menu-move="up"]').disabled = index === 0;
      row.querySelector('[data-menu-move="down"]').disabled = index === siblings.length - 1;
      row.dataset.menuDepth = String(Math.min(2, tree.ancestors.get(row).size));
      const select = row.querySelector('[data-menu-parent]');
      const top = document.createElement('option');
      top.value = ''; top.textContent = '无（顶级菜单）';
      const options = current.map(candidate => {
        const option = document.createElement('option');
        const label = candidate.querySelector('[data-menu-label]').value.trim();
        const destination = candidate.querySelector('[data-menu-destination]');
        option.value = identity(candidate);
        option.textContent = '— '.repeat(Math.min(2, tree.ancestors.get(candidate).size)) + (label || destination.selectedOptions?.[0]?.textContent || destination.value);
        option.disabled = !canParent(row, candidate, tree);
        return option;
      });
      if (parent && !current.some(candidate => identity(candidate) === parent)) {
        const invalid = document.createElement('option');
        invalid.value = parent; invalid.textContent = '上级菜单无效，请重新选择';
        options.push(invalid);
      }
      select.replaceChildren(top, ...options);
      select.value = parent;
      row.dataset.menuParent = parent;
      syncRow(row);
    });
  };
  root.addEventListener('click', event => {
    const button = event.target.closest('button');
    if (!button) return;
    const row = button.closest('[data-menu-row]');
    if (button.matches('[data-menu-add]')) {
      event.preventDefault();
      if (rows().length >= Number(form.dataset.menuLimit)) return;
      const index = String(sequence++);
      const content = template.content.cloneNode(true);
      const newRow = content.querySelector('[data-menu-row]');
      newRow.querySelectorAll('[name], [id], [for]').forEach(node => {
        for (const attribute of ['name', 'id', 'for']) {
          if (node.hasAttribute(attribute)) node.setAttribute(attribute, node.getAttribute(attribute).replaceAll('__INDEX__', index));
        }
      });
      // Each row gets its own identity; never reuse the template's server-generated ID.
      const bytes = new Uint8Array(16);
      window.crypto.getRandomValues(bytes);
      const id = Array.from(bytes, byte => byte.toString(16).padStart(2, '0')).join('');
      newRow.querySelector('[data-menu-id]').value = id;
      newRow.querySelector('[data-menu-parent]').value = '';
      newRow.querySelector('[data-menu-move="up"]').value = 'up:' + id;
      newRow.querySelector('[data-menu-move="down"]').value = 'down:' + id;
      newRow.querySelector('[data-menu-remove]').value = 'remove:' + id;
      newRow.querySelector('[data-menu-destination]').value = root.querySelector('[data-menu-new-destination]').value;
      list.append(content);
      sync(); changed();
      newRow.querySelector('[data-menu-label]').focus();
      status.textContent = root.querySelector('[data-menu-add]').textContent.trim();
    } else if (row && button.matches('[data-menu-remove]')) {
      event.preventDefault();
      const next = row.nextElementSibling || row.previousElementSibling;
      rows().filter(item => parentOf(item) === identity(row)).forEach(item => { item.querySelector('[data-menu-parent]').value = parentOf(row); });
      row.remove(); sync(); changed();
      (next ? next.querySelector('[data-menu-destination]') : add).focus();
    } else if (row && button.matches('[data-menu-move]')) {
      event.preventDefault();
      const current = rows();
      const siblings = current.filter(item => parentOf(item) === parentOf(row));
      const position = siblings.indexOf(row);
      const up = button.dataset.menuMove === 'up';
      const neighbor = siblings[position + (up ? -1 : 1)];
      if (neighbor) {
        const moved = up ? row : neighbor;
        const before = up ? neighbor : row;
        const tree = hierarchy(current);
        current.filter(item => item === moved || tree.ancestors.get(item).has(identity(moved))).forEach(item => list.insertBefore(item, before));
      }
      sync(); changed(); button.focus();
    }
  });
  form.addEventListener('input', event => { if (event.target.matches('[data-menu-label]')) sync(); changed(); });
  form.addEventListener('change', event => {
    const row = event.target.closest('[data-menu-row]');
    if (row && event.target.matches('[data-menu-parent]')) {
      const parent = parentOf(row);
      const candidate = rows().find(item => identity(item) === parent);
      if (parent && (!candidate || !canParent(row, candidate, hierarchy(rows())))) {
        event.target.value = row.dataset.menuParent || '';
        status.textContent = '请选择有效的上级菜单，菜单最多三级。';
      }
    }
    if (row) sync();
    changed();
  });
  form.addEventListener('submit', () => { dirty = false; });
  window.addEventListener('beforeunload', event => {
    if (!dirty) return;
    event.preventDefault(); event.returnValue = '';
  });
  sync();
})();
