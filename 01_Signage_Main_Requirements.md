# Digital Signage System — Main Requirements

**Project:** DangeeCast Multi-Store Digital Signage
**Document version:** 1.0
**Date:** April 2026
**Server:** aromen.biz/signage
**Companion doc:** `02_Signage_APK_Requirements.md`

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Goals & Scope](#2-goals--scope)
3. [System Architecture](#3-system-architecture)
4. [Hardware Requirements](#4-hardware-requirements)
5. [Network & Connectivity](#5-network--connectivity)
6. [Server Setup](#6-server-setup)
7. [Folder Structure](#7-folder-structure)
8. [Database Schema](#8-database-schema)
9. [API Specifications](#9-api-specifications)
10. [Playlist Format](#10-playlist-format)
11. [Web Player Requirements](#11-web-player-requirements)
12. [Admin Dashboard Requirements](#12-admin-dashboard-requirements)
13. [Synchronization Strategy](#13-synchronization-strategy)
14. [Device Lifecycle](#14-device-lifecycle)
15. [Deployment Plan](#15-deployment-plan)
16. [Operations & Monitoring](#16-operations--monitoring)
17. [Decisions Log](#17-decisions-log)
18. [Open Questions](#18-open-questions)
19. [Glossary](#19-glossary)

---

## 1. Project Overview

A digital signage system to display promotional images and videos on TV screens across **50 retail store locations**. Content is centrally managed via a web-based admin dashboard and delivered to Android-based set-top boxes connected to in-store TVs over HDMI.

### Key components

- **Android Box (G96 MAX)** at each store, connected to TV via HDMI
- **WebView APK** running on each box, loading the web player
- **Web Player** (HTML/JavaScript) — actual signage logic
- **PHP/MySQL Admin Dashboard** for content management
- **Apache server** at `aromen.biz/signage` hosting everything

### Delivery model

The Android device runs a thin WebView wrapper that loads the web player from the server. All signage logic lives in the web player. The APK rarely needs updating; product changes happen by editing files on the server.

---

## 2. Goals & Scope

### Primary goals

- Display scheduled image and video content on TVs across 50 stores
- Different content per weekday per store
- Centrally managed — no per-store technical knowledge needed
- Resilient — survives network drops, power cuts, and server downtime
- Low-touch operations — minimal in-store technical support required

### Scope inclusions

- Content management (upload, schedule, assign)
- Per-device weekday playlists
- Automatic content synchronization
- Device monitoring (online/offline status, app version, last sync)
- Auto-registration of new devices
- Remote APK update mechanism
- Crash reporting
- Background MP3 audio (planned, not initial release)

### Scope exclusions

- Live streaming or real-time broadcast
- User-interactive content (touch screens, kiosk surveys)
- Multi-language UI in player (single language acceptable)
- Multi-zone screens (split-screen with separate content regions)
- Per-hour scheduling (weekday granularity is sufficient)
- Analytics on viewer count or attention metrics
- Integration with POS or sales systems

---

## 3. System Architecture

### High-level flow

```
[Admin Dashboard]  →  [MySQL Database + Media Files]
        ↓
[playlist.json files generated per device]
        ↓
[Apache web server with HTTPS]
        ↑
[Android Box (G96 MAX) over LAN]
        ↓
[WebView APK loads index.html]
        ↓
[Web Player polls for playlist updates every 10 min]
        ↓
[TV via HDMI displays content]
```

### Data flow summary

1. Admin uploads media via dashboard → stored in `media/` folder + database record
2. Admin assigns content to device weekday playlist → database updated
3. On save, dashboard regenerates `playlist.json` for that device
4. Device polls its `playlist.json` URL every 10 minutes
5. If file changed, device downloads new playlist and applies
6. Device pings server every 5 minutes to report online status + version
7. Dashboard shows real-time device health

### Why this architecture

- **Web-based player** allows changes without redeploying APK
- **Static JSON files** mean no PHP execution per playlist request — fast and cheap
- **Apache Last-Modified header** handles efficient sync without custom server logic
- **Self-registering devices** eliminate manual provisioning
- **Hardcoded URL in APK** keeps device setup zero-config

---

## 4. Hardware Requirements

### Target device

| Field | Specification |
|---|---|
| Model | G96 MAX (Ausha) |
| Operating System | Android 11 |
| RAM | 4 GB |
| Storage | 32 GB |
| Network | LAN (Ethernet) — primary |
| Resolution | 4K capable (1080p output to TV) |
| HDMI Output | Yes |

### Why this device

- Android 11 with modern WebView (auto-updated via Play Store)
- 4 GB RAM allows preloading and smooth video playback
- 32 GB storage caches significant content offline
- Standard Android (not Android TV) — easier app behavior, no Leanback restrictions
- Ethernet built-in — required for stable connectivity

### Display

- HDMI to in-store TV
- 1080p output (Full HD)
- Landscape orientation only
- TV must support HDMI input

### Reference device (legacy)

The system was originally specified for **Xiaomi Mi Box 4 (Android TV 9)**. The APK and player are designed to be backward compatible with this device. New deployments use G96 MAX.

---

## 5. Network & Connectivity

### Connection type

- **Wired LAN (Ethernet)** at all 50 stores
- WiFi explicitly not used due to:
  - Credential management complexity across stores
  - Reconnection delays after router reboots
  - 2.4 GHz congestion in retail environments
  - WiFi MAC randomization issues

### Bandwidth requirements

- Minimum: 2 Mbps per store (sufficient for HD video downloads)
- Recommended: 10 Mbps per store
- Initial content sync may use 100–500 MB depending on playlist size
- Steady-state sync: <1 MB per day per device (only updates downloaded)

### Store-side requirements

- Ethernet port available within 5 meters of TV mount location
- Standard CAT5e or CAT6 cable
- Available switch port on store router/switch
- Optional but recommended: small UPS (~₹2,500) for router + Android box

### Server-side capacity

- 50 devices × 6 polls/hour (10-min interval) = 300 requests/hour
- 50 devices × 12 pings/hour (5-min interval) = 600 requests/hour
- Total: ~900 requests/hour at steady state
- Apache + PHP can handle this trivially on shared hosting

---

## 6. Server Setup

### Host

| Field | Value |
|---|---|
| Domain | aromen.biz |
| Path | /signage |
| Protocol | HTTPS (already configured) |
| Server | Apache |
| Backend | PHP |
| Database | MySQL |

### Co-located project

The same server already runs `aromen.biz/biometric`. The signage system runs alongside it without conflict. They share:

- Apache configuration
- HTTPS certificate
- MySQL instance (different database)
- PHP version

### Server requirements

- Apache 2.4+ with `mod_headers` enabled (for Last-Modified support — usually default)
- PHP 7.4 or higher
- MySQL 5.7 or MariaDB 10.3+
- Disk space: 50 GB recommended (media files + logs over time)
- HTTPS certificate already in place

---

## 7. Folder Structure

```
http://aromen.biz/signage/
│
├── player/
│   └── index.html              ← Web signage player
│
├── media/                      ← Shared media library (all devices)
│   ├── slide1.jpg
│   ├── promo_video.mp4
│   ├── jingle.mp3
│   └── (auto-renamed on upload to prevent collisions)
│
├── devices/                    ← One folder per device
│   ├── a1b2c3d4e5f67890/
│   │   └── playlist.json
│   ├── f9e8d7c6b5a43210/
│   │   └── playlist.json
│   └── (one folder per ANDROID_ID)
│
├── admin/                      ← PHP admin dashboard
│   ├── index.php               (login + landing)
│   ├── media.php               (media library)
│   ├── devices.php             (device list)
│   ├── playlist_editor.php
│   └── (other admin pages)
│
├── api/                        ← Public APIs called by APK + player
│   ├── register.php            (device first-time registration)
│   ├── ping.php                (5-min heartbeat)
│   ├── version.php             (APK update check)
│   └── crash.php               (crash log receiver)
│
├── apk/                        ← APK files for self-update
│   ├── current.apk             (latest version)
│   └── archive/
│       ├── signage_v1.0.0.apk
│       └── signage_v1.1.0.apk
│
└── logs/                       ← Server-side logs
    ├── crashes/
    └── deploys/
```

### Folder access

- `media/`, `devices/`, `apk/` — public read (devices fetch from here)
- `admin/` — password protected (only authorized users)
- `api/` — public, but rate-limited and validated
- `logs/` — not publicly accessible

---

## 8. Database Schema

### Tables overview

| Table | Purpose |
|---|---|
| `cities` | Master list of cities (Ahmedabad, Mumbai, etc.) |
| `stores` | Master list of stores, linked to cities |
| `devices` | One row per Android box, identified by ANDROID_ID |
| `media` | Uploaded media file inventory |
| `playlist_items` | Per-device, per-weekday content schedule |
| `device_logs` | Optional: ping history, version changes |

### Key fields

#### cities
- `id` (primary key)
- `name`
- `created_at`

#### stores
- `id` (primary key)
- `city_id` (foreign key to cities)
- `name`
- `address` (optional)
- `created_at`

#### devices
- `id` (primary key)
- `android_id` (unique, primary identifier from Android)
- `mac_address` (Ethernet MAC, for human reference)
- `store_id` (foreign key to stores, nullable until assigned)
- `display_name` (admin-friendly label)
- `app_version` (current APK version reported by device)
- `last_seen` (timestamp of last ping)
- `last_ip` (IP address of last ping)
- `assigned` (boolean — false until admin assigns to a store)
- `notes` (free text, admin only)
- `created_at`
- `updated_at`

#### media
- `id` (primary key)
- `filename` (auto-generated, hash-suffixed)
- `original_name` (uploaded filename, for display)
- `file_hash` (SHA-256, for deduplication)
- `mime_type`
- `file_type` (image / video / audio)
- `file_size_bytes`
- `duration_seconds` (for videos and audio)
- `uploaded_by` (admin user ID)
- `uploaded_at`

#### playlist_items
- `id` (primary key)
- `device_id` (foreign key)
- `weekday` (enum: monday–sunday)
- `media_id` (foreign key)
- `sort_order` (integer, for drag-drop ordering)
- `duration_seconds` (override for images; 0 for videos = play full)
- `audio_media_id` (optional foreign key for background MP3)
- `video_volume` (0.0–1.0, optional)
- `audio_volume` (0.0–1.0, optional)
- `created_at`
- `updated_at`

#### device_logs (optional)
- `id`
- `device_id`
- `event_type` (ping / register / version_change / crash)
- `details` (JSON or text)
- `timestamp`

### Relationships

- One **city** has many **stores**
- One **store** has many **devices**
- One **device** has many **playlist items** (across weekdays)
- One **media** file can appear in many **playlist items** (across devices)

---

## 9. API Specifications

### Public APIs (called by Android device)

#### POST /api/register.php

**When called:** First boot of new device, then once per day thereafter.

**Purpose:** Tell server this device exists, refresh metadata.

**Request parameters:**
- `android_id` (required)
- `mac_address` (optional but expected)
- `app_version` (required)
- `device_model` (optional)
- `android_version` (optional)

**Response:**
- `status`: success / error
- `assigned`: true/false (whether admin has assigned a store)
- `message`: human-readable status

**Behavior:**
- If `android_id` not in database → create new row with `assigned = false`
- If `android_id` exists → update `last_seen`, `app_version`, `mac_address`
- Never overwrites store assignment

#### POST /api/ping.php

**When called:** Every 5 minutes, by APK.

**Purpose:** Heartbeat — proves device is alive.

**Request parameters:**
- `android_id` (required)
- `app_version` (required)

**Response:**
- `status`: success / error
- Optionally: `force_refresh` flag if admin pressed "force refresh" in dashboard

**Behavior:**
- Updates `devices.last_seen` to NOW
- Updates `devices.last_ip` from request IP
- Updates `devices.app_version`
- Returns small JSON (~100 bytes)

#### GET /api/version.php

**When called:** Once per day by APK.

**Purpose:** Check if a newer APK exists.

**Request parameters:**
- `current_version` (required) — e.g., "1.0.0"

**Response:**
- `latest_version` — e.g., "1.1.0"
- `update_available` (boolean)
- `apk_url` (string) — direct download URL if update available
- `release_notes` (optional)

#### POST /api/crash.php

**When called:** When APK catches an uncaught exception.

**Purpose:** Server-side crash logging for diagnosis.

**Request parameters:**
- `android_id`
- `app_version`
- `android_version`
- `device_model`
- `stack_trace` (text)
- `timestamp`

**Response:**
- `status`: received / error

**Behavior:**
- Appends to log file or `crashes` database table
- Never blocks device — fire-and-forget

### Static endpoints (no PHP, served directly by Apache)

#### GET /devices/{ANDROID_ID}/playlist.json

**Behavior:**
- Apache serves JSON file directly
- Apache automatically sets `Last-Modified` header from file mtime
- Player sends `If-Modified-Since` header
- Apache responds with `304 Not Modified` if unchanged (~200 bytes)
- Apache responds with `200 OK` + JSON body if changed
- No PHP execution needed for the common case

#### GET /media/{filename}

**Behavior:**
- Direct file serving by Apache
- Player appends `?v=<timestamp>` for cache busting
- Browser/WebView caches based on standard HTTP headers

---

## 10. Playlist Format

### Structure

The `playlist.json` file is the single source of truth for what plays on a device. One file per device. Generated by the dashboard whenever an admin saves changes.

### Top-level fields

- `android_id`: device's unique identifier
- `store_name`: display label
- `base_url`: absolute URL to media folder (with trailing slash)
- `global_volume`: default volume 0.0–1.0
- `last_updated`: ISO timestamp (informational)
- `schedule`: object containing each weekday

### Weekday entries

Each weekday key (`monday` through `sunday`) maps to an array of items. Each item describes one piece of content to play.

### Item fields

- `file`: filename relative to `base_url` (with `?v=` suffix for cache busting)
- `type`: image / video
- `duration`: seconds for images; 0 for videos = play full natural length
- `audio`: optional background MP3 filename, or null
- `video_volume`: 0.0–1.0, optional (falls back to global_volume)
- `audio_volume`: 0.0–1.0, optional (falls back to global_volume)

### Behavior rules

- Items in array play in order
- After last item, loop back to first
- At midnight, switch to next weekday's array automatically
- If a weekday array is empty, show "No content scheduled" placeholder
- If playlist file is missing (new unassigned device), show registration screen

### Cache busting

Every `file` value includes a `?v=<unix_timestamp>` suffix matching the file's modification time on the server. When a media file is replaced, the timestamp changes, and devices automatically fetch the new version on their next sync.

### Schedule granularity

Currently weekday-based. Hourly or date-specific scheduling is **not** in scope for v1.0.

---

## 11. Web Player Requirements

### File location

`https://aromen.biz/signage/player/index.html`

### Core features (already built)

- Fullscreen image and video playback
- Smooth fade transitions between items
- Progress bar at bottom of screen
- Last-Modified header–based sync
- Weekday schedule auto-detection
- Midnight rollover to next day
- Status overlay in top-right corner (sync status, device ID)
- Loading screen on initial boot
- "No content" fallback screen
- 60-second retry on server unreachable
- Background download — current playback never interrupted
- `.tmp` rename protection during partial downloads
- Configurable `BASE_URL` and `DEVICE_ID` at top of script

### Features to add

- **Read `window.ANDROID_ID` and `window.ETH_MAC`** from APK injection
  - Replaces hardcoded test_device_001
- **Unregistered device screen** when no playlist.json exists
  - Show ANDROID_ID and MAC in large readable text
  - Display message: "This device is not yet assigned. Please contact admin with the IDs above."
  - Auto-retry every 60 seconds (admin may have just assigned it)
- **Background MP3 audio** — play audio file alongside image/video
  - Loop audio if shorter than image duration
  - Stop and start fresh per item (no continuous music across items)
- **Per-item volume control**
  - Use `AndroidBridge.setVolume()` for system volume
  - Use HTML5 video/audio `volume` property for media-element volume
- **Force refresh handler** — when ping.php returns `force_refresh: true`, immediately re-fetch playlist
- **Diagnostics info screen** triggered by APK long-press OK
  - APK shows its overlay; player provides device-side info via JS bridge

### Service Worker (optional, future)

For deeper offline resilience, a Service Worker can cache the player HTML/CSS/JS and last-known media files. Falls back to cache when network unreachable. Adds 50 lines to `index.html`. Recommended for v1.1 after initial deployment.

### Browser compatibility

Target: Chromium-based WebView on Android 5.0+ (G96 MAX runs Android 11, Mi Box 4 runs Android TV 9). Use ES2017 syntax for safety. Avoid bleeding-edge APIs that may not be present on older WebViews.

---

## 12. Admin Dashboard Requirements

### Tech stack

- PHP 7.4+ (matching existing biometric project)
- MySQL 5.7+
- Bootstrap 5 for UI
- SortableJS for drag-and-drop ordering
- Vanilla JavaScript (no heavy framework)

### User authentication

- Single admin account initially (you)
- Password-protected via PHP session
- Optional: multi-user with roles in v1.1

### Module: Login

- Username + password
- Session-based authentication
- Logout

### Module: Dashboard Home

- Total devices count
- Online/offline breakdown (online if `last_seen` < 10 minutes ago)
- Recent activity feed (registrations, version changes, crashes)
- Map or list view by city
- Quick stats: total media files, storage used

### Module: Media Library

- **Upload**: drag-and-drop or file picker
- **Auto-rename**: files renamed with hash suffix to prevent collisions (e.g., `slide1_a3f9.jpg`)
- **Auto cache-bust**: timestamp updated on replace
- **Preview**: thumbnail for images, first frame for videos
- **Delete**: confirmation prompt; warns if media is in use by any playlist
- **Filter**: by type (image / video / audio), by upload date
- **Search**: by original filename
- **Bulk operations**: multi-select for delete

### Module: Device List

- All devices grouped by city, then store
- Columns: Display name, Store, ANDROID_ID, MAC, App version, Online status, Last seen
- Color coding: green if online (last_seen < 10 min), red otherwise
- Sort by any column
- Filter by city, store, or status
- "Unassigned Devices" section at top — newly registered devices awaiting assignment
- Per-device actions: View, Edit, Force refresh, Delete

### Module: Device Detail / Edit

- View all device fields
- Assign to city + store (drop-down)
- Set display name
- Add notes
- View playlist (link to playlist editor)
- View ping history (last 24 hours)
- View version history
- Force refresh button (sets a flag in DB; ping.php returns force_refresh on next call)
- Delete device

### Module: Playlist Editor

- Select device
- Tabs for each weekday (Monday–Sunday)
- Drag-and-drop reorder of items within a day
- Add item: choose from media library
- Per-item settings: duration (for images), background audio, volumes
- "Copy playlist" button: copy this device's schedule to other devices
- "Save & Publish" button: regenerates `playlist.json` for this device
- Auto-save draft (optional, v1.1)
- Preview button (optional, v1.1)

### Module: Cities & Stores

- CRUD for cities
- CRUD for stores within cities
- Cannot delete a city/store with active devices

### Module: APK Management

- Upload new APK file
- Set as "current" version (referenced by version.php)
- View deployment status: how many devices are on which version
- Release notes per version
- Archive of old APKs

### Module: Crash Logs

- List of recent crashes
- Filter by device, version, date range
- View full stack trace
- Mark as resolved
- Delete old crashes

### Module: Settings

- Default polling intervals
- Default volumes
- Crisis broadcast (push immediate content to all devices — v1.2 feature, out of scope initially)

---

## 13. Synchronization Strategy

### Polling

- Player polls its `playlist.json` every **10 minutes**
- Player also polls on every boot
- APK pings server every **5 minutes** (heartbeat only, separate from playlist sync)
- APK checks for APK updates **once per day**

### Why 10 minutes for playlist

- Fast enough that admins see changes within 10 min on average
- Slow enough to keep server load minimal
- Apache `Last-Modified` makes 99% of these polls return 304 (no-content) at ~200 bytes each

### Sync mechanism

- Player sends `If-Modified-Since: <last_known_modified_time>`
- Apache compares to file mtime
- If file unchanged → 304 Not Modified, player does nothing
- If file newer → 200 OK + JSON body, player applies new playlist
- Network failure → player keeps using cached playlist, retries in 60 seconds

### Cache strategy

- Full week's schedule cached locally on device
- If server down for hours/days, playback continues uninterrupted
- New media files downloaded in background; current playback never blocks
- Old media deleted from cache once not referenced by any playlist (handled by WebView GC)

### Forced refresh

- Admin can press "Force refresh" in dashboard for any device
- Sets a flag in DB
- Next ping.php call returns `force_refresh: true`
- APK signals web player via JS bridge
- Player immediately re-fetches playlist (bypassing 10-min wait)

---

## 14. Device Lifecycle

### Stage 1: Manufacturing / Procurement

- Order G96 MAX boxes
- Each device has unique ANDROID_ID burned in at first boot

### Stage 2: APK Installation

- Done at central location (your office) before shipping
- Install signed release APK via USB drive
- Boot once to confirm app launches
- No per-device configuration needed

### Stage 3: Shipping

- Ship to store with HDMI cable, power adapter, remote
- No labels needed (auto-registration handles identification)

### Stage 4: First Boot at Store

- Store staff plug in power, HDMI, and Ethernet
- Device auto-boots, APK auto-launches
- APK calls `register.php` with ANDROID_ID + MAC + version
- Server creates DB record with `assigned = false`
- Player attempts to load playlist.json — file doesn't exist (404)
- Player shows "Unregistered device" screen with ANDROID_ID + MAC visible

### Stage 5: Assignment

- Admin opens dashboard → sees new entry in "Unassigned Devices"
- Admin clicks device, assigns to city + store, sets display name
- Dashboard generates `playlist.json` for this device
- Within 60 seconds, player retries → finds playlist → starts playing

### Stage 6: Operations

- Device runs continuously, only powered down for store closing (optional)
- Polls playlist every 10 min, pings every 5 min
- Auto-recovers from network drops, reboots, server outages

### Stage 7: Updates

- New APK uploaded to dashboard
- `version.php` updated to point to new version
- Each device's daily update check finds the new version
- Downloads APK, prompts install (someone must press OK on remote)

### Stage 8: Replacement

- If device fails, ship replacement
- New device gets new ANDROID_ID — auto-registers as new entry
- Admin reassigns to same store
- Old device record can be archived or deleted

---

## 15. Deployment Plan

### Phase 1 — Foundation (Week 1)

- [ ] Upload signage_package.zip to aromen.biz/signage/
- [ ] Verify HTTPS player loads correctly in browser
- [ ] Confirm test playlist plays 3 slides
- [ ] Order 1 G96 MAX for testing

### Phase 2 — Backend (Week 2–3)

- [ ] Create MySQL database and tables
- [ ] Build register.php and ping.php
- [ ] Build media upload endpoint with hash-rename
- [ ] Build playlist.json generator (DB → file)
- [ ] Test backend with manually crafted playlists

### Phase 3 — APK (Week 3–4)

- [ ] Set up Android Studio
- [ ] Build APK with all confirmed features
- [ ] Test on G96 MAX over LAN
- [ ] Verify auto-start, auto-registration, OK-button diagnostics
- [ ] Generate signed release APK

### Phase 4 — Dashboard UI (Week 4–6)

- [ ] Login + session
- [ ] Media library
- [ ] Device list with online/offline status
- [ ] Playlist editor with drag-drop
- [ ] APK management page
- [ ] Crash logs page

### Phase 5 — Pilot (Week 7)

- [ ] Deploy to 2–3 stores as pilot
- [ ] Run for 1 week, monitor closely
- [ ] Document issues, iterate

### Phase 6 — Audio Features (Week 8)

- [ ] Add background MP3 support to player
- [ ] Add per-item volume controls to playlist editor
- [ ] Test audio mixing with video silent playback

### Phase 7 — Rollout (Week 9–10)

- [ ] Bulk install APKs on all 50 boxes
- [ ] Ship boxes to all stores
- [ ] Monitor auto-registration
- [ ] Assign each new device to its store as it appears
- [ ] Smoke-test each store within 24 hours

### Phase 8 — Stabilization (Week 11–12)

- [ ] Address field issues
- [ ] Fine-tune polling intervals if needed
- [ ] Document operational procedures
- [ ] Train any staff who will use the dashboard

---

## 16. Operations & Monitoring

### Daily monitoring

- Check dashboard for offline devices (red status)
- Investigate any crashes reported in last 24 hours
- Confirm new devices auto-registered correctly

### Weekly tasks

- Review media library; delete unused files
- Audit playlist assignments for accuracy
- Check disk usage on server

### Monthly tasks

- Backup database
- Backup media folder
- Review APK update adoption rates
- Review device version distribution

### Troubleshooting workflow

When a store reports "screen is blank" or "wrong content":

1. Check dashboard — is device online (last_seen < 10 min)?
2. If offline → check store's network (call store, ask if other devices have internet)
3. If online but blank → check playlist for that device
4. If playlist correct → ask store staff to long-press OK on remote, photograph diagnostics, send to you
5. If still stuck → use force refresh from dashboard
6. If still stuck → SSH to server, check Apache logs for that device's IP

### Common issues

| Issue | Likely cause | Fix |
|---|---|---|
| Device offline | Power off, network down, or device crashed | Power cycle device |
| Wrong content playing | Cache not refreshed | Force refresh from dashboard |
| Stuttering video | Network bandwidth | Reduce video bitrate or check store internet |
| Black screen on boot | HDMI handshake | Power cycle TV first, then box |
| Device not in dashboard | Failed auto-registration | Check Apache error logs; verify register.php |
| App crashes repeatedly | Bad media file or APK bug | Check crash logs; possibly roll back APK |

### Backup strategy

- Database: daily mysqldump, retain 30 days
- Media files: weekly rsync to backup location
- APKs: archived per version in `/apk/archive/`
- Configuration: `playlist.json` files included in media backup

---

## 17. Decisions Log

| Decision | Choice | Reason |
|---|---|---|
| Player platform | Web Kiosk + WebView APK | Fits PHP stack, instant updates without redeploying APK |
| Primary device ID | ANDROID_ID | Stable, no permissions, doesn't randomize |
| Secondary device ID | Ethernet MAC | Human-readable, useful for inventory and physical labeling |
| Media storage | Shared `media/` folder | No duplicate uploads across devices |
| File naming | Hash-suffixed on upload | Prevents collisions, transparent to users |
| Cache busting | `?v=<timestamp>` query param | Forces fresh download on file replace |
| Sync method | Last-Modified HTTP header | Apache native, efficient, no server code |
| Polling interval | 10 minutes (playlist), 5 minutes (heartbeat) | Balance of responsiveness vs server load |
| Schedule granularity | Weekday | Sufficient for retail signage, simple to manage |
| Cache strategy | Full week cached locally | Offline resilience |
| Dashboard | PHP + MySQL + Bootstrap | Same stack as existing biometric project |
| Volume control | JS 0.0–1.0 + native bridge for system volume | Fine-grained per-item control plus device-level adjustment |
| Background audio | MP3 per item (planned v1.1) | Silent video + music for stores with no PA system |
| Network | LAN (Ethernet) | Stability, no credential management, no MAC randomization |
| Server protocol | HTTPS | Secure, no mixed content issues, future-proof |
| APK launcher mode | Regular app (not default launcher) | Simpler, lower risk of locking out device on crash |
| Offline fallback | Show last cached content | Best UX during network/server outage |
| Device assignment | Auto-register, assign in dashboard | Zero-touch field deployment |
| Diagnostics access | Long-press OK on remote (3 sec) | Hidden from store staff, accessible to admin |
| URL configuration | Hardcoded in APK | Eliminates per-device setup; rebuild for URL changes |
| APK update mechanism | Daily check + user-prompted install | Simple, no root required |
| Crash reporting | Server-side via crash.php | Remote diagnosis without ADB access |
| Min Android version | 5.0 (API 21) | Covers Mi Box 4 and G96 MAX |

---

## 18. Open Questions

Items that need decisions before or during build:

- [ ] Default admin username and password
- [ ] Database name (suggested: `aromen_signage`)
- [ ] Whether to use a subdomain (`signage.aromen.biz`) vs path (`aromen.biz/signage`) — currently going with path
- [ ] Whether the existing biometric project's authentication system should be reused, or build fresh
- [ ] Specific cities and stores to populate as initial master data
- [ ] Branding: dashboard logo, color scheme, app name in launcher
- [ ] Whether to add a "broadcast" feature for emergency content push (v1.2)
- [ ] Whether to track playback analytics (which item played, how many times) — currently out of scope
- [ ] Whether stores need a way to report issues from the device (e.g., a button to flag problems) — currently out of scope
- [ ] Time zone handling: server is presumably IST; confirm all devices follow store-local time
- [ ] Whether to support a "preview mode" in the dashboard to see what's currently playing on a device
- [ ] Retention policy for old crash logs and ping history

---

## 19. Glossary

| Term | Meaning |
|---|---|
| **ANDROID_ID** | Unique 64-bit identifier generated per Android device, stable across reboots |
| **APK** | Android Package — the installable app file format |
| **WebView** | Android component that embeds a Chromium browser inside an app |
| **Kiosk mode** | App running in fullscreen, no exit options for end users |
| **Player** | The HTML/JavaScript signage application loaded by the WebView |
| **Playlist** | The schedule of media items for one device for one weekday |
| **Sync** | Process of checking server for updated content |
| **Heartbeat / Ping** | Short HTTP call from device to server proving it's alive |
| **Force refresh** | Admin-triggered immediate sync, bypassing the 10-min wait |
| **Cache bust** | Technique of appending `?v=` to URLs to force fresh downloads |
| **Last-Modified** | HTTP header indicating when a file was last changed on the server |
| **304 Not Modified** | HTTP response indicating the cached version is still current |
| **Auto-registration** | Process where new devices report themselves to the server on first boot |
| **Unassigned device** | Registered device that admin hasn't yet linked to a specific store |
| **Diagnostics overlay** | On-screen info popup triggered by long-pressing OK on the remote |
| **Device owner mode** | Special Android privilege allowing silent APK installs (not used in v1.0) |

---

*End of main requirements document. APK-specific requirements continue in `02_Signage_APK_Requirements.md`.*