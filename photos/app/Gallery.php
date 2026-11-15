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

const CAPTION_FIELDS = ['title', 'date', 'location', 'description'];

/** Date and location read from the file name; title and description start empty. */
function auto_fields(string $file): array
{
    $p = parse_photo_name($file);
    return ['title' => '', 'date' => $p['date_label'], 'location' => $p['place'], 'description' => ''];
}

/**
 * Caption details you've written: photo id => fields you set. A field that's
 * missing follows the automatic value; one set to "" is deliberately blank.
 * Captions saved by the earlier single-line editor become the description,
 * with date and location left blank, since they replaced the whole caption.
 */
function load_captions(): array
{
    $out = [];
    foreach (read_json(CAPTIONS_FILE, []) as $id => $v) {
        if (is_string($v)) {
            $out[$id] = ['date' => '', 'location' => '', 'description' => $v];
        } elseif (is_array($v)) {
            $out[$id] = array_intersect_key($v, array_flip(CAPTION_FIELDS));
        }
    }
    return $out;
}

/** The caption a visitor sees: your fields over the automatic ones. */
function caption_fields(string $id, string $file, ?array $captions = null): array
{
    $captions ??= load_captions();
    return array_merge(auto_fields($file), $captions[$id] ?? []);
}

/**
 * Save the fields from the editor. Date and location are kept only where they
 * differ from the automatic value, so a renamed file still updates them.
 * Pass null to go back to automatic for everything.
 */
function save_caption(string $id, ?array $fields, string $file): void
{
    $fh = fopen(CAPTIONS_FILE . '.lock', 'c');
    if ($fh) {
        flock($fh, LOCK_EX);
    }
    $all = load_captions();
    if ($fields === null) {
        unset($all[$id]);
    } else {
        $auto = auto_fields($file);
        $keep = [];
        foreach (CAPTION_FIELDS as $f) {
            $max = $f === 'description' ? 2000 : 200;
            $v = (string) ($fields[$f] ?? '');
            $v = $f === 'description'
                ? trim(preg_replace("/[ \t]+/u", ' ', str_replace("\r", '', $v)) ?? '')
                : trim(preg_replace('/\s+/u', ' ', $v) ?? '');
            $v = mb_substr($v, 0, $max);
            if ($v !== $auto[$f]) {
                $keep[$f] = $v;
            }
        }
        if ($keep) {
            $all[$id] = $keep;
        } else {
            unset($all[$id]);
        }
    }
    write_json(CAPTIONS_FILE, $all);
    if ($fh) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/** Find a photo's file name in the gallery index. */
function photo_file(string $id): ?string
{
    foreach (load_gallery()['albums'] ?? [] as $a) {
        foreach ($a['photos'] as $p) {
            if ($p['id'] === $id) {
                return $p['file'];
            }
        }
    }
    return null;
}

/** The data the gallery page hands to its script. */
function gallery_for_page(bool $owner): array
{
    $g = load_gallery();
    $captions = load_captions();
    $albums = [];
    foreach ($g['albums'] ?? [] as $a) {
        $photos = [];
        foreach ($a['photos'] as $p) {
            $row = [
                'id'  => $p['id'],
                'w'   => $p['w'],
                'h'   => $p['h'],
                'g'   => 'img.php?id=' . $p['id'] . '&s=g&v=' . $p['v'],
                'l'   => 'img.php?id=' . $p['id'] . '&s=l&v=' . $p['v'],
                'cap' => caption_fields($p['id'], $p['file'], $captions),
            ];
            if ($owner) {
                $row['auto'] = auto_fields($p['file']);
                $row['custom'] = isset($captions[$p['id']]);
                $row['file'] = $p['file'];
            }
            $photos[] = $row;
        }
        $albums[] = ['slug' => $a['slug'], 'name' => $a['name'], 'photos' => $photos];
    }
    $cover = null;
    if (!empty($g['cover']) && is_file(image_file($g['cover']['id'], 'large'))) {
        $cover = 'img.php?id=' . $g['cover']['id'] . '&s=l&v=' . $g['cover']['v'];
    }
    return ['albums' => $albums, 'cover' => $cover, 'built' => $g['built_at'] ?? null];
}
