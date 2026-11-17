<?php
declare(strict_types=1);

/**
 * Moving a photo to the Trash folder from the gallery page (owner only).
 * The original moves out of the albums folder in Dropbox, so the next sync
 * would drop it anyway; this also takes it off the site straight away.
 */

/** The Trash folder, in Dropbox or, with a local albums folder, on this server. */
function trash_path(): string
{
    $local = rtrim((string) cfg('local_albums_path', ''), '/');
    if ($local !== '') {
        return rtrim((string) (cfg('local_trash_path', '') ?: dirname($local) . '/Trash'), '/');
    }
    $p = trim((string) cfg('dropbox_trash_path', ''));
    if ($p === '') {
        $albums = '/' . trim((string) cfg('dropbox_albums_path', '/Photographs/Albums'), '/');
        $p = rtrim(dirname($albums), '/') . '/Trash';
    }
    return '/' . trim($p, '/');
}

/**
 * Move one photo to the Trash folder and take it out of the gallery.
 * @return array{to:string} where the original went
 */
function trash_photo(string $id, AlbumSource $source): array
{
    ensure_data_dir();
    // Wait for any sync that is running, so it can't put the photo back.
    $lock = fopen(DATA_DIR . '/sync.lock', 'c');
    $locked = false;
    for ($i = 0; $lock && $i < 40; $i++) {
        if (flock($lock, LOCK_EX | LOCK_NB)) {
            $locked = true;
            break;
        }
        usleep(250000);
    }
    if (!$locked) {
        if ($lock) {
            fclose($lock);
        }
        throw new RuntimeException('A sync is running. Try again in a minute.');
    }
    try {
        $state = read_json(STATE_FILE, []);
        $key = null;
        foreach ($state['images'] ?? [] as $k => $m) {
            if (($m['id'] ?? '') === $id) {
                $key = (string) $k;
                break;
            }
        }
        if ($key === null) {
            throw new RuntimeException('That photo is no longer in the gallery.');
        }
        $path = (string) $state['images'][$key]['path'];
        $to = $source->trashImage($key, $path);

        // Off the site: the cached copies, the index entry and any public link.
        // Captions stay, so a photo moved back from the Trash gets its caption back.
        unset($state['images'][$key]);
        write_json(STATE_FILE, $state);
        @unlink(image_file($id, 'grid'));
        @unlink(image_file($id, 'large'));
        remove_from_gallery($id);
        share_remove($id);
        log_line("Moved $path to the Trash ($to).");
        return ['to' => $to];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Drop a photo from gallery.json, and its album if that leaves it empty. */
function remove_from_gallery(string $id): void
{
    $g = load_gallery();
    $albums = [];
    foreach ($g['albums'] ?? [] as $a) {
        $a['photos'] = array_values(array_filter($a['photos'], fn($p) => $p['id'] !== $id));
        if ($a['photos']) {
            $albums[] = $a;
        }
    }
    $g['albums'] = $albums;
    if (($g['cover']['id'] ?? null) === $id) {
        $first = $albums[0]['photos'][0] ?? null;
        $g['cover'] = $first ? ['id' => $first['id'], 'v' => $first['v']] : null;
    }
    write_json(GALLERY_FILE, $g);
}

/** A free name in $dir for $file: "name.jpg", else "name (1).jpg" and so on. */
function free_local_name(string $dir, string $file): string
{
    $stem = pathinfo($file, PATHINFO_FILENAME);
    $ext = pathinfo($file, PATHINFO_EXTENSION);
    $name = $file;
    for ($n = 1; file_exists($dir . '/' . $name); $n++) {
        $name = $stem . " ($n)" . ($ext !== '' ? ".$ext" : '');
    }
    return $name;
}
