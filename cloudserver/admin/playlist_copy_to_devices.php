<?php
/**
 * Clones a source device's full playlist (items + bg music + triggers,
 * for all 7 days) onto one or more selected target devices. The targets'
 * existing playlists are wiped first so the copy is a true overwrite.
 *
 * Triggered from the modal on playlist_editor.php?device_id=<source>.
 *
 * Tolerates pre-migration installs the same way playlist_save.php does:
 * if a table or column doesn't exist on this database, that bit of the
 * copy is silently skipped instead of crashing the whole transaction.
 */

require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_playlist_writer.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: devices.php'); exit; }
csrf_check();

$sourceId = (int)($_POST['source_device_id'] ?? 0);
$targets  = $_POST['target_device_ids'] ?? [];
if (!is_array($targets)) $targets = [];
$targets = array_values(array_unique(array_filter(array_map('intval', $targets))));
// Refuse self-copy — the destination would just be a no-op overwrite of
// itself but it's almost certainly a UI mistake; better to flag it.
$targets = array_values(array_filter($targets, fn ($t) => $t !== $sourceId));

if (!$sourceId || !$targets) {
    flash_set('Bad copy request — need a source device and at least one different target.', 'error');
    header('Location: playlist_editor.php?device_id=' . $sourceId);
    exit;
}

$pdo = admin_pdo();

// Verify source + targets all exist before touching anything.
$placeholders = implode(',', array_fill(0, count($targets), '?'));
$check = $pdo->prepare("SELECT id FROM devices WHERE id IN ($placeholders)");
$check->execute($targets);
$existingIds = array_map('intval', array_column($check->fetchAll(), 'id'));
$missing = array_diff($targets, $existingIds);
if ($missing) {
    flash_set('Some target devices no longer exist: ' . implode(', ', $missing), 'error');
    header('Location: playlist_editor.php?device_id=' . $sourceId);
    exit;
}
$srcCheck = $pdo->prepare('SELECT id FROM devices WHERE id = ?');
$srcCheck->execute([$sourceId]);
if (!$srcCheck->fetch()) {
    flash_set('Source device not found.', 'error');
    header('Location: devices.php');
    exit;
}

// Pre-flight: which optional tables / columns are present?
// Same pattern as playlist_save.php so this script works across migrations.
$has = ['bg' => false, 'duck' => false, 'bg_items' => false, 'bg_items_perVol' => false, 'triggers' => false];
try { $pdo->query('SELECT 1 FROM device_bg_audio LIMIT 0'); $has['bg'] = true;
    try { $pdo->query('SELECT video_duck_volume FROM device_bg_audio LIMIT 0'); $has['duck'] = true; } catch (PDOException $_) {}
} catch (PDOException $_) {}
try { $pdo->query('SELECT 1 FROM device_bg_audio_items LIMIT 0'); $has['bg_items'] = true;
    try { $pdo->query('SELECT volume, video_duck_volume FROM device_bg_audio_items LIMIT 0'); $has['bg_items_perVol'] = true; } catch (PDOException $_) {}
} catch (PDOException $_) {}
try { $pdo->query('SELECT 1 FROM playlist_triggers LIMIT 0'); $has['triggers'] = true; } catch (PDOException $_) {}

