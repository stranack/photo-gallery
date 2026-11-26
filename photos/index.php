<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$title = (string) cfg('site_title', 'Photographs');
$subtitle = (string) cfg('site_subtitle', '');
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $typed = (string) $_POST['password'];
    if (gallery_password_usable() && hash_equals((string) cfg('gallery_password'), $typed)) {
        set_visitor_cookie(true);
        header('Location: ./');
        exit;
    }
    // The owner can use the admin password here too; it also turns on caption editing.
    if (admin_password_usable() && hash_equals((string) cfg('admin_password'), $typed)) {
        set_owner_cookie(true);
        header('Location: ./');
        exit;
    }
    failed_attempt_pause();
    $error = 'That password isn’t right. Try again.';
}
if (isset($_GET['signout'])) {
    // Sign out completely: the gallery password and, for the owner, the
    // admin sign-in too (which would otherwise keep letting this browser in).
    set_visitor_cookie(false);
    set_owner_cookie(false);
    header('Location: ./');
    exit;
}

$owner = is_owner();
$visitor = is_visitor();
// Without the password, the page shows the public albums, if there are any.
// ?signin shows the password page, for the private albums.
$wantsSignin = isset($_GET['signin']) || $error !== null;
$allowed = $visitor || (!$wantsSignin && gallery_password_usable() && any_public_album());
$css = 'assets/style.css?v=' . @filemtime(__DIR__ . '/assets/style.css');
$js = 'assets/gallery.js?v=' . @filemtime(__DIR__ . '/assets/gallery.js');

function head_html(string $title, string $css): void
{ ?><!doctype html>
<html lang="en"<?= app_html_attrs('gallery') ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title><?= h($title) ?></title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= h($css) ?>">
<?= app_head_tags() ?></head>
<?php }

if (!$allowed):
    head_html($title, $css); ?>
<body class="gate">
<main class="gate-card">
  <p class="eyebrow">Private gallery</p>
  <h1 class="display"><?= h($title) ?></h1>
  <?php if ($subtitle !== ''): ?><p class="subtitle"><?= h($subtitle) ?></p><?php endif; ?>
  <?php if (!gallery_password_usable()): ?>
    <p class="gate-note">This gallery isn’t open yet. (Owner: set <code>gallery_password</code> in <code>app/config.php</code>.)</p>
  <?php else: ?>
  <form method="post" class="gate-form" action="./">
    <label for="pw" class="visually-hidden">Gallery password</label>
    <input id="pw" type="password" name="password" placeholder="Password" autocomplete="current-password" required autofocus>
    <button type="submit" class="btn btn-solid">View gallery</button>
  </form>
  <?php if ($error): ?><p class="gate-error" role="alert"><?= h($error) ?></p><?php endif; ?>
  <?php if (any_public_album()): ?><p class="gate-note"><a href="./">See the public albums</a></p><?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
<?php exit; endif;

$data = gallery_for_page($owner, $visitor);
$data['signedIn'] = $visitor;
$data['owner'] = $owner;
$data['csrf'] = $owner ? owner_csrf() : null;
$data['shareCsrf'] = share_csrf();
$data['slideshowSeconds'] = max(2, (int) cfg('slideshow_seconds', 5));
$photoCount = array_sum(array_map(fn($a) => count($a['photos']), $data['albums']));
head_html($title, $css); ?>
<body class="gallery<?= $data['cover'] ? ' has-cover' : '' ?>">

<header class="hero">
  <?php if ($data['cover']): ?><img class="hero-img" src="<?= h($data['cover']) ?>" alt="" fetchpriority="high"><?php endif; ?>
  <div class="hero-text">
    <h1 class="display"><?= h($title) ?></h1>
    <?php if ($subtitle !== ''): ?><p class="subtitle"><?= h($subtitle) ?></p><?php endif; ?>
    <?php if ($photoCount): ?><a class="btn btn-ghost" href="#gallery" data-scroll>View gallery</a><?php endif; ?>
  </div>
</header>

