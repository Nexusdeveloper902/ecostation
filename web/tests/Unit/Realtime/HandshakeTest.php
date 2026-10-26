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
            "GET /app HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\nSec-WebSocket-Version: 13\r\n",
        );
        $plain = Handshake::parseRequest("GET /admin HTTP/1.1\r\nHost: x\r\n");
        $noKey = Handshake::parseRequest(
            "GET /app HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\n",
        );

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

    #[Test]
    public function a_valid_upgrade_requires_connection_version_and_a_16_byte_key(): void
    {
        $this->assertTrue(Handshake::isUpgrade(Handshake::parseRequest($this->upgradeHead())));

        $missingConnection = $this->upgradeHead([
            'Connection' => 'keep-alive',
        ]);
        $this->assertFalse(Handshake::isUpgrade(Handshake::parseRequest($missingConnection)));

        $wrongVersion = $this->upgradeHead([
            'Sec-WebSocket-Version' => '8',
        ]);
        $this->assertFalse(Handshake::isUpgrade(Handshake::parseRequest($wrongVersion)));

        foreach (['abc', '', 'not-base64!!!', base64_encode('too-short')] as $key) {
            $badKey = $this->upgradeHead(['Sec-WebSocket-Key' => $key]);
            $this->assertFalse(Handshake::isUpgrade(Handshake::parseRequest($badKey)), $key);
        }

        $wrongUpgrade = $this->upgradeHead(['Upgrade' => 'h2c']);
        $this->assertFalse(Handshake::isUpgrade(Handshake::parseRequest($wrongUpgrade)));

        $noVersion = $this->upgradeHead(['Sec-WebSocket-Version' => '']);
        $this->assertFalse(Handshake::isUpgrade(Handshake::parseRequest($noVersion)));
    }

    #[Test]
    public function a_native_client_without_origin_is_accepted(): void
    {
        $request = Handshake::parseRequest($this->upgradeHead(['Origin' => '']));

        $this->assertTrue(Handshake::originAllowed($request));
    }

    #[Test]
    public function a_same_host_browser_origin_is_accepted(): void
    {
        $request = Handshake::parseRequest($this->upgradeHead([
            'Origin' => 'http://127.0.0.1:8081',
        ]));

        $this->assertTrue(Handshake::originAllowed($request));
    }

    #[Test]
    public function a_cross_origin_browser_is_rejected_unless_explicitly_allowed(): void
    {
        $request = Handshake::parseRequest($this->upgradeHead([
            'Origin' => 'https://evil.example',
        ]));

        $this->assertFalse(Handshake::originAllowed($request));
        $this->assertFalse(Handshake::originAllowed($request, ['https://other.example']));

        $this->assertTrue(Handshake::originAllowed($request, ['https://evil.example']));
        $this->assertTrue(Handshake::originAllowed($request, ['https://EVIL.example/']));
    }

    #[Test]
    public function an_explicit_wildcard_is_for_lan_demos_only_and_allows_any_origin(): void
    {
        $request = Handshake::parseRequest($this->upgradeHead([
            'Origin' => 'http://192.168.40.30:8000',
        ]));

        $this->assertTrue(Handshake::originAllowed($request, ['*']));
    }

    #[Test]
    public function a_same_host_different_port_is_cross_origin_and_rejected(): void
    {
        $request = Handshake::parseRequest($this->upgradeHead([
            'Origin' => 'http://127.0.0.1:9999',
        ]));

        $this->assertFalse(Handshake::originAllowed($request));
    }

    #[Test]
    public function handshake_targets_redact_the_token_query(): void
    {
        $this->assertSame(
            '/app?token=***&x=1',
            Handshake::redactTarget('/app?token=7.1893457207.secret&x=1'),
        );
        $this->assertSame('/app?x=1&token=***', Handshake::redactTarget('/app?x=1&token=secret'));
        $this->assertStringNotContainsString('secret', Handshake::redactTarget('/app?token=secret'));

        // The parsed request keeps the real token; only logging redacts it.
        $request = Handshake::parseRequest($this->upgradeHead());
        $this->assertSame('7.1893457207.abcdef', $request['query']['token']);
        $this->assertStringNotContainsString('abcdef', Handshake::redactTarget($request['target']));
    }

    #[Test]
    public function service_unavailable_has_its_own_plain_http_status(): void
    {
        $response = Handshake::denialResponse(503, 'sin base.');

        $this->assertStringStartsWith("HTTP/1.1 503 Service Unavailable\r\n", $response);
        $this->assertStringEndsWith("\r\n\r\nsin base.", $response);
    }

    /** @param  array<string, string>  $overrides  valor '' = cabecera ausente */
    private function upgradeHead(array $overrides = []): string
    {
        $headers = [
            'Host' => '127.0.0.1:8081',
            'Upgrade' => 'websocket',
            'Connection' => 'keep-alive, Upgrade',
            'Sec-WebSocket-Key' => 'dGhlIHNhbXBsZSBub25jZQ==',
            'Sec-WebSocket-Version' => '13',
            'Origin' => 'http://127.0.0.1:8081',
        ];

        foreach ($overrides as $name => $value) {
            if ($value === '') {
                unset($headers[$name]);
            } else {
                $headers[$name] = $value;
            }
        }

        $head = 'GET /app?token=7.1893457207.abcdef HTTP/1.1';
        foreach ($headers as $name => $value) {
            $head .= "\r\n{$name}: {$value}";
        }

        return $head;
    }
}
