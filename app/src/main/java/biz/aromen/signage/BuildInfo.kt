package biz.aromen.signage

/**
 * Single accessor for app version + code so the rest of the codebase
 * doesn't import [BuildConfig] directly. Trivial but keeps the call sites
 * stable if we ever swap the source (e.g. from manifest, or from a runtime
 * version override).
 */
object BuildInfo {
    val versionName: String = BuildConfig.VERSION_NAME
    val versionCode: Int = BuildConfig.VERSION_CODE
}
