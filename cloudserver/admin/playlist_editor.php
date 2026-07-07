<?php
require __DIR__ . '/_auth.php';
admin_require_login();
require __DIR__ . '/_layout.php';

$pdo = admin_pdo();

$deviceId = (int)($_GET['device_id'] ?? 0);
if (!$deviceId) { header('Location: devices.php'); exit; }

$dev = $pdo->prepare(
    'SELECT d.id, d.android_id, d.display_name, s.name AS store_name, c.name AS city_name
       FROM devices d LEFT JOIN stores s ON s.id = d.store_id
       LEFT JOIN cities c ON c.id = s.city_id WHERE d.id = ?'
);
$dev->execute([$deviceId]);
$d = $dev->fetch();
if (!$d) { flash_set('Device not found.', 'error'); header('Location: devices.php'); exit; }

$weekday = strtolower($_GET['day'] ?? 'monday');
$weekdays = ['monday','tuesday','wednesday','thursday','friday','saturday','sunday'];
if (!in_array($weekday, $weekdays, true)) $weekday = 'monday';

// Image/video items that loop continuously. Hour-range columns
// (start_hour, end_hour) added in migration 009; tolerate their
// absence on pre-009 databases.
try {
    $items = $pdo->prepare(
        'SELECT pi.id, pi.media_id, pi.duration_seconds,
                pi.video_volume, pi.sort_order,
                pi.start_hour, pi.end_hour,
                m.original_name, m.filename, m.file_type
           FROM playlist_items pi JOIN media m ON m.id = pi.media_id
          WHERE pi.device_id = ? AND pi.weekday = ?
          ORDER BY pi.sort_order, pi.id'
    );
    $items->execute([$deviceId, $weekday]);
    $rows = $items->fetchAll();
} catch (PDOException $_) {
    $items = $pdo->prepare(
        'SELECT pi.id, pi.media_id, pi.duration_seconds,
                pi.video_volume, pi.sort_order,
                0 AS start_hour, 24 AS end_hour,
                m.original_name, m.filename, m.file_type
           FROM playlist_items pi JOIN media m ON m.id = pi.media_id
          WHERE pi.device_id = ? AND pi.weekday = ?
          ORDER BY pi.sort_order, pi.id'
    );
    $items->execute([$deviceId, $weekday]);
    $rows = $items->fetchAll();
}

// Background music settings for this weekday (volume + duck level).
// At most one row per (device, weekday).
$bgStmt = $pdo->prepare(
    'SELECT volume, video_duck_volume
       FROM device_bg_audio
      WHERE device_id = ? AND weekday = ?'
);
$bgStmt->execute([$deviceId, $weekday]);
$bg = $bgStmt->fetch() ?: ['volume' => 1.00, 'video_duck_volume' => 0.20];

// Track list — multiple files played in order on continuous loop.
// Per-track volume + duck columns added in migration 005; we tolerate them
// being absent so the editor still renders pre-migration.
try {
    $bgItemsStmt = $pdo->prepare(
        'SELECT dbai.id, dbai.audio_media_id, dbai.sort_order,
                dbai.volume, dbai.video_duck_volume,
                m.original_name
           FROM device_bg_audio_items dbai
           JOIN media m ON m.id = dbai.audio_media_id
          WHERE dbai.device_id = ? AND dbai.weekday = ?
          ORDER BY dbai.sort_order, dbai.id'
    );
    $bgItemsStmt->execute([$deviceId, $weekday]);
    $bgItems = $bgItemsStmt->fetchAll();
} catch (PDOException $_) {
    // Migration 005 not yet applied — fall back to no per-track columns.
    $bgItemsStmt = $pdo->prepare(
        'SELECT dbai.id, dbai.audio_media_id, dbai.sort_order,
                NULL AS volume, NULL AS video_duck_volume,
                m.original_name
           FROM device_bg_audio_items dbai
           JOIN media m ON m.id = dbai.audio_media_id
          WHERE dbai.device_id = ? AND dbai.weekday = ?
          ORDER BY dbai.sort_order, dbai.id'
    );
    $bgItemsStmt->execute([$deviceId, $weekday]);
    $bgItems = $bgItemsStmt->fetchAll();
}

