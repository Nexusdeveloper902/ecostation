<?php

namespace App\Services\Recycling;

use App\Contracts\MaterialClassifier;
use App\Services\Recycling\Drivers\DeepSeekClassifier;
use App\Services\Recycling\Drivers\StubClassifier;

/**
 * Resolves the configured MaterialClassifier driver. Registering a new
 * driver = adding it here + config — no controller edits.
 */
class ClassifierFactory
{
    public static function make(): MaterialClassifier
    {
        $driver = config('ecostation.classifier.driver', 'stub');

        return match ($driver) {
            'stub' => app(StubClassifier::class),
            'deepseek' => new DeepSeekClassifier(
                config('ecostation.classifier.deepseek.api_key'),
                (string) config('ecostation.classifier.deepseek.model', 'deepseek-flash'),
                (float) config('ecostation.classifier.deepseek.timeout'),
            ),
            // A typo'd RECYCLING_CLASSIFIER_DRIVER must surface as the
            // standard classifier failure (HTTP 503), not an unhandled 500.
            default => throw ClassificationException::driverUnavailable(
                'factory',
                "unknown classifier driver [{$driver}] (expected stub | deepseek)",
            ),
        };
    }
}
