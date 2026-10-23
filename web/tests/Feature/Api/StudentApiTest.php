<?php

namespace Tests\Feature\Api;

use App\Models\PointsLedger;
use App\Models\PresenceEvent;
use App\Models\RecyclingDeposit;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Android student API (Task 12): Sanctum token mint, rotation wall,
 * own balance/history, and the token roles. Staff and station accounts
 * can never mint a student token, and an orphaned student account sees
 * no data at all.
 */
class StudentApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function a_student_gets_a_token_and_reads_only_their_own_balance_and_history(): void
    {
        $this->classifiedDepositFor('Maria González', 'plastic', 10);

        $maria = $this->studentAccount('maria');
        $response = $this->mintToken($maria->email);

        $response->assertOk()
            ->assertJsonStructure(['token', 'must_change_password', 'student' => ['id', 'name', 'class_name']]);
        $this->assertFalse($response->json('must_change_password'));
        $this->assertSame($maria->student->id, $response->json('student.id'));
        $this->assertSame('Maria González', $response->json('student.name'));
        $this->assertSame('5° A', $response->json('student.class_name'));
        $this->assertIsString($response->json('token'));
        $this->assertNotSame('', $response->json('token'));

        $token = $response->json('token');

        $balance = $this->asStudent($token)->getJson('/api/v1/me/balance')->assertOk();
        $this->assertSame(10, $balance->json('balance'));
        $this->assertSame(1, $balance->json('rank'));
        $this->assertSame($maria->student->id, $balance->json('student.id'));

        $history = $this->asStudent($token)->getJson('/api/v1/me/history')->assertOk();
        $this->assertCount(1, $history->json('data'));
        $this->assertNull($history->json('next_page_url'));

        $item = $history->json('data.0');
        $this->assertSame(
            ['deposit_id', 'occurred_at', 'material_class', 'points_awarded', 'is_recyclable'],
            array_keys($item),
        );
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d-05:00$/', $item['occurred_at']);
        $this->assertSame('plastic', $item['material_class']);
        $this->assertSame(10, $item['points_awarded']);
        $this->assertTrue($item['is_recyclable']);

        // Another student's token never sees Maria's numbers.
        $carlos = $this->studentAccount('carlos');
        $carlosToken = $this->mintToken($carlos->email)->json('token');

        $this->asStudent($carlosToken)->getJson('/api/v1/me/balance')->assertOk()->assertJsonPath('balance', 0);
        $this->asStudent($carlosToken)->getJson('/api/v1/me/history')->assertOk()->assertJsonCount(0, 'data');
    }

    #[Test]
    public function history_page_two_keeps_the_newest_first_order_after_twenty_deposits(): void
    {
        $student = Student::where('name', 'Maria González')->firstOrFail();
        $card = $this->cardOf('Maria González');
        $depositIds = [];

        // 22 depósitos con minutos distintos: el más nuevo es i = 0.
        for ($i = 0; $i < 22; $i++) {
            $event = PresenceEvent::create([
                'card_id' => $card->id,
                'reader_id' => $this->reader()->id,
                'class_id' => $student->class_id,
                'occurred_at' => now()->subMinutes($i),
            ]);

            $depositIds[] = RecyclingDeposit::create([
                'event_id' => $event->id,
                'material_class' => 'plastic',
                'confidence' => 0.95,
                'points_awarded' => 10,
                'is_bottle' => true,
                'is_recyclable' => true,
            ])->id;
        }

        $token = $this->mintToken($this->studentAccount('maria')->email)->json('token');

        $pageOne = $this->asStudent($token)->getJson('/api/v1/me/history')->assertOk();
        $pageOne->assertJsonCount(20, 'data');
        $this->assertSame($depositIds[0], $pageOne->json('data.0.deposit_id'));
        $this->assertSame($depositIds[19], $pageOne->json('data.19.deposit_id'));
        $this->assertNotNull($pageOne->json('next_page_url'));

        $pageTwo = $this->asStudent($token)->getJson('/api/v1/me/history?page=2')->assertOk();
        $pageTwo->assertJsonCount(2, 'data');
        $this->assertSame($depositIds[20], $pageTwo->json('data.0.deposit_id'));
        $this->assertSame($depositIds[21], $pageTwo->json('data.1.deposit_id'));
        $this->assertNull($pageTwo->json('next_page_url'));
    }

    #[Test]
    public function the_token_endpoint_is_throttled_after_five_failed_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->flushHeaders();
            $this->app['auth']->forgetGuards();

            $this->postJson('/api/v1/auth/token', [
                'email' => 'maria@ecostation.test',
                'password' => 'wrong-password',
                'device_name' => 'EcoStation Android',
            ])->assertStatus(422);
        }

        $this->flushHeaders();
        $this->postJson('/api/v1/auth/token', [
            'email' => 'maria@ecostation.test',
            'password' => 'wrong-password',
            'device_name' => 'EcoStation Android',
        ])->assertStatus(429);
    }

    #[Test]
    public function history_reports_an_unknown_stored_material_as_null_instead_of_throwing(): void
    {
        $deposit = $this->classifiedDepositFor('Maria González', 'plastic', 10);

        // Simulate a legacy/corrupt row the enum cast cannot represent.
        DB::table('recycling_deposits')->where('id', $deposit->id)->update(['material_class' => 'unobtainium']);

        $token = $this->mintToken($this->studentAccount('maria')->email)->json('token');

        $this->asStudent($token)->getJson('/api/v1/me/history')
            ->assertOk()
            ->assertJsonPath('data.0.material_class', null);
    }

    #[Test]
    public function staff_and_station_accounts_cannot_get_a_token(): void
    {
        foreach (['admin@ecostation.test', 'docente@ecostation.test', 'estacion@ecostation.test'] as $email) {
            $this->mintToken($email)->assertStatus(422)->assertJsonPath('errors.email.0', __('auth.failed'));
        }

        $this->postJson('/api/v1/auth/token', [
            'email' => 'maria@ecostation.test',
            'password' => 'not-the-password',
            'device_name' => 'EcoStation Android',
        ])->assertStatus(422)->assertJsonPath('errors.email.0', __('auth.failed'));
    }

    #[Test]
    public function the_api_rejects_a_password_longer_than_the_bcrypt_limit(): void
    {
        $maria = $this->studentAccount('maria');
        $token = $maria->createToken('telefono', ['student'])->plainTextToken;
        $tooLong = str_repeat('b', 73);

        $this->asStudent($token)->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => $tooLong,
            'password_confirmation' => $tooLong,
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);

        $this->assertTrue(Hash::check('password', $maria->fresh()->password));
    }

    #[Test]
    public function an_unknown_account_still_pays_the_password_hash_check_for_timing_safety(): void
    {
        // The old short-circuit skipped Hash::check for unknown emails,
        // making account existence measurable. The guard must run anyway.
        Hash::shouldReceive('check')
            ->once()
            ->andReturn(false);

        $this->mintToken('nadie@ecostation.test')
            ->assertStatus(422)
            ->assertJsonPath('errors.email.0', __('auth.failed'));
    }

    #[Test]
    public function a_teacher_token_is_forbidden_from_me_endpoints(): void
    {
        $teacher = $this->teacher();
        $token = $teacher->createToken('x')->plainTextToken;

        $this->asStudent($token)->getJson('/api/v1/me/balance')->assertForbidden();
        $this->asStudent($token)->getJson('/api/v1/me/history')->assertForbidden();
    }

    #[Test]
    public function an_orphaned_student_account_gets_404_not_data(): void
    {
        $maria = $this->studentAccount('maria');
        $maria->student_id = null;
        $maria->save();

        $token = $maria->createToken('x', ['student'])->plainTextToken;

        $this->asStudent($token)->getJson('/api/v1/me/balance')->assertNotFound();
        $this->asStudent($token)->getJson('/api/v1/me/history')->assertNotFound();
    }

    #[Test]
    public function a_flagged_account_must_change_password_first(): void
    {
        $maria = $this->studentAccount('maria');
        $maria->must_change_password = true;
        $maria->save();

        $token = $this->mintToken($maria->email, 'password')
            ->assertOk()
            ->assertJsonPath('must_change_password', true)
            ->json('token');

        $this->asStudent($token)->getJson('/api/v1/me/balance')->assertForbidden();
        $this->asStudent($token)->getJson('/api/v1/me/history')->assertForbidden();

        // A wrong current password is a 422 and rotates nothing.
        $this->asStudent($token)->putJson('/api/v1/auth/password', [
            'current_password' => 'wrong-password',
            'password' => 'nueva-clave-123',
            'password_confirmation' => 'nueva-clave-123',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['current_password']]);

        $this->asStudent($token)->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'nueva-clave-123',
            'password_confirmation' => 'nueva-clave-123',
        ])->assertNoContent();

        $this->asStudent($token)->getJson('/api/v1/me/balance')->assertOk();
        $this->assertFalse($maria->refresh()->must_change_password);
    }

    #[Test]
    public function rotating_the_password_revokes_every_other_token(): void
    {
        $maria = $this->studentAccount('maria');
        $current = $maria->createToken('telefono-actual', ['student'])->plainTextToken;
        $other = $maria->createToken('telefono-viejo', ['student'])->plainTextToken;

        $this->asStudent($current)->getJson('/api/v1/me/balance')->assertOk();

        $this->asStudent($current)->putJson('/api/v1/auth/password', [
            'current_password' => 'password',
            'password' => 'clave-nueva-123',
            'password_confirmation' => 'clave-nueva-123',
        ])->assertNoContent();

        // The token that performed the rotation survives; the other dies.
        $this->asStudent($current)->getJson('/api/v1/me/balance')->assertOk();
        $this->asStudent($other)->getJson('/api/v1/me/balance')->assertUnauthorized();
    }

    #[Test]
    public function tokens_expire_after_the_configured_window(): void
    {
        config(['sanctum.expiration' => 60 * 24 * 30]);

        $maria = $this->studentAccount('maria');
        $token = $maria->createToken('telefono', ['student'])->plainTextToken;

        $this->asStudent($token)->getJson('/api/v1/me/balance')->assertOk();

        $this->travel(31)->days();

        $this->asStudent($token)->getJson('/api/v1/me/balance')->assertUnauthorized();
    }

    #[Test]
    public function logout_revokes_the_token(): void
    {
        $maria = $this->studentAccount('maria');
        $token = $maria->createToken('x', ['student'])->plainTextToken;

        $this->asStudent($token)->getJson('/api/v1/me/balance')->assertOk();
        $this->asStudent($token)->deleteJson('/api/v1/auth/token')->assertNoContent();

        $this->asStudent($token)->getJson('/api/v1/me/balance')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- helpers

    /** Mint a token; the test app keeps auth guards between requests. */
    private function mintToken(string $email, string $password = 'password')
    {
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/v1/auth/token', [
            'email' => $email,
            'password' => $password,
            'device_name' => 'EcoStation Android',
        ]);
    }

    /** Authenticate as the token owner, forcing a fresh guard per request. */
    private function asStudent(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function studentAccount(string $slug): User
    {
        return User::where('email', $slug.'@ecostation.test')->firstOrFail();
    }

    private function classifiedDepositFor(string $name, string $material, int $points): RecyclingDeposit
    {
        $student = Student::where('name', $name)->firstOrFail();
        $event = $this->recyclingTapFor($name);

        $deposit = RecyclingDeposit::create([
            'event_id' => $event->id,
            'material_class' => $material,
            'confidence' => 0.95,
            'points_awarded' => $points,
            'is_bottle' => $material === 'plastic',
            'is_recyclable' => $material !== 'other',
        ]);

        if ($points > 0) {
            PointsLedger::create([
                'student_id' => $student->id,
                'delta' => $points,
                'reason' => 'recycling_deposit',
                'event_id' => $event->id,
            ]);
        }

        return $deposit;
    }
}
