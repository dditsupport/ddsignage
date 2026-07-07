package biz.aromen.signage

import java.net.DatagramPacket
import java.net.DatagramSocket
import java.net.InetAddress

/**
 * Minimal SNTPv3 client. We only care about the server's transmit timestamp
 * (bytes 40..47 of the response), since the device clock is too far off for
 * the round-trip-correction math to be meaningful.
 */
object SntpClient {
    private const val NTP_PORT = 123
    private const val NTP_PACKET_SIZE = 48
    // NTP epoch is 1900-01-01 UTC; Unix epoch is 1970-01-01 UTC.
    private const val OFFSET_1900_TO_1970_SECONDS = 2208988800L

    /** Returns server time in Unix millis, or null on failure. */
    fun fetchTimeMillis(host: String, timeoutMs: Int): Long? {
        var socket: DatagramSocket? = null
        return try {
            socket = DatagramSocket()
            socket.soTimeout = timeoutMs
            val addr = InetAddress.getByName(host)
            val buf = ByteArray(NTP_PACKET_SIZE)
            // LI=0, VN=3, Mode=3 (client) → 0x1B
            buf[0] = 0x1B
            socket.send(DatagramPacket(buf, buf.size, addr, NTP_PORT))
            socket.receive(DatagramPacket(buf, buf.size))
            val secs1900 = readUint32(buf, 40)
            val frac = readUint32(buf, 44)
            val unixSecs = secs1900 - OFFSET_1900_TO_1970_SECONDS
            unixSecs * 1000L + ((frac * 1000L) ushr 32)
        } catch (_: Throwable) {
            null
        } finally {
            try { socket?.close() } catch (_: Throwable) {}
        }
    }

    private fun readUint32(buf: ByteArray, offset: Int): Long {
        return ((buf[offset].toLong() and 0xff) shl 24) or
            ((buf[offset + 1].toLong() and 0xff) shl 16) or
            ((buf[offset + 2].toLong() and 0xff) shl 8) or
            (buf[offset + 3].toLong() and 0xff)
    }
}
