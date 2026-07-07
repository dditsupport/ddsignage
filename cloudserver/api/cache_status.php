<?php
/**
 * POST /api/cache_status.php
 *
 * Reported by the PLAYER (not the APK) so the dashboard can show whether a
 * box has finished caching its playlist media. The player computes these
 * from the Service Worker's cache contents vs. the playlist's media URL set,
 * and posts on each cache sync + as files land. It stops posting once fully
 * cached (cached == total), so this endpoint is quiet on settled devices.
 *
 * Request (form-encoded):
 *   android_id   (required)
 *   cached_count (required, int >= 0) — distinct playlist media URLs cached
 *   total_count  (required, int >= 0) — distinct playlist media URLs wanted
 *
 * Updates an EXISTING device row only (never creates one — register.php owns
 * device creation). Touches last_seen so a reporting box counts as online.
 *
 * Response: {"status":"success","fully_cached":<bool>}
 */

require_once __DIR__ . '/_common.php';
api_require_post();

$androidId = api_post('android_id');
if (!api_valid_android_id($androidId)) {
    api_json(['status' => 'error', 'message' => 'android_id is required and must match [A-Za-z0-9_-]{1,64}'], 400);
}

// Clamp to non-negative ints. Treat anything non-numeric as 0 rather than
// erroring — a malformed report shouldn't 500 the box's reporting loop.
$cached = max(0, (int)api_post('cached_count', '0'));
$total  = max(0, (int)api_post('total_count', '0'));
// Cached can never exceed total; if a stale report says so, cap it.
if ($cached > $total) $cached = $total;

$pdo = api_pdo();

try {
    $sel = $pdo->prepare('SELECT id FROM devices WHERE android_id = ?');
    $sel->execute([$androidId]);
    $row = $sel->fetch();
    if (!$row) {
        // Unknown device — don't create it here. The player can only report
        // after a successful playlist fetch, which implies it's registered,
        // so this is rare (race on first boot). 404 lets the player retry.
        api_json(['status' => 'error', 'message' => 'Device not registered'], 404);
    }
    $deviceId = (int)$row['id'];

    $pdo->prepare(
        'UPDATE devices
            SET media_cached_count = ?,
                media_total_count  = ?,
                cache_status_at    = NOW(),
                last_seen          = NOW()
          WHERE id = ?'
    )->execute([$cached, $total, $deviceId]);

    api_json([
        'status'       => 'success',
        'fully_cached' => ($total > 0 && $cached >= $total),
    ]);
} catch (Throwable $t) {
    error_log('cache_status.php failed: ' . $t->getMessage());
    api_json(['status' => 'error', 'message' => 'Server error'], 500);
}
