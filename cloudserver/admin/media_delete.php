<?php
require __DIR__ . '/_auth.php';
admin_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: media.php'); exit; }
csrf_check();

$id = (int)($_POST['id'] ?? 0);
if (!$id) { header('Location: media.php'); exit; }

$pdo = admin_pdo();
$cfg = admin_config();

$row = $pdo->prepare('SELECT id, filename FROM media WHERE id = ?');
$row->execute([$id]);
$m = $row->fetch();
if (!$m) { flash_set('Media not found.', 'error'); header('Location: media.php'); exit; }

// Find which devices reference it; warn the operator before removing.
$refs = $pdo->prepare(
    'SELECT DISTINCT device_id FROM playlist_items WHERE media_id = ? OR audio_media_id = ?'
);
$refs->execute([$id, $id]);
$refDevices = $refs->fetchAll();

// Cascading FK on playlist_items.media_id removes references; for audio we
// have ON DELETE SET NULL. Disk file is removed separately.
$pdo->prepare('DELETE FROM media WHERE id = ?')->execute([$id]);

$disk = $cfg['paths']['media_dir'] . '/' . $m['filename'];
if (is_file($disk)) @unlink($disk);

if ($refDevices) {
    // Regenerate playlist.json for every device that referenced this media.
    require __DIR__ . '/_playlist_writer.php';
    foreach ($refDevices as $r) playlist_regenerate((int)$r['device_id']);
    flash_set('Media deleted. ' . count($refDevices) . ' playlist file(s) regenerated.', 'ok');
} else {
    flash_set('Media deleted.', 'ok');
}

header('Location: media.php');
