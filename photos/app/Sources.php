<?php
declare(strict_types=1);

/** Extensions the gallery shows. Dropbox makes JPEG previews from all of these. */
const IMAGE_EXTS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'tif', 'tiff', 'bmp', 'heic', 'heif'];

/** Grid thumbnails and full-screen versions, as Dropbox thumbnail sizes. */
const SIZE_GRID  = ['w960h640', 960, 640];
const SIZE_LARGE = ['w2048h1536', 2048, 1536];

/**
 * Where the albums come from. Paths are relative to the albums folder,
 * e.g. "Album 1 Vehicles/2026-11-06_DSC_4403.jpeg".
 */
interface AlbumSource
{
    /**
     * Every image under the albums folder.
     * @return array<int, array{key:string,path:string,version:string}>
     */
    public function listImages(): array;

    /**
     * JPEG versions of a few images, in both sizes.
     * @param string[] $paths
     * @return array<string, array{grid:?string,large:?string}>
     */
    public function fetchImages(array $paths): array;

    /** How many images fetchImages() should be given at a time. */
    public function batchSize(): int;

    /**
     * Move one image out of the albums folder into the Trash folder, keeping
     * its album folder name. A name already taken there gets a number added.
     * @param string $key  the key listImages() gave it
     * @param string $path its path relative to the albums folder
     * @return string where it went
     */
    public function trashImage(string $key, string $path): string;

    public function describe(): string;
}

function is_image_name(string $name): bool
{
    if ($name === '' || $name[0] === '.') {
        return false;
    }
    return in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), IMAGE_EXTS, true);
}

/** Reads an albums folder on this server. Also used for testing. */
final class LocalSource implements AlbumSource
{
    public function __construct(private string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function describe(): string
    {
        return 'Folder on this server: ' . $this->root;
    }

    public function batchSize(): int
    {
        return 4;
    }

    public function listImages(): array
    {
        if (!is_dir($this->root)) {
            throw new RuntimeException("Albums folder not found: {$this->root}");
        }
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            /** @var SplFileInfo $f */
            if (!$f->isFile() || !is_image_name($f->getFilename())) {
                continue;
            }
            $rel = ltrim(substr(str_replace('\\', '/', $f->getPathname()), strlen($this->root)), '/');
            if (str_contains('/' . $rel, '/.')) {
                continue;
            }
            $out[] = ['key' => 'local:' . $rel, 'path' => $rel, 'version' => $f->getMTime() . '-' . $f->getSize()];
        }
        return $out;
    }

    public function trashImage(string $key, string $path): string
    {
        $from = $this->root . '/' . $path;
        if ($path === '' || str_contains('/' . $path . '/', '/../') || !is_file($from)) {
            throw new RuntimeException('That photo is no longer in the albums folder.');
        }
        $trash = trash_path();
        $dir = $trash . (str_contains($path, '/') ? '/' . dirname($path) : '');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create the Trash folder $dir.");
        }
        $to = $dir . '/' . free_local_name($dir, basename($path));
        if (!@rename($from, $to)) {
            throw new RuntimeException('Could not move the photo to the Trash folder.');
        }
        return $to;
    }

    public function fetchImages(array $paths): array
    {
        $out = [];
        foreach ($paths as $p) {
            $bytes = @file_get_contents($this->root . '/' . $p);
            $out[$p] = [
                'grid'  => $bytes ? resize_to_jpeg($bytes, SIZE_GRID[1], SIZE_GRID[2]) : null,
                'large' => $bytes ? resize_to_jpeg($bytes, SIZE_LARGE[1], SIZE_LARGE[2]) : null,
            ];
        }
        return $out;
    }
}

