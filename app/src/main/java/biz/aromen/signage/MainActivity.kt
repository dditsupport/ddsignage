package biz.aromen.signage

import android.annotation.SuppressLint
import android.app.admin.DevicePolicyManager
import android.content.ComponentName
import android.content.Context
import android.content.IntentFilter
import android.media.AudioManager
import android.net.http.SslError
import android.os.Build
import android.os.Bundle
import android.os.Handler
import android.os.Looper
import android.util.Log
import android.view.KeyEvent
import android.view.View
import android.view.WindowManager
import android.webkit.ConsoleMessage
import android.webkit.SslErrorHandler
import android.webkit.WebChromeClient
import android.webkit.WebResourceError
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import androidx.core.view.WindowCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.WindowInsetsControllerCompat
import org.json.JSONObject
import java.net.InetAddress
import java.net.URI
import java.text.SimpleDateFormat
import java.util.Calendar
import java.util.Date
import java.util.Locale
import java.util.TimeZone

class MainActivity : AppCompatActivity() {

    private lateinit var webView: WebView
    private lateinit var splashOverlay: View
    private lateinit var pingLog: TextView
    private lateinit var diagnosticsOverlay: View
    private lateinit var diagnosticsBody: TextView
    private val mainHandler = Handler(Looper.getMainLooper())
    @Volatile private var diagnosticsStarted = false
    private val showDiagnosticsRunnable = Runnable { showDiagnosticsOverlay() }

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // DeviceIdentity.init runs in SignageApplication.onCreate, before any
        // Activity. SignageConfig is build-time constants and needs no init.

        WindowCompat.setDecorFitsSystemWindows(window, false)
        setContentView(R.layout.activity_main)
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        // Allow Chrome DevTools (chrome://inspect) to attach to the WebView.
        WebView.setWebContentsDebuggingEnabled(true)

        webView = findViewById(R.id.player_web_view)
        splashOverlay = findViewById(R.id.splash_overlay)
        pingLog = findViewById(R.id.splash_ping_log)
        diagnosticsOverlay = findViewById(R.id.diagnostics_overlay)
        diagnosticsBody = findViewById(R.id.diagnostics_body)

