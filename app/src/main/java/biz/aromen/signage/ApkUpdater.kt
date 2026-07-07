package biz.aromen.signage

import android.app.PendingIntent
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.os.Build
import android.util.Log
import java.io.File
import java.io.FileInputStream
import java.io.FileOutputStream
import java.net.HttpURLConnection
import java.net.URL
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
import java.util.TimeZone

/**
 * Silent OTA updater.
 *
 * Trigger: BackgroundTasks.versionRunnable runs SignageApi.checkVersion()
 * once a day. When the server reports update_available=true with a non-empty
 * apk_url (the URL of e.g. https://aromen.biz/signage/apk/signage_v2.5.apk),
 * it hands off to [maybeUpdate], which streams the APK to cacheDir and
 * commits a PackageInstaller session.
 *
 * Why silent works: every shipped box is provisioned as Device Owner during
 * setup (see MainActivity.ensurePersistentHomeLauncher). On a DO-provisioned
 * device, PackageInstaller.commit() bypasses the user-confirm dialog and
 * the OS just replaces the APK in place. Without DO this same code still
 * runs but Android would show a confirm prompt — useless on an unattended
 * shop-floor box.
 *
 * Failure handling: install failures (bad signature, parse error, etc.)
 * are recorded in SharedPreferences with a 24 h cooldown so a broken upload
 * can't burn bandwidth every day. The cooldown is per-version — fix the
 * upload, bump the version, and the next check tries again.
 *
 * Process death: a successful commit() ends with the OS killing our
 * process and re-launching the new APK (the persistent HOME provisioning
 * makes this automatic). The screen blanks for a couple of seconds; that's
 * the only end-user-visible artifact.
 */
object ApkUpdater {

    private const val TAG = "SignagePlayer"
    private const val PREFS = "signage_updater"
    private const val KEY_LAST_FAILED_VERSION = "last_failed_version"
    private const val KEY_LAST_FAILED_AT = "last_failed_at"

    private const val FAIL_COOLDOWN_MS = 24L * 60L * 60_000L
    private const val DL_CONNECT_TIMEOUT_MS = 30_000
    private const val DL_READ_TIMEOUT_MS = 60_000
    private const val COPY_BUFFER = 64 * 1024

    /** Broadcast action that PackageInstaller delivers our install result on. */
    const val ACTION_INSTALL_RESULT = "biz.aromen.signage.INSTALL_RESULT"

    /** Diagnostics overlay reads this. Best-effort, in-memory only. */
    @Volatile var lastUpdate: String = "(none)"; private set

    /**
     * Entry point from BackgroundTasks. Runs on the bg executor.
     * Safe to call repeatedly with the same version — the failure cooldown
     * and the per-version cache file dedupe redundant work.
     */
    fun maybeUpdate(latestVersion: String, apkUrl: String) {
        if (latestVersion.isBlank() || apkUrl.isBlank()) return

        val ctx = SignageApplication.appContext
        val prefs = ctx.getSharedPreferences(PREFS, Context.MODE_PRIVATE)
        val lastFailed = prefs.getString(KEY_LAST_FAILED_VERSION, "") ?: ""
        val lastFailedAt = prefs.getLong(KEY_LAST_FAILED_AT, 0L)
        val elapsed = System.currentTimeMillis() - lastFailedAt
        if (lastFailed == latestVersion && elapsed < FAIL_COOLDOWN_MS) {
            val mins = (FAIL_COOLDOWN_MS - elapsed) / 60_000L
            lastUpdate = "${formatNow()} skip v=$latestVersion (recent fail, retry in ${mins}m)"
            Log.i(TAG, lastUpdate)
            return
        }

        try {
            val file = downloadApk(apkUrl, latestVersion) ?: return
            installSilently(file, latestVersion)
        } catch (t: Throwable) {
            recordFailure(latestVersion, "exception ${t.javaClass.simpleName}: ${t.message}")
        }
    }

