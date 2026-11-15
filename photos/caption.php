<?php
/*
 * Saves a caption written on the gallery page. Only the owner (signed in to
 * admin.php in this browser) can do this.
 *   POST id, title, date, location, description   saves the caption details
 *   POST id, reset=1       goes back to the date and location from the file name
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
$file = photo_file($id);
if ($file === null) {
    reply(404, ['error' => 'That photo is no longer in the gallery.']);
}
if (!empty($_POST['reset'])) {
    save_caption($id, null, $file);
} else {
    $fields = [];
    foreach (CAPTION_FIELDS as $f) {
        $fields[$f] = (string) ($_POST[$f] ?? '');
    }
    save_caption($id, $fields, $file);
}
reply(200, ['caption' => caption_fields($id, $file), 'custom' => isset(load_captions()[$id])]);
