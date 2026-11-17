<?php
/*
 * Moves a photo to the Trash folder from the gallery's full-screen view.
 * Only the owner (signed in with the admin password in this browser) can do this.
 *   POST id   moves the original out of its album in Dropbox into the Trash
 *             folder and takes the photo off the site
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
    reply(403, ['error' => 'Sign in to admin.php to remove photos.']);
}
if (!hash_equals(owner_csrf(), (string) ($_SERVER['HTTP_X_CSRF'] ?? ''))) {
    reply(403, ['error' => 'This page is out of date. Reload it and try again.']);
}
$id = (string) ($_POST['id'] ?? '');
if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
    reply(400, ['error' => 'Unknown photo']);
}
@set_time_limit(60);
try {
    $r = trash_photo($id, make_source());
} catch (Throwable $e) {
    log_line('Could not move a photo to the Trash: ' . $e->getMessage());
    reply(500, ['error' => $e->getMessage()]);
}
reply(200, ['ok' => true, 'to' => $r['to']]);
