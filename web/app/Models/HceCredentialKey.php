<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HceCredentialKey extends Model
{
    protected $fillable = ['card_id', 'secret', 'fingerprint', 'provisioned_by_reader_id', 'provisioned_at'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['secret' => 'encrypted', 'provisioned_at' => 'datetime'];
    }

    public function card(): BelongsTo
    {
        return $this->belongsTo(Card::class);
    }
}
