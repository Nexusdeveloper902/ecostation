<?php

namespace App\Enums;

/** Durable backend states of a pending_captures row (bottle-first flow). */
enum PendingCaptureState: string
{
    case AwaitingCard = 'awaiting_card';
    case Validating = 'validating';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
    case Failed = 'failed';
    case Expired = 'expired';

    public function isOpen(): bool
    {
        return $this === self::AwaitingCard || $this === self::Validating;
    }
}
