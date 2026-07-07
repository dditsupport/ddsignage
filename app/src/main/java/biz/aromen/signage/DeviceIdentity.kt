package biz.aromen.signage

import android.annotation.SuppressLint
import android.content.Context
import android.provider.Settings
import android.util.Log
import java.net.NetworkInterface

/**
 * Resolves the device's identity values once at startup. Values are cached
 * so the JS bridge can return them synchronously from any thread.
 *
 * No overrides — the APK is fully hardcoded; what the device reports is
 * what it really is.
 */
object DeviceIdentity {

    private const val TAG = "SignagePlayer"

    var androidId: String = ""
        private set

    var macAddress: String = ""
        private set

    @SuppressLint("HardwareIds")
    fun init(context: Context) {
        androidId = try {
            Settings.Secure.getString(
                context.contentResolver,
                Settings.Secure.ANDROID_ID
            ).orEmpty()
        } catch (t: Throwable) {
            Log.w(TAG, "Failed to read ANDROID_ID: ${t.message}")
            ""
        }

        macAddress = readEthernetMac()

        Log.i(TAG, "Device identity — ANDROID_ID=$androidId, MAC=$macAddress")
    }

    private fun readEthernetMac(): String {
        return try {
            val iface = NetworkInterface.getByName("eth0") ?: return ""
            val bytes = iface.hardwareAddress ?: return ""
            bytes.joinToString(":") { String.format("%02X", it) }
        } catch (t: Throwable) {
            Log.w(TAG, "Failed to read eth0 MAC: ${t.message}")
            ""
        }
    }
}
