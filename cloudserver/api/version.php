<?php
/**
 * GET /api/version.php?current_version=<string>
 *
 * Returns the latest APK version info. The "current" row in apk_versions is
 * the single source of truth — admins toggle is_current in the dashboard.
 *
 * Response:
 *   {
 *     "status":"success",
 *     "latest_version":"1.1.0",
 *     "update_available":true|false,
 *     "apk_url":"https://.../apk/current.apk",
 *     "release_notes":"..."
 *   }
 */

require_once __DIR__ . '/_common.php';
api_require_get();

$current = api_get('current_version');
if ($current === null || $current === '') {
    api_json(['status' => 'error', 'message' => 'current_version is required'], 400);
}

$pdo = api_pdo();

try {
    $row = $pdo->query(
        'SELECT version_name, version_code, apk_url, release_notes
           FROM apk_versions
          WHERE is_current = 1
          ORDER BY version_code DESC
          LIMIT 1'
    )->fetch();

    if (!$row) {
        api_json([
            'status'           => 'success',
            'latest_version'   => $current,
            'update_available' => false,
            'apk_url'          => '',
            'release_notes'    => '',
        ]);
    }

    // Compare by version string. Admins must keep version_name monotonically
    // increasing for this to work. version_code is the authoritative field
    // but the APK only knows its versionName.
    $updateAvailable = version_compare($row['version_name'], $current, '>');

    api_json([
        'status'           => 'success',
        'latest_version'   => $row['version_name'],
        'update_available' => $updateAvailable,
        'apk_url'          => $updateAvailable ? $row['apk_url'] : '',
        'release_notes'    => $row['release_notes'] ?? '',
    ]);
} catch (Throwable $t) {
    error_log('version.php failed: ' . $t->getMessage());
    api_json(['status' => 'error', 'message' => 'Server error'], 500);
}
