<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RecyclingUpdate extends Model
{
    public const TYPE_CAPTURE_CREATED = 'capture_created';

    public const TYPE_VALIDATION_STARTED = 'validation_started';

    public const TYPE_VALIDATED = 'validated';

    public const TYPE_POINTS_AWARDED = 'points_awarded';

    public const TYPE_LEADERBOARD_UPDATED = 'leaderboard_updated';

    protected $fillable = ['type', 'payload'];

    protected function casts(): array
    {
        return ['payload' => 'array'];
    }
}
