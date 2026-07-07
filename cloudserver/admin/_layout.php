<?php
/**
 * Shared layout helpers — header() opens <html>, footer() closes it.
 * Pages call:
 *   $page = 'devices'; $title = 'Devices';
 *   require __DIR__ . '/_layout.php';
 *   layout_header();
 *   ...content...
 *   layout_footer();
 */

declare(strict_types=1);

if (!function_exists('layout_header')) {

    function layout_header(string $title = 'DangeeCast', string $page = ''): void {
        $flashes = flash_take();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> · DangeeCast</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark mb-3">
  <div class="container-fluid">
    <a class="navbar-brand" href="dashboard.php">DangeeCast</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link <?= $page==='dashboard'?'active':'' ?>" href="dashboard.php">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= $page==='devices'?'active':'' ?>" href="devices.php">Devices</a></li>
        <li class="nav-item"><a class="nav-link <?= $page==='timeline'?'active':'' ?>" href="device_timeline.php">Timeline</a></li>
        <li class="nav-item"><a class="nav-link <?= $page==='media'?'active':'' ?>" href="media.php">Media</a></li>
        <li class="nav-item"><a class="nav-link <?= $page==='cities'?'active':'' ?>" href="cities.php">Cities &amp; Stores</a></li>
        <li class="nav-item"><a class="nav-link <?= $page==='apk'?'active':'' ?>" href="apk.php">APK</a></li>
        <li class="nav-item"><a class="nav-link <?= $page==='crashes'?'active':'' ?>" href="crashes.php">Crashes</a></li>
      </ul>
      <ul class="navbar-nav">
        <li class="nav-item"><span class="navbar-text me-3"><?= h($_SESSION['admin'] ?? '') ?></span></li>
        <li class="nav-item"><a class="btn btn-outline-light btn-sm" href="logout.php">Sign out</a></li>
      </ul>
    </div>
  </div>
</nav>
<main class="container-fluid">
        <?php foreach ($flashes as $f):
            $cls = ['ok'=>'success','error'=>'danger','info'=>'info','warn'=>'warning'][$f['type']] ?? 'info'; ?>
          <div class="alert alert-<?= h($cls) ?>"><?= h($f['msg']) ?></div>
        <?php endforeach; ?>
        <?php
    }

    function layout_footer(): void {
        ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script src="assets/app.js"></script>
</body>
</html>
        <?php
    }
}
