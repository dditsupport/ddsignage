<?php
require __DIR__ . '/_auth.php';
header('Location: ' . (empty($_SESSION['admin']) ? 'login.php' : 'dashboard.php'));
exit;
