<?php

namespace App\Domain\Payments\Data;

use App\Domain\Payments\Enums\PaymentStatus;
use Carbon\CarbonInterface;

/**
 * Estado normalizado de uma cobrança no banco.
 */
final readonly class ProviderCharge
{
    /**
     * @param  array<string, mixed>  $details  dados do método (qr code, linha digitável...), mesclados em payment.details
     * @param  array<string, mixed>  $raw  resposta original do banco (para log/debug)
     */
    public function __construct(
        public string $reference,
        public PaymentStatus $status,
        public array $details = [],
        public ?int $paidAmount = null,
        public ?CarbonInterface $paidAt = null,
        public ?int $refundedAmount = null,
        public ?CarbonInterface $expiresAt = null,
        public ?string $failureReason = null,
        public array $raw = [],
    ) {}
}
