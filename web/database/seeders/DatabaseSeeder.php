<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Solo desarrollo: nunca sembrar la cuenta demo fuera de local/testing.
        if (! app()->environment('local', 'testing')) {
            return;
        }

        User::factory()->create([
            'name' => 'Demo',
            'email' => 'demo@ecostation.test',
            'password' => 'demo1234',
        ]);
    }
}
