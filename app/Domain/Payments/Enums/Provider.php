<?php

namespace App\Domain\Payments\Enums;

enum Provider: string
{
    case Efi = 'efi';
    case Sicoob = 'sicoob';
}
