<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BootTest extends TestCase
{
    #[Test]
    public function the_app_runs_in_spanish_on_bogota_time_with_sqlite(): void
    {
        $this->assertSame('es', app()->getLocale());
        $this->assertSame('America/Bogota', config('app.timezone'));
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('EcoStation', config('app.name'));
    }
}
