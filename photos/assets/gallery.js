/* Photo Gallery: albums, grid, viewer, slideshow, favourites, captions. */
(function () {
  'use strict';

  var G = window.GALLERY || { albums: [] };
  var $ = function (id) { return document.getElementById(id); };
  var HEART = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.3 4.6 13a4.7 4.7 0 0 1 6.6-6.7l.8.8.8-.8a4.7 4.7 0 0 1 6.6 6.7z"/></svg>';

  var byId = {};
  G.albums.forEach(function (a) { a.photos.forEach(function (p) { byId[p.id] = p; }); });

  // ---- Favourites, kept in this browser only ----------------------------
  var FAV_KEY = 'gallery-favourites:' + location.pathname;
  var favs = {};
  try { (JSON.parse(localStorage.getItem(FAV_KEY) || '[]') || []).forEach(function (id) { favs[id] = 1; }); } catch (e) {}
  function saveFavs() {
    try { localStorage.setItem(FAV_KEY, JSON.stringify(Object.keys(favs))); } catch (e) {}
  }
  function isFav(id) { return !!favs[id]; }
  function toggleFav(id) {
    if (favs[id]) delete favs[id]; else favs[id] = 1;
    saveFavs();
    document.querySelectorAll('.fav[data-id="' + id + '"]').forEach(function (b) {
      b.classList.toggle('on', isFav(id));
      b.setAttribute('aria-pressed', String(isFav(id)));
    });
    renderTabs();
    if (view === 'favourites') render();
    if (lb.open) syncViewerFav();
  }
  function favPhotos() {
    var out = [];
    G.albums.forEach(function (a) { a.photos.forEach(function (p) { if (favs[p.id]) out.push(p); }); });
    return out;
  }

  // ---- Which album is showing ------------------------------------------
  var view = null;
  function viewFromHash() {
    var h = decodeURIComponent(location.hash.replace(/^#/, ''));
    if (h === 'favourites') return 'favourites';
    for (var i = 0; i < G.albums.length; i++) if (G.albums[i].slug === h) return h;
    return G.albums.length ? G.albums[0].slug : null;
  }
  function currentAlbum() {
    for (var i = 0; i < G.albums.length; i++) if (G.albums[i].slug === view) return G.albums[i];
    return null;
  }
  function currentPhotos() {
    if (view === 'favourites') return favPhotos();
    var a = currentAlbum();
    return a ? a.photos : [];
  }

  function renderTabs() {
    var tabs = $('tabs');
    if (!tabs) return;
    var html = '';
    G.albums.forEach(function (a) {
      html += '<button class="tab" role="tab" data-view="' + esc(a.slug) + '" aria-selected="' + (view === a.slug) + '">' + esc(a.name) + '</button>';
    });
    var n = Object.keys(favs).filter(function (id) { return byId[id]; }).length;
    if (G.albums.length) {
      html += '<button class="tab" role="tab" data-view="favourites" aria-selected="' + (view === 'favourites') + '" title="Favourites">' +
        HEART + '<span>Favourites</span>' + (n ? '<span class="n">' + n + '</span>' : '') + '</button>';
    }
    tabs.innerHTML = html;
  }

  function render() {
    renderTabs();
    var head = $('album-head');
    var photos = currentPhotos();
    if (!head) return;
    if (view === 'favourites') {
      head.innerHTML = '<h2>Favourites</h2><p>' + (photos.length ? count(photos.length) : 'Tap the heart on any photo to keep it here') + '</p>';
    } else if (currentAlbum()) {
      head.innerHTML = '<h2>' + esc(currentAlbum().name) + '</h2><p>' + count(photos.length) + '</p>';
    } else {
      head.innerHTML = '';
    }
    layout();
  }

  // ---- Justified rows: every row fills the width, photos keep their shape
  var lastWidth = 0;
  function layout() {
    var grid = $('grid');
    if (!grid) return;
    var photos = currentPhotos();
    var width = grid.clientWidth;
    lastWidth = width;
    var gap = parseFloat(getComputedStyle(document.documentElement).getPropertyValue('--gap')) || 6;
    var target = width < 560 ? 170 : width < 1000 ? 240 : 320;
    var html = '';
    var row = [];
    var rowAspect = 0;

    function flush(last) {
      if (!row.length) return;
      var avail = width - gap * (row.length - 1);
      var h = avail / rowAspect;
      if (last && h > target * 1.25) h = target; // a short last row stays left-aligned
      html += '<div class="row">';
      row.forEach(function (p) {
        var w = Math.floor(h * (p.w / p.h));
        html += '<div class="tile" role="button" tabindex="0" data-id="' + p.id + '" style="width:' + w + 'px;height:' + Math.round(h) + 'px"' +
          ' aria-label="' + esc(label(p) || 'Photo') + '">' +
          '<img src="' + esc(p.g) + '" alt="' + esc(label(p)) + '" loading="lazy" decoding="async" width="' + w + '" height="' + Math.round(h) + '" onload="this.classList.add(\'loaded\')">' +
          '<button class="fav' + (isFav(p.id) ? ' on' : '') + '" type="button" data-id="' + p.id + '" aria-label="Favourite" aria-pressed="' + isFav(p.id) + '">' + HEART + '</button>' +
          '</div>';
      });
      html += '</div>';
      row = [];
      rowAspect = 0;
    }

    photos.forEach(function (p) {
      row.push(p);
      rowAspect += p.w / p.h;
      if ((width - gap * (row.length - 1)) / rowAspect <= target) flush(false);
    });
    flush(true);
    grid.innerHTML = html;
  }

  // ---- Viewer -----------------------------------------------------------
  var lb = { open: false, list: [], i: 0, timer: null, idleTimer: null, token: 0 };
  var lbEl = $('lb');
  var img = $('lb-img');

  function openViewer(list, i, play) {
    if (!list.length) return;
    lb.list = list;
    lb.i = i;
    lb.open = true;
    lbEl.hidden = false;
    document.body.style.overflow = 'hidden';
    buildStrip();
    show(false);
    if (play) startShow(); else stopShow();
    wake();
  }
  function closeViewer() {
    stopShow();
    closeEditor();
    closeShare();
    lb.open = false;
    lbEl.hidden = true;
    document.body.style.overflow = '';
    if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(function () {});
  }
  function show(fade) {
    var p = lb.list[lb.i];
    var token = ++lb.token;
    $('lb-count').textContent = (lb.i + 1) + ' / ' + lb.list.length;
    renderCaption(p);
    syncViewerFav();
    closeEditor();
    markStrip();
    var next = new Image();
    var swap = function () {
      if (token !== lb.token) return;
      img.src = next.src;
      img.alt = label(p);
      img.classList.remove('fading');
    };
    if (fade) {
      img.classList.add('fading');
      next.onload = function () { setTimeout(swap, 350); };
    } else {
      if (!img.src || img.dataset.id !== p.id) img.src = p.g; // the grid copy shows at once
      next.onload = swap;
    }
    img.dataset.id = p.id;
    next.onerror = swap;
    next.src = p.l;
    [1, -1, 2].forEach(function (d) { // load the neighbours ahead of time
      var q = lb.list[(lb.i + d + lb.list.length) % lb.list.length];
      if (q) (new Image()).src = q.l;
    });
  }
  function go(d, fade) {
    if (!lb.list.length) return;
    lb.i = (lb.i + d + lb.list.length) % lb.list.length;
    show(!!fade);
  }
  // ---- Filmstrip of every photo in the album --------------------------
  var strip = $('lb-strip');
  function buildStrip() {
    var h = window.innerWidth < 760 ? 54 : 76;
    strip.innerHTML = lb.list.map(function (p, i) {
      var w = Math.round(h * Math.min(Math.max(p.w / p.h, 0.6), 1.8));
      return '<button type="button" data-i="' + i + '" style="width:' + w + 'px" aria-label="Photo ' + (i + 1) + (label(p) ? ': ' + esc(label(p)) : '') + '">' +
        '<img src="' + esc(p.g) + '" alt="" loading="lazy" decoding="async"></button>';
    }).join('');
    strip.hidden = lb.list.length < 2;
  }
  function markStrip() {
    var prev = strip.querySelector('.on');
    if (prev) { prev.classList.remove('on'); prev.removeAttribute('aria-current'); }
    var b = strip.querySelector('[data-i="' + lb.i + '"]');
    if (!b) return;
    b.classList.add('on');
    b.setAttribute('aria-current', 'true');
    strip.scrollTo({ left: b.offsetLeft - strip.clientWidth / 2 + b.offsetWidth / 2 });
  }
  strip.addEventListener('click', function (e) {
    var b = e.target.closest('button[data-i]');
    if (!b) return;
    e.stopPropagation();
    lb.i = parseInt(b.getAttribute('data-i'), 10);
    show(false);
    wake();
  });

  // ---- Caption under the photo, and making room for it -----------------
  function renderCaption(p) {
    var c = p.cap || {};
    $('cap-title').textContent = c.title || '';
    $('cap-meta').textContent = [c.date, c.location].filter(Boolean).join(' · ');
    $('cap-desc').textContent = c.description || '';
    $('cap-desc').title = c.description || '';
    fitStage();
  }
  function fitStage() {
    if (!lb.open) return;
    var below = (strip.hidden ? 12 : strip.offsetHeight) + $('lb-cap').offsetHeight + 8;
    $('lb-stage').style.bottom = Math.max(below, window.innerWidth < 760 ? 72 : 76) + 'px';
  }
  window.addEventListener('resize', fitStage);

  function syncViewerFav() {
    var p = lb.list[lb.i];
    if (p) $('lb-fav').setAttribute('aria-pressed', String(isFav(p.id)));
  }

  // ---- Slideshow --------------------------------------------------------
  function startShow() {
    stopShow();
    lbEl.classList.add('playing');
    $('lb-play').setAttribute('aria-label', 'Pause slideshow');
    lb.timer = setInterval(function () { go(1, true); }, (G.slideshowSeconds || 5) * 1000);
    wake();
  }
  function stopShow() {
    clearInterval(lb.timer);
    lb.timer = null;
    lbEl.classList.remove('playing', 'idle');
    $('lb-play').setAttribute('aria-label', 'Play slideshow');
  }
  function toggleShow() { if (lb.timer) stopShow(); else { startShow(); go(1, true); } }

  // Controls fade away after a few seconds without the mouse moving.
  function wake() {
    lbEl.classList.remove('idle');
    clearTimeout(lb.idleTimer);
    lb.idleTimer = setTimeout(function () {
      if (lb.open && lb.timer && !editing() && !sharing()) lbEl.classList.add('idle');
    }, 2800);
  }

  // ---- Captions (owner only) ------------------------------------------
  var form = $('cap-edit');
  function editing() { return form && !form.hidden; }
  function openEditor() {
    if (!form) return;
    stopShow();
    var p = lb.list[lb.i];
    var c = p.cap || {}, auto = p.auto || {};
    $('cap-t').value = c.title || '';
    $('cap-d').value = c.date || '';
    $('cap-l').value = c.location || '';
    $('cap-x').value = c.description || '';
    hint('cap-d', auto.date);
    hint('cap-l', auto.location);
    $('cap-reset').hidden = !p.custom;
    $('cap-msg').textContent = '';
    form.hidden = false;
    lbEl.classList.remove('idle');
    $('cap-t').focus();
  }
  // Under date and location: what the file name says, with a way back to it.
  function hint(field, auto) {
    var el = $(field + '-hint');
    var input = $(field);
    function update() {
      if (!auto) el.textContent = 'Not in the file name';
      else if (input.value.trim() === auto) el.textContent = 'From the file name';
      else {
        el.innerHTML = 'File name says: ' + esc(auto) + ' · <button type="button">use</button>';
        el.querySelector('button').onclick = function () { input.value = auto; update(); input.focus(); };
      }
    }
    input.oninput = update;
    update();
  }
  function closeEditor() { if (form) form.hidden = true; }
  function saveCaption(reset) {
    var p = lb.list[lb.i];
    var body = new URLSearchParams();
    body.set('id', p.id);
    if (reset) body.set('reset', '1');
    else {
      body.set('title', $('cap-t').value);
      body.set('date', $('cap-d').value);
      body.set('location', $('cap-l').value);
      body.set('description', $('cap-x').value);
    }
    $('cap-msg').textContent = 'Saving…';
    fetch('caption.php', { method: 'POST', body: body, headers: { 'X-CSRF': G.csrf }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Could not save'); return j; }); })
      .then(function (j) {
        p.cap = j.caption;
        p.custom = j.custom;
        renderCaption(p);
        closeEditor();
        var t = document.querySelector('.tile[data-id="' + p.id + '"]');
        if (t) t.setAttribute('aria-label', label(p) || 'Photo');
      })
      .catch(function (e) { $('cap-msg').textContent = e.message; });
  }

  // ---- Wiring -----------------------------------------------------------
  /** A one-line description of a photo, for screen readers and alt text. */
  function label(p) {
    var c = p.cap || {};
    var line = [c.title, c.date, c.location].filter(Boolean).join(' · ');
    return line || c.description || '';
  }
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function count(n) { return n + (n === 1 ? ' photo' : ' photos'); }
  function tileIndex(el) {
    var id = el.getAttribute('data-id');
    var list = currentPhotos();
    for (var i = 0; i < list.length; i++) if (list[i].id === id) return i;
    return 0;
  }

  document.addEventListener('click', function (e) {
    var t = e.target;
    var fav = t.closest('.fav');
    if (fav) { e.stopPropagation(); toggleFav(fav.getAttribute('data-id')); return; }
    var tab = t.closest('.tab');
    if (tab) {
      var v = tab.getAttribute('data-view');
      history.replaceState(null, '', '#' + v);
      view = v;
      render();
      var bar = $('gallery');
      if (bar && bar.getBoundingClientRect().top > 1) bar.scrollIntoView({ behavior: 'smooth' });
      return;
    }
    var tile = t.closest('.tile');
    if (tile) { openViewer(currentPhotos(), tileIndex(tile), false); return; }
    if (t.closest('[data-scroll]')) { e.preventDefault(); $('gallery').scrollIntoView({ behavior: 'smooth' }); return; }
    if (t.closest('[data-top]')) { e.preventDefault(); window.scrollTo({ top: 0, behavior: 'smooth' }); }
  });
  document.addEventListener('keydown', function (e) {
    var tile = e.target.closest && e.target.closest('.tile');
    if (tile && !lb.open && (e.key === 'Enter' || e.key === ' ') && e.target === tile) {
      e.preventDefault();
      openViewer(currentPhotos(), tileIndex(tile), false);
      return;
    }
    if (!lb.open) return;
    if (sharing()) { if (e.key === 'Escape') closeShare(); return; }
    if (editing()) { if (e.key === 'Escape') closeEditor(); return; }
    wake();
    if (e.key === 'ArrowRight') go(1);
    else if (e.key === 'ArrowLeft') go(-1);
    else if (e.key === 'Escape') closeViewer();
    else if (e.key === ' ') { e.preventDefault(); toggleShow(); }
    else if (e.key === 'f' || e.key === 'F') toggleFav(lb.list[lb.i].id);
  });

  var playBtn = $('play');
  if (playBtn) playBtn.addEventListener('click', function () {
    var list = currentPhotos();
    if (!list.length) list = G.albums.length ? G.albums[0].photos : [];
    openViewer(list, 0, true);
    if (lbEl.requestFullscreen) lbEl.requestFullscreen().catch(function () {});
  });
  $('lb-close').addEventListener('click', closeViewer);
  $('lb-prev').addEventListener('click', function () { go(-1); });
  $('lb-next').addEventListener('click', function () { go(1); });
  $('lb-play').addEventListener('click', toggleShow);
  $('lb-fav').addEventListener('click', function () { toggleFav(lb.list[lb.i].id); });
  lbEl.addEventListener('mousemove', wake);
  $('lb-stage').addEventListener('click', function (e) {
    if (e.target === img) return;
    closeViewer(); // a click on the dark surround closes the viewer
  });
  if (form) {
    $('lb-edit').addEventListener('click', openEditor);
    $('cap-cancel').addEventListener('click', closeEditor);
    $('cap-reset').addEventListener('click', function () { saveCaption(true); });
    form.addEventListener('submit', function (e) { e.preventDefault(); saveCaption(false); });
    $('cap-x').addEventListener('keydown', function (e) { // Ctrl/Cmd+Enter saves from the description
      if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); saveCaption(false); }
    });
  }


  // ---- Share panel ------------------------------------------------------
  var shareEl = $('share');
  function sharing() { return shareEl && !shareEl.hidden; }
  function shareUrl(t) { return new URL('share.php?s=' + t, location.href).href; }
  function openShare() {
    stopShow();
    closeEditor();
    var p = lb.list[lb.i], c = p.cap || {};
    $('sh-img').src = p.g;
    $('sh-img').alt = label(p);
    $('sh-title').textContent = c.title || c.location || '';
    $('sh-date').textContent = c.date || '';
    $('sh-dl').href = p.l + '&dl=1';
    $('sh-copy-label').textContent = 'Copy link';
    $('sh-msg').textContent = '';
    $('sh-more').hidden = !navigator.share;
    syncShareOwner(p);
    shareEl.hidden = false;
    lbEl.classList.remove('idle');
    $('sh-close').focus();
  }
  function closeShare() { if (shareEl) shareEl.hidden = true; }
  function syncShareOwner(p) {
    var o = $('sh-owner');
    if (o) o.hidden = !p.sh;
  }
  // The photo's public link, made the first time someone shares it.
  function ensureLink(p) {
    if (p.sh) return Promise.resolve(shareUrl(p.sh));
    var body = new URLSearchParams({ action: 'create', id: p.id });
    return fetch('share.php', { method: 'POST', body: body, headers: { 'X-CSRF': G.shareCsrf }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Could not make a link'); return j; }); })
      .then(function (j) { p.sh = j.token; syncShareOwner(p); return shareUrl(j.token); });
  }
  function shareText(p) {
    var c = p.cap || {};
    return c.title || [c.date, c.location].filter(Boolean).join(' · ') || document.title;
  }
  // Pop-up blockers only allow windows opened straight from a click, so open
  // it first and point it at the share page once the link exists.
  function shareTo(makeUrl) {
    var p = lb.list[lb.i];
    var w = window.open('', '_blank');
    ensureLink(p).then(function (url) {
      var target = makeUrl(url, shareText(p));
      if (w) { w.opener = null; w.location.href = target; } else location.href = target;
    }).catch(function (e) { if (w) w.close(); $('sh-msg').textContent = e.message; });
  }
  if (shareEl) {
    $('lb-share').addEventListener('click', openShare);
    $('sh-close').addEventListener('click', closeShare);
    shareEl.addEventListener('click', function (e) { if (e.target === shareEl) closeShare(); });
    $('sh-copy').addEventListener('click', function () {
      var p = lb.list[lb.i];
      ensureLink(p).then(function (url) {
        var done = function () { $('sh-copy-label').textContent = 'Copied'; $('sh-msg').textContent = ''; };
        if (navigator.clipboard && window.isSecureContext) {
          return navigator.clipboard.writeText(url).then(done, function () { window.prompt('Copy this link:', url); });
        }
        window.prompt('Copy this link:', url);
      }).catch(function (e) { $('sh-msg').textContent = e.message; });
    });
    $('sh-fb').addEventListener('click', function () {
      shareTo(function (url) { return 'https://www.facebook.com/sharer/sharer.php?u=' + encodeURIComponent(url); });
    });
    $('sh-x').addEventListener('click', function () {
      shareTo(function (url, text) { return 'https://x.com/intent/post?url=' + encodeURIComponent(url) + '&text=' + encodeURIComponent(text); });
    });
    $('sh-more').addEventListener('click', function () {
      var p = lb.list[lb.i];
      ensureLink(p).then(function (url) {
        return navigator.share({ title: shareText(p), url: url });
      }).catch(function (e) { if (e && e.name !== 'AbortError') $('sh-msg').textContent = e.message; });
    });
    var stop = $('sh-stop');
    if (stop) stop.addEventListener('click', function () {
      var p = lb.list[lb.i];
      var body = new URLSearchParams({ action: 'remove', id: p.id });
      fetch('share.php', { method: 'POST', body: body, headers: { 'X-CSRF': G.shareCsrf }, credentials: 'same-origin' })
        .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Could not stop sharing'); return j; }); })
        .then(function () { p.sh = null; syncShareOwner(p); $('sh-copy-label').textContent = 'Copy link'; $('sh-msg').textContent = 'Stopped. The old link no longer works.'; })
        .catch(function (e) { $('sh-msg').textContent = e.message; });
    });
  }

  // Swipe between photos on touch screens.
  var sx = null, sy = null;
  lbEl.addEventListener('touchstart', function (e) {
    if (editing() || sharing()) { sx = null; return; }
    sx = e.touches[0].clientX; sy = e.touches[0].clientY; wake();
  }, { passive: true });
  lbEl.addEventListener('touchend', function (e) {
    if (sx === null) return;
    var dx = e.changedTouches[0].clientX - sx, dy = e.changedTouches[0].clientY - sy;
    sx = null;
    if (Math.abs(dx) > 45 && Math.abs(dx) > Math.abs(dy)) go(dx < 0 ? 1 : -1);
    else if (dy > 90 && Math.abs(dy) > Math.abs(dx)) closeViewer();
  });

  var resizeTimer;
  window.addEventListener('resize', function () {
    clearTimeout(resizeTimer);
    resizeTimer = setTimeout(function () { if ($('grid') && $('grid').clientWidth !== lastWidth) layout(); }, 120);
  });
  window.addEventListener('hashchange', function () { view = viewFromHash(); render(); });

  view = viewFromHash();
  render();
})();
