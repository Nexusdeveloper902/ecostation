<?php

namespace Tests\Feature\Console;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El feed en vivo no arranca sin una APP_KEY utilizable: una clave
 * vacía haría la firma del token reproducible por cualquiera.
 */
class RealtimeServeCommandTest extends TestCase
{
    #[Test]
    public function an_empty_app_key_refuses_to_start_with_a_spanish_error(): void
    {
        config(['app.key' => '']);

        $this->artisan('realtime:serve', ['--check' => true])
            ->expectsOutputToContain('APP_KEY')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_short_app_key_refuses_to_start(): void
    {
        config(['app.key' => 'short-key']);

        $this->artisan('realtime:serve', ['--check' => true])
            ->expectsOutputToContain('demasiado corta')
            ->assertExitCode(1);
    }

    #[Test]
    public function a_usable_app_key_passes_the_preflight(): void
    {
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $this->artisan('realtime:serve', ['--check' => true])
            ->expectsOutputToContain('APP_KEY utilizable')
            ->assertExitCode(0);
    }
}
