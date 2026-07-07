<?php
/**
 * Per-device online/offline timeline.
 *
 * Renders one horizontal row per day in [from..to] for a single selected
 * device, with green bands where the device was online and a grey track
 * where it was offline. Time axis spans 08:00 → 03:00 next day (the store
 * operating window — matches the bridge dashboard's layout).
 *
 * Data source: rows in `device_logs` where event_type = 'ping'. The ping
 * endpoint already inserts one of these per heartbeat (every poll_interval,
 * default 300 s), *but only when config.log_pings = true*. If the flag is
 * off, this page is empty and prints a warning at the top. Operators flip
 * the flag in config.php; no schema change.
 *
 * Derivation:
 *   tolerance = 2 × the device's configured poll_interval_seconds.
 *   Sort pings, merge any two within tolerance into a single online band.
 *   Each band extends half-a-poll before the first ping and half-a-poll
 *   after the last (we can't pin the exact transition any tighter than
 *   that without a sweeper).
 *
 * Retention: prune ping rows older than 90 days on every page load. The
 * (event_type, timestamp) index makes the DELETE near-free when there's
 * nothing to do.
 */

require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_layout.php';

$pdo = admin_pdo();

// --- Retention sweep. Cheap with idx_dl_event_time.
$pdo->exec("DELETE FROM device_logs WHERE event_type = 'ping' AND timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)");

$logPingsEnabled = !empty(admin_config()['log_pings']);

// --- Devices for the dropdown
$devices = $pdo->query(
    "SELECT id, android_id, display_name, poll_interval_seconds
       FROM devices
      ORDER BY COALESCE(display_name, android_id)"
)->fetchAll();

// --- Inputs
$deviceId = (int)($_GET['device_id'] ?? 0);
if (!$deviceId && $devices) $deviceId = (int)$devices[0]['id'];

$tz = new DateTimeZone('Asia/Kolkata');
$today = new DateTimeImmutable('today', $tz);
$defaultFrom = $today->modify('-6 days')->format('Y-m-d');
$defaultTo   = $today->format('Y-m-d');

$fromStr = (string)($_GET['from'] ?? $defaultFrom);
$toStr   = (string)($_GET['to']   ?? $defaultTo);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromStr)) $fromStr = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toStr))   $toStr   = $defaultTo;

$fromDt = DateTimeImmutable::createFromFormat('!Y-m-d', $fromStr, $tz) ?: new DateTimeImmutable($defaultFrom, $tz);
$toDt   = DateTimeImmutable::createFromFormat('!Y-m-d', $toStr,   $tz) ?: new DateTimeImmutable($defaultTo, $tz);
if ($toDt < $fromDt) { [$fromDt, $toDt] = [$toDt, $fromDt]; }

// Cap at 31 days so a typo like 1990 → today doesn't render forever.
$daySpan = (int)$fromDt->diff($toDt)->format('%a') + 1;
if ($daySpan > 31) {
    $toDt = $fromDt->modify('+30 days');
    $daySpan = 31;
}

// --- Resolve selected device for label + poll interval
$device = null;
foreach ($devices as $d) {
    if ((int)$d['id'] === $deviceId) { $device = $d; break; }
}
$pollInterval = (int)($device['poll_interval_seconds'] ?? 300);
if ($pollInterval <= 0) $pollInterval = 300;
$toleranceSec = $pollInterval * 2;
$halfPoll = (int)round($pollInterval / 2);

// --- Shift window per day: 08:00 → 03:00 next day = 19 h
$shiftStartHour = 8;
$shiftEndHour   = 27; // 24 + 3
$windowSec = ($shiftEndHour - $shiftStartHour) * 3600;

// --- Query all pings in [first day 08:00 .. last day 03:00 next day]
$rangeStart = $fromDt->setTime($shiftStartHour, 0, 0);
$rangeEnd   = $toDt->modify('+1 day')->setTime(3, 0, 0);

