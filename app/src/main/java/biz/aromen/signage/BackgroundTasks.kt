package biz.aromen.signage

import android.os.Handler
import android.os.Looper
import android.util.Log
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone
import java.util.concurrent.ExecutorService
import java.util.concurrent.Executors

/**
 * Schedules the three recurring server calls (register, ping, version)
 * using a single background thread for the network and the main looper
 * for timing. The kiosk app stays in foreground continuously, so plain
 * Handler-based scheduling is sufficient — no WorkManager needed.
 *
 * The latest result of each call is cached so the diagnostics overlay
 * can render it without holding any references to MainActivity.
 *
 * On a force_refresh from ping, [onForceRefresh] is invoked on the main
 * thread; MainActivity wires this to webView.reload().
 */
object BackgroundTasks {

    private const val TAG = "SignagePlayer"

    // First-run delays (deliberately short — we want a fast first heartbeat
    // so the dashboard sees a new device come online quickly).
    private const val INITIAL_REGISTER_DELAY_MS = 5_000L
    private const val INITIAL_PING_DELAY_MS = 30_000L
    private const val INITIAL_VERSION_DELAY_MS = 60_000L

    // Default ping cadence — overridden by the server on each successful
    // ping (poll_interval_seconds in the response). Kept @Volatile so the
    // re-arming pingRunnable can read the latest value safely.
    @Volatile private var pingIntervalMs = 5L * 60_000L      // 5 min
    private const val MIN_PING_INTERVAL_MS = 30L * 1_000L    // server-side clamp floor
    private const val MAX_PING_INTERVAL_MS = 60L * 60_000L   // 1 h
    private const val DAILY_INTERVAL_MS = 24L * 60L * 60_000L

    private val mainHandler = Handler(Looper.getMainLooper())
    private val executor: ExecutorService = Executors.newSingleThreadExecutor { r ->
        Thread(r, "signage-bg").apply { isDaemon = true }
    }

    @Volatile var lastRegister: String = "(pending)"; private set
    @Volatile var lastPing: String = "(pending)"; private set
    @Volatile var lastVersion: String = "(pending)"; private set
    @Volatile var lastForceRefresh: String = "(none)"; private set

    /** Currently-active ping cadence in seconds (after server override). */
    fun currentPingIntervalSeconds(): Int = (pingIntervalMs / 1000L).toInt()

    /**
     * Fire an extra ping right now, off the regular schedule. Used when the
     * remote's UP key triggers a force-fetch — the player JS pulls a fresh
     * playlist via Apache, but `last_seen` only moves when api/ping.php is
     * hit, so we have to call it explicitly to keep the dashboard in sync.
     *
     * Safe to call from any thread — work runs on the bg executor.
     */
    fun pingNow() {
        executor.execute {
            val r = SignageApi.ping()
            val summary = if (r.ok)
                "OK force_refresh=${r.forceRefresh} interval=${r.pollIntervalSeconds ?: "(server-default)"} (manual)"
            else
                "FAIL http=${r.httpCode} err=${r.error ?: "(none)"} (manual)"
            lastPing = "${formatNow()}  $summary"
            Log.i(TAG, "ping (manual): $lastPing")
            r.pollIntervalSeconds?.let { secs ->
                val newMs = (secs * 1000L).coerceIn(MIN_PING_INTERVAL_MS, MAX_PING_INTERVAL_MS)
                if (newMs != pingIntervalMs) {
                    Log.i(TAG, "ping interval ${pingIntervalMs / 1000}s -> ${newMs / 1000}s")
                    pingIntervalMs = newMs
                }
            }
            if (r.forceRefresh) {
                lastForceRefresh = formatNow()
                mainHandler.post { onForceRefresh?.invoke() }
            }
        }
    }
    @Volatile private var started = false
    @Volatile private var onForceRefresh: (() -> Unit)? = null

