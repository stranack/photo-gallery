/* Photo Gallery: the owner's Add images and Add album pages. */
(function () {
  'use strict';

  var A = window.ADD || {};
  var $ = function (id) { return document.getElementById(id); };
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function post(query, body, raw) {
    return fetch('add.php?' + query, {
      method: 'POST', body: body, credentials: 'same-origin',
      headers: raw ? { 'X-CSRF': A.csrf, 'Content-Type': 'application/octet-stream' } : { 'X-CSRF': A.csrf }
    }).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) throw new Error(j.error || 'Something went wrong'); return j; },
        function () { throw new Error('The server sent an error page (HTTP ' + r.status + ').'); });
    });
  }

  // ---- Add album --------------------------------------------------------
  var albumForm = $('album-form');
  if (albumForm) {
    var nameIn = $('al-name');
    var preview = function () { $('al-preview').textContent = nameIn.value.trim() || '…'; };
    nameIn.addEventListener('input', preview);
    preview();
    albumForm.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = albumForm.querySelector('button[type=submit]');
      var pub = albumForm.querySelector('input[name=public]:checked').value;
      btn.disabled = true;
      $('al-msg').textContent = 'Creating…';
      post('action=album', new URLSearchParams({ name: nameIn.value, 'public': pub }))
        .then(function (j) {
          $('al-msg').innerHTML = 'Created <strong>' + esc(j.folder) + '</strong>. <a href="add.php?album=' + encodeURIComponent(j.folder) + '">Add images to it</a>';
          nameIn.value = '';
          preview();
          btn.disabled = false;
        })
        .catch(function (err) { $('al-msg').textContent = err.message; btn.disabled = false; });
    });
  }

  // ---- Add images -------------------------------------------------------
  var form = $('up-form');
  if (!form) return;
  var list = $('up-list');
  var placeId = '';
  if (window.PlaceSuggest) window.PlaceSuggest($('up-loc'), function (id) { placeId = id; });
  var files = []; // {file, li}

  // The camera's date, read from the start of a JPEG's EXIF data, as YYYY-MM-DD.
  // DateTimeOriginal usually comes after the plain DateTime, so prefer the second date found.
  function cameraDate(file) {
    return file.slice(0, 256 * 1024).arrayBuffer().then(function (buf) {
      var bytes = new Uint8Array(buf), s = '';
      for (var i = 0; i < bytes.length; i++) s += String.fromCharCode(bytes[i]);
      var m = s.match(/(19|20)\d\d:[01]\d:[0-3]\d [0-2]\d:[0-5]\d:[0-5]\d/g);
      if (!m) return '';
      var d = m[Math.min(1, m.length - 1)];
      return d.slice(0, 10).replace(/:/g, '-');
    }).catch(function () { return ''; });
  }
  function sizeText(n) { return n > 1048576 ? (n / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(n / 1024)) + ' KB'; }

  function addFiles(chosen) {
    Array.prototype.forEach.call(chosen, function (file) {
      var li = document.createElement('li');
      li.className = 'up-item';
      var url = URL.createObjectURL(file);
      li.innerHTML =
        '<img class="up-thumb" alt="" src="' + url + '" onerror="this.classList.add(\'none\')">' +
        '<div class="up-fields">' +
          '<p class="up-name">' + esc(file.name) + ' <span>' + sizeText(file.size) + '</span></p>' +
          '<label>Title<input class="f-title" maxlength="200" autocomplete="off"></label>' +
          '<label>Date<input class="f-date" maxlength="200" autocomplete="off" placeholder="YYYY-MM-DD, or e.g. Summer 1991"><small class="f-date-hint"></small></label>' +
          '<label class="wide">Description<textarea class="f-desc" rows="2" maxlength="2000"></textarea></label>' +
          '<div class="up-bar" hidden><span></span></div><p class="up-state" role="status"></p>' +
        '</div>' +
        '<button type="button" class="up-remove" aria-label="Remove ' + esc(file.name) + '">×</button>';
      list.appendChild(li);
      var entry = { file: file, li: li, done: false };
      files.push(entry);
      li.querySelector('.up-remove').addEventListener('click', function () {
        if (busy) return;
        files.splice(files.indexOf(entry), 1);
        li.remove();
        URL.revokeObjectURL(url);
        update();
      });
      cameraDate(file).then(function (d) {
        var input = li.querySelector('.f-date');
        if (d && !input.value) { input.value = d; li.querySelector('.f-date-hint').textContent = 'From the camera'; }
      });
    });
    update();
  }
  function update() {
    var waiting = files.filter(function (f) { return !f.done; }).length;
    $('up-go').disabled = busy || !waiting;
    $('up-go').textContent = waiting > 1 ? 'Upload ' + waiting + ' photos' : 'Upload';
  }

  var input = $('up-files');
  input.addEventListener('change', function () { addFiles(input.files); input.value = ''; });
  var drop = $('drop');
  ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function (e) { e.preventDefault(); drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.remove('over'); }); });
  drop.addEventListener('drop', function (e) { e.preventDefault(); addFiles(e.dataTransfer.files); });

  // Send one photo in pieces, then save it with its caption.
  function uploadOne(entry, album) {
    var li = entry.li, file = entry.file, chunk = A.chunk || 4194304;
    var bar = li.querySelector('.up-bar'), fill = bar.querySelector('span'), state = li.querySelector('.up-state');
    bar.hidden = false;
    state.textContent = 'Uploading…';
    var session = '', offset = 0;
    function next() {
      if (offset >= file.size && session) return Promise.resolve();
      var piece = file.slice(offset, offset + chunk);
      return post('action=chunk&session=' + encodeURIComponent(session) + '&offset=' + offset, piece, true).then(function (j) {
        session = j.session;
        offset = j.offset;
        fill.style.width = Math.round(offset / file.size * 100) + '%';
        return next();
      });
    }
    return next().then(function () {
      state.textContent = 'Saving…';
      return post('action=finish', new URLSearchParams({
        session: session, offset: String(offset), album: album, file: file.name,
        title: li.querySelector('.f-title').value,
        date: li.querySelector('.f-date').value,
        location: $('up-loc').value,
        description: li.querySelector('.f-desc').value,
        tags: $('up-tags').value,
        place_id: placeId
      }));
    }).then(function (j) {
      entry.done = true;
      li.classList.add('done');
      state.textContent = 'Saved as ' + j.path.split('/').pop();
      li.querySelectorAll('input, textarea').forEach(function (x) { x.disabled = true; });
    }, function (err) {
      li.classList.add('failed');
      state.textContent = err.message;
      throw err;
    });
  }

  // Bring the new photos onto the site; a big batch may need a few rounds.
  function syncAlbum(album, round) {
    return post('action=sync', new URLSearchParams({ album: album })).then(function (j) {
      if (j.status === 'partial' && round < 8) {
        $('up-msg').textContent = 'Putting them in the gallery… ' + j.remaining + ' to go';
        return syncAlbum(album, round + 1);
      }
      return j;
    });
  }

  var busy = false;
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var album = $('up-album').value;
    if (!album) { $('up-msg').textContent = 'Choose an album first.'; $('up-album').focus(); return; }
    var todo = files.filter(function (f) { return !f.done; });
    if (!todo.length) return;
    busy = true;
    update();
    var failed = 0;
    $('up-msg').textContent = 'Uploading ' + todo.length + (todo.length === 1 ? ' photo…' : ' photos…');
    todo.reduce(function (p, entry) {
      return p.then(function () { return uploadOne(entry, album).catch(function () { failed++; }); });
    }, Promise.resolve()).then(function () {
      if (failed === todo.length) throw new Error('Nothing was uploaded.');
      $('up-msg').textContent = 'Putting them in the gallery…';
      return syncAlbum(album, 0);
    }).then(function (j) {
      var ok = todo.length - failed;
      $('up-msg').textContent = ok + (ok === 1 ? ' photo' : ' photos') + ' added' + (failed ? '; ' + failed + ' didn’t upload (see above)' : '') +
        (j.status === 'partial' ? '. The rest will appear after the next hourly sync.' : '.');
      if (j.slug) { $('up-view').href = './#' + j.slug; $('up-view').hidden = false; }
    }).catch(function (err) {
      $('up-msg').textContent = err.message;
    }).then(function () {
      busy = false;
      update();
    });
  });
  update();
})();
