<?php

namespace App\Services\Realtime;

/**
 * Códec mínimo de frames RFC 6455. Los frames servidor→cliente van sin
 * máscara; los cliente→servidor deben venir enmascarados. Solo lo que
 * el feed necesita: text/ping/pong/close, FIN único, las tres
 * codificaciones de longitud y un techo de 1 MiB por frame.
 */
final class WsFrame
{
    public const OP_CONT = 0x0;

    public const OP_TEXT = 0x1;

    public const OP_BINARY = 0x2;

    public const OP_CLOSE = 0x8;

    public const OP_PING = 0x9;

    public const OP_PONG = 0xA;

    /** Techo de un frame entrante: 1 MiB. */
    public const MAX_INBOUND = 0x100000;

    public static function encode(string $payload, int $opcode = self::OP_TEXT, bool $fin = true): string
    {
        $length = \strlen($payload);
        $frame = chr(($fin ? 0x80 : 0x00) | $opcode);

        if ($length < 126) {
            $frame .= chr($length);
        } elseif ($length < 65536) {
            $frame .= chr(126).pack('n', $length);
        } else {
            $frame .= chr(127).pack('J', $length);
        }

        return $frame.$payload;
    }

    /**
     * @return array{consumed: int, frame: array{fin: bool, opcode: int, payload: string}|null, error: string|null}
     */
    public static function decode(string $buffer): array
    {
        $len = \strlen($buffer);
        if ($len < 2) {
            return ['consumed' => 0, 'frame' => null, 'error' => null];
        }

        $b0 = \ord($buffer[0]);
        $b1 = \ord($buffer[1]);

        $fin = ($b0 & 0x80) !== 0;
        $rsv = ($b0 & 0x70) !== 0;
        $opcode = $b0 & 0x0F;
        $masked = ($b1 & 0x80) !== 0;
        $length = $b1 & 0x7F;
        $offset = 2;

        if ($length === 126) {
            if ($len < 4) {
                return ['consumed' => 0, 'frame' => null, 'error' => null];
            }
            $length = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($length === 127) {
            if ($len < 10) {
                return ['consumed' => 0, 'frame' => null, 'error' => null];
            }
            $length = unpack('J', substr($buffer, 2, 8))[1];
            $offset = 10;
        }

        if ($rsv || $length > self::MAX_INBOUND || ($length !== 0 && $opcode === self::OP_CONT)) {
            return ['consumed' => 0, 'frame' => null, 'error' => 'protocol'];
        }

        $mask = '';
        if ($masked) {
            if ($len < $offset + 4) {
                return ['consumed' => 0, 'frame' => null, 'error' => null];
            }
            $mask = substr($buffer, $offset, 4);
            $offset += 4;
        }

        if ($len < $offset + $length) {
            return ['consumed' => 0, 'frame' => null, 'error' => null];
        }

        $payload = substr($buffer, $offset, $length);
        if ($masked) {
            for ($i = 0; $i < $length; $i++) {
                $payload[$i] = \chr(\ord($payload[$i]) ^ \ord($mask[$i % 4]));
            }
        }

        return [
            'consumed' => $offset + $length,
            'frame' => ['fin' => $fin, 'opcode' => $opcode, 'payload' => $payload],
            'error' => null,
        ];
    }

    public static function encodeMasked(string $payload, int $opcode = self::OP_TEXT, ?string $mask = null): string
    {
        $mask ??= random_bytes(4);
        $masked = '';
        $length = \strlen($payload);
        for ($i = 0; $i < $length; $i++) {
            $masked .= \chr(\ord($payload[$i]) ^ \ord($mask[$i % 4]));
        }

        $frame = chr(0x80 | $opcode);
        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $frame .= chr(0x80 | 126).pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127).pack('J', $length);
        }

        return $frame.$mask.$masked;
    }
}
