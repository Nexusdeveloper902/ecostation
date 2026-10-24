<?php

namespace Database\Seeders;

use App\Enums\MaterialClass;
use App\Models\PresenceEvent;
use App\Models\Reader;
use App\Models\RecyclingDeposit;
use App\Models\RecyclingUpdate;
use App\Models\Student;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Optional demo activity: deposits, points and realtime history for the
 * demo students created by DemoSeeder. It is NOT called by DatabaseSeeder,
 * so tests and a real deployment stay untouched. Run it explicitly:
 *
 *     php artisan db:seed --class=DemoActivitySeeder
 *
 * The data is deterministic (fixed seed) and idempotent: a second run adds
 * nothing. Rows are inserted in chronological order so event ids and the
 * realtime `hello` history read naturally. No photos are created; deposit
 * rows have image_path = null.
 */
class DemoActivitySeeder extends Seeder
{
    private const MARKER = 'demo_activity';

    /** Material mix used for the demo: plastic-heavy, a few "other". */
    private const WEIGHTS = [
        'plastic' => 40,
        'paper' => 25,
        'glass' => 15,
        'metal' => 15,
        'other' => 5,
    ];

    public function run(): void
    {
        $reader = Reader::query()->orderBy('id')->first();

        if ($reader === null) {
            $this->command?->warn('DemoActivitySeeder: ejecuta primero DemoSeeder (no hay estaciones).');

            return;
        }

        $already = RecyclingDeposit::query()
            ->whereHas('event', fn ($q) => $q->where('metadata->'.self::MARKER, true))
            ->count();

        if ($already > 0) {
            $this->command?->info('DemoActivitySeeder: la actividad de demostración ya existe.');

            return;
        }

        mt_srand(20261023);

        $students = Student::query()->with('cards')->orderBy('id')->get();

        if ($students->isEmpty()) {
            $this->command?->warn('DemoActivitySeeder: no hay estudiantes demo.');

            return;
        }

        $now = Carbon::now(config('app.timezone'));
        $rows = [];

        foreach ($students as $index => $student) {
            $card = $student->cards->first();

            if ($card === null) {
                continue;
            }

            // Between 11 and 16 deposits over the last eight weeks, plus two
            // today so the "Hoy" panels and the live tables have data.
            $count = 11 + ($index % 6);

            for ($i = 0; $i < $count; $i++) {
                $daysAgo = $i < 2 ? 0 : mt_rand(1, 55);
                $at = $now->copy()
                    ->subDays($daysAgo)
                    ->setTime(mt_rand(7, 15), mt_rand(0, 59), mt_rand(0, 59));

                $rows[] = [
                    'student' => $student,
                    'card' => $card,
                    'at' => $at,
                    'material' => $this->pickMaterial(),
                ];
            }
        }

        // Chronological insert order: the last 20 rows of the demo are a mix
        // of students and the realtime history reads newest-first.
        usort($rows, fn (array $a, array $b): int => $a['at'] <=> $b['at']);

        DB::transaction(function () use ($rows, $reader): void {
            $balances = [];

            foreach ($rows as $row) {
                /** @var Student $student */
                $student = $row['student'];
                /** @var MaterialClass $material */
                $material = $row['material'];
                $at = $row['at'];
                $points = $material->points();

                $event = PresenceEvent::create([
                    'card_id' => $row['card']->id,
                    'reader_id' => $reader->id,
                    'class_id' => $student->class_id,
                    'occurred_at' => $at,
                    'metadata' => [self::MARKER => true],
                ]);

                RecyclingDeposit::create([
                    'event_id' => $event->id,
                    'image_path' => null,
                    'material_class' => $material,
                    'confidence' => round(0.72 + mt_rand(0, 260) / 1000, 3),
                    'points_awarded' => $points,
                    'is_bottle' => in_array($material, [MaterialClass::Plastic, MaterialClass::Glass], true),
                    'is_recyclable' => $material !== MaterialClass::Other,
                ]);

                $balances[$student->id] = ($balances[$student->id] ?? 0) + $points;

                if ($points > 0) {
                    DB::table('points_ledger')->insert([
                        'student_id' => $student->id,
                        'delta' => $points,
                        'reason' => 'recycling_deposit',
                        'event_id' => $event->id,
                        'created_at' => $at,
                        'updated_at' => $at,
                    ]);
                }

                RecyclingUpdate::create([
                    'type' => RecyclingUpdate::TYPE_POINTS_AWARDED,
                    'payload' => [
                        'event_id' => $event->id,
                        'student_id' => $student->id,
                        'student_name' => $student->name,
                        'material_class' => $material->value,
                        'points' => $points,
                        'new_balance' => $balances[$student->id],
                        'class_id' => $student->class_id,
                        'demo' => true,
                    ],
                ]);
            }
        });

        $this->command?->info('DemoActivitySeeder: actividad demo creada para '.$students->count().' estudiantes.');
    }

    private function pickMaterial(): MaterialClass
    {
        $roll = mt_rand(1, array_sum(self::WEIGHTS));

        foreach (self::WEIGHTS as $value => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return MaterialClass::from($value);
            }
        }

        return MaterialClass::Other;
    }
}
