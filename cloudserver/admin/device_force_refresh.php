<?php
require __DIR__ . '/_auth.php';
admin_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: devices.php');
    exit;
}
csrf_check();

$id = (int)($_POST['device_id'] ?? 0);
if ($id) {
    admin_pdo()->prepare('UPDATE devices SET force_refresh = 1 WHERE id = ?')->execute([$id]);
    flash_set('Force refresh queued — device will pick it up on next ping (within 5 min).', 'ok');
}
header('Location: devices.php');
