<?php

namespace App\Services\Recycling;

use RuntimeException;

/**
 * Raised when a material-classifier driver fails. Deliberately distinct
 * from validation errors: the controller maps this to HTTP 503 so devices
 * can retry later.
 */
class ClassificationException extends RuntimeException
{
    public static function driverUnavailable(string $driver, string $reason): self
    {
        return new self("Classifier driver [{$driver}] failed: {$reason}");
    }

    /** DeepSeek 402: the key is valid but the account has no balance. */
    public static function insufficientBalance(): self
    {
        return new self('Classifier driver [deepseek] failed: saldo insuficiente en DeepSeek (HTTP 402) — recargue la cuenta');
    }
}
