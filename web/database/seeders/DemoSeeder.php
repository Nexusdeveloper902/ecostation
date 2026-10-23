<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Card;
use App\Models\Reader;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Semilla de demostración. En producción se niega a correr salvo permiso
 * explícito (ECOSTATION_DEMO=1); si corre allí, las cuentas humanas
 * nacen con must_change_password=true. La estación queda exenta porque
 * el altavoz no puede rotar desde la web.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $production = app()->environment('production');

        if ($production && ! $this->demoAllowed()) {
            $this->command?->warn(
                'DemoSeeder no se ejecutó: estás en producción y las credenciales de demostración '
                .'compartidas no son seguras. Define ECOSTATION_DEMO=1 solo si es una instalación '
                .'de demostración controlada.'
            );

            return;
        }

        $mustChange = $production;

        $a = SchoolClass::firstOrCreate(['name' => '5° A']);
        $b = SchoolClass::firstOrCreate(['name' => '5° B']);

        $admin = $this->demoUser('admin@ecostation.test', 'Admin', UserRole::Admin, null, $mustChange);
        $teacher = $this->demoUser('docente@ecostation.test', 'Docente', UserRole::Teacher, null, $mustChange);
        $teacher->classes()->syncWithoutDetaching([$a->id]);

        $this->demoUser('estacion@ecostation.test', 'Estación', UserRole::Station, null, false);

        $n = 0;
        foreach ([
            [$a, ['Maria González', 'Carlos Pérez', 'Ana Martínez']],
            [$b, ['Diego López', 'Lucía Fernández']],
        ] as [$class, $names]) {
            foreach ($names as $name) {
                $student = Student::firstOrCreate(['name' => $name, 'class_id' => $class->id]);
                Card::firstOrCreate(['credential_uid' => 'DEMO-'.(++$n)], ['student_id' => $student->id]);
                $this->demoUser(
                    Str::lower(Str::ascii($student->firstName())).'@ecostation.test',
                    $name,
                    UserRole::Student,
                    $student->id,
                    $mustChange,
                );
            }
        }

        $reader = Reader::where('label', 'Estación Demo')->first();

        if ($reader === null) {
            $reader = Reader::forceCreate([
                'label' => 'Estación Demo',
                'api_key' => Str::random(32),
            ]);
            $this->command?->info("Reader API key (shown once): {$reader->api_key}");
        }
    }

    /**
     * Idempotent demo account: the privileged attributes (role,
     * student_id, flag) never ride mass assignment.
     */
    private function demoUser(string $email, string $name, UserRole $role, ?int $studentId, bool $mustChange): User
    {
        $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'password']);

        if ($user->wasRecentlyCreated) {
            $user->forceFill([
                'role' => $role,
                'student_id' => $studentId,
                'must_change_password' => $mustChange,
            ])->save();
        }

        return $user;
    }

    private function demoAllowed(): bool
    {
        return config('ecostation.demo_seed') === true
            || env('ECOSTATION_DEMO') === '1'
            || getenv('ECOSTATION_DEMO') === '1';
    }
}