$pings = [];
if ($device && $logPingsEnabled) {
    $stmt = $pdo->prepare(
        "SELECT UNIX_TIMESTAMP(timestamp) AS ts
           FROM device_logs
          WHERE device_id = ?
            AND event_type = 'ping'
            AND timestamp >= ?
            AND timestamp <= ?
          ORDER BY timestamp ASC"
    );
    $stmt->execute([
        $deviceId,
        $rangeStart->format('Y-m-d H:i:s'),
        $rangeEnd->format('Y-m-d H:i:s'),
    ]);
    while ($r = $stmt->fetch()) $pings[] = (int)$r['ts'];
}

// --- Merge pings into online bands. Two pings within tolerance => same
//     band. Each band's outer edges extend by half a poll interval so the
//     visual matches the "device was alive around that time" intent.
$bands = [];
foreach ($pings as $ts) {
    if (!$bands) {
        $bands[] = ['start' => $ts - $halfPoll, 'end' => $ts + $halfPoll];
        continue;
    }
    $lastIdx = count($bands) - 1;
    if ($ts - $bands[$lastIdx]['end'] <= $toleranceSec) {
        $bands[$lastIdx]['end'] = $ts + $halfPoll;
    } else {
        $bands[] = ['start' => $ts - $halfPoll, 'end' => $ts + $halfPoll];
    }
}

// --- Build per-day rows by intersecting each band with the day's window.
$days = [];
$cursor = $fromDt;
for ($i = 0; $i < $daySpan; $i++) {
    $wStart = $cursor->setTime($shiftStartHour, 0, 0)->getTimestamp();
    $wEnd   = $cursor->modify('+1 day')->setTime(3, 0, 0)->getTimestamp();
    $rowBands = [];
    $onlineSec = 0;
    foreach ($bands as $b) {
        $cs = max($b['start'], $wStart);
        $ce = min($b['end'],   $wEnd);
        if ($ce > $cs) {
            $rowBands[] = [
                'left'  => ($cs - $wStart) / $windowSec * 100,
                'width' => ($ce - $cs) / $windowSec * 100,
            ];
            $onlineSec += ($ce - $cs);
        }
    }
    $days[] = [
        'label'      => $cursor->format('D j M'),
        'date'       => $cursor->format('Y-m-d'),
        'bands'      => $rowBands,
        'online_sec' => $onlineSec,
    ];
    $cursor = $cursor->modify('+1 day');
}

// --- Hour axis ticks: every 2 h across the 19 h window
$hourTicks = [];
for ($h = $shiftStartHour; $h <= $shiftEndHour; $h += 2) {
    $hourTicks[] = [
        'label' => sprintf('%02d:00', $h % 24),
        'left'  => ($h - $shiftStartHour) / ($shiftEndHour - $shiftStartHour) * 100,
    ];
}

function fmt_duration(int $sec): string {
    if ($sec <= 0) return '0m';
    $h = intdiv($sec, 3600);
    $m = intdiv($sec % 3600, 60);
    if ($h && $m) return $h . 'h ' . $m . 'm';
    if ($h)       return $h . 'h';
    return $m . 'm';
}

layout_header('Device timeline', 'timeline');
?>
<h1 class="h3 mb-3">Device timeline</h1>

