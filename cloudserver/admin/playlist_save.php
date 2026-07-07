<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_playlist_writer.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: devices.php'); exit; }
csrf_check();

$deviceId = (int)($_POST['device_id'] ?? 0);
$weekday  = strtolower((string)($_POST['weekday'] ?? ''));
$weekdays = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
if (!$deviceId || !in_array($weekday, $weekdays, true)) {
    flash_set('Bad request — missing device or weekday.', 'error');
    header('Location: devices.php'); exit;
}
$itemOrder  = $_POST['item_order'] ?? []; // array of keys in order (existing IDs or "new_*" placeholders)
$itemsRaw   = $_POST['items'] ?? [];
$copyToAll  = !empty($_POST['copy_to_all']);
$targetDays = $copyToAll ? $weekdays : [$weekday];

// Background music for this weekday — settings (volume + duck level) plus
// an ordered list of one-or-more audio tracks that the player loops through.
$bgVolPct    = (int)($_POST['bg_audio_volume_pct'] ?? 100);
if ($bgVolPct < 0 || $bgVolPct > 100) $bgVolPct = 100;
$bgVolume    = round($bgVolPct / 100.0, 2);
$bgDuckPct   = (int)($_POST['bg_audio_duck_pct'] ?? 20);
if ($bgDuckPct < 0 || $bgDuckPct > 100) $bgDuckPct = 20;
$bgDuckVolume = round($bgDuckPct / 100.0, 2);
$bgItemOrder = $_POST['bg_item_order'] ?? [];
$bgItemsRaw  = $_POST['bg_items'] ?? [];

$pdo = admin_pdo();

// Pre-flight: check whether the bg-audio migrations have been applied. The
// editor still works without them — we just skip the bg-related writes
// and let the user know what's missing. Avoids a transaction-level crash
// half-way through the save.
$hasBgSettings = false;
$hasBgItems    = false;
$hasDuckColumn = false;
try {
    $pdo->query('SELECT 1 FROM device_bg_audio LIMIT 0');
    $hasBgSettings = true;
    try {
        $pdo->query('SELECT video_duck_volume FROM device_bg_audio LIMIT 0');
        $hasDuckColumn = true;
    } catch (PDOException $_) { /* migration 003 not applied */ }
} catch (PDOException $_) { /* migration 002 not applied */ }
$hasBgItemPerTrackVol = false;
try {
    $pdo->query('SELECT 1 FROM device_bg_audio_items LIMIT 0');
    $hasBgItems = true;
    try {
        $pdo->query('SELECT volume, video_duck_volume FROM device_bg_audio_items LIMIT 0');
        $hasBgItemPerTrackVol = true;
    } catch (PDOException $_) { /* migration 005 not applied */ }
} catch (PDOException $_) { /* migration 004 not applied */ }

// playlist_items.start_hour / end_hour (migration 009). Pre-009
// installs fall back to all-day persistence.
$hasItemHourRange = false;
try {
    $pdo->query('SELECT start_hour, end_hour FROM playlist_items LIMIT 0');
    $hasItemHourRange = true;
} catch (PDOException $_) { /* migration 009 not applied */ }

$pdo->beginTransaction();

