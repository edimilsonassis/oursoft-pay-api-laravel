<?php

namespace App\Domain\Payments\Data;

use App\Domain\Payments\Enums\RefundStatus;

final readonly class ProviderRefund
{
    public function __construct(
        public RefundStatus $status,
        public ?string $reference = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}
}
