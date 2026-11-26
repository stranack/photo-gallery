<?php
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_DIR', __DIR__);

mb_internal_encoding('UTF-8');

$GLOBALS['CONFIG'] = [
    'site_title'          => 'Photographs',
    'site_subtitle'       => '',
    'gallery_password'    => '',
    'remember_days'       => 30,
    'admin_password'      => '',
    'dropbox_app_key'     => '',
    'dropbox_app_secret'  => '',
    'dropbox_albums_path' => '/Photographs/Albums',
    'dropbox_trash_path'  => '',
    'cover_photo'         => '',
    'slideshow_seconds'   => 5,
    'cron_key'            => '',
    'cron_time_budget'    => 300,
    'timezone'            => 'UTC',
    'local_albums_path'   => '',
    'local_trash_path'    => '',
    'data_dir'            => '',
];

$configFile = APP_DIR . '/config.php';
if (is_file($configFile)) {
    $loaded = require $configFile;
    if (is_array($loaded)) {
        $GLOBALS['CONFIG'] = array_merge($GLOBALS['CONFIG'], $loaded);
    }
    define('CONFIG_LOADED', true);
} else {
    define('CONFIG_LOADED', false);
}

define('DATA_DIR', rtrim($GLOBALS['CONFIG']['data_dir'] ?: APP_ROOT . '/data', '/'));
define('IMAGE_DIR', DATA_DIR === APP_ROOT . '/data' ? APP_ROOT . '/images' : DATA_DIR . '/images');
define('GALLERY_FILE', DATA_DIR . '/gallery.json');
define('CAPTIONS_FILE', DATA_DIR . '/captions.json');
define('SHARES_FILE', DATA_DIR . '/shares.json');
define('ALBUMS_FILE', DATA_DIR . '/albums.json');
define('STATE_FILE', DATA_DIR . '/state.json');

date_default_timezone_set($GLOBALS['CONFIG']['timezone'] ?: 'UTC');

require APP_DIR . '/helpers.php';
require APP_DIR . '/Sources.php';
require APP_DIR . '/Gallery.php';
require APP_DIR . '/Sync.php';
require APP_DIR . '/Trash.php';
require APP_DIR . '/Upload.php';
