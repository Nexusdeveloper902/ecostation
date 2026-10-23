<?php

namespace App\Contracts;

use App\Services\Recycling\ClassificationException;

/**
 * Swappable material-classification contract. Controllers depend on THIS
 * interface, never on a concrete classifier; swapping drivers is a .env
 * change (RECYCLING_CLASSIFIER_DRIVER), not a code change.
 */
interface MaterialClassifier
{
    /**
     * Classify a material image. The booleans are boundary knowledge
     * persisted on the deposit; every business decision (points) stays
     * backend-owned via material_class.
     *
     * @param  string  $imagePath  absolute path to the uploaded image file
     * @return array{material_class: string, confidence: float, is_bottle: bool, is_recyclable: bool}
     *
     * @throws ClassificationException on driver failure
     */
    public function classify(string $imagePath): array;
}
