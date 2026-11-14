<?php
/*
 * Hands a photo to visitors who have the gallery password. The copies live in
 * images/, which is blocked from the web, so this check can't be skipped.
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

if (!is_visitor()) {
    http_response_code(403);
    exit;
}
$id = (string) ($_GET['id'] ?? '');
if (!preg_match('/^[0-9a-f]{16}$/', $id)) {
    http_response_code(404);
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
header('Cache-Control: private, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Robots-Tag: noindex');
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($file));
readfile($file);