        configureWebView(webView)
        populateSplashIdentity()
        ensurePersistentHomeLauncher()
        maxOutMediaVolume()
        runBootDiagnostics()
        BackgroundTasks.start(onForceRefresh = { handleForceRefresh() })
    }

    /**
     * Web-content `audio.volume = X` is internal to the WebView's mixer —
     * it can never exceed the OS media-stream level. So if a store left
     * the box's media volume at 30 % using the TV remote's app-volume
     * dial, even our "100 %" plays at 30 % of speaker max.
     *
     * For unattended signage we want the dashboard sliders to be the
     * single source of truth. We pin STREAM_MUSIC to its max on every
     * launch so the WebView's internal level is the only effective knob.
     * FLAG_REMOVE_SOUND_AND_VIBRATE suppresses the system "volume bar"
     * popping up when we do this.
     */
    private fun maxOutMediaVolume() {
        try {
            val am = getSystemService(Context.AUDIO_SERVICE) as? AudioManager ?: return
            val max = am.getStreamMaxVolume(AudioManager.STREAM_MUSIC)
            am.setStreamVolume(
                AudioManager.STREAM_MUSIC,
                max,
                AudioManager.FLAG_REMOVE_SOUND_AND_VIBRATE
            )
            Log.i(TAG, "OS media stream pinned to $max (max)")
        } catch (t: Throwable) {
            // Some vendor ROMs throw SecurityException for setStreamVolume
            // when "Do Not Disturb" access isn't granted. Not fatal.
            Log.w(TAG, "Could not max media stream: ${t.message}")
        }
    }

    /**
     * Cheap Android TV boxes (e.g. Droidlogic W2) regularly drop the
     * BOOT_COMPLETED broadcast or block background activity starts, so the
     * BootReceiver alone can't be trusted. The bulletproof fix is to be the
     * HOME launcher — the system runs HOME unconditionally after boot.
     *
     * Adding the HOME intent-filter in the manifest is half the job; on
     * Android 8+ a launcher picker prompt would still appear at first boot
     * unless we also pin ourselves as the persistent default. That requires
     * device-owner privileges. If we don't have them yet, we log instructions
     * and fall through; the user can finish provisioning and re-launch.
     */
    private fun ensurePersistentHomeLauncher() {
        val dpm = try {
            getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        } catch (t: Throwable) {
            Log.w(TAG, "DevicePolicyManager unavailable: ${t.message}")
            return
        }
        if (!dpm.isDeviceOwnerApp(packageName)) {
            Log.w(
                TAG,
                "Not device owner — boot-to-app will require user to pick this " +
                    "app as launcher manually. Provision with: " +
                    "adb shell dpm set-device-owner $packageName/.SignageDeviceAdminReceiver"
            )
            return
        }
        val admin = ComponentName(this, SignageDeviceAdminReceiver::class.java)
        val homeFilter = IntentFilter(android.content.Intent.ACTION_MAIN).apply {
            addCategory(android.content.Intent.CATEGORY_HOME)
            addCategory(android.content.Intent.CATEGORY_DEFAULT)
        }
        val self = ComponentName(this, MainActivity::class.java)
        try {
            // Idempotent: clearing first means a re-install with a new
            // component name doesn't leave a stale mapping behind.
            dpm.clearPackagePersistentPreferredActivities(admin, packageName)
            dpm.addPersistentPreferredActivity(admin, homeFilter, self)
            Log.i(TAG, "Pinned as persistent HOME launcher — will auto-start every boot")
        } catch (t: Throwable) {
            Log.e(TAG, "addPersistentPreferredActivity failed: ${t.message}")
        }
    }

    override fun onDestroy() {
        BackgroundTasks.stop()
        if (::webView.isInitialized) {
            webView.stopLoading()
            webView.destroy()
        }
        super.onDestroy()
    }

    private fun handleForceRefresh() {
        Log.i(TAG, "Force refresh from server — reloading WebView with fresh cache")
        // reload() reuses cached resources. Re-running the full startPlayerFlow
        // path means a fresh cache-busted URL + clearCache, so a server-side
        // player update lands immediately on next force_refresh.
        startPlayerFlow()
    }

    private fun populateSplashIdentity() {
        findViewById<TextView>(R.id.splash_android_id).text =
            "ANDROID_ID: ${DeviceIdentity.androidId.ifEmpty { "(none)" }}"
        findViewById<TextView>(R.id.splash_mac).text =
            "MAC: ${DeviceIdentity.macAddress.ifEmpty { "(none)" }}"
    }

    /**
     * Boot flow: ping 8.8.8.8 ten times at ~1Hz cadence, displaying each
     * result on the splash. Total elapsed time is ~10s regardless of
     * individual ping outcomes. After the loop the splash hides and the
     * player URL is loaded.
     */
    private fun runBootDiagnostics() {
        if (diagnosticsStarted) return
        diagnosticsStarted = true
        Thread({
            val clockLine = checkDeviceClock()
            Log.i(TAG, clockLine)
            mainHandler.post { pingLog.append("$clockLine\n") }
            for (i in 1..10) {
                val iterStart = System.currentTimeMillis()
                val result = pingOnce("8.8.8.8", 1000)
                val line = "Ping $i/10: $result"
                Log.i(TAG, line)
                mainHandler.post { pingLog.append("$line\n") }
                val elapsed = System.currentTimeMillis() - iterStart
                val sleepMs = (1000L - elapsed).coerceAtLeast(0L)
                try {
                    Thread.sleep(sleepMs)
                } catch (_: InterruptedException) {
                    return@Thread
                }
            }
            // If the clock is wrong, every HTTPS request will fail with SSL_DATE_INVALID.
            // Try to fix it from NTP before the WebView load — otherwise we go white-screen
            // every boot the box's auto-time can't reach an NTP server.
            val ntpLine = syncClockIfNeeded()
            Log.i(TAG, ntpLine)
            mainHandler.post { pingLog.append("$ntpLine\n") }

            // ping only verifies IP routing. Resolve the player host to surface DNS failures,
            // which look identical (white screen) to anything else from the WebView's side.
            val dnsLine = resolvePlayerHost()
            Log.i(TAG, dnsLine)
            mainHandler.post { pingLog.append("$dnsLine\n") }
            mainHandler.post { startPlayerFlow() }
        }, "boot-diagnostics").start()
    }

    private fun pingOnce(host: String, timeoutMs: Int): String {
        return try {
            val addr = InetAddress.getByName(host)
            val start = System.currentTimeMillis()
            val ok = addr.isReachable(timeoutMs)
            val rtt = System.currentTimeMillis() - start
            if (ok) "$host  ${rtt}ms  OK" else "$host  timeout"
        } catch (t: Throwable) {
            "$host  error: ${t.message}"
        }
    }

    private fun checkDeviceClock(): String {
        val now = System.currentTimeMillis()
        return if (now < CLOCK_FLOOR_MILLIS) {
            "Device time: ${formatMillis(now)}  ** SUSPECTED WRONG -- HTTPS will fail **"
        } else {
            "Device time: ${formatMillis(now)}"
        }
    }

    /**
     * If the clock is suspect, query NTP and (if we're device owner) set
     * the system time via DevicePolicyManager. Returns one descriptive line
     * for the splash log explaining what happened.
     */
    private fun syncClockIfNeeded(): String {
        if (System.currentTimeMillis() >= CLOCK_FLOOR_MILLIS) return "NTP sync skipped (clock looks OK)"

        // time.google.com first (anycast, fast); fall back to hardcoded Google
        // Public NTP IPs so we still work if DNS itself is broken.
        val candidates = listOf("time.google.com", "216.239.35.0", "216.239.35.4")
        var serverTime: Long? = null
        var usedServer: String? = null
        for (server in candidates) {
            val t = SntpClient.fetchTimeMillis(server, 2000) ?: continue
            if (t < CLOCK_FLOOR_MILLIS) continue // server itself returned junk
            serverTime = t
            usedServer = server
            break
        }
        if (serverTime == null) return "NTP sync FAILED: all servers unreachable (UDP 123 may be blocked)"

        val dpm = getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        if (!dpm.isDeviceOwnerApp(packageName)) {
            return "NTP got ${formatMillis(serverTime)} from $usedServer but APK is not device owner. " +
                "Run on the box: adb shell dpm set-device-owner $packageName/.SignageDeviceAdminReceiver"
        }
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.P) {
            return "NTP got ${formatMillis(serverTime)} but DPM.setTime requires Android 9+ (this box is API ${Build.VERSION.SDK_INT})"
        }
        val admin = ComponentName(this, SignageDeviceAdminReceiver::class.java)
        return try {
            // setTime fails if auto-time is on; turn it off first. setAutoTimeEnabled
            // is API 30+, so on API 28-29 we use the older setGlobalSetting path.
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
                dpm.setAutoTimeEnabled(admin, false)
            } else {
                @Suppress("DEPRECATION")
                dpm.setGlobalSetting(admin, android.provider.Settings.Global.AUTO_TIME, "0")
            }
            val ok = dpm.setTime(admin, serverTime)
            if (ok) {
                "NTP sync OK from $usedServer -> ${formatMillis(serverTime)}"
            } else {
                "NTP got ${formatMillis(serverTime)} but DPM.setTime returned false (auto-time still enforced?)"
            }
        } catch (t: Throwable) {
            "NTP sync error after fetch: ${t.javaClass.simpleName}: ${t.message}"
        }
    }

    private fun formatMillis(millis: Long): String =
        SimpleDateFormat("yyyy-MM-dd HH:mm:ss z", Locale.US).apply {
            // Display every diagnostic timestamp in Asia/Kolkata regardless of
            // the box's local TZ — the dashboard, server logs, and trigger
            // schedule are all IST, so showing IST here keeps everything
            // line-up-able without mental conversion.
            timeZone = TimeZone.getTimeZone("Asia/Kolkata")
        }.format(Date(millis))

    private fun resolvePlayerHost(): String {
        val host = try {
            URI(SignageConfig.playerUrl).host.orEmpty()
        } catch (t: Throwable) {
            return "DNS skipped (bad URL): ${t.message}"
        }
        if (host.isEmpty()) return "DNS skipped (no host in URL)"
        return try {
            val start = System.currentTimeMillis()
            val addr = InetAddress.getByName(host)
            val rtt = System.currentTimeMillis() - start
            "DNS $host -> ${addr.hostAddress}  ${rtt}ms  OK"
        } catch (t: Throwable) {
            "DNS $host  error: ${t.message}"
        }
    }

    private fun showLoadFailure(message: String) {
        Log.e(TAG, message)
        mainHandler.post {
            splashOverlay.visibility = View.VISIBLE
            pingLog.append("\n$message\n")
        }
    }

    private fun startPlayerFlow() {
        splashOverlay.visibility = View.GONE
        // Cache-bust the player URL on every boot so a stale WebView cache
        // can never trap us on an old player build. ?v=<versionCode> ties
        // it to APK releases (predictable for inspection); the timestamp
        // forces a brand-new fetch even within the same APK install.
        val url = SignageConfig.playerUrl
        val sep = if (url.contains('?')) '&' else '?'
        val busted = "$url${sep}v=${BuildInfo.versionCode}&t=${System.currentTimeMillis()}"
        Log.i(TAG, "Loading player URL: $busted")
        // Also wipe the WebView HTTP cache for our origin — the cache-bust
        // above handles index.html itself, but referenced JS/CSS would
        // otherwise still serve from cache. clearCache(true) clears disk
        // + memory caches, which is what we want on a fresh boot.
        webView.clearCache(true)
        webView.loadUrl(busted)
    }

    @SuppressLint("SetJavaScriptEnabled", "AddJavascriptInterface")
    private fun configureWebView(wv: WebView) {
        wv.settings.apply {
            javaScriptEnabled = true
            domStorageEnabled = true
            mediaPlaybackRequiresUserGesture = false
            // useWideViewPort + loadWithOverviewMode intentionally OMITTED.
            // They're mobile-browser settings that compute a virtual CSS
            // viewport (typically 980 px when the page lacks a viewport
            // meta), which made 1920×1080 images letterbox horizontally
            // inside a wider virtual layout even on a 1920×1080 screen.
            // Without these, the layout width = the WebView's actual
            // pixel width = the screen, so a 1920×1080 image fills exactly.
        }
        wv.addJavascriptInterface(AndroidBridge(applicationContext), "AndroidBridge")
        wv.webViewClient = SignageWebViewClient()
        wv.webChromeClient = object : WebChromeClient() {
            override fun onConsoleMessage(message: ConsoleMessage): Boolean {
                Log.i(
                    TAG,
                    "WebView console [${message.messageLevel()}] " +
                        "${message.message()} @ ${message.sourceId()}:${message.lineNumber()}"
                )
                return true
            }
        }
    }

    private inner class SignageWebViewClient : WebViewClient() {
        override fun onPageFinished(view: WebView?, url: String?) {
            super.onPageFinished(view, url)
            view ?: return
            val androidId = JSONObject.quote(DeviceIdentity.androidId)
            val mac = JSONObject.quote(DeviceIdentity.macAddress)
            val js = """
                (function () {
                    window.ANDROID_ID = $androidId;
                    window.ETH_MAC = $mac;
                    window.MAC_ADDRESS = $mac;
                })();
            """.trimIndent()
            view.evaluateJavascript(js, null)
        }

        override fun onReceivedError(
            view: WebView?,
            request: WebResourceRequest?,
            error: WebResourceError?
        ) {
            super.onReceivedError(view, request, error)
            if (request?.isForMainFrame != true) return
            showLoadFailure(
                "Load error ${error?.errorCode}: ${error?.description} (${request.url})"
            )
        }

        override fun onReceivedHttpError(
            view: WebView?,
            request: WebResourceRequest?,
            errorResponse: WebResourceResponse?
        ) {
            super.onReceivedHttpError(view, request, errorResponse)
            if (request?.isForMainFrame != true) return
            showLoadFailure(
                "HTTP ${errorResponse?.statusCode} ${errorResponse?.reasonPhrase} for ${request.url}"
            )
        }

        override fun onReceivedSslError(
            view: WebView?,
            handler: SslErrorHandler?,
            error: SslError?
        ) {
            showLoadFailure("SSL error ${error?.primaryError} on ${error?.url}")
            handler?.cancel()
        }
    }

    override fun onResume() {
        super.onResume()
        applyFullscreen()
    }

    override fun onWindowFocusChanged(hasFocus: Boolean) {
        super.onWindowFocusChanged(hasFocus)
        if (hasFocus) applyFullscreen()
    }

    private fun applyFullscreen() {
        if (!SignageConfig.forceFullscreen) return
        val controller = WindowInsetsControllerCompat(window, window.decorView)
        controller.systemBarsBehavior =
            WindowInsetsControllerCompat.BEHAVIOR_SHOW_TRANSIENT_BARS_BY_SWIPE
        controller.hide(WindowInsetsCompat.Type.systemBars())
    }

    /**
     * Remote key handling:
     *   - Long-press OK (DPAD_CENTER / ENTER) [LONG_PRESS_MS] → native diagnostics overlay.
     *     Short OK while it's visible dismisses it.
     *   - DPAD_DOWN → toggle the in-player "Downloads & load status" panel
     *     (shows the device folder URL + per-image load result, so a tech with
     *     just the remote can see why a picture isn't appearing).
     *   - Anything else passes through to the WebView.
     */
    override fun dispatchKeyEvent(event: KeyEvent): Boolean {
        // DEBUG: flash every key-down to the player's status overlay so we can
        // tell whether the remote is firing what we expect. Helpful when
        // KEYCODE_DPAD_DOWN doesn't toggle the panel — usually means the
        // remote sends a non-standard code (KEYCODE_BUTTON_THUMBR, channel
        // down, etc.) and we need to map that one too.
        if (event.action == KeyEvent.ACTION_DOWN && ::webView.isInitialized) {
            val keyName = KeyEvent.keyCodeToString(event.keyCode)
            Log.d(TAG, "Key event: $keyName (${event.keyCode})")
            webView.evaluateJavascript(
                "window.signageDebugKey && window.signageDebugKey(" +
                    JSONObject.quote(keyName) + ", ${event.keyCode});",
                null
            )
        }

        // DPAD_DOWN on every Android TV remote we've seen, plus a couple of
        // common alternates from quirky vendor remotes.
        val isDown = event.keyCode == KeyEvent.KEYCODE_DPAD_DOWN ||
            event.keyCode == KeyEvent.KEYCODE_DPAD_DOWN_LEFT ||
            event.keyCode == KeyEvent.KEYCODE_DPAD_DOWN_RIGHT ||
            event.keyCode == KeyEvent.KEYCODE_PAGE_DOWN
        if (isDown) {
            // Trigger on UP so we don't fire repeatedly on key-repeat.
            if (event.action == KeyEvent.ACTION_UP) {
                webView.evaluateJavascript(
                    "window.signageToggleDownloads && window.signageToggleDownloads();",
                    null
                )
                return true
            }
            // Swallow the DOWN action so it never reaches the WebView and
            // shifts focus inside the page.
            return true
        }

        // DPAD_UP forces an immediate playlist fetch — bypass the poll
        // interval so a tech standing at the screen with the remote can
        // pull a freshly-saved playlist on demand. We also fire a ping
        // through the APK channel so the dashboard's "Last seen" updates
        // at the same moment (the player JS playlist fetch hits Apache
        // directly and doesn't touch api/ping.php).
        val isUp = event.keyCode == KeyEvent.KEYCODE_DPAD_UP ||
            event.keyCode == KeyEvent.KEYCODE_DPAD_UP_LEFT ||
            event.keyCode == KeyEvent.KEYCODE_DPAD_UP_RIGHT ||
            event.keyCode == KeyEvent.KEYCODE_PAGE_UP
        if (isUp) {
            if (event.action == KeyEvent.ACTION_UP) {
                webView.evaluateJavascript(
                    "window.signageForceFetchPlaylist && window.signageForceFetchPlaylist();",
                    null
                )
                BackgroundTasks.pingNow()
                return true
            }
            return true
        }

        val isOk = event.keyCode == KeyEvent.KEYCODE_DPAD_CENTER ||
            event.keyCode == KeyEvent.KEYCODE_ENTER ||
            event.keyCode == KeyEvent.KEYCODE_BUTTON_SELECT
        if (!isOk) return super.dispatchKeyEvent(event)

        when (event.action) {
            KeyEvent.ACTION_DOWN -> {
                if (event.repeatCount == 0) {
                    mainHandler.postDelayed(showDiagnosticsRunnable, LONG_PRESS_MS)
                }
            }
            KeyEvent.ACTION_UP -> {
                mainHandler.removeCallbacks(showDiagnosticsRunnable)
                if (diagnosticsOverlay.visibility == View.VISIBLE) {
                    diagnosticsOverlay.visibility = View.GONE
                    return true
                }
            }
        }
        return super.dispatchKeyEvent(event)
    }

    private fun showDiagnosticsOverlay() {
        diagnosticsBody.text = buildDiagnosticsText()
        diagnosticsOverlay.visibility = View.VISIBLE
    }

    private fun buildDiagnosticsText(): String {
        val dpm = try {
            getSystemService(Context.DEVICE_POLICY_SERVICE) as DevicePolicyManager
        } catch (_: Throwable) { null }
        val isDeviceOwner = dpm?.isDeviceOwnerApp(packageName) == true

        // Derive folder URLs from the player URL — same shape the player JS
        // reports, kept here so the operator can see them without opening
        // the DOWN-key panel. /signage/player/index.html → /signage as base.
        val basePrefix = SignageConfig.playerUrl.substringBefore("/player/")
        val deviceFolder = if (DeviceIdentity.androidId.isNotEmpty())
            "$basePrefix/devices/${DeviceIdentity.androidId}/"
        else
            "(unknown — no ANDROID_ID)"
        val mediaBase = "$basePrefix/media/"

        return buildString {
            appendLine("App version : ${BuildInfo.versionName} (code ${BuildInfo.versionCode})")
            appendLine("Device model: ${Build.MANUFACTURER} ${Build.MODEL}")
            appendLine("Android     : ${Build.VERSION.RELEASE} (API ${Build.VERSION.SDK_INT})")
            appendLine("Device time : ${formatMillis(System.currentTimeMillis())}")
            appendLine("Device owner: $isDeviceOwner")
            appendLine()
            appendLine("ANDROID_ID  : ${DeviceIdentity.androidId.ifEmpty { "(none)" }}")
            appendLine("MAC         : ${DeviceIdentity.macAddress.ifEmpty { "(none)" }}")
            appendLine()
            appendLine("Player URL  : ${SignageConfig.playerUrl}")
            appendLine("API base    : ${SignageApi.apiBase()}")
            appendLine("Device folder: $deviceFolder")
            appendLine("Media base  : $mediaBase")
            appendLine("Fullscreen  : ${SignageConfig.forceFullscreen}")
            appendLine("Logging     : ${SignageConfig.enableLogging}")
            appendLine()
            appendLine("Last register : ${BackgroundTasks.lastRegister}")
            appendLine("Last ping     : ${BackgroundTasks.lastPing}")
            appendLine("Ping every    : ${BackgroundTasks.currentPingIntervalSeconds()}s (server-configured)")
            appendLine("Last version  : ${BackgroundTasks.lastVersion}")
            appendLine("Last update   : ${ApkUpdater.lastUpdate}")
            appendLine("Last refresh  : ${BackgroundTasks.lastForceRefresh}")
            appendLine("Crash flush   : ${CrashReporter.lastFlush}")
        }
    }

    companion object {
        private const val TAG = "SignagePlayer"
        private const val LONG_PRESS_MS = 3_000L

        // 2026-01-01 UTC — anything before this is treated as a wrong clock.
        // Update before deploying past 2027 so the floor stays meaningful.
        private val CLOCK_FLOOR_MILLIS: Long =
            Calendar.getInstance(TimeZone.getTimeZone("UTC")).apply {
                clear(); set(2026, Calendar.JANUARY, 1)
            }.timeInMillis
    }
}
