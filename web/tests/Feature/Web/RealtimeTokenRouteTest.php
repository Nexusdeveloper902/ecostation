<?php

namespace Tests\Feature\Web;

use App\Models\User;
use App\Services\Realtime\RealtimeToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * GET /realtime/token — el token del feed. La matriz de roles: admin,
 * docente y estación reciben 200; estudiante 403; invitado redirige.
 * Además el camino REAL del altavoz: POST /login y luego el token con la
 * misma cookie de sesión.
 */
class RealtimeTokenRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $this->get('/realtime/token')->assertRedirect('/login');
    }

    #[Test]
    public function admins_receive_a_verifiable_token_and_the_ws_url(): void
    {
        $admin = User::where('role', 'admin')->firstOrFail();
        $response = $this->actingAs($admin)->getJson('/realtime/token');

        $response->assertOk()->assertJsonStructure(['token', 'expires_at', 'url']);

        $this->assertSame($admin->id, RealtimeToken::verify($response->json('token')));
        $this->assertGreaterThan(time(), $response->json('expires_at'));
        $this->assertSame('ws://localhost:8081/app', $response->json('url'));
    }

    #[Test]
    public function teachers_receive_tokens_too(): void
    {
        $teacher = User::where('role', 'teacher')->firstOrFail();

        $response = $this->actingAs($teacher)->getJson('/realtime/token');

        $response->assertOk();
        $this->assertSame((int) $teacher->id, RealtimeToken::verify($response->json('token')));
    }

    #[Test]
    public function station_accounts_receive_tokens(): void
    {
        $station = User::where('email', 'estacion@ecostation.test')->firstOrFail();

        $response = $this->actingAs($station)->getJson('/realtime/token');

        $response->assertOk();
        $this->assertSame((int) $station->id, RealtimeToken::verify($response->json('token')));
    }

    #[Test]
    public function students_are_forbidden(): void
    {
        $student = User::where('role', 'student')->firstOrFail();

        $this->actingAs($student)->getJson('/realtime/token')->assertForbidden();
    }

    #[Test]
    public function the_speaker_logs_in_and_then_mints_a_token_with_the_same_session(): void
    {
        $this->post('/login', [
            'email' => 'estacion@ecostation.test',
            'password' => 'password',
        ])->assertRedirect(route('station'));

        $response = $this->getJson('/realtime/token');

        $response->assertOk()->assertJsonStructure(['token', 'expires_at', 'url']);
        $this->assertSame('ws://localhost:8081/app', $response->json('url'));
        $this->assertNotNull(RealtimeToken::verify($response->json('token')));
    }

    #[Test]
    public function a_flagged_speaker_is_redirected_instead_of_getting_json(): void
    {
        $station = User::where('email', 'estacion@ecostation.test')->firstOrFail();
        $station->forceFill(['must_change_password' => true])->save();

        $this->post('/login', [
            'email' => 'estacion@ecostation.test',
            'password' => 'password',
        ]);

        $response = $this->getJson('/realtime/token');

        $response->assertRedirect(route('password.change'));
        $this->assertSame(302, $response->getStatusCode());
    }

    #[Test]
    public function the_sanctum_station_route_serves_only_station_tokens(): void
    {
        $station = User::where('email', 'estacion@ecostation.test')->firstOrFail();
        $this->app['auth']->forgetGuards();

        $response = $this->withToken($station->createToken('speaker')->plainTextToken)
            ->getJson('/api/v1/realtime/token');

        $response->assertOk()->assertJsonStructure(['token', 'expires_at', 'url']);

        $this->app['auth']->forgetGuards();
        $teacherToken = User::where('role', 'teacher')->firstOrFail()->createToken('x')->plainTextToken;
        $this->withToken($teacherToken)->getJson('/api/v1/realtime/token')->assertForbidden();
    }
}
