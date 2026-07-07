/**
 * DangeeCast Service Worker — disk-backed offline cache.
 *
 * Lives at /signage/sw.js so its default scope (/signage/) covers the
 * player HTML, playlist JSON, and media bytes.
 *
 * What it caches and how:
 *   1. Player HTML (/signage/player/index.html)
 *      Pre-cached on install. Network-first with cache fallback. Allows
 *      the WebView to boot offline as long as a previous online boot
 *      cached the page.
 *   2. Playlist JSON (/signage/devices/<id>/playlist.json)
 *      Network-first with cache fallback. After first online poll, the
 *      box can boot offline and play the last-known schedule.
 *   3. Media (/signage/media/*)
 *      Cache-first with network fallback. Once cached, never touches
 *      the network.
 *
 * 'sync-cache' messages from the page (sent on each playlist apply)
 * fetch missing media URLs and DELETE cached media not in the new
 * playlist — implements "delete content not defined in playlist".
 *
 * Quota: browser-managed. Eviction of old entries when quota is
 * exceeded is the browser's job.
 */

'use strict';

const CACHE_NAME = 'signage-cache-v2';
const PLAYER_HTML_PATH = '/signage/player/index.html';
const MEDIA_PATH_PREFIX = '/signage/media/';
const PLAYLIST_PATH_RE  = /^\/signage\/devices\/[^\/]+\/playlist\.json$/;

// ---------- Lifecycle ----------

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    // Pre-cache the player HTML so the box can boot offline. cache:'reload'
    // bypasses the browser's HTTP cache so we get the canonical version,
    // not whatever stale copy might be sitting around.
    try {
      const cache = await caches.open(CACHE_NAME);
      const resp = await fetch(PLAYER_HTML_PATH, { cache: 'reload' });
      if (resp.ok) {
        await cache.put(PLAYER_HTML_PATH, resp);
      }
    } catch (e) {
      // Ignore — first request after activation will populate the cache.
    }
    self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    // Drop any old cache versions so v1 entries don't accumulate after
    // we bumped to v2.
    const names = await caches.keys();
    await Promise.all(
      names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n))
    );
    await self.clients.claim();
  })());
});

// ---------- Fetch interception ----------

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') return;

  const url = new URL(event.request.url);

  // Player HTML — network-first with cache fallback. Cached using a
  // canonical (no-query) key so the cache-bust query the APK appends
  // (?v=N&t=ms) on every boot doesn't create a different cache entry.
  if (url.pathname === PLAYER_HTML_PATH) {
    event.respondWith(networkFirstThenCache(event.request, PLAYER_HTML_PATH));
    return;
  }

  // Playlist JSON — same network-first pattern. Cached per device URL.
  if (PLAYLIST_PATH_RE.test(url.pathname)) {
    event.respondWith(networkFirstThenCache(event.request, url.pathname));
    return;
  }

  // Media — cache-first; once cached, never touches the network. This
  // is what makes the screen keep cycling through a server outage.
  if (url.pathname.startsWith(MEDIA_PATH_PREFIX)) {
    event.respondWith(cacheFirstThenNetwork(event.request));
    return;
  }

  // Everything else (API, fonts from CDN, anything else) falls through
  // to the network with no SW interference.
});

/**
 * Cache-first: prefer cached response; fall back to network and store on
 * 2xx. Used for media bytes — they don't change once uploaded (the URL
 * carries a ?v=mtime cache-bust so a new version of the same media file
 * has a different URL).
 */
async function cacheFirstThenNetwork(request) {
  const cache = await caches.open(CACHE_NAME);
  const cached = await cache.match(request);
  if (cached) return cached;

  try {
    const response = await fetch(request);
    if (response.ok) {
      await cache.put(request, response.clone());
    }
    return response;
  } catch (e) {
    return new Response('', { status: 504, statusText: 'SW: offline + uncached' });
  }
}

