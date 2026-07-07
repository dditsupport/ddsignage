<?php
require __DIR__ . '/_auth.php';
admin_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: apk.php'); exit; }
csrf_check();

$cfg = admin_config();
$apkDir = $cfg['paths']['apk_dir'];

if (!is_dir($apkDir) && !@mkdir($apkDir, 0755, true)) {
    flash_set('APK directory not writable: ' . $apkDir, 'error');
    header('Location: apk.php'); exit;
}

$versionName = trim((string)($_POST['version_name'] ?? ''));
$versionCode = (int)($_POST['version_code'] ?? 0);
$notes       = trim((string)($_POST['release_notes'] ?? '')) ?: null;

if ($versionName === '' || $versionCode <= 0) {
    flash_set('Version name and version code are required.', 'error');
    header('Location: apk.php'); exit;
}

$file = $_FILES['apk'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
    flash_set('No APK uploaded or upload error.', 'error');
    header('Location: apk.php'); exit;
}
if (!is_uploaded_file($file['tmp_name'])) {
    flash_set('Refused: not an uploaded file.', 'error');
    header('Location: apk.php'); exit;
}

// Sanity check: APKs are ZIP files; the magic bytes should be PK\x03\x04.
$fp = fopen($file['tmp_name'], 'rb');
$magic = $fp ? fread($fp, 4) : '';
if ($fp) fclose($fp);
if ($magic !== "PK\x03\x04") {
    flash_set('Refused: file does not look like an APK (ZIP magic bytes missing).', 'error');
    header('Location: apk.php'); exit;
}

$safeName = 'signage_v' . preg_replace('/[^A-Za-z0-9._-]/', '_', $versionName) . '.apk';
$target   = $apkDir . '/' . $safeName;
if (!move_uploaded_file($file['tmp_name'], $target)) {
    flash_set('Failed to move APK into apk/ directory.', 'error');
    header('Location: apk.php'); exit;
}

$apkUrl = rtrim((string)($cfg['public_base_url'] ?? ''), '/') . '/apk/' . rawurlencode($safeName);

try {
    admin_pdo()->prepare(
        'INSERT INTO apk_versions (version_name, version_code, apk_url, release_notes, is_current)
         VALUES (?, ?, ?, ?, 0)'
    )->execute([$versionName, $versionCode, $apkUrl, $notes]);
    flash_set('Uploaded. Mark it as Current to start serving via version.php.', 'ok');
} catch (Throwable $t) {
    @unlink($target);
    flash_set('Upload rejected: ' . $t->getMessage(), 'error');
}

header('Location: apk.php');
