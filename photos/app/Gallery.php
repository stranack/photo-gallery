<?php
declare(strict_types=1);

/**
 * Album names: "Album 1 Vehicles" or "01 Vehicles" shows as "Vehicles",
 * and the number sets the order. Names without a number sort after numbered
 * ones, alphabetically.
 * @return array{name:string,order:int}
 */
function album_label(string $folder): array
{
    if (preg_match('/^(?:album\s*)?(\d+)\s*[-._:)]?\s*(.+)$/iu', $folder, $m) && trim($m[2]) !== '') {
        return ['name' => trim($m[2]), 'order' => (int) $m[1]];
    }
    return ['name' => $folder, 'order' => PHP_INT_MAX];
}

function slugify(string $s): string
{
    $t = function_exists('iconv') ? (string) @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) : $s;
    $t = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $t), '-'));
    return $t !== '' ? $t : substr(sha1($s), 0, 8);
}

/**
 * Read the date and place from a file name made by prefix_photo_dates.py:
 *   2018-09-15_San-Francisco_United-States_DSC_0029.JPG
 *   2026-11-06_DSC_4403.jpeg
 *   1991_14.jpg
 * @return array{date:?string,date_label:string,place:string}
 */
function parse_photo_name(string $file): array
{
    $stem = pathinfo($file, PATHINFO_FILENAME);
    $date = null;
    $label = '';
    $rest = $stem;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:_|$)/', $stem, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        $date = "$m[1]-$m[2]-$m[3]";
        $label = (int) $m[3] . ' ' . month_name((int) $m[2]) . ' ' . $m[1];
        $rest = (string) substr($stem, strlen($m[0]));
    } elseif (preg_match('/^((?:19|20)\d{2})(?:_|$)/', $stem, $m)) {
        $date = $m[1];
        $label = $m[1];
        $rest = (string) substr($stem, strlen($m[0]));
    }
    $place = [];
    if ($date !== null) {
        foreach (explode('_', $rest) as $part) {
            if (!is_place_part($part)) {
                break;
            }
            $place[] = str_replace('-', ' ', $part);
        }
    }
    return ['date' => $date, 'date_label' => $label, 'place' => implode(', ', $place)];
}

/**
 * Place names are words (letters, hyphens, apostrophes, dots). Camera names
 * such as DSC, IMG, PXL or anything with a digit end the place.
 */
function is_place_part(string $p): bool
{
    if ($p === '' || preg_match('/\d/', $p)) {
        return false;
    }
    if (!preg_match("/^\p{Lu}[\p{L}\p{M}'’.\-]*$/u", $p)) {
        return false;
    }
    if (preg_match('/^(DSC|DSCN|DSCF|IMG|PXL|MVIMG|GOPR|DJI|SAM|PANO|SCAN|VID|MOV|DCIM|P)$/', $p)) {
        return false;
    }
    return true;
}

function month_name(int $m): string
{
    return ['', 'January', 'February', 'March', 'April', 'May', 'June', 'July',
        'August', 'September', 'October', 'November', 'December'][$m] ?? '';
}

function auto_caption(string $file): string
{
    $p = parse_photo_name($file);
    return implode(' · ', array_filter([$p['date_label'], $p['place']], fn($x) => $x !== ''));
}

function photo_id(string $key): string
{
    return substr(sha1($key), 0, 16);
}

function image_file(string $id, string $size): string
{
    return IMAGE_DIR . '/' . $id . ($size === 'large' ? '-l' : '-g') . '.jpg';
}

function load_gallery(): array
{
    return read_json(GALLERY_FILE, ['albums' => [], 'cover' => null]);
}

/** Caption overrides: photo id => text ("" means no caption). */
function load_captions(): array
{
    return read_json(CAPTIONS_FILE, []);
}

function save_caption(string $id, ?string $text): void
{
    $fh = fopen(CAPTIONS_FILE . '.lock', 'c');
    if ($fh) {
        flock($fh, LOCK_EX);
    }
    $all = load_captions();
    if ($text === null) {
        unset($all[$id]);
    } else {
        $all[$id] = mb_substr(trim(preg_replace('/\s+/u', ' ', $text) ?? ''), 0, 500);
    }
    write_json(CAPTIONS_FILE, $all);
    if ($fh) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** The data the gallery page hands to its script. */
function gallery_for_page(bool $owner): array
{
    $g = load_gallery();
    $captions = load_captions();
    $albums = [];
    $index = [];
    foreach ($g['albums'] ?? [] as $a) {
        $photos = [];
        foreach ($a['photos'] as $p) {
            $custom = array_key_exists($p['id'], $captions);
            $row = [
                'id'  => $p['id'],
                'w'   => $p['w'],
                'h'   => $p['h'],
                'g'   => 'img.php?id=' . $p['id'] . '&s=g&v=' . $p['v'],
                'l'   => 'img.php?id=' . $p['id'] . '&s=l&v=' . $p['v'],
                'cap' => $custom ? $captions[$p['id']] : $p['auto'],
            ];
            if ($owner) {
                $row['auto'] = $p['auto'];
                $row['custom'] = $custom;
                $row['file'] = $p['file'];
            }
            $photos[] = $row;
            $index[$p['id']] = $row;
        }
        $albums[] = ['slug' => $a['slug'], 'name' => $a['name'], 'photos' => $photos];
    }
    $cover = null;
    if (!empty($g['cover']) && is_file(image_file($g['cover']['id'], 'large'))) {
        $cover = 'img.php?id=' . $g['cover']['id'] . '&s=l&v=' . $g['cover']['v'];
    }
    return ['albums' => $albums, 'cover' => $cover, 'built' => $g['built_at'] ?? null];
}
