<?php
/*
 * Photo Gallery: settings
 *
 * Copy this file to config.php (in the same app/ folder) and fill in the values.
 * config.php is never served to visitors: the app/ folder is blocked by .htaccess.
 */
return [

    // Shown on the cover, in the header and in the browser tab.
    'site_title'    => 'Photographs',
    // A short line under the title on the cover. Leave empty for none.
    'site_subtitle' => 'Kevin Stranack',

    // Password visitors type to see the gallery. Share it with the people you
    // want to see your photos. Changing it signs out everyone.
    'gallery_password' => 'change-me',

    // How many days a visitor's browser stays signed in.
    'remember_days' => 30,

    // Password for admin.php (connecting Dropbox, running a sync, editing
    // captions). Use something long and different from the gallery password.
    'admin_password' => 'change-me-to-something-long',

    // Dropbox app credentials, from https://www.dropbox.com/developers/apps
    // (the "App key" and "App secret" on your app's Settings tab).
    'dropbox_app_key'    => '',
    'dropbox_app_secret' => '',

    // The folder holding your albums, inside Dropbox, starting with a slash.
    // Each subfolder becomes an album. "Album 1 Vehicles" shows as "Vehicles",
    // and the number sets the order. An image placed directly in this folder
    // (not in an album) is used as the cover photo.
    'dropbox_albums_path' => '/Photographs/Albums',

    // Where the trash button in the full-screen view moves a photo, inside
    // Dropbox. It keeps its album folder name, e.g.
    // /Photographs/Trash/Album 1 Vehicles/2026-11-06_DSC_4403.jpeg. Keep it
    // outside the albums folder. Leave empty for a Trash folder next to the
    // albums folder (/Photographs/Trash).
    'dropbox_trash_path' => '',

    // Optional: the file name of the cover photo, if you'd rather pick one from
    // inside an album, e.g. '2026-11-06_DSC_4403.jpeg'. Leave empty to use an
    // image in the albums folder itself, or else the first photo of the first album.
    'cover_photo' => '',

    // Seconds each photo stays on screen in the slideshow.
    'slideshow_seconds' => 5,

    // Secret used by cron.php?key=... if you trigger syncs from a web cron
    // service instead of a cPanel cron job. Leave empty to switch cron.php off.
    'cron_key' => '',

    // How many seconds one cron-job sync may spend fetching photos before it
    // stops and picks up again on the next run.
    'cron_time_budget' => 300,

    // Time zone for dates on the admin page and in the sync log.
    'timezone' => 'America/Regina',

    // Advanced: read albums from a folder on this server instead of Dropbox.
    // Leave empty to use Dropbox.
    'local_albums_path' => '',

    // Advanced: keep data (gallery index, captions, Dropbox token) outside the
    // website folder. Leave empty to use photos/data/.
    'data_dir' => '',
];