/**
 * Network-first: try the network; if it fails (or returns non-2xx), fall
 * back to whatever's cached under `cacheKeyPath`. On 2xx, store the new
 * response under that same key.
 *
 * cacheKeyPath is canonical (no query string) so cache-bust queries on
 * the request don't create separate entries — at any point in time the
 * cache holds at most one version of each path.
 */
async function networkFirstThenCache(request, cacheKeyPath) {
  const cache = await caches.open(CACHE_NAME);
  const cacheKey = new Request(cacheKeyPath);

  try {
    const response = await fetch(request);
    if (response.ok) {
      // Successful response — store it under the canonical key.
      await cache.put(cacheKey, response.clone());
      return response;
    }
    // 4xx / 5xx — prefer cached over an error response so a renamed
    // /signage/ → /signage_/ doesn't blank the box.
    const cached = await cache.match(cacheKey);
    if (cached) return cached;
    return response;
  } catch (e) {
    // Network completely failed — return cached if we have one.
    const cached = await cache.match(cacheKey);
    if (cached) return cached;
    return new Response('', { status: 504, statusText: 'SW: offline + uncached' });
  }
}

// ---------- Retry helper for prefetch fetches ----------

/**
 * Retry policy for background prefetches. Big files (e.g. 52 MB MP3 bg
 * music) on flaky store WiFi often die mid-download — without retries
 * the file stays uncached until the next 50-min poll cycle.
 *
 * Backoff schedule: immediate, +5s, +15s, +60s. Total ~80 s for 4
 * attempts. Beyond that, the next regular sync-cache call (triggered by
 * the next playlist poll OR the UP key on the remote) starts a fresh
 * retry cycle. We deliberately don't extend past ~80 s because Service
 * Worker `waitUntil` keeps the worker alive for the duration — longer
 * retries would tie up resources without much marginal benefit.
 */
const RETRY_BACKOFFS_MS = [0, 5000, 15000, 60000];

function sleep(ms) {
  return new Promise(function (r) { setTimeout(r, ms); });
}

/**
 * Fetch with retries, posting progress to the page after each attempt.
 * Returns the successful Response or throws after the last retry.
 */
async function fetchWithRetry(url, sourceClient) {
  let lastErr = 'unknown';
  for (let i = 0; i < RETRY_BACKOFFS_MS.length; i++) {
    if (RETRY_BACKOFFS_MS[i] > 0) {
      // Tell the page we're about to wait + retry. Lets the panel
      // render "retry 2/4 in 15s" so the operator can tell the box
      // isn't just stuck.
      postProgress(sourceClient, {
        url: url,
        state: 'waiting',
        attempt: i + 1,
        totalAttempts: RETRY_BACKOFFS_MS.length,
        waitMs: RETRY_BACKOFFS_MS[i],
        lastError: lastErr,
      });
      await sleep(RETRY_BACKOFFS_MS[i]);
    }
    postProgress(sourceClient, {
      url: url,
      state: 'fetching',
      attempt: i + 1,
      totalAttempts: RETRY_BACKOFFS_MS.length,
    });
    try {
      const response = await fetch(url);
      if (response.ok) return response;
      lastErr = 'HTTP ' + response.status;
    } catch (e) {
      lastErr = (e && e.message) || 'fetch error';
    }
  }
  throw new Error(lastErr);
}

function postProgress(sourceClient, msg) {
  if (!sourceClient) return;
  try {
    sourceClient.postMessage(Object.assign({ type: 'cache-progress' }, msg));
  } catch (_) { /* client gone */ }
}

// ---------- Message channel: page → SW ----------

self.addEventListener('message', (event) => {
  const msg = event.data;
  if (!msg || typeof msg !== 'object') return;

  if (msg.type === 'sync-cache') {
    event.waitUntil(syncMediaCache(msg.urls || [], event.source));
  } else if (msg.type === 'cache-stats') {
    event.waitUntil(reportStats(event.source));
  }
});

