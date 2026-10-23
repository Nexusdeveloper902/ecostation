package co.ecostation.app.net

import org.junit.Assert.assertEquals
import org.junit.Assert.assertThrows
import org.junit.Test
import java.io.ByteArrayInputStream
import java.io.IOException

class BoundedReadTest {

    @Test
    fun reads_up_to_the_budget() {
        assertEquals("hola", BoundedRead.read(ByteArrayInputStream("hola".toByteArray()), 4))
        assertEquals("", BoundedRead.read(ByteArrayInputStream(ByteArray(0)), 4))
        assertEquals("", BoundedRead.read(null, 4))
    }

    @Test
    fun aborts_one_byte_past_the_budget_in_spanish() {
        val e = assertThrows(IOException::class.java) {
            BoundedRead.read(ByteArrayInputStream(ByteArray(65)), 64)
        }
        assertEquals(true, e.message!!.contains("supera el límite"))
        // Exactly the budget still fits.
        assertEquals(64 * 1024, BoundedRead.read(ByteArrayInputStream(ByteArray(64 * 1024)), BoundedRead.SMALL_MAX).length)
    }
}
