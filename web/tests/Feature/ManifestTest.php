<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El manifiesto público que sondea la app Android como respaldo cuando
 * falla el descubrimiento mDNS: 200, JSON y `"start_url"` en el cuerpo.
 */
class ManifestTest extends TestCase
{
    #[Test]
    public function the_manifest_is_public_and_carries_start_url_for_the_apps_subnet_probe(): void
    {
        $response = $this->get('/manifest.webmanifest');

        $response->assertOk()
            ->assertJsonPath('start_url', '/')
            ->assertJsonPath('name', 'EcoStation')
            ->assertJsonPath('short_name', 'EcoStation')
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('lang', 'es')
            ->assertSee('"start_url"', false);

        $this->assertStringContainsString(
            'application/manifest+json',
            (string) $response->headers->get('Content-Type'),
        );
    }

    #[Test]
    public function a_flagged_user_can_still_read_the_manifest(): void
    {
        // El sondeo de subred no lleva sesión ni token: el manifiesto
        // nunca puede quedar detrás del muro de contraseña.
        $this->get('/manifest.webmanifest')->assertOk();
    }
}
