<?php

namespace App\Domain\Payments\Enums;

enum Environment: string
{
    case Sandbox = 'sandbox';
    case Production = 'production';
}
