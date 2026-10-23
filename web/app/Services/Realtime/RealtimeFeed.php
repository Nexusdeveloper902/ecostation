<?php

namespace App\Services\Realtime;

use App\Models\PresenceEvent;
use Illuminate\Support\Facades\DB;

/**
 * El canal `tap`: lee SOLO la tabla `events`, que sigue siendo la
 * fuente de verdad. Cada tap tiene `feedback: accepted` (el plan no
 * tiene `served`/`reason`: un evento escrito siempre fue aceptado) y
 * `class_id` para el filtro por curso del docente.
 */
final class RealtimeFeed
{
    /**
     * @param  array<int, int>|null  $classIds  null = todos los cursos
     * @return array<int, array<string, mixed>>
     */
    public function eventsAfter(int $eventId, int $limit = 100, ?array $classIds = null): array
    {
        return $this->rows($limit, 'asc', false, $eventId, $classIds);
    }

    /**
     * Los eventos más recientes, del más viejo al más nuevo (el `hello`).
     *
     * @param  array<int, int>|null  $classIds  null = todos los cursos
     * @return array<int, array<string, mixed>>
     */
    public function recent(int $limit = 20, ?array $classIds = null): array
    {
        return $this->rows($limit, 'desc', true, null, $classIds);
    }

    public function latestEventId(): int
    {
        try {
            return (int) (DB::table('events')->max('id') ?? 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<int, int>|null  $classIds
     * @return array<int, array<string, mixed>>
     */
    private function rows(int $limit, string $direction, bool $reverse, ?int $afterId, ?array $classIds): array
    {
        // The event row carries the class SNAPSHOT of the tap. Historical
        // rows keep meaning even after a student transfers, so both the
        // join and the filter use events.class_id, never the student's
        // current enrolment.
        $query = DB::table('events')
            ->join('cards', 'cards.id', '=', 'events.card_id')
            ->join('students', 'students.id', '=', 'cards.student_id')
            ->leftJoin('classes', 'classes.id', '=', 'events.class_id')
            ->leftJoin('readers', 'readers.id', '=', 'events.reader_id');

        if ($afterId !== null) {
            $query->where('events.id', '>', $afterId);
        }

        if ($classIds !== null) {
            $query->whereIn('events.class_id', $classIds);
        }

        $rows = $query
            ->orderBy('events.id', $direction)
            ->limit($limit)
            ->get([
                'events.id',
                'events.occurred_at',
                'students.id as student_id',
                'students.name as student_name',
                'events.class_id as class_id',
                'classes.name as class_name',
                'readers.label as reader_label',
            ])
            ->map(function ($row) {
                $occurredAt = (string) $row->occurred_at;

                return [
                    'id' => (int) $row->id,
                    'type' => PresenceEvent::TYPE,
                    'feedback' => 'accepted',
                    'student_id' => (int) $row->student_id,
                    'student_name' => (string) $row->student_name,
                    'class_id' => $row->class_id !== null ? (int) $row->class_id : null,
                    'class_name' => $row->class_name !== null ? (string) $row->class_name : null,
                    'reader_label' => $row->reader_label !== null ? (string) $row->reader_label : null,
                    'time' => substr($occurredAt, 11, 5),
                    'date' => substr($occurredAt, 0, 10),
                ];
            })
            ->all();

        return $reverse ? \array_reverse($rows) : $rows;
    }
}
