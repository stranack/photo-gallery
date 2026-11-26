/* Place-name suggestions from GeoNames for a Location box (owner only).
 *   PlaceSuggest(input, onChange)
 * Typing shows matching places; picking one fills the box with
 * "Town, Province, Country". onChange(id) gets the GeoNames id of the picked
 * place, or '' once the text no longer matches it. */
(function () {
  'use strict';
  var n = 0;
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  window.PlaceSuggest = function (input, onChange) {
    var id = 'place-list-' + (++n);
    var box = document.createElement('div');
    box.className = 'place-box';
    input.parentNode.insertBefore(box, input);
    box.appendChild(input);
    var list = document.createElement('ul');
    list.className = 'place-list';
    list.id = id;
    list.setAttribute('role', 'listbox');
    list.hidden = true;
    box.appendChild(list);
    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', id);
    input.setAttribute('aria-expanded', 'false');
    input.setAttribute('autocomplete', 'off');

    var items = [], active = -1, timer = null, seq = 0, picked = null;
    function close() { list.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; input.removeAttribute('aria-activedescendant'); }
    function show(html) { list.innerHTML = html; list.hidden = false; input.setAttribute('aria-expanded', 'true'); }
    function mark(i) {
      active = i;
      list.querySelectorAll('[role=option]').forEach(function (li, k) { li.setAttribute('aria-selected', String(k === i)); });
      if (i >= 0) input.setAttribute('aria-activedescendant', id + '-' + i); else input.removeAttribute('aria-activedescendant');
    }
    function pick(i) {
      var p = items[i];
      if (!p) return;
      input.value = p.label;
      picked = p;
      close();
      onChange(p.id);
      input.dispatchEvent(new Event('input', { bubbles: true }));
    }
    function search() {
      var q = input.value.trim();
      if (q.split(',')[0].trim().length < 2) { close(); return; }
      var mine = ++seq;
      fetch('place.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (mine !== seq || document.activeElement !== input) return;
          if (j.error) { show('<li class="place-note">' + esc(j.error) + '</li>'); items = []; return; }
          items = j.places || [];
          if (!items.length) { show('<li class="place-note">No matching places. Type it as you like.</li>'); return; }
          show(items.map(function (p, i) {
            return '<li role="option" id="' + id + '-' + i + '" data-i="' + i + '" aria-selected="false">' + esc(p.label) +
              (p.kind ? ' <span>' + esc(p.kind) + '</span>' : '') + '</li>';
          }).join('') + '<li class="place-note">Place names from <a href="https://www.geonames.org" target="_blank" rel="noopener">GeoNames</a></li>');
          mark(-1);
        })
        .catch(function () { if (mine === seq) close(); });
    }
    input.addEventListener('input', function (e) {
      if (picked && input.value !== picked.label) { picked = null; onChange(''); }
      if (!e.isTrusted) return; // our own event after a pick
      clearTimeout(timer);
      timer = setTimeout(search, 250);
    });
    input.addEventListener('keydown', function (e) {
      if (list.hidden || !items.length) return;
      if (e.key === 'ArrowDown') { e.preventDefault(); mark((active + 1) % items.length); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); mark(active <= 0 ? items.length - 1 : active - 1); }
      else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); e.stopPropagation(); pick(active); }
      else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(); }
    });
    list.addEventListener('mousedown', function (e) {
      var li = e.target.closest('[data-i]');
      if (li) { e.preventDefault(); pick(parseInt(li.getAttribute('data-i'), 10)); }
    });
    input.addEventListener('blur', function () { setTimeout(close, 150); });
    return {
      // Tell the box which place the current text came from (when opening an editor).
      set: function (placeId) { picked = placeId ? { id: placeId, label: input.value } : null; }
    };
  };
})();
