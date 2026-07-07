<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_layout.php';
require __DIR__ . '/_playlist_writer.php';

$pdo = admin_pdo();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) { header('Location: devices.php'); exit; }

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();

    if (($_POST['action'] ?? '') === 'delete') {
        $pdo->prepare('DELETE FROM devices WHERE id = ?')->execute([$id]);
        flash_set('Device deleted.', 'ok');
        header('Location: devices.php');
        exit;
    }

    $storeId    = (int)($_POST['store_id'] ?? 0) ?: null;
    $name       = trim((string)($_POST['display_name'] ?? ''));
    $notes      = trim((string)($_POST['notes'] ?? ''));
    $assigned   = $storeId ? 1 : 0;

    // Polling cadence — clamp to a sane window so a typo can't DoS the
    // server (1s) or render the device functionally offline (24h+).
    $poll       = (int)($_POST['poll_interval_seconds'] ?? 300);
    if ($poll < 30)    $poll = 30;
    if ($poll > 3600)  $poll = 3600;

    // Try writing the new column. If migration 006 hasn't been applied,
    // fall back to the pre-006 UPDATE so the rest of the form still saves.
    try {
        $pdo->prepare(
            'UPDATE devices SET store_id = ?, display_name = ?, notes = ?, assigned = ?, poll_interval_seconds = ? WHERE id = ?'
        )->execute([$storeId, $name ?: null, $notes ?: null, $assigned, $poll, $id]);
    } catch (PDOException $_) {
        $pdo->prepare(
            'UPDATE devices SET store_id = ?, display_name = ?, notes = ?, assigned = ? WHERE id = ?'
        )->execute([$storeId, $name ?: null, $notes ?: null, $assigned, $id]);
        flash_set('Device updated, but poll-interval not saved (apply migration 006).', 'warn');
        header('Location: device_edit.php?id=' . $id);
        exit;
    }

    // Republish playlist.json so the new poll_interval_seconds (or any
    // other writer-emitted setting) propagates to the box on its next
    // playlist poll. Without this, changing the poll interval here would
    // only take effect after the NEXT manual playlist save — confusing
    // because the dashboard shows the new value but the device keeps
    // using the old.
    try {
        playlist_regenerate($id);
    } catch (Throwable $t) {
        flash_set('Device updated, but playlist.json regeneration failed: ' . $t->getMessage(), 'warn');
        header('Location: device_edit.php?id=' . $id);
        exit;
    }

    flash_set('Device updated.', 'ok');
    header('Location: device_edit.php?id=' . $id);
    exit;
}

$device = $pdo->prepare('SELECT * FROM devices WHERE id = ?');
$device->execute([$id]);
$d = $device->fetch();
if (!$d) { flash_set('Device not found.', 'error'); header('Location: devices.php'); exit; }

$stores = $pdo->query(
    'SELECT s.id, s.name AS store_name, c.name AS city_name
       FROM stores s JOIN cities c ON c.id = s.city_id
      ORDER BY c.name, s.name'
)->fetchAll();

$pings = $pdo->prepare(
    "SELECT event_type, details, timestamp FROM device_logs
      WHERE device_id = ? AND timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
      ORDER BY timestamp DESC LIMIT 50"
);
$pings->execute([$id]);
$pingHistory = $pings->fetchAll();

layout_header('Edit device', 'devices');
?>
<nav><a href="devices.php" class="text-decoration-none">← Devices</a></nav>
<h1 class="h3 my-3">Device <code><?= h($d['android_id']) ?></code></h1>

