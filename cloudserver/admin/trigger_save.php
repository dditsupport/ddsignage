<?php
/**
 * Inserts a single time-of-day trigger for a device + weekday.
 *
 * Triggers fire once at the assigned local time on the matching weekday;
 * the player interrupts the loop, plays the chosen media to its natural
 * end, then resumes. This endpoint is one trigger per submit by design —
 * the editor lists existing triggers and lets the user delete + re-add.
 */

require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_playlist_writer.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: devices.php'); exit; }
csrf_check();

$deviceId    = (int)($_POST['device_id'] ?? 0);
$weekday     = strtolower((string)($_POST['weekday'] ?? ''));
$triggerTime = trim((string)($_POST['trigger_time'] ?? '')); // HTML5 time input → "HH:MM"
$mediaId     = (int)($_POST['media_id'] ?? 0);
$volPct      = (int)($_POST['volume_pct'] ?? 100);

$weekdays = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];

if (!$deviceId || !in_array($weekday, $weekdays, true) || !$mediaId
    || !preg_match('/^\d{2}:\d{2}$/', $triggerTime)) {
    flash_set('Bad trigger input — need device, weekday, time (HH:MM), and media.', 'error');
    header('Location: playlist_editor.php?device_id=' . $deviceId . '&day=' . $weekday);
    exit;
}
if ($volPct < 0 || $volPct > 100) $volPct = 100;
$volume = round($volPct / 100.0, 2);

$pdo = admin_pdo();

// Confirm media exists AND is a triggerable type (audio/video). Triggers
// are intentionally restricted to time-bounded media — an image trigger
// would need an explicit duration we don't currently capture.
$mStmt = $pdo->prepare('SELECT file_type FROM media WHERE id = ?');
$mStmt->execute([$mediaId]);
$mType = $mStmt->fetchColumn();
if ($mType === false || !in_array($mType, ['audio', 'video'], true)) {
    flash_set('Trigger media must be audio or video.', 'error');
    header('Location: playlist_editor.php?device_id=' . $deviceId . '&day=' . $weekday);
    exit;
}

try {
    $ins = $pdo->prepare(
        'INSERT INTO playlist_triggers
            (device_id, weekday, trigger_time, media_id, volume)
         VALUES (?, ?, ?, ?, ?)'
    );
    $ins->execute([$deviceId, $weekday, $triggerTime . ':00', $mediaId, $volume]);
} catch (Throwable $t) {
    flash_set('Trigger save failed: ' . $t->getMessage(), 'error');
    header('Location: playlist_editor.php?device_id=' . $deviceId . '&day=' . $weekday);
    exit;
}

if (!playlist_regenerate($deviceId)) {
    flash_set('Trigger added in DB, but playlist.json could NOT be written.', 'error');
} else {
    flash_set("Trigger added: $triggerTime on " . ucfirst($weekday) . '.', 'ok');
}
header('Location: playlist_editor.php?device_id=' . $deviceId . '&day=' . $weekday);
