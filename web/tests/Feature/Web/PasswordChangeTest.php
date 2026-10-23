<?php

namespace Tests\Feature\Web;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The rotation wall. Flagged accounts (fresh provisioned logins) can open
 * only the password-change form and logout; every other authenticated
 * page bounces them to the form (HTML) or answers a JSON 403. Rotating
 * clears the flag and lands on the role's own page; the form stays
 * available voluntarily afterwards.
 */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function flaggedStudent(): User
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();
        $user = $student->account;
        $user->update(['must_change_password' => true]);

        return $user->fresh();
    }

    private function flaggedStation(): User
    {
        $station = User::where('email', 'estacion@ecostation.test')->firstOrFail();
        $station->update(['must_change_password' => true]);

        return $station->fresh();
    }

    #[Test]
    public function a_flagged_student_bounces_off_every_page_to_the_form(): void
    {
        $user = $this->flaggedStudent();

        // The role wall would 403 at /station for a student — the
        // rotation wall runs FIRST and redirects instead (ordering pinned).
        $this->actingAs($user)->get('/')->assertRedirect(route('password.change'));
        $this->actingAs($user)->get('/station')->assertRedirect(route('password.change'));
    }

    #[Test]
    public function a_flagged_admin_bounces_too(): void
    {
        $admin = $this->admin();
        $admin->update(['must_change_password' => true]);

        $this->actingAs($admin)->get('/')->assertRedirect(route('password.change'));
        $this->actingAs($admin)->get('/station')->assertRedirect(route('password.change'));
    }

    #[Test]
    public function json_callers_get_a_403_instead_of_a_redirect(): void
    {
        $this->actingAs($this->flaggedStudent())
            ->postJson('/api/v1/events/tap', ['credential_uid' => 'X'])
            ->assertForbidden()
            ->assertJsonFragment(['message' => __('api.password_change_required')]);
    }

    #[Test]
    public function a_flagged_pat_caller_gets_the_403_too(): void
    {
        // The wall resolves sanctum on demand — Bearer-PAT traffic from a
        // flagged account is gated, not just sessions.
        $admin = $this->admin();
        $token = $admin->createToken('audit')->plainTextToken;
        $admin->update(['must_change_password' => true]);

        $this->postJson('/api/v1/events/tap', ['credential_uid' => 'X'], [
            'Authorization' => "Bearer {$token}",
        ])
            ->assertForbidden()
            ->assertJsonFragment(['message' => __('api.password_change_required')]);
    }

    #[Test]
    public function logout_stays_reachable_while_flagged(): void
    {
        $this->actingAs($this->flaggedStudent())
            ->post('/logout')
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    #[Test]
    public function unknown_paths_keep_their_404_while_flagged(): void
    {
        $this->actingAs($this->flaggedStudent())
            ->get('/no-such-page-anywhere')
            ->assertNotFound();
    }

    #[Test]
    public function the_form_renders_in_spanish(): void
    {
        $this->actingAs($this->flaggedStudent())
            ->get(route('password.change'))
            ->assertOk()
            ->assertSee('Define una nueva contraseña', false);
    }

    #[Test]
    public function rotation_needs_the_current_password_and_a_confirmed_replacement(): void
    {
        $user = $this->flaggedStudent();

        $this->actingAs($user)
            ->from(route('password.change'))
            ->put(route('password.update'), [
                'current_password' => 'not-the-password',
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'a-brand-new-secret',
            ])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($user)
            ->from(route('password.change'))
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'short',
                'password_confirmation' => 'short',
            ])
            ->assertSessionHasErrors('password');

        $this->actingAs($user)
            ->from(route('password.change'))
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'something-else',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    #[Test]
    public function rotating_to_the_current_or_initial_password_is_refused(): void
    {
        $user = $this->flaggedStudent();

        $this->actingAs($user)
            ->from(route('password.change'))
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);

        // Same refusal when the current password is already personal but
        // the replacement is the documented initial value.
        $user->update(['password' => 'a-personal-secret-1', 'must_change_password' => true]);

        $this->actingAs($user->fresh())
            ->from(route('password.change'))
            ->put(route('password.update'), [
                'current_password' => 'a-personal-secret-1',
                'password' => 'password',
                'password_confirmation' => 'password',
            ])
            ->assertSessionHasErrors('password');

        $this->assertTrue($user->fresh()->must_change_password);
    }

    #[Test]
    public function a_successful_rotation_clears_the_flag_and_lands_home(): void
    {
        $user = $this->flaggedStation();

        $this->actingAs($user)
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'a-brand-new-secret',
            ])
            ->assertRedirect(route('station'));

        $fresh = $user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertTrue(Hash::check('a-brand-new-secret', $fresh->password));

        // The wall is gone: the station page renders now.
        $this->actingAs($fresh)->get('/station')->assertOk();
    }

    #[Test]
    public function the_old_password_dies_with_the_rotation(): void
    {
        $user = $this->flaggedStation();

        $this->actingAs($user)
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'a-brand-new-secret',
                'password_confirmation' => 'a-brand-new-secret',
            ])
            ->assertRedirect(route('station'));

        $this->post('/logout');

        // Fresh guest flow (no actingAs before these — stickiness).
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');

        $this->post('/login', ['email' => $user->email, 'password' => 'a-brand-new-secret'])
            ->assertRedirect(route('station'));
    }

    #[Test]
    public function an_unflagged_user_rotates_voluntarily(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('password.change'))->assertOk();

        $this->actingAs($admin)
            ->put(route('password.update'), [
                'current_password' => 'password',
                'password' => 'an-admin-secret-1',
                'password_confirmation' => 'an-admin-secret-1',
            ])
            ->assertRedirect();

        $this->assertFalse($admin->fresh()->must_change_password);
        $this->assertTrue(Hash::check('an-admin-secret-1', $admin->fresh()->password));
    }

    #[Test]
    public function guests_never_reach_the_form(): void
    {
        $this->get(route('password.change'))->assertRedirect(route('login'));
    }
}
