<?php

namespace Database\Seeders;

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

        $this->call(DemoSeeder::class);
    }
}
