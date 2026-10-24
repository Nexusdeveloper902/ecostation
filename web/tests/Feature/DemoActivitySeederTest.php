<?php

namespace Tests\Feature;

use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Models\Student;
use Database\Seeders\DemoActivitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * DemoActivitySeeder is optional demo data, never part of DatabaseSeeder.
 * It must not be required for the app to work, must be deterministic and
 * idempotent, and must keep the realtime hello readable (chronological ids).
 */
class DemoActivitySeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_demo_activity_is_optional_idempotent_and_chronological(): void
    {
        $this->seedDemo();

        $this->assertSame(0, RecyclingDeposit::count(), 'el seed normal no crea actividad');

        $this->seed(DemoActivitySeeder::class);

        $count = RecyclingDeposit::count();
        $this->assertGreaterThan(50, $count);
        $this->assertSame(5, Student::query()->whereHas('cards')->count());
        $this->assertTrue(Student::query()->whereHas('pointsLedger')->exists());

        $times = PresenceEvent::query()
            ->orderBy('id')
            ->pluck('occurred_at')
            ->map(fn ($value) => Carbon::parse((string) $value)->getTimestamp())
            ->all();

        $sorted = $times;
        sort($sorted);
        $this->assertSame($sorted, $times, 'los ids del demo siguen el orden temporal');

        $this->seed(DemoActivitySeeder::class);
        $this->assertSame($count, RecyclingDeposit::count(), 'una segunda ejecución no duplica');
    }
}
