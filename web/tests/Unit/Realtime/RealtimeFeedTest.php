<?php

namespace Tests\Unit\Realtime;

use App\Models\PresenceEvent;
use App\Services\Realtime\RealtimeFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Forma de las filas del canal `tap`: lo que el panel y el altavoz
 * reciben. Sin `served`, `reason` ni `school_id`; `feedback` siempre es
 * `accepted`; `type` es el valor congelado `RECYCLING_DEPOSIT`.
 */
class RealtimeFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    #[Test]
    public function recent_events_carry_the_readable_context_oldest_first(): void
    {
        $event = PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader()->id,
            'class_id' => $this->cardOf('Maria González')->student->class_id,
            'occurred_at' => now()->setTime(7, 50),
        ]);

        $rows = (new RealtimeFeed)->recent(20);

        $this->assertNotEmpty($rows);
        $ids = array_column($rows, 'id');
        $sorted = $ids;
        sort($sorted);
        $this->assertSame($sorted, $ids);

        $row = $rows[array_key_last($rows)];
        $this->assertSame($event->id, $row['id']);
        $this->assertSame('RECYCLING_DEPOSIT', $row['type']);
        $this->assertSame('accepted', $row['feedback']);
        $this->assertSame('07:50', $row['time']);
        $this->assertSame(now()->toDateString(), $row['date']);
        $this->assertSame('Maria González', $row['student_name']);
        $this->assertSame('Estación Demo', $row['reader_label']);
        $this->assertSame('5° A', $row['class_name']);
        $this->assertIsInt($row['student_id']);

        // The frozen wire shape: no Pulse meal/school baggage.
        $this->assertArrayNotHasKey('served', $row);
        $this->assertArrayNotHasKey('reason', $row);
        $this->assertArrayNotHasKey('school_id', $row);
    }

    #[Test]
    public function events_after_return_only_newer_rows_oldest_first(): void
    {
        $event = PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader()->id,
            'class_id' => $this->cardOf('Maria González')->student->class_id,
            'occurred_at' => now()->setTime(7, 50),
        ]);

        $feed = new RealtimeFeed;

        $this->assertSame([], $feed->eventsAfter($event->id));

        PresenceEvent::create([
            'card_id' => $this->cardOf('Carlos Pérez')->id,
            'reader_id' => $this->reader()->id,
            'class_id' => $this->cardOf('Carlos Pérez')->student->class_id,
            'occurred_at' => now()->setTime(8, 5),
        ]);

        $delta = $feed->eventsAfter($event->id);
        $this->assertCount(1, $delta);
        $this->assertSame('RECYCLING_DEPOSIT', $delta[0]['type']);
        $this->assertSame('Carlos Pérez', $delta[0]['student_name']);
    }

    #[Test]
    public function the_class_filter_narrows_the_rows_for_a_teacher(): void
    {
        $maria = $this->cardOf('Maria González');
        $diego = $this->cardOf('Diego López');

        PresenceEvent::create([
            'card_id' => $maria->id,
            'reader_id' => $this->reader()->id,
            'class_id' => $maria->student->class_id,
            'occurred_at' => now(),
        ]);
        PresenceEvent::create([
            'card_id' => $diego->id,
            'reader_id' => $this->reader()->id,
            'class_id' => $diego->student->class_id,
            'occurred_at' => now(),
        ]);

        $rows = (new RealtimeFeed)->recent(20, [$maria->student->class_id]);

        $this->assertSame(['Maria González'], array_column($rows, 'student_name'));
    }

    #[Test]
    public function the_class_snapshot_is_the_events_class_not_the_students_current_one(): void
    {
        $maria = $this->cardOf('Maria González');
        $classA = (int) $maria->student->class_id;
        $classB = (int) $this->cardOf('Diego López')->student->class_id;

        $event = PresenceEvent::create([
            'card_id' => $maria->id,
            'reader_id' => $this->reader()->id,
            'class_id' => $classA,
            'occurred_at' => now(),
        ]);

        // The student moves to 5° B AFTER the historical tap.
        $maria->student->update(['class_id' => $classB]);

        $rows = (new RealtimeFeed)->recent(20);

        $this->assertSame($classA, $rows[0]['class_id'], 'la fila conserva el curso del evento');
        $this->assertSame('5° A', $rows[0]['class_name']);

        $this->assertSame([], (new RealtimeFeed)->recent(20, [$classB]));
        $this->assertSame([$event->id], array_column((new RealtimeFeed)->recent(20, [$classA]), 'id'));
    }

    #[Test]
    public function latest_event_id_tracks_the_head(): void
    {
        $feed = new RealtimeFeed;
        $before = $feed->latestEventId();

        $event = PresenceEvent::create([
            'card_id' => $this->cardOf('Maria González')->id,
            'reader_id' => $this->reader()->id,
            'class_id' => $this->cardOf('Maria González')->student->class_id,
            'occurred_at' => now(),
        ]);

        $this->assertSame($event->id, $feed->latestEventId());
        $this->assertGreaterThanOrEqual($before, $event->id);
    }
}
