<?php
/**
 * POST /api/register.php
 *
 * Called on first boot of a new device, then once per day thereafter.
 * Creates the row if absent, updates metadata if present. Never overwrites
 * the operator's store_id assignment.
 *
 * Request (form-encoded):
 *   android_id      (required)
 *   mac_address     (optional)
 *   app_version     (required)
 *   device_model    (optional)
 *   android_version (optional)
 *
 * Response: {"status":"success","assigned":<bool>,"message":"..."}
 */

require_once __DIR__ . '/_common.php';
api_require_post();

$androidId    = api_post('android_id');
$mac          = api_post('mac_address', '') ?: null;
// app_version now carries the combined "<name> (<code>)" string from the
// APK, e.g. "1.7 (8)". Stored verbatim — server doesn't need to parse.
$appVersion   = api_post('app_version', '') ?: null;
$deviceModel  = api_post('device_model', '') ?: null;
$androidVer   = api_post('android_version', '') ?: null;

// Device-health stats (added in APK 2.1, optional). Treat 0 / missing /
// negative as "not reported" so old APKs don't overwrite previously-set
// values with zeros.
$pickPositiveInt = function ($key) {
    $raw = api_post($key, '0');
    if ($raw === null || $raw === '') return null;
    $n = (int)$raw;
    return $n > 0 ? $n : null;
};
$screenW       = $pickPositiveInt('screen_width');
$screenH       = $pickPositiveInt('screen_height');
$storageFree   = $pickPositiveInt('storage_free_bytes');
$storageTotal  = $pickPositiveInt('storage_total_bytes');
$reportingStats = ($screenW !== null) || ($screenH !== null)
                || ($storageFree !== null) || ($storageTotal !== null);

if (!api_valid_android_id($androidId)) {
    api_json(['status' => 'error', 'message' => 'android_id is required and must match [A-Za-z0-9_-]{1,64}'], 400);
}
if (!$appVersion) {
    api_json(['status' => 'error', 'message' => 'app_version is required'], 400);
}

$pdo = api_pdo();
$ip  = api_client_ip();

try {
    $existing = $pdo->prepare('SELECT id, assigned, store_id FROM devices WHERE android_id = ?');
    $existing->execute([$androidId]);
    $row = $existing->fetch();

    if ($row) {
        // Try the wider UPDATE first (includes screen + storage from
        // migration 008). Falls back to the pre-008 UPDATE if those
        // columns don't exist yet, so heartbeat keeps working before
        // the operator has applied the migration.
        $deviceId = (int)$row['id'];
        try {
            $upd = $pdo->prepare(
                'UPDATE devices
                    SET mac_address         = COALESCE(NULLIF(?, ""), mac_address),
                        app_version         = ?,
                        device_model        = COALESCE(NULLIF(?, ""), device_model),
                        android_version     = COALESCE(NULLIF(?, ""), android_version),
                        screen_width        = COALESCE(?, screen_width),
                        screen_height       = COALESCE(?, screen_height),
                        storage_free_bytes  = COALESCE(?, storage_free_bytes),
                        storage_total_bytes = COALESCE(?, storage_total_bytes),
                        stats_reported_at   = CASE WHEN ? THEN NOW() ELSE stats_reported_at END,
                        last_seen           = NOW(),
                        last_ip             = ?
                  WHERE id = ?'
            );
            $upd->execute([
                $mac ?? '', $appVersion, $deviceModel ?? '', $androidVer ?? '',
                $screenW, $screenH, $storageFree, $storageTotal,
                $reportingStats ? 1 : 0,
                $ip, $deviceId,
            ]);
        } catch (PDOException $_) {
            // Migration 008 not applied — re-run without the new columns.
            $upd = $pdo->prepare(
                'UPDATE devices
                    SET mac_address     = COALESCE(NULLIF(?, ""), mac_address),
                        app_version     = ?,
                        device_model    = COALESCE(NULLIF(?, ""), device_model),
                        android_version = COALESCE(NULLIF(?, ""), android_version),
                        last_seen       = NOW(),
                        last_ip         = ?
                  WHERE id = ?'
            );
            $upd->execute([$mac ?? '', $appVersion, $deviceModel ?? '', $androidVer ?? '', $ip, $deviceId]);
        }
        $assigned = (bool)$row['assigned'];
        $message  = 'Device updated';
    } else {
        // INSERT — same fallback pattern. New rows on a pre-008 schema
        // just don't carry the new fields; first post-migration register
        // call will UPDATE them in.
        try {
            $insert = $pdo->prepare(
                'INSERT INTO devices
                    (android_id, mac_address, app_version, device_model, android_version,
                     screen_width, screen_height, storage_free_bytes, storage_total_bytes,
                     stats_reported_at, last_seen, last_ip, assigned)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ' .
                 ($reportingStats ? 'NOW()' : 'NULL') .
                 ', NOW(), ?, 0)'
            );
            $insert->execute([
                $androidId, $mac, $appVersion, $deviceModel, $androidVer,
                $screenW, $screenH, $storageFree, $storageTotal,
                $ip,
            ]);
        } catch (PDOException $_) {
            $insert = $pdo->prepare(
                'INSERT INTO devices
                    (android_id, mac_address, app_version, device_model, android_version,
                     last_seen, last_ip, assigned)
                 VALUES (?, ?, ?, ?, ?, NOW(), ?, 0)'
            );
            $insert->execute([$androidId, $mac, $appVersion, $deviceModel, $androidVer, $ip]);
        }
        $deviceId = (int)$pdo->lastInsertId();
        $assigned = false;
        $message  = 'New device registered — awaiting store assignment';
    }

    api_log_event($deviceId, 'register', json_encode([
        'app_version' => $appVersion,
        'mac' => $mac,
        'ip' => $ip,
    ]));

    api_json([
        'status'   => 'success',
        'assigned' => $assigned,
        'message'  => $message,
    ]);
} catch (Throwable $t) {
    error_log('register.php failed: ' . $t->getMessage());
    api_json(['status' => 'error', 'message' => 'Server error'], 500);
}
