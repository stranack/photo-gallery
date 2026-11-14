# Photo Gallery

A small PHP site that publishes albums of photographs from Dropbox as a private, password-protected gallery. It runs on ordinary shared hosting (I use Bluehost), and the look follows client-gallery services such as ShootProof: a full-screen cover photo, album tabs, a justified photo grid, a full-screen viewer and a slideshow.

Once an hour the site checks the albums folder in Dropbox. It fetches web-sized copies of new or changed photos, removes copies of photos you've deleted and updates the albums. Its Dropbox access is read-only, and your original files never leave Dropbox.

## Features

- A password page, then a cover photo with the gallery title and a **View gallery** button
- One tab per album, plus **Favourites**
- Photos laid out in justified rows, so every photo keeps its shape
- A full-screen viewer with arrows, keyboard keys (← → Esc, Space for the slideshow, F for favourite) and swiping on phones
- A slideshow with cross-fades that goes full screen
- Favourites: visitors tap the heart on any photo, and the Favourites tab collects them. They're saved in the visitor's own browser.
- Captions made from your file names, which you can replace with your own words
- Works on phones, and stays out of search engines

## How albums work

Every subfolder of the albums folder (by default `Photographs/Albums` in Dropbox) is an album:

```
Photographs/Albums/
  cover.jpg                  ← an image here, outside any album, is the cover photo
  Album 1 Vehicles/          ← shows as "Vehicles", first tab
  Album 2 Abandoned/         ← shows as "Abandoned", second tab
```

A number at the start of the folder name sets the order of the tabs and isn't shown: `Album 1 Vehicles` and `01 Vehicles` both appear as **Vehicles**. Photos within an album are sorted by file name, so date-prefixed names come out in date order. Subfolders inside an album are included in that album. Hidden folders (starting with a dot) are skipped.

For the cover, the site uses an image placed directly in the albums folder. If there isn't one, it uses the first photo of the first album, or the file named in `cover_photo` in the settings.

JPEG, PNG, GIF, WebP, TIFF, BMP and HEIC files are shown. Raw camera files (NEF, CR2, DNG and so on) are skipped, since Dropbox can't make previews of them.

## Captions

Captions come from the file names made by `prefix_photo_dates.py`:

| File name | Caption |
|---|---|
| `2018-09-15_San-Francisco_United-States_DSC_0029.JPG` | 15 September 2018 · San Francisco, United States |
| `2026-11-06_DSC_4403.jpeg` | 6 November 2026 |
| `1991_14.jpg` | 1991 |
| `DSC_4399 (1).jpeg` | (no caption) |

To write your own caption, sign in to `admin.php` once on your browser, open a photo in the gallery and click the pencil. **Save** keeps your words and **Use automatic** goes back to the caption from the file name. Saving an empty caption hides it for that photo. Your captions are saved on the site (in `data/captions.json`) and stay with the photo if you rename or move it within the albums folder.

## Requirements

- PHP 8.0 or newer with the `curl` and `json` extensions, standard on shared hosts
- Apache or LiteSpeed with `.htaccess` support, which keeps the settings, data and photo copies private
- A cron job, or a free web cron service, to run the hourly sync

## Layout

```
photos/              upload this folder to your web space
  index.php          password page and gallery
  img.php            hands photos only to visitors with the password
  caption.php        saves captions you write (owner only)
  admin.php          connect Dropbox, run a sync, check status
  cron.php           optional web-triggered sync
  app/               code and settings (blocked from the web)
  data/              gallery index, captions, sync state (blocked from the web)
  images/            web-sized photo copies, filled in by the sync (blocked from the web)
  assets/            stylesheet, script, icon
```

## Setup

The steps below use cPanel. Setup takes about 20 minutes. If you set up Kevin's Library, you can reuse the same Dropbox app: it already has the permissions this site needs, so skip step 1 and use its key and secret.

### 1. Create a Dropbox app

1. Go to <https://www.dropbox.com/developers/apps> and click **Create app**.
2. Choose **Scoped access**, then **Full Dropbox**.
3. Give it a name. It has to be unique across Dropbox, and only you will see it.
4. On the **Permissions** tab, tick **files.metadata.read** and **files.content.read**, then click **Submit**.
5. On the **Settings** tab, note the **App key** and **App secret**.

