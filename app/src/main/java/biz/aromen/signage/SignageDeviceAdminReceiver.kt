package biz.aromen.signage

import android.app.admin.DeviceAdminReceiver

/**
 * Empty receiver — its only job is to exist so this APK can be provisioned
 * as device owner via:
 *
 *   adb shell dpm set-device-owner biz.aromen.signage/.SignageDeviceAdminReceiver
 *
 * Once that succeeds, MainActivity can use DevicePolicyManager.setTime()
 * to correct a wrong clock when NTP isn't reaching the box on its own.
 */
class SignageDeviceAdminReceiver : DeviceAdminReceiver()
