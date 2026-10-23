<?php

namespace Tests\Unit;

use App\Enums\MaterialClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function defaults_are_the_spec_point_table_and_thirty_day_retention(): void
    {
        $this->assertSame(10, MaterialClass::Plastic->points());
        $this->assertSame(15, MaterialClass::Metal->points());
        $this->assertSame(8, MaterialClass::Glass->points());
        $this->assertSame(5, MaterialClass::Paper->points());
        $this->assertSame(0, MaterialClass::Other->points());
        $this->assertSame(30, settings()->photoRetentionDays());
    }

    #[Test]
    public function an_admin_override_is_live_for_the_next_read(): void
    {
        settings()->setMany(['points.plastic' => '20', 'photos.retention_days' => 7]);
        $this->assertSame(20, MaterialClass::Plastic->points());
        $this->assertSame(7, settings()->photoRetentionDays());
    }

    #[Test]
    public function invalid_values_and_unknown_keys_are_rejected(): void
    {
        foreach ([['points.metal' => -1], ['points.metal' => 'abc'], ['photos.retention_days' => 0], ['pae.lunch_start' => '11:00']] as $bad) {
            try {
                settings()->setMany($bad);
                $this->fail(json_encode($bad));
            } catch (ValidationException) {
            }
        }
        $this->assertSame(15, MaterialClass::Metal->points());
    }
}
