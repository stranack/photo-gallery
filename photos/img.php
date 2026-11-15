<?php
/*
 * Hands a photo to visitors who have the gallery password, or to anyone with
 * the share token of a photo that has been shared. The copies live in images/,
 * which is blocked from the web, so these checks can't be skipped.
 *   &dl=1  sends it as a download, named after the photo's title.
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$id = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
    http_response_code(404);
    exit;
}
$token = (string) ($_GET['t'] ?? '');
$shared = $token !== '' && share_photo($token) === $id;
if (!$shared && !is_visitor()) {
    http_response_code(403);
    exit;
}
$file = image_file($id, ($_GET['s'] ?? '') === 'l' ? 'large' : 'grid');
if (!is_file($file)) {
    http_response_code(404);
    exit;
}
$etag = '"' . $id . '-' . filemtime($file) . '"';
header('Content-Type: image/jpeg');
// The address carries a version, so browsers may keep it; "private" keeps it
// out of shared caches, since the gallery is password protected.
header('Cache-Control: private, max-age=' . ($shared ? '3600' : '31536000, immutable'));
if (!empty($_GET['dl'])) {
    $name = download_name($id, (string) (find_photo($id)['file'] ?? 'photo.jpg'));
    $ascii = preg_replace('/[^A-Za-z0-9 ._()\-]/', '', $name) ?: 'photo.jpg';
    header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
}
header('ETag: ' . $etag);
header('X-Robots-Tag: noindex');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($file));
readfile($file);
