package biz.aromen.signage

/**
 * Build-time configuration. All values are baked into the APK; there is no
 * runtime config file and no SD-card override. Change a value, rebuild,
 * reinstall the APK.
 *
 * If we ever need per-device differentiation again, the right move is a
 * config endpoint on the server keyed by ANDROID_ID — not a local file.
 */
object SignageConfig {

    const val playerUrl: String = "https://yourdomain/signage/player/index.html"
    const val enableLogging: Boolean = true
    const val forceFullscreen: Boolean = true
}
