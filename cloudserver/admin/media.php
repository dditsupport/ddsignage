<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_layout.php';

$pdo = admin_pdo();

$filter = $_GET['type'] ?? '';
$validFilters = ['', 'image', 'video', 'audio'];
if (!in_array($filter, $validFilters, true)) $filter = '';

if ($filter) {
    $stmt = $pdo->prepare('SELECT * FROM media WHERE file_type = ? ORDER BY uploaded_at DESC');
    $stmt->execute([$filter]);
} else {
    $stmt = $pdo->query('SELECT * FROM media ORDER BY uploaded_at DESC');
}
$media = $stmt->fetchAll();

function bytes_h2(int $b): string {
    if ($b < 1024**2) return number_format($b/1024, 0) . ' KB';
    if ($b < 1024**3) return number_format($b/1024/1024, 1) . ' MB';
    return number_format($b/1024/1024/1024, 2) . ' GB';
}

layout_header('Media library', 'media');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3 mb-0">Media library</h1>
  <div class="btn-group">
    <a class="btn btn-sm btn-<?= $filter===''?'primary':'outline-secondary' ?>" href="media.php">All</a>
    <a class="btn btn-sm btn-<?= $filter==='image'?'primary':'outline-secondary' ?>" href="media.php?type=image">Images</a>
    <a class="btn btn-sm btn-<?= $filter==='video'?'primary':'outline-secondary' ?>" href="media.php?type=video">Videos</a>
    <a class="btn btn-sm btn-<?= $filter==='audio'?'primary':'outline-secondary' ?>" href="media.php?type=audio">Audio</a>
  </div>
</div>

<div class="card mb-4"><div class="card-body">
  <h2 class="h6">Upload</h2>
  <form method="post" action="media_upload.php" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
    <?= csrf_field() ?>
    <input class="form-control" type="file" name="files[]" multiple required
      accept="image/*,video/*,audio/mpeg,audio/mp3,audio/wav,audio/aac,audio/ogg">
    <button class="btn btn-primary">Upload</button>
  </form>
  <div class="form-text">
    Allowed: JPG/PNG/WebP/GIF, MP4/WebM/MKV/MOV, MP3/AAC/OGG/WAV.
    Max <?= h(number_format((admin_config()['uploads']['max_bytes'] ?? 0) / (1024 * 1024), 0)) ?> MB per file.<br>
    <strong>Recommended image size: 1920×1080 landscape (Full HD).</strong>
    Uploads are stored as-is — use the <em>Optimise</em> button on an
    image below to downscale + letterbox it to 1920×1080 when needed.
  </div>
  <hr>
  <form method="post" action="media_reoptimize.php" class="d-flex gap-2 align-items-center"
        onsubmit="return confirm('Re-shrink every image in the library to 1920x1080 / quality 85?\n\nUse this once, after uploading the fix, to repair pre-existing oversized images that show as a black screen on the player.');">
    <?= csrf_field() ?>
    <button class="btn btn-outline-warning btn-sm">Re-optimise all images</button>
    <span class="form-text mb-0">One-shot maintenance: shrinks any oversized image (e.g. a 20 MB DSLR JPEG) so the player can decode it. Idempotent.</span>
  </form>
</div></div>

<div class="row g-3">
  <?php foreach ($media as $m):
    $url = admin_public_url('media/' . $m['filename']);
    $isImg   = $m['file_type'] === 'image';
    $isVideo = $m['file_type'] === 'video';
  ?>
  <div class="col-sm-6 col-md-4 col-xl-3">
    <div class="card h-100">
      <?php if ($isImg): ?>
        <img class="card-img-top" loading="lazy" src="<?= h($url) ?>" alt="" style="object-fit: cover; height: 160px; background:#f4f4f4;">
      <?php elseif ($isVideo): ?>
        <video class="card-img-top" preload="metadata" muted style="object-fit: cover; height: 160px; background:#000;">
          <source src="<?= h($url) ?>">
        </video>
      <?php else: ?>
        <div class="card-img-top d-flex align-items-center justify-content-center" style="height:160px; background:#f4f4f4;">
          <span class="text-muted">🎵 audio</span>
        </div>
      <?php endif; ?>
      <div class="card-body">
        <div class="text-truncate" title="<?= h($m['original_name']) ?>"><strong><?= h($m['original_name']) ?></strong></div>
        <div class="small text-muted">
          <?= h($m['file_type']) ?> · <?= h(bytes_h2((int)$m['file_size_bytes'])) ?>
          <?= $m['duration_seconds'] ? ' · ' . (int)$m['duration_seconds'] . 's' : '' ?>
        </div>
        <div class="mt-2 d-flex gap-2 flex-wrap">
          <a class="btn btn-sm btn-outline-secondary" href="<?= h($url) ?>" target="_blank">Open</a>
          <?php if ($isImg): ?>
          <form method="post" action="media_reoptimize.php" class="d-inline"
                onsubmit="return confirm('Downscale + letterbox <?= h($m['original_name']) ?> to 1920×1080 (JPEG q85)?\n\nThis rewrites the file in place. Already-optimised images are skipped.');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button class="btn btn-sm btn-outline-warning" title="Resize to 1920×1080 + JPEG q85">Optimise</button>
          </form>
          <?php endif; ?>
          <form method="post" action="media_delete.php" class="d-inline" onsubmit="return confirm('Delete <?= h($m['original_name']) ?>? This will remove it from any playlists referencing it.');">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button class="btn btn-sm btn-outline-danger">Delete</button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
  <?php if (!$media): ?>
    <div class="col-12 text-muted text-center py-5">No media files yet. Upload some above.</div>
  <?php endif; ?>
</div>
<?php layout_footer();
