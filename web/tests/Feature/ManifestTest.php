<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El manifiesto público que sondea la app Android como respaldo cuando
 * falla el descubrimiento mDNS: 200, JSON y `"start_url"` en el cuerpo.
 *
 * No está exento del muro de rotación de contraseña: el sondeo de
 * subred no lleva sesión (por eso funciona como invitado), pero una
 * cuenta marcada que abra la URL desde un navegador se redirige al
 * formulario como cualquier otra página web.
 */
class ManifestTest extends TestCase
{
    use RefreshDatabase;

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
    public function an_unauthenticated_probe_never_hits_the_password_wall(): void
    {
        $this->assertGuest();
        $this->get('/manifest.webmanifest')->assertOk();
    }

    #[Test]
    public function a_flagged_user_is_redirected_because_the_manifest_is_not_exempt(): void
    {
        $this->seedDemo();

        $user = User::where('email', 'maria@ecostation.test')->firstOrFail();
        $user->forceFill(['must_change_password' => true])->save();

        $this->actingAs($user->fresh())
            ->get('/manifest.webmanifest')
            ->assertRedirect(route('password.change'));
    }
}
