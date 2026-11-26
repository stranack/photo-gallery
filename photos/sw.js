/* Photo gallery service worker.

   It keeps copies of the gallery page, styles, scripts and the photos you've
   looked at, so the gallery opens quickly and still works with no connection.

   Gallery page: network first, falling back to the saved copy after a few
   seconds or when offline, then to offline.html.
   Styles, scripts, photos: saved copy first (their addresses carry a version,
   so they change when the file does), fetched and kept when missing.

   Never touched: admin, add, share pages and links, downloads, and anything
   sent with POST (captions, covers, albums, trash). */
'use strict';

var VERSION = 'pg-v1';
var SHELL = VERSION + '-shell';    // assets, offline page
var PAGES = VERSION + '-pages';    // the gallery page
var GRID = VERSION + '-grid';      // grid-sized photos
var LARGE = VERSION + '-large';    // full-screen photos
var KEEP = [SHELL, PAGES, GRID, LARGE];
var MAX_GRID = 1000;
var MAX_LARGE = 150;
var PAGE_WAIT = 4000; // ms to wait for the network before using the saved page

var PRECACHE = [
  'offline.html',
  'assets/style.css',
  'assets/gallery.js',
  'assets/app.js',
  'assets/icon.svg',
  'assets/icon-192.png',
  'assets/offline-photo.svg'
];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(SHELL).then(function (c) {
    // One missing file shouldn't stop the rest being saved.
    return Promise.all(PRECACHE.map(function (u) {
      return c.add(new Request(u, { cache: 'reload' })).catch(function () {});
    }));
  }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return KEEP.indexOf(k) < 0; })
      .map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('message', function (e) {
  var d = e.data || {};
  if (d === 'forget-private' || d.type === 'forget-private') {
    // This browser can no longer see the private albums: drop what it kept.
    e.waitUntil(Promise.all([caches.delete(PAGES), caches.delete(GRID), caches.delete(LARGE)]));
  } else if (d.type === 'keep-photos' && Array.isArray(d.urls)) {
    // The photos this browser may see now. Anything else saved here was
    // deleted, replaced by a newer version, or made private: drop it.
    var keep = {};
    d.urls.forEach(function (u) { keep[u] = 1; });
    e.waitUntil(Promise.all([GRID, LARGE].map(function (name) {
      return caches.open(name).then(function (c) {
        return c.keys().then(function (reqs) {
          return Promise.all(reqs.filter(function (r) { return !keep[r.url]; })
            .map(function (r) { return c.delete(r); }));
        });
      });
    })));
  }
});

function trim(cacheName, max) {
  return caches.open(cacheName).then(function (c) {
    return c.keys().then(function (keys) {
      var extra = keys.length - max;
      if (extra <= 0) { return; }
      return Promise.all(keys.slice(0, extra).map(function (k) { return c.delete(k); }));
    });
  });
}

function pageFetch(e, req, key) {
  var saved = caches.open(PAGES).then(function (c) { return c.match(key); });
  var net = fetch(req).then(function (res) {
    // Only a real page is worth keeping, not an error or a redirect.
    if (res.ok && res.type === 'basic' && !res.redirected) {
      var copy = res.clone();
      e.waitUntil(caches.open(PAGES).then(function (c) { return c.put(key, copy); }));
    }
    return res;
  });
  var timeout = new Promise(function (resolve) { setTimeout(resolve, PAGE_WAIT); });

  return Promise.race([net.catch(function () { return null; }), timeout.then(function () { return 'slow'; })])
    .then(function (first) {
      if (first && first !== 'slow') { return first; }
      // Offline or slow: the saved copy if there is one, otherwise keep waiting.
      return saved.then(function (hit) {
        if (hit) { return hit; }
        return net.catch(function () {
          return caches.match('offline.html', { cacheName: SHELL });
        });
      });
    });
}

function keptFetch(e, req, cacheName, max, fallback) {
  return caches.open(cacheName).then(function (c) {
    return c.match(req).then(function (hit) {
      if (hit) { return hit; }
      return fetch(req).then(function (res) {
        if (res.ok) {
          var copy = res.clone();
          e.waitUntil(c.put(req, copy).then(function () { return max ? trim(cacheName, max) : null; }));
        }
        return res;
      }).catch(function (err) {
        // Offline, and this exact version isn't saved.
        if (fallback === 'any') {
          return c.match(req, { ignoreSearch: true }).then(function (any) {
            if (any) { return any; }
            throw err;
          });
        }
        if (fallback === 'photo') {
          return caches.match('assets/offline-photo.svg', { cacheName: SHELL }).then(function (p) {
            if (p) { return p; }
            throw err;
          });
        }
        throw err;
      });
    });
  });
}

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') { return; }
  var url = new URL(req.url);
  if (url.origin !== self.location.origin) { return; }

  var base = self.registration.scope;
  if (req.url.indexOf(base) !== 0) { return; }
  var path = url.pathname.slice(new URL(base).pathname.length);
  var q = url.searchParams;

  if (/^assets\//.test(path)) {
    e.respondWith(keptFetch(e, req, SHELL, 0, 'any'));
  } else if (path === 'img.php') {
    // Share links (t=) and downloads (dl=) always go to the site.
    if (q.has('t') || q.has('dl')) { return; }
    var large = q.get('s') === 'l';
    e.respondWith(keptFetch(e, req, large ? LARGE : GRID, large ? MAX_LARGE : MAX_GRID, 'photo'));
  } else if ((path === '' || path === 'index.php') && req.mode === 'navigate') {
    // Sign in and sign out pages go to the site; every other address of the
    // gallery page (?source=app, #album) is kept as one copy.
    if (q.has('signin') || q.has('signout')) { return; }
    e.respondWith(pageFetch(e, req, base));
  } else if (req.mode === 'navigate') {
    // Admin, Add and share pages aren't kept, but say so plainly when offline.
    e.respondWith(fetch(req).catch(function () {
      return caches.match('offline.html', { cacheName: SHELL }).then(function (p) {
        return p || Response.error();
      });
    }));
  }
  // Everything else goes straight to the network as before.
});
