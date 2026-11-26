/* Photo Gallery: albums, grid, viewer, slideshow, favourites, tags, captions, trash, public/private albums, zoom, albums page. */
(function () {
  'use strict';

  var G = window.GALLERY || { albums: [] };
  var $ = function (id) { return document.getElementById(id); };
  var GLOBE = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M3.5 12h17M12 3.5c2.3 2.4 3.4 5.2 3.4 8.5s-1.1 6.1-3.4 8.5c-2.3-2.4-3.4-5.2-3.4-8.5s1.1-6.1 3.4-8.5z" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>';
  var LOCK = '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="1.8" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8.5 10.5V7.8a3.5 3.5 0 0 1 7 0v2.7" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>';
  var BACK = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.5 5 7.5 12l7 7" fill="none" stroke="currentColor" stroke-width="1.6"/></svg>';
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

  // ---- Tags: a comma-separated caption field, browsed from the Tags tab --
  var tagSel = null;
  function tagList(c) {
    return String((c && c.tags) || '').split(',').map(function (t) { return t.trim(); }).filter(Boolean);
  }
  function allTags() {
    var m = {};
    G.albums.forEach(function (a) {
      a.photos.forEach(function (p) {
        var seen = {};
        tagList(p.cap).forEach(function (t) {
          var k = t.toLowerCase();
          if (seen[k]) return;
          seen[k] = 1;
          if (!m[k]) m[k] = { name: t, n: 0 };
          m[k].n++;
        });
      });
    });
    return Object.keys(m).map(function (k) { return m[k]; })
      .sort(function (a, b) { return a.name.toLowerCase() < b.name.toLowerCase() ? -1 : 1; });
  }
  function taggedPhotos(tag) {
    var k = String(tag).toLowerCase(), out = [], seen = {};
    G.albums.forEach(function (a) {
      a.photos.forEach(function (p) {
        if (seen[p.id]) return;
        if (tagList(p.cap).some(function (t) { return t.toLowerCase() === k; })) { seen[p.id] = 1; out.push(p); }
      });
    });
    return out;
  }
  function showTag(tag) {
    tagSel = tag || null;
    view = 'tags';
    history.replaceState(null, '', '#tags' + (tagSel ? '/' + encodeURIComponent(tagSel) : ''));
    if (lb.open) closeViewer();
    render();
    var bar = $('gallery');
    if (bar && bar.getBoundingClientRect().top > 1) bar.scrollIntoView({ behavior: 'smooth' });
  }

  // ---- Which album is showing ------------------------------------------
  var view = null;
  function viewFromHash() {
    var h = decodeURIComponent(location.hash.replace(/^#/, ''));
    tagSel = null;
    if (h === 'tags' || h.indexOf('tags/') === 0) {
      tagSel = h.length > 5 ? h.slice(5) : null;
      return allTags().length ? 'tags' : 'albums';
    }
    if (h === 'favourites') return 'favourites';
    for (var i = 0; i < G.albums.length; i++) if (G.albums[i].slug === h) return h;
    return 'albums'; // the Albums page is the starting point
  }
  function currentAlbum() {
    for (var i = 0; i < G.albums.length; i++) if (G.albums[i].slug === view) return G.albums[i];
    return null;
  }
  function currentPhotos() {
    if (view === 'favourites') return favPhotos();
    if (view === 'tags') return tagSel ? taggedPhotos(tagSel) : [];
    var a = currentAlbum();
    return a ? a.photos : [];
  }

  function renderTabs() {
    var tabs = $('tabs');
    if (!tabs) return;
    var html = '';
    if (G.albums.length) {
      // "Albums" stays underlined inside an album too.
      html += '<button class="tab" role="tab" data-view="albums" aria-selected="' + (view === 'albums' || !!currentAlbum()) + '">Albums</button>';
    }
    if (allTags().length) {
      html += '<button class="tab" role="tab" data-view="tags" aria-selected="' + (view === 'tags') + '">Tags</button>';
    }
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
    } else if (view === 'tags') {
      var chips = allTags().map(function (t) {
        var on = tagSel && t.name.toLowerCase() === tagSel.toLowerCase();
        return '<button type="button" class="tag-chip' + (on ? ' on' : '') + '" data-tag="' + esc(t.name) + '" aria-pressed="' + !!on + '">' + esc(t.name) + ' <span class="n">' + t.n + '</span></button>';
      }).join('');
      head.innerHTML = '<h2>' + (tagSel ? esc(tagSel) : 'Tags') + '</h2><p>' +
        (tagSel ? count(photos.length) : 'Choose a tag to see its photos') + '</p><div class="tag-cloud">' + chips + '</div>';
    } else if (view === 'albums') {
      var all = G.albums.reduce(function (n, a) { return n + a.photos.length; }, 0);
      head.innerHTML = '<h2>Albums</h2><p>' + G.albums.length + (G.albums.length === 1 ? ' album' : ' albums') + ' · ' + count(all) + '</p>';
    } else if (currentAlbum()) {
      head.innerHTML = '<a class="back-link" href="#albums">' + BACK + 'All albums</a>' +
        '<h2>' + esc(currentAlbum().name) + '</h2><p>' + count(photos.length) + '</p>' + visibilityButton(currentAlbum());
    } else {
      head.innerHTML = '';
    }
    layout();
  }

  // ---- Public or private album (owner only) ------------------------------
  // Public albums are open to anyone; private ones need the gallery password.
  function visibilityButton(a) {
    if (!G.owner) return '';
    var pub = !!a['public'];
    return '<button type="button" class="vis-btn' + (pub ? ' is-public' : '') + '" id="vis-btn" data-slug="' + esc(a.slug) + '"' +
      ' aria-pressed="' + pub + '" title="' + (pub ? 'Anyone can see this album. Click to make it private.' : 'Only people with the gallery password can see this album. Click to make it public.') + '">' +
      (pub ? GLOBE : LOCK) + '<span>' + (pub ? 'Public' : 'Private') + '</span></button><p class="vis-msg" id="vis-msg" role="status"></p>';
  }
  function toggleVisibility(slug) {
    var a = null;
    G.albums.forEach(function (x) { if (x.slug === slug) a = x; });
    if (!a) return;
    var want = !a['public'];
    var btn = $('vis-btn');
    if (btn) btn.disabled = true;
    var body = new URLSearchParams({ slug: slug, 'public': want ? '1' : '0' });
    fetch('album.php', { method: 'POST', body: body, headers: { 'X-CSRF': G.csrf }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Could not change the album'); return j; }, function () { throw new Error('Could not change the album (the server sent an error page).'); }); })
      .then(function (j) {
        a['public'] = !!j['public'];
        render();
        var m = $('vis-msg');
        if (m) m.textContent = a['public'] ? 'Now public: anyone can see this album.' : 'Now private: the gallery password is needed.';
      })
      .catch(function (e) { if (btn) btn.disabled = false; var m = $('vis-msg'); if (m) m.textContent = e.message; });
  }

  // ---- Albums page: one card per album, like Flickr's ------------------
  function albumCards() {
    return '<div class="album-grid">' + G.albums.map(function (a) {
      var cover = byId[a.cover] || a.photos[0];
      var lock = G.owner && !a['public'] ? '<span class="album-card-lock" title="Private album">' + LOCK + '<span class="visually-hidden">Private</span></span>' : '';
      return '<a class="album-card" href="#' + esc(a.slug) + '">' +
        (cover ? '<img src="' + esc(cover.g) + '" alt="" loading="lazy" decoding="async" onload="this.classList.add(\'loaded\')">' : '') +
        lock + '<span class="album-card-text"><span class="album-card-name">' + esc(a.name) + '</span>' +
        '<span class="album-card-n">' + count(a.photos.length) + '</span></span></a>';
    }).join('') + '</div>';
  }

  // ---- Justified rows: every row fills the width, photos keep their shape
  var lastWidth = 0;
  function layout() {
    var grid = $('grid');
    if (!grid) return;
    var width = grid.clientWidth;
    lastWidth = width;
    if (view === 'albums') { grid.innerHTML = albumCards(); return; }
    var photos = currentPhotos();
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
  var lb = { open: false, list: [], i: 0, timer: null, idleTimer: null, token: 0, zoom: 0 };
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
    setZoom(0);
    stopShow();
    closeEditor();
    closeShare();
    closeDelete();
    lb.open = false;
    lbEl.hidden = true;
    document.body.style.overflow = '';
    if (document.fullscreenElement && document.exitFullscreen) document.exitFullscreen().catch(function () {});
  }
  function show(fade) {
    if (lb.zoom === 2) setZoom(1);
    var p = lb.list[lb.i];
    var token = ++lb.token;
    clearTimeout(flashTimer);
    $('lb-count').removeAttribute('data-count');
    $('lb-count').textContent = (lb.i + 1) + ' / ' + lb.list.length;
    renderCaption(p);
    syncViewerFav();
    if (typeof syncCover === 'function') syncCover();
    closeEditor();
    closeDelete();
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
    // A description that only repeats the location isn't shown twice.
    var key = function (x) { return String(x || '').toLowerCase().replace(/[^0-9a-z\u00c0-\uffff]+/g, ' ').trim(); };
    var desc = c.description && key(c.description) !== key(c.location) ? c.description : '';
    $('cap-desc').textContent = desc;
    $('cap-desc').title = desc;
    var tg = $('cap-tags');
    if (tg) {
      tg.innerHTML = tagList(c).map(function (t) {
        return '<button type="button" class="tag-chip" data-tag="' + esc(t) + '">' + esc(t) + '</button>';
      }).join('');
    }
    fitStage();
  }
  function fitStage() {
    if (!lb.open) return;
    if (lb.zoom) { $('lb-stage').style.bottom = ''; return; }
    var below = (strip.hidden ? 12 : strip.offsetHeight) + $('lb-cap').offsetHeight + 8;
    $('lb-stage').style.bottom = Math.max(below, window.innerWidth < 760 ? 72 : 76) + 'px';
  }
  window.addEventListener('resize', fitStage);

  function syncViewerFav() {
    var p = lb.list[lb.i];
    if (p) $('lb-fav').setAttribute('aria-pressed', String(isFav(p.id)));
  }

  // ---- Zoom, like Flickr's lightbox ------------------------------------
  // 0: normal viewer. 1: the photo fills the screen, controls hidden.
  // 2: full detail (the photo at its own size); move the mouse or drag to look around.
  var stage = $('lb-stage');
  function canZoomMore() {
    return img.naturalWidth > stage.clientWidth + 8 || img.naturalHeight > stage.clientHeight + 8;
  }
  function setZoom(level, ev) {
    lb.zoom = level;
    lbEl.classList.toggle('zoom', level > 0);
    lbEl.classList.toggle('zoom2', level === 2);
    lbEl.classList.remove('zoom-max');
    if (level === 1 && !canZoomMore()) lbEl.classList.add('zoom-max');
    if (level === 2 && ev) {
      // Start with the spot that was clicked in the middle of the screen.
      var r = img.getBoundingClientRect();
      var fx = r.width ? (ev.clientX - r.left) / r.width : 0.5;
      var fy = r.height ? (ev.clientY - r.top) / r.height : 0.5;
      requestAnimationFrame(function () { panTo(fx, fy); });
    }
    if (level === 0) stage.scrollTo(0, 0);
    fitStage();
  }
  function panTo(fx, fy) {
    stage.scrollLeft = Math.max(0, Math.min(1, fx)) * (stage.scrollWidth - stage.clientWidth);
    stage.scrollTop = Math.max(0, Math.min(1, fy)) * (stage.scrollHeight - stage.clientHeight);
  }
  function zoomStep(ev) {
    if (lb.zoom === 0) { stopShow(); setZoom(1); }
    else if (lb.zoom === 1) setZoom(canZoomMore() ? 2 : 0, ev);
    else setZoom(0);
  }
  img.addEventListener('click', function (e) {
    if (lb.dragged) { lb.dragged = false; return; }
    zoomStep(e);
  });
  // At full detail, the mouse position picks which part of the photo shows.
  stage.addEventListener('mousemove', function (e) {
    if (lb.zoom !== 2 || e.buttons) return;
    var r = stage.getBoundingClientRect();
    panTo((e.clientX - r.left) / r.width, (e.clientY - r.top) / r.height);
  });
  // A mouse drag at full detail moves the photo instead of counting as a click.
  var drag = null;
  stage.addEventListener('mousedown', function (e) {
    if (lb.zoom !== 2) return;
    e.preventDefault();
    drag = { x: e.clientX, y: e.clientY, l: stage.scrollLeft, t: stage.scrollTop };
    lb.dragged = false;
  });
  window.addEventListener('mousemove', function (e) {
    if (!drag) return;
    var dx = e.clientX - drag.x, dy = e.clientY - drag.y;
    if (Math.abs(dx) + Math.abs(dy) > 4) lb.dragged = true;
    stage.scrollLeft = drag.l - dx;
    stage.scrollTop = drag.t - dy;
  });
  window.addEventListener('mouseup', function () { drag = null; });

  // ---- Slideshow --------------------------------------------------------
  function startShow() {
    stopShow();
    if (lb.zoom === 2) setZoom(1);
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
      if (lb.open && lb.timer && !editing() && !sharing() && !deleting()) lbEl.classList.add('idle');
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
    $('cap-g').value = c.tags || '';
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
      body.set('tags', $('cap-g').value);
    }
    $('cap-msg').textContent = 'Saving…';
    fetch('caption.php', { method: 'POST', body: body, headers: { 'X-CSRF': G.csrf }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Could not save'); return j; }); })
      .then(function (j) {
        p.cap = j.caption;
        p.custom = j.custom;
        renderCaption(p);
        closeEditor();
        renderTabs();
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
    var vis = t.closest('.vis-btn');
    if (vis) { toggleVisibility(vis.getAttribute('data-slug')); return; }
    var chip = t.closest('.tag-chip');
    if (chip) { showTag(chip.getAttribute('data-tag')); return; }
    var tab = t.closest('.tab');
    if (tab) {
      var v = tab.getAttribute('data-view');
      if (v === 'tags') { showTag(null); return; }
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
    if (t.closest('[data-top]')) {
      // The title goes back to the main page: the cover, with the Albums page below.
      e.preventDefault();
      if (lb.open) closeViewer();
      if (view !== 'albums') {
        view = 'albums';
        history.replaceState(null, '', location.pathname + location.search);
        render();
      }
      window.scrollTo({ top: 0, behavior: 'smooth' });
    }
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
    if (deleting()) { if (e.key === 'Escape') closeDelete(); return; }
    if (editing()) { if (e.key === 'Escape') closeEditor(); return; }
    wake();
    if (e.key === 'ArrowRight') go(1);
    else if (e.key === 'ArrowLeft') go(-1);
    else if (e.key === 'Escape') { if (lb.zoom) setZoom(lb.zoom - 1); else closeViewer(); }
    else if (e.key === 'z' || e.key === 'Z') zoomStep();
    else if (e.key === ' ') { e.preventDefault(); toggleShow(); }
    else if (e.key === 'f' || e.key === 'F') toggleFav(lb.list[lb.i].id);
    else if ((e.key === 'Delete' || e.key === 'Backspace') && delEl) { e.preventDefault(); openDelete(); }
  });

  var playBtn = $('play');
  if (playBtn) playBtn.addEventListener('click', function () {
    var list = currentPhotos();
    if (!list.length) list = G.albums.reduce(function (all, a) { return all.concat(a.photos); }, []);
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
    if (lb.dragged) { lb.dragged = false; return; }
    if (lb.zoom) { setZoom(0); return; } // when zoomed, a click on the surround goes back
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


  // ---- Album cover (owner only) ---------------------------------------
  var coverBtn = $('lb-cover');
  function albumOf(id) {
    for (var i = 0; i < G.albums.length; i++) {
      for (var j = 0; j < G.albums[i].photos.length; j++) if (G.albums[i].photos[j].id === id) return G.albums[i];
    }
    return null;
  }
  function syncCover() {
    if (!coverBtn) return;
    var p = lb.list[lb.i], a = p && albumOf(p.id);
    var on = !!(a && a.cover === p.id);
    coverBtn.setAttribute('aria-pressed', String(on));
    coverBtn.title = on ? 'This is the cover of ' + a.name : 'Make this the cover of ' + (a ? a.name : 'its album');
  }
  if (coverBtn) coverBtn.addEventListener('click', function () {
    var p = lb.list[lb.i];
    fetch('album.php', { method: 'POST', body: new URLSearchParams({ action: 'cover', id: p.id }), headers: { 'X-CSRF': G.csrf }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Could not set the cover'); return j; }); })
      .then(function (j) {
        G.albums.forEach(function (a) { if (a.slug === j.slug) a.cover = j.cover; });
        syncCover();
        flash('Album cover set');
      })
      .catch(function (e) { flash(e.message); });
  });
  // A short note at the top of the viewer.
  var flashTimer;
  function flash(msg) {
    var c = $('lb-count');
    var keep = c.getAttribute('data-count') || c.textContent;
    c.setAttribute('data-count', keep);
    c.textContent = msg;
    clearTimeout(flashTimer);
    flashTimer = setTimeout(function () { c.textContent = c.getAttribute('data-count'); c.removeAttribute('data-count'); }, 2200);
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

  // ---- Move to Trash (owner only) -------------------------------------
  var delEl = $('del-confirm');
  function deleting() { return delEl && !delEl.hidden; }
  function openDelete() {
    if (!delEl) return;
    stopShow();
    closeEditor();
    closeShare();
    var p = lb.list[lb.i];
    $('del-img').src = p.g;
    $('del-img').alt = label(p);
    $('del-msg').textContent = '';
    $('del-yes').disabled = false;
    delEl.hidden = false;
    lbEl.classList.remove('idle');
    $('del-no').focus();
  }
  function closeDelete() { if (delEl && !$('del-yes').disabled) delEl.hidden = true; }
  // Take a photo out of every list the page holds, then show what's left.
  function dropPhoto(id) {
    for (var i = G.albums.length - 1; i >= 0; i--) {
      var ps = G.albums[i].photos;
      for (var j = ps.length - 1; j >= 0; j--) if (ps[j].id === id) ps.splice(j, 1);
      if (!ps.length) G.albums.splice(i, 1);
    }
    delete byId[id];
    if (favs[id]) { delete favs[id]; saveFavs(); }
    for (var k = lb.list.length - 1; k >= 0; k--) if (lb.list[k] && lb.list[k].id === id) lb.list.splice(k, 1);
    G.albums.forEach(function (a) { if (a.cover === id) a.cover = a.photos[0] ? a.photos[0].id : null; });
    if (view !== 'favourites' && view !== 'tags' && view !== 'albums' && !currentAlbum()) {
      view = 'albums';
      history.replaceState(null, '', '#albums');
    }
    render();
    if (!lb.list.length) { closeViewer(); return; }
    if (lb.i >= lb.list.length) lb.i = lb.list.length - 1;
    buildStrip();
    show(false);
  }
  function trashPhoto() {
    var p = lb.list[lb.i];
    var yes = $('del-yes');
    yes.disabled = true;
    $('del-msg').textContent = 'Moving to the Trash…';
    fetch('delete.php', { method: 'POST', body: new URLSearchParams({ id: p.id }), headers: { 'X-CSRF': G.csrf }, credentials: 'same-origin' })
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Could not move the photo'); return j; }, function () { throw new Error('Could not move the photo (the server sent an error page).'); }); })
      .then(function () {
        yes.disabled = false;
        delEl.hidden = true;
        dropPhoto(p.id);
      })
      .catch(function (e) { yes.disabled = false; $('del-msg').textContent = e.message; });
  }
  if (delEl) {
    $('lb-del').addEventListener('click', openDelete);
    $('del-no').addEventListener('click', closeDelete);
    $('del-yes').addEventListener('click', trashPhoto);
  }

  // Swipe between photos on touch screens.
  var sx = null, sy = null;
  lbEl.addEventListener('touchstart', function (e) {
    if (editing() || sharing() || deleting() || lb.zoom === 2) { sx = null; return; }
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
  window.addEventListener('hashchange', function () {
    view = viewFromHash();
    render();
    var bar = $('gallery');
    if (bar && bar.getBoundingClientRect().top > 1) bar.scrollIntoView({ behavior: 'smooth' });
    else if (bar && window.scrollY > bar.offsetTop) window.scrollTo({ top: bar.offsetTop });
  });

  view = viewFromHash();
  render();
})();
