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
        $a = SchoolClass::create(['name' => '5° A']);
        $b = SchoolClass::create(['name' => '5° B']);

        User::create(['name' => 'Admin', 'email' => 'admin@ecostation.test', 'password' => 'password', 'role' => UserRole::Admin]);
        User::create(['name' => 'Docente', 'email' => 'docente@ecostation.test', 'password' => 'password', 'role' => UserRole::Teacher])
            ->classes()->attach($a->id);
        User::create(['name' => 'Estación', 'email' => 'estacion@ecostation.test', 'password' => 'password', 'role' => UserRole::Station]);

        $n = 0;
        foreach ([
            [$a, ['Maria González', 'Carlos Pérez', 'Ana Martínez']],
            [$b, ['Diego López', 'Lucía Fernández']],
        ] as [$class, $names]) {
            foreach ($names as $name) {
                $student = Student::create(['name' => $name, 'class_id' => $class->id]);
                Card::create(['credential_uid' => 'DEMO-'.(++$n), 'student_id' => $student->id]);
                User::create([
                    'name' => $name,
                    'email' => Str::lower(Str::ascii($student->firstName())).'@ecostation.test',
                    'password' => 'password',
                    'role' => UserRole::Student,
                    'student_id' => $student->id,
                ]);
            }
        }

        $key = Str::random(32);
        Reader::create(['label' => 'Estación Demo', 'api_key' => $key]);
        $this->command?->info("Reader API key (shown once): {$key}");
    }
}
