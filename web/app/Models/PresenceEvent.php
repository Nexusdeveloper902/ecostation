<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class PresenceEvent extends Model
{
    /** The value the API keeps answering in `event_type`. */
    public const TYPE = 'RECYCLING_DEPOSIT';

    protected $table = 'events';

    protected $fillable = ['card_id', 'reader_id', 'class_id', 'occurred_at', 'metadata'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'metadata' => 'array'];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }

    public function reader(): BelongsTo
    {
        return $this->belongsTo(Reader::class);
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function student(): HasOneThrough
    {
        return $this->hasOneThrough(Student::class, Card::class, 'id', 'id', 'card_id', 'student_id');
    }

    public function deposit(): HasOne
    {
        return $this->hasOne(RecyclingDeposit::class, 'event_id');
    }
}