/** Shrink an image to fit $maxW x $maxH, turned the right way up, as JPEG. */
function resize_to_jpeg(string $bytes, int $maxW, int $maxH): ?string
{
    if (!function_exists('imagecreatefromstring')) {
        throw new RuntimeException('The PHP gd extension is needed for a local albums folder.');
    }
    $src = @imagecreatefromstring($bytes);
    if (!$src) {
        return null;
    }
    if (function_exists('exif_read_data')) {
        $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($angle) {
            $src = imagerotate($src, $angle, 0);
        }
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min($maxW / $w, $maxH / $h, 1);
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    ob_start();
    imagejpeg($dst, null, 84);
    $out = (string) ob_get_clean();
    imagedestroy($src);
    imagedestroy($dst);
    return $out;
}

/** Reads the albums from Dropbox using the HTTP API and a long-lived refresh token. */
final class DropboxSource implements AlbumSource
{
    private string $apiHost;
    private string $contentHost;
    private ?string $accessToken = null;
    private int $accessExpires = 0;

    public function __construct(
        private string $appKey,
        private string $appSecret,
        private string $refreshToken,
        private string $albumsPath,
        private string $cacheFile,
        array $hosts = []
    ) {
        $this->albumsPath = '/' . trim($albumsPath, '/');
        $this->apiHost = $hosts['api'] ?? 'https://api.dropboxapi.com';
        $this->contentHost = $hosts['content'] ?? 'https://content.dropboxapi.com';
    }

    public function describe(): string
    {
        return 'Dropbox: ' . $this->albumsPath;
    }

    public function batchSize(): int
    {
        // Each photo is asked for twice (grid and full screen). Dropbox allows
        // 25 entries per call; the large versions keep responses a few MB.
        return 6;
    }

    public function listImages(): array
    {
        $out = [];
        $rootLen = strlen($this->albumsPath);
        $r = $this->rpc('/2/files/list_folder', [
            'path'      => $this->albumsPath,
            'recursive' => true,
            'limit'     => 2000,
        ]);
        while (true) {
            foreach ($r['entries'] ?? [] as $e) {
                if (($e['.tag'] ?? '') !== 'file' || !is_image_name((string) $e['name'])) {
                    continue;
                }
                $rel = ltrim(substr((string) $e['path_display'], $rootLen), '/');
                if (str_contains('/' . $rel, '/.')) {
                    continue; // hidden folders such as .Final
                }
                $out[] = [
                    'key'     => (string) $e['id'],
                    'path'    => $rel,
                    'version' => (string) ($e['content_hash'] ?? $e['rev']),
                ];
            }
            if (empty($r['has_more'])) {
                break;
            }
            $r = $this->rpc('/2/files/list_folder/continue', ['cursor' => $r['cursor']]);
        }
        return $out;
    }

    public function fetchImages(array $paths): array
    {
        $paths = array_values($paths);
        $entries = [];
        foreach ($paths as $p) {
            foreach ([SIZE_GRID, SIZE_LARGE] as $s) {
                $entries[] = [
                    'path'   => $this->albumsPath . '/' . $p,
                    'format' => 'jpeg',
                    'size'   => $s[0],
                    'mode'   => 'bestfit',
                ];
            }
        }
        $res = $this->rpc('/2/files/get_thumbnail_batch', ['entries' => $entries], $this->contentHost);
        $out = [];
        foreach ($paths as $p) {
            $out[$p] = ['grid' => null, 'large' => null];
        }
        foreach (($res['entries'] ?? []) as $i => $entry) {
            $p = $paths[intdiv($i, 2)] ?? null;
            if ($p === null || ($entry['.tag'] ?? '') !== 'success' || empty($entry['thumbnail'])) {
                continue;
            }
            $bytes = base64_decode($entry['thumbnail'], true);
            $out[$p][$i % 2 === 0 ? 'grid' : 'large'] = $bytes === false ? null : $bytes;
        }
        return $out;
    }

    public function trashImage(string $key, string $path): string
    {
        // The file id follows the photo even if it was renamed since the last sync.
        $from = str_starts_with($key, 'id:') ? $key : $this->albumsPath . '/' . $path;
        if (str_starts_with(strtolower(trash_path() . '/'), strtolower($this->albumsPath . '/'))) {
            throw new RuntimeException('The Trash folder is inside the albums folder. Set dropbox_trash_path in config.php to a folder outside it.');
        }
        try {
            $r = $this->rpc('/2/files/move_v2', [
                'from_path'  => $from,
                'to_path'    => trash_path() . '/' . $path,
                'autorename' => true,
            ]);
        } catch (RuntimeException $e) {
            $m = $e->getMessage();
            if (str_contains($m, 'missing_scope') || str_contains($m, 'files.content.write')) {
                throw new RuntimeException('The Dropbox connection is read-only. Give the Dropbox app the files.content.write permission, then disconnect and reconnect Dropbox on the admin page.');
            }
            if (str_contains($m, 'from_lookup/not_found')) {
                throw new RuntimeException('That photo is no longer in Dropbox. A sync will take it off the site.');
            }
            throw $e;
        }
        return (string) ($r['metadata']['path_display'] ?? trash_path() . '/' . $path);
    }

    /** Account name, used by the admin page to confirm the connection works. */
    public function accountName(): string
    {
        $r = $this->rpc('/2/users/get_current_account', null);
        return (string) ($r['name']['display_name'] ?? $r['email'] ?? 'unknown');
    }

    // ---- OAuth -----------------------------------------------------------

    public static function authorizeUrl(string $appKey): string
    {
        return 'https://www.dropbox.com/oauth2/authorize?' . http_build_query([
            'client_id'         => $appKey,
            'response_type'     => 'code',
            'token_access_type' => 'offline',
        ]);
    }

    /** Swap the one-time code from Dropbox for a refresh token. */
    public static function exchangeCode(string $appKey, string $appSecret, string $code, array $hosts = []): string
    {
        $api = $hosts['api'] ?? 'https://api.dropboxapi.com';
        [$status, $body] = http_post($api . '/oauth2/token', [], http_build_query([
            'code'          => trim($code),
            'grant_type'    => 'authorization_code',
            'client_id'     => $appKey,
            'client_secret' => $appSecret,
        ]), 'application/x-www-form-urlencoded');
        $json = json_decode((string) $body, true);
        if ($status !== 200 || empty($json['refresh_token'])) {
            $msg = $json['error_description'] ?? $json['error'] ?? substr((string) $body, 0, 200);
            throw new RuntimeException("Dropbox rejected the code: $msg");
        }
        return $json['refresh_token'];
    }

    private function token(): string
    {
        if ($this->accessToken && time() < $this->accessExpires - 60) {
            return $this->accessToken;
        }
        if (is_file($this->cacheFile)) {
            $c = json_decode((string) file_get_contents($this->cacheFile), true);
            if (!empty($c['token']) && ($c['expires'] ?? 0) > time() + 60) {
                $this->accessToken = $c['token'];
                $this->accessExpires = (int) $c['expires'];
                return $this->accessToken;
            }
        }
        [$status, $body] = http_post($this->apiHost . '/oauth2/token', [], http_build_query([
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id'     => $this->appKey,
            'client_secret' => $this->appSecret,
        ]), 'application/x-www-form-urlencoded');
        $json = json_decode((string) $body, true);
        if ($status !== 200 || empty($json['access_token'])) {
            $msg = $json['error_description'] ?? $json['error'] ?? substr((string) $body, 0, 200);
            throw new RuntimeException("Could not refresh the Dropbox token: $msg. Reconnect Dropbox on the admin page.");
        }
        $this->accessToken = $json['access_token'];
        $this->accessExpires = time() + (int) ($json['expires_in'] ?? 14400);
        @file_put_contents($this->cacheFile, json_encode(['token' => $this->accessToken, 'expires' => $this->accessExpires]), LOCK_EX);
        @chmod($this->cacheFile, 0600);
        return $this->accessToken;
    }

    private function rpc(string $endpoint, ?array $args, ?string $host = null): array
    {
        $url = ($host ?? $this->apiHost) . $endpoint;
        $payload = $args === null ? 'null' : json_encode($args);
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            [$status, $body, $headers] = http_post($url, [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->token(),
            ], $payload);
            if ($status === 429 || $status >= 500) {
                $wait = (int) ($headers['retry-after'] ?? 0) ?: 2 * $attempt;
                sleep(min($wait, 30));
                continue;
            }
            break;
        }
        $json = json_decode((string) $body, true);
        if ($status !== 200 || !is_array($json)) {
            $summary = is_array($json) ? ($json['error_summary'] ?? '') : substr((string) $body, 0, 300);
            throw new RuntimeException("Dropbox $endpoint failed (HTTP $status): $summary");
        }
        return $json;
    }
}

