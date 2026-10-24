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

    #[Test]
    public function the_runtime_is_patched_for_php2shell(): void
    {
        // CVE-2026-109618 / CVE-2026-109619 (PHP2Shell): unauthenticated RCE
        // in every PHP runtime through 8.5.11. Never run below 8.5.12.
        $this->assertGreaterThanOrEqual(
            80512,
            PHP_VERSION_ID,
            'PHP 8.5.12+ es obligatorio (PHP2Shell, CVE-2026-109618/109619).',
        );
    }
}