// Time triggers for this weekday.
$trigStmt = $pdo->prepare(
    'SELECT pt.id, pt.trigger_time, pt.media_id, pt.volume,
            m.original_name, m.file_type
       FROM playlist_triggers pt
       JOIN media m ON m.id = pt.media_id
      WHERE pt.device_id = ? AND pt.weekday = ?
      ORDER BY pt.trigger_time, pt.id'
);
$trigStmt->execute([$deviceId, $weekday]);
$triggers = $trigStmt->fetchAll();

$mediaList     = $pdo->query("SELECT id, original_name, file_type FROM media WHERE file_type IN ('image','video') ORDER BY uploaded_at DESC")->fetchAll();
$audioList     = $pdo->query("SELECT id, original_name FROM media WHERE file_type = 'audio' ORDER BY uploaded_at DESC")->fetchAll();
$avMediaList   = $pdo->query("SELECT id, original_name, file_type FROM media WHERE file_type IN ('audio','video') ORDER BY uploaded_at DESC")->fetchAll();

// Other-devices list — feeds the "Copy this playlist to other devices" modal.
// Show every device EXCEPT the source so the modal can never accidentally
// copy a device onto itself.
$otherDevicesStmt = $pdo->prepare(
    'SELECT d.id, d.android_id, d.display_name, s.name AS store_name, c.name AS city_name
       FROM devices d
       LEFT JOIN stores s ON s.id = d.store_id
       LEFT JOIN cities c ON c.id = s.city_id
      WHERE d.id <> ?
      ORDER BY d.assigned DESC, c.name, s.name, d.display_name, d.android_id'
);
$otherDevicesStmt->execute([$deviceId]);
$otherDevices = $otherDevicesStmt->fetchAll();

layout_header('Playlist · ' . ($d['display_name'] ?: $d['android_id']), 'devices');
?>
<nav><a href="devices.php" class="text-decoration-none">← Devices</a> · <a href="device_edit.php?id=<?= (int)$d['id'] ?>" class="text-decoration-none">Device settings</a></nav>
<h1 class="h3 my-3">Playlist · <?= h($d['display_name'] ?: $d['android_id']) ?></h1>
<p class="text-muted small">
  <?= h($d['city_name'] ?: '—') ?> / <?= h($d['store_name'] ?: '—') ?> · <code><?= h($d['android_id']) ?></code>
</p>

<ul class="nav nav-pills mb-3">
  <?php foreach ($weekdays as $w): ?>
    <li class="nav-item">
      <a class="nav-link <?= $w === $weekday ? 'active' : '' ?>"
         href="playlist_editor.php?device_id=<?= (int)$deviceId ?>&day=<?= h($w) ?>">
         <?= h(ucfirst($w)) ?>
      </a>
    </li>
  <?php endforeach; ?>
</ul>

