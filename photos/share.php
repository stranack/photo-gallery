<?php
/*
 * Public page for one shared photo:   share.php?s=TOKEN
 * Anyone with the link sees that photo and its caption; Facebook and X read
 * the preview tags. The rest of the gallery stays behind the password.
 *
 * Also creates and removes share links (POST, JSON):
 *   action=create, id   anyone signed in to the gallery, or anyone at all
 *                       for a photo in a public album
 *   action=remove, id   the owner only
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $reply = static function (int $code, array $body): void {
        http_response_code($code);
        echo json_encode($body);
        exit;
    };
    if (!hash_equals(share_csrf(), (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        $reply(403, ['error' => 'This page is out of date. Reload it and try again.']);
    }
    $id = (string) ($_POST['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{16}$/', $id) || find_photo($id) === null) {
        $reply(404, ['error' => 'That photo is no longer in the gallery.']);
    }
    if (!is_visitor() && !photo_is_public($id)) {
        $reply(403, ['error' => 'Sign in to the gallery first.']);
    }
    $action = (string) ($_POST['action'] ?? '');
    if ($action === 'create') {
        $t = share_create($id);
        $reply(200, ['token' => $t, 'url' => site_base_url() . 'share.php?s=' . $t]);
    }
    if ($action === 'remove') {
        if (!is_owner()) {
            $reply(403, ['error' => 'Only the owner can stop sharing a photo.']);
        }
        share_remove($id);
        $reply(200, ['token' => null]);
    }
    $reply(400, ['error' => 'Unknown action']);
}

header('Cache-Control: no-cache');
$token = (string) ($_GET['s'] ?? '');
$id = share_photo($token);
$photo = $id !== null ? find_photo($id) : null;
$siteTitle = (string) cfg('site_title', 'Photographs');
$by = (string) cfg('site_subtitle', '');
$css = 'assets/style.css?v=' . @filemtime(__DIR__ . '/assets/style.css');

if ($photo === null) {
    http_response_code(404);
    header('X-Robots-Tag: noindex');
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= h($siteTitle) ?></title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= h($css) ?>">
</head>
<body class="gate">
<main class="gate-card">
  <p class="eyebrow"><?= h($siteTitle) ?></p>
  <h1 class="display" style="font-size:clamp(1.6rem,4vw,2.6rem)">This photo isn’t shared any more</h1>
  <p class="gate-note">The link may have been turned off by the photographer.</p>
</main>
</body>
</html>
<?php
    exit;
}

$cap = caption_fields($id, $photo['file']);
if (same_place($cap['description'], $cap['location'])) {
    $cap['description'] = ''; // don't show the place twice
}
$meta = implode(' · ', array_filter([$cap['date'], $cap['location']], fn($x) => $x !== ''));
$heading = $cap['title'] !== '' ? $cap['title'] : ($meta !== '' ? $meta : $siteTitle);
$base = site_base_url();
$img = 'img.php?id=' . $id . '&s=l&t=' . $token . '&v=' . $photo['v'];
$pageUrl = $base . 'share.php?s=' . $token;
$summary = $cap['description'] !== '' ? $cap['description'] : $meta;
$summary = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $summary)), 0, 200);
$byline = $by !== '' ? 'Photograph by ' . $by : $siteTitle;
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= h($heading) ?> · <?= h($by !== '' ? $by : $siteTitle) ?></title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= h($css) ?>">
<link rel="canonical" href="<?= h($pageUrl) ?>">
<meta property="og:type" content="article">
<meta property="og:site_name" content="<?= h($siteTitle . ($by !== '' ? ' · ' . $by : '')) ?>">
<meta property="og:title" content="<?= h($heading) ?>">
<meta property="og:description" content="<?= h($summary !== '' ? $summary : $byline) ?>">
<meta property="og:url" content="<?= h($pageUrl) ?>">
<meta property="og:image" content="<?= h($base . $img) ?>">
<meta property="og:image:width" content="<?= (int) $photo['w'] ?>">
<meta property="og:image:height" content="<?= (int) $photo['h'] ?>">
<meta property="og:image:alt" content="<?= h($heading) ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= h($heading) ?>">
<meta name="twitter:description" content="<?= h($summary !== '' ? $summary : $byline) ?>">
<meta name="twitter:image" content="<?= h($base . $img) ?>">
</head>
<body class="shared">
<main class="shared-wrap">
  <figure class="shared-fig">
    <img src="<?= h($img) ?>" alt="<?= h($heading) ?>" width="<?= (int) $photo['w'] ?>" height="<?= (int) $photo['h'] ?>">
    <figcaption class="shared-cap">
      <?php if ($cap['title'] !== ''): ?><p class="cap-title"><?= h($cap['title']) ?></p><?php endif; ?>
      <?php if ($meta !== ''): ?><p class="cap-meta"><?= h($meta) ?></p><?php endif; ?>
      <?php if ($cap['description'] !== ''): ?><p class="cap-desc shared-desc"><?= h($cap['description']) ?></p><?php endif; ?>
    </figcaption>
  </figure>
  <footer class="shared-foot">
    <span><?= h($byline) ?></span>
    <a href="./"><?= h($siteTitle) ?> gallery</a>
  </footer>
</main>
</body>
</html>
