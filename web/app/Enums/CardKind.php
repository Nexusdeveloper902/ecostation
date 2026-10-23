<?php

namespace App\Enums;

/** HOW a credential was captured: a physical RF UID or a phone's HCE credential id. */
enum CardKind: string
{
    case Physical = 'physical';
    case Hce = 'hce';
}
