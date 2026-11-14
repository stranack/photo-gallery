<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$title = (string) cfg('site_title', 'Photographs');
$subtitle = (string) cfg('site_subtitle', '');
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    if (gallery_password_usable() && hash_equals((string) cfg('gallery_password'), (string) $_POST['password'])) {
        set_visitor_cookie(true);
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
$allowed = is_visitor();
$css = 'assets/style.css?v=' . @filemtime(__DIR__ . '/assets/style.css');
$js = 'assets/gallery.js?v=' . @filemtime(__DIR__ . '/assets/gallery.js');

function head_html(string $title, string $css): void
{ ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="same-origin">
<title><?= h($title) ?></title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= h($css) ?>">
</head>
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
  <form method="post" class="gate-form">
    <label for="pw" class="visually-hidden">Gallery password</label>
    <input id="pw" type="password" name="password" placeholder="Password" autocomplete="current-password" required autofocus>
    <button type="submit" class="btn btn-solid">View gallery</button>
  </form>
  <?php if ($error): ?><p class="gate-error" role="alert"><?= h($error) ?></p><?php endif; ?>
  <?php endif; ?>
</main>
</body>
</html>
<?php exit; endif;

$data = gallery_for_page($owner);
$data['owner'] = $owner;
$data['csrf'] = $owner ? owner_csrf() : null;
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

<nav class="bar" id="gallery" aria-label="Albums">
  <a class="bar-title" href="#" data-top><?= h($title) ?></a>
  <div class="tabs" role="tablist" id="tabs"></div>
  <div class="bar-tools">
    <button class="icon-btn" id="play" type="button" title="Slideshow" aria-label="Play slideshow">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5.5v13l10.5-6.5z"/></svg><span>Slideshow</span>
    </button>
    <?php if ($owner): ?><a class="icon-btn" href="admin.php" title="Admin" aria-label="Admin"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 8.5a3.5 3.5 0 1 0 0 7 3.5 3.5 0 0 0 0-7Zm8.4 4.6.1-1.1-.1-1.1 2-1.6-2-3.4-2.4 1a8 8 0 0 0-1.9-1.1L15.8 3h-3.9l-.4 2.6a8 8 0 0 0-1.9 1.1l-2.4-1-2 3.4 2 1.6-.1 1.1.1 1.1-2 1.6 2 3.4 2.4-1c.6.5 1.2.8 1.9 1.1l.4 2.6h3.9l.4-2.6c.7-.3 1.3-.6 1.9-1.1l2.4 1 2-3.4z" fill="none" stroke="currentColor" stroke-width="1.5"/></svg></a><?php endif; ?>
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
  <a href="?signout=1">Sign out</a>
</footer>

<div class="lb" id="lb" hidden role="dialog" aria-modal="true" aria-label="Photo viewer">
  <div class="lb-stage" id="lb-stage">
    <img id="lb-img" alt="">
  </div>
  <div class="lb-top">
    <span class="lb-count" id="lb-count"></span>
    <div class="lb-actions">
      <button class="icon-btn" id="lb-fav" type="button" aria-label="Favourite" aria-pressed="false"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 20.3 4.6 13a4.7 4.7 0 0 1 6.6-6.7l.8.8.8-.8a4.7 4.7 0 0 1 6.6 6.7z"/></svg></button>
      <button class="icon-btn" id="lb-play" type="button" aria-label="Play slideshow"><svg viewBox="0 0 24 24" aria-hidden="true"><path class="i-play" d="M8 5.5v13l10.5-6.5z"/><path class="i-pause" d="M7 5h3.5v14H7zm6.5 0H17v14h-3.5z"/></svg></button>
      <?php if ($owner): ?><button class="icon-btn" id="lb-edit" type="button" aria-label="Edit caption"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 1-4.5L15.8 4.7a1.8 1.8 0 0 1 2.5 0l1 1a1.8 1.8 0 0 1 0 2.5L8.5 19z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/></svg></button><?php endif; ?>
      <button class="icon-btn" id="lb-close" type="button" aria-label="Close"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6 6 18" stroke="currentColor" stroke-width="1.6" fill="none"/></svg></button>
    </div>
  </div>
  <button class="lb-nav lb-prev" id="lb-prev" type="button" aria-label="Previous photo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 4 7 12l8 8" stroke="currentColor" stroke-width="1.4" fill="none"/></svg></button>
  <button class="lb-nav lb-next" id="lb-next" type="button" aria-label="Next photo"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9 4 8 8-8 8" stroke="currentColor" stroke-width="1.4" fill="none"/></svg></button>
  <p class="lb-cap" id="lb-cap"></p>
  <?php if ($owner): ?>
  <form class="cap-edit" id="cap-edit" hidden>
    <label for="cap-text">Caption</label>
    <textarea id="cap-text" rows="2" maxlength="500"></textarea>
    <p class="cap-auto" id="cap-auto"></p>
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
<script src="<?= h($js) ?>"></script>
</body>
</html>
