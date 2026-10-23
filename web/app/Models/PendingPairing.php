<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingPairing extends Model
{
    protected $fillable = ['student_id', 'reader_id', 'card_id', 'expires_at', 'consumed_at',
        'last_rejected_uid', 'last_rejected_reason', 'last_rejected_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'last_rejected_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(Reader::class);
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function isActive(): bool
    {
        return $this->consumed_at === null
            && $this->expires_at->isFuture()
            && $this->created_at->lte(now());
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->where('created_at', '<=', now());
    }
}