<!-- ============================================================= -->
<!-- Form 1: items + background music. Saves both in one submit.   -->
<!-- Items loop continuously; background music plays continuously   -->
<!-- in parallel and ducks automatically when a video with sound    -->
<!-- comes up.                                                      -->
<!-- ============================================================= -->
<form method="post" action="playlist_save.php" id="plf">
  <?= csrf_field() ?>
  <input type="hidden" name="device_id" value="<?= (int)$deviceId ?>">
  <input type="hidden" name="weekday" value="<?= h($weekday) ?>">

  <div class="card mb-3 border-info"><div class="card-body">
    <h2 class="h6 text-info">🎵 Background music — plays continuously on <?= h(ucfirst($weekday)) ?></h2>
    <p class="text-muted small mb-2">
      Multiple tracks are played in order on a continuous loop. Drag to reorder; ✕ to remove.
      When a video with sound plays, the bg drops to "Vol while video plays %" so the video stays audible, then restores when the next image starts.
    </p>

    <!-- Volume controls -->
    <div class="row g-2 align-items-end mb-3">
      <div class="col-md-3">
        <label class="form-label" title="Bg-music level when no video is playing">Volume %</label>
        <input class="form-control" type="number" min="0" max="100" step="5"
               name="bg_audio_volume_pct"
               value="<?= (int)round((float)$bg['volume'] * 100) ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label" title="Bg-music level while a video with sound is playing — 0 = fully muted, 20 = default ducking">Vol while video plays %</label>
        <input class="form-control" type="number" min="0" max="100" step="5"
               name="bg_audio_duck_pct"
               value="<?= (int)round((float)$bg['video_duck_volume'] * 100) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label">Add track to bg loop</label>
        <div class="input-group">
          <select class="form-select" id="bg-add-media">
            <option value="">— Pick from audio library —</option>
            <?php foreach ($audioList as $a): ?>
              <option value="<?= (int)$a['id'] ?>" data-name="<?= h($a['original_name']) ?>">
                <?= h($a['original_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button type="button" class="btn btn-info" id="bg-add-btn">Add</button>
        </div>
      </div>
    </div>

    <!-- Track list -->
    <?php
      // Per-track defaults: if NULL in DB, blank the field so the writer
      // sees it as "use the per-day default" instead of forcing 100/20.
      $bgRowDefaults = [
        'vol_pct'  => (int)round((float)$bg['volume'] * 100),
        'duck_pct' => (int)round((float)$bg['video_duck_volume'] * 100),
      ];
    ?>
    <ul class="list-group" id="bg-items-list">
      <?php foreach ($bgItems as $bi): ?>
        <?php
          $rowVolPct  = $bi['volume']            !== null ? (int)round((float)$bi['volume']            * 100) : $bgRowDefaults['vol_pct'];
          $rowDuckPct = $bi['video_duck_volume'] !== null ? (int)round((float)$bi['video_duck_volume'] * 100) : $bgRowDefaults['duck_pct'];
        ?>
        <li class="list-group-item" data-id="<?= (int)$bi['id'] ?>">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="handle text-muted me-2" title="drag to reorder" style="cursor: grab;">⠿</span>
            <strong class="me-2">[audio]</strong>
            <span class="flex-grow-1 text-truncate" style="min-width: 120px;"><?= h($bi['original_name']) ?></span>
            <input type="hidden" name="bg_items[<?= (int)$bi['id'] ?>][audio_media_id]" value="<?= (int)$bi['audio_media_id'] ?>">
            <label class="small mb-0" title="Per-track playback volume %, overrides per-day default">Vol</label>
            <input class="form-control form-control-sm" style="width: 70px"
                   type="number" min="0" max="100" step="5"
                   name="bg_items[<?= (int)$bi['id'] ?>][volume_pct]"
                   value="<?= $rowVolPct ?>">
            <label class="small mb-0" title="Per-track 'volume while video plays' %, overrides per-day default">VidVol</label>
            <input class="form-control form-control-sm" style="width: 70px"
                   type="number" min="0" max="100" step="5"
                   name="bg_items[<?= (int)$bi['id'] ?>][duck_pct]"
                   value="<?= $rowDuckPct ?>">
            <!-- Inline onclick is a fallback for when app.js is stale-cached or
                 missing — the delegated handler in app.js still works too. -->
            <button type="button" class="btn btn-outline-danger btn-sm bg-remove-item"
                    onclick="this.closest('li').remove(); return false;">×</button>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$bgItems): ?>
      <p class="text-muted small mt-2 mb-0">No background tracks yet — add one or more from the library above.</p>
    <?php endif; ?>

    <!-- Hidden template for newly-added bg tracks. The Vol/VidVol defaults
         are seeded from the per-day values above so a quickly-added track
         inherits whatever the user just set, no extra typing needed. -->
    <template id="bg-new-item-tpl">
      <li class="list-group-item" data-id="">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="handle text-muted me-2" style="cursor: grab;">⠿</span>
          <strong class="me-2">[audio]</strong>
          <span class="flex-grow-1 text-truncate bg-name-label" style="min-width: 120px;"></span>
          <input type="hidden" name="bg_items[__BGKEY__][audio_media_id]" value="">
          <label class="small mb-0">Vol</label>
          <input class="form-control form-control-sm" style="width: 70px"
                 type="number" min="0" max="100" step="5"
                 name="bg_items[__BGKEY__][volume_pct]"
                 value="<?= $bgRowDefaults['vol_pct'] ?>">
          <label class="small mb-0">VidVol</label>
          <input class="form-control form-control-sm" style="width: 70px"
                 type="number" min="0" max="100" step="5"
                 name="bg_items[__BGKEY__][duck_pct]"
                 value="<?= $bgRowDefaults['duck_pct'] ?>">
          <button type="button" class="btn btn-outline-danger btn-sm bg-remove-item"
                  onclick="this.closest('li').remove(); return false;">×</button>
        </div>
      </li>
    </template>
  </div></div>

  <div class="card mb-3"><div class="card-body">
    <div class="row g-2">
      <div class="col-md-6">
        <label class="form-label">Add item</label>
        <select class="form-select" id="add-media">
          <option value="">— Pick from library —</option>
          <?php foreach ($mediaList as $m): ?>
            <option value="<?= (int)$m['id'] ?>" data-type="<?= h($m['file_type']) ?>" data-name="<?= h($m['original_name']) ?>">
              [<?= h($m['file_type']) ?>] <?= h($m['original_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Default duration (s)</label>
        <input type="number" min="1" class="form-control" id="add-duration" value="10">
        <div class="form-text">Ignored for videos (play full length).</div>
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <button type="button" class="btn btn-primary w-100" id="add-btn">Add to <?= h(ucfirst($weekday)) ?></button>
      </div>
    </div>
  </div></div>

  <div class="card"><div class="card-body">
    <h2 class="h6">Items — drag to reorder (loops continuously)</h2>
    <ul class="list-group" id="items-list" data-weekday="<?= h($weekday) ?>">
      <?php foreach ($rows as $r): ?>
        <?php
          $isVideo = $r['file_type'] === 'video';
          $vVolPct = $r['video_volume'] !== null ? (int)round((float)$r['video_volume'] * 100) : 100;
        ?>
        <li class="list-group-item" data-id="<?= (int)$r['id'] ?>">
          <div class="d-flex align-items-center gap-2 flex-wrap">
            <span class="handle text-muted me-2" title="drag to reorder" style="cursor: grab;">⠿</span>
            <strong class="me-2">[<?= h($r['file_type']) ?>]</strong>
            <span class="flex-grow-1 text-truncate" style="min-width: 120px;"><?= h($r['original_name']) ?></span>
            <input type="hidden" name="items[<?= (int)$r['id'] ?>][media_id]" value="<?= (int)$r['media_id'] ?>">
            <label class="small mb-0" title="Display duration in seconds (videos play full length)">Dur</label>
            <input class="form-control form-control-sm" style="width: 70px"
                   type="number" min="1"
                   name="items[<?= (int)$r['id'] ?>][duration_seconds]"
                   value="<?= (int)$r['duration_seconds'] ?>" <?= $isVideo ? 'disabled' : '' ?>>
            <label class="small mb-0" title="Video sound volume %, 0 = muted (videos only)">VVol</label>
            <input class="form-control form-control-sm vidvol" style="width: 70px"
                   type="number" min="0" max="100" step="5"
                   name="items[<?= (int)$r['id'] ?>][video_volume_pct]"
                   value="<?= $vVolPct ?>" <?= $isVideo ? '' : 'disabled' ?>>
            <label class="small mb-0" title="Hour range (24-hour clock). Item plays from start (inclusive) to end (exclusive). Default 0-24 = all day. Example: 9-12 means 09:00-11:59.">Hr</label>
            <input class="form-control form-control-sm" style="width: 55px"
                   type="number" min="0" max="23" step="1"
                   name="items[<?= (int)$r['id'] ?>][start_hour]"
                   value="<?= (int)($r['start_hour'] ?? 0) ?>">
            <span class="small">–</span>
            <input class="form-control form-control-sm" style="width: 55px"
                   type="number" min="1" max="24" step="1"
                   name="items[<?= (int)$r['id'] ?>][end_hour]"
                   value="<?= (int)($r['end_hour'] ?? 24) ?>">
            <button type="button" class="btn btn-outline-danger btn-sm remove-item"
                    onclick="this.closest('li').remove(); return false;">×</button>
          </div>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$rows): ?>
      <p class="text-muted mt-3 mb-0">No items yet — pick from the library above.</p>
    <?php endif; ?>
  </div></div>

  <input type="hidden" name="copy_to_all" id="copy_to_all" value="0">
  <div class="mt-3 d-flex gap-2 flex-wrap">
    <button class="btn btn-success" type="submit">Save &amp; publish</button>
    <button class="btn btn-warning" type="submit"
            onclick="if(!confirm('Save this <?= h(ucfirst($weekday)) ?> playlist (including bg music) and copy it to ALL 7 days?\n\nThis overwrites the other 6 days for this device.')){return false;} document.getElementById('copy_to_all').value='1';">
      Save &amp; copy to all days
    </button>
    <a class="btn btn-outline-secondary" href="playlist_editor.php?device_id=<?= (int)$deviceId ?>&day=<?= h($weekday) ?>">Reset</a>
    <!-- Cross-device copy: lives outside the Save form so it doesn't
         accidentally include unsaved item edits. Operates on the
         currently-saved DB state. -->
    <button type="button" class="btn btn-outline-info ms-auto"
            data-bs-toggle="modal" data-bs-target="#copyToDevicesModal">
      Copy this playlist to other devices…
    </button>
  </div>

  <!-- Hidden fields for newly added items: rendered into list-items by app.js -->
  <template id="new-item-tpl">
    <li class="list-group-item" data-id="">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="handle text-muted me-2" style="cursor: grab;">⠿</span>
        <strong class="me-2 type-label"></strong>
        <span class="flex-grow-1 text-truncate name-label" style="min-width: 120px;"></span>
        <input type="hidden" name="items[__KEY__][media_id]" value="">
        <label class="small mb-0">Dur</label>
        <input class="form-control form-control-sm dur" style="width: 70px"
               type="number" min="1" name="items[__KEY__][duration_seconds]" value="10">
        <label class="small mb-0" title="Video sound volume %, 0 = muted">VVol</label>
        <input class="form-control form-control-sm vidvol" style="width: 70px"
               type="number" min="0" max="100" step="5"
               name="items[__KEY__][video_volume_pct]" value="100" disabled>
        <label class="small mb-0" title="Hour range (24h clock). 0-24 = all day. Example: 9-12 plays 09:00-11:59 only.">Hr</label>
        <input class="form-control form-control-sm" style="width: 55px"
               type="number" min="0" max="23" step="1"
               name="items[__KEY__][start_hour]" value="0">
        <span class="small">–</span>
        <input class="form-control form-control-sm" style="width: 55px"
               type="number" min="1" max="24" step="1"
               name="items[__KEY__][end_hour]" value="24">
        <button type="button" class="btn btn-outline-danger btn-sm remove-item"
                onclick="this.closest('li').remove(); return false;">×</button>
      </div>
    </li>
  </template>
</form>

<!-- ============================================================= -->
<!-- Inline editor wiring. Lives here (not in app.js) so it always   -->
<!-- ships with the editor PHP — a stale-cached app.js can never     -->
<!-- block Add or break form submission. Drag-reorder still requires -->
<!-- app.js + SortableJS, which is a nice-to-have, not critical.    -->
<!-- ============================================================= -->
<script>
(function () {
  'use strict';

  var form    = document.getElementById('plf');
  var list    = document.getElementById('items-list');
  var bgList  = document.getElementById('bg-items-list');

  // -------- Items: Add to <weekday> --------
  var addBtn  = document.getElementById('add-btn');
  var addSel  = document.getElementById('add-media');
  var addDur  = document.getElementById('add-duration');
  var itemTpl = document.getElementById('new-item-tpl');
  var nextItemKey = 1;

  if (addBtn && addSel && itemTpl && list) {
    addBtn.addEventListener('click', function () {
      var opt = addSel.options[addSel.selectedIndex];
      if (!opt || !opt.value) return;
      var key = 'new_' + (nextItemKey++);
      var html = itemTpl.innerHTML.replace(/__KEY__/g, key);
      var wrap = document.createElement('div');
      wrap.innerHTML = html.trim();
      var li = wrap.firstElementChild;
      li.querySelector('.type-label').textContent = '[' + (opt.dataset.type || '') + ']';
      li.querySelector('.name-label').textContent = opt.dataset.name || '';
      li.querySelector('input[name$="[media_id]"]').value = opt.value;
      var dur = li.querySelector('.dur');
      if (dur) {
        dur.value = parseInt(addDur.value || '10', 10) || 10;
        if (opt.dataset.type === 'video') dur.disabled = true;
      }
      var vidVol = li.querySelector('.vidvol');
      if (vidVol) vidVol.disabled = opt.dataset.type !== 'video';
      list.appendChild(li);
      addSel.selectedIndex = 0;
    });
  }

  // -------- Background music: Add track --------
  var bgAddBtn = document.getElementById('bg-add-btn');
  var bgAddSel = document.getElementById('bg-add-media');
  var bgTpl    = document.getElementById('bg-new-item-tpl');
  var nextBgKey = 1;

  if (bgAddBtn && bgAddSel && bgTpl && bgList) {
    bgAddBtn.addEventListener('click', function () {
      var opt = bgAddSel.options[bgAddSel.selectedIndex];
      if (!opt || !opt.value) return;
      var key = 'bgnew_' + (nextBgKey++);
      var html = bgTpl.innerHTML.replace(/__BGKEY__/g, key);
      var wrap = document.createElement('div');
      wrap.innerHTML = html.trim();
      var li = wrap.firstElementChild;
      li.querySelector('.bg-name-label').textContent = opt.dataset.name || '';
      li.querySelector('input[name$="[audio_media_id]"]').value = opt.value;

      // Seed the new row's Vol / VidVol from the CURRENT per-day inputs
      // above, not the page-load defaults baked into the template. So if
      // the user has already typed "70" into the top Volume % field, the
      // newly-added track inherits 70 (matching what they almost certainly
      // intend) instead of the older saved value.
      var curVol  = document.querySelector('input[name="bg_audio_volume_pct"]');
      var curDuck = document.querySelector('input[name="bg_audio_duck_pct"]');
      var rowVol  = li.querySelector('input[name$="[volume_pct]"]');
      var rowDuck = li.querySelector('input[name$="[duck_pct]"]');
      if (curVol  && rowVol)  rowVol.value  = curVol.value;
      if (curDuck && rowDuck) rowDuck.value = curDuck.value;

      bgList.appendChild(li);
      bgAddSel.selectedIndex = 0;
    });
  }

  // -------- On submit: append item_order[] + bg_item_order[] --------
  // Must run AFTER any duplicate-attached handlers from app.js, but order
  // doesn't matter functionally — the duplicates are idempotent (we wipe
  // any pre-existing order inputs before appending fresh ones).
  if (form) {
    form.addEventListener('submit', function () {
      function rebuildOrder(name, fromList, inputPrefix) {
        if (!fromList) return;
        form.querySelectorAll('input[name="' + name + '"]').forEach(function (n) { n.remove(); });
        fromList.querySelectorAll('li').forEach(function (li) {
          var key = li.dataset.id;
          if (!key) {
            var keyInput = li.querySelector('input[name^="' + inputPrefix + '"]');
            if (keyInput) {
              var m = keyInput.name.match(new RegExp(inputPrefix.replace(/[\[\]]/g, '\\$&') + '([^\\]]+)'));
              if (m) key = m[1];
            }
          }
          if (!key) return;
          var i = document.createElement('input');
          i.type = 'hidden'; i.name = name; i.value = key;
          form.appendChild(i);
        });
      }
      rebuildOrder('item_order[]',    list,   'items[');
      rebuildOrder('bg_item_order[]', bgList, 'bg_items[');
    });
  }
})();
</script>

<!-- ============================================================= -->
<!-- Triggers — own form so adding/deleting doesn't mess with       -->
<!-- the items form above.                                          -->
<!-- ============================================================= -->
<div class="card mt-4 border-warning"><div class="card-body">
  <h2 class="h6 text-warning">⏰ Time triggers — fire at fixed times on <?= h(ucfirst($weekday)) ?></h2>
  <p class="text-muted small mb-3">
    At each scheduled time, the player interrupts the loop, plays the chosen audio or video to its natural end, then resumes.
    Useful for store announcements, hourly chimes, lunchtime promos, etc.
  </p>

  <?php if ($triggers): ?>
    <table class="table table-sm align-middle">
      <thead class="table-light">
        <tr><th style="width: 100px">Time</th><th>Media</th><th style="width: 90px">Volume</th><th style="width: 80px"></th></tr>
      </thead>
      <tbody>
        <?php foreach ($triggers as $t): ?>
          <tr>
            <td><code><?= h(substr((string)$t['trigger_time'], 0, 5)) ?></code></td>
            <td>
              <span class="badge text-bg-secondary me-2"><?= h($t['file_type']) ?></span>
              <?= h($t['original_name']) ?>
            </td>
            <td><?= (int)round((float)$t['volume'] * 100) ?>%</td>
            <td>
              <form method="post" action="trigger_delete.php" class="d-inline"
                    onsubmit="return confirm('Delete this trigger?');">
                <?= csrf_field() ?>
                <input type="hidden" name="device_id"  value="<?= (int)$deviceId ?>">
                <input type="hidden" name="weekday"    value="<?= h($weekday) ?>">
                <input type="hidden" name="trigger_id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-sm btn-outline-danger">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <p class="text-muted small">No triggers scheduled for <?= h(ucfirst($weekday)) ?>.</p>
  <?php endif; ?>

  <form method="post" action="trigger_save.php" class="row g-2 align-items-end">
    <?= csrf_field() ?>
    <input type="hidden" name="device_id" value="<?= (int)$deviceId ?>">
    <input type="hidden" name="weekday"   value="<?= h($weekday) ?>">
    <div class="col-md-2">
      <label class="form-label small">Time (24h)</label>
      <input type="time" name="trigger_time" class="form-control" required>
    </div>
    <div class="col-md-6">
      <label class="form-label small">Media (audio or video)</label>
      <select class="form-select" name="media_id" required>
        <option value="">— Pick —</option>
        <?php foreach ($avMediaList as $m): ?>
          <option value="<?= (int)$m['id'] ?>">
            [<?= h($m['file_type']) ?>] <?= h($m['original_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2">
      <label class="form-label small">Volume %</label>
      <input type="number" name="volume_pct" class="form-control" min="0" max="100" step="5" value="100">
    </div>
    <div class="col-md-2">
      <button class="btn btn-warning w-100">Add trigger</button>
    </div>
  </form>
</div></div>

<!-- ============================================================= -->
<!-- Cross-device copy modal. Triggered by the "Copy this playlist  -->
<!-- to other devices…" button above. Posts to playlist_copy_to_     -->
<!-- devices.php which wipes + clones items/bg-music/triggers for    -->
<!-- all 7 days from this device onto the selected targets.          -->
<!-- ============================================================= -->
<div class="modal fade" id="copyToDevicesModal" tabindex="-1" aria-labelledby="copyToDevicesModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form method="post" action="playlist_copy_to_devices.php" class="modal-content"
          onsubmit="return confirm('Copy THIS device\'s playlist (items + bg music + triggers, all 7 days) to the selected device(s)?\n\nEach target\'s existing playlist will be COMPLETELY OVERWRITTEN.');">
      <?= csrf_field() ?>
      <input type="hidden" name="source_device_id" value="<?= (int)$deviceId ?>">

      <div class="modal-header">
        <h5 class="modal-title" id="copyToDevicesModalLabel">
          Copy <?= h($d['display_name'] ?: $d['android_id']) ?>'s playlist to other devices
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <p class="text-muted small mb-3">
          Cloning includes items + per-item volumes, the full background-music
          configuration, and all time triggers, for <strong>all 7 days</strong>.
          Each target device's existing playlist is overwritten.
        </p>

        <?php if (!$otherDevices): ?>
          <div class="alert alert-info mb-0">No other devices to copy to.</div>
        <?php else: ?>
          <div class="d-flex gap-2 mb-2">
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    onclick="document.querySelectorAll('#copyToDevicesModal input[name=&quot;target_device_ids[]&quot;]').forEach(function(c){c.checked=true;});">
              Select all
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary"
                    onclick="document.querySelectorAll('#copyToDevicesModal input[name=&quot;target_device_ids[]&quot;]').forEach(function(c){c.checked=false;});">
              Clear
            </button>
          </div>
          <table class="table table-sm align-middle mb-0">
            <thead class="table-light">
              <tr>
                <th style="width: 40px;"></th>
                <th>City / Store</th>
                <th>Display name</th>
                <th>ANDROID_ID</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($otherDevices as $od): ?>
                <tr>
                  <td>
                    <input type="checkbox" class="form-check-input"
                           name="target_device_ids[]"
                           id="copyTarget_<?= (int)$od['id'] ?>"
                           value="<?= (int)$od['id'] ?>">
                  </td>
                  <td>
                    <label class="form-check-label" for="copyTarget_<?= (int)$od['id'] ?>">
                      <?= h(($od['city_name'] ?: '—') . ' / ' . ($od['store_name'] ?: '—')) ?>
                    </label>
                  </td>
                  <td>
                    <label class="form-check-label" for="copyTarget_<?= (int)$od['id'] ?>">
                      <?= h($od['display_name'] ?: '—') ?>
                    </label>
                  </td>
                  <td><code><?= h($od['android_id']) ?></code></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <?php if ($otherDevices): ?>
          <button type="submit" class="btn btn-info">
            Copy to selected devices
          </button>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<?php layout_footer();