    /**
     * Stream the APK into cacheDir/update-<ver>.apk via a .tmp file so a
     * mid-download crash never leaves a truncated file at the canonical
     * path. Returns the cached file on success, null on any failure (which
     * has already been recorded).
     */
    private fun downloadApk(apkUrl: String, version: String): File? {
        val ctx = SignageApplication.appContext
        val target = File(ctx.cacheDir, "update-$version.apk")
        if (target.length() > 0L) {
            lastUpdate = "${formatNow()} cached v=$version (${target.length()} B)"
            Log.i(TAG, lastUpdate)
            return target
        }

        val tmp = File(ctx.cacheDir, "update-$version.tmp")
        tmp.delete()

        val conn = (URL(apkUrl).openConnection() as HttpURLConnection).apply {
            connectTimeout = DL_CONNECT_TIMEOUT_MS
            readTimeout = DL_READ_TIMEOUT_MS
            useCaches = false
            requestMethod = "GET"
        }
        try {
            conn.connect()
            val code = conn.responseCode
            if (code !in 200..299) {
                recordFailure(version, "download HTTP $code from $apkUrl")
                return null
            }
            FileOutputStream(tmp).use { out ->
                conn.inputStream.use { input -> input.copyTo(out, COPY_BUFFER) }
            }
        } finally {
            conn.disconnect()
        }

        // ZIP magic check: catches HTML error pages served with 2xx, partial
        // downloads where the socket closed mid-stream, and the misconfigured
        // case where apk_url points at index.html.
        FileInputStream(tmp).use { fis ->
            val magic = ByteArray(4)
            val n = fis.read(magic)
            val isApk = n == 4 &&
                magic[0] == 0x50.toByte() && magic[1] == 0x4B.toByte() &&
                magic[2] == 0x03.toByte() && magic[3] == 0x04.toByte()
            if (!isApk) {
                tmp.delete()
                val hex = magic.joinToString("") { "%02X".format(it) }
                recordFailure(version, "bad APK magic (got $hex)")
                return null
            }
        }

        if (!tmp.renameTo(target)) {
            tmp.delete()
            recordFailure(version, "rename tmp -> $target failed")
            return null
        }
        lastUpdate = "${formatNow()} downloaded v=$version (${target.length()} B)"
        Log.i(TAG, lastUpdate)
        return target
    }

    private fun installSilently(file: File, version: String) {
        val ctx = SignageApplication.appContext
        val pi = ctx.packageManager.packageInstaller
        val params = PackageInstaller.SessionParams(
            PackageInstaller.SessionParams.MODE_FULL_INSTALL
        )
        // Restrict the session to our own package — a misconfigured apk_url
        // pointing at someone else's APK will then fail INSTALL_FAILED_INVALID
        // rather than silently replacing this app with another.
        params.setAppPackageName(ctx.packageName)

        val sessionId = pi.createSession(params)
        var session: PackageInstaller.Session? = null
        try {
            session = pi.openSession(sessionId)
            FileInputStream(file).use { input ->
                session.openWrite("base.apk", 0L, file.length()).use { out ->
                    input.copyTo(out, COPY_BUFFER)
                    session.fsync(out)
                }
            }

            val intent = Intent(ACTION_INSTALL_RESULT).apply {
                setPackage(ctx.packageName) // route only to our manifest receiver
                putExtra(EXTRA_VERSION, version)
            }
            // FLAG_MUTABLE required on API 31+ because PackageInstaller writes
            // EXTRA_STATUS / EXTRA_STATUS_MESSAGE into the intent before
            // delivery. Pre-31 the default is mutable.
            val flags = PendingIntent.FLAG_UPDATE_CURRENT or
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S)
                    PendingIntent.FLAG_MUTABLE
                else 0
            val sender = PendingIntent.getBroadcast(ctx, sessionId, intent, flags)

            session.commit(sender.intentSender)
            lastUpdate = "${formatNow()} install committed v=$version session=$sessionId"
            Log.i(TAG, lastUpdate)
        } catch (t: Throwable) {
            try { session?.abandon() } catch (_: Throwable) { /* ignore */ }
            recordFailure(version, "commit ${t.javaClass.simpleName}: ${t.message}")
            throw t
        } finally {
            try { session?.close() } catch (_: Throwable) { /* ignore */ }
        }
    }

    /** Called by ApkInstallReceiver on STATUS_SUCCESS (rarely reached for self-update — see receiver). */
    internal fun onInstallSuccess(version: String) {
        lastUpdate = "${formatNow()} install SUCCESS v=$version"
        Log.i(TAG, lastUpdate)
        File(SignageApplication.appContext.cacheDir, "update-$version.apk").delete()
    }

    internal fun onInstallFailure(version: String, status: Int, msg: String?) {
        recordFailure(version, "install status=$status msg=${msg ?: "(none)"}")
        File(SignageApplication.appContext.cacheDir, "update-$version.apk").delete()
    }

    private fun recordFailure(version: String, reason: String) {
        SignageApplication.appContext
            .getSharedPreferences(PREFS, Context.MODE_PRIVATE).edit()
            .putString(KEY_LAST_FAILED_VERSION, version)
            .putLong(KEY_LAST_FAILED_AT, System.currentTimeMillis())
            .apply()
        lastUpdate = "${formatNow()} FAIL v=$version $reason"
        Log.w(TAG, lastUpdate)
    }

    const val EXTRA_VERSION = "version"

    private val istFormatter: SimpleDateFormat =
        SimpleDateFormat("HH:mm:ss", Locale.US).apply {
            timeZone = TimeZone.getTimeZone("Asia/Kolkata")
        }

    private fun formatNow(): String = istFormatter.format(Date())
}
