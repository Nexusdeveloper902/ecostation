<?php

namespace Tests;

use App\Models\Card;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\Student;
use App\Models\User;
use App\Services\SettingsService;
use Database\Seeders\DemoSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        SettingsService::flushCache();
    }

    /** @return array{admin: User, teacher: User, station: User, students: Collection} */
    protected function seedDemo(): array
    {
        $this->seed(DemoSeeder::class);

        return [
            'admin' => $this->admin(),
            'teacher' => $this->teacher(),
            'station' => User::where('email', 'estacion@ecostation.test')->firstOrFail(),
            'students' => Student::orderBy('id')->get(),
        ];
    }

    /**
     * Start a clean browser session for another user. AuthenticateSession
     * pins the password hash of the session owner, so switching users in
     * one test must not reuse the previous session.
     */
    protected function actingAsFresh(User $user): static
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user);

        return $this;
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@ecostation.test')->firstOrFail();
    }

    protected function teacher(): User
    {
        return User::where('email', 'docente@ecostation.test')->firstOrFail();
    }

    protected function reader(): Reader
    {
        return Reader::firstOrFail();
    }

    /** The key is the reader identity (stored as-is, hidden from serialization). */
    protected function readerToken(): string
    {
        return $this->reader()->makeVisible('api_key')->api_key;
    }

    protected function cardOf(string $name): Card
    {
        return Student::where('name', 'like', $name.'%')->firstOrFail()->cards()->firstOrFail();
    }

    protected function cardUidFor(string $name): string
    {
        return $this->cardOf($name)->credential_uid;
    }

    protected function recyclingTapFor(string $name): PresenceEvent
    {
        $card = $this->cardOf($name);

        return PresenceEvent::create([
            'card_id' => $card->id,
            'reader_id' => $this->reader()->id,
            'class_id' => $card->student->class_id,
            'occurred_at' => now(),
        ]);
    }
}
