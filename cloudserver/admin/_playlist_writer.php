<?php
/**
 * Generates /devices/{android_id}/playlist.json from the current DB state
 * for one device. Called whenever the dashboard saves a playlist edit, OR
 * whenever a media file referenced by a playlist is removed.
 *
 * Format follows §10 of 01_Signage_Main_Requirements.md:
 *   {
 *     "android_id": "...",
 *     "store_name": "...",
 *     "base_url":   "https://aromen.biz/signage/media/",
 *     "global_volume": 1.0,
 *     "last_updated": "2026-05-05T12:00:00Z",
 *     "schedule": {
 *       "monday":  [ {file, type, duration, audio, video_volume, audio_volume}, ... ],
 *       ...
 *     }
 *   }
 *
 * Atomic write: serialise to .tmp, rename over the live file. The player
 * relies on Apache's Last-Modified header so write the file with mtime = now.
 */

declare(strict_types=1);

require_once __DIR__ . '/_db.php';

if (!function_exists('playlist_regenerate')) {

    function playlist_regenerate(int $deviceId): bool {
        $pdo = admin_pdo();
        $cfg = admin_config();

        // Defensive defaults — production config usually sets all of these,
        // but a broken / partial config.php would otherwise throw at the
        // rtrim() calls below (PHP 8 strict types reject null).
        $publicBaseUrl = (string)($cfg['public_base_url'] ?? '');
        $mediaDirCfg   = (string)($cfg['paths']['media_dir'] ?? '');
        $devicesDirCfg = (string)($cfg['paths']['devices_dir'] ?? '');
        if ($publicBaseUrl === '' || $mediaDirCfg === '' || $devicesDirCfg === '') {
            error_log('signage: playlist_regenerate missing config — public_base_url='
                . var_export($cfg['public_base_url'] ?? null, true)
                . ' media_dir=' . var_export($cfg['paths']['media_dir'] ?? null, true)
                . ' devices_dir=' . var_export($cfg['paths']['devices_dir'] ?? null, true));
            return false;
        }

        // Tolerate the column being absent — migration 006 may not be
        // applied yet on legacy installs. Default 300 s matches the old
        // hardcoded behaviour.
        $pollInterval = 300;
        try {
            $devStmt = $pdo->prepare(
                'SELECT d.android_id, d.display_name, d.poll_interval_seconds,
                        s.name AS store_name, c.name AS city_name
                   FROM devices d
                   LEFT JOIN stores s ON s.id = d.store_id
                   LEFT JOIN cities c ON c.id = s.city_id
                  WHERE d.id = ?'
            );
            $devStmt->execute([$deviceId]);
            $dev = $devStmt->fetch();
            if ($dev && isset($dev['poll_interval_seconds']) && $dev['poll_interval_seconds'] > 0) {
                $pollInterval = (int)$dev['poll_interval_seconds'];
            }
        } catch (PDOException $_) {
            $devStmt = $pdo->prepare(
                'SELECT d.android_id, d.display_name,
                        s.name AS store_name, c.name AS city_name
                   FROM devices d
                   LEFT JOIN stores s ON s.id = d.store_id
                   LEFT JOIN cities c ON c.id = s.city_id
                  WHERE d.id = ?'
            );
            $devStmt->execute([$deviceId]);
            $dev = $devStmt->fetch();
        }
        if (!$dev || !$dev['android_id']) return false;

        // start_hour / end_hour added in migration 009; tolerate the
        // columns being absent on pre-009 databases.
        try {
            $itemsStmt = $pdo->prepare(
                'SELECT pi.weekday, pi.sort_order, pi.duration_seconds,
                        pi.video_volume, pi.audio_volume,
                        pi.start_hour, pi.end_hour,
                        m.filename AS media_file, m.file_type AS media_type,
                        am.filename AS audio_file
                   FROM playlist_items pi
                   JOIN media m ON m.id = pi.media_id
                   LEFT JOIN media am ON am.id = pi.audio_media_id
                  WHERE pi.device_id = ?
                  ORDER BY pi.weekday, pi.sort_order, pi.id'
            );
            $itemsStmt->execute([$deviceId]);
        } catch (PDOException $_) {
            $itemsStmt = $pdo->prepare(
                'SELECT pi.weekday, pi.sort_order, pi.duration_seconds,
                        pi.video_volume, pi.audio_volume,
                        0 AS start_hour, 24 AS end_hour,
                        m.filename AS media_file, m.file_type AS media_type,
                        am.filename AS audio_file
                   FROM playlist_items pi
                   JOIN media m ON m.id = pi.media_id
                   LEFT JOIN media am ON am.id = pi.audio_media_id
                  WHERE pi.device_id = ?
                  ORDER BY pi.weekday, pi.sort_order, pi.id'
            );
            $itemsStmt->execute([$deviceId]);
        }

        $weekdays = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
        $schedule = array_fill_keys($weekdays, []);
        $backgroundAudio = array_fill_keys($weekdays, null);
        $triggers = array_fill_keys($weekdays, []);

        $mediaDir = rtrim($mediaDirCfg, '/');

        // Background music — one continuous loop per weekday. Joined to media
        // so we have the disk filename + can cache-bust via mtime.
        // Background music + triggers come from migrations 002/003/004. If
        // those haven't been applied yet, the queries throw "table doesn't
        // exist" / "unknown column". We swallow those specific errors so
        // playlist.json regeneration still succeeds for the legacy items
        // schema — the user gets an empty bg/triggers section in the JSON
        // but their items keep playing. Log the warning so the operator
        // still notices something to fix.
        try {
            $bgSettingsStmt = $pdo->prepare(
                'SELECT weekday, volume, video_duck_volume
                   FROM device_bg_audio
                  WHERE device_id = ?'
            );
            $bgSettingsStmt->execute([$deviceId]);
            $bgSettings = [];
            foreach ($bgSettingsStmt as $r) {
                $bgSettings[$r['weekday']] = [
                    'volume'      => (float)$r['volume'],
                    'duck_volume' => (float)$r['video_duck_volume'],
                ];
            }

            // Try the per-track volume columns (migration 005). If they're
            // missing, fall back to a NULL alias so per-track stays "inherit".
            try {
                $bgItemsStmt = $pdo->prepare(
                    'SELECT dbai.weekday, dbai.sort_order, dbai.volume, dbai.video_duck_volume,
                            m.filename AS audio_file
                       FROM device_bg_audio_items dbai
                       JOIN media m ON m.id = dbai.audio_media_id
                      WHERE dbai.device_id = ?
                      ORDER BY dbai.weekday, dbai.sort_order, dbai.id'
                );
                $bgItemsStmt->execute([$deviceId]);
            } catch (PDOException $_) {
                $bgItemsStmt = $pdo->prepare(
                    'SELECT dbai.weekday, dbai.sort_order,
                            NULL AS volume, NULL AS video_duck_volume,
                            m.filename AS audio_file
                       FROM device_bg_audio_items dbai
                       JOIN media m ON m.id = dbai.audio_media_id
                      WHERE dbai.device_id = ?
                      ORDER BY dbai.weekday, dbai.sort_order, dbai.id'
                );
                $bgItemsStmt->execute([$deviceId]);
            }
            $bgFilesByDay = array_fill_keys($weekdays, []);
            foreach ($bgItemsStmt as $r) {
                $diskPath = $mediaDir . '/' . $r['audio_file'];
                $cacheBust = is_file($diskPath) ? ('?v=' . (int)filemtime($diskPath)) : '';
                // Each track is now a struct {file, volume?, duck_volume?}.
                // A null per-track value means "use the per-day default".
                $entry = ['file' => $r['audio_file'] . $cacheBust];
                if ($r['volume']            !== null) $entry['volume']      = (float)$r['volume'];
                if ($r['video_duck_volume'] !== null) $entry['duck_volume'] = (float)$r['video_duck_volume'];
                $bgFilesByDay[$r['weekday']][] = $entry;
            }

            foreach ($weekdays as $w) {
                $files = $bgFilesByDay[$w];
                if (!$files) {
                    $backgroundAudio[$w] = null;
                    continue;
                }
                $cfg = $bgSettings[$w] ?? ['volume' => 1.00, 'duck_volume' => 0.20];
                $backgroundAudio[$w] = [
                    // files = [{file, volume?, duck_volume?}, ...]. The player
                    // applies per-track values when present, falls back to the
                    // per-day volume / duck_volume below otherwise.
                    'files'       => $files,
                    'volume'      => $cfg['volume'],
                    'duck_volume' => $cfg['duck_volume'],
                ];
            }
        } catch (PDOException $e) {
            error_log('signage: bg-audio query failed (apply migrations 002-004?): ' . $e->getMessage());
        }

        try {
            $trigStmt = $pdo->prepare(
                'SELECT pt.id, pt.weekday, pt.trigger_time, pt.volume,
                        m.filename AS media_file, m.file_type AS media_type
                   FROM playlist_triggers pt
                   JOIN media m ON m.id = pt.media_id
                  WHERE pt.device_id = ?
                  ORDER BY pt.weekday, pt.trigger_time, pt.id'
            );
            $trigStmt->execute([$deviceId]);
            foreach ($trigStmt as $tg) {
                $diskPath = $mediaDir . '/' . $tg['media_file'];
                $cacheBust = is_file($diskPath) ? ('?v=' . (int)filemtime($diskPath)) : '';
                $triggers[$tg['weekday']][] = [
                    'id'     => (int)$tg['id'],
                    'time'   => substr((string)$tg['trigger_time'], 0, 5),
                    'file'   => $tg['media_file'] . $cacheBust,
                    'type'   => $tg['media_type'],
                    'volume' => (float)$tg['volume'],
                ];
            }
        } catch (PDOException $e) {
            error_log('signage: triggers query failed (apply migration 002?): ' . $e->getMessage());
        }

        foreach ($itemsStmt as $row) {
            $cacheBust = '';
            $diskPath = $mediaDir . '/' . $row['media_file'];
            if (is_file($diskPath)) {
                $cacheBust = '?v=' . (int)filemtime($diskPath);
            }
            $item = [
                'file'     => $row['media_file'] . $cacheBust,
                'type'     => $row['media_type'],
                'duration' => $row['media_type'] === 'video' ? 0 : (int)$row['duration_seconds'],
            ];
            // Hour window — only emit when non-default. Saves bytes in the
            // common case and keeps old player code (that ignores these
            // fields) behaving identically. Player filters by current IST
            // hour; missing fields are treated as 0..24 = all day.
            $sh = (int)$row['start_hour'];
            $eh = (int)$row['end_hour'];
            if ($sh !== 0 || $eh !== 24) {
                $item['start_hour'] = $sh;
                $item['end_hour']   = $eh;
            }
            if ($row['audio_file']) {
                $audioDisk = $mediaDir . '/' . $row['audio_file'];
                $audioBust = is_file($audioDisk) ? ('?v=' . (int)filemtime($audioDisk)) : '';
                $item['audio'] = $row['audio_file'] . $audioBust;
            } else {
                $item['audio'] = null;
            }
            if ($row['video_volume'] !== null) $item['video_volume'] = (float)$row['video_volume'];
            if ($row['audio_volume'] !== null) $item['audio_volume'] = (float)$row['audio_volume'];

            $schedule[$row['weekday']][] = $item;
        }

        $payload = [
            'android_id'            => $dev['android_id'],
            'store_name'            => trim((($dev['city_name'] ?? '') . ' / ' . ($dev['store_name'] ?? '')), ' /'),
            'base_url'              => rtrim($publicBaseUrl, '/') . '/media/',
            'global_volume'         => 1.0,
            'last_updated'          => date('c'), // Asia/Kolkata — set in _db.php
            // Player rebuilds its setInterval to match this on every poll;
            // changing it in the dashboard takes effect within ≤1 cycle.
            'poll_interval_seconds' => $pollInterval,
            'schedule'              => $schedule,
            'background_audio'      => $backgroundAudio,
            'triggers'              => $triggers,
        ];

        $devicesDir = rtrim($devicesDirCfg, '/');
        $deviceDir  = $devicesDir . '/' . $dev['android_id'];
        if (!is_dir($deviceDir) && !@mkdir($deviceDir, 0755, true)) {
            return false;
        }
        $finalPath = $deviceDir . '/playlist.json';
        $tmpPath   = $finalPath . '.tmp';

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) return false;
        if (file_put_contents($tmpPath, $json) === false) return false;
        if (!rename($tmpPath, $finalPath)) return false;
        @touch($finalPath); // bump mtime so Last-Modified picks up the change
        return true;
    }
}
