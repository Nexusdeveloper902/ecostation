<?php

namespace App\Services;

use App\Enums\MaterialClass;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin-editable runtime settings: settings row override -> config/ecostation.php default.
 * Per-request cache; secrets are deliberately not part of this surface.
 */
class SettingsService
{
    /** @var array<string, mixed>|null */
    private static ?array $cache = null;

    /** key => [config path, validation rule] */
    private const REGISTRY = [
        'points.plastic' => ['ecostation.points.plastic', 'required|integer|between:0,1000'],
        'points.metal' => ['ecostation.points.metal', 'required|integer|between:0,1000'],
        'points.glass' => ['ecostation.points.glass', 'required|integer|between:0,1000'],
        'points.paper' => ['ecostation.points.paper', 'required|integer|between:0,1000'],
        'points.other' => ['ecostation.points.other', 'required|integer|between:0,1000'],
        'photos.retention_days' => ['ecostation.photos.retention_days', 'required|integer|between:1,365'],
        'pairing.window_seconds' => ['ecostation.pairing_window_seconds', 'required|integer|between:10,600'],
        'accounts.student_email_domain' => ['ecostation.student_email_domain', 'required|string|max:190'],
        'accounts.student_initial_password' => ['ecostation.student_initial_password', 'nullable|string|min:4|max:120'],
    ];

    private const INT_KEYS = [
        'points.plastic', 'points.metal', 'points.glass', 'points.paper', 'points.other',
        'photos.retention_days', 'pairing.window_seconds',
    ];

    public static function flushCache(): void
    {
        self::$cache = null;
    }

    /** @return array<int, string> */
    public static function keys(): array
    {
        return array_keys(self::REGISTRY);
    }

    public function get(string $key): mixed
    {
        $this->load();

        return self::$cache[$key] ?? $this->default($key);
    }

    /** @return array<string, mixed> key => effective value */
    public function all(): array
    {
        $all = [];
        foreach (self::keys() as $key) {
            $all[$key] = $this->get($key);
        }

        return $all;
    }

    /**
     * Validate + persist overrides. Unknown keys / invalid values throw ValidationException.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed> effective values after the write
     */
    public function setMany(array $values): array
    {
        $unknown = array_diff(array_keys($values), self::keys());
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'settings' => 'Clave de configuración desconocida: '.implode(', ', $unknown),
            ]);
        }

        // Validate the merged view so a partial update can't yield an invalid combined state.
        // Dotted keys are nested paths to the validator, so nest the data.
        $nested = [];
        foreach (array_merge($this->all(), $values) as $key => $value) {
            data_set($nested, $key, $value);
        }
        validator($nested, array_map(fn ($r) => $r[1], self::REGISTRY))->validate();

        foreach ($values as $key => $value) {
            Setting::updateOrCreate(
                ['key' => $key],
                ['value' => in_array($key, self::INT_KEYS, true) ? (int) $value : $value],
            );
        }

        self::flushCache();

        return $this->all();
    }

    public function pointsFor(MaterialClass $m): int
    {
        return (int) $this->get("points.{$m->value}");
    }

    public function photoRetentionDays(): int
    {
        return (int) $this->get('photos.retention_days');
    }

    public function pairingWindowSeconds(): int
    {
        return (int) $this->get('pairing.window_seconds');
    }

    public function studentEmailDomain(): string
    {
        return (string) $this->get('accounts.student_email_domain');
    }

    /**
     * Override opcional. `null` significa "genera una clave aleatoria por
     * estudiante"; nunca se degrada a la vieja contraseña compartida.
     */
    public function studentInitialPassword(): ?string
    {
        $value = $this->get('accounts.student_initial_password');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function default(string $key): mixed
    {
        return config(self::REGISTRY[$key][0]);
    }

    /** A missing settings table (pre-migration boot) degrades to config defaults. */
    private function load(): void
    {
        if (self::$cache !== null) {
            return;
        }

        self::$cache = [];

        try {
            $rows = DB::table('settings')->get(['key', 'value']);
        } catch (\Throwable) {
            return;
        }

        foreach ($rows as $row) {
            self::$cache[(string) $row->key] = json_decode((string) $row->value, true);
        }
    }
}
