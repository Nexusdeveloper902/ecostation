package co.ecostation.app.net

import org.junit.Assert.assertEquals
import org.junit.Assert.assertFalse
import org.junit.Assert.assertNull
import org.junit.Assert.assertTrue
import org.junit.Test

class EcoEndpointTest {

    @Test
    fun pickMirrorsTheFirmwarePolicy() {
        val c = EcoEndpoint.Companion
        assertNull(c.pick(emptyList()))
        val picked = c.pick(
            listOf(
                EcoEndpoint.Candidate(null, 8000, "1"),
                EcoEndpoint.Candidate("0.0.0.0", 8000, "1"),
                EcoEndpoint.Candidate("192.168.43.10", 0, "1"),
                EcoEndpoint.Candidate("192.168.43.11", 8000, "2"),
                EcoEndpoint.Candidate("192.168.43.12", 8001, null), // missing TXT accepted
            ),
        )
        assertEquals(EcoEndpoint("192.168.43.12", 8001), picked)
        assertEquals(
            "10.0.0.5",
            c.pick(listOf(EcoEndpoint.Candidate("fe80::1", 8000, "1"), EcoEndpoint.Candidate("10.0.0.5", 8000, "1")))!!.host,
        )
        assertEquals("http://192.168.43.12:8001", picked!!.baseUrl)
        assertEquals("http://[fe80::1]:8000", EcoEndpoint("fe80::1", 8000).baseUrl)
        assertEquals("[fe80::1]:8000", EcoEndpoint("fe80::1", 8000).toString())
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

    @Test fun parseManual_accepts_hosts_ports_and_urls_and_refuses_garbage() {
        assertEquals(EcoEndpoint("192.168.1.10", 8000), EcoEndpoint.parseManual("192.168.1.10"))
        assertEquals(EcoEndpoint("192.168.1.10", 8080), EcoEndpoint.parseManual(" 192.168.1.10:8080 "))
        assertEquals(EcoEndpoint("servidor.local", 8000), EcoEndpoint.parseManual("http://servidor.local:8000/"))
        assertNull(EcoEndpoint.parseManual(""))
        assertNull(EcoEndpoint.parseManual(null))
        assertNull(EcoEndpoint.parseManual("host:99999"))
        assertNull(EcoEndpoint.parseManual("host:0"))
        assertNull(EcoEndpoint.parseManual("a b"))
        assertNull(EcoEndpoint.parseManual("https://x"))
    }

    @Test fun parseManual_rejects_loopback_and_bad_ipv4() {
        assertNull(EcoEndpoint.parseManual("127.0.0.1"))
        assertNull(EcoEndpoint.parseManual("127.0.0.1:8000"))
        assertNull(EcoEndpoint.parseManual("localhost"))
        assertNull(EcoEndpoint.parseManual("300.1.1.1"))
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
