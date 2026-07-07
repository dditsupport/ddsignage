<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_layout.php';

$pdo = admin_pdo();

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    try {
        if ($action === 'add_city') {
            $name = trim((string)$_POST['name']);
            if ($name !== '') {
                $pdo->prepare('INSERT INTO cities (name) VALUES (?)')->execute([$name]);
                flash_set('City added.', 'ok');
            }
        } elseif ($action === 'delete_city') {
            $pdo->prepare('DELETE FROM cities WHERE id = ?')->execute([(int)$_POST['id']]);
            flash_set('City deleted.', 'ok');
        } elseif ($action === 'add_store') {
            $cityId = (int)$_POST['city_id'];
            $name   = trim((string)$_POST['name']);
            $addr   = trim((string)($_POST['address'] ?? ''));
            if ($cityId && $name !== '') {
                $pdo->prepare('INSERT INTO stores (city_id, name, address) VALUES (?, ?, ?)')
                    ->execute([$cityId, $name, $addr ?: null]);
                flash_set('Store added.', 'ok');
            }
        } elseif ($action === 'delete_store') {
            $pdo->prepare('DELETE FROM stores WHERE id = ?')->execute([(int)$_POST['id']]);
            flash_set('Store deleted.', 'ok');
        }
    } catch (Throwable $t) {
        // FK restrict trips here when a city/store still has children. Show a real error.
        flash_set('Database refused: ' . $t->getMessage(), 'error');
    }
    header('Location: cities.php');
    exit;
}

$cities = $pdo->query(
    'SELECT c.id, c.name, COUNT(s.id) AS store_count
       FROM cities c LEFT JOIN stores s ON s.city_id = c.id
      GROUP BY c.id, c.name
      ORDER BY c.name'
)->fetchAll();

$stores = $pdo->query(
    'SELECT s.id, s.name, s.address, c.name AS city_name, c.id AS city_id,
            (SELECT COUNT(*) FROM devices WHERE store_id = s.id) AS device_count
       FROM stores s JOIN cities c ON c.id = s.city_id
      ORDER BY c.name, s.name'
)->fetchAll();

layout_header('Cities & Stores', 'cities');
?>
<div class="row">
  <div class="col-lg-5">
    <h1 class="h4">Cities</h1>
    <form method="post" class="d-flex mb-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_city">
      <input class="form-control me-2" name="name" placeholder="New city name" required>
      <button class="btn btn-primary">Add</button>
    </form>
    <table class="table table-sm">
      <thead class="table-light"><tr><th>City</th><th>Stores</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($cities as $c): ?>
          <tr>
            <td><?= h($c['name']) ?></td>
            <td><?= (int)$c['store_count'] ?></td>
            <td class="text-end">
              <form method="post" class="d-inline" onsubmit="return confirm('Delete city <?= h($c['name']) ?>?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_city">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" <?= $c['store_count'] > 0 ? 'disabled' : '' ?>>Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$cities): ?><tr><td colspan="3" class="text-muted">No cities yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="col-lg-7">
    <h1 class="h4">Stores</h1>
    <form method="post" class="row g-2 mb-3">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_store">
      <div class="col-md-4">
        <select class="form-select" name="city_id" required>
          <option value="">Select city…</option>
          <?php foreach ($cities as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3"><input class="form-control" name="name" placeholder="Store name" required></div>
      <div class="col-md-4"><input class="form-control" name="address" placeholder="Address (optional)"></div>
      <div class="col-md-1"><button class="btn btn-primary w-100">Add</button></div>
    </form>

    <table class="table table-sm">
      <thead class="table-light"><tr><th>City</th><th>Store</th><th>Devices</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($stores as $s): ?>
          <tr>
            <td><?= h($s['city_name']) ?></td>
            <td><?= h($s['name']) ?><?= $s['address'] ? '<br><small class="text-muted">' . h($s['address']) . '</small>' : '' ?></td>
            <td><?= (int)$s['device_count'] ?></td>
            <td class="text-end">
              <form method="post" class="d-inline" onsubmit="return confirm('Delete store <?= h($s['name']) ?>?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_store">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="btn btn-sm btn-outline-danger" <?= $s['device_count'] > 0 ? 'disabled title="Has devices assigned"' : '' ?>>Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$stores): ?><tr><td colspan="4" class="text-muted">No stores yet.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php layout_footer();
