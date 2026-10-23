<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function demo_user_is_seeded_in_testing(): void
    {
        $this->seed();
        $this->assertDatabaseHas('users', ['email' => 'admin@ecostation.test']);

        $admin = User::where('email', 'admin@ecostation.test')->firstOrFail();
        $station = User::where('email', 'estacion@ecostation.test')->firstOrFail();
        $this->assertFalse($admin->must_change_password);
        $this->assertFalse($station->must_change_password);
    }

    #[Test]
    public function demo_user_is_not_seeded_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->seed();
        $this->assertSame(0, User::count());
    }

    #[Test]
    public function production_refuses_the_demo_seed_with_a_spanish_warning(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('db:seed', ['--class' => DemoSeeder::class, '--force' => true])
            ->expectsOutputToContain('producción')
            ->assertExitCode(0);

        $this->assertSame(0, User::count());
    }

    #[Test]
    public function an_explicitly_allowed_production_seed_forces_human_rotation_but_not_the_station(): void
    {
        $this->app['env'] = 'production';
        config(['ecostation.demo_seed' => true]);

        (new DemoSeeder)->run();

        $admin = User::where('email', 'admin@ecostation.test')->firstOrFail();
        $teacher = User::where('email', 'docente@ecostation.test')->firstOrFail();
        $station = User::where('email', 'estacion@ecostation.test')->firstOrFail();
        $student = User::where('email', 'maria@ecostation.test')->firstOrFail();

        $this->assertTrue($admin->must_change_password);
        $this->assertTrue($teacher->must_change_password);
        $this->assertTrue($student->must_change_password);
        $this->assertFalse($station->must_change_password);
    }
}
