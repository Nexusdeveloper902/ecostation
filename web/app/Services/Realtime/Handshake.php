<?php

namespace App\Services\Realtime;

/**
 * Helpers del handshake de apertura RFC 6455: parsea la cabecera HTTP,
 * calcula Sec-WebSocket-Accept y responde 101 — o rechaza con un HTTP
 * plano (401 para un token inválido) antes de cualquier frame.
 *
 * La validación es deliberadamente estricta: un upgrade real exige
 * `Connection: Upgrade`, `Upgrade: websocket`, versión 13 y una clave
 * de 16 bytes; el `Origin` de un navegador debe ser el mismo host (los
 * clientes nativos pueden no enviarlo).
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
     * @return array{request_line: string, method: string, target: string, path: string, query: array<string, string>, headers: array<string, string>}
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
            'target' => $target,
            'path' => $path,
            'query' => $queryPairs,
            'headers' => $headers,
        ];
    }

    /**
     * Un upgrade válido: GET, Upgrade websocket, Connection contiene
     * Upgrade, versión 13 y una Sec-WebSocket-Key base64 de 16 bytes.
     */
    public static function isUpgrade(array $request): bool
    {
        $headers = $request['headers'] ?? [];

        return ($request['method'] ?? '') === 'GET'
            && strcasecmp((string) ($headers['upgrade'] ?? ''), 'websocket') === 0
            && self::headerHasToken((string) ($headers['connection'] ?? ''), 'upgrade')
            && trim((string) ($headers['sec-websocket-version'] ?? '')) === '13'
            && self::isValidKey((string) ($headers['sec-websocket-key'] ?? ''));
    }

    /** RFC 6455: la clave es el base64 de un nonce de 16 bytes. */
    public static function isValidKey(string $key): bool
    {
        $key = trim($key);

        if ($key === '' || \strlen($key) > 128) {
            return false;
        }

        $decoded = base64_decode($key, true);

        return is_string($decoded) && \strlen($decoded) === 16;
    }

    /**
     * Un `Origin` ausente es aceptable (clientes nativos). Un navegador
     * debe venir del mismo host que el `Host:` de la petición; cualquier
     * otro origen se rechaza salvo que esté en la lista explícita.
     *
     * @param  array<int, string>  $allowedOrigins
     */
    public static function originAllowed(array $request, array $allowedOrigins = []): bool
    {
        $headers = $request['headers'] ?? [];
        $origin = trim((string) ($headers['origin'] ?? ''));

        if ($origin === '') {
            return true;
        }

        $normalized = strtolower(rtrim($origin, '/'));
        foreach ($allowedOrigins as $allowed) {
            $allowed = trim($allowed);
            // Docker/LAN demo: an explicit `*` trusts any browser origin.
            // The WebSocket token is still required; production should list
            // the exact origins instead.
            if ($allowed === '*') {
                return true;
            }
            if ($allowed !== '' && strtolower(rtrim($allowed, '/')) === $normalized) {
                return true;
            }
        }

        $parsed = parse_url($origin);
        if (! is_array($parsed) || empty($parsed['host'])) {
            return false;
        }

        [$host, $port] = self::splitHost((string) ($headers['host'] ?? ''));
        if ($host === '') {
            return false;
        }

        $scheme = strtolower((string) ($parsed['scheme'] ?? ''));
        $originPort = isset($parsed['port'])
            ? (int) $parsed['port']
            : ($scheme === 'https' ? 443 : 80);

        if (strtolower((string) $parsed['host']) !== $host) {
            return false;
        }

        // Si el Host declara puerto, el Origin debe coincidir; si no lo
        // declara (proxy TLS), el host es la parte que importa.
        return $port === null || $originPort === $port;
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
            403 => 'Forbidden',
            408 => 'Request Timeout',
            426 => 'Upgrade Required',
            503 => 'Service Unavailable',
            default => 'Error',
        };

        return "HTTP/1.1 {$status} {$text}\r\n"
            ."Connection: close\r\n"
            .'Content-Type: text/plain; charset=utf-8'."\r\n"
            .'Content-Length: '.\strlen($reason)."\r\n\r\n{$reason}";
    }

    /**
     * Nunca registrar una URL de handshake completa: el token viaja en
     * la query. Devuelve el target con el valor de `token` enmascarado.
     */
    public static function redactTarget(string $target): string
    {
        return (string) preg_replace('/([?&]token=)[^&#]*/i', '$1***', $target);
    }

    /** ¿La cabecera (lista separada por comas) contiene el token? */
    private static function headerHasToken(string $header, string $token): bool
    {
        foreach (explode(',', $header) as $part) {
            if (strcasecmp(trim($part), $token) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: string, 1: int|null} host en minúsculas y puerto explícito (o null)
     */
    private static function splitHost(string $hostHeader): array
    {
        $hostHeader = trim($hostHeader);
        if ($hostHeader === '') {
            return ['', null];
        }

        $parsed = parse_url('http://'.$hostHeader);
        if (! is_array($parsed) || empty($parsed['host'])) {
            return ['', null];
        }

        return [
            strtolower((string) $parsed['host']),
            isset($parsed['port']) ? (int) $parsed['port'] : null,
        ];
    }
}
