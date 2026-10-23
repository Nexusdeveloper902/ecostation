<?php

namespace App\Services\Recycling\Drivers;

use App\Contracts\MaterialClassifier;
use App\Services\Recycling\ClassificationException;

/**
 * A misconfigured driver must fail at CLASSIFY time, never while the
 * container builds the controller. This object keeps the HTTP contract
 * (503 + "clasificador no disponible") and gives the device a retryable
 * verdict instead of an unhandled 500.
 */
class FailingClassifier implements MaterialClassifier
{
    public function __construct(private readonly string $driver) {}

    public function classify(string $imagePath): array
    {
        throw ClassificationException::driverUnavailable(
            $this->driver,
            "unknown classifier driver [{$this->driver}] (expected stub | deepseek)",
        );
    }
}
