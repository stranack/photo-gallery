<?php
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('X-Robots-Tag: noindex');
header('Cache-Control: no-store');

session_name('gallery_admin');
session_set_cookie_params([
    'httponly' => true,
    'samesite' => 'Lax',
    'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$signedIn = !empty($_SESSION['admin']) && is_owner();
$notice = null;
$error = null;
$syncResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $csrfOk = hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''));

    if ($action === 'login') {
        if (admin_password_usable() && hash_equals((string) cfg('admin_password'), (string) ($_POST['password'] ?? ''))) {
            session_regenerate_id(true);
            $_SESSION['admin'] = true;
            set_owner_cookie(true);
            header('Location: admin.php');
            exit;
        }
        failed_attempt_pause();
        $error = 'That password is not correct.';
    } elseif (!$csrfOk) {
        $error = 'The form expired. Try again.';
    } elseif ($signedIn) {
        try {
            switch ($action) {
                case 'logout':
                    set_owner_cookie(false);
                    $_SESSION = [];
                    session_destroy();
                    header('Location: admin.php');
                    exit;
                case 'connect':
                    $token = DropboxSource::exchangeCode(cfg('dropbox_app_key'), cfg('dropbox_app_secret'), (string) ($_POST['code'] ?? ''), cfg('_dropbox_hosts', []));
                    save_dropbox_refresh_token($token);
                    $who = '';
                    try {
                        $src = make_source();
                        $who = $src instanceof DropboxSource ? ' as ' . $src->accountName() : '';
                    } catch (Throwable $e) {
                    }
                    $notice = 'Dropbox connected' . $who . '. Run a sync to load your albums.';
                    break;
                case 'unshare':
                    share_remove((string) ($_POST['id'] ?? ''));
                    $notice = 'That photo is no longer shared. Its old link now shows a "not shared" page.';
                    break;
                case 'disconnect':
                    save_dropbox_refresh_token(null);
                    $notice = 'Dropbox disconnected. Photos already on the site stay until the next sync.';
                    break;
                case 'test':
                    $n = count(make_source()->listImages());
                    $notice = "The connection works: $n photos found in " . make_source()->describe() . '.';
                    break;
                case 'sync':
                case 'sync-force':
                    @set_time_limit(60);
                    $syncResult = (new Sync(make_source()))->run(20.0, $action === 'sync-force');
                    if (($syncResult['status'] ?? '') === 'error') {
                        $error = $syncResult['error'];
                    }
                    break;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}
$signedIn = !empty($_SESSION['admin']) && is_owner();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin · <?= h(cfg('site_title')) ?></title>
<link rel="icon" href="assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="assets/style.css?v=<?= h((string) @filemtime(__DIR__ . '/assets/style.css')) ?>">
</head>
<body class="admin">
<main class="admin-wrap">
  <p class="admin-back"><a href="./">&larr; <?= h(cfg('site_title')) ?></a></p>
  <h1>Gallery admin</h1>

  <?php if ($notice): ?><p class="flash flash-ok" role="status"><?= h($notice) ?></p><?php endif; ?>
  <?php if ($error): ?><p class="flash flash-err" role="alert"><?= h($error) ?></p><?php endif; ?>

<?php if (!$signedIn): ?>
  <?php if (!CONFIG_LOADED): ?>
    <p>Copy <code>app/config.sample.php</code> to <code>app/config.php</code> and set the passwords first.</p>
  <?php elseif (!admin_password_usable()): ?>
    <p>Set <code>admin_password</code> in <code>app/config.php</code> to something at least 8 characters long (not the sample value), then reload this page.</p>
  <?php else: ?>
    <form method="post" class="panel login">
      <input type="hidden" name="action" value="login">
      <label for="pw">Admin password</label>
      <input id="pw" type="password" name="password" autocomplete="current-password" required autofocus>
      <button type="submit" class="btn">Sign in</button>
    </form>
  <?php endif; ?>
<?php else:
    $state = read_json(STATE_FILE, []);
    $images = $state['images'] ?? [];
    $ok = count(array_filter($images, fn($m) => $m['status'] === 'ok'));
    $failedList = array_filter($images, fn($m) => $m['status'] === 'failed');
    $gallery = load_gallery();
    $captions = load_captions();
    $connected = dropbox_refresh_token() !== null;
    $usingLocal = !empty(cfg('local_albums_path'));
    $haveKeys = cfg('dropbox_app_key') && cfg('dropbox_app_secret');
    $missing = array_filter(['curl', 'json'], fn($e) => !extension_loaded($e));
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'yourdomain') . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/');
    $csrf = h($_SESSION['csrf']);
    $log = is_file(DATA_DIR . '/sync.log') ? array_slice(file(DATA_DIR . '/sync.log', FILE_IGNORE_NEW_LINES) ?: [], -15) : [];
?>
  <div class="panel">
    <h2>Status</h2>
    <dl class="status">
      <div><dt>Source</dt><dd><?= $usingLocal ? h('Folder on this server: ' . cfg('local_albums_path')) : ($connected ? 'Dropbox, ' . h(cfg('dropbox_albums_path')) : 'Dropbox not connected') ?></dd></div>
      <div><dt>Albums</dt><dd><?php if ($gallery['albums']): ?><?= h(implode(', ', array_map(fn($a) => $a['name'] . ' (' . count($a['photos']) . ')', $gallery['albums']))) ?><?php else: ?>None yet<?php endif; ?></dd></div>
      <div><dt>Photos on site</dt><dd><?= number_format($ok) ?><?php if (isset($state['complete']) && !$state['complete']): ?> (more still to fetch)<?php endif; ?><?php if ($failedList): ?>, <?= count($failedList) ?> Dropbox couldn't preview<?php endif; ?></dd></div>
      <div><dt>Your captions</dt><dd><?= count($captions) ?> written by you; the rest come from file names</dd></div>
      <div><dt>Shared photos</dt><dd><?= count(load_shares()) ?> with a public page<?= load_shares() ? ' (listed below)' : '' ?></dd></div>
      <div><dt>Gallery password</dt><dd><?= gallery_password_usable() ? 'Set' : '<span class="err">Not set: visitors can\'t get in until you set <code>gallery_password</code> in config.php</span>' ?></dd></div>
      <div><dt>Last checked</dt><dd><?= !empty($state['last_sync']) ? h(date('M j, Y g:i a', (int) $state['last_sync'])) : 'Never' ?></dd></div>
      <?php if (!empty($state['last_error'])): ?><div><dt>Last error</dt><dd class="err"><?= h($state['last_error']['message']) ?></dd></div><?php endif; ?>
      <div><dt>PHP</dt><dd><?= h(PHP_VERSION) ?><?= $missing ? ', missing: ' . h(implode(', ', $missing)) : ', all needed extensions present' ?></dd></div>
    </dl>
    <p class="hint">You're signed in on this browser, so the full-screen view shows a pencil for editing captions and a trash can for moving a photo to the Trash folder.</p>
  </div>

  <?php if (!$usingLocal): ?>
  <div class="panel">
    <h2>Dropbox</h2>
    <?php if (!$haveKeys): ?>
      <p>Add <code>dropbox_app_key</code> and <code>dropbox_app_secret</code> to <code>app/config.php</code>, then reload this page. The README explains how to create the Dropbox app.</p>
    <?php elseif (!$connected): ?>
      <ol class="steps">
        <li><a href="<?= h(DropboxSource::authorizeUrl(cfg('dropbox_app_key'))) ?>" target="_blank" rel="noopener">Open Dropbox's permission page</a> in a new tab and click <strong>Allow</strong>.</li>
        <li>Dropbox shows an access code. Copy it.</li>
        <li>Paste it here:</li>
      </ol>
      <form method="post" class="inline-form">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="connect">
        <label class="visually-hidden" for="code">Access code from Dropbox</label>
        <input id="code" name="code" required autocomplete="off" placeholder="Access code">
        <button type="submit" class="btn">Connect Dropbox</button>
      </form>
    <?php else: ?>
      <p>Connected. The site reads <code><?= h(cfg('dropbox_albums_path')) ?></code>. The trash button in the full-screen view moves photos to <code><?= h(trash_path()) ?></code>.</p>
      <div class="btn-row">
        <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="test"><button class="btn btn-quiet">Test connection</button></form>
        <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="disconnect"><button class="btn btn-quiet">Disconnect</button></form>
      </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if ($connected || $usingLocal): ?>
  <div class="panel">
    <h2>Sync</h2>
    <?php if ($syncResult && ($syncResult['status'] ?? '') !== 'error'): ?>
      <p class="flash flash-ok" role="status">
        <?= $syncResult['status'] === 'busy' ? 'A sync is already running. Wait a minute and try again.' : h(sprintf(
            '%d new or changed photos fetched, %d removed, %d still to fetch.',
            $syncResult['fetched'], $syncResult['removed'], $syncResult['remaining']
        )) ?>
      </p>
    <?php endif; ?>
    <?php $more = $syncResult && ($syncResult['status'] ?? '') === 'partial'; ?>
    <p>A sync looks for added, changed and removed photos in your albums folder. Each step runs for up to 20 seconds<?= $more ? ', and this page keeps going on its own until every photo is in' : '' ?>.</p>
    <div class="btn-row">
      <form method="post" <?= $more ? 'data-autocontinue' : '' ?>><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="sync"><button class="btn"><?= $more ? 'Continue sync' : 'Sync now' ?></button></form>
      <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="sync-force"><button class="btn btn-quiet">Retry failed photos</button></form>
    </div>
    <?php if ($failedList): ?>
      <p class="hint">Dropbox couldn't make a preview of: <?= h(implode(', ', array_map(fn($m) => $m['path'], array_slice($failedList, 0, 10)))) ?><?= count($failedList) > 10 ? '…' : '' ?>. Raw camera files and some HEIC files can't be previewed; export a JPEG instead.</p>
    <?php endif; ?>
  </div>
  <?php endif; ?>


  <?php $shares = load_shares(); if ($shares): ?>
  <div class="panel">
    <h2>Shared photos</h2>
    <p class="hint">Each of these has a public page that anyone with its link can see. Stopping a share turns its link off for good; sharing the photo again makes a new link.</p>
    <ul class="share-list">
    <?php foreach ($shares as $sid => $sh): $sp = find_photo((string) $sid); ?>
      <li>
        <?php if ($sp): $sc = caption_fields((string) $sid, $sp['file'], $captions); ?>
          <a href="share.php?s=<?= h($sh['t']) ?>" target="_blank" rel="noopener"><img src="img.php?id=<?= h($sid) ?>&amp;s=g&amp;v=<?= h($sp['v']) ?>" alt="" loading="lazy"></a>
          <span><strong><?= h($sc['title'] !== '' ? $sc['title'] : $sp['file']) ?></strong><br><span class="hint"><?= h($sp['album']) ?> · shared <?= h(date('M j, Y', (int) $sh['at'])) ?></span></span>
        <?php else: ?>
          <span><strong>A photo no longer in the gallery</strong></span>
        <?php endif; ?>
        <form method="post"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="unshare"><input type="hidden" name="id" value="<?= h($sid) ?>"><button class="btn btn-quiet">Stop sharing</button></form>
      </li>
    <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="panel">
    <h2>Hourly updates</h2>
    <p>In cPanel, open <strong>Cron Jobs</strong>, choose <strong>Once Per Hour</strong>, and use this command:</p>
    <pre class="cmd"><code>php -q <?= h(APP_DIR . '/sync.php') ?> --quiet</code></pre>
    <?php if (cfg('cron_key')): ?>
    <p>Or have a web cron service visit this address every hour:</p>
    <pre class="cmd"><code><?= h($base . '/cron.php?key=' . cfg('cron_key')) ?></code></pre>
    <?php endif; ?>
  </div>

  <?php if ($log): ?>
  <div class="panel">
    <h2>Recent activity</h2>
    <pre class="log"><?= h(implode("\n", array_reverse($log))) ?></pre>
  </div>
  <?php endif; ?>

  <form method="post" class="signout"><input type="hidden" name="csrf" value="<?= $csrf ?>"><input type="hidden" name="action" value="logout"><button class="link-btn">Sign out</button></form>
<?php endif; ?>
</main>
<script>
document.querySelectorAll('form[data-autocontinue]').forEach(function (f) {
  setTimeout(function () { f.submit(); }, 800);
});
</script>
</body>
</html>
