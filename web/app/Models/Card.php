<?php

namespace App\Models;

use App\Enums\CardKind;
use App\Enums\CardStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Card extends Model
{
    protected $fillable = ['credential_uid', 'kind', 'student_id', 'status'];

    protected function casts(): array
    {
        return ['kind' => CardKind::class, 'status' => CardStatus::class];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(PresenceEvent::class);
    }

    public function hceKey(): HasOne
    {
        return $this->hasOne(HceCredentialKey::class);
    }

    public function isHce(): bool
    {
        return $this->kind === CardKind::Hce;
    }

    public function isActive(): bool
    {
        return $this->status === CardStatus::Active;
    }
}
