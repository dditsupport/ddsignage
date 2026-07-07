package biz.aromen.signage

import android.content.Context
import android.os.StatFs
import android.util.Log
import android.webkit.JavascriptInterface
import org.json.JSONObject
import java.io.File

/**
 * JavaScript-facing bridge exposed to the web player as `window.AndroidBridge`.
 *
 * Methods are called from the WebView's JS thread, NOT the UI thread.
 * Keep all methods quick and read-only — back them with cached values
 * resolved at app startup where possible.
 */
class AndroidBridge(private val context: Context) {

    @JavascriptInterface
    fun getAndroidId(): String = DeviceIdentity.androidId

    @JavascriptInterface
    fun getMacAddress(): String = DeviceIdentity.macAddress

    /**
     * Currently-active APK ping cadence in seconds (after the server's
     * per-device override has been applied). Surfaced in the DOWN-key
     * panel so it can be cross-checked against the player-JS poll
     * interval — they should usually agree.
     */
    @JavascriptInterface
    fun getPingIntervalSeconds(): Int = BackgroundTasks.currentPingIntervalSeconds()

    /**
     * Where the WebView keeps the bytes for downloaded media on this box,
     * plus free-space / cache-size totals. Surfaced in the Downloads panel
     * so a tech can adb-shell into the box and inspect / clear the cache
     * if a file isn't where they expect.
     *
     * Returns a JSON string (a struct would be safer but JS interop with
     * Kotlin objects via @JavascriptInterface is awkward — JSON is fine).
     *
     * NOTE: image bytes don't appear under their original filenames here.
     * The WebView HTTP cache stores them in opaque hashed-name files inside
     * the WebView/ subfolder. To verify a specific file is cached, look at
     * the size of the cache dir before and after a fetch.
     */
    @JavascriptInterface
    fun getStorageInfo(): String {
        val cacheDir = context.cacheDir
        val dataDir = context.filesDir.parentFile?.absolutePath ?: "(unknown)"
        val webViewCacheDir = File(cacheDir, "WebView")

        val statFree: Long
        val statTotal: Long
        try {
            val s = StatFs(cacheDir.absolutePath)
            statFree = s.availableBytes
            statTotal = s.totalBytes
        } catch (t: Throwable) {
            Log.w(TAG, "StatFs failed for ${cacheDir.absolutePath}: ${t.message}")
            return JSONObject().put("error", t.message).toString()
        }

        // Cap the recursion so a runaway cache walk can't lock the JS thread.
        val cacheBytes = safeDirSize(cacheDir, MAX_FILES_TO_WALK)
        val webViewCacheBytes = if (webViewCacheDir.exists())
            safeDirSize(webViewCacheDir, MAX_FILES_TO_WALK) else 0L

        return JSONObject().apply {
            put("cache_dir", cacheDir.absolutePath)
            put("webview_cache_dir", webViewCacheDir.absolutePath)
            put("data_dir", dataDir)
            put("cache_bytes", cacheBytes)
            put("webview_cache_bytes", webViewCacheBytes)
            put("free_bytes", statFree)
            put("total_bytes", statTotal)
        }.toString()
    }

    private fun safeDirSize(root: File, fileBudget: Int): Long {
        if (!root.exists()) return 0L
        var seen = 0
        var total = 0L
        for (f in root.walkTopDown()) {
            if (f.isFile) {
                total += f.length()
                seen++
                if (seen >= fileBudget) break
            }
        }
        return total
    }

    companion object {
        private const val TAG = "SignagePlayer"
        private const val MAX_FILES_TO_WALK = 5_000
    }
}
