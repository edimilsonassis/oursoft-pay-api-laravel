<?php

namespace App\Domain\Payments\Data;

/**
 * Dados do cartão já tokenizado no front (o gateway nunca recebe o PAN).
 */
final readonly class CardData
{
    public function __construct(
        public string $token,
        public int $installments = 1,
        public ?array $billingAddress = null,
    ) {}
}
