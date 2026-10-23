<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reader extends Model
{
    protected $fillable = ['label', 'api_key'];

    protected $hidden = ['api_key'];

    public function events(): HasMany
    {
        return $this->hasMany(PresenceEvent::class);
    }
}
