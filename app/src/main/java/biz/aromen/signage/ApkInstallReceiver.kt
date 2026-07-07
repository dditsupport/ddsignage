package biz.aromen.signage

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.pm.PackageInstaller
import android.util.Log

/**
 * Receives the result of ApkUpdater's PackageInstaller.commit().
 *
 * Race note: for a successful self-update the OS kills our process before
 * (or while) delivering this broadcast, so STATUS_SUCCESS is rarely observed
 * here — the success signal is "the new APK is running on next boot."
 * Failures (INVALID_APK, INCOMPATIBLE, SIGNATURE_MISMATCH, etc.) happen
 * BEFORE the process swap, so those reliably land here and get recorded
 * for the 24 h cooldown.
 *
 * STATUS_PENDING_USER_ACTION means the box wasn't actually Device Owner
 * (provisioning got reverted, factory reset without re-provisioning, etc.).
 * We can't auto-confirm — log it and back off.
 */
class ApkInstallReceiver : BroadcastReceiver() {

    override fun onReceive(context: Context, intent: Intent) {
        val status = intent.getIntExtra(PackageInstaller.EXTRA_STATUS, -1)
        val msg = intent.getStringExtra(PackageInstaller.EXTRA_STATUS_MESSAGE)
        val version = intent.getStringExtra(ApkUpdater.EXTRA_VERSION) ?: ""

        when (status) {
            PackageInstaller.STATUS_SUCCESS -> {
                ApkUpdater.onInstallSuccess(version)
            }
            PackageInstaller.STATUS_PENDING_USER_ACTION -> {
                ApkUpdater.onInstallFailure(
                    version, status,
                    "pending user action — APK not provisioned as device owner?"
                )
                Log.w(TAG, "Install pending user action: $msg")
            }
            else -> {
                ApkUpdater.onInstallFailure(version, status, msg)
            }
        }
    }

    companion object {
        private const val TAG = "SignagePlayer"
    }
}
