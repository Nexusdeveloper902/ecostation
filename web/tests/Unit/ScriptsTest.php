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

        $this->assertStringContainsString('ECOSTATION_LAN', $run);
        $this->assertStringContainsString('bind_host="127.0.0.1"', $run);
        $this->assertStringContainsString('php artisan serve --host="$bind_host" --port=8000', $run);
        $this->assertStringContainsString('php artisan realtime:serve --host="$bind_host" --port="$realtime_port"', $run);
        $this->assertStringContainsString('php artisan schedule:work', $run);
        $this->assertStringContainsString('avahi-publish-service EcoStation _ecostation._tcp 8000 version=1 protocol=1 api=/api', $run);
        $this->assertStringContainsString('scripts/mdns/announce.py --port 8000', $run);
        $this->assertStringContainsString('ECOSTATION_MDNS', $run);
        $this->assertStringContainsString('0|false|no|off', $run);
        $this->assertStringContainsString('ECOSTATION_DEBUG', $run);
        $this->assertStringContainsString('APP_DEBUG="$debug"', $run);
        $this->assertStringContainsString("config('realtime.port')", $run);
        $this->assertStringContainsString('kill -0 "$pid"', $run);
        $this->assertStringContainsString('wait -n', $run);
        $this->assertStringContainsString('trap ', $run);
    }

    #[Test]
    public function the_announcer_rejects_invalid_cli_values(): void
    {
        if ($this->python() === null) {
            $this->markTestSkipped('python3 no está disponible');
        }

        [$code, $output] = $this->announcer(['--port' => '0']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('puerto', $output);

        [$code, $output] = $this->announcer(['--port' => '70000']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('65535', $output);

        [$code, $output] = $this->announcer(['--port' => '8000', '--interval' => '-1']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('negativo', $output);

        [$code, $output] = $this->announcer(['--port' => '8000', '--name' => str_repeat('a', 64)]);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('etiqueta', $output);
    }

    #[Test]
    public function the_announcer_validates_labels_and_ports_in_its_helpers(): void
    {
        if ($this->python() === null) {
            $this->markTestSkipped('python3 no está disponible');
        }

        $script = base_path('scripts/mdns/announce.py');
        $probe = <<<'PY'
import importlib.util
import sys
spec = importlib.util.spec_from_file_location("announce", sys.argv[1])
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)
assert mod.validate_name("EcoStation")
assert mod.validate_name("a.b")
assert mod.port_arg("65535") == 65535
assert mod.interval_arg("0") == 0.0
for bad in ["", "a" * 64, "a..b"]:
    try:
        mod.validate_name(bad)
    except ValueError:
        pass
    else:
        raise AssertionError("validate_name accepted %r" % bad)
for bad in ["0", "65536", "-1", "abc"]:
    try:
        mod.port_arg(bad)
    except Exception:
        pass
    else:
        raise AssertionError("port_arg accepted %r" % bad)
for bad in ["-0.1", "nan", "inf", "abc"]:
    try:
        mod.interval_arg(bad)
    except Exception:
        pass
    else:
        raise AssertionError("interval_arg accepted %r" % bad)
print("ok")
PY;

        [$code, $output] = $this->pythonCommand([$this->python(), '-c', $probe, $script]);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('ok', $output);
    }

    // ---------------------------------------------------------------- helpers

    private function python(): ?string
    {
        static $path = false;

        if ($path === false) {
            $found = trim((string) shell_exec('command -v python3 2>/dev/null'));
            $path = $found !== '' ? $found : null;
        }

        return $path;
    }

    /**
     * @param  array<string, string>  $args
     * @return array{0: int, 1: string}
     */
    private function announcer(array $args): array
    {
        $command = [$this->python(), base_path('scripts/mdns/announce.py')];

        foreach ($args as $flag => $value) {
            $command[] = $flag;
            $command[] = $value;
        }

        return $this->pythonCommand($command);
    }

    /**
     * @param  array<int, string|null>  $parts
     * @return array{0: int, 1: string}
     */
    private function pythonCommand(array $parts): array
    {
        $command = implode(' ', array_map(fn ($part) => escapeshellarg((string) $part), $parts)).' 2>&1';
        $output = [];
        $code = 0;
        exec($command, $output, $code);

        return [$code, implode("\n", $output)];
    }
}
