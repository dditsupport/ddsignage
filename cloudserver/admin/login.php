<?php
require __DIR__ . '/_auth.php';

$error = null;
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    csrf_check();
    $u = trim((string)($_POST['username'] ?? ''));
    $p = (string)($_POST['password'] ?? '');
    if (admin_attempt_login($u, $p)) {
        header('Location: dashboard.php');
        exit;
    }
    // Slow brute force without exposing which field was wrong.
    usleep(750_000);
    $error = 'Invalid username or password';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Sign in · DangeeCast</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="bg-light">
<div class="container" style="max-width: 420px; margin-top: 12vh;">
  <div class="card shadow-sm">
    <div class="card-body p-4">
      <h1 class="h4 mb-3 text-center">DangeeCast</h1>
      <?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <div class="mb-3">
          <label class="form-label">Username</label>
          <input class="form-control" name="username" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input class="form-control" type="password" name="password" required>
        </div>
        <button class="btn btn-primary w-100">Sign in</button>
      </form>
    </div>
  </div>
</div>
</body>
</html>
