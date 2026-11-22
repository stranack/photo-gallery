<?php
/*
 * Adds titles, descriptions, tags and the like to photos already in the
 * gallery, from a JSON file (for example one made from a Flickr export).
 *
 *   php app/import.php captions.json [--overwrite] [--dry-run]
 *
 * The file is a list of entries:
 *   [{"file": "2021-01-15_Southbank_Canada_Parked.jpg", "album": "Rural Saskatchewan",
 *     "title": "Parked", "description": "...", "tags": "Rural, Southbank"}]
 * "album" is only needed if the same file name is in several albums. Tags are
 * added to the ones a photo already has; other fields you've typed by hand are
 * kept unless you pass --overwrite. Run a sync first so new photos are listed.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/bootstrap.php';

$args = array_slice($argv, 1);
$overwrite = in_array('--overwrite', $args, true);
$dry = in_array('--dry-run', $args, true);
$files = array_values(array_filter($args, fn($a) => !str_starts_with($a, '--')));
if (count($files) !== 1 || !is_file($files[0])) {
    fwrite(STDERR, "Usage: php app/import.php captions.json [--overwrite] [--dry-run]\n");
    exit(2);
}
$entries = json_decode((string) file_get_contents($files[0]), true);
if (!is_array($entries)) {
    fwrite(STDERR, "That file isn't a JSON list of entries.\n");
    exit(2);
}
if ($dry) {
    $backup = CAPTIONS_FILE . '.dry-run-backup';
    $had = is_file(CAPTIONS_FILE);
    if ($had) {
        copy(CAPTIONS_FILE, $backup);
    }
}
$r = import_captions($entries, $overwrite);
if ($dry) {
    if ($had) {
        rename($backup, CAPTIONS_FILE);
    } else {
        @unlink(CAPTIONS_FILE);
    }
}
echo ($dry ? '[dry run] ' : '') . "Updated {$r['updated']}, unchanged {$r['unchanged']}, not found " . count($r['unmatched']) . ', ambiguous ' . count($r['ambiguous']) . ".\n";
foreach (['unmatched' => 'Not found', 'ambiguous' => 'Same name in several albums (add "album")'] as $k => $label) {
    if ($r[$k]) {
        echo "$label:\n  " . implode("\n  ", array_slice($r[$k], 0, 50)) . (count($r[$k]) > 50 ? "\n  …" : '') . "\n";
    }
}