<div class="row">
  <div class="col-lg-7">
    <form method="post" class="card mb-4">
      <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <div class="mb-3">
          <label class="form-label">Display name</label>
          <input class="form-control" name="display_name" value="<?= h($d['display_name'] ?? '') ?>" placeholder="e.g. Mumbai Andheri front window">
        </div>

        <div class="mb-3">
          <label class="form-label">Store</label>
          <select class="form-select" name="store_id">
            <option value="0">— Unassigned —</option>
            <?php foreach ($stores as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= ((int)$d['store_id'] === (int)$s['id']) ? 'selected' : '' ?>>
                <?= h($s['city_name']) ?> / <?= h($s['store_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!$stores): ?>
            <div class="form-text text-warning">Add a city and store first under <a href="cities.php">Cities &amp; Stores</a>.</div>
          <?php endif; ?>
        </div>

        <div class="mb-3">
          <label class="form-label">Poll interval (seconds)</label>
          <input class="form-control" type="number" min="30" max="3600" step="30"
                 name="poll_interval_seconds"
                 value="<?= (int)($d['poll_interval_seconds'] ?? 300) ?>">
          <div class="form-text">
            How often the device checks the server for updates.
            Used by both the APK ping (heartbeat / force-refresh detection) and
            the player playlist poll. Range 30–3600 s; default 300 (5 min).
            Lower = faster updates, more bandwidth + load. UP-key on the
            remote bypasses this and pulls instantly.
          </div>
        </div>

        <div class="mb-3">
          <label class="form-label">Notes</label>
          <textarea class="form-control" name="notes" rows="3"><?= h($d['notes'] ?? '') ?></textarea>
        </div>

        <button class="btn btn-primary">Save</button>
        <a href="playlist_editor.php?device_id=<?= (int)$id ?>" class="btn btn-success">Edit playlist</a>
      </div>
    </form>

    <form method="post" onsubmit="return confirm('Delete this device record? This cannot be undone.');">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="action" value="delete">
      <button class="btn btn-outline-danger btn-sm">Delete device record</button>
    </form>
  </div>

  <div class="col-lg-5">
    <div class="card mb-4"><div class="card-body">
      <h2 class="h6">Identity &amp; status</h2>
      <?php
        // Stats columns added in migration 008 — may be absent on legacy
        // installs. SELECT * already covers it; we just defensively
        // null-coalesce here for rendering.
        $sw = isset($d['screen_width'])  ? (int)$d['screen_width']  : 0;
        $sh = isset($d['screen_height']) ? (int)$d['screen_height'] : 0;
        $screenStr = ($sw > 0 && $sh > 0) ? ($sw . ' × ' . $sh) : '—';

        $sFree  = isset($d['storage_free_bytes'])  ? (int)$d['storage_free_bytes']  : 0;
        $sTotal = isset($d['storage_total_bytes']) ? (int)$d['storage_total_bytes'] : 0;
        $statsAt = $d['stats_reported_at'] ?? null;
        $storageStr = '—';
        if ($sTotal > 0) {
            $usedPct = $sTotal > 0 ? (int)round(($sTotal - $sFree) / $sTotal * 100) : 0;
            $storageStr = human_bytes($sFree) . ' free / ' . human_bytes($sTotal)
                        . ' total (' . $usedPct . '% used)';
            if ($statsAt) {
                $ago = max(0, time() - strtotime($statsAt));
                $storageStr .= ' &nbsp;<span class="text-muted">— as of ' . h(human_ago($ago)) . ' ago</span>';
            }
        }

        // Media-cache completeness (migration 010). The player reports how
        // many of the playlist's media files the Service Worker has actually
        // cached. cached == total (and total > 0) means the box is fully
        // provisioned and will survive a server/network outage.
        $cachedN  = isset($d['media_cached_count']) ? (int)$d['media_cached_count'] : null;
        $totalN   = isset($d['media_total_count'])  ? (int)$d['media_total_count']  : null;
        $cacheAt  = $d['cache_status_at'] ?? null;
        $cacheStr = '—';
        $cacheBadge = '';
        if ($totalN !== null && $cachedN !== null) {
            if ($totalN === 0) {
                $cacheStr = 'no media in playlist';
            } elseif ($cachedN >= $totalN) {
                $cacheBadge = 'text-success';
                $cacheStr = '✓ fully cached (' . $totalN . ' file' . ($totalN === 1 ? '' : 's') . ')';
            } else {
                $cacheBadge = 'text-warning';
                $cacheStr = 'caching ' . $cachedN . ' / ' . $totalN;
            }
            if ($cacheAt) {
                $ago = max(0, time() - strtotime($cacheAt));
                $cacheStr .= ' &nbsp;<span class="text-muted">— as of ' . h(human_ago($ago)) . ' ago</span>';
            }
        }
      ?>
      <dl class="row small mb-0">
        <dt class="col-5">ANDROID_ID</dt><dd class="col-7"><code><?= h($d['android_id']) ?></code></dd>
        <dt class="col-5">MAC</dt><dd class="col-7"><code><?= h($d['mac_address'] ?? '—') ?></code></dd>
        <dt class="col-5">Model</dt><dd class="col-7"><?= h($d['device_model'] ?: '—') ?></dd>
        <dt class="col-5">Android</dt><dd class="col-7"><?= h($d['android_version'] ?: '—') ?></dd>
        <dt class="col-5">Screen</dt><dd class="col-7"><?= h($screenStr) ?></dd>
        <dt class="col-5">Storage</dt><dd class="col-7"><?= $storageStr /* contains pre-escaped HTML */ ?></dd>
        <dt class="col-5">Media cache</dt><dd class="col-7 <?= $cacheBadge ?>"><?= $cacheStr /* contains pre-escaped HTML */ ?></dd>
        <dt class="col-5">App version</dt><dd class="col-7"><?= h($d['app_version'] ?: '—') ?></dd>
        <dt class="col-5">Last seen</dt><dd class="col-7"><?= h($d['last_seen'] ?: 'never') ?></dd>
        <dt class="col-5">Last IP</dt><dd class="col-7"><?= h($d['last_ip'] ?: '—') ?></dd>
        <dt class="col-5">Force refresh</dt><dd class="col-7"><?= $d['force_refresh'] ? 'pending' : 'idle' ?></dd>
      </dl>
    </div></div>

    <div class="card"><div class="card-body">
      <h2 class="h6">Recent activity (24h)</h2>
      <?php if (!$pingHistory): ?>
        <p class="text-muted small mb-0">No activity logged. Enable <code>log_pings</code> in config to see ping history.</p>
      <?php else: ?>
        <ul class="list-unstyled small mb-0">
          <?php foreach ($pingHistory as $p): ?>
            <li><code><?= h($p['timestamp']) ?></code> — <?= h($p['event_type']) ?><?= $p['details'] ? ' — ' . h(mb_strimwidth($p['details'], 0, 80, '…')) : '' ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div></div>
  </div>
</div>
<?php layout_footer();
