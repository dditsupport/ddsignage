package biz.aromen.signage

import android.content.Context
import android.os.Build
import android.util.Log
import java.io.File
import java.io.PrintWriter
import java.io.StringWriter

/**
 * Catches uncaught exceptions, writes the stack trace to a file in the
 * crash queue, then chains to the previous handler so the system still
 * tears the process down. On next launch [flushPending] uploads anything
 * left in the queue and deletes accepted files.
 *
 * Stays small on disk: each file is one stack trace, named by timestamp.
 * If the network is down, files accumulate until the next online run.
 */
object CrashReporter {

    private const val TAG = "SignagePlayer"
    private const val QUEUE_DIR = "crash_queue"

    private var previousHandler: Thread.UncaughtExceptionHandler? = null
    @Volatile private var lastFlushSummary: String = "no flush yet"

    val lastFlush: String get() = lastFlushSummary

    fun install(context: Context) {
        previousHandler = Thread.getDefaultUncaughtExceptionHandler()
        val appContext = context.applicationContext
        Thread.setDefaultUncaughtExceptionHandler { thread, throwable ->
            try {
                writeToQueue(appContext, throwable)
            } catch (t: Throwable) {
                Log.e(TAG, "Failed to write crash to queue: ${t.message}")
            }
            previousHandler?.uncaughtException(thread, throwable)
        }
    }

    fun flushPending(context: Context) {
        val dir = queueDir(context)
        val files = dir.listFiles()?.sortedBy { it.lastModified() } ?: emptyList()
        if (files.isEmpty()) {
            lastFlushSummary = "queue empty"
            return
        }
        var sent = 0
        var failed = 0
        for (file in files) {
            val stackTrace = try { file.readText() } catch (_: Throwable) { continue }
            val ok = SignageApi.reportCrash(stackTrace, file.lastModified())
            if (ok) {
                file.delete()
                sent++
            } else {
                failed++
            }
        }
        lastFlushSummary = "sent=$sent failed=$failed"
        Log.i(TAG, "Crash queue flush: $lastFlushSummary")
    }

    private fun writeToQueue(context: Context, throwable: Throwable) {
        val dir = queueDir(context)
        if (!dir.exists()) dir.mkdirs()
        val file = File(dir, "crash_${System.currentTimeMillis()}.txt")
        val header = buildString {
            appendLine("timestamp_ms=${System.currentTimeMillis()}")
            appendLine("android_id=${DeviceIdentity.androidId}")
            appendLine("device_model=${Build.MANUFACTURER} ${Build.MODEL}")
            appendLine("android_version=${Build.VERSION.RELEASE} (API ${Build.VERSION.SDK_INT})")
            appendLine("---")
        }
        file.writeText(header + stackTraceText(throwable))
    }

    private fun stackTraceText(t: Throwable): String {
        val sw = StringWriter()
        t.printStackTrace(PrintWriter(sw))
        return sw.toString()
    }

    private fun queueDir(context: Context): File =
        File(context.filesDir, QUEUE_DIR)
}