try {
    // Replace target weekday playlists atomically. When "copy to all days" is
    // set we wipe and rewrite all 7 weekdays from the same posted item list.
    $del = $pdo->prepare('DELETE FROM playlist_items WHERE device_id = ? AND weekday = ?');
    foreach ($targetDays as $day) {
        $del->execute([$deviceId, $day]);
    }

    // Convert a 0-100 percentage from the form to a 0.00-1.00 decimal for
    // the DB. Empty / out-of-range values become NULL so the player's
    // fallbacks kick in (per-day default for bg, globalVolume for items).
    $pctToDecimal = function ($raw) {
        if ($raw === '' || $raw === null) return null;
        $pct = (int)$raw;
        if ($pct < 0 || $pct > 100) return null;
        return round($pct / 100.0, 2);
    };

    // Background music: gated on migrations 002/003/004/005 having been
    // applied. Each table/column is checked independently so partial
    // migrations still save what they can. The pre-flight check above set
    // $hasBgSettings, $hasDuckColumn, $hasBgItems, $hasBgItemPerTrackVol.
    if (!is_array($bgItemOrder) || !$bgItemOrder) $bgItemOrder = array_keys($bgItemsRaw);

    if ($hasBgSettings) {
        $delBg = $pdo->prepare('DELETE FROM device_bg_audio WHERE device_id = ? AND weekday = ?');
        // Build the INSERT to match whichever columns actually exist.
        if ($hasDuckColumn) {
            $insBg = $pdo->prepare(
                'INSERT INTO device_bg_audio (device_id, weekday, audio_media_id, volume, video_duck_volume)
                 VALUES (?, ?, NULL, ?, ?)'
            );
        } else {
            $insBg = $pdo->prepare(
                'INSERT INTO device_bg_audio (device_id, weekday, audio_media_id, volume)
                 VALUES (?, ?, NULL, ?)'
            );
        }
        foreach ($targetDays as $day) {
            $delBg->execute([$deviceId, $day]);
            if ($hasDuckColumn) {
                $insBg->execute([$deviceId, $day, $bgVolume, $bgDuckVolume]);
            } else {
                $insBg->execute([$deviceId, $day, $bgVolume]);
            }
        }
    }

    if ($hasBgItems) {
        $delBgItems = $pdo->prepare('DELETE FROM device_bg_audio_items WHERE device_id = ? AND weekday = ?');
        if ($hasBgItemPerTrackVol) {
            $insBgItem = $pdo->prepare(
                'INSERT INTO device_bg_audio_items (device_id, weekday, audio_media_id, volume, video_duck_volume, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?)'
            );
        } else {
            $insBgItem = $pdo->prepare(
                'INSERT INTO device_bg_audio_items (device_id, weekday, audio_media_id, sort_order)
                 VALUES (?, ?, ?, ?)'
            );
        }
        foreach ($targetDays as $day) {
            $delBgItems->execute([$deviceId, $day]);
            $sort = 0;
            foreach ($bgItemOrder as $key) {
                $row = $bgItemsRaw[$key] ?? null;
                if (!is_array($row)) continue;
                $audioId = (int)($row['audio_media_id'] ?? 0);
                if (!$audioId) continue;
                if ($hasBgItemPerTrackVol) {
                    // Per-track Vol% / VidVol% from the form. Empty/out-of-range
                    // becomes NULL so the player falls back to per-day defaults.
                    $rowVol  = $pctToDecimal($row['volume_pct'] ?? null);
                    $rowDuck = $pctToDecimal($row['duck_pct']   ?? null);
                    $insBgItem->execute([$deviceId, $day, $audioId, $rowVol, $rowDuck, $sort++]);
                } else {
                    $insBgItem->execute([$deviceId, $day, $audioId, $sort++]);
                }
            }
        }
    }

    if (!is_array($itemOrder) || !$itemOrder) $itemOrder = array_keys($itemsRaw);
    if ($hasItemHourRange) {
        $ins = $pdo->prepare(
            'INSERT INTO playlist_items
                (device_id, weekday, media_id, sort_order, duration_seconds,
                 audio_media_id, video_volume, audio_volume,
                 start_hour, end_hour)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO playlist_items
                (device_id, weekday, media_id, sort_order, duration_seconds,
                 audio_media_id, video_volume, audio_volume)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
    }
    foreach ($targetDays as $day) {
        $sort = 0;
        foreach ($itemOrder as $key) {
            $row = $itemsRaw[$key] ?? null;
            if (!is_array($row)) continue;
            $mediaId = (int)($row['media_id'] ?? 0);
            if (!$mediaId) continue;
            $duration = max(1, (int)($row['duration_seconds'] ?? 10));
            $audioId  = (int)($row['audio_media_id'] ?? 0) ?: null;
            $videoVol = $pctToDecimal($row['video_volume_pct'] ?? null);
            $audioVol = $pctToDecimal($row['audio_volume_pct'] ?? null);

            if ($hasItemHourRange) {
                // Clamp + sanity-check the hour window. start must be in
                // [0,23], end in [1,24], and start<end. Anything weird
                // collapses to the all-day default 0..24 so a typo
                // doesn't silently hide an item.
                $sh = (int)($row['start_hour'] ?? 0);
                $eh = (int)($row['end_hour']   ?? 24);
                if ($sh < 0 || $sh > 23 || $eh < 1 || $eh > 24 || $sh >= $eh) {
                    $sh = 0; $eh = 24;
                }
                $ins->execute([
                    $deviceId, $day, $mediaId, $sort++, $duration,
                    $audioId, $videoVol, $audioVol,
                    $sh, $eh,
                ]);
            } else {
                $ins->execute([
                    $deviceId, $day, $mediaId, $sort++, $duration,
                    $audioId, $videoVol, $audioVol,
                ]);
            }
        }
    }

    $pdo->commit();
} catch (Throwable $t) {
    $pdo->rollBack();
    $msg = $t->getMessage();
    // Catch the most common cause and tell the user what to do about it.
    if (strpos($msg, "doesn't exist") !== false || strpos($msg, "Unknown column") !== false) {
        flash_set("Save failed: $msg — apply the pending migration files in cloudserver/migrations/ then retry.", 'error');
    } else {
        flash_set('Save failed: ' . $msg, 'error');
    }
    header('Location: playlist_editor.php?device_id=' . $deviceId . '&day=' . $weekday);
    exit;
}

// Regenerate playlist.json. This used to be called bare and any throw
// (e.g. a missing table from an unapplied migration) bubbled out as an
// uncaught HTTP 500. Wrap in try/catch so the user sees a flash message
// they can actually act on instead of a blank error page.
try {
    if (!playlist_regenerate($deviceId)) {
        flash_set('Items saved, but playlist.json could NOT be written. Check folder permissions on devices/.', 'error');
    } else {
        flash_set($copyToAll ? 'Playlist saved and copied to all 7 days.' : 'Playlist saved and published.', 'ok');
    }
} catch (Throwable $t) {
    flash_set('Items saved, but playlist.json regeneration failed: ' . $t->getMessage()
        . ' — likely a pending DB migration. Apply cloudserver/migrations/00X_*.sql then re-save.', 'error');
}

header('Location: playlist_editor.php?device_id=' . $deviceId . '&day=' . $weekday);