async function syncMediaCache(wantedUrls, sourceClient) {
  const cache = await caches.open(CACHE_NAME);
  const wanted = new Set(wantedUrls);

  // 1. Delete media entries no longer in the playlist. We only touch
  // entries under MEDIA_PATH_PREFIX so we don't accidentally delete
  // the player HTML or playlist JSON cached entries.
  const existingRequests = await cache.keys();
  let deleted = 0;
  for (const req of existingRequests) {
    const u = new URL(req.url);
    if (!u.pathname.startsWith(MEDIA_PATH_PREFIX)) continue;
    if (!wanted.has(req.url)) {
      const ok = await cache.delete(req);
      if (ok) deleted++;
    }
  }

  // 2. Fetch + store anything in the new playlist that isn't cached yet.
  // Uses fetchWithRetry — large files (52 MB+) on flaky WiFi often need
  // 2-3 attempts before they land. Each attempt posts progress to the
  // page so the panel can show "retry 2/4 in 15s" instead of an opaque
  // "(network)" badge that never updates.
  const existingMediaUrls = new Set(
    existingRequests
      .filter((r) => new URL(r.url).pathname.startsWith(MEDIA_PATH_PREFIX))
      .map((r) => r.url)
  );
  const toFetch = wantedUrls.filter((u) => !existingMediaUrls.has(u));
  let fetched = 0;
  let failed = 0;
  await Promise.all(toFetch.map(async (url) => {
    try {
      const response = await fetchWithRetry(url, sourceClient);
      await cache.put(url, response);
      fetched++;
      postProgress(sourceClient, { url: url, state: 'cached' });
    } catch (e) {
      failed++;
      postProgress(sourceClient, {
        url: url,
        state: 'failed',
        lastError: (e && e.message) || 'unknown',
      });
    }
  }));

  // 3. Tell the page what happened so the panel can refresh.
  if (sourceClient) {
    const allMediaUrls = (await cache.keys())
      .filter((r) => new URL(r.url).pathname.startsWith(MEDIA_PATH_PREFIX))
      .map((r) => r.url);
    sourceClient.postMessage({
      type: 'sync-cache-done',
      deleted, fetched, failed,
      cachedUrls: allMediaUrls,
    });
  }
}

async function reportStats(sourceClient) {
  const cache = await caches.open(CACHE_NAME);
  const requests = await cache.keys();
  const cachedUrls = requests
    .filter((r) => new URL(r.url).pathname.startsWith(MEDIA_PATH_PREFIX))
    .map((r) => r.url);

  // Detect whether we have the player HTML and a playlist cached too.
  let hasPlayerHtml = false;
  let hasPlaylist = false;
  for (const req of requests) {
    const p = new URL(req.url).pathname;
    if (p === PLAYER_HTML_PATH) hasPlayerHtml = true;
    if (PLAYLIST_PATH_RE.test(p)) hasPlaylist = true;
  }

  let bytes = 0;
  // Per-URL byte counts so the player can render "12.3 MB" next to each
  // item in the DOWN-key panel. Same 200-entry budget as the total —
  // enumerating bigger caches is too expensive to do on a panel-open.
  const perUrl = {};
  if (requests.length <= 200) {
    for (const req of requests) {
      const resp = await cache.match(req);
      if (resp) {
        const blob = await resp.clone().blob();
        bytes += blob.size;
        perUrl[req.url] = blob.size;
      }
    }
  } else {
    bytes = -1;
  }

  let quota = null, usage = null;
  if (self.navigator && self.navigator.storage && self.navigator.storage.estimate) {
    try {
      const e = await self.navigator.storage.estimate();
      quota = e.quota || null;
      usage = e.usage || null;
    } catch (_) { /* ignore */ }
  }

  if (sourceClient) {
    sourceClient.postMessage({
      type: 'cache-stats-reply',
      cachedUrls, bytes, quota, usage,
      hasPlayerHtml, hasPlaylist,
      perUrl,
    });
  }
}
