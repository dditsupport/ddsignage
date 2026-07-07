package biz.aromen.signage

import android.content.Context
import android.hardware.display.DisplayManager
import android.os.Build
import android.os.StatFs
import android.util.Log
import android.view.Display
import org.json.JSONObject
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

/**
 * Thin wrapper around the four server endpoints. Form-encoded POSTs match
 * the PHP $_POST style implied by the requirements doc. All network calls
 * are blocking; callers must run them off the main thread.
 *
 * The API base URL is derived from [SignageConfig.playerUrl] by replacing
 * the trailing /player/... segment with /api/. Spec says the server layout
 * is fixed, so this is sound.
 */
object SignageApi {

    private const val TAG = "SignagePlayer"
    private const val DEFAULT_TIMEOUT_MS = 8_000

    data class PingResult(
        val ok: Boolean,
        val httpCode: Int,
        val body: String,
        val forceRefresh: Boolean,
        // Server-configured ping cadence in seconds; null if the server
        // didn't include the field (legacy server, pre migration 006).
        val pollIntervalSeconds: Int? = null,
        val error: String? = null
    )

    data class RegisterResult(
        val ok: Boolean,
        val httpCode: Int,
        val body: String,
        val assigned: Boolean,
        val error: String? = null
    )

    data class VersionResult(
        val ok: Boolean,
        val httpCode: Int,
        val body: String,
        val updateAvailable: Boolean,
        val latestVersion: String,
        val apkUrl: String,
        val error: String? = null
    )

    fun apiBase(): String {
        val url = SignageConfig.playerUrl
        val idx = url.indexOf("/player/")
        return if (idx > 0) {
            url.substring(0, idx) + "/api/"
        } else {
            // Best-effort: strip last path segment and tack on api/.
            val parent = url.substringBeforeLast('/', missingDelimiterValue = url) + "/"
            parent
        }
    }

    /**
     * The string we send as `app_version` to the server. Combines the
     * human-readable versionName with the numeric versionCode so the
     * dashboard can distinguish two builds that ship as "1.7" but have
     * different codes (which happens often during dev iterations).
     * Format: "1.7 (8)" — stable, parses both ways with a regex.
     */
    private fun appVersionString(): String =
        "${BuildInfo.versionName} (${BuildInfo.versionCode})"

    fun register(): RegisterResult {
        // Gather extra device-health stats. Cheap calls — done inline.
        // Empty / 0 values are sent as "" / "0" so the server can detect
        // "field not reported" vs an actual zero.
        val (sw, sh) = currentScreenSize()
        val (sFree, sTotal) = currentStorageBytes()
        val params = mapOf(
            "android_id" to DeviceIdentity.androidId,
            "mac_address" to DeviceIdentity.macAddress,
            "app_version" to appVersionString(),
            "device_model" to "${Build.MANUFACTURER} ${Build.MODEL}",
            "android_version" to "${Build.VERSION.RELEASE} (API ${Build.VERSION.SDK_INT})",
            // Device health — surfaced on the dashboard's device_edit page.
            "screen_width" to sw.toString(),
            "screen_height" to sh.toString(),
            "storage_free_bytes" to sFree.toString(),
            "storage_total_bytes" to sTotal.toString()
        )
        return try {
            val (code, body) = postForm(URL(apiBase() + "register.php"), params, DEFAULT_TIMEOUT_MS)
            val json = parseJsonOrNull(body)
            RegisterResult(
                ok = code in 200..299 && json?.optString("status") == "success",
                httpCode = code,
                body = body,
                assigned = json?.optBoolean("assigned", false) ?: false
            )
        } catch (t: Throwable) {
            Log.w(TAG, "register.php failed: ${t.message}")
            RegisterResult(false, -1, "", assigned = false, error = t.javaClass.simpleName + ": " + t.message)
        }
    }

    fun ping(): PingResult {
        val params = mapOf(
            "android_id" to DeviceIdentity.androidId,
            "app_version" to appVersionString()
        )
        return try {
            val (code, body) = postForm(URL(apiBase() + "ping.php"), params, DEFAULT_TIMEOUT_MS)
            val json = parseJsonOrNull(body)
            val poll = json?.optInt("poll_interval_seconds", -1) ?: -1
            PingResult(
                ok = code in 200..299 && json?.optString("status") == "success",
                httpCode = code,
                body = body,
                forceRefresh = json?.optBoolean("force_refresh", false) ?: false,
                pollIntervalSeconds = if (poll > 0) poll else null
            )
        } catch (t: Throwable) {
            Log.w(TAG, "ping.php failed: ${t.message}")
            PingResult(false, -1, "", forceRefresh = false, error = t.javaClass.simpleName + ": " + t.message)
        }
    }

