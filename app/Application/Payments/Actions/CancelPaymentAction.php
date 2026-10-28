<?php

namespace App\Application\Payments\Actions;

use App\Domain\Payments\Contracts\ProviderRegistry;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Models\Payment;

class CancelPaymentAction
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ApplyProviderChargeAction $applyCharge,
    ) {}

    public function __invoke(Payment $payment): Payment
    {
        if (! $payment->status->isCancelable() || ! $payment->provider_reference) {
            throw PaymentException::invalidState('cancelar', $payment->status->value);
        }

        $account = $payment->account;
        $charge = $this->providers->get($account->provider)->cancelCharge($account, $payment);

        return ($this->applyCharge)($payment, $charge, 'api');
    }
}
