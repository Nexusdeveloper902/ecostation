<?php

namespace Tests\Feature\Api;

use App\Models\PointsLedger;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The recycling leaderboard: ranking derived from the real ledger, the
 * class board, and the student's own standing resolved from their
 * account — never from a client-supplied id.
 */
class LeaderboardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function the_leaderboard_ranks_students_from_the_real_ledger(): void
    {
        $maria = $this->studentIdOf('Maria González');
        $carlos = $this->studentIdOf('Carlos Pérez');
        $ana = $this->studentIdOf('Ana Martínez');

        $this->award($maria, 10);
        $this->award($carlos, 25);
        $this->award($ana, 10);

        $response = $this->actingAs($this->adminUser())->getJson('/api/v1/recycling/leaderboard');

        $response->assertOk()->assertJsonStructure([
            'top' => [['rank', 'student_id', 'student_name', 'class_name', 'points']],
            'classes',
        ]);
        $response->assertJsonCount(2, 'classes');

        $entries = collect($response->json('top'));

        $this->assertSame($carlos, $entries[0]['student_id']);
        $this->assertSame(1, $entries[0]['rank']);
        $this->assertSame(25, $entries[0]['points']);

        $this->assertSame(min($maria, $ana), $entries[1]['student_id']);
        $this->assertSame(2, $entries[1]['rank']);
        $this->assertSame(10, $entries[1]['points']);
        $this->assertSame(max($maria, $ana), $entries[2]['student_id']);
        $this->assertSame(2, $entries[2]['rank'], 'equal points share the rank (competition ranking)');
        $this->assertSame(10, $entries[2]['points']);
    }

    #[Test]
    public function a_student_receives_their_own_standing_from_their_account(): void
    {
        $maria = $this->studentIdOf('Maria González');
        $this->award($maria, 10);
        $this->award($this->studentIdOf('Carlos Pérez'), 25);

        $response = $this->actingAs($this->studentUser('maria'))->getJson('/api/v1/recycling/leaderboard');

        $response->assertOk();
        $this->assertSame(2, $response->json('me.rank'));
        $this->assertSame(10, $response->json('me.points'));
    }

    #[Test]
    public function me_rank_matches_the_balance_rank_under_ties(): void
    {
        $maria = $this->studentIdOf('Maria González');
        $this->award($maria, 10);
        $this->award($this->studentIdOf('Ana Martínez'), 10);
        $this->award($this->studentIdOf('Carlos Pérez'), 25);

        $user = $this->studentUser('maria');

        $boardRank = $this->actingAsFresh($user)
            ->getJson('/api/v1/recycling/leaderboard')
            ->assertOk()
            ->json('me.rank');
        $balanceRank = $this->actingAsFresh($user)
            ->getJson('/api/v1/me/balance')
            ->assertOk()
            ->json('rank');

        $this->assertSame(2, $boardRank);
        $this->assertSame($boardRank, $balanceRank, 'ambos endpoints usan el mismo rank competitivo');
    }

    #[Test]
    public function non_student_roles_get_no_me_block(): void
    {
        $response = $this->actingAs($this->teacher())->getJson('/api/v1/recycling/leaderboard');

        $response->assertOk();
        $this->assertArrayNotHasKey('me', $response->json());
    }

    #[Test]
    public function guests_and_roles_are_gated(): void
    {
        $this->getJson('/api/v1/recycling/leaderboard')->assertUnauthorized();
        $this->actingAs($this->studentUser('maria'))->getJson('/api/v1/recycling/leaderboard')->assertOk();

        // Station accounts have no leaderboard scope.
        $station = User::where('email', 'estacion@ecostation.test')->firstOrFail();
        $this->actingAs($station)->getJson('/api/v1/recycling/leaderboard')->assertForbidden();
    }

    #[Test]
    public function the_limit_parameter_is_clamped(): void
    {
        $this->award($this->studentIdOf('Maria González'), 10);

        $response = $this->actingAs($this->adminUser())->getJson('/api/v1/recycling/leaderboard?limit=999');

        $response->assertOk();
        $this->assertLessThanOrEqual(100, count($response->json('top')));
    }

    // ------------------------------------------------------------- helpers

    private function adminUser(): User
    {
        return User::where('email', 'admin@ecostation.test')->firstOrFail();
    }

    private function studentUser(string $slug): User
    {
        return User::where('email', $slug.'@ecostation.test')->firstOrFail();
    }

    private function studentIdOf(string $name): int
    {
        return Student::where('name', $name)->firstOrFail()->id;
    }

    private function award(int $studentId, int $points): void
    {
        PointsLedger::create([
            'student_id' => $studentId,
            'delta' => $points,
            'reason' => 'recycling_deposit',
        ]);
    }
}
