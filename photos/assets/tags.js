/* Tag field for the owner: tags as chips, with suggestions from tags already
 * in the gallery and a way to add a new one.
 *   TagInput(input, knownTags)
 * The original input stays the source of truth (a "a, b, c" string) and is
 * hidden; knownTags() returns the gallery's tags. Call .refresh() after
 * setting input.value in code. */
(function () {
  'use strict';
  var n = 0;
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function split(v) { return String(v || '').split(',').map(function (t) { return t.trim(); }).filter(Boolean); }

  window.TagInput = function (input, knownTags) {
    var id = 'tag-list-' + (++n);
    var box = document.createElement('div');
    box.className = 'tag-input';
    box.innerHTML = '<span class="tag-chips"></span><input type="text" class="tag-entry" autocomplete="off" placeholder="Add a tag" ' +
      'role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="' + id + '">' +
      '<ul class="tag-sugg" id="' + id + '" role="listbox" hidden></ul>';
    input.parentNode.insertBefore(box, input.nextSibling);
    input.hidden = true;
    var chips = box.querySelector('.tag-chips'), entry = box.querySelector('.tag-entry'), list = box.querySelector('.tag-sugg');
    if (input.id) {
      // The field's label now points at the visible box.
      var lab = document.querySelector('label[for="' + input.id + '"]');
      if (lab) { entry.id = input.id + '-entry'; lab.htmlFor = entry.id; }
    }
    var options = [], active = -1;

    function tags() { return split(input.value); }
    function set(arr) {
      input.value = arr.join(', ');
      input.dispatchEvent(new Event('input', { bubbles: true }));
      draw();
    }
    function has(t) { return tags().some(function (x) { return x.toLowerCase() === t.toLowerCase(); }); }
    function add(t) {
      t = t.replace(/\s+/g, ' ').trim().slice(0, 40);
      if (!t || has(t)) { entry.value = ''; close(); return; }
      // Use the gallery's spelling of a tag that already exists.
      var known = (knownTags() || []).filter(function (k) { return k.toLowerCase() === t.toLowerCase(); })[0];
      set(tags().concat([known || t]));
      entry.value = '';
      close();
    }
    function remove(i) { var a = tags(); a.splice(i, 1); set(a); entry.focus(); }
    function draw() {
      chips.innerHTML = tags().map(function (t, i) {
        return '<span class="tag-pill">' + esc(t) + '<button type="button" data-i="' + i + '" aria-label="Remove tag ' + esc(t) + '">×</button></span>';
      }).join('');
    }
    function close() { list.hidden = true; entry.setAttribute('aria-expanded', 'false'); active = -1; entry.removeAttribute('aria-activedescendant'); }
    function mark(i) {
      active = i;
      list.querySelectorAll('[role=option]').forEach(function (li, k) { li.setAttribute('aria-selected', String(k === i)); });
      if (i >= 0) entry.setAttribute('aria-activedescendant', id + '-' + i); else entry.removeAttribute('aria-activedescendant');
    }
    function suggest() {
      var q = entry.value.trim(), ql = q.toLowerCase();
      if (!q) { close(); return; }
      var matches = (knownTags() || []).filter(function (k) { return k.toLowerCase().indexOf(ql) >= 0 && !has(k); })
        .sort(function (a, b) { return (a.toLowerCase().indexOf(ql) === 0 ? 0 : 1) - (b.toLowerCase().indexOf(ql) === 0 ? 0 : 1) || a.localeCompare(b); })
        .slice(0, 8);
      options = matches.map(function (m) { return { tag: m, label: esc(m) }; });
      var exact = matches.some(function (m) { return m.toLowerCase() === ql; });
      if (!exact && !has(q)) options.push({ tag: q, label: 'Add new tag “' + esc(q) + '”', isNew: true });
      if (!options.length) { close(); return; }
      list.innerHTML = options.map(function (o, i) {
        return '<li role="option" id="' + id + '-' + i + '" data-i="' + i + '" aria-selected="false"' + (o.isNew ? ' class="new"' : '') + '>' + o.label + '</li>';
      }).join('');
      list.hidden = false;
      entry.setAttribute('aria-expanded', 'true');
      mark(0);
    }
    entry.addEventListener('input', function () {
      if (entry.value.indexOf(',') >= 0) { // typing a comma adds what came before it
        var parts = entry.value.split(',');
        entry.value = parts.pop();
        parts.forEach(function (t) { if (t.trim()) add(t); });
      }
      suggest();
    });
    entry.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault(); e.stopPropagation();
        if (!list.hidden && options[active]) add(options[active].tag); else if (entry.value.trim()) add(entry.value);
      } else if (e.key === 'ArrowDown' && !list.hidden) { e.preventDefault(); mark((active + 1) % options.length); }
      else if (e.key === 'ArrowUp' && !list.hidden) { e.preventDefault(); mark(active <= 0 ? options.length - 1 : active - 1); }
      else if (e.key === 'Escape' && !list.hidden) { e.preventDefault(); e.stopPropagation(); close(); }
      else if (e.key === 'Backspace' && !entry.value && tags().length) { remove(tags().length - 1); }
    });
    entry.addEventListener('blur', function () {
      setTimeout(function () { if (entry.value.trim()) add(entry.value); close(); }, 150);
    });
    list.addEventListener('mousedown', function (e) {
      var li = e.target.closest('[data-i]');
      if (li) { e.preventDefault(); add(options[+li.getAttribute('data-i')].tag); entry.focus(); }
    });
    chips.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-i]');
      if (b) remove(+b.getAttribute('data-i'));
    });
    box.addEventListener('click', function (e) { if (e.target === box || e.target === chips) entry.focus(); });
    draw();
    return {
      refresh: function () { entry.value = ''; close(); draw(); },
      // Add anything still typed in the box, before saving.
      commit: function () { if (entry.value.trim()) add(entry.value); }
    };
  };
})();
