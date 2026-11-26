<?php
declare(strict_types=1);

function cfg(string $key, $default = null)
{
    return $GLOBALS['CONFIG'][$key] ?? $default;
}

function h($s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ensure_data_dir(): void
{
    foreach ([DATA_DIR, IMAGE_DIR] as $d) {
        if (!is_dir($d) && !@mkdir($d, 0755, true) && !is_dir($d)) {
            throw new RuntimeException("Cannot create folder $d. Check its permissions.");
        }
    }
}

function read_json(string $file, $default = [])
{
    if (!is_file($file)) {
        return $default;
    }
    $j = json_decode((string) file_get_contents($file), true);
    return is_array($j) ? $j : $default;
}

/** Write JSON through a temporary file, so readers never see half a file. */
function write_json(string $file, $data): void
{
    ensure_data_dir();
    $tmp = $file . '.tmp' . getmypid();
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException("Could not save $file");
    }
}

function log_line(string $msg): void
{
    try {
        ensure_data_dir();
        $f = DATA_DIR . '/sync.log';
        if (is_file($f) && filesize($f) > 512 * 1024) {
            @rename($f, $f . '.1');
        }
        @file_put_contents($f, date('Y-m-d H:i:s') . '  ' . $msg . "\n", FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // Logging is best-effort.
    }
}

function cookie_path(): string
{
    return rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
}

function set_cookie(string $name, string $value, int $days): void
{
    setcookie($name, $value, [
        'expires'  => $days > 0 ? time() + $days * 86400 : time() - 3600,
        'path'     => cookie_path(),
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// ---- Who is looking -------------------------------------------------------

function admin_password_usable(): bool
{
    $p = (string) cfg('admin_password', '');
    return CONFIG_LOADED && strlen($p) >= 8 && $p !== 'change-me-to-something-long';
}

function gallery_password_usable(): bool
{
    $p = (string) cfg('gallery_password', '');
    return CONFIG_LOADED && strlen($p) >= 4 && $p !== 'change-me';
}

/** Derived from the admin password, so changing it signs out every browser. */
function owner_token(): string
{
    return admin_password_usable() ? hash_hmac('sha256', 'gallery-owner-v1', (string) cfg('admin_password')) : '';
}

/** Derived from the gallery password, so changing it signs out every visitor. */
function visitor_token(): string
{
    return gallery_password_usable() ? hash_hmac('sha256', 'gallery-visitor-v1', (string) cfg('gallery_password')) : '';
}

function is_owner(): bool
{
    $t = owner_token();
    return $t !== '' && hash_equals($t, (string) ($_COOKIE['gallery_owner'] ?? ''));
}

function is_visitor(): bool
{
    if (is_owner()) {
        return true;
    }
    $t = visitor_token();
    return $t !== '' && hash_equals($t, (string) ($_COOKIE['gallery_access'] ?? ''));
}

function set_owner_cookie(bool $on): void
{
    set_cookie('gallery_owner', $on ? owner_token() : '', $on ? 365 : 0);
}

function set_visitor_cookie(bool $on): void
{
    set_cookie('gallery_access', $on ? visitor_token() : '', $on ? max(1, (int) cfg('remember_days', 30)) : 0);
}

/** Token the caption editor sends back, so another site can't post captions in the owner's name. */
function owner_csrf(): string
{
    return hash_hmac('sha256', 'captions-csrf', owner_token());
}

/** Slow down password guessing a little. */
function failed_attempt_pause(): void
{
    usleep(random_int(800000, 1500000));
}

// ---- Installing as an app -------------------------------------------------

/**
 * Attributes for <html>: whether this browser can see the private albums, and
 * which page it is. assets/app.js reads them to keep the photos saved on the
 * device in step with what the browser may see.
 */
function app_html_attrs(string $page): string
{
    return ' data-visitor="' . (is_visitor() ? '1' : '0') . '" data-page="' . h($page) . '"';
}

/**
 * What makes the site installable as an app: the manifest, home-screen
 * icons, the bar colour, and the script that registers the service worker.
 */
function app_head_tags(): string
{
    $v = h((string) @filemtime(APP_ROOT . '/assets/app.js'));
    return '<link rel="manifest" href="manifest.php">' . "\n"
        . '<meta name="theme-color" content="#1f1e1c">' . "\n"
        . '<meta name="mobile-web-app-capable" content="yes">' . "\n"
        . '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n"
        . '<meta name="apple-mobile-web-app-status-bar-style" content="black">' . "\n"
        . '<link rel="apple-touch-icon" href="assets/apple-touch-icon.png">' . "\n"
        . '<script src="assets/app.js?v=' . $v . '" defer></script>' . "\n";
}
