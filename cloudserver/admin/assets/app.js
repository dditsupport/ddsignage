// Admin dashboard glue. Keep it dependency-free beyond Bootstrap + SortableJS,
// both loaded via CDN in _layout.php.

(function () {
  'use strict';

  // ----- Playlist editor: drag-reorder, add/remove items ----------------
  const list = document.getElementById('items-list');
  if (!list) return;

  const form = document.getElementById('plf');
  const tpl  = document.getElementById('new-item-tpl');
  const addSel  = document.getElementById('add-media');
  const addDur  = document.getElementById('add-duration');
  const addBtn  = document.getElementById('add-btn');

  // Drag-to-reorder. Sort order is reconstructed from DOM order on submit.
  if (window.Sortable) {
    Sortable.create(list, {
      animation: 150,
      handle: '.handle',
      ghostClass: 'sortable-ghost',
      chosenClass: 'sortable-chosen',
    });
  }

  // Remove-item buttons (covers existing rows + future-added rows).
  list.addEventListener('click', function (e) {
    const btn = e.target.closest('.remove-item');
    if (!btn) return;
    const li = btn.closest('li');
    if (li) li.remove();
  });

  // Add-item button: clone template, fill in media metadata, append.
  let nextNewKey = 1;
  if (addBtn) {
    addBtn.addEventListener('click', function () {
      const opt = addSel.options[addSel.selectedIndex];
      if (!opt || !opt.value) return;

      const key = 'new_' + (nextNewKey++);
      const html = tpl.innerHTML.replace(/__KEY__/g, key);
      const wrap = document.createElement('div');
      wrap.innerHTML = html.trim();
      const li = wrap.firstElementChild;

      li.querySelector('.type-label').textContent = '[' + (opt.dataset.type || '') + ']';
      li.querySelector('.name-label').textContent = opt.dataset.name || '';
      li.querySelector('input[name$="[media_id]"]').value = opt.value;
      const dur = li.querySelector('.dur');
      if (dur) {
        dur.value = parseInt(addDur.value || '10', 10) || 10;
        if (opt.dataset.type === 'video') dur.disabled = true;
      }
      // Video volume only matters for video items — enable it when the
      // selected item is a video, leave disabled otherwise.
      const vidVol = li.querySelector('.vidvol');
      if (vidVol) {
        vidVol.disabled = opt.dataset.type !== 'video';
      }

      list.appendChild(li);
      addSel.selectedIndex = 0;
    });
  }

  // ----- Background music: same drag-add-remove pattern, separate list ----
  const bgList = document.getElementById('bg-items-list');
  if (bgList) {
    if (window.Sortable) {
      Sortable.create(bgList, {
        animation: 150, handle: '.handle',
        ghostClass: 'sortable-ghost', chosenClass: 'sortable-chosen',
      });
    }
    bgList.addEventListener('click', function (e) {
      const btn = e.target.closest('.bg-remove-item');
      if (!btn) return;
      const li = btn.closest('li');
      if (li) li.remove();
    });

    const bgAddSel = document.getElementById('bg-add-media');
    const bgAddBtn = document.getElementById('bg-add-btn');
    const bgTpl   = document.getElementById('bg-new-item-tpl');
    let nextBgKey = 1;
    if (bgAddBtn && bgAddSel && bgTpl) {
      bgAddBtn.addEventListener('click', function () {
        const opt = bgAddSel.options[bgAddSel.selectedIndex];
        if (!opt || !opt.value) return;
        const key = 'bgnew_' + (nextBgKey++);
        const html = bgTpl.innerHTML.replace(/__BGKEY__/g, key);
        const wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        const li = wrap.firstElementChild;
        li.querySelector('.bg-name-label').textContent = opt.dataset.name || '';
        li.querySelector('input[name$="[audio_media_id]"]').value = opt.value;
        bgList.appendChild(li);
        bgAddSel.selectedIndex = 0;
      });
    }
  }

  // On submit, append item_order[] AND bg_item_order[] inputs reflecting
  // current DOM order in their respective lists.
  if (form) {
    form.addEventListener('submit', function () {
      form.querySelectorAll('input[name="item_order[]"]').forEach(n => n.remove());
      list.querySelectorAll('li').forEach(li => {
        const key = li.dataset.id || (li.querySelector('input[name^="items["]') || {}).name?.match(/items\[([^\]]+)\]/)?.[1];
        if (!key) return;
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = 'item_order[]'; i.value = key;
        form.appendChild(i);
      });

      if (bgList) {
        form.querySelectorAll('input[name="bg_item_order[]"]').forEach(n => n.remove());
        bgList.querySelectorAll('li').forEach(li => {
          const key = li.dataset.id ||
            (li.querySelector('input[name^="bg_items["]') || {}).name?.match(/bg_items\[([^\]]+)\]/)?.[1];
          if (!key) return;
          const i = document.createElement('input');
          i.type = 'hidden'; i.name = 'bg_item_order[]'; i.value = key;
          form.appendChild(i);
        });
      }
    });
  }
})();
