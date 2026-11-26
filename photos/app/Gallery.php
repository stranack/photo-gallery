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

// place_id is the GeoNames id of the location, when it was picked from the suggestions.
const CAPTION_FIELDS = ['title', 'date', 'location', 'description', 'tags', 'place_id'];

/**
 * Tidy a list of tags typed or imported as text: split on commas, semicolons
 * and new lines, trim, drop repeats (ignoring case) and keep up to 40 tags of
 * up to 40 characters. Returns them as one "a, b, c" string.
 */
function normalize_tags(string $text): string
{
    $out = [];
    foreach (preg_split('/[,;\n]+/u', $text) ?: [] as $t) {
        $t = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $t)), 0, 40);
        if ($t !== '' && !isset($out[mb_strtolower($t)])) {
            $out[mb_strtolower($t)] = $t;
        }
        if (count($out) >= 40) {
            break;
        }
    }
    return implode(', ', $out);
}

/** Date and location read from the file name; title, description and tags start empty. */
function auto_fields(string $file): array
{
    $p = parse_photo_name($file);
    return ['title' => '', 'date' => $p['date_label'], 'location' => $p['place'], 'description' => '', 'tags' => '', 'place_id' => ''];
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
            $max = $f === 'description' ? 2000 : ($f === 'tags' ? 1700 : 200);
            $v = (string) ($fields[$f] ?? '');
            if ($f === 'tags') {
                $v = normalize_tags($v);
            } elseif ($f === 'place_id') {
                $v = preg_match('/^\d{1,12}$/', trim($v)) ? trim($v) : '';
            } else {
                $v = $f === 'description'
                    ? trim(preg_replace("/[ \t]+/u", ' ', str_replace("\r", '', $v)) ?? '')
                    : trim(preg_replace('/\s+/u', ' ', $v) ?? '');
            }
            $v = mb_substr($v, 0, $max);
            if ($f === 'place_id' && trim((string) ($keep['location'] ?? $auto['location'])) === '') {
                $v = ''; // no location, so no place to point to
            }
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

/**
 * Add titles, descriptions, tags and the like from a list of entries, each
 * {file, album?, title?, description?, tags?, location?, date?}. A photo is
 * found by its file name (and album name or slug, if given, when the same name
 * is in several albums). Fields already set by hand are kept unless
 * $overwrite is true. Tags are added to the ones already there.
 * @return array{updated:int,unchanged:int,unmatched:string[],ambiguous:string[]}
 */
function import_captions(array $entries, bool $overwrite = false): array
{
    $byFile = [];
    foreach (load_gallery()['albums'] ?? [] as $a) {
        foreach ($a['photos'] as $p) {
            $byFile[mb_strtolower($p['file'])][] = ['id' => $p['id'], 'file' => $p['file'], 'album' => [mb_strtolower($a['name']), mb_strtolower($a['slug'])]];
        }
    }
    $captions = load_captions();
    $res = ['updated' => 0, 'unchanged' => 0, 'unmatched' => [], 'ambiguous' => []];
    foreach ($entries as $e) {
        $name = (string) ($e['file'] ?? '');
        $hits = $byFile[mb_strtolower($name)] ?? [];
        if (!empty($e['album'])) {
            $want = mb_strtolower((string) $e['album']);
            $hits = array_values(array_filter($hits, fn($h) => in_array($want, $h['album'], true)));
        }
        if (!$hits) {
            $res['unmatched'][] = $name;
            continue;
        }
        if (count($hits) > 1) {
            $res['ambiguous'][] = $name;
            continue;
        }
        $h = $hits[0];
        $cur = caption_fields($h['id'], $h['file'], $captions);
        $fields = array_intersect_key($cur, array_flip(CAPTION_FIELDS));
        $auto = auto_fields($h['file']);
        $changed = false;
        foreach (CAPTION_FIELDS as $f) {
            if (!isset($e[$f]) || trim((string) $e[$f]) === '') {
                continue;
            }
            $new = (string) $e[$f];
            if ($f === 'tags') {
                $new = normalize_tags($cur['tags'] . ', ' . $new);
            } elseif (!$overwrite && $fields[$f] !== '' && $fields[$f] !== $auto[$f]) {
                continue; // typed by hand; leave it
            }
            if ($new !== $fields[$f]) {
                $fields[$f] = $new;
                $changed = true;
            }
        }
        // Exports such as Flickr's often repeat the place as the description.
        // A place-only description goes into the location instead.
        if (same_place($fields['description'], $fields['location'])) {
            $fields['description'] = '';
            $changed = $changed || $cur['description'] !== '';
        } elseif ($fields['description'] !== '' && looks_like_place($fields['description'])
            && ($fields['location'] === '' || place_key($fields['description']) !== place_key($fields['location']) && str_contains(place_key($fields['description']), place_key($fields['location'])))) {
            $fields['location'] = $fields['description'];
            $fields['description'] = '';
            $changed = true;
        }
        if ($changed) {
            save_caption($h['id'], $fields, $h['file']);
            $captions = load_captions();
            $res['updated']++;
        } else {
            $res['unchanged']++;
        }
    }
    return $res;
}

/** Text reduced to lower-case words, for comparing places. */
function place_key(string $s): string
{
    return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($s)));
}

