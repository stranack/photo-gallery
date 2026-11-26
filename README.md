# Photo Gallery

A small PHP site that publishes albums of photographs from Dropbox as a gallery. Each album is either public, open to anyone, or private, behind a password. It runs on ordinary shared hosting (I use Bluehost), and the look follows client-gallery services such as ShootProof: a full-screen cover photo, album tabs, a justified photo grid, a full-screen viewer and a slideshow.

Once an hour the site checks the albums folder in Dropbox. It fetches web-sized copies of new or changed photos, removes copies of photos you've deleted and updates the albums. Your original files never leave Dropbox. The site changes nothing in Dropbox except when you, signed in as the owner, move a photo to the Trash.

## Features

- A cover photo with the gallery title and a **View gallery** button
- Public albums anyone can see, and private albums that need the gallery password (see [Public and private albums](#public-and-private-albums))
- An **Albums** page, like Flickr's: a card for each album with its cover photo, name and photo count. The header has **Albums**, **Tags** and **Favourites** tabs, and each album has an **All albums** link back
- Signed in as the owner, the picture button in the full-screen view makes that photo its album's cover (otherwise the album's first photo is used)
- Photos laid out in justified rows, so every photo keeps its shape
- A full-screen viewer with arrows, keyboard keys (← → Esc, Space for the slideshow, F for favourite, Z for zoom) and swiping on phones
- Zoom like Flickr: click the photo to fill the screen, click again to see it at full detail (the web copy's own size, up to 2048 pixels) and move the mouse or drag to look around; click again or press Esc to go back
- A slideshow with cross-fades that goes full screen
- Favourites: visitors tap the heart on any photo, and the Favourites tab collects them. They're saved in the visitor's own browser.
- Captions made from your file names, which you can replace with your own words
- A trash button for the owner that takes a photo off the gallery and moves the original into a Trash folder in Dropbox
- Works on phones, and stays out of search engines

## How albums work

Every subfolder of the albums folder (by default `Photographs/Albums` in Dropbox) is an album:

```
Photographs/Albums/
  cover.jpg                  ← an image here, outside any album, is the cover photo
  Album 1 Vehicles/          ← shows as "Vehicles", first on the Albums page
  Album 2 Abandoned/         ← shows as "Abandoned", second
```

A number at the start of the folder name sets the order on the Albums page and isn't shown: `Album 1 Vehicles` and `01 Vehicles` both appear as **Vehicles**. Photos within an album are sorted by file name, so date-prefixed names come out in date order. Subfolders inside an album are included in that album. Hidden folders (starting with a dot) are skipped.

For the cover, the site uses an image placed directly in the albums folder. If there isn't one, it uses the first photo of the first album, or the file named in `cover_photo` in the settings.

JPEG, PNG, GIF, WebP, TIFF, BMP and HEIC files are shown. Raw camera files (NEF, CR2, DNG and so on) are skipped, since Dropbox can't make previews of them.

## Public and private albums

Each album is **public** or **private**. Anyone who visits the gallery sees the public albums without a password. The private albums appear only after signing in with the gallery password (the **Sign in** button in the header, or `?signin=1`). If no album is public, visitors see the password page first, as before.

Signed in as the owner, open an album: under its name is a button showing a globe (**Public**) or a lock (**Private**). Click it to switch. The change applies at once. Private albums also show a small lock on their card on the Albums page, for the owner only.

- When this feature was added, every album was made public except the Tabbernor album.
- A new album folder starts out private until you make it public. Renaming an album's folder in Dropbox counts as a new album, so check its setting after a rename.
- Without the password, the cover shows only a photo that is loose in the albums folder or in a public album. If your cover is in a private album, the first photo of the first public album is used instead.
- Making an album private doesn't turn off share links already made for its photos. Use **Stop sharing** for those.
- Settings are saved on the site in `data/albums.json`.

## Captions

Each photo's caption has four parts: a **title**, a **date**, a **location** and a **description**. They show under the photo in the full-screen view: the title first, then the date and location on one line, then the description.

The date and location fill in automatically from file names made by `prefix_photo_dates.py`. The title and description start empty.

| File name | Date | Location |
|---|---|---|
| `2018-09-15_San-Francisco_United-States_DSC_0029.JPG` | 15 September 2018 | San Francisco, United States |
| `2026-11-06_DSC_4403.jpeg` | 6 November 2026 | (empty) |
| `1991_14.jpg` | 1991 | (empty) |
| `DSC_4399 (1).jpeg` | (empty) | (empty) |

To edit a caption, sign in with your admin password, either on the gallery's password page or at `admin.php`. Then open a photo and click the pencil. Under the date and location, the editor shows whether the value came from the file name, and offers to put the file name's version back if you've changed it. Dates are plain text, so "Summer 1991" or "about 1975" work too.

**Save** keeps what you've typed; an emptied field stays empty. **Use automatic** clears your changes to that photo and goes back to the file name. If you leave the date or location as the file name gives them, they keep following the file name, so renaming the file updates them. Your captions are saved on the site (in `data/captions.json`) and stay with the photo if you rename or move it within the albums folder.

## Tags

Each photo can also have **tags**: short labels such as "Rural", "Churches" or "Prairie". Add them in the caption editor (the pencil), separated by commas. They show as small chips under the photo in the full-screen view, and a **Tags** tab appears once any photo has a tag. The tab lists every tag with its photo count; choosing one shows that tag's photos from all albums. A chip in the viewer jumps to the same list, and `#tags/Rural` in the address opens it directly.

To fill in titles, descriptions and tags for many photos at once (for example from a Flickr export), put them in a JSON file and run the importer on the server after a sync:

```
php app/import.php captions.json --dry-run     # shows what would change
php app/import.php captions.json
```

Each entry is `{"file": "…", "title": "…", "description": "…", "tags": "A, B", "album": "…"}`. A photo is found by its file name; add `album` (name or slug) only if the same file name is in several albums. Tags are added to a photo's existing ones, and anything you've typed by hand is kept unless you add `--overwrite`. A description that is only a place name (as Flickr exports often have, e.g. "Lang, Saskatchewan, Canada") goes into the location instead, so the place isn't shown twice.

## Sharing and downloads

The share button in the full-screen view opens a panel with a preview card (photo, title, your name, date) and these options:

- **Download**: saves the web-size copy (up to 2048 pixels), named after the photo's title. Your originals stay in Dropbox.
- **Copy link**, **Facebook** and **X**: give that one photo its own public page, `share.php?s=…`, with a preview Facebook and X can show. Anyone with the link sees just that photo and its caption; the rest of the gallery stays behind the password.
- **More** (phones and tablets): the device's own share menu, with the same public link.

Anyone signed in to the gallery can share a photo. To turn a share off, open the photo's share panel while signed in as the owner and choose **Stop sharing**, or use the **Shared photos** list on the admin page. The old link then shows a "not shared any more" page; sharing the photo again makes a new link.

## Removing photos

Signed in as the owner, open a photo and click the trash can (or press Delete). After you confirm, the site:

- moves the original out of its album into the Trash folder in Dropbox, keeping the album folder's name: `Photographs/Albums/Album 1 Vehicles/x.jpg` goes to `Photographs/Trash/Album 1 Vehicles/x.jpg`. If a file of that name is already there, Dropbox adds a number.
- takes the photo off the gallery at once, deletes the site's copies of it and turns off its public link if it had one.

Nothing is deleted from Dropbox, so the Trash folder is where to look if you change your mind: move the file back into its album folder and the next sync puts it back, with its caption. Empty the Trash folder in Dropbox whenever you like. To use a different folder, set `dropbox_trash_path` in `config.php`; keep it outside the albums folder.

This needs the Dropbox app's **files.content.write** permission (step 1 of Setup). If you set the gallery up before the trash button existed:

1. At <https://www.dropbox.com/developers/apps>, open the app, tick **files.content.write** on the **Permissions** tab and click **Submit**.
2. On the admin page, click **Disconnect**, then connect Dropbox again with a new code. A connection made before the change keeps its old, read-only permissions.

If you share the Dropbox app with Kevin's Library, the library's connection gets the new permission only when you reconnect it there too; it doesn't need it.

## Requirements

- PHP 8.0 or newer with the `curl` and `json` extensions, standard on shared hosts
- Apache or LiteSpeed with `.htaccess` support, which keeps the settings, data and photo copies private
- A cron job, or a free web cron service, to run the hourly sync

## Layout

```
photos/              upload this folder to your web space
  index.php          password page and gallery
  img.php            hands photos only to visitors with the password (or a share link)
  share.php          public page for a shared photo; creates and removes share links
  caption.php        saves captions you write (owner only)
  delete.php         moves a photo to the Trash folder (owner only)
  album.php          makes an album public or private, or sets its cover (owner only)
  admin.php          connect Dropbox, run a sync, check status
  cron.php           optional web-triggered sync
  app/               code and settings (blocked from the web); import.php adds captions in bulk
  data/              gallery index, captions, sync state (blocked from the web)
  images/            web-sized photo copies, filled in by the sync (blocked from the web)
  assets/            stylesheet, script, icon
```

## Setup

The steps below use cPanel. Setup takes about 20 minutes. If you set up Kevin's Library, you can reuse the same Dropbox app: add the **files.content.write** permission (step 1, item 4), skip the rest of step 1 and use its key and secret.

### 1. Create a Dropbox app

1. Go to <https://www.dropbox.com/developers/apps> and click **Create app**.
2. Choose **Scoped access**, then **Full Dropbox**.
3. Give it a name. It has to be unique across Dropbox, and only you will see it.
4. On the **Permissions** tab, tick **files.metadata.read**, **files.content.read** and **files.content.write**, then click **Submit**. The site uses the write permission only for the owner's trash button.
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

- Public albums are open to anyone with the address. Private albums need the gallery password. Visitors' browsers stay signed in for 30 days (`remember_days`), and changing the password signs everyone out.
- The photo copies are in `images/`, which is blocked from the web. Photos are only handed out through `img.php`, which checks on every request that the photo is in a public album or that the visitor has the password, so a copied address of a private photo doesn't work for someone without it.
- Pages ask search engines not to index them.
- Favourites live in each visitor's browser. The site doesn't record who favourited what.
- The `app/`, `data/` and `images/` folders are blocked by `.htaccess`. For extra separation, set `data_dir` in `config.php` to a folder outside your web space; the photo copies then go there too.

## Testing locally

Set `local_albums_path` in `config.php` to a folder of albums on your own computer, run `php photos/app/sync.php`, then `php -S localhost:8000 -t photos` and open <http://localhost:8000>. Local mode needs the PHP `gd` extension to resize photos.

## Troubleshooting

**The password page says the gallery isn't open yet.** `gallery_password` is still `change-me`, or shorter than 4 characters.

**"Dropbox rejected the code."** Codes expire after a few minutes and work only once. Open the permission page again.

**"path/not_found".** `dropbox_albums_path` doesn't match where the albums are in Dropbox. Leave out your computer's own folders.

**"The Dropbox connection is read-only."** The trash button needs the **files.content.write** permission. See [Removing photos](#removing-photos).

**A photo is missing.** Check the admin page: it lists any photos Dropbox couldn't preview. Raw files and some HEIC files need exporting as JPEG.