    fun start(onForceRefresh: () -> Unit) {
        if (started) return
        started = true
        this.onForceRefresh = onForceRefresh
        mainHandler.postDelayed(registerRunnable, INITIAL_REGISTER_DELAY_MS)
        mainHandler.postDelayed(pingRunnable, INITIAL_PING_DELAY_MS)
        mainHandler.postDelayed(versionRunnable, INITIAL_VERSION_DELAY_MS)
    }

    fun stop() {
        mainHandler.removeCallbacks(registerRunnable)
        mainHandler.removeCallbacks(pingRunnable)
        mainHandler.removeCallbacks(versionRunnable)
        started = false
        onForceRefresh = null
    }

    private val registerRunnable: Runnable = Runnable {
        executor.execute {
            val r = SignageApi.register()
            val summary = if (r.ok) "OK assigned=${r.assigned}" else "FAIL http=${r.httpCode} err=${r.error ?: "(none)"}"
            lastRegister = "${formatNow()}  $summary"
            Log.i(TAG, "register: $lastRegister")
        }
        mainHandler.postDelayed(registerRunnable, DAILY_INTERVAL_MS)
    }

    private val pingRunnable: Runnable = Runnable {
        executor.execute {
            try {
                val r = SignageApi.ping()
                val summary = if (r.ok)
                    "OK force_refresh=${r.forceRefresh} interval=${r.pollIntervalSeconds ?: "(server-default)"}"
                else
                    "FAIL http=${r.httpCode} err=${r.error ?: "(none)"}"
                lastPing = "${formatNow()}  $summary"
                Log.i(TAG, "ping: $lastPing")

                // Adopt the server-configured ping cadence. Clamped to the
                // same range as the server.
                r.pollIntervalSeconds?.let { secs ->
                    val newMs = (secs * 1000L).coerceIn(MIN_PING_INTERVAL_MS, MAX_PING_INTERVAL_MS)
                    if (newMs != pingIntervalMs) {
                        Log.i(TAG, "ping interval ${pingIntervalMs / 1000}s -> ${newMs / 1000}s")
                        pingIntervalMs = newMs
                    }
                }

                if (r.forceRefresh) {
                    lastForceRefresh = formatNow()
                    mainHandler.post { onForceRefresh?.invoke() }
                }
            } finally {
                // Schedule the NEXT tick from the bg thread, AFTER the ping
                // returned and pingIntervalMs has been updated. The previous
                // implementation scheduled it from the main thread before
                // executor.execute had a chance to run, so a new interval
                // took two cycles to propagate instead of one. try/finally
                // guarantees we always reschedule, even if the ping throws.
                mainHandler.postDelayed(pingRunnable, pingIntervalMs)
            }
        }
    }

    private val versionRunnable: Runnable = Runnable {
        executor.execute {
            val r = SignageApi.checkVersion()
            val summary = if (r.ok) {
                if (r.updateAvailable) "OK update->${r.latestVersion} apk=${r.apkUrl}"
                else "OK current"
            } else {
                "FAIL http=${r.httpCode} err=${r.error ?: "(none)"}"
            }
            lastVersion = "${formatNow()}  $summary"
            Log.i(TAG, "version: $lastVersion")

            // If the server is offering a newer APK, kick off the silent
            // download + install. Runs on this same bg executor; on success
            // the OS will kill us mid-execution and re-launch the new APK
            // via the persistent HOME provisioning. No-op if the same
            // version recently failed (24 h cooldown inside maybeUpdate).
            if (r.ok && r.updateAvailable && r.apkUrl.isNotBlank()) {
                ApkUpdater.maybeUpdate(r.latestVersion, r.apkUrl)
            }
        }
        mainHandler.postDelayed(versionRunnable, DAILY_INTERVAL_MS)
    }

    // All "Last X" timestamps in the diagnostics overlay are formatted in
    // Asia/Kolkata so a box mis-configured to UTC still shows IST wall-clock.
    private val istFormatter: SimpleDateFormat =
        SimpleDateFormat("HH:mm:ss", Locale.US).apply {
            timeZone = TimeZone.getTimeZone("Asia/Kolkata")
        }

    private fun formatNow(): String = istFormatter.format(Date())
}
