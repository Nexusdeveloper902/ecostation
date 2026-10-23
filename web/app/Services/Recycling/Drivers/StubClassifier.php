<?php

namespace App\Services\Recycling\Drivers;

use App\Contracts\MaterialClassifier;
use App\Enums\MaterialClass;
use App\Services\Recycling\ClassificationException;

/**
 * Deterministic per-image stub classifier (the default driver): the class
 * comes from a SHA-256 hash of the image bytes and confidence from the
 * same hash, so the whole pipeline works with zero model dependencies.
 */
class StubClassifier implements MaterialClassifier
{
    public function classify(string $imagePath): array
    {
        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw ClassificationException::driverUnavailable('stub', "image not readable at [{$imagePath}]");
        }

        $hash = hash_file('sha256', $imagePath);
        $materials = MaterialClass::cases();

        $class = $materials[hexdec(substr($hash, 0, 4)) % count($materials)];
        $confidence = round(0.55 + (hexdec(substr($hash, 4, 4)) % 45) / 100, 2); // 0.55–0.99

        return [
            'material_class' => $class->value,
            'confidence' => $confidence,
            // Deterministic boundary semantics, derived from the same hash:
            // bottles are the plastic shape; other = non-recyclable.
            'is_bottle' => $class === MaterialClass::Plastic && hexdec(substr($hash, 8, 2)) % 2 === 0,
            'is_recyclable' => $class !== MaterialClass::Other,
        ];
    }
}
