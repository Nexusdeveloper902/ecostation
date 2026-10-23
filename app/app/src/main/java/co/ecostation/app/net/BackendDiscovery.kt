package co.ecostation.app.net

import android.content.Context
import android.net.nsd.NsdManager
import android.net.nsd.NsdServiceInfo
import android.os.Build
import java.net.HttpURLConnection
import java.net.Inet4Address
import java.net.InetSocketAddress
import java.net.NetworkInterface
import java.net.Socket
import java.net.URL
import java.util.concurrent.CountDownLatch
import java.util.concurrent.Executors
import java.util.concurrent.LinkedBlockingQueue
import java.util.concurrent.TimeUnit

/**
 * Finds the EcoStation server on the local network. Blocking; call it from the
 * bridge's worker thread.
 *
 * 1. **mDNS / DNS-SD** — `NsdManager` browse for `_ecostation._tcp` (the exact
 *    service the firmware's `lib/EcoDiscovery` queries and `./run serve`
 *    publishes), resolve, apply the firmware's selection policy
 *    ([EcoEndpoint.pick]). The system NSD service browses every
 *    interface, which includes the phone's own hotspot.
 * 2. **Subnet probe (fallback)** — only if mDNS yields nothing: for each
 *    private IPv4 subnet the phone is on (/22 or smaller, never cellular),
 *    try TCP :8000 on every host and confirm the answer is EcoStation
 *    (`/manifest.webmanifest`). Exists because multicast on a phone's own
 *    hotspot is the least-proven link of the demo chain.
 * / Descubre el backend: primero mDNS (_ecostation._tcp), luego sondeo de subred.
 */
class BackendDiscovery(context: Context) {

    private val nsd = context.applicationContext.getSystemService(Context.NSD_SERVICE) as NsdManager

    fun discover(log: (String) -> Unit): EcoEndpoint? {
        viaMdns(log)?.let { return it }
        log("mDNS: no ${EcoEndpoint.SERVICE_TYPE} answer — probing local subnets")
        return viaProbe(log)
    }

    // ------------------------------------------------------------------ mDNS

    private fun viaMdns(log: (String) -> Unit): EcoEndpoint? {
        val found = LinkedBlockingQueue<NsdServiceInfo>()
        val listener = object : NsdManager.DiscoveryListener {
            override fun onServiceFound(info: NsdServiceInfo) { found.add(info) }
            override fun onServiceLost(info: NsdServiceInfo) = Unit
            override fun onDiscoveryStarted(serviceType: String) = Unit
            override fun onDiscoveryStopped(serviceType: String) = Unit
            override fun onStartDiscoveryFailed(serviceType: String, errorCode: Int) {
                log("mDNS: discovery failed to start ($errorCode)")
            }
            override fun onStopDiscoveryFailed(serviceType: String, errorCode: Int) = Unit
        }

        val services = ArrayList<NsdServiceInfo>()
        try {
            nsd.discoverServices(EcoEndpoint.SERVICE_TYPE, NsdManager.PROTOCOL_DNS_SD, listener)
            // Wait for the first answer, then briefly for any siblings.
            found.poll(BROWSE_TIMEOUT_MS, TimeUnit.MILLISECONDS)?.let { services.add(it) }
            if (services.isNotEmpty()) {
                Thread.sleep(SIBLING_WAIT_MS)
                found.drainTo(services)
            }
        } catch (e: InterruptedException) {
            Thread.currentThread().interrupt()
        } catch (e: Exception) {
            log("mDNS: ${e.javaClass.simpleName}: ${e.message}")
        } finally {
            try {
                nsd.stopServiceDiscovery(listener)
            } catch (_: Exception) {
            }
        }
        if (services.isEmpty()) return null

        // Resolve one at a time (pre-Android 14 NSD refuses concurrent resolves).
        val candidates = services.distinctBy { it.serviceName }.take(MAX_CONSIDERED).mapNotNull { resolve(it, log) }
        return EcoEndpoint.pick(candidates)?.also { log("mDNS: resolved $it") }
    }

