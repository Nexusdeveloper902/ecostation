package co.ecostation.app.net

import java.util.Locale

/**
 * The EcoStation server address. Pure Kotlin, host-tested.
 *
 * DNS-SD contract: service `_ecostation._tcp.local`, port = the web/API
 * port, TXT protocol=1. The `protocol` TXT is a compatibility gate: absent =
 * accepted, anything but "1" = rejected. A manual address always wins over
 * discovery.
 *
 * **Only LAN HTTP is supported.** The app sends a Sanctum bearer token and the
 * station password over this connection, so a public host is refused even
 * though the manifest enables cleartext for the LAN. Accepted hosts are:
 * private IPv4 literals (10/8, 172.16/12, 192.168/16, 169.254/16) and
 * `*.local` / `*.lan` names. `https://` is still rejected: supporting it would
 * need TLS in [co.ecostation.app.speaker.WsClient], not just a scheme swap.
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
         * candidate with a LAN address, a non-zero port and a compatible
         * protocol TXT. Non-LAN answers (public IPv4, cellular IPv6) are
         * discarded before selection.
         */
        fun pick(candidates: List<Candidate>): EcoEndpoint? = candidates
            .filter { !it.ip.isNullOrBlank() && it.port in 1..65535 }
            .filter { protocolCompatible(it.protocolTxt) }
            .filter { isLanHost(it.ip!!) }
            .firstOrNull()
            ?.let { EcoEndpoint(it.ip!!, it.port) }

        private val HOST = Regex("[A-Za-z0-9.-]+")
        private val IPV4 = Regex("""(\d{1,3})(\.\d{1,3}){3}""")
        private val MANIFEST_NAME = Regex(""""name"\s*:\s*"EcoStation"""")

        /**
         * The only hosts this app will send credentials to: a private IPv4
         * literal or a `.local` / `.lan` name. Loopback, `0.0.0.0`, public
         * IPv4 and IPv6 are refused.
         */
        fun isLanHost(host: String): Boolean {
            val h = host.trim().lowercase(Locale.ROOT)
            if (h.isEmpty() || ':' in h) return false
            if (IPV4.matches(h)) {
                val parts = h.split('.').map { it.toIntOrNull() ?: return false }
                if (parts.any { it !in 0..255 }) return false
                val a = parts[0]
                val b = parts[1]
                if (a == 0 || a == 127) return false
                return a == 10 ||
                    (a == 172 && b in 16..31) ||
                    (a == 192 && b == 168) ||
                    (a == 169 && b == 254)
            }
            if (!HOST.matches(h) || h == "localhost") return false
            return h.endsWith(".local") || h.endsWith(".lan")
        }

        /** The web manifest an EcoStation backend publishes. */
        fun isEcoStationManifest(body: String): Boolean =
            body.contains("\"start_url\"") && MANIFEST_NAME.containsMatchIn(body)

        /**
         * The accept/reject decision for a discovered endpoint: the host must
         * be LAN-only and its `/manifest.webmanifest` must carry both the
         * product name and `start_url`. Used for the mDNS winner too — a TXT
         * `protocol=1` answer alone must not receive the station password.
         */
        fun confirmsEcoStation(endpoint: EcoEndpoint, manifestBody: String?): Boolean =
            isLanHost(endpoint.host) &&
                manifestBody != null &&
                isEcoStationManifest(manifestBody)

        /**
         * Typed address: `host`, `host:port` or `http://host[:port]/`. Port
         * defaults to [DEFAULT_API_PORT]. Only LAN HTTP is accepted; loopback
         * and `0.0.0.0` are refused (they are the phone itself or a wildcard).
         */
        fun parseManual(text: String?): EcoEndpoint? {
            val raw = text?.trim() ?: return null
            if (raw.isEmpty()) return null
            val withoutScheme = when {
                raw.startsWith("http://", ignoreCase = true) -> raw.substring(7)
                "://" in raw -> return null // https, ws, anything else: unsupported
                else -> raw
            }
            val body = withoutScheme.trimEnd('/')
            if (body.isEmpty() || '/' in body || body.count { it == ':' } > 1) return null
            val host = body.substringBefore(':').trim()
            val port = if (':' in body) {
                body.substringAfter(':').toIntOrNull() ?: return null
            } else {
                DEFAULT_API_PORT
            }
            if (port !in 1..65535 || !isLanHost(host)) return null
            return EcoEndpoint(host.lowercase(Locale.ROOT), port)
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
