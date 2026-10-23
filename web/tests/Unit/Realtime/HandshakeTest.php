<?php

namespace Tests\Unit\Realtime;

use App\Services\Realtime\Handshake;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Helpers del handshake RFC 6455, con el ejemplo trabajado de la propia
 * especificación para Sec-WebSocket-Accept.
 */
class HandshakeTest extends TestCase
{
    #[Test]
    public function the_rfc_6455_worked_example_matches(): void
    {
        $this->assertSame(
            's3pPLMBiTxaQ9kYGzzhZRbK+xOo=',
            Handshake::acceptKey('dGhlIHNhbXBsZSBub25jZQ=='),
        );
    }

    #[Test]
    public function the_request_head_parses_into_lowercased_headers(): void
    {
        $head = "GET /app?token=7.1893457207.abcdef HTTP/1.1\r\n"
            ."Host: 127.0.0.1:8081\r\n"
            ."Upgrade: WebSocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
            ."Sec-WebSocket-Version: 13\r\n";

        $request = Handshake::parseRequest($head);

        $this->assertSame('GET', $request['method']);
        $this->assertSame('/app', $request['path']);
        $this->assertSame('7.1893457207.abcdef', $request['query']['token']);
        $this->assertSame('WebSocket', $request['headers']['upgrade']);
        $this->assertSame('dGhlIHNhbXBsZSBub25jZQ==', $request['headers']['sec-websocket-key']);
    }

    #[Test]
    public function upgrades_are_recognized_and_plain_requests_are_not(): void
    {
        $upgrade = Handshake::parseRequest(
            "GET /app HTTP/1.1\r\nUpgrade: websocket\r\nSec-WebSocket-Key: abc\r\n",
        );
        $plain = Handshake::parseRequest("GET /admin HTTP/1.1\r\nHost: x\r\n");
        $noKey = Handshake::parseRequest("GET /app HTTP/1.1\r\nUpgrade: websocket\r\n");

        $this->assertTrue(Handshake::isUpgrade($upgrade));
        $this->assertFalse(Handshake::isUpgrade($plain));
        $this->assertFalse(Handshake::isUpgrade($noKey));
    }

    #[Test]
    public function the_101_response_carries_the_accept_key(): void
    {
        $accept = Handshake::acceptKey('dGhlIHNhbXBsZSBub25jZQ==');
        $response = Handshake::successResponse($accept);

        $this->assertStringStartsWith("HTTP/1.1 101 Switching Protocols\r\n", $response);
        $this->assertStringContainsString("Sec-WebSocket-Accept: {$accept}\r\n", $response);
        $this->assertStringEndsWith("\r\n\r\n", $response);
    }

    #[Test]
    public function denials_are_plain_http_with_a_body_length(): void
    {
        $response = Handshake::denialResponse(401, 'no.');

        $this->assertStringStartsWith("HTTP/1.1 401 Unauthorized\r\n", $response);
        $this->assertStringContainsString('Content-Length: 3', $response);
        $this->assertStringEndsWith("\r\n\r\nno.", $response);
    }
}
