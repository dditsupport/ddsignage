<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_layout.php';

$pdo = admin_pdo();

// poll_interval_seconds added in migration 006; tolerate its absence so the
// dashboard still loads on installs that haven't applied 006 yet.
try {
    $rows = $pdo->query(
        'SELECT d.id, d.android_id, d.mac_address, d.display_name, d.app_version,
                d.last_seen, d.last_ip, d.assigned, d.force_refresh,
                d.poll_interval_seconds,
                d.device_model, d.android_version,
                s.name AS store_name, c.name AS city_name
           FROM devices d
           LEFT JOIN stores s ON s.id = d.store_id
           LEFT JOIN cities c ON c.id = s.city_id
          ORDER BY d.assigned ASC, c.name, s.name, d.display_name, d.android_id'
    )->fetchAll();
} catch (PDOException $_) {
    $rows = $pdo->query(
        'SELECT d.id, d.android_id, d.mac_address, d.display_name, d.app_version,
                d.last_seen, d.last_ip, d.assigned, d.force_refresh,
                NULL AS poll_interval_seconds,
                d.device_model, d.android_version,
                s.name AS store_name, c.name AS city_name
           FROM devices d
           LEFT JOIN stores s ON s.id = d.store_id
           LEFT JOIN cities c ON c.id = s.city_id
          ORDER BY d.assigned ASC, c.name, s.name, d.display_name, d.android_id'
    )->fetchAll();
}

$unassigned = array_filter($rows, fn($r) => !$r['assigned'] || $r['store_name'] === null);
$assigned   = array_filter($rows, fn($r) =>  $r['assigned'] && $r['store_name'] !== null);

layout_header('Devices', 'devices');
?>
<h1 class="h3 mb-4">Devices</h1>

<?php if ($unassigned): ?>
<h2 class="h5 text-warning">Unassigned (<?= count($unassigned) ?>)</h2>
<p class="text-muted small">New devices reporting in. Click to assign each one to a store.</p>
<div class="table-responsive mb-4">
  <table class="table table-sm table-striped align-middle">
    <thead class="table-light">
      <tr><th>ANDROID_ID</th><th>MAC</th><th>Model</th><th>Version</th><th>Last seen</th><th></th></tr>
    </thead>
    <tbody>
      <?php foreach ($unassigned as $d): ?>
        <tr>
          <td><code><?= h($d['android_id']) ?></code></td>
          <td><code><?= h($d['mac_address'] ?: '—') ?></code></td>
          <td><?= h($d['device_model'] ?: '—') ?></td>
          <td><?= h($d['app_version'] ?: '—') ?></td>
          <td><?= h($d['last_seen'] ?: 'never') ?></td>
          <td><a class="btn btn-primary btn-sm" href="device_edit.php?id=<?= (int)$d['id'] ?>">Assign</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<h2 class="h5">Assigned (<?= count($assigned) ?>)</h2>
<div class="table-responsive">
  <table class="table table-sm align-middle">
    <thead class="table-light">
      <tr>
        <th>Status</th><th>City / Store</th><th>Display name</th>
        <th>ANDROID_ID</th><th>Version</th><th>Poll</th><th>Last seen</th><th></th>
      </tr>
    </thead>
    <tbody>
      <?php
      // Player pings every 5 min, so anything older than 5 min is "offline".
      // Use 330s (5 min + 30s slack) so a slightly-late ping doesn't blink the
      // dot every cycle.
      $offlineCutoff = time() - 330;
      foreach ($assigned as $d):
        $lastSeenTs = $d['last_seen'] ? strtotime($d['last_seen']) : 0;
        $online     = $lastSeenTs >= $offlineCutoff;
        $dot        = $online ? '🟢' : '⚫';
        $offlineFor = $online ? '' : (
          $lastSeenTs ? ' (' . human_ago(time() - $lastSeenTs) . ' ago)' : ' (never seen)'
        );
      ?>
        <tr<?= $online ? '' : ' class="text-muted"' ?>>
          <td><?= $dot ?><span class="small"><?= h($offlineFor) ?></span></td>
          <td><?= h($d['city_name']) ?> / <?= h($d['store_name']) ?></td>
          <td><?= h($d['display_name'] ?: '—') ?></td>
          <td><code><?= h($d['android_id']) ?></code></td>
          <td><?= h($d['app_version'] ?: '—') ?></td>
          <td><?= $d['poll_interval_seconds'] !== null ? (int)$d['poll_interval_seconds'] . 's' : '—' ?></td>
          <td><?= h($d['last_seen'] ?: 'never') ?></td>
          <td>
            <a class="btn btn-sm btn-outline-primary" href="device_edit.php?id=<?= (int)$d['id'] ?>">Edit</a>
            <a class="btn btn-sm btn-outline-success" href="playlist_editor.php?device_id=<?= (int)$d['id'] ?>">Playlist</a>
            <form method="post" action="device_force_refresh.php" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="device_id" value="<?= (int)$d['id'] ?>">
              <button class="btn btn-sm btn-outline-secondary" <?= $d['force_refresh'] ? 'disabled' : '' ?>>
                <?= $d['force_refresh'] ? 'Pending…' : 'Force refresh' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$assigned): ?>
        <tr><td colspan="8" class="text-muted text-center py-3">No assigned devices yet.</td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>
<?php layout_footer();
