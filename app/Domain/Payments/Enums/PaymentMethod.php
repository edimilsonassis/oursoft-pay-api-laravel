<?php

namespace App\Domain\Payments\Enums;

enum PaymentMethod: string
{
    case Pix = 'pix';
    case Boleto = 'boleto';
    case CreditCard = 'credit_card';
    case DebitCard = 'debit_card';

    public function isCard(): bool
    {
        return $this === self::CreditCard || $this === self::DebitCard;
    }
}
