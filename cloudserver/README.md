# DangeeCast — Cloud Server

Server-side files for the DangeeCast digital-signage system. Deploys onto Apache + PHP 8.4 + MySQL 5.7+, sitting at `https://aromen.biz/signage/`.

## Folder layout

```
cloudserver/
├── schema.sql                 ← run once into a fresh DB
├── config.example.php         ← copy → config.php and fill in
├── api/                       ← public APIs called by the APK
│   ├── _common.php
│   ├── register.php
│   ├── ping.php
│   ├── version.php
│   └── crash.php
├── player/
│   └── index.html             ← web player loaded by the WebView APK
├── admin/                     ← password-gated dashboard
│   ├── login.php / logout.php / index.php
│   ├── dashboard.php
│   ├── devices.php / device_edit.php / device_force_refresh.php
│   ├── cities.php             (cities + stores CRUD on one page)
│   ├── media.php / media_upload.php / media_delete.php
│   ├── playlist_editor.php / playlist_save.php
│   ├── apk.php / apk_upload.php
│   ├── crashes.php
│   ├── _auth.php / _db.php / _layout.php / _playlist_writer.php
│   └── assets/style.css, app.js
├── devices/                   ← per-ANDROID_ID/playlist.json files (auto-generated)
├── media/                     ← uploaded media files (hash-suffixed names)
├── apk/                       ← uploaded APK builds for self-update
└── logs/                      ← future server-side logs (currently empty, denied to public)
```

The `devices/`, `media/`, `apk/`, `logs/` folders each have an `.htaccess` controlling listing and which file types are servable.

## First-time deployment

The expected layout on the server is:

```
<deploy root>/                  e.g. /home/aromen/
├── public_html/
│   └── signage/                ← upload cloudserver/* here (everything in this folder)
└── config_dc/
    └── config.php              ← real secrets, NEVER inside public_html
```

Steps:

1. **Upload `cloudserver/*`** to the docroot path that maps to `https://aromen.biz/signage/` (e.g. `public_html/signage/`).
2. **Create the database and a user**, e.g.:
   ```sql
   CREATE DATABASE gtvpheud_DangeeCast CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   CREATE USER 'gtvpheud_DangeeCast'@'localhost' IDENTIFIED BY '<strong-password>';
   GRANT SELECT, INSERT, UPDATE, DELETE ON gtvpheud_DangeeCast.* TO 'gtvpheud_DangeeCast'@'localhost';
   FLUSH PRIVILEGES;
   ```
3. **Load the schema:** `mysql gtvpheud_DangeeCast < schema.sql`
4. **Generate an admin password hash:**
   ```bash
   php -r 'echo password_hash("your-real-password", PASSWORD_BCRYPT)."\n";'
   ```
5. **Place the config file outside the docroot:**
   ```bash
   mkdir -p ../config_dc
   cp config.example.php ../config_dc/config.php
   # then edit ../config_dc/config.php — set db.password, admin.password_hash, public_base_url
   ```
   The loaders look for `<deploy-root>/config_dc/config.php` automatically. You can also point them anywhere via the `DANGEECAST_CONFIG` environment variable (set in `.htaccess` with `SetEnv`).
6. **Set folder permissions** so the web user (e.g. `www-data`) can write to `media/`, `devices/`, `apk/`:
   ```bash
   chown -R www-data:www-data media devices apk logs
   chmod 755 media devices apk logs
   ```
7. **Verify:** browse to `/signage/admin/login.php`, log in, then `Cities & Stores` → add at least one city + store before assigning devices.

## Endpoints used by the APK

| Method | Path | Called when |
|---|---|---|
| POST | `/signage/api/register.php` | first boot, then once per day |
| POST | `/signage/api/ping.php` | every 5 min |
| GET  | `/signage/api/version.php?current_version=<v>` | once per day |
| POST | `/signage/api/crash.php` | when a queued crash file is flushed |
| GET  | `/signage/devices/<ANDROID_ID>/playlist.json` | every 10 min by the player |

## Player

`player/index.html` is the WebView target. It:

- reads `window.ANDROID_ID` / `window.MAC_ADDRESS` injected by the APK
- fetches its `playlist.json` with `If-Modified-Since` for cheap polling
- shows an "Unregistered device" panel on 404 and an error panel on other failures
- crossfades between items via two stacked layers
- supports per-item background MP3 (audio loops while item plays)
- listens for `signage:force-refresh` window event from the APK

## Playlist regeneration

Whenever an admin saves the playlist editor, `_playlist_writer.php::playlist_regenerate($deviceId)` writes a fresh JSON to `devices/<ANDROID_ID>/playlist.json` atomically (writes `.tmp`, then `rename`). Apache's `Last-Modified` header makes 99% of player polls return `304 Not Modified` once the file stabilises.

## Things you'll likely tweak after first deploy

- **PHP `upload_max_filesize` and `post_max_size`** in `php.ini` — bump to ≥ 500 MB if you want big videos through the dashboard upload form.
- **`config.php` `log_pings`** — true in dev to populate the device-detail activity panel; false in production to keep `device_logs` from growing without bound.
- **Apache `mod_headers`** must be enabled for `Cache-Control` and the playlist `Last-Modified` flow.

## Out of scope for v1.0

- Multi-user admin accounts (single user, single password)
- Live streaming, kiosk surveys, multi-zone screens
- Per-hour scheduling (weekday granularity only)
- Viewer analytics, attention metrics
- POS / sales integration
- Service worker / offline caching in the player (planned for v1.1)
