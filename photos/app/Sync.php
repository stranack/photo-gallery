<?php
declare(strict_types=1);

/**
 * Brings the site up to date with the albums folder: lists every image,
 * fetches web-sized copies of new or changed ones, removes copies of deleted
 * ones and rebuilds the gallery index. Work is split into time-limited steps,
 * so a first sync of many photos carries on over several runs.
 */
final class Sync
{
    /** @var callable|null */
    private $say;

    public function __construct(private AlbumSource $source, ?callable $say = null)
    {
        $this->say = $say;
    }

    /** @return array{status:string,total:int,done:int,remaining:int,failed:int,fetched:int,removed:int,error?:string} */
    public function run(float $budgetSeconds, bool $force = false): array
    {
        $start = microtime(true);
        ensure_data_dir();
        $lock = fopen(DATA_DIR . '/sync.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['status' => 'busy', 'total' => 0, 'done' => 0, 'remaining' => 0, 'failed' => 0, 'fetched' => 0, 'removed' => 0];
        }
        try {
            return $this->work($start, $budgetSeconds, $force);
        } catch (Throwable $e) {
            log_line('Sync error: ' . $e->getMessage());
            $state = read_json(STATE_FILE, []);
            $state['last_error'] = ['at' => time(), 'message' => $e->getMessage()];
            write_json(STATE_FILE, $state);
            return ['status' => 'error', 'error' => $e->getMessage(), 'total' => 0, 'done' => 0, 'remaining' => 0, 'failed' => 0, 'fetched' => 0, 'removed' => 0];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function work(float $start, float $budget, bool $force): array
    {
        $state = read_json(STATE_FILE, []);
        $manifest = $state['images'] ?? [];
        if ($force) {
            foreach ($manifest as &$m) {
                $m['tries'] = 0;
            }
            unset($m);
        }

        $listing = $this->source->listImages();
        $this->say(count($listing) . ' images in ' . $this->source->describe());

        // Forget photos that are gone from the albums folder.
        $present = [];
        foreach ($listing as $img) {
            $present[$img['key']] = true;
        }
        $removed = 0;
        foreach (array_keys($manifest) as $key) {
            if (!isset($present[$key])) {
                $id = $manifest[$key]['id'];
                @unlink(image_file($id, 'grid'));
                @unlink(image_file($id, 'large'));
                unset($manifest[$key]);
                $removed++;
            }
        }

        // Work out what needs fetching.
        $todo = [];
        foreach ($listing as $img) {
            $key = $img['key'];
            $id = photo_id($key);
            $m = $manifest[$key] ?? null;
            $current = $m && $m['version'] === $img['version'] && $m['status'] === 'ok'
                && is_file(image_file($id, 'grid')) && is_file(image_file($id, 'large'));
            if ($m) {
                $manifest[$key]['path'] = $img['path']; // follows renames and moves
            }
            if ($current) {
                continue;
            }
            if ($m && $m['version'] === $img['version'] && $m['status'] === 'failed' && ($m['tries'] ?? 0) >= 3) {
                continue; // gave up on this one; Full refresh tries again
            }
            $todo[$img['path']] = $img;
        }

        $fetched = 0;
        $batches = array_chunk($todo, $this->source->batchSize(), true);
        foreach ($batches as $batch) {
            if (microtime(true) - $start > $budget) {
                break;
            }
            $got = $this->source->fetchImages(array_keys($batch));
            foreach ($batch as $path => $img) {
                $key = $img['key'];
                $id = photo_id($key);
                $r = $got[$path] ?? ['grid' => null, 'large' => null];
                $size = $r['large'] ? @getimagesizefromstring($r['large']) : false;
                if ($r['grid'] && $size) {
                    file_put_contents(image_file($id, 'grid'), $r['grid'], LOCK_EX);
                    file_put_contents(image_file($id, 'large'), $r['large'], LOCK_EX);
                    $manifest[$key] = [
                        'id' => $id, 'path' => $path, 'version' => $img['version'], 'status' => 'ok',
                        'w' => (int) $size[0], 'h' => (int) $size[1], 'v' => substr(sha1($img['version']), 0, 8),
                    ];
                    unset($todo[$path]);
                    $fetched++;
                } else {
                    $tries = (($manifest[$key]['version'] ?? '') === $img['version']) ? (int) ($manifest[$key]['tries'] ?? 0) + 1 : 1;
                    $manifest[$key] = ['id' => $id, 'path' => $path, 'version' => $img['version'], 'status' => 'failed', 'tries' => $tries];
                    if ($tries >= 3) {
                        unset($todo[$path]);
                        log_line("Could not get a preview of $path; skipped after 3 tries.");
                    }
                }
            }
            $state['images'] = $manifest;
            write_json(STATE_FILE, $state);
            $this->buildGallery($listing, $manifest);
            $this->say("Fetched $fetched, " . count($todo) . ' to go');
        }

        $failed = count(array_filter($manifest, fn($m) => $m['status'] === 'failed'));
        $remaining = count($todo);
        $state['images'] = $manifest;
        $state['last_sync'] = time();
        $state['last_error'] = null;
        $state['complete'] = $remaining === 0;
        write_json(STATE_FILE, $state);
        $this->buildGallery($listing, $manifest);

        if ($fetched || $removed) {
            log_line("Sync: $fetched fetched, $removed removed, $remaining still to fetch, $failed failed.");
        }
        $done = count(array_filter($manifest, fn($m) => $m['status'] === 'ok'));
        return [
            'status' => $remaining === 0 ? 'done' : 'partial',
            'total' => count($listing), 'done' => $done, 'remaining' => $remaining,
            'failed' => $failed, 'fetched' => $fetched, 'removed' => $removed,
        ];
    }

    /** Group the photos the site has copies of into albums, and pick the cover. */
    private function buildGallery(array $listing, array $manifest): void
    {
        $albums = [];
        $loose = [];
        $coverName = strtolower(trim((string) cfg('cover_photo', '')));
        $coverPick = null;
        foreach ($listing as $img) {
            $m = $manifest[$img['key']] ?? null;
            if (!$m || $m['status'] !== 'ok') {
                continue;
            }
            $file = basename($img['path']);
            $photo = [
                'id' => $m['id'], 'file' => $file, 'path' => $img['path'],
                'w' => $m['w'], 'h' => $m['h'], 'v' => $m['v'], 'auto' => auto_caption($file),
            ];
            if ($coverName !== '' && strtolower($file) === $coverName) {
                $coverPick = $photo;
            }
            $parts = explode('/', $img['path']);
            if (count($parts) === 1) {
                $loose[] = $photo;
                continue;
            }
            $folder = $parts[0];
            $albums[$folder][] = $photo;
        }

        $out = [];
        $slugs = [];
        foreach ($albums as $folder => $photos) {
            usort($photos, fn($a, $b) => strnatcasecmp($a['path'], $b['path']));
            $label = album_label((string) $folder);
            $slug = slugify($label['name']);
            while (isset($slugs[$slug])) {
                $slug .= '-2';
            }
            $slugs[$slug] = true;
            $out[] = [
                'slug' => $slug, 'name' => $label['name'], 'order' => $label['order'], 'folder' => (string) $folder,
                'photos' => array_map(fn($p) => array_diff_key($p, ['path' => 1]), $photos),
            ];
        }
        usort($out, fn($a, $b) => ($a['order'] <=> $b['order']) ?: strnatcasecmp($a['name'], $b['name']));

        usort($loose, fn($a, $b) => strnatcasecmp($a['file'], $b['file']));
        $cover = $coverPick ?? ($loose[0] ?? ($out[0]['photos'][0] ?? null));

        write_json(GALLERY_FILE, [
            'built_at' => time(),
            'albums' => $out,
            'cover' => $cover ? ['id' => $cover['id'], 'v' => $cover['v']] : null,
        ]);
    }

    private function say(string $msg): void
    {
        if ($this->say) {
            ($this->say)($msg);
        }
    }
}