<nav class="bar" id="gallery" aria-label="Gallery">
  <a class="bar-title" href="./" data-top title="Main page"><?= h($title) ?></a>
  <div class="tabs" role="tablist" id="tabs"></div>
  <div class="bar-tools">
    <button class="icon-btn" id="play" type="button" title="Slideshow" aria-label="Play slideshow">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z"/></svg><span>Slideshow</span>
    </button>
    <?php if (!$visitor): ?><a class="icon-btn" href="?signin=1" title="Sign in for more albums" aria-label="Sign in for more albums"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10.5" width="14" height="10" rx="1.8" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="M8.5 10.5V7.8a3.5 3.5 0 0 1 7 0v2.7" fill="none" stroke="currentColor" stroke-width="1.6"/></svg><span>Sign in</span></a><?php endif; ?>
    <?php if ($owner): ?><a class="icon-btn" href="add.php" title="Add images" aria-label="Add images"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="13.5" height="12" rx="1.4" fill="none" stroke="currentColor" stroke-width="1.5"/><path d="m3.5 14.5 3.8-3.8 3 3 2-2 4.7 4.6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M19.5 3v6M16.5 6h6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg><span>Add image</span></a>
    <a class="icon-btn" href="add.php?new=album" title="Add album" aria-label="Add album"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3.5 7.5V18c0 .8.6 1.4 1.4 1.4h14.2c.8 0 1.4-.6 1.4-1.4V9.4c0-.8-.6-1.4-1.4-1.4h-7l-2-2.5H4.9c-.8 0-1.4.6-1.4 1.4z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><path d="M12 11v5.5M9.25 13.75h5.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg><span>Add album</span></a>
    <a class="icon-btn" href="admin.php" title="Admin" aria-label="Admin"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10.07 5.27L10.56 2.91L13.44 2.91L13.93 5.27L15.39 5.88L17.41 4.56L19.44 6.59L18.12 8.61L18.73 10.07L21.09 10.56L21.09 13.44L18.73 13.93L18.12 15.39L19.44 17.41L17.41 19.44L15.39 18.12L13.93 18.73L13.44 21.09L10.56 21.09L10.07 18.73L8.61 18.12L6.59 19.44L4.56 17.41L5.88 15.39L5.27 13.93L2.91 13.44L2.91 10.56L5.27 10.07L5.88 8.61L4.56 6.59L6.59 4.56L8.61 5.88Z" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/><circle cx="12" cy="12" r="3" fill="none" stroke="currentColor" stroke-width="1.5"/></svg></a><?php endif; ?>
  </div>
</nav>

<main class="wrap">
  <div class="album-head" id="album-head"></div>
  <div class="grid" id="grid"></div>
  <?php if (!$photoCount): ?>
    <p class="empty">No photos here yet.<?php if ($owner): ?> Run a sync on the <a href="admin.php">admin page</a>.<?php endif; ?></p>
  <?php endif; ?>
</main>

<footer class="foot">
  <span>&copy; <?= date('Y') ?> <?= h($subtitle !== '' ? $subtitle : $title) ?></span>
  <a href="admin.php">Admin</a>
  <?php if ($visitor): ?><a href="?signout=1">Sign out</a><?php else: ?><a href="?signin=1">Sign in for more albums</a><?php endif; ?>
</footer>

