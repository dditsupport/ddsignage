<?php
/**
 * Re-runs image_optimize_for_signage() across image files in the media
 * library. Two modes, picked by the presence of a POST 'id' field:
 *
 *   - POST id=<media_id>  → optimise that single image (per-row button
 *                            on media.php). This is the day-to-day flow,
 *                            since uploads no longer auto-optimise.
 *   - POST id absent      → optimise every image (bulk maintenance, from
 *                            the "Re-optimise all images" button).
 *
 * Idempotent: an already-optimised image (≤ 2 MB and within the bounding
 * box) is detected and skipped without re-encoding.
 */

require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_image.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: media.php'); exit; }
csrf_check();

$cfg     = admin_config();
$mediaDir = rtrim((string)($cfg['paths']['media_dir'] ?? ''), '/');
$pdo = admin_pdo();

$singleId = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($singleId > 0) {
    $stmt = $pdo->prepare("SELECT id, filename, mime_type FROM media WHERE id = ? AND file_type = 'image'");
    $stmt->execute([$singleId]);
    $rows = $stmt->fetchAll();
    if (!$rows) {
        flash_set('Selected media not found, or it is not an image.', 'error');
        header('Location: media.php'); exit;
    }
} else {
    $rows = $pdo->query("SELECT id, filename, mime_type FROM media WHERE file_type = 'image'")->fetchAll();
}

$updated = 0; $skipped = 0; $missing = 0;
$bytesSaved = 0;
$update = $pdo->prepare('UPDATE media SET file_size_bytes = ? WHERE id = ?');

foreach ($rows as $r) {
    $path = $mediaDir . '/' . $r['filename'];
    if (!is_file($path)) { $missing++; continue; }

    $before = (int)@filesize($path);
    $changed = image_optimize_for_signage($path, (string)$r['mime_type']);
    if ($changed) {
        $after = (int)@filesize($path);
        $update->execute([$after, (int)$r['id']]);
        $bytesSaved += max(0, $before - $after);
        $updated++;
    } else {
        $skipped++;
    }
}

flash_set(
    "Re-optimised $updated image(s); skipped $skipped already-OK; $missing missing on disk. Saved " .
        number_format($bytesSaved / (1024 * 1024), 1) . ' MB.',
    'ok'
);

// Bump mtime on every device's playlist.json so the player refetches and
// thereby re-renders the now-smaller files (their cache-bust ?v=mtime is
// tied to the media file's mtime, which changed). Skip when nothing was
// rewritten — a no-op optimise has no downstream effect to propagate.
if ($updated > 0) {
    require __DIR__ . '/_playlist_writer.php';
    foreach ($pdo->query('SELECT id FROM devices')->fetchAll() as $d) {
        @playlist_regenerate((int)$d['id']);
    }
}

header('Location: media.php');
