<?php
/*
 * Saves a caption written on the gallery page. Only the owner (signed in to
 * admin.php in this browser) can do this.
 *   POST id, caption       sets your own caption ("" hides the caption)
 *   POST id, reset=1       goes back to the caption made from the file name
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['error' => 'POST only']);
}
if (!is_owner()) {
    reply(403, ['error' => 'Sign in to admin.php to edit captions.']);
}
if (!hash_equals(owner_csrf(), (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
    reply(403, ['error' => 'This page is out of date. Reload it and try again.']);
}
$id = (string) ($_POST['id'] ?? '');
if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
    reply(400, ['error' => 'Unknown photo']);
}
$auto = null;
foreach (load_gallery()['albums'] ?? [] as $a) {
    foreach ($a['photos'] as $p) {
        if ($p['id'] === $id) {
            $auto = $p['auto'];
        }
    }
}
if ($auto === null) {
    reply(404, ['error' => 'That photo is no longer in the gallery.']);
}
if (!empty($_POST['reset'])) {
    save_caption($id, null);
    reply(200, ['caption' => $auto, 'custom' => false]);
}
save_caption($id, (string) ($_POST['caption'] ?? ''));
reply(200, ['caption' => load_captions()[$id] ?? '', 'custom' => true]);
