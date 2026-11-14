<?php
/*
 * Command-line sync, for a cPanel cron job:
 *
 *   php -q /home/USERNAME/public_html/photos/app/sync.php --quiet
 *
 * Add --force to retry photos that failed before.
 */
declare(strict_types=1);

// Refuse web requests. (Some hosts, Bluehost among them, run cron jobs with
// the CGI build of PHP, so PHP_SAPI can't be used to tell them apart.)
if (isset($_SERVER['REQUEST_METHOD']) || isset($_SERVER['REMOTE_ADDR'])) {
    http_response_code(403);
    exit('Run this from the command line or a cron job.');
}

require __DIR__ . '/bootstrap.php';

$args = $_SERVER['argv'] ?? [];
$force = in_array('--force', $args, true);
$quiet = in_array('--quiet', $args, true);

try {
    $sync = new Sync(make_source(), $quiet ? null : static function (string $m): void {
        echo $m, "\n";
    });
    $result = $sync->run((float) cfg('cron_time_budget', 300), $force);
} catch (Throwable $e) {
    log_line('Error: ' . $e->getMessage());
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
if (!$quiet) {
    echo json_encode($result), "\n";
}
exit(($result['status'] ?? '') === 'error' ? 1 : 0);