/**
 * Minimal POST helper built on curl.
 * @return array{0:int,1:string,2:array<string,string>} status, body, lower-cased response headers
 */
function http_post(string $url, array $headers, string $body, ?string $contentType = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The PHP curl extension is not available on this server.');
    }
    $respHeaders = [];
    if ($contentType) {
        $headers[] = 'Content-Type: ' . $contentType;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    $result = curl_exec($ch);
    if ($result === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException("Network error contacting $url: $err");
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, (string) $result, $respHeaders];
}

/** Build the configured source, or explain why it can't be built. */
function make_source(): AlbumSource
{
    $c = $GLOBALS['CONFIG'];
    if (!empty($c['local_albums_path'])) {
        return new LocalSource($c['local_albums_path']);
    }
    if (empty($c['dropbox_app_key']) || empty($c['dropbox_app_secret'])) {
        throw new RuntimeException('Add your Dropbox app key and secret to app/config.php.');
    }
    $refresh = dropbox_refresh_token();
    if ($refresh === null) {
        throw new RuntimeException('Dropbox is not connected yet. Open admin.php and follow the "Connect Dropbox" steps.');
    }
    return new DropboxSource(
        $c['dropbox_app_key'],
        $c['dropbox_app_secret'],
        $refresh,
        $c['dropbox_albums_path'],
        DATA_DIR . '/dropbox-access.json',
        $c['_dropbox_hosts'] ?? []
    );
}

function dropbox_refresh_token(): ?string
{
    $j = read_json(DATA_DIR . '/dropbox-token.json', []);
    return $j['refresh_token'] ?? null;
}

function save_dropbox_refresh_token(?string $token): void
{
    ensure_data_dir();
    $f = DATA_DIR . '/dropbox-token.json';
    @unlink(DATA_DIR . '/dropbox-access.json');
    if ($token === null) {
        @unlink($f);
        return;
    }
    write_json($f, ['refresh_token' => $token, 'saved' => date('c')]);
    @chmod($f, 0600);
}
