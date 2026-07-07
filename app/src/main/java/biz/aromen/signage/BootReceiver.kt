package biz.aromen.signage

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.util.Log

/**
 * Re-launches MainActivity after the box powers on, so a power cycle in a
 * store doesn't leave the TV blank.
 *
 * Some vendor ROMs emit only QUICKBOOT_POWERON instead of (or in addition to)
 * BOOT_COMPLETED, so we listen for both.
 */
class BootReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val action = intent.action ?: return
        if (action != Intent.ACTION_BOOT_COMPLETED &&
            action != "android.intent.action.QUICKBOOT_POWERON" &&
            action != "com.htc.intent.action.QUICKBOOT_POWERON") {
            return
        }
        Log.i(TAG, "Boot received ($action) — launching MainActivity")
        val launch = Intent(context, MainActivity::class.java).apply {
            addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
        }
        try {
            context.startActivity(launch)
        } catch (t: Throwable) {
            Log.e(TAG, "startActivity from boot failed: ${t.message}")
        }
    }

    companion object {
        private const val TAG = "SignagePlayer"
    }
}
