<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The teacher reporting range: `?from`/`?to` as Y-m-d, defaulting to the
 * last 30 days. Unparseable or reversed values degrade to a safe range
 * instead of a 500.
 */
final class ReportRange
{
    /** @return array{0: string, 1: string} [from, to] */
    public static function resolve(Request $request): array
    {
        $to = self::safeDate((string) $request->query('to', '')) ?? Carbon::today()->toDateString();
        $from = self::safeDate((string) $request->query('from', '')) ?? Carbon::parse($to)->subDays(29)->toDateString();

        if (Carbon::parse($from)->gt(Carbon::parse($to))) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    private static function safeDate(string $raw): ?string
    {
        if ($raw === '') {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $raw);
        } catch (\Throwable) {
            return null;
        }

        return $date->format('Y-m-d') === $raw ? $raw : null;
    }
}
