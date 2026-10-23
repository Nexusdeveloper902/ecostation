<?php

namespace Tests\Feature\Web;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function user(string $role): User
    {
        return User::where('role', $role)->firstOrFail();
    }

    #[Test]
    public function the_landing_page_redirects_guests_to_login(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    #[Test]
    public function a_station_user_lands_on_the_station_page(): void
    {
        $this->actingAs($this->user('station'))
            ->get('/')
            ->assertRedirect(route('station'));

        $this->actingAs($this->user('station'))
            ->get('/station')
            ->assertOk()
            ->assertSee('aplicación del altavoz', false);
    }

    #[Test]
    public function guests_cannot_reach_the_station_page(): void
    {
        $this->get('/station')->assertRedirect(route('login'));
    }

    #[Test]
    public function login_succeeds_with_correct_credentials(): void
    {
        $station = $this->user('station');

        $response = $this->post('/login', [
            'email' => $station->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('station'));
        $this->assertAuthenticatedAs($station);
    }

    #[Test]
    public function login_fails_with_wrong_credentials(): void
    {
        $response = $this->from(route('login'))->post('/login', [
            'email' => 'estacion@ecostation.test',
            'password' => 'wrong-password',
        ]);

        $response->assertRedirect(route('login'));
        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    #[Test]
    public function the_login_is_throttled_to_five_attempts_per_minute_per_email_and_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->from(route('login'))->post('/login', [
                'email' => 'estacion@ecostation.test',
                'password' => 'wrong-password',
            ])->assertRedirect(route('login'));
        }

        $this->post('/login', [
            'email' => 'estacion@ecostation.test',
            'password' => 'wrong-password',
        ])->assertStatus(429);
    }

    #[Test]
    public function a_teacher_cannot_open_the_station_page(): void
    {
        $this->actingAs($this->user('teacher'))
            ->get('/station')
            ->assertForbidden();
    }

    #[Test]
    public function logout_ends_the_session(): void
    {
        $this->actingAs($this->user('teacher'));

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    #[Test]
    public function a_logged_in_user_is_redirected_away_from_the_login_page(): void
    {
        $this->actingAs($this->user('teacher'))
            ->get('/login')
            ->assertRedirect(route('home'));
    }

    #[Test]
    public function the_login_form_keeps_the_csrf_field_the_speaker_app_scrapes(): void
    {
        $this->get('/login')->assertOk()->assertSee('name="_token"', false);
    }

    #[Test]
    public function the_landing_page_lands_each_role_on_its_own_first_page(): void
    {
        $targets = [
            'admin' => route('admin.students'),
            'teacher' => route('teacher.dashboard'),
            'student' => route('student.dashboard'),
            'station' => route('station'),
        ];

        foreach ($targets as $role => $target) {
            $this->actingAs($this->user($role))->get('/')->assertRedirect($target);
        }
    }
}
