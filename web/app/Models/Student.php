<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    protected $fillable = ['name', 'class_id'];

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function pointsLedger(): HasMany
    {
        return $this->hasMany(PointsLedger::class);
    }

    public function account(): HasOne
    {
        return $this->hasOne(User::class);
    }

    public function pointBalance(): int
    {
        return (int) $this->pointsLedger()->sum('delta');
    }

    public function firstName(): string
    {
        return explode(' ', trim($this->name))[0];
    }
}
