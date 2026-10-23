<?php

namespace App\Enums;

enum CardStatus: string
{
    case Active = 'active';
    case Lost = 'lost';
    case Revoked = 'revoked';
}
