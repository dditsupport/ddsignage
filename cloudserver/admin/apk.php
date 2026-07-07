<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_layout.php';

$pdo = admin_pdo();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    if ($action === 'set_current') {
        $id = (int)$_POST['id'];
        $pdo->prepare('UPDATE apk_versions SET is_current = 0')->execute();
        $pdo->prepare('UPDATE apk_versions SET is_current = 1 WHERE id = ?')->execute([$id]);
        flash_set('Marked as current. Devices will pick up the update on their daily check.', 'ok');
    } elseif ($action === 'delete') {
        $id = (int)$_POST['id'];
        $row = $pdo->prepare('SELECT apk_url FROM apk_versions WHERE id = ?');
        $row->execute([$id]);
        $r = $row->fetch();
        if ($r) {
            // Best-effort: only remove the local file if apk_url points inside our /apk dir.
            $cfg = admin_config();
            $localPrefix = rtrim((string)($cfg['public_base_url'] ?? ''), '/') . '/apk/';
            if (str_starts_with($r['apk_url'], $localPrefix)) {
                $local = $cfg['paths']['apk_dir'] . '/' . substr($r['apk_url'], strlen($localPrefix));
                if (is_file($local)) @unlink($local);
            }
        }
        $pdo->prepare('DELETE FROM apk_versions WHERE id = ?')->execute([$id]);
        flash_set('Version removed.', 'ok');
    }
    header('Location: apk.php');
    exit;
}

$versions = $pdo->query('SELECT * FROM apk_versions ORDER BY version_code DESC')->fetchAll();

// Distribution: how many devices on each version?
$dist = $pdo->query(
    'SELECT app_version, COUNT(*) AS n FROM devices
      WHERE app_version IS NOT NULL AND app_version <> ""
      GROUP BY app_version ORDER BY n DESC'
)->fetchAll();

layout_header('APK management', 'apk');
?>
<h1 class="h3 mb-4">APK management</h1>

<div class="card mb-4"><div class="card-body">
  <h2 class="h6">Upload new APK</h2>
  <form method="post" action="apk_upload.php" enctype="multipart/form-data" class="row g-2">
    <?= csrf_field() ?>
    <div class="col-md-3"><input class="form-control" name="version_name" placeholder="Version name (e.g. 1.1.0)" required></div>
    <div class="col-md-2"><input class="form-control" name="version_code" type="number" min="1" placeholder="Version code" required></div>
    <div class="col-md-3"><input class="form-control" type="file" name="apk" accept=".apk" required></div>
    <div class="col-md-3"><input class="form-control" name="release_notes" placeholder="Release notes (optional)"></div>
    <div class="col-md-1"><button class="btn btn-primary w-100">Upload</button></div>
  </form>
</div></div>

<h2 class="h5">Versions</h2>
<table class="table table-sm align-middle">
  <thead class="table-light"><tr><th>Current</th><th>Version</th><th>Code</th><th>Notes</th><th>Uploaded</th><th>URL</th><th></th></tr></thead>
  <tbody>
    <?php foreach ($versions as $v): ?>
      <tr>
        <td><?= $v['is_current'] ? '<span class="badge bg-success">CURRENT</span>' : '' ?></td>
        <td><strong><?= h($v['version_name']) ?></strong></td>
        <td><?= (int)$v['version_code'] ?></td>
        <td><?= h(mb_strimwidth($v['release_notes'] ?? '', 0, 80, '…')) ?></td>
        <td><?= h($v['uploaded_at']) ?></td>
        <td><a href="<?= h($v['apk_url']) ?>" target="_blank" class="small text-truncate d-inline-block" style="max-width: 250px;"><?= h($v['apk_url']) ?></a></td>
        <td class="text-end">
          <?php if (!$v['is_current']): ?>
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="set_current">
              <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
              <button class="btn btn-sm btn-outline-success">Make current</button>
            </form>
          <?php endif; ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Delete version <?= h($v['version_name']) ?>?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
            <button class="btn btn-sm btn-outline-danger" <?= $v['is_current'] ? 'disabled' : '' ?>>Delete</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$versions): ?>
      <tr><td colspan="7" class="text-muted text-center">No APK versions uploaded yet.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<h2 class="h5 mt-4">Version distribution across fleet</h2>
<?php if (!$dist): ?>
  <p class="text-muted">No devices have reported a version yet.</p>
<?php else: ?>
<table class="table table-sm" style="max-width: 480px;">
  <thead class="table-light"><tr><th>Version</th><th>Devices</th></tr></thead>
  <tbody>
    <?php foreach ($dist as $r): ?>
      <tr><td><?= h($r['app_version']) ?></td><td><?= (int)$r['n'] ?></td></tr>
    <?php endforeach; ?>
  </tbody>
</table>
<?php endif; ?>
<?php layout_footer();
