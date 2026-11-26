<?php
/*
 * Album settings from the gallery page. Only the owner (signed in with the
 * admin password in this browser) can change them.
 *   POST slug, public=1|0      makes an album public (open to anyone, without
 *                              the gallery password) or private
 *   POST action=cover, id      makes a photo its album's cover on the Albums page
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    reply(405, ['error' => 'POST only']);
}
if (!is_owner()) {
    reply(403, ['error' => 'Sign in to admin.php to change who can see an album.']);
}
if (!hash_equals(owner_csrf(), (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
    reply(403, ['error' => 'This page is out of date. Reload it and try again.']);
}
if (($_POST['action'] ?? '') === 'cover') {
    $id = (string) ($_POST['id'] ?? '');
    $slug = preg_match('/^[0-9a-f]{16}$/', $id) ? set_album_cover($id) : null;
    if ($slug === null) {
        reply(404, ['error' => 'That photo is no longer in the gallery.']);
    }
    reply(200, ['slug' => $slug, 'cover' => $id]);
}
$slug = (string) ($_POST['slug'] ?? '');
$public = ($_POST['public'] ?? '') === '1';
if (!set_album_public($slug, $public)) {
    reply(404, ['error' => 'That album is no longer in the gallery.']);
}
reply(200, ['slug' => $slug, 'public' => $public]);
