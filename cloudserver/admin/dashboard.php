<?php
// TEMP DIAGNOSTIC — surfaces fatal/PDO errors to the page so we can see why
// the dashboard 500s on aromen.biz. Remove after the issue is fixed.
error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_layout.php';

try {
    $pdo = admin_pdo();

    $totalDevices    = (int)$pdo->query('SELECT COUNT(*) FROM devices')->fetchColumn();
    $onlineDevices   = (int)$pdo->query("SELECT COUNT(*) FROM devices WHERE last_seen >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)")->fetchColumn();
    $unassigned      = (int)$pdo->query('SELECT COUNT(*) FROM devices WHERE assigned = 0 OR store_id IS NULL')->fetchColumn();
    $totalMedia      = (int)$pdo->query('SELECT COUNT(*) FROM media')->fetchColumn();
    $totalSize       = (int)$pdo->query('SELECT COALESCE(SUM(file_size_bytes), 0) FROM media')->fetchColumn();
    $crashes24h      = (int)$pdo->query("SELECT COUNT(*) FROM crashes WHERE received_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetchColumn();

    $recentDevices = $pdo->query(
        'SELECT d.android_id, d.display_name, d.last_seen, d.app_version, d.assigned,
                d.media_cached_count, d.media_total_count,
                s.name AS store_name, c.name AS city_name
           FROM devices d
           LEFT JOIN stores s ON s.id = d.store_id
           LEFT JOIN cities c ON c.id = s.city_id
          ORDER BY d.last_seen DESC
          LIMIT 10'
    )->fetchAll();
} catch (Throwable $t) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<pre style="margin:20px;padding:20px;background:#fee;color:#900;font-family:monospace;white-space:pre-wrap;">';
    echo "Dashboard load failed.\n\n";
    echo 'Type:    ' . get_class($t) . "\n";
    echo 'Message: ' . htmlspecialchars($t->getMessage(), ENT_QUOTES, 'UTF-8') . "\n";
    echo 'File:    ' . htmlspecialchars($t->getFile(), ENT_QUOTES, 'UTF-8') . ':' . $t->getLine() . "\n\n";
    echo "Trace:\n" . htmlspecialchars($t->getTraceAsString(), ENT_QUOTES, 'UTF-8');
    echo '</pre>';
    exit;
}

function bytes_h(int $b): string {
    if ($b < 1024) return $b . ' B';
    if ($b < 1024**2) return number_format($b/1024, 1) . ' KB';
    if ($b < 1024**3) return number_format($b/1024/1024, 1) . ' MB';
    return number_format($b/1024/1024/1024, 2) . ' GB';
}

layout_header('Dashboard', 'dashboard');
?>
<h1 class="h3 mb-4">Dashboard</h1>
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card"><div class="card-body">
      <div class="text-muted small">Total devices</div>
      <div class="h2 mb-0"><?= $totalDevices ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card"><div class="card-body">
      <div class="text-muted small">Online (last 10 min)</div>
      <div class="h2 mb-0 text-success"><?= $onlineDevices ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card"><div class="card-body">
      <div class="text-muted small">Unassigned</div>
      <div class="h2 mb-0 text-warning"><?= $unassigned ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card"><div class="card-body">
      <div class="text-muted small">Crashes (24h)</div>
      <div class="h2 mb-0 <?= $crashes24h ? 'text-danger' : '' ?>"><?= $crashes24h ?></div>
    </div></div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="card"><div class="card-body">
      <div class="text-muted small">Media files</div>
      <div class="h4 mb-0"><?= $totalMedia ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card"><div class="card-body">
      <div class="text-muted small">Storage used</div>
      <div class="h4 mb-0"><?= h(bytes_h($totalSize)) ?></div>
    </div></div>
  </div>
</div>

<h2 class="h5 mb-3">Recently active devices</h2>
<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light">
      <tr>
        <th>ANDROID_ID</th><th>Name</th><th>City / Store</th>
        <th>Version</th><th>Media cache</th><th>Last seen</th><th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($recentDevices as $d): ?>
        <?php
          $isOnline = $d['last_seen'] && strtotime($d['last_seen']) >= time() - 600;
          $statusClass = $isOnline ? 'text-success' : 'text-secondary';
          $statusText = $isOnline ? 'online' : 'offline';

          // Media-cache completeness. null when the device has never
          // reported (just registered, or hasn't applied a playlist yet).
          $cN = isset($d['media_cached_count']) ? (int)$d['media_cached_count'] : null;
          $tN = isset($d['media_total_count'])  ? (int)$d['media_total_count']  : null;
          $cacheCell = '<span class="text-muted">—</span>';
          if ($tN !== null && $cN !== null) {
              if ($tN === 0) {
                  $cacheCell = '<span class="text-muted">no media</span>';
              } elseif ($cN >= $tN) {
                  $cacheCell = '<span class="text-success">✓ ' . $tN . '/' . $tN . '</span>';
              } else {
                  $cacheCell = '<span class="text-warning">' . $cN . '/' . $tN . '</span>';
              }
          }
        ?>
        <tr>
          <td><code><?= h($d['android_id']) ?></code></td>
          <td><?= h($d['display_name'] ?: '—') ?></td>
          <td><?= h(($d['city_name'] ?? '') . ($d['city_name'] && $d['store_name'] ? ' / ' : '') . ($d['store_name'] ?? '')) ?: '—' ?></td>
          <td><?= h($d['app_version'] ?: '—') ?></td>
          <td><?= $cacheCell /* pre-escaped */ ?></td>
          <td><?= h($d['last_seen'] ?: 'never') ?></td>
          <td class="<?= $statusClass ?>"><?= h($statusText) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$recentDevices): ?>
        <tr><td colspan="7" class="text-muted text-center py-3">No devices have registered yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php layout_footer();
