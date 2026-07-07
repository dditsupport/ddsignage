<?php
/**
 * POST /api/ping.php
 *
 * 5-min heartbeat. Updates last_seen / last_ip / app_version on the device row.
 * If the device's force_refresh flag is set, returns force_refresh:true and
 * clears the flag in the same transaction (one-shot semantics).
 *
 * Request (form-encoded):
 *   android_id  (required)
 *   app_version (required)
 *
 * Response: {"status":"success","force_refresh":<bool>}
 */

require_once __DIR__ . '/_common.php';
api_require_post();

$androidId  = api_post('android_id');
$appVersion = api_post('app_version', '') ?: null;

if (!api_valid_android_id($androidId)) {
    api_json(['status' => 'error', 'message' => 'android_id is required'], 400);
}
if (!$appVersion) {
    api_json(['status' => 'error', 'message' => 'app_version is required'], 400);
}

$pdo = api_pdo();
$ip  = api_client_ip();

try {
    $pdo->beginTransaction();

    // Lock the row so the force_refresh read+clear is atomic with respect to
    // a concurrent admin click that might re-set it. Also fetch the
    // configured poll cadence so the APK can adjust its ping timer.
    // Tolerate missing column (pre-006 databases).
    try {
        $sel = $pdo->prepare(
            'SELECT id, force_refresh, poll_interval_seconds
               FROM devices WHERE android_id = ? FOR UPDATE'
        );
        $sel->execute([$androidId]);
        $row = $sel->fetch();
    } catch (PDOException $_) {
        $sel = $pdo->prepare('SELECT id, force_refresh FROM devices WHERE android_id = ? FOR UPDATE');
        $sel->execute([$androidId]);
        $row = $sel->fetch();
    }

    if (!$row) {
        $pdo->rollBack();
        // Device pinged before registering. Tell APK to register first.
        api_json([
            'status'        => 'error',
            'message'       => 'Device not registered — call register.php first',
            'force_refresh' => false,
        ], 404);
    }

    $deviceId     = (int)$row['id'];
    $forceRefresh = (bool)$row['force_refresh'];
    // 300 s default matches the historical hardcoded value.
    $pollInterval = isset($row['poll_interval_seconds']) && $row['poll_interval_seconds'] > 0
        ? (int)$row['poll_interval_seconds']
        : 300;

    $upd = $pdo->prepare(
        'UPDATE devices
            SET last_seen   = NOW(),
                last_ip     = ?,
                app_version = ?,
                force_refresh = 0
          WHERE id = ?'
    );
    $upd->execute([$ip, $appVersion, $deviceId]);
    $pdo->commit();

    api_log_event($deviceId, 'ping', null);

    api_json([
        'status'                => 'success',
        'force_refresh'         => $forceRefresh,
        'poll_interval_seconds' => $pollInterval,
    ]);
} catch (Throwable $t) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('ping.php failed: ' . $t->getMessage());
    api_json(['status' => 'error', 'message' => 'Server error'], 500);
}
