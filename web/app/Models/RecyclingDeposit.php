<?php

namespace App\Models;

use App\Enums\MaterialClass;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecyclingDeposit extends Model
{
    protected $fillable = ['event_id', 'image_path', 'material_class', 'confidence', 'points_awarded', 'is_bottle', 'is_recyclable'];

    protected function casts(): array
    {
        return [
            'material_class' => MaterialClass::class,
            'confidence' => 'float',
            'is_bottle' => 'boolean',
            'is_recyclable' => 'boolean',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(PresenceEvent::class, 'event_id');
    }
}
