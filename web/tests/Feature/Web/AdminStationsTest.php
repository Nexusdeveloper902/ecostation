<?php

namespace Tests\Feature\Web;

use App\Models\Reader;
use App\Models\User;
use App\Services\DeviceAuth\DeviceRequestSigner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The station/key desk: create, rotate, delete. The plaintext key leaves
 * the server exactly once (the minting response flash); the index shows
 * only the public fingerprint and never a key.
 */
class AdminStationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function tap(string $apiKey, string $credentialUid = 'DEMO-1')
    {
        return $this->postJson('/api/v1/events/tap', ['credential_uid' => $credentialUid], [
            'Authorization' => 'Bearer '.$apiKey,
        ]);
    }

    #[Test]
    public function a_created_station_key_authenticates_a_tap(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/stations', ['label' => 'Estación Nueva'])
            ->assertRedirect(route('admin.stations'));

        $key = (string) session('api_key');
        $this->assertNotSame('', $key);

        $reader = Reader::where('label', 'Estación Nueva')->firstOrFail();
        $this->assertSame($key, $reader->makeVisible('api_key')->api_key);

        $this->tap($key)->assertOk();
        $this->assertDatabaseHas('events', ['reader_id' => $reader->id]);
    }

    #[Test]
    public function rotating_the_key_invalidates_the_old_one_and_the_new_one_works(): void
    {
        $this->actingAs($this->admin())->post('/admin/stations', ['label' => 'Estación Rotable']);
        $oldKey = (string) session('api_key');
        $reader = Reader::where('label', 'Estación Rotable')->firstOrFail();

        $this->tap($oldKey)->assertOk();

        $this->actingAs($this->admin())
            ->post("/admin/stations/{$reader->id}/rotate-key")
            ->assertRedirect(route('admin.stations'));

        $newKey = (string) session('api_key');
        $this->assertNotSame($oldKey, $newKey);

        $this->tap($oldKey)->assertUnauthorized();
        $this->tap($newKey)->assertOk();
    }

    #[Test]
    public function the_server_always_generates_the_key_and_ignores_payload_keys(): void
    {
        $this->actingAs($this->admin())
            ->post('/admin/stations', ['label' => 'Estación Forzada', 'api_key' => 'attacker-key'])
            ->assertRedirect(route('admin.stations'));

        $reader = Reader::where('label', 'Estación Forzada')->firstOrFail();
        $createdKey = (string) $reader->makeVisible('api_key')->api_key;

        $this->assertNotSame('attacker-key', $createdKey);
        $this->assertSame(32, strlen($createdKey));

        $this->actingAs($this->admin())
            ->post("/admin/stations/{$reader->id}/rotate-key", ['api_key' => 'attacker-key-2'])
            ->assertRedirect(route('admin.stations'));

        $rotatedKey = (string) $reader->fresh()->makeVisible('api_key')->api_key;
        $this->assertNotSame($createdKey, $rotatedKey);
        $this->assertNotSame('attacker-key-2', $rotatedKey);
        $this->assertSame(32, strlen($rotatedKey));
    }

    #[Test]
    public function the_index_shows_the_fingerprint_and_never_a_key(): void
    {
        $demo = $this->reader();
        $demoKey = $this->readerToken();

        $response = $this->actingAs($this->admin())->get('/admin/stations');

        $response->assertOk()
            ->assertSee($demo->label)
            ->assertSee(DeviceRequestSigner::fingerprint($demoKey))
            ->assertDontSee($demoKey);
    }

    #[Test]
    public function deleting_a_station_removes_it(): void
    {
        $reader = Reader::forceCreate(['label' => 'Estación Borrable', 'api_key' => Str::random(32)]);

        $this->actingAs($this->admin())
            ->delete("/admin/stations/{$reader->id}")
            ->assertRedirect(route('admin.stations'));

        $this->assertDatabaseMissing('readers', ['id' => $reader->id]);
    }

    #[Test]
    public function a_teacher_and_a_station_user_are_forbidden_on_every_station_route(): void
    {
        $reader = $this->reader();
        $routes = [
            ['get', '/admin/stations'],
            ['post', '/admin/stations'],
            ['post', "/admin/stations/{$reader->id}/rotate-key"],
            ['delete', "/admin/stations/{$reader->id}"],
        ];

        foreach ([$this->teacher(), User::where('email', 'estacion@ecostation.test')->firstOrFail()] as $user) {
            $this->actingAsFresh($user);

            foreach ($routes as [$method, $uri]) {
                $this->{$method}($uri)->assertForbidden();
            }
        }
    }
}
