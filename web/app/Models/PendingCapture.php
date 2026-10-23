<?php

namespace App\Models;

use App\Enums\PendingCaptureState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingCapture extends Model
{
    protected $fillable = ['reader_id', 'image_path', 'event_id', 'card_id', 'state', 'expires_at'];

    protected function casts(): array
    {
        return ['state' => PendingCaptureState::class, 'expires_at' => 'datetime'];
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(Reader::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(PresenceEvent::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function isUsable(): bool
    {
        return $this->state === PendingCaptureState::AwaitingCard
            && $this->expires_at->isFuture()
            && $this->created_at->lte(now());
    }

    public function scopeUsable(Builder $query): Builder
    {
        return $query->where('state', PendingCaptureState::AwaitingCard->value)
            ->where('expires_at', '>', now())
            ->where('created_at', '<=', now());
    }
}
