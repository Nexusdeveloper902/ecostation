package co.ecostation.app.net

/**
 * The EcoStation server address. Pure Kotlin, host-tested.
 *
 * DNS-SD contract: service `_ecostation._tcp.local`, port = the web/API
 * port, TXT protocol=1. The `protocol` TXT is a compatibility gate: absent =
 * accepted, anything but "1" = rejected. A manual address always wins over
 * discovery.
 */
data class EcoEndpoint(val host: String, val port: Int) {
    val baseUrl: String get() = "http://${hostLiteral()}:$port"

    private fun hostLiteral() = if (':' in host) "[$host]" else host

    override fun toString() = "${hostLiteral()}:$port"

    /** One DNS-SD answer, already mapped off the Android types. */
    data class Candidate(val ip: String?, val port: Int, val protocolTxt: String?)

    companion object {
        /** `NsdManager` wants the type without `.local`. */
        const val SERVICE_TYPE = "_ecostation._tcp"
        const val PROTOCOL_VERSION = "1"

        /** artisan serve default; the fallback probe has no SRV to read it from. */
        const val DEFAULT_API_PORT = 8000

        fun protocolCompatible(txt: String?) = txt.isNullOrEmpty() || txt == PROTOCOL_VERSION

        /**
         * Selection policy (same as firmware `pickEcoEndpoint`): first
         * candidate with a usable address, a non-zero port and a compatible
         * protocol TXT. IPv4 answers are preferred over IPv6 ones — the
         * backend's documented serve bind is `--host=0.0.0.0` (IPv4 only).
         */
        fun pick(candidates: List<Candidate>): EcoEndpoint? = candidates
            .filter { !it.ip.isNullOrBlank() && it.ip != "0.0.0.0" && it.port in 1..65535 }
            .filter { protocolCompatible(it.protocolTxt) }
            .sortedBy { if (':' in it.ip!!) 1 else 0 }
            .firstOrNull()
            ?.let { EcoEndpoint(it.ip!!, it.port) }

        private val HOST = Regex("[A-Za-z0-9.-]+")
        private val IPV4 = Regex("""(\d{1,3})(\.\d{1,3}){3}""")

        /**
         * Typed address: `host`, `host:port` or `http://host[:port]/`. Port
         * defaults to [DEFAULT_API_PORT]. Plain http only; loopback is refused
         * (it would be the phone itself), as in the firmware.
         */
        fun parseManual(text: String?): EcoEndpoint? {
            val t = text?.trim()?.removePrefix("http://")?.trimEnd('/') ?: return null
            val host = t.substringBefore(':')
            val port = if (':' in t) t.substringAfter(':').toIntOrNull() ?: return null else DEFAULT_API_PORT
            val ok = HOST.matches(host) && port in 1..65535 &&
                (!IPV4.matches(host) || host.split('.').all { it.toInt() <= 255 }) &&
                host != "localhost" && !host.startsWith("127.")
            return if (ok) EcoEndpoint(host, port) else null
        }

        /** Manual address if one is typed (valid or not), else [discover]. */
        fun locate(manual: String?, discover: () -> EcoEndpoint?): EcoEndpoint? =
            if (manual.isNullOrBlank()) discover() else parseManual(manual)

        /**
         * Hosts to probe on one local IPv4 subnet when mDNS finds nothing —
         * the phone-is-the-hotspot safety net. Private ranges only, and only
         * subnets of /22 or smaller, so a carrier or corporate /16 is never
         * swept. Excludes network, broadcast and the phone's own address.
         */
        fun probeHosts(address: ByteArray, prefixLength: Int): List<String> {
            if (address.size != 4 || prefixLength !in 22..30) return emptyList()
            val a = address[0].toInt() and 0xFF
            val b = address[1].toInt() and 0xFF
            val private = a == 10 || (a == 172 && b in 16..31) || (a == 192 && b == 168)
            if (!private) return emptyList()

            val self = address.fold(0L) { acc, byte -> (acc shl 8) or (byte.toLong() and 0xFF) }
            val mask = (0xFFFFFFFFL shl (32 - prefixLength)) and 0xFFFFFFFFL
            val network = self and mask
            val broadcast = network or (mask.inv() and 0xFFFFFFFFL)
            return (network + 1 until broadcast)
                .filter { it != self }
                .map { "${it shr 24 and 0xFF}.${it shr 16 and 0xFF}.${it shr 8 and 0xFF}.${it and 0xFF}" }
        }
    }
}
