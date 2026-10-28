<?php

namespace App\Application\Payments\Actions;

use App\Domain\Payments\Contracts\ProviderRegistry;
use App\Models\Payment;

/** Reconsulta a cobrança no banco (fonte da verdade) e aplica o estado. */
class SyncPaymentAction
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ApplyProviderChargeAction $applyCharge,
    ) {}

    public function __invoke(Payment $payment, string $source = 'sync'): Payment
    {
        if (! $payment->provider_reference) {
            return $payment;
        }

        $account = $payment->account;
        $charge = $this->providers->get($account->provider)->fetchCharge($account, $payment);

        return ($this->applyCharge)($payment, $charge, $source);
    }
}
