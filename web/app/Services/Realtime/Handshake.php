<?php

namespace App\Services\Realtime;

/**
 * Helpers del handshake de apertura RFC 6455: parsea la cabecera HTTP,
 * calcula Sec-WebSocket-Accept y responde 101 — o rechaza con un HTTP
 * plano (401 para un token inválido) antes de cualquier frame.
 */
final class Handshake
{
    /** El GUID mágico de RFC 6455 §1.3. */
    public const GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** Sec-WebSocket-Accept = base64(SHA1(key + GUID)). */
    public static function acceptKey(string $secWebSocketKey): string
    {
        return base64_encode(sha1($secWebSocketKey.self::GUID, true));
    }

    /**
     * @return array{request_line: string, method: string, path: string, query: array<string, string>, headers: array<string, string>}
     */
    public static function parseRequest(string $rawHead): array
    {
        $lines = explode("\r\n", rtrim($rawHead, "\r\n"));
        $requestLine = (string) ($lines[0] ?? '');
        $headers = [];

        foreach (\array_slice($lines, 1) as $line) {
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        $parts = explode(' ', $requestLine);
        $target = $parts[1] ?? '/';
        [$path, $query] = \array_pad(explode('?', $target, 2), 2, '');

        $queryPairs = [];
        if ($query !== '') {
            parse_str($query, $queryPairs);
        }

        return [
            'request_line' => $requestLine,
            'method' => $parts[0] ?? '',
            'path' => $path,
            'query' => $queryPairs,
            'headers' => $headers,
        ];
    }

    public static function isUpgrade(array $request): bool
    {
        return ($request['method'] ?? '') === 'GET'
            && strcasecmp((string) ($request['headers']['upgrade'] ?? ''), 'websocket') === 0
            && (($request['headers']['sec-websocket-key'] ?? '') !== '');
    }

    public static function successResponse(string $acceptKey): string
    {
        return "HTTP/1.1 101 Switching Protocols\r\n"
            ."Upgrade: websocket\r\n"
            ."Connection: Upgrade\r\n"
            ."Sec-WebSocket-Accept: {$acceptKey}\r\n\r\n";
    }

    /** Rechazo en HTTP plano, antes de empezar a enmarcar WebSocket. */
    public static function denialResponse(int $status, string $reason): string
    {
        $text = match ($status) {
            400 => 'Bad Request',
            401 => 'Unauthorized',
            426 => 'Upgrade Required',
            default => 'Error',
        };

        return "HTTP/1.1 {$status} {$text}\r\n"
            ."Connection: close\r\n"
            .'Content-Type: text/plain; charset=utf-8'."\r\n"
            .'Content-Length: '.\strlen($reason)."\r\n\r\n{$reason}";
    }
}
