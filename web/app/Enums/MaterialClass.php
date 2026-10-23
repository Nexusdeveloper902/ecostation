<?php

namespace App\Enums;

enum MaterialClass: string
{
    case Plastic = 'plastic';
    case Paper = 'paper';
    case Metal = 'metal';
    case Glass = 'glass';
    case Other = 'other';

    /** Points per material. Task 3 rewrites this to honor the admin-editable settings override. */
    public function points(): int
    {
        return (int) config("recycling.points.{$this->value}", 0);
    }
}
