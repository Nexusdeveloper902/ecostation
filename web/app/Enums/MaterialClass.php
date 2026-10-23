<?php

namespace App\Enums;

enum MaterialClass: string
{
    case Plastic = 'plastic';
    case Paper = 'paper';
    case Metal = 'metal';
    case Glass = 'glass';
    case Other = 'other';

    /** Points per material: the single source for award amounts (settings override -> config default). */
    public function points(): int
    {
        return settings()->pointsFor($this);
    }
}
