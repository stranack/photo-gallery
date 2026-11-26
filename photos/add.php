<?php
/*
 * Add images and albums without opening Dropbox (owner only).
 *   add.php                 the Add images page
 *   add.php?new=album       the Add album page
 * The page's script talks to the same address (POST, JSON, X-CSRF header):
 *   action=album      name, public=1|0          creates "Album N Name" in Dropbox
 *   action=chunk      ?session=&offset=, body is a piece of the file
 *                     (no session: starts an upload; returns its session)
 *   action=finish     session, offset, album, file, title, date, location,
 *                     description, tags        saves the photo and its caption
 *   action=sync       album                     brings new photos onto the site
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $reply = static function (int $code, array $body): void {
        http_response_code($code);
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    };
    if (!is_owner()) {
        $reply(403, ['error' => 'Sign in to admin.php first.']);
    }
    if (!hash_equals(owner_csrf(), (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
        $reply(403, ['error' => 'This page is out of date. Reload it and try again.']);
    }
    @set_time_limit(120);
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
    try {
        $source = make_source();
        if ($action === 'album') {
            $folder = create_album($source, (string) ($_POST['name'] ?? ''), ($_POST['public'] ?? '') === '1');
            log_line("Created album folder $folder.");
            $reply(200, ['folder' => $folder, 'name' => album_label($folder)['name']]);
        }
        if ($action === 'chunk') {
            $bytes = (string) file_get_contents('php://input');
            if ($bytes === '') {
                $reply(400, ['error' => 'The piece of the file didn’t arrive. Try again.']);
            }
            $session = (string) ($_GET['session'] ?? '');
            $offset = (int) ($_GET['offset'] ?? 0);
            if ($session === '') {
                $session = $source->uploadStart($bytes);
            } else {
                $source->uploadAppend($session, $offset, $bytes);
            }
            $reply(200, ['session' => $session, 'offset' => $offset + strlen($bytes)]);
        }
        if ($action === 'finish') {
            $album = (string) ($_POST['album'] ?? '');
            if (!in_array($album, $source->listAlbumFolders(), true)) {
                $reply(400, ['error' => 'Choose an album.']);
            }
            $file = (string) ($_POST['file'] ?? '');
            if (!is_image_name(basename($file))) {
                $reply(400, ['error' => 'That isn’t a kind of photo the gallery shows (JPEG, PNG, GIF, WebP, TIFF, BMP or HEIC).']);
            }
            $fields = [];
            foreach (CAPTION_FIELDS as $f) {
                $fields[$f] = (string) ($_POST[$f] ?? '');
            }
            $name = upload_name($file, $fields['date'], $fields['location']);
            $saved = $source->uploadFinish((string) ($_POST['session'] ?? ''), (int) ($_POST['offset'] ?? 0), $album . '/' . $name);
            $id = photo_id($saved['key']);
            $final = basename($saved['path']);
            // Date and location that match the new file name (or were left
            // empty) follow the file name, like photos added in Dropbox.
            $auto = auto_fields($final);
            $parsed = parse_photo_name($final);
            if (trim($fields['date']) === '' || trim($fields['date']) === $parsed['date']) {
                $fields['date'] = $auto['date'];
            }
            if (trim($fields['location']) === '' || place_key($fields['location']) === place_key($auto['location'])) {
                $fields['location'] = $auto['location'];
            }
            save_caption($id, $fields, $final);
            log_line("Added {$saved['path']} from the gallery.");
            $reply(200, ['id' => $id, 'path' => $saved['path']]);
        }
        if ($action === 'sync') {
            $r = (new Sync($source))->run(25.0);
            if (($r['status'] ?? '') === 'error') {
                $reply(500, ['error' => $r['error'] ?? 'The sync failed.']);
            }
            $slug = null;
            foreach (load_gallery()['albums'] ?? [] as $a) {
                if (($a['folder'] ?? '') === (string) ($_POST['album'] ?? '')) {
                    $slug = $a['slug'];
                }
            }
            $reply(200, ['status' => $r['status'], 'remaining' => $r['remaining'], 'slug' => $slug]);
        }
        $reply(400, ['error' => 'Unknown action']);
    } catch (Throwable $e) {
        log_line('Add page: ' . $e->getMessage());
        $reply(500, ['error' => $e->getMessage()]);
    }
}

if (!is_owner()) {
    header('Location: admin.php');
    exit;
}

$mode = ($_GET['new'] ?? '') === 'album' ? 'album' : 'images';
$folders = [];
$loadError = null;
try {
    $folders = album_folders(make_source());
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}
$settings = load_album_settings();
$preselect = (string) ($_GET['album'] ?? '');
$css = 'assets/style.css?v=' . @filemtime(__DIR__ . '/assets/style.css');
$js = 'assets/add.js?v=' . @filemtime(__DIR__ . '/assets/add.js');
$page = [
    'csrf' => owner_csrf(),
    'chunk' => upload_chunk_bytes(),
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $mode === 'album' ? 'Add album' : 'Add images' ?> · <?= h(cfg('site_title', 'Photographs')) ?></title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="<?= h($css) ?>">
</head>
<body class="admin add-page">
<main class="admin-wrap add-wrap">
  <p class="admin-back"><a href="./">&larr; <?= h(cfg('site_title', 'Photographs')) ?></a></p>
  <nav class="add-switch" aria-label="Add">
    <a href="add.php" <?= $mode === 'images' ? 'aria-current="page"' : '' ?>>Add images</a>
    <a href="add.php?new=album" <?= $mode === 'album' ? 'aria-current="page"' : '' ?>>Add album</a>
  </nav>
  <?php if ($loadError): ?><p class="flash flash-err" role="alert"><?= h($loadError) ?></p><?php endif; ?>

<?php if ($mode === 'album'): ?>
  <h1>Add album</h1>
  <form class="panel" id="album-form">
    <label for="al-name">Album name</label>
    <input id="al-name" name="name" maxlength="80" required autocomplete="off" placeholder="e.g. Grain Elevators">
    <p class="hint">It becomes the folder <strong>Album <?= h((string) (max(array_merge([0], array_map(fn($f) => $f['order'] === PHP_INT_MAX ? 0 : $f['order'], $folders))) + 1)) ?> <span id="al-preview">…</span></strong> in Dropbox, last on the Albums page.</p>
    <fieldset class="add-vis">
      <legend>Who can see it</legend>
      <label><input type="radio" name="public" value="1"> Public: anyone</label>
      <label><input type="radio" name="public" value="0" checked> Private: needs the gallery password</label>
    </fieldset>
    <button type="submit" class="btn">Create album</button>
    <p class="add-msg" id="al-msg" role="status"></p>
  </form>
  <p class="hint">An album shows on the gallery once it has photos.</p>

<?php else: ?>
  <h1>Add images</h1>
  <form class="panel" id="up-form">
    <label for="up-album">Album</label>
    <div class="add-row">
      <select id="up-album" required>
        <option value="">Choose an album…</option>
        <?php foreach ($folders as $f): $pub = !empty($settings[$f['folder']]['public']); ?>
          <option value="<?= h($f['folder']) ?>" <?= $preselect === $f['folder'] ? 'selected' : '' ?>><?= h($f['name']) ?><?= $pub ? '' : ' (private)' ?></option>
        <?php endforeach; ?>
      </select>
      <a class="btn btn-quiet" href="add.php?new=album">New album</a>
    </div>

    <label for="up-files">Photos</label>
    <label class="drop" id="drop">
      <input type="file" id="up-files" accept="image/*,.heic,.heif,.tif,.tiff" multiple>
      <span>Choose photos, or drop them here</span>
    </label>

    <div class="add-shared">
      <div>
        <label for="up-loc">Location, for all</label>
        <input id="up-loc" maxlength="200" autocomplete="off" placeholder="e.g. Regina, Saskatchewan, Canada">
      </div>
      <div>
        <label for="up-tags">Tags, for all</label>
        <input id="up-tags" maxlength="1700" autocomplete="off" placeholder="e.g. Rural, Prairie">
      </div>
    </div>
    <p class="hint">Each photo is renamed with its date and place, like your other photos (2026-11-26_Regina_Saskatchewan_Canada_DSC_4410.jpg), and keeps its original size in Dropbox.</p>

    <ol class="up-list" id="up-list"></ol>

    <div class="btn-row">
      <button type="submit" class="btn" id="up-go" disabled>Upload</button>
      <a class="btn btn-quiet" id="up-view" href="./" hidden>View album</a>
    </div>
    <p class="add-msg" id="up-msg" role="status"></p>
  </form>
<?php endif; ?>
</main>
<script>window.ADD = <?= json_encode($page, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script src="<?= h($js) ?>"></script>
</body>
</html>