    fun checkVersion(): VersionResult {
        val query = "?current_version=" + URLEncoder.encode(BuildInfo.versionName, "UTF-8")
        return try {
            val (code, body) = getRequest(URL(apiBase() + "version.php" + query), DEFAULT_TIMEOUT_MS)
            val json = parseJsonOrNull(body)
            VersionResult(
                ok = code in 200..299 && json != null,
                httpCode = code,
                body = body,
                updateAvailable = json?.optBoolean("update_available", false) ?: false,
                latestVersion = json?.optString("latest_version").orEmpty(),
                apkUrl = json?.optString("apk_url").orEmpty()
            )
        } catch (t: Throwable) {
            Log.w(TAG, "version.php failed: ${t.message}")
            VersionResult(false, -1, "", false, "", "", error = t.javaClass.simpleName + ": " + t.message)
        }
    }

    /** Returns true if the server accepted (200..299). */
    fun reportCrash(stackTrace: String, timestampMs: Long): Boolean {
        val params = mapOf(
            "android_id" to DeviceIdentity.androidId,
            "app_version" to appVersionString(),
            "android_version" to "${Build.VERSION.RELEASE} (API ${Build.VERSION.SDK_INT})",
            "device_model" to "${Build.MANUFACTURER} ${Build.MODEL}",
            "stack_trace" to stackTrace,
            "timestamp" to timestampMs.toString()
        )
        return try {
            val (code, _) = postForm(URL(apiBase() + "crash.php"), params, DEFAULT_TIMEOUT_MS)
            code in 200..299
        } catch (t: Throwable) {
            Log.w(TAG, "crash.php failed: ${t.message}")
            false
        }
    }

    private fun postForm(url: URL, params: Map<String, String>, timeoutMs: Int): Pair<Int, String> {
        val body = params.entries.joinToString("&") { (k, v) ->
            URLEncoder.encode(k, "UTF-8") + "=" + URLEncoder.encode(v, "UTF-8")
        }
        val conn = (url.openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            doOutput = true
            connectTimeout = timeoutMs
            readTimeout = timeoutMs
            useCaches = false
            setRequestProperty("Content-Type", "application/x-www-form-urlencoded; charset=UTF-8")
            setRequestProperty("Accept", "application/json")
        }
        return try {
            conn.outputStream.use { it.write(body.toByteArray(Charsets.UTF_8)) }
            val code = conn.responseCode
            val stream = if (code in 200..299) conn.inputStream else conn.errorStream
            val text = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }.orEmpty()
            code to text
        } catch (e: IOException) {
            // Surface the error code if we got one, otherwise -1 to signal IO failure.
            val code = try { conn.responseCode } catch (_: Throwable) { -1 }
            code to (e.message ?: e.javaClass.simpleName)
        } finally {
            conn.disconnect()
        }
    }

    private fun getRequest(url: URL, timeoutMs: Int): Pair<Int, String> {
        val conn = (url.openConnection() as HttpURLConnection).apply {
            requestMethod = "GET"
            connectTimeout = timeoutMs
            readTimeout = timeoutMs
            useCaches = false
            setRequestProperty("Accept", "application/json")
        }
        return try {
            val code = conn.responseCode
            val stream = if (code in 200..299) conn.inputStream else conn.errorStream
            val text = stream?.bufferedReader(Charsets.UTF_8)?.use { it.readText() }.orEmpty()
            code to text
        } catch (e: IOException) {
            val code = try { conn.responseCode } catch (_: Throwable) { -1 }
            code to (e.message ?: e.javaClass.simpleName)
        } finally {
            conn.disconnect()
        }
    }

    private fun parseJsonOrNull(body: String): JSONObject? {
        if (body.isBlank()) return null
        return try {
            JSONObject(body)
        } catch (_: Throwable) {
            null
        }
    }

    /**
     * Native pixel resolution of the default display. On a TV box this is
     * what's actually being sent over HDMI. Uses Display.Mode.physicalWidth
     * (API 23+) — present on every box we ship to. Returns (0, 0) on any
     * failure so the server can treat zero as "not reported".
     *
     * Note: we deliberately use DisplayManager (not WindowManager) because
     * register() runs from BackgroundTasks's bg executor with no Activity
     * context. Activity.windowMetrics would require an Activity reference.
     */
    private fun currentScreenSize(): Pair<Int, Int> {
        return try {
            val dm = SignageApplication.appContext
                .getSystemService(Context.DISPLAY_SERVICE) as DisplayManager
            val display = dm.getDisplay(Display.DEFAULT_DISPLAY) ?: return 0 to 0
            val mode = display.mode
            mode.physicalWidth to mode.physicalHeight
        } catch (t: Throwable) {
            Log.w(TAG, "currentScreenSize failed: ${t.message}")
            0 to 0
        }
    }

    /**
     * Free + total bytes on the partition that hosts our cache dir — i.e.
     * the partition used by the SW media cache. Mirrors the StatFs call in
     * AndroidBridge.getStorageInfo() (which serves the player's DOWN-key
     * panel) so dashboard numbers match what a tech sees on the screen.
     */
    private fun currentStorageBytes(): Pair<Long, Long> {
        return try {
            val ctx = SignageApplication.appContext
            val s = StatFs(ctx.cacheDir.absolutePath)
            s.availableBytes to s.totalBytes
        } catch (t: Throwable) {
            Log.w(TAG, "currentStorageBytes failed: ${t.message}")
            0L to 0L
        }
    }
}
