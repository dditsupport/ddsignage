<?php
require __DIR__ . '/_auth.php';
admin_logout();
header('Location: login.php');
exit;
