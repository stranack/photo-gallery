<?php
/*
 * Makes an album public or private from the gallery page. Only the owner
 * (signed in with the admin password in this browser) can do this.
 *   POST slug, public=1|0
 * A public album is open to anyone, without the gallery password.
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
$slug = (string) ($_POST['slug'] ?? '');
$public = ($_POST['public'] ?? '') === '1';
if (!set_album_public($slug, $public)) {
    reply(404, ['error' => 'That album is no longer in the gallery.']);
}
reply(200, ['slug' => $slug, 'public' => $public]);
