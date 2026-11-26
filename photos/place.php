<?php
/*
 * Place-name suggestions for the owner's Location boxes:  place.php?q=Clayb
 * Returns [{id, label, kind}] from GeoNames. Owner only, so the free
 * GeoNames allowance is spent only on the owner's typing.
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: private, max-age=3600');

if (!is_owner()) {
    http_response_code(403);
    echo json_encode(['error' => 'Sign in to admin.php first.']);
    exit;
}
try {
    echo json_encode(['places' => place_suggestions((string) ($_GET['q'] ?? ''))], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(502);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
