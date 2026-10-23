<?php

namespace Tests\Unit\Realtime;

use App\Services\Realtime\WsFrame;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Códec de frames RFC 6455: ida y vuelta, las tres longitudes, máscara
 * y el techo de tamaño de entrada.
 */
class WsFrameTest extends TestCase
{
    #[Test]
    public function small_text_frames_roundtrip(): void
    {
        $frame = WsFrame::encode('{"type":"tap"}', WsFrame::OP_TEXT);

        $result = WsFrame::decode($frame);

        $this->assertSame(\strlen($frame), $result['consumed']);
        $this->assertTrue($result['frame']['fin']);
        $this->assertSame(WsFrame::OP_TEXT, $result['frame']['opcode']);
        $this->assertSame('{"type":"tap"}', $result['frame']['payload']);
    }

    #[Test]
    public function payload_of_125_bytes_uses_the_short_form(): void
    {
        $payload = str_repeat('x', 125);
        $frame = WsFrame::encode($payload);

        $this->assertSame("\x7d", $frame[1]);
        $result = WsFrame::decode($frame);
        $this->assertSame($payload, $result['frame']['payload']);
    }

    #[Test]
    public function payload_of_126_bytes_switches_to_the_16_bit_form(): void
    {
        $payload = str_repeat('y', 126);
        $frame = WsFrame::encode($payload);

        $this->assertSame(126, \ord($frame[1]));
        $this->assertSame(126, unpack('n', substr($frame, 2, 2))[1]);
        $result = WsFrame::decode($frame);
        $this->assertSame($payload, $result['frame']['payload']);
    }

    #[Test]
    public function a_large_payload_roundtrips_over_the_64_bit_form(): void
    {
        $payload = str_repeat('z', 70000);
        $result = WsFrame::decode(WsFrame::encode($payload));

        $this->assertSame(70000, \strlen($result['frame']['payload']));
    }

    #[Test]
    public function masked_client_frames_are_unmasked_on_decode(): void
    {
        $masked = WsFrame::encodeMasked('hello, server', WsFrame::OP_TEXT, "\x01\x02\x03\x04");
        $result = WsFrame::decode($masked);

        $this->assertSame('hello, server', $result['frame']['payload']);
    }

    #[Test]
    public function partial_buffers_wait_for_more_bytes(): void
    {
        $frame = WsFrame::encode('complete payload');

        $result = WsFrame::decode(substr($frame, 0, 5));

        $this->assertSame(0, $result['consumed']);
        $this->assertNull($result['frame']);
    }

    #[Test]
    public function trailing_bytes_are_reported_for_buffer_trimming(): void
    {
        $frame = WsFrame::encode('first');
        $buffer = $frame.'leftover bytes';

        $result = WsFrame::decode($buffer);

        $this->assertSame(\strlen($frame), $result['consumed']);
        $this->assertSame('first', $result['frame']['payload']);
        $this->assertSame('leftover bytes', substr($buffer, $result['consumed']));
    }

    #[Test]
    public function ping_frames_encode_and_decode(): void
    {
        $result = WsFrame::decode(WsFrame::encode('hb', WsFrame::OP_PING));

        $this->assertSame(WsFrame::OP_PING, $result['frame']['opcode']);
        $this->assertSame('hb', $result['frame']['payload']);
    }

    #[Test]
    public function a_masked_client_frame_decodes_when_the_mask_is_required(): void
    {
        $frame = WsFrame::encodeMasked('hi', WsFrame::OP_PING, "\x01\x02\x03\x04");

        $result = WsFrame::decode($frame, requireMask: true);

        $this->assertNull($result['error']);
        $this->assertSame('hi', $result['frame']['payload']);
    }

    #[Test]
    public function an_unmasked_client_frame_is_rejected_when_the_mask_is_required(): void
    {
        $result = WsFrame::decode(WsFrame::encode('unmasked'), requireMask: true);

        $this->assertSame('protocol', $result['error']);
        $this->assertNull($result['frame']);
    }

    #[Test]
    public function rsv_bits_are_rejected(): void
    {
        $frame = WsFrame::encodeMasked('x', WsFrame::OP_TEXT, "\0\0\0\0");
        $frame[0] = \chr(\ord($frame[0]) | 0x40);

        $this->assertSame('protocol', WsFrame::decode($frame, requireMask: true)['error']);
    }

    #[Test]
    public function fragmented_control_frames_are_rejected(): void
    {
        $frame = WsFrame::encodeMasked('hb', WsFrame::OP_PING, "\0\0\0\0");
        $frame[0] = \chr(\ord($frame[0]) & 0x7F);

        $this->assertSame('protocol', WsFrame::decode($frame, requireMask: true)['error']);
    }

    #[Test]
    public function control_payloads_over_125_bytes_are_rejected(): void
    {
        $frame = WsFrame::encodeMasked(str_repeat('x', 126), WsFrame::OP_PING, "\0\0\0\0");

        $this->assertSame('protocol', WsFrame::decode($frame, requireMask: true)['error']);
    }

    #[Test]
    public function reserved_opcodes_are_rejected(): void
    {
        foreach ([0x3, 0x7, 0xB, 0xF] as $opcode) {
            $frame = WsFrame::encodeMasked('x', $opcode, "\0\0\0\0");

            $this->assertSame(
                'protocol',
                WsFrame::decode($frame, requireMask: true)['error'],
                sprintf('opcode 0x%X debe rechazarse', $opcode),
            );
        }
    }
}
