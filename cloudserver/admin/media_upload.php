<?php
require __DIR__ . '/_auth.php';
admin_require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { header('Location: media.php'); exit; }
csrf_check();

$cfg     = admin_config();
$mediaDir = $cfg['paths']['media_dir'];
$maxBytes = (int)$cfg['uploads']['max_bytes'];
$imgMimes = $cfg['uploads']['image_mimes'];
$vidMimes = $cfg['uploads']['video_mimes'];
$audMimes = $cfg['uploads']['audio_mimes'];

if (!is_dir($mediaDir) && !@mkdir($mediaDir, 0755, true)) {
    flash_set('Media directory does not exist and could not be created: ' . $mediaDir, 'error');
    header('Location: media.php'); exit;
}

$files = $_FILES['files'] ?? null;
if (!$files || empty($files['name'][0])) {
    flash_set('No files selected.', 'warn');
    header('Location: media.php'); exit;
}

$pdo = admin_pdo();
$ok = 0; $fail = [];

$count = count($files['name']);
for ($i = 0; $i < $count; $i++) {
    $errCode = (int)$files['error'][$i];
    $name    = (string)$files['name'][$i];
    $tmp     = (string)$files['tmp_name'][$i];
    $size    = (int)$files['size'][$i];

    if ($errCode !== UPLOAD_ERR_OK) {
        $fail[] = "$name: upload error code $errCode";
        continue;
    }
    if ($size <= 0 || $size > $maxBytes) {
        $fail[] = "$name: size " . $size . " out of bounds";
        continue;
    }
    if (!is_uploaded_file($tmp)) {
        $fail[] = "$name: not an uploaded file";
        continue;
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = $finfo->file($tmp) ?: '';
    if (in_array($mime, $imgMimes, true))      $type = 'image';
    elseif (in_array($mime, $vidMimes, true))  $type = 'video';
    elseif (in_array($mime, $audMimes, true))  $type = 'audio';
    else { $fail[] = "$name: rejected MIME $mime"; continue; }

    $hash = hash_file('sha256', $tmp);

    // Dedup: if a file with this hash exists, reuse the row.
    $existing = $pdo->prepare('SELECT id, filename FROM media WHERE file_hash = ? LIMIT 1');
    $existing->execute([$hash]);
    if ($existing->fetch()) {
        $fail[] = "$name: duplicate of an existing file (skipped)";
        continue;
    }

    $ext     = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: '');
    $base    = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($name, PATHINFO_FILENAME));
    $base    = substr($base, 0, 60) ?: 'file';
    $suffix  = substr($hash, 0, 8);
    $newName = $base . '_' . $suffix . ($ext ? ('.' . $ext) : '');

    $target  = $mediaDir . '/' . $newName;
    if (!move_uploaded_file($tmp, $target)) {
        $fail[] = "$name: failed to move into media dir";
        continue;
    }

    // Uploads are stored as-is. Images can be downscaled later from the
    // media library via the per-image "Optimise" button (or the bulk
    // "Re-optimise all images" maintenance action). We don't touch the
    // bytes here so the operator stays in control of when an image gets
    // rewritten.

    $pdo->prepare(
        'INSERT INTO media (filename, original_name, file_hash, mime_type, file_type, file_size_bytes, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    )->execute([$newName, $name, $hash, $mime, $type, $size, $_SESSION['admin'] ?? null]);
    $ok++;
}

if ($ok) flash_set("$ok file(s) uploaded.", 'ok');
if ($fail) flash_set('Some files were skipped: ' . implode('; ', $fail), 'warn');

header('Location: media.php');