### 2. Upload the files

Copy the `photos` folder into your web space as `public_html/photos`. With cPanel, zip the folder, upload the zip in **File Manager** and use **Extract**. Make sure the `.htaccess` files come along; File Manager hides them unless you turn on **Show Hidden Files** in its settings.

### 3. Add your settings

In `photos/app/`, copy `config.sample.php` to `config.php` and set:

- `site_title` and `site_subtitle`: shown on the cover.
- `gallery_password`: the password you give to people you want to see the gallery.
- `admin_password`: a long, different password for the admin page.
- `dropbox_app_key` and `dropbox_app_secret`: from step 1.
- `dropbox_albums_path`: where the albums are in Dropbox, starting with a slash (`/Photographs/Albums`).

`config.php` is listed in `.gitignore`, so your passwords stay out of GitHub.

### 4. Connect Dropbox and run the first sync

1. Visit `stranack.ca/photos/admin.php` and sign in.
2. Under **Dropbox**, open the permission page, click **Allow**, and paste the code Dropbox gives you into the admin page.
3. Click **Sync now**. Each step runs for up to 20 seconds and fetches about 6 photos at a time, and the page continues by itself until every photo is in. Each photo takes roughly 0.5 MB on the server.

### 5. Schedule hourly updates

In cPanel, open **Cron Jobs**, choose **Once Per Hour**, and paste the command shown on the admin page:

```
php -q /home3/stranack/public_html/photos/app/sync.php --quiet
```

A check takes a second or two when nothing has changed. If you can't use cron, set a random `cron_key` in `config.php` and point a web cron service at the `cron.php?key=...` address the admin page shows.

## Updating the code automatically (optional)

GitHub can put code changes live whenever something in `photos/` changes on `main`. This works the same way as Kevin's Library: `.github/workflows/deploy.yml` asks cPanel's **Git Version Control** to pull the latest commit, and `.cpanel.yml` copies `photos/` over the live folder. Your `config.php`, the `data` folder and the photo copies aren't in the repository, so a deploy never replaces them, and the copy stops if the live folder doesn't already contain `img.php`.

One-time setup:

1. In the GitHub repository, open **Settings → Secrets and variables → Actions** and add your cPanel API token as a secret named `BLUEHOST_CPANEL_API_TOKEN` (the same token Kevin's Library uses works here).
2. In cPanel, open **Git Version Control**, choose **Create**, and clone this repository into `/home3/stranack/repositories/photo-gallery`.
3. In GitHub, open **Actions → Deploy to Bluehost → Run workflow** for the first deploy.

Until the secret exists, the workflow just notes that it skipped the deploy.

## Privacy

- Visitors need the gallery password. Their browser stays signed in for 30 days (`remember_days`), and changing the password signs everyone out.
- The photo copies are in `images/`, which is blocked from the web. Photos are only handed out through `img.php`, which checks the password on every request, so a copied photo address doesn't work for someone without the password.
- Pages ask search engines not to index them.
- Favourites live in each visitor's browser. The site doesn't record who favourited what.
- The `app/`, `data/` and `images/` folders are blocked by `.htaccess`. For extra separation, set `data_dir` in `config.php` to a folder outside your web space; the photo copies then go there too.

## Testing locally

Set `local_albums_path` in `config.php` to a folder of albums on your own computer, run `php photos/app/sync.php`, then `php -S localhost:8000 -t photos` and open <http://localhost:8000>. Local mode needs the PHP `gd` extension to resize photos.

## Troubleshooting

**The password page says the gallery isn't open yet.** `gallery_password` is still `change-me`, or shorter than 4 characters.

**"Dropbox rejected the code."** Codes expire after a few minutes and work only once. Open the permission page again.

**"path/not_found".** `dropbox_albums_path` doesn't match where the albums are in Dropbox. Leave out your computer's own folders.

**A photo is missing.** Check the admin page: it lists any photos Dropbox couldn't preview. Raw files and some HEIC files need exporting as JPEG.
