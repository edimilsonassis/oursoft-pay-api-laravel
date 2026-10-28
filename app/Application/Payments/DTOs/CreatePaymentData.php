<?php

namespace App\Application\Payments\DTOs;

use App\Domain\Payments\Data\CardData;
use App\Domain\Payments\Enums\PaymentMethod;
use Carbon\CarbonImmutable;

final readonly class CreatePaymentData
{
    public function __construct(
        public PaymentMethod $method,
        public int $amount,
        public ?string $accountId = null,
        public ?string $description = null,
        public ?string $externalReference = null,
        public ?string $idempotencyKey = null,
        public array $payer = [],
        public ?CarbonImmutable $dueDate = null,
        public ?int $expiresIn = null,
        public ?string $instructions = null,
        public ?CardData $card = null,
        public array $metadata = [],
    ) {}
}
