<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_schema_has_the_recycling_tables_and_none_of_the_pulse_baggage(): void
    {
        foreach (['users', 'classes', 'teacher_class', 'students', 'cards', 'readers', 'events', 'recycling_deposits',
            'points_ledger', 'pending_pairings', 'pending_captures', 'recycling_updates', 'tap_feedback',
            'hce_credential_keys', 'settings', 'personal_access_tokens'] as $t) {
            $this->assertTrue(Schema::hasTable($t), $t);
        }
        foreach (['schools', 'rewards', 'reward_redemptions', 'roster_updates', 'password_reset_tokens'] as $t) {
            $this->assertFalse(Schema::hasTable($t), $t);
        }
        foreach (['users', 'students', 'events', 'readers'] as $t) {
            $this->assertFalse(Schema::hasColumn($t, 'school_id'), $t);
        }
        $this->assertFalse(Schema::hasColumn('events', 'served'));
        $this->assertFalse(Schema::hasColumn('readers', 'type'));
        $this->assertEmpty(array_filter(Schema::getColumnListing('students'), fn ($c) => str_starts_with($c, 'pae')));
    }

    #[Test]
    public function the_demo_seed_links_the_teacher_to_one_class_and_every_student_to_a_card_and_account(): void
    {
        $demo = $this->seedDemo();
        $this->assertSame(['5° A'], $demo['teacher']->classes->pluck('name')->all());
        $this->assertSame(UserRole::Station, $demo['station']->role);
        $this->assertCount(5, $demo['students']);
        foreach ($demo['students'] as $s) {
            $this->assertNotNull($s->cards()->first());
            $this->assertNotNull($s->account);
        }
        $this->assertSame('RECYCLING_DEPOSIT', PresenceEvent::TYPE);
    }

    #[Test]
    public function test_helpers_resolve_the_demo_reader_card_and_tap(): void
    {
        $this->seedDemo();
        $this->assertSame(32, strlen($this->readerToken()));
        $this->assertSame('DEMO-1', $this->cardUidFor('Maria'));
        $tap = $this->recyclingTapFor('Diego');
        $this->assertSame('Diego López', $tap->student->name);
        $this->assertNotNull($tap->class_id);
    }

    #[Test]
    public function the_demo_seed_can_run_twice(): void
    {
        $this->seedDemo();
        $this->seedDemo();
        $this->assertSame(5, Student::count());
        $this->assertSame(1, Reader::count());
    }
}
