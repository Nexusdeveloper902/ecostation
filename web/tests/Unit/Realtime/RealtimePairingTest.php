<?php

namespace Tests\Unit\Realtime;

use App\Models\Student;
use App\Services\PairingService;
use App\Services\Realtime\RealtimePairing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El detector de cambios del canal `pairing`: la firma cambia cuando
 * cambian los DATOS (armar, consumir, rechazar) y no por el mero paso
 * del tiempo; el payload es PairingService::statusPayload() tal cual.
 */
class RealtimePairingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedDemo();
    }

    private function channel(): RealtimePairing
    {
        return $this->app->make(RealtimePairing::class);
    }

    private function pairings(): PairingService
    {
        return $this->app->make(PairingService::class);
    }

    #[Test]
    public function the_signature_is_stable_while_nothing_changes(): void
    {
        $channel = $this->channel();

        $first = $channel->signature();
        $this->assertNotSame('', $first);

        $this->assertSame($first, $channel->signature(), 'una tabla inactiva no debe re-difundir');
    }

    #[Test]
    public function arming_changes_the_signature(): void
    {
        $channel = $this->channel();
        $before = $channel->signature();

        $this->pairings()->arm(Student::orderBy('id')->first());

        $this->assertNotSame($before, $channel->signature(), 'armar debe difundirse');
    }

    #[Test]
    public function consuming_and_rejecting_change_the_signature(): void
    {
        $channel = $this->channel();
        $pairings = $this->pairings();
        $target = Student::create(['name' => 'Fresh Target', 'class_id' => null]);

        // Un rechazo marca la fila armada: debe difundirse.
        $pairings->arm($target);
        $armed = $channel->signature();
        $pairings->pair($this->reader(), $this->cardUidFor('Maria González'));
        $this->assertNotSame($armed, $channel->signature(), 'un tap rechazado debe re-difundirse');

        // El consumo cierra el ciclo: también debe difundirse.
        $rejected = $channel->signature();
        $pairings->pair($this->reader(), 'SIGFRESH0001');
        $this->assertNotSame($rejected, $channel->signature(), 'un emparejamiento consumido debe difundirse');
    }

    #[Test]
    public function the_payload_is_the_shared_status_truth(): void
    {
        $pairings = $this->pairings();
        $target = Student::create(['name' => 'Payload Target', 'class_id' => null]);
        $pairings->arm($target);
        $pairings->pair($this->reader(), 'PAYLOADFRESH1');

        $this->assertSame(
            $pairings->statusPayload(),
            $this->channel()->payload(),
        );

        $payload = $this->channel()->payload();
        $this->assertNull($payload['pending']);
        $this->assertSame('PAYLOADFRESH1', $payload['last_pairing']['card_uid']);
        $this->assertSame($target->name, $payload['recent_pairings'][0]['student_name']);
    }
}
