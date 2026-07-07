<?php
/**
 * POST /api/crash.php
 *
 * Receives a crash report from the APK. Fire-and-forget from the device side,
 * so we always return 200 once the row is persisted; the device deletes its
 * queued file on success.
 *
 * Request (form-encoded):
 *   android_id      (recommended, used to link to devices.id if known)
 *   app_version
 *   android_version
 *   device_model
 *   stack_trace     (required)
 *   timestamp       (device-side ms epoch)
 *
 * Response: {"status":"received"}
 */

require_once __DIR__ . '/_common.php';
api_require_post();

$stackTrace = api_post('stack_trace', '') ?: null;
if ($stackTrace === null || $stackTrace === '') {
    api_json(['status' => 'error', 'message' => 'stack_trace is required'], 400);
}

$androidId   = api_post('android_id', '') ?: null;
$appVersion  = api_post('app_version', '') ?: null;
$androidVer  = api_post('android_version', '') ?: null;
$deviceModel = api_post('device_model', '') ?: null;
$timestamp   = api_post('timestamp', '') ?: null;
$timestampMs = ($timestamp !== null && ctype_digit($timestamp)) ? (int)$timestamp : null;

// Cap the stack trace size at 256 KB so a runaway log can't fill the table.
if (strlen($stackTrace) > 256 * 1024) {
    $stackTrace = substr($stackTrace, 0, 256 * 1024) . "\n…[truncated]";
}

$pdo = api_pdo();

try {
    $deviceId = null;
    if ($androidId && api_valid_android_id($androidId)) {
        $sel = $pdo->prepare('SELECT id FROM devices WHERE android_id = ?');
        $sel->execute([$androidId]);
        $row = $sel->fetch();
        if ($row) $deviceId = (int)$row['id'];
    }

    $ins = $pdo->prepare(
        'INSERT INTO crashes
            (device_id, android_id, app_version, android_version, device_model,
             stack_trace, device_timestamp_ms)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([$deviceId, $androidId, $appVersion, $androidVer, $deviceModel, $stackTrace, $timestampMs]);

    if ($deviceId !== null) {
        api_log_event($deviceId, 'crash', 'crash report received');
    }

    api_json(['status' => 'received']);
} catch (Throwable $t) {
    error_log('crash.php failed: ' . $t->getMessage());
    api_json(['status' => 'error', 'message' => 'Server error'], 500);
}
