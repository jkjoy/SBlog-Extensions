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
    const current = rows();
    root.querySelector('[data-menu-empty]').hidden = current.length > 0;
    add.disabled = current.length >= Number(form.dataset.menuLimit);
    current.forEach((row, index) => {
      row.querySelector('[data-menu-move="up"]').disabled = index === 0;
      row.querySelector('[data-menu-move="down"]').disabled = index === current.length - 1;
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
      newRow.querySelector('input[type="hidden"]').value = id;
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
      row.remove(); sync(); changed();
      (next ? next.querySelector('[data-menu-destination]') : add).focus();
    } else if (row && button.matches('[data-menu-move]')) {
      event.preventDefault();
      if (button.dataset.menuMove === 'up' && row.previousElementSibling) {
        list.insertBefore(row, row.previousElementSibling);
      } else if (button.dataset.menuMove === 'down' && row.nextElementSibling) {
        list.insertBefore(row.nextElementSibling, row);
      }
      sync(); changed(); button.focus();
    }
  });
  form.addEventListener('input', changed);
  form.addEventListener('change', event => {
    const row = event.target.closest('[data-menu-row]');
    if (row) syncRow(row);
    changed();
  });
  form.addEventListener('submit', () => { dirty = false; });
  window.addEventListener('beforeunload', event => {
    if (!dirty) return;
    event.preventDefault(); event.returnValue = '';
  });
  sync();
})();