$pdo->beginTransaction();
try {
    // Prepare the per-table delete + insert-from-source pairs once;
    // execute per-target. Each statement is parameterised on (?targetId,
    // ?sourceId) where applicable.
    $delItems   = $pdo->prepare('DELETE FROM playlist_items WHERE device_id = ?');
    $insItems   = $pdo->prepare(
        'INSERT INTO playlist_items
            (device_id, weekday, media_id, sort_order, duration_seconds,
             audio_media_id, video_volume, audio_volume)
         SELECT ?, weekday, media_id, sort_order, duration_seconds,
                audio_media_id, video_volume, audio_volume
           FROM playlist_items WHERE device_id = ?'
    );

    $delBg = $insBg = null;
    if ($has['bg']) {
        $delBg = $pdo->prepare('DELETE FROM device_bg_audio WHERE device_id = ?');
        if ($has['duck']) {
            $insBg = $pdo->prepare(
                'INSERT INTO device_bg_audio (device_id, weekday, audio_media_id, volume, video_duck_volume)
                 SELECT ?, weekday, audio_media_id, volume, video_duck_volume
                   FROM device_bg_audio WHERE device_id = ?'
            );
        } else {
            $insBg = $pdo->prepare(
                'INSERT INTO device_bg_audio (device_id, weekday, audio_media_id, volume)
                 SELECT ?, weekday, audio_media_id, volume
                   FROM device_bg_audio WHERE device_id = ?'
            );
        }
    }

    $delBgItems = $insBgItems = null;
    if ($has['bg_items']) {
        $delBgItems = $pdo->prepare('DELETE FROM device_bg_audio_items WHERE device_id = ?');
        if ($has['bg_items_perVol']) {
            $insBgItems = $pdo->prepare(
                'INSERT INTO device_bg_audio_items
                    (device_id, weekday, audio_media_id, sort_order, volume, video_duck_volume)
                 SELECT ?, weekday, audio_media_id, sort_order, volume, video_duck_volume
                   FROM device_bg_audio_items WHERE device_id = ?'
            );
        } else {
            $insBgItems = $pdo->prepare(
                'INSERT INTO device_bg_audio_items
                    (device_id, weekday, audio_media_id, sort_order)
                 SELECT ?, weekday, audio_media_id, sort_order
                   FROM device_bg_audio_items WHERE device_id = ?'
            );
        }
    }

    $delTrig = $insTrig = null;
    if ($has['triggers']) {
        $delTrig = $pdo->prepare('DELETE FROM playlist_triggers WHERE device_id = ?');
        $insTrig = $pdo->prepare(
            'INSERT INTO playlist_triggers
                (device_id, weekday, trigger_time, media_id, volume)
             SELECT ?, weekday, trigger_time, media_id, volume
               FROM playlist_triggers WHERE device_id = ?'
        );
    }

    foreach ($targets as $targetId) {
        // Wipe target's current playlist first — we want a clean overwrite,
        // not a merge. Order of deletes doesn't matter (no FK between them).
        $delItems->execute([$targetId]);
        if ($delBg)       $delBg->execute([$targetId]);
        if ($delBgItems)  $delBgItems->execute([$targetId]);
        if ($delTrig)     $delTrig->execute([$targetId]);

        // Clone source rows over.
        $insItems->execute([$targetId, $sourceId]);
        if ($insBg)       $insBg->execute([$targetId, $sourceId]);
        if ($insBgItems)  $insBgItems->execute([$targetId, $sourceId]);
        if ($insTrig)     $insTrig->execute([$targetId, $sourceId]);
    }

    $pdo->commit();
} catch (Throwable $t) {
    $pdo->rollBack();
    flash_set('Copy failed: ' . $t->getMessage(), 'error');
    header('Location: playlist_editor.php?device_id=' . $sourceId);
    exit;
}

// Republish playlist.json for each target so the changes propagate to
// the boxes on their next playlist poll. Errors here are non-fatal —
// the DB is already updated; a manual save on the target would re-trigger.
$regenFailed = [];
foreach ($targets as $targetId) {
    try {
        if (!playlist_regenerate($targetId)) $regenFailed[] = $targetId;
    } catch (Throwable $t) {
        $regenFailed[] = $targetId;
        error_log("playlist_copy_to_devices: regen failed for device $targetId: " . $t->getMessage());
    }
}

$count = count($targets);
$msg = "Playlist copied from device $sourceId to $count device(s).";
if ($regenFailed) {
    $msg .= ' BUT playlist.json regeneration failed for: ' . implode(', ', $regenFailed)
          . ' — a manual Save on each will retry.';
    flash_set($msg, 'warn');
} else {
    flash_set($msg, 'ok');
}

header('Location: playlist_editor.php?device_id=' . $sourceId);
