/* Shared by the gallery, Add and Admin pages: registers the service worker
   (sw.js) that lets the gallery install as an app and open without a
   connection, and keeps the photos saved on this device in step with what
   this browser is allowed to see. */
(function () {
  'use strict';
  if (!('serviceWorker' in navigator)) { return; }

  var visitor = document.documentElement.getAttribute('data-visitor') === '1';
  var isGallery = document.documentElement.getAttribute('data-page') === 'gallery';

  function tell(msg) {
    navigator.serviceWorker.ready.then(function (reg) {
      if (reg.active) { reg.active.postMessage(msg); }
    }).catch(function () {});
  }

  if (isGallery && navigator.onLine) {
    var G = window.GALLERY;
    if (G && Array.isArray(G.albums)) {
      // Keep only the photos on this page: that drops deleted photos, old
      // versions, and albums that are now private for a signed-out browser.
      var urls = [];
      var abs = function (u) { if (u) { urls.push(new URL(u, location.href).href); } };
      G.albums.forEach(function (a) {
        (a.photos || []).forEach(function (p) { abs(p.g); abs(p.l); });
      });
      abs(G.cover);
      tell({ type: 'keep-photos', urls: urls });
    } else if (!visitor) {
      // The password page: nothing saved here should still be on show.
      tell({ type: 'forget-private' });
    }
  }

  window.addEventListener('load', function () {
    navigator.serviceWorker.register('sw.js', { scope: './' }).catch(function () {
      /* not served over https, or blocked: the site works as before */
    });
  });
})();
