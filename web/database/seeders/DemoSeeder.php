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

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $a = SchoolClass::firstOrCreate(['name' => '5° A']);
        $b = SchoolClass::firstOrCreate(['name' => '5° B']);

        User::firstOrCreate(['email' => 'admin@ecostation.test'], ['name' => 'Admin', 'password' => 'password', 'role' => UserRole::Admin]);
        User::firstOrCreate(['email' => 'docente@ecostation.test'], ['name' => 'Docente', 'password' => 'password', 'role' => UserRole::Teacher])
            ->classes()->syncWithoutDetaching([$a->id]);
        User::firstOrCreate(['email' => 'estacion@ecostation.test'], ['name' => 'Estación', 'password' => 'password', 'role' => UserRole::Station]);

        $n = 0;
        foreach ([
            [$a, ['Maria González', 'Carlos Pérez', 'Ana Martínez']],
            [$b, ['Diego López', 'Lucía Fernández']],
        ] as [$class, $names]) {
            foreach ($names as $name) {
                $student = Student::firstOrCreate(['name' => $name, 'class_id' => $class->id]);
                Card::firstOrCreate(['credential_uid' => 'DEMO-'.(++$n)], ['student_id' => $student->id]);
                User::firstOrCreate(
                    ['email' => Str::lower(Str::ascii($student->firstName())).'@ecostation.test'],
                    ['name' => $name, 'password' => 'password', 'role' => UserRole::Student, 'student_id' => $student->id],
                );
            }
        }

        $reader = Reader::firstOrCreate(['label' => 'Estación Demo'], ['api_key' => Str::random(32)]);
        if ($reader->wasRecentlyCreated) {
            $this->command?->info("Reader API key (shown once): {$reader->api_key}");
        }
    }
}
