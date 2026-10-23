<?php

namespace Tests\Feature;

use App\Models\User;
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
    }

    #[Test]
    public function demo_user_is_not_seeded_in_production(): void
    {
        $this->app['env'] = 'production';
        $this->seed();
        $this->assertSame(0, User::count());
    }
}
