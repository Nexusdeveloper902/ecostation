<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRole;
use App\Models\Reader;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Privileged columns never ride mass assignment. Payload fields are
 * untrusted even on admin forms; server-side generation/assignment wins.
 */
class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function filling_a_user_payload_cannot_change_role_student_or_flag(): void
    {
        $user = User::forceCreate([
            'name' => 'Docente',
            'email' => 'docente.seguro@ecostation.test',
            'password' => 'password',
            'role' => 'teacher',
            'student_id' => null,
            'must_change_password' => false,
        ]);

        $user->fill([
            'name' => 'Docente Renombrado',
            'role' => 'admin',
            'student_id' => 999,
            'must_change_password' => true,
        ]);
        $user->save();

        $fresh = $user->fresh();
        $this->assertSame('teacher', $fresh->role->value);
        $this->assertNull($fresh->student_id);
        $this->assertFalse($fresh->must_change_password);
        $this->assertSame('Docente Renombrado', $fresh->name);
    }

    #[Test]
    public function a_reader_api_key_cannot_be_mass_assigned(): void
    {
        $reader = Reader::forceCreate([
            'label' => 'Estación segura',
            'api_key' => 'server-generated-key-0123456789abcdef',
        ]);

        $reader->fill(['api_key' => 'attacker-key'])->save();

        $this->assertSame(
            'server-generated-key-0123456789abcdef',
            $reader->fresh()->makeVisible('api_key')->api_key,
        );
    }

    #[Test]
    public function the_private_local_disk_cannot_serve_signed_urls(): void
    {
        // Only DepositPhotoController may open a deposit photo; Laravel's
        // framework-signed /storage route would bypass StudentScope.
        $this->assertFalse((bool) config('filesystems.disks.local.serve'));
    }

    #[Test]
    public function ensure_role_denies_guests_with_401(): void
    {
        $middleware = new EnsureRole;
        $request = Request::create('/protected', 'GET');

        try {
            $middleware->handle($request, fn () => response('ok'), 'admin');
            $this->fail('Un invitado no debe pasar el muro de rol.');
        } catch (HttpException $e) {
            $this->assertSame(401, $e->getStatusCode());
        }
    }
}
