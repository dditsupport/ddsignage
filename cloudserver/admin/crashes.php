<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_layout.php';

$pdo = admin_pdo();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'resolve' && $id) {
        $pdo->prepare('UPDATE crashes SET resolved = 1 WHERE id = ?')->execute([$id]);
        flash_set('Marked resolved.', 'ok');
    } elseif ($action === 'delete' && $id) {
        $pdo->prepare('DELETE FROM crashes WHERE id = ?')->execute([$id]);
        flash_set('Crash deleted.', 'ok');
    }
    header('Location: crashes.php');
    exit;
}

$showResolved = !empty($_GET['resolved']);
$where = $showResolved ? '' : 'WHERE c.resolved = 0';
$rows = $pdo->query(
    "SELECT c.*, d.display_name, d.android_id AS dev_aid, s.name AS store_name, ci.name AS city_name
       FROM crashes c
       LEFT JOIN devices d ON d.id = c.device_id
       LEFT JOIN stores s ON s.id = d.store_id
       LEFT JOIN cities ci ON ci.id = s.city_id
       $where
      ORDER BY c.received_at DESC LIMIT 200"
)->fetchAll();

layout_header('Crashes', 'crashes');
?>
<div class="d-flex justify-content-between align-items-center mb-3">
  <h1 class="h3 mb-0">Crashes</h1>
  <div class="btn-group">
    <a class="btn btn-sm btn-<?= $showResolved?'outline-secondary':'primary' ?>" href="crashes.php">Open</a>
    <a class="btn btn-sm btn-<?= $showResolved?'primary':'outline-secondary' ?>" href="crashes.php?resolved=1">All (incl. resolved)</a>
  </div>
</div>

<?php if (!$rows): ?>
  <p class="text-muted">No crashes <?= $showResolved ? 'recorded' : 'open' ?>. Nice.</p>
<?php else: ?>
<div class="accordion" id="acc">
  <?php foreach ($rows as $i => $r):
    $aid = $r['accordion-id'] ?? ('c' . $r['id']);
  ?>
    <div class="accordion-item">
      <h2 class="accordion-header">
        <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($aid) ?>">
          <span class="me-3 text-muted small"><?= h($r['received_at']) ?></span>
          <strong class="me-3"><?= h($r['app_version'] ?: '?') ?></strong>
          <span class="me-3"><?= h(($r['city_name'] ?? '') . ($r['store_name'] ? ' / ' . $r['store_name'] : '') ?: $r['dev_aid'] ?? '(unknown device)') ?></span>
          <?php if ($r['resolved']): ?><span class="badge bg-success ms-auto me-2">resolved</span><?php endif; ?>
        </button>
      </h2>
      <div id="<?= h($aid) ?>" class="accordion-collapse collapse" data-bs-parent="#acc">
        <div class="accordion-body">
          <div class="row small text-muted mb-2">
            <div class="col-md-3"><strong>Device:</strong> <?= h($r['device_model'] ?: '—') ?></div>
            <div class="col-md-3"><strong>Android:</strong> <?= h($r['android_version'] ?: '—') ?></div>
            <div class="col-md-3"><strong>ANDROID_ID:</strong> <code><?= h($r['android_id'] ?: '—') ?></code></div>
            <div class="col-md-3"><strong>Device ts:</strong> <?= $r['device_timestamp_ms'] ? h(gmdate('Y-m-d H:i:s', (int)($r['device_timestamp_ms']/1000))) : '—' ?></div>
          </div>
          <pre class="border rounded p-2 small bg-light" style="max-height: 360px; overflow: auto;"><?= h($r['stack_trace']) ?></pre>
          <div class="d-flex gap-2">
            <?php if (!$r['resolved']): ?>
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resolve">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button class="btn btn-sm btn-outline-success">Mark resolved</button>
              </form>
            <?php endif; ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Delete this crash record?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger">Delete</button>
            </form>
          </div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php layout_footer();
