package co.ecostation.app.net

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class EcoEndpointTest {

    @Test
    fun pickMirrorsTheFirmwarePolicyAndKeepsOnlyLanHosts() {
        val c = EcoEndpoint.Companion
        assertNull(c.pick(emptyList()))
        val picked = c.pick(
            listOf(
                EcoEndpoint.Candidate(null, 8000, "1"),
                EcoEndpoint.Candidate("0.0.0.0", 8000, "1"),
                EcoEndpoint.Candidate("192.168.43.10", 0, "1"),
                EcoEndpoint.Candidate("192.168.43.11", 8000, "2"),
                EcoEndpoint.Candidate("8.8.8.8", 8000, "1"), // public: refused
                EcoEndpoint.Candidate("fe80::1", 8000, "1"), // IPv6: refused
                EcoEndpoint.Candidate("192.168.43.12", 8001, null), // missing TXT accepted
            ),
        )
        assertEquals(EcoEndpoint("192.168.43.12", 8001), picked)
        assertEquals("http://192.168.43.12:8001", picked!!.baseUrl)
        assertEquals(
            "192.168.43.20",
            c.pick(listOf(EcoEndpoint.Candidate("10.0.0.5", 8000, "2"), EcoEndpoint.Candidate("192.168.43.20", 8000, "1")))!!.host,
        )
        // A .local mDNS answer is a LAN name.
        assertEquals(
            EcoEndpoint("estacion.local", 8000),
            c.pick(listOf(EcoEndpoint.Candidate("estacion.local", 8000, "1"))),
        )
        // IPv6 formatting still exists for callers that build an endpoint directly.
        assertEquals("http://[fe80::1]:8000", EcoEndpoint("fe80::1", 8000).baseUrl)
        assertEquals("[fe80::1]:8000", EcoEndpoint("fe80::1", 8000).toString())
    }

    @Test
    fun rank_keeps_every_acceptable_candidate_in_answer_order_for_verification() {
        val candidates = listOf(
            EcoEndpoint.Candidate("192.168.43.10", 8000, "1"),
            EcoEndpoint.Candidate("8.8.8.8", 8000, "1"),
            EcoEndpoint.Candidate("192.168.43.11", 8000, "1"),
            EcoEndpoint.Candidate("192.168.43.10", 8000, "1"), // duplicate
            EcoEndpoint.Candidate("192.168.43.12", 8000, "2"), // wrong protocol
            EcoEndpoint.Candidate("192.168.43.13", 8001, null),
        )
        assertEquals(
            listOf(
                EcoEndpoint("192.168.43.10", 8000),
                EcoEndpoint("192.168.43.11", 8000),
                EcoEndpoint("192.168.43.13", 8001),
            ),
            EcoEndpoint.rank(candidates),
        )
        assertEquals(EcoEndpoint("192.168.43.10", 8000), EcoEndpoint.pick(candidates))
    }

    @Test
    fun probeHostsOnlySweepsSmallPrivateSubnets() {
        val hotspot = byteArrayOf(192.toByte(), 168.toByte(), 43, 1)
        val hosts = EcoEndpoint.probeHosts(hotspot, 24)
        assertEquals(253, hosts.size)
        assertTrue("192.168.43.91" in hosts)
        assertTrue("192.168.43.1" !in hosts && "192.168.43.0" !in hosts && "192.168.43.255" !in hosts)
        assertEquals(1022 - 1, EcoEndpoint.probeHosts(byteArrayOf(10, 1, 2, 3), 22).size)
        assertTrue(EcoEndpoint.probeHosts(byteArrayOf(10, 1, 2, 3), 16).isEmpty())
        assertTrue(EcoEndpoint.probeHosts(byteArrayOf(8, 8, 8, 8), 24).isEmpty())
        assertTrue(EcoEndpoint.probeHosts(byteArrayOf(172.toByte(), 32, 0, 1), 24).isEmpty())
    }

    @Test fun parseManual_accepts_lan_hosts_ports_and_urls_and_refuses_garbage() {
        assertEquals(EcoEndpoint("192.168.1.10", 8000), EcoEndpoint.parseManual("192.168.1.10"))
        assertEquals(EcoEndpoint("192.168.1.10", 8080), EcoEndpoint.parseManual(" 192.168.1.10:8080 "))
        // The returned host is the one that was validated, without padding.
        assertEquals(EcoEndpoint("192.168.1.10", 8000), EcoEndpoint.parseManual("192.168.1.10 :8000"))
        assertEquals(EcoEndpoint("servidor.local", 8000), EcoEndpoint.parseManual("http://servidor.local:8000/"))
        assertEquals(EcoEndpoint("estacion.lan", 8000), EcoEndpoint.parseManual("estacion.lan"))
        assertEquals(EcoEndpoint("169.254.10.20", 8000), EcoEndpoint.parseManual("169.254.10.20"))
        assertEquals(EcoEndpoint("172.16.0.9", 8000), EcoEndpoint.parseManual("172.16.0.9"))
        assertNull(EcoEndpoint.parseManual(""))
        assertNull(EcoEndpoint.parseManual(null))
        assertNull(EcoEndpoint.parseManual("host:99999"))
        assertNull(EcoEndpoint.parseManual("host:0"))
        assertNull(EcoEndpoint.parseManual("a b"))
        assertNull(EcoEndpoint.parseManual("https://x"))
        assertNull(EcoEndpoint.parseManual("http://192.168.1.10/ruta"))
    }

    @Test fun parseManual_rejects_loopback_public_and_bad_ipv4() {
        assertNull(EcoEndpoint.parseManual("127.0.0.1"))
        assertNull(EcoEndpoint.parseManual("127.0.0.1:8000"))
        assertNull(EcoEndpoint.parseManual("localhost"))
        assertNull(EcoEndpoint.parseManual("0.0.0.0"))
        assertNull(EcoEndpoint.parseManual("8.8.8.8"))
        assertNull(EcoEndpoint.parseManual("172.32.0.1"))
        assertNull(EcoEndpoint.parseManual("192.169.0.1"))
        assertNull(EcoEndpoint.parseManual("ecostation.example.com"))
        assertNull(EcoEndpoint.parseManual("300.1.1.1"))
    }

    @Test fun lan_host_policy_is_explicit() {
        for (host in listOf("10.0.0.1", "10.255.255.254", "172.16.0.1", "172.31.255.254",
            "192.168.0.1", "169.254.1.1", "eco.local", "ECO.LAN")) {
            assertTrue(host, EcoEndpoint.isLanHost(host))
        }
        for (host in listOf("0.0.0.0", "127.0.0.1", "8.8.8.8", "172.15.0.1", "172.32.0.1",
            "192.169.0.1", "localhost", "ecostation.example.com", "fe80::1", "", "a b")) {
            assertFalse(host, EcoEndpoint.isLanHost(host))
        }
    }

    @Test fun the_manifest_must_name_the_product_and_carry_start_url() {
        assertTrue(
            EcoEndpoint.isEcoStationManifest(
                """{"name":"EcoStation","start_url":"/","icons":[]}""",
            ),
        )
        assertTrue(
            EcoEndpoint.isEcoStationManifest(
                """{ "name" : "EcoStation" , "start_url" : "/" }""",
            ),
        )
        assertFalse(EcoEndpoint.isEcoStationManifest("""{"start_url":"/"}"""))
        assertFalse(EcoEndpoint.isEcoStationManifest("""{"name":"OtraApp","start_url":"/"}"""))
        assertFalse(EcoEndpoint.isEcoStationManifest("""{"name":"EcoStation"}"""))
    }

    @Test fun discovery_accepts_only_a_lan_host_whose_manifest_names_ecostation() {
        val endpoint = EcoEndpoint("192.168.43.10", 8000)
        // Good manifest: both fields present.
        assertTrue(EcoEndpoint.confirmsEcoStation(endpoint, """{"name":"EcoStation","start_url":"/"}"""))
        // Missing name.
        assertFalse(EcoEndpoint.confirmsEcoStation(endpoint, """{"start_url":"/"}"""))
        // Missing start_url.
        assertFalse(EcoEndpoint.confirmsEcoStation(endpoint, """{"name":"EcoStation"}"""))
        // No body at all (HTTP error / unreadable manifest).
        assertFalse(EcoEndpoint.confirmsEcoStation(endpoint, null))
        assertFalse(EcoEndpoint.confirmsEcoStation(endpoint, ""))
        // A public host is refused even with a perfect manifest.
        assertFalse(
            EcoEndpoint.confirmsEcoStation(
                EcoEndpoint("8.8.8.8", 8000),
                """{"name":"EcoStation","start_url":"/"}""",
            ),
        )
    }

    @Test fun cellular_and_vpn_interfaces_are_excluded_from_the_sweep() {
        for (name in listOf("rmnet_data0", "ccmni0", "pdp0", "v4-rmnet_data1", "tun0", "dummy0",
            "ppp0", "wwan0", "radio0", "seth0")) {
            assertTrue(name, BackendDiscovery.isCellularInterface(name))
        }
        for (name in listOf("wlan0", "eth0", "ap0", "br0")) {
            assertFalse(name, BackendDiscovery.isCellularInterface(name))
        }
    }

    @Test fun manual_address_wins_over_discovery() {
        var called = false
        assertEquals(EcoEndpoint("10.0.0.5", 8000), EcoEndpoint.locate("10.0.0.5") { called = true; null })
        assertFalse(called)
        assertEquals(EcoEndpoint("10.0.0.9", 8000), EcoEndpoint.locate("  ") { EcoEndpoint("10.0.0.9", 8000) })
        assertNull(EcoEndpoint.locate("a b") { EcoEndpoint("10.0.0.9", 8000) })
    }

    @Test fun service_type_is_ecostation() = assertEquals("_ecostation._tcp", EcoEndpoint.SERVICE_TYPE)
}