<div class="lb" id="lb" hidden role="dialog" aria-modal="true" aria-label="Photo viewer">
  <div class="lb-stage" id="lb-stage">
    <img id="lb-img" alt="">
  </div>
  <div class="lb-top">
    <span class="lb-count" id="lb-count"></span>
    <div class="lb-actions">
      <button class="icon-btn" id="lb-fav" type="button" aria-label="Favourite" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.3 4.6 13a4.7 4.7 0 0 1 6.6-6.7l.8.8.8-.8a4.7 4.7 0 0 1 6.6 6.7z"/></svg></button>
      <button class="icon-btn" id="lb-share" type="button" aria-label="Share or download"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="18" cy="5.5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="18.5" r="2.5"/><path d="m8.2 10.8 7.6-4.1M8.2 13.2l7.6 4.1"/></svg></button>
      <button class="icon-btn" id="lb-play" type="button" aria-label="Play slideshow"><svg viewBox="0 0 24 24" aria-hidden="true"><path class="i-play" d="M8 5.5v13l10.5-6.5z"/><path class="i-pause" d="M7 5h3.5v14H7zm6.5 0H17v14h-3.5z"/></svg></button>
      <?php if ($owner): ?><button class="icon-btn" id="lb-cover" type="button" aria-label="Make this the album cover" title="Make this the album cover"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="14" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m3.5 16 4.6-4.6 3.4 3.4 2.6-2.6 6.4 6.3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/><circle cx="15.5" cy="9.3" r="1.5" fill="none" stroke="currentColor" stroke-width="1.6"/></svg></button>
      <button class="icon-btn" id="lb-edit" type="button" aria-label="Edit caption"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 1-4.5L15.8 4.7a1.8 1.8 0 0 1 2.5 0l1 1a1.8 1.8 0 0 1 0 2.5L8.5 19z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></button>
      <button class="icon-btn" id="lb-del" type="button" aria-label="Move to Trash" title="Move to Trash"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4.5 6.5h15M9.5 6.5V4.8c0-.4.4-.8.8-.8h3.4c.4 0 .8.4.8.8v1.7M6.5 6.5l.9 12.6c.1.8.7 1.4 1.5 1.4h6.2c.8 0 1.4-.6 1.5-1.4l.9-12.6M10 10.5v6M14 10.5v6" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button><?php endif; ?>
      <button class="icon-btn" id="lb-close" type="button" aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="1.6" fill="none"/></svg></button>
    </div>
  </div>
  <button class="lb-nav lb-prev" id="lb-prev" type="button" aria-label="Previous photo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 4 7 12l8 8" stroke="currentColor" stroke-width="1.4" fill="none"/></svg></button>
  <button class="lb-nav lb-next" id="lb-next" type="button" aria-label="Next photo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 4 8 8-8 8" stroke="currentColor" stroke-width="1.4" fill="none"/></svg></button>
  <div class="lb-cap" id="lb-cap" aria-live="polite">
    <p class="cap-title" id="cap-title"></p>
    <p class="cap-meta" id="cap-meta"></p>
    <p class="cap-desc" id="cap-desc"></p>
    <p class="cap-tags" id="cap-tags"></p>
  </div>
  <div class="lb-strip" id="lb-strip" aria-label="All photos in this album"></div>

  <div class="share" id="share" hidden role="dialog" aria-modal="true" aria-labelledby="sh-title">
    <div class="share-card">
      <img id="sh-img" alt="">
      <div class="share-info">
        <p class="share-title" id="sh-title"></p>
        <div class="share-by">
          <span><?= h($subtitle !== '' ? $subtitle : $title) ?></span>
          <span id="sh-date"></span>
        </div>
      </div>
    </div>
    <p class="share-head">Save and share</p>
    <div class="share-btns">
      <a class="share-btn" id="sh-dl" href="#" download><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 4v11m0 0-4.5-4.5M12 15l4.5-4.5M5 19.5h14"/></svg></span>Download</a>
      <button class="share-btn" id="sh-copy" type="button"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg></span><em id="sh-copy-label">Copy link</em></button>
      <button class="share-btn" id="sh-fb" type="button"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path class="fill" d="M13.5 21v-7.5H16l.4-3h-2.9V8.7c0-.9.3-1.5 1.5-1.5h1.5V4.5c-.3 0-1.2-.1-2.2-.1-2.2 0-3.7 1.3-3.7 3.8v2.3H8.1v3h2.5V21z"/></svg></span>Facebook</button>
      <button class="share-btn" id="sh-x" type="button"><span><svg viewBox="0 0 24 24" aria-hidden="true"><path class="fill" d="M17.3 4h2.9l-6.3 7.2L21.3 20h-5.8l-4.5-5.9L5.8 20H2.9l6.7-7.7L2.5 4h5.9l4.1 5.4zm-1 14.3h1.6L7.6 5.6H5.9z"/></svg></span>X</button>
      <button class="share-btn" id="sh-more" type="button" hidden><span><svg viewBox="0 0 24 24" aria-hidden="true"><circle class="fill" cx="5.5" cy="12" r="1.6"/><circle class="fill" cx="12" cy="12" r="1.6"/><circle class="fill" cx="18.5" cy="12" r="1.6"/></svg></span>More</button>
    </div>
    <p class="share-note" id="sh-note">Copy link, Facebook and X give this one photo its own public page. The rest of the gallery stays private.</p>
    <?php if ($owner): ?><p class="share-owner" id="sh-owner" hidden>This photo has a public page. <button type="button" id="sh-stop">Stop sharing</button></p><?php endif; ?>
    <p class="share-msg" id="sh-msg" role="status"></p>
    <button class="icon-btn share-close" id="sh-close" type="button" aria-label="Close sharing"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
  </div>
  <?php if ($owner): ?>
  <div class="cap-edit del-confirm" id="del-confirm" hidden role="alertdialog" aria-modal="true" aria-labelledby="del-head" aria-describedby="del-text">
    <img id="del-img" alt="">
    <div>
      <h2 class="cap-edit-head" id="del-head">Move this photo to the Trash?</h2>
      <p class="del-text" id="del-text">It comes off the gallery, and the original moves from its album in Dropbox to <strong><?= h(trash_path()) ?></strong>. To bring it back, move it back into its album folder in Dropbox.</p>
      <div class="cap-btns">
        <button type="button" class="btn btn-solid btn-danger" id="del-yes">Move to Trash</button>
        <button type="button" class="btn btn-ghost" id="del-no">Cancel</button>
      </div>
      <p class="cap-msg" id="del-msg" role="status"></p>
    </div>
  </div>
  <form class="cap-edit" id="cap-edit" hidden>
    <h2 class="cap-edit-head">Caption</h2>
    <label for="cap-t">Title</label>
    <input id="cap-t" maxlength="200" autocomplete="off">
    <div class="cap-row">
      <div>
        <label for="cap-d">Date</label>
        <input id="cap-d" maxlength="200" autocomplete="off" placeholder="e.g. 6 November 2026">
        <p class="cap-hint" id="cap-d-hint"></p>
      </div>
      <div>
        <label for="cap-l">Location</label>
        <input id="cap-l" maxlength="200" autocomplete="off" placeholder="e.g. Regina, Canada">
        <p class="cap-hint" id="cap-l-hint"></p>
      </div>
    </div>
    <label for="cap-x">Description</label>
    <textarea id="cap-x" rows="3" maxlength="2000"></textarea>
    <label for="cap-g">Tags</label>
    <input id="cap-g" maxlength="1700" autocomplete="off" placeholder="e.g. Rural, Prairie, Grain elevator">
    <p class="cap-hint">Type to find a tag, or press Enter (or a comma) to add a new one</p>
    <label for="cap-album">Album</label>
    <select id="cap-album"></select>
    <p class="cap-hint" id="cap-album-hint">Choosing another album moves the photo there, in Dropbox too</p>
    <fieldset class="cap-also">
      <legend>Also show in</legend>
      <div id="cap-also"></div>
    </fieldset>
    <div class="cap-btns">
      <button type="submit" class="btn btn-solid">Save</button>
      <button type="button" class="btn btn-ghost" id="cap-reset">Use automatic</button>
      <button type="button" class="btn btn-ghost" id="cap-cancel">Cancel</button>
    </div>
    <p class="cap-msg" id="cap-msg" role="status"></p>
  </form>
  <?php endif; ?>
</div>

<script>window.GALLERY = <?= json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<?php if ($owner): ?><script src="assets/tags.js?v=<?= h((string) @filemtime(__DIR__ . '/assets/tags.js')) ?>"></script><script src="assets/places.js?v=<?= h((string) @filemtime(__DIR__ . '/assets/places.js')) ?>"></script><?php endif; ?>
<script src="<?= h($js) ?>"></script>
</body>
</html>
