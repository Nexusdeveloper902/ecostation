<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Reader extends Model
{
    /** Fingerprint => reader map used by Pulse-HMAC verification. */
    public const AUTH_MAP_CACHE_KEY = 'pulse-reader-auth-map-v1';

    // La clave la genera SIEMPRE el servidor (forceCreate/forceFill).
    protected $fillable = ['label'];

    protected $hidden = ['api_key'];

    protected static function booted(): void
    {
        // A minted/rotated/deleted key must be visible on the very next
        // signed request; the map is otherwise cached per fingerprint.
        static::saved(fn () => Cache::forget(self::AUTH_MAP_CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::AUTH_MAP_CACHE_KEY));
    }

    public function events(): HasMany
    {
        return $this->hasMany(PresenceEvent::class);
    }
}
