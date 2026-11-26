<?php
declare(strict_types=1);

/*
 * Adding albums and photos from the gallery (owner only), so there's no need
 * to open Dropbox. Photos go to Dropbox in pieces, then a short sync brings
 * them onto the site.
 */

/** Album folders in the albums folder, in gallery order, with their shown names. */
function album_folders(AlbumSource $source): array
{
    $out = [];
    foreach ($source->listAlbumFolders() as $folder) {
        $out[] = ['folder' => $folder] + album_label($folder);
    }
    usort($out, fn($a, $b) => ($a['order'] <=> $b['order']) ?: strnatcasecmp($a['name'], $b['name']));
    return $out;
}

/** Tidy a name typed for a new album: no slashes or control characters, single spaces. */
function clean_album_name(string $name): string
{
    $name = (string) preg_replace('/[\/\\\\:*?"<>|\p{Cc}]+/u', ' ', $name);
    $name = trim((string) preg_replace('/\s+/u', ' ', $name), " .");
    return mb_substr($name, 0, 80);
}

/**
 * Create "Album N Name", with N one more than the highest album number, so it
 * comes last. Returns the folder name.
 */
function create_album(AlbumSource $source, string $name, bool $public): string
{
    $name = clean_album_name($name);
    if ($name === '') {
        throw new RuntimeException('Give the album a name.');
    }
    $max = 0;
    foreach (album_folders($source) as $f) {
        if (mb_strtolower($f['name']) === mb_strtolower($name)) {
            throw new RuntimeException("There is already an album called “{$f['name']}”.");
        }
        if ($f['order'] !== PHP_INT_MAX) {
            $max = max($max, $f['order']);
        }
    }
    $folder = $source->createAlbumFolder('Album ' . ($max + 1) . ' ' . $name);
    set_folder_public($folder, $public);
    return $folder;
}

/** Public or private for an album folder, even one with no photos yet. */
function set_folder_public(string $folder, bool $public): void
{
    load_album_settings(); // makes sure the first-time settings exist
    with_album_settings(function (array $all) use ($folder, $public) {
        $all[$folder] = ['public' => $public] + ($all[$folder] ?? []);
        return $all;
    });
}

/**
 * The name a photo gets in Dropbox, following the date-and-place convention
 * of prefix_photo_dates.py: "2026-11-26_Regina_Saskatchewan_Canada_DSC_4410.jpg".
 * The date goes in only as YYYY-MM-DD (or a year), and the place only when
 * every part is a plain place name; anything else stays in the caption.
 * A file that already starts with a date keeps its name.
 */
function upload_name(string $original, string $date, string $location): string
{
    $original = (string) preg_replace('/[\/\\\\:*?"<>|\p{Cc}]+/u', '_', basename($original));
    $original = trim($original) !== '' ? trim($original) : 'photo.jpg';
    if (parse_photo_name($original)['date'] !== null) {
        return $original;
    }
    $date = trim($date);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        if (!preg_match('/^(19|20)\d{2}$/', $date)) {
            return $original;
        }
    }
    $parts = [];
    foreach (array_filter(array_map('trim', explode(',', $location)), fn($p) => $p !== '') as $p) {
        $p = str_replace(' ', '-', (string) preg_replace('/\s+/u', ' ', $p));
        if (!is_place_part($p)) {
            $parts = [];
            break;
        }
        $parts[] = $p;
    }
    // A camera name such as DSC_4410 ends the place when the name is read back.
    $rest = $original;
    if ($parts && is_place_part((string) explode('_', pathinfo($rest, PATHINFO_FILENAME))[0])) {
        $rest = 'photo_' . $rest;
    }
    return implode('_', array_merge([$date], $parts, [$rest]));
}

/** Largest piece the browser should send in one request, under PHP's post_max_size. */
function upload_chunk_bytes(): int
{
    $v = trim((string) ini_get('post_max_size'));
    $n = (int) $v;
    $unit = strtolower(substr($v, -1));
    $n *= ['g' => 1 << 30, 'm' => 1 << 20, 'k' => 1 << 10][$unit] ?? 1;
    $limit = $n > 0 ? $n - 64 * 1024 : 8 << 20;
    return max(256 * 1024, min(4 << 20, $limit));
}
