<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * El contrato de descubrimiento LAN: `_ecostation._tcp` en el script
 * anunciador, en el XML de avahi y en `./run serve`. Nada de `_pulse`.
 */
class ScriptsTest extends TestCase
{
    #[Test]
    public function the_lan_advertisement_uses_the_ecostation_service_name(): void
    {
        $py = file_get_contents(base_path('scripts/mdns/announce.py'));
        $this->assertStringContainsString('SERVICE = "_ecostation._tcp.local"', $py);
        $this->assertStringNotContainsString('_pulse', $py);
        $this->assertStringContainsString('<type>_ecostation._tcp</type>', file_get_contents(base_path('scripts/mdns/ecostation.service')));
        $this->assertStringContainsString('_ecostation._tcp', file_get_contents(base_path('run')));
    }

    #[Test]
    public function the_announcer_defaults_to_the_ecostation_name_and_is_spanish(): void
    {
        $py = file_get_contents(base_path('scripts/mdns/announce.py'));

        $this->assertStringContainsString('--name', $py);
        $this->assertStringContainsString('"EcoStation"', $py);
        $this->assertStringNotContainsString('_pulse', $py);
        $this->assertStringNotContainsString('Pulse', $py);
    }

    #[Test]
    public function the_avahi_service_file_carries_the_same_contract(): void
    {
        $xml = file_get_contents(base_path('scripts/mdns/ecostation.service'));

        $this->assertStringContainsString('EcoStation en %h', $xml);
        $this->assertStringContainsString('<txt-record>version=1</txt-record>', $xml);
        $this->assertStringContainsString('<txt-record>protocol=1</txt-record>', $xml);
        $this->assertStringContainsString('<txt-record>api=/api</txt-record>', $xml);
        $this->assertStringContainsString('<port>8000</port>', $xml);
    }

    #[Test]
    public function run_serve_starts_the_three_processes_and_can_skip_mdns(): void
    {
        $run = file_get_contents(base_path('run'));

        $this->assertStringContainsString('php artisan serve --host=0.0.0.0 --port=8000', $run);
        $this->assertStringContainsString('php artisan realtime:serve', $run);
        $this->assertStringContainsString('php artisan schedule:work', $run);
        $this->assertStringContainsString('avahi-publish-service EcoStation _ecostation._tcp 8000 version=1 protocol=1 api=/api', $run);
        $this->assertStringContainsString('scripts/mdns/announce.py --port 8000', $run);
        $this->assertStringContainsString('ECOSTATION_MDNS', $run);
        $this->assertStringContainsString('trap ', $run);
    }
}
