<?php

namespace Tests\Unit;

use App\Enums\MaterialClass;
use App\Services\Recycling\Drivers\StubClassifier;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StubClassifierTest extends TestCase
{
    private StubClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->classifier = new StubClassifier;
    }

    #[Test]
    public function returns_a_valid_material_class(): void
    {
        $path = $this->tempImage('alpha');

        $result = $this->classifier->classify($path);

        $this->assertContains($result['material_class'], array_column(MaterialClass::cases(), 'value'));
    }

    #[Test]
    public function confidence_is_within_the_documented_range(): void
    {
        foreach (['one', 'two', 'three'] as $seed) {
            $result = $this->classifier->classify($this->tempImage($seed));

            $this->assertGreaterThanOrEqual(0.55, $result['confidence']);
            $this->assertLessThanOrEqual(0.99, $result['confidence']);
        }
    }

    #[Test]
    public function is_deterministic_for_the_same_image(): void
    {
        $path = $this->tempImage('deterministic-seed');

        $first = $this->classifier->classify($path);
        $second = $this->classifier->classify($path);

        $this->assertSame($first, $second, 'Same image must always classify identically (stable tests, retry-safe devices).');
    }

    #[Test]
    public function different_images_can_classify_differently(): void
    {
        // Not a strict guarantee by design, but with 5 classes and varied
        // hashes at least one of six images should differ — sanity check
        // that the hash actually varies the outcome.
        $results = [];
        foreach (range(1, 6) as $i) {
            $results[] = $this->classifier->classify($this->tempImage('variant-'.$i))['material_class'];
        }

        $this->assertGreaterThan(1, count(array_unique($results)));
    }

    #[Test]
    public function throws_for_missing_files(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->classifier->classify('/nonexistent/image.png');
    }

    private function tempImage(string $seed): string
    {
        $path = sys_get_temp_dir().'/stub_classifier_'.md5($seed).'.bin';
        file_put_contents($path, hash('sha256', $seed, binary: true).random_bytes(16).$seed);

        return $path;
    }
}