<?php if (!$logPingsEnabled): ?>
<div class="alert alert-warning">
  <strong>Ping logging is OFF.</strong>
  No data will appear here until <code>log_pings</code> is set to <code>true</code>
  in your server <code>config.php</code>. (Pings still update <code>last_seen</code>,
  but per-heartbeat history isn't being persisted.)
</div>
<?php endif; ?>

<?php if (!$devices): ?>
<div class="alert alert-info">No devices registered yet.</div>
<?php else: ?>

<form method="get" class="row g-2 align-items-end mb-4">
  <div class="col-md-5">
    <label class="form-label small text-muted mb-1">Device</label>
    <select name="device_id" class="form-select">
      <?php foreach ($devices as $d):
        $label = $d['display_name'] ?: $d['android_id']; ?>
      <option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $deviceId ? 'selected' : '' ?>>
        <?= h($label) ?>
        <?php if ($d['display_name']): ?> · <?= h($d['android_id']) ?><?php endif; ?>
      </option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-md-2">
    <label class="form-label small text-muted mb-1">From</label>
    <input type="date" name="from" value="<?= h($fromDt->format('Y-m-d')) ?>" class="form-control">
  </div>
  <div class="col-md-2">
    <label class="form-label small text-muted mb-1">To</label>
    <input type="date" name="to" value="<?= h($toDt->format('Y-m-d')) ?>" class="form-control">
  </div>
  <div class="col-md-3">
    <button class="btn btn-primary w-100">Apply</button>
  </div>
</form>

<?php
  $devLabel = $device ? ($device['display_name'] ?: $device['android_id']) : '(none)';
  $totalOnline = array_sum(array_column($days, 'online_sec'));
  $totalWindow = $daySpan * $windowSec;
  $uptimePct = $totalWindow > 0 ? round($totalOnline / $totalWindow * 100, 1) : 0;
?>

<div class="card mb-3">
  <div class="card-body">
    <div class="d-flex justify-content-between flex-wrap mb-3">
      <div>
        <div class="small text-muted">Device</div>
        <strong><?= h($devLabel) ?></strong>
      </div>
      <div>
        <div class="small text-muted">Range</div>
        <strong><?= h($fromDt->format('D j M Y')) ?> → <?= h($toDt->format('D j M Y')) ?></strong>
        <span class="text-muted">(<?= (int)$daySpan ?> day<?= $daySpan === 1 ? '' : 's' ?>)</span>
      </div>
      <div>
        <div class="small text-muted">Pings in range</div>
        <strong><?= count($pings) ?></strong>
      </div>
      <div>
        <div class="small text-muted">Uptime (shift hours)</div>
        <strong class="<?= $uptimePct >= 95 ? 'text-success' : ($uptimePct >= 60 ? 'text-warning' : 'text-danger') ?>">
          <?= h(number_format($uptimePct, 1)) ?>%
        </strong>
        <span class="text-muted">(<?= h(fmt_duration($totalOnline)) ?> of <?= h(fmt_duration($totalWindow)) ?>)</span>
      </div>
      <div>
        <div class="small text-muted">Poll interval</div>
        <strong><?= (int)$pollInterval ?>s</strong>
      </div>
    </div>

    <div class="small text-muted mb-2">
      Each row: <strong>08:00 → 03:00 next day</strong>. A gap longer than
      <?= (int)$toleranceSec ?>s (2× poll interval) counts as offline.
    </div>

    <div class="timeline-axis" style="position:relative; height:20px; margin-left:90px; border-bottom:1px solid #dee2e6;">
      <?php foreach ($hourTicks as $t): ?>
        <span style="position:absolute; left:<?= h((string)$t['left']) ?>%; transform:translateX(-50%); font-size:11px; color:#6c757d;">
          <?= h($t['label']) ?>
        </span>
      <?php endforeach; ?>
    </div>

    <?php foreach ($days as $day): ?>
      <div class="d-flex align-items-center" style="height:30px; margin-top:4px;">
        <div class="small text-muted" style="width:90px;">
          <?= h($day['label']) ?>
        </div>
        <div style="flex:1; position:relative; height:20px; background:#e9ecef; border-radius:3px;"
             title="<?= h(fmt_duration($day['online_sec'])) ?> online">
          <?php foreach ($day['bands'] as $b): ?>
            <div style="position:absolute; top:0; bottom:0;
                        left:<?= h((string)$b['left']) ?>%;
                        width:<?= h((string)$b['width']) ?>%;
                        background:#198754; border-radius:3px;"></div>
          <?php endforeach; ?>
        </div>
        <div class="small text-muted ps-2" style="width:80px; text-align:right;">
          <?= h(fmt_duration($day['online_sec'])) ?>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="d-flex gap-3 mt-3 small text-muted">
      <span><span style="display:inline-block; width:16px; height:10px; background:#198754; vertical-align:middle; border-radius:2px;"></span> Online</span>
      <span><span style="display:inline-block; width:16px; height:10px; background:#e9ecef; vertical-align:middle; border-radius:2px;"></span> Offline / no data</span>
    </div>
  </div>
</div>

<?php endif; ?>
<?php layout_footer();