/** True when a description says no more than the location does. */
function same_place(string $description, string $location): bool
{
    return $description !== '' && $location !== '' && place_key($description) === place_key($location);
}

/** A short "Town, Province, Country" line with no sentence punctuation. */
function looks_like_place(string $s): bool
{
    return mb_strlen($s) < 80 && str_contains($s, ',') && !preg_match('/[.!?;:\n]/u', $s);
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

// ---- Public and private albums ------------------------------------------
// A public album can be seen by anyone, without the gallery password. A
// private one needs the password. The owner switches each album with the
// globe/lock button above its photos. Settings are kept in data/albums.json,
// keyed by the album's folder name in Dropbox. An album with no setting yet
// (a new folder, or a renamed one) is private until the owner makes it public.

/** @return array<string, array{public:bool}> folder name => setting */
function load_album_settings(): array
{
    if (!is_file(ALBUMS_FILE)) {
        return seed_album_settings();
    }
    return read_json(ALBUMS_FILE, []);
}

/**
 * The first time this runs (when public albums were added, November 2026),
 * every album then in the gallery is made public except the Tabbernor album.
 */
function seed_album_settings(): array
{
    $albums = load_gallery()['albums'] ?? [];
    if (!$albums) {
        return [];
    }
    return with_album_settings(function (array $all) use ($albums) {
        foreach ($albums as $a) {
            $folder = (string) ($a['folder'] ?? $a['name']);
            if (!isset($all[$folder])) {
                $all[$folder] = ['public' => !preg_match('/tabbernor/i', $folder)];
            }
        }
        return $all;
    });
}

function with_album_settings(callable $change): array
{
    ensure_data_dir();
    $fh = fopen(ALBUMS_FILE . '.lock', 'c');
    if ($fh) {
        flock($fh, LOCK_EX);
    }
    $all = $change(read_json(ALBUMS_FILE, []));
    write_json(ALBUMS_FILE, $all);
    if ($fh) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    return $all;
}

function album_is_public(array $album, ?array $settings = null): bool
{
    $settings ??= load_album_settings();
    return !empty($settings[(string) ($album['folder'] ?? $album['name'])]['public']);
}

/** Make an album (found by its slug) public or private. Returns false if there's no such album. */
function set_album_public(string $slug, bool $public): bool
{
    load_album_settings(); // makes sure the first-time settings exist
    foreach (load_gallery()['albums'] ?? [] as $a) {
        if ($a['slug'] === $slug) {
            $folder = (string) ($a['folder'] ?? $a['name']);
            with_album_settings(function (array $all) use ($folder, $public) {
                $all[$folder] = ['public' => $public] + ($all[$folder] ?? []);
                return $all;
            });
            return true;
        }
    }
    return false;
}

/**
 * Make a photo the cover of the album it's in, for the Albums page.
 * Returns that album's slug, or null if the photo isn't in the gallery.
 */
function set_album_cover(string $photoId): ?string
{
    load_album_settings(); // makes sure the first-time settings exist
    foreach (load_gallery()['albums'] ?? [] as $a) {
        foreach ($a['photos'] as $p) {
            if ($p['id'] === $photoId) {
                $folder = (string) ($a['folder'] ?? $a['name']);
                with_album_settings(function (array $all) use ($folder, $photoId) {
                    $all[$folder] = ($all[$folder] ?? ['public' => false]);
                    $all[$folder]['cover'] = $photoId;
                    return $all;
                });
                return $a['slug'];
            }
        }
    }
    return null;
}

/** The photo shown for an album on the Albums page: the chosen cover, else its first photo. */
function album_cover_id(array $album, array $settings): ?string
{
    $want = $settings[(string) ($album['folder'] ?? $album['name'])]['cover'] ?? null;
    foreach ($album['photos'] as $p) {
        if ($p['id'] === $want) {
            return $want;
        }
    }
    return $album['photos'][0]['id'] ?? null;
}

/**
 * The cover someone without the password may see: the chosen cover if it's a
 * loose image in the albums folder or sits in a public album, otherwise the
 * first photo of the first public album. Null if no album is public.
 */
function public_cover(?array $g = null, ?array $settings = null): ?array
{
    $g ??= load_gallery();
    $settings ??= load_album_settings();
    $public = array_values(array_filter($g['albums'] ?? [], fn($a) => album_is_public($a, $settings)));
    if (!$public) {
        return null;
    }
    $cover = $g['cover'] ?? null;
    if ($cover) {
        $inPrivate = false;
        foreach ($g['albums'] ?? [] as $a) {
            foreach ($a['photos'] as $p) {
                if ($p['id'] === $cover['id']) {
                    if (album_is_public($a, $settings)) {
                        return $cover;
                    }
                    $inPrivate = true;
                }
            }
        }
        if (!$inPrivate) {
            return $cover; // a loose image in the albums folder, kept for the cover
        }
    }
    $first = $public[0]['photos'][0] ?? null;
    return $first ? ['id' => $first['id'], 'v' => $first['v']] : null;
}

/** Can someone without the password see this photo? */
function photo_is_public(string $id): bool
{
    $g = load_gallery();
    $settings = load_album_settings();
    foreach ($g['albums'] ?? [] as $a) {
        if (!album_is_public($a, $settings)) {
            continue;
        }
        foreach ($a['photos'] as $p) {
            if ($p['id'] === $id) {
                return true;
            }
        }
    }
    return (public_cover($g, $settings)['id'] ?? null) === $id;
}

function any_public_album(): bool
{
    $settings = load_album_settings();
    foreach (load_gallery()['albums'] ?? [] as $a) {
        if (album_is_public($a, $settings)) {
            return true;
        }
    }
    return false;
}

/**
 * The data the gallery page hands to its script. Visitors with the password
 * (and the owner) get every album; everyone else gets only the public ones.
 */
function gallery_for_page(bool $owner, bool $visitor = true): array
{
    $g = load_gallery();
    $captions = load_captions();
    $shares = load_shares();
    $settings = load_album_settings();
    $albums = [];
    foreach ($g['albums'] ?? [] as $a) {
        $isPublic = album_is_public($a, $settings);
        if (!$visitor && !$isPublic) {
            continue;
        }
        $photos = [];
        foreach ($a['photos'] as $p) {
            $row = [
                'id'  => $p['id'],
                'w'   => $p['w'],
                'h'   => $p['h'],
                'g'   => 'img.php?id=' . $p['id'] . '&s=g&v=' . $p['v'],
                'l'   => 'img.php?id=' . $p['id'] . '&s=l&v=' . $p['v'],
                'cap' => caption_fields($p['id'], $p['file'], $captions),
                'sh'  => $shares[$p['id']]['t'] ?? null,
            ];
            if ($owner) {
                $row['auto'] = auto_fields($p['file']);
                $row['custom'] = isset($captions[$p['id']]);
                $row['file'] = $p['file'];
            }
            $photos[] = $row;
        }
        $albums[] = ['slug' => $a['slug'], 'name' => $a['name'], 'public' => $isPublic,
            'cover' => album_cover_id($a, $settings), 'photos' => $photos];
    }
    $coverRow = $visitor ? ($g['cover'] ?? null) : public_cover($g, $settings);
    $cover = null;
    if (!empty($coverRow) && is_file(image_file($coverRow['id'], 'large'))) {
        $cover = 'img.php?id=' . $coverRow['id'] . '&s=l&v=' . $coverRow['v'];
    }
    return ['albums' => $albums, 'cover' => $cover, 'built' => $g['built_at'] ?? null];
}

// ---- Public share links ---------------------------------------------------
// A shared photo gets an unguessable token; share.php?s=TOKEN shows that one
// photo to anyone, while the rest of the gallery stays behind the password.

/** @return array<string, array{t:string,at:int}> photo id => share */
function load_shares(): array
{
    return read_json(SHARES_FILE, []);
}

function with_shares(callable $change): array
{
    ensure_data_dir();
    $fh = fopen(SHARES_FILE . '.lock', 'c');
    if ($fh) {
        flock($fh, LOCK_EX);
    }
    $all = load_shares();
    $all = $change($all);
    write_json(SHARES_FILE, $all);
    if ($fh) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
    return $all;
}

/** The photo's share token, made on first use. */
function share_create(string $id): string
{
    $all = with_shares(function (array $all) use ($id) {
        if (empty($all[$id]['t'])) {
            $all[$id] = ['t' => bin2hex(random_bytes(12)), 'at' => time()];
        }
        return $all;
    });
    return $all[$id]['t'];
}

function share_remove(string $id): void
{
    with_shares(function (array $all) use ($id) {
        unset($all[$id]);
        return $all;
    });
}

/** Photo id for a share token, or null if it isn't (or is no longer) shared. */
function share_photo(string $token): ?string
{
    if (!preg_match('/^[0-9a-f]{24}$/', $token)) {
        return null;
    }
    foreach (load_shares() as $id => $s) {
        if (hash_equals($s['t'], $token)) {
            return (string) $id;
        }
    }
    return null;
}

/** A photo's row in the gallery index (with its version), or null. */
function find_photo(string $id): ?array
{
    foreach (load_gallery()['albums'] ?? [] as $a) {
        foreach ($a['photos'] as $p) {
            if ($p['id'] === $id) {
                return $p + ['album' => $a['name']];
            }
        }
    }
    return null;
}

/** Token the share panel sends back, so another site can't create links. */
function share_csrf(): string
{
    if (is_owner()) {
        return hash_hmac('sha256', 'share-csrf', owner_token());
    }
    if (is_visitor()) {
        return hash_hmac('sha256', 'share-csrf', visitor_token());
    }
    // Someone looking at the public albums only. The key is made from the
    // passwords, so it is never empty and changes along with them.
    return hash_hmac('sha256', 'share-csrf-public', visitor_token() . owner_token());
}

/** A file name for downloads: the title if there is one, else the original name. */
function download_name(string $id, string $file): string
{
    $title = caption_fields($id, $file)['title'];
    $base = $title !== '' ? $title : pathinfo($file, PATHINFO_FILENAME);
    $base = trim((string) preg_replace('/[^\p{L}\p{N} ._()\-]+/u', '', $base));
    return ($base !== '' ? mb_substr($base, 0, 120) : 'photo') . '.jpg';
}

function site_base_url(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir . '/';
}
