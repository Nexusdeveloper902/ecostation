<?php

namespace Tests\Feature\Web;

use App\Enums\MaterialClass;
use App\Models\PointsLedger;
use App\Models\RecyclingDeposit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The student self-service pages. The student row always comes from the
 * authenticated account, never from a URL parameter, and an orphaned
 * account answers 404 instead of leaking another student's data.
 */
class StudentPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function accountOf(string $name): User
    {
        return $this->cardOf($name)->student->account;
    }

    private function depositFor(string $name, MaterialClass $material = MaterialClass::Plastic, int $points = 10): RecyclingDeposit
    {
        $event = $this->recyclingTapFor($name);

        PointsLedger::create([
            'student_id' => $event->card->student_id,
            'delta' => $points,
            'reason' => 'recycling_deposit',
            'event_id' => $event->id,
        ]);

        return RecyclingDeposit::create([
            'event_id' => $event->id,
            'material_class' => $material->value,
            'confidence' => 0.9,
            'points_awarded' => $points,
            'is_bottle' => $material === MaterialClass::Plastic,
            'is_recyclable' => $material !== MaterialClass::Other,
        ]);
    }

    #[Test]
    public function a_student_sees_their_own_balance_rank_and_recent_deposits(): void
    {
        $this->depositFor('Maria González');

        $this->actingAs($this->accountOf('Maria González'))
            ->get('/student')
            ->assertOk()
            ->assertViewHas('balance', 10)
            ->assertViewHas('rank', 1)
            ->assertViewHas('recent', fn ($recent) => $recent->count() === 1)
            ->assertSee('plastic');
    }

    #[Test]
    public function the_history_page_lists_only_the_signed_in_students_deposits(): void
    {
        $this->depositFor('Maria González');

        $this->actingAs($this->accountOf('Maria González'))
            ->get('/student/history')
            ->assertOk()
            ->assertViewHas('deposits', fn ($deposits) => $deposits->total() === 1)
            ->assertSee('plastic');

        $this->actingAsFresh($this->accountOf('Carlos Pérez'))
            ->get('/student/history')
            ->assertOk()
            ->assertViewHas('deposits', fn ($deposits) => $deposits->total() === 0)
            ->assertDontSee('plastic');
    }

    #[Test]
    public function the_leaderboard_shows_both_classes_and_my_standing(): void
    {
        $this->actingAs($this->accountOf('Maria González'))
            ->get('/student/leaderboard')
            ->assertOk()
            ->assertViewHas('classes', fn ($classes) => count($classes) === 2)
            ->assertViewHas('top', fn ($top) => count($top) === 5)
            ->assertViewHas('me', fn ($me) => $me['points'] === 0);
    }

    #[Test]
    public function the_dashboard_exposes_the_year_month_and_material_series(): void
    {
        $this->depositFor('Maria González');

        $this->actingAs($this->accountOf('Maria González'))
            ->get('/student')
            ->assertOk()
            ->assertViewHas('yearItems', 1)
            ->assertViewHas('monthItems', 1)
            ->assertViewHas('weekPoints', 10)
            ->assertViewHas('byMaterial', fn (array $mix) => $mix['plastic']['items'] === 1
                && $mix['plastic']['points'] === 10
                && $mix['metal']['items'] === 0);
    }

    #[Test]
    public function the_history_filters_by_month_and_material_without_leaking_other_rows(): void
    {
        $this->depositFor('Maria González', MaterialClass::Plastic, 10);

        $this->actingAs($this->accountOf('Maria González'))
            ->get('/student/history?material=azul')
            ->assertOk()
            ->assertViewHas('deposits', fn ($deposits) => $deposits->total() === 1)
            ->assertViewHas('selectedMaterial', 'plastic');

        $this->actingAs($this->accountOf('Maria González'))
            ->get('/student/history?material=coral')
            ->assertOk()
            ->assertViewHas('deposits', fn ($deposits) => $deposits->total() === 0)
            ->assertDontSee('plastic');
    }

    #[Test]
    public function a_teacher_gets_403_on_every_student_page(): void
    {
        $teacher = $this->teacher();

        foreach (['/student', '/student/history', '/student/leaderboard'] as $path) {
            $this->actingAs($teacher)->get($path)->assertForbidden();
        }
    }

    #[Test]
    public function a_guest_is_redirected_to_login(): void
    {
        foreach (['/student', '/student/history', '/student/leaderboard'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    #[Test]
    public function an_orphaned_student_account_gets_404_not_data(): void
    {
        $account = $this->accountOf('Maria González');
        $account->forceFill(['student_id' => null])->save();

        foreach (['/student', '/student/history', '/student/leaderboard'] as $path) {
            $this->actingAs($account)->get($path)->assertNotFound();
        }
    }
}