    @Suppress("DEPRECATION") // resolveService/host: still the only API below 34
    private fun resolve(service: NsdServiceInfo, log: (String) -> Unit): EcoEndpoint.Candidate? {
        val done = CountDownLatch(1)
        var result: EcoEndpoint.Candidate? = null
        nsd.resolveService(service, object : NsdManager.ResolveListener {
            override fun onResolveFailed(info: NsdServiceInfo, errorCode: Int) {
                log("mDNS: resolve '${info.serviceName}' failed ($errorCode)")
                done.countDown()
            }

            override fun onServiceResolved(info: NsdServiceInfo) {
                val addresses = if (Build.VERSION.SDK_INT >= 34) info.hostAddresses else listOfNotNull(info.host)
                val address = addresses.firstOrNull { it is Inet4Address } ?: addresses.firstOrNull()
                val protocol = info.attributes["protocol"]?.toString(Charsets.UTF_8)
                result = EcoEndpoint.Candidate(address?.hostAddress, info.port, protocol)
                done.countDown()
            }
        })
        if (!done.await(RESOLVE_TIMEOUT_MS, TimeUnit.MILLISECONDS)) log("mDNS: resolve '${service.serviceName}' timed out")
        return result
    }

    // ----------------------------------------------------------------- probe

    private fun viaProbe(log: (String) -> Unit): EcoEndpoint? {
        val hosts = localSubnetHosts()
        if (hosts.isEmpty()) {
            log("probe: no private IPv4 subnet to scan")
            return null
        }
        log("probe: ${hosts.size} hosts on :${EcoEndpoint.DEFAULT_API_PORT}")
        val pool = Executors.newFixedThreadPool(PROBE_THREADS)
        val hits = LinkedBlockingQueue<EcoEndpoint>()
        val remaining = CountDownLatch(hosts.size)
        try {
            hosts.forEach { host ->
                pool.execute {
                    try {
                        val endpoint = EcoEndpoint(host, EcoEndpoint.DEFAULT_API_PORT)
                        if (portOpen(endpoint) && looksLikeEcoStation(endpoint)) hits.add(endpoint)
                    } finally {
                        remaining.countDown()
                    }
                }
            }
            // First confirmed hit wins; otherwise wait for the sweep to end.
            val deadline = System.currentTimeMillis() + PROBE_BUDGET_MS
            while (System.currentTimeMillis() < deadline) {
                hits.poll(200, TimeUnit.MILLISECONDS)?.let {
                    log("probe: found EcoStation at $it")
                    return it
                }
                if (remaining.count == 0L && hits.isEmpty()) break
            }
            return hits.poll()
        } finally {
            pool.shutdownNow()
        }
    }

    private fun localSubnetHosts(): List<String> = try {
        NetworkInterface.getNetworkInterfaces().toList()
            .filter { it.isUp && !it.isLoopback && !it.isVirtual && !isCellular(it.name) }
            .flatMap { nif ->
                nif.interfaceAddresses
                    .filter { it.address is Inet4Address }
                    .flatMap { EcoEndpoint.probeHosts(it.address.address, it.networkPrefixLength.toInt()) }
            }
            .distinct()
    } catch (e: Exception) {
        emptyList()
    }

    private fun isCellular(name: String) = CELLULAR_PREFIXES.any { name.startsWith(it) }

    private fun portOpen(endpoint: EcoEndpoint): Boolean = try {
        Socket().use { it.connect(InetSocketAddress(endpoint.host, endpoint.port), PROBE_CONNECT_MS) }
        true
    } catch (e: Exception) {
        false
    }

    /** `/manifest.webmanifest` is a public, EcoStation web route. */
    private fun looksLikeEcoStation(endpoint: EcoEndpoint): Boolean = try {
        val conn = URL(endpoint.baseUrl + "/manifest.webmanifest").openConnection() as HttpURLConnection
        conn.connectTimeout = 1_500
        conn.readTimeout = 1_500
        conn.instanceFollowRedirects = false
        val ok = conn.responseCode == 200 &&
            conn.inputStream.bufferedReader().use { it.readText() }.contains("\"start_url\"")
        conn.disconnect()
        ok
    } catch (e: Exception) {
        false
    }

    private companion object {
        const val BROWSE_TIMEOUT_MS = 5_000L
        const val SIBLING_WAIT_MS = 400L
        const val RESOLVE_TIMEOUT_MS = 4_000L
        const val MAX_CONSIDERED = 4 // same fan-out as the firmware
        const val PROBE_THREADS = 48
        const val PROBE_CONNECT_MS = 500
        const val PROBE_BUDGET_MS = 15_000L
        val CELLULAR_PREFIXES = listOf("rmnet", "ccmni", "pdp", "v4-", "tun", "dummy", "ppp")
    }
}
