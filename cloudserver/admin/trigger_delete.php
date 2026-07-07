<?php
/**
 * Removes a single time-of-day trigger and republishes playlist.json.
 */

require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_playlist_writer.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: devices.php'); exit; }
csrf_check();

$deviceId  = (int)($_POST['device_id'] ?? 0);
$weekday   = strtolower((string)($_POST['weekday'] ?? ''));
$triggerId = (int)($_POST['trigger_id'] ?? 0);

$weekdays = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
if (!$deviceId || !$triggerId || !in_array($weekday, $weekdays, true)) {
    flash_set('Bad delete request.', 'error');
    header('Location: playlist_editor.php?device_id=' . $deviceId . '&day=' . $weekday);
    exit;
}

$pdo = admin_pdo();
// Scope by device_id too — defence in depth so a forged trigger_id from
// another device's editor session can't delete cross-device.
$del = $pdo->prepare('DELETE FROM playlist_triggers WHERE id = ? AND device_id = ?');
$del->execute([$triggerId, $deviceId]);

if ($del->rowCount() === 0) {
    flash_set('Trigger not found (already deleted?).', 'warn');
} elseif (!playlist_regenerate($deviceId)) {
    flash_set('Trigger deleted, but playlist.json could NOT be written.', 'error');
} else {
    flash_set('Trigger deleted.', 'ok');
}
header('Location: playlist_editor.php?device_id=' . $deviceId . '&day=' . $weekday);
