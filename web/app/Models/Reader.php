<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reader extends Model
{
    // La clave la genera SIEMPRE el servidor (forceCreate/forceFill).
    protected $fillable = ['label'];

    protected $hidden = ['api_key'];

    public function events(): HasMany
    {
        return $this->hasMany(PresenceEvent::class);
    }
}
