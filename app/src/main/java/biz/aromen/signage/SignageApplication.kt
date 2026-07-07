package biz.aromen.signage

import android.app.Application
import android.content.Context
import android.util.Log

/**
 * Owns one-shot startup that must run before any Activity:
 *   - load config from disk
 *   - resolve device identity (ANDROID_ID / MAC, with overrides)
 *   - install the uncaught exception handler
 *   - flush any crash files queued by a previous run
 *
 * Activities can read [SignageConfig] / [DeviceIdentity] without re-initialising.
 *
 * Also exposes [appContext] so non-Activity components ([SignageApi],
 * [BackgroundTasks]) can reach the Application context without having one
 * threaded through every call. Set in [onCreate] before any other code runs.
 */
class SignageApplication : Application() {

    override fun onCreate() {
        super.onCreate()
        appContext = applicationContext
        DeviceIdentity.init(this)
        CrashReporter.install(this)
        // Don't block startup on network. Spawn a thread so a slow/down server
        // never delays the player launch.
        Thread({ CrashReporter.flushPending(this) }, "crash-flush").start()
        Log.i(TAG, "SignageApplication initialised")
    }

    companion object {
        private const val TAG = "SignagePlayer"

        /**
         * Application context, set in [onCreate]. Available to anyone
         * after Application init has run (i.e. before any Activity is
         * created — so safe to read from background threads, receivers,
         * services, etc.). Never holds an Activity reference.
         */
        lateinit var appContext: Context
            private set
    }
}
