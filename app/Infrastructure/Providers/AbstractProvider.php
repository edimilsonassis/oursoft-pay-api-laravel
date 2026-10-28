<?php

namespace App\Infrastructure\Providers;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Data\CardData;
use App\Domain\Payments\Data\ProviderCharge;
use App\Domain\Payments\Data\ProviderRefund;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Models\Payment;
use App\Models\ProviderAccount;
use App\Models\Refund;

/**
 * Encaminha cada operação ao {@see ChargeGateway} responsável pelo método da cobrança.
 */
abstract class AbstractProvider implements PaymentProvider
{
    /** @return array<string, ChargeGateway> indexado por PaymentMethod::value */
    abstract protected function gateways(): array;

    public function createCharge(ProviderAccount $account, Payment $payment, ?CardData $card = null): ProviderCharge
    {
        return $this->gateway($payment->method)->create($account, $payment, $card);
    }

    public function fetchCharge(ProviderAccount $account, Payment $payment): ProviderCharge
    {
        return $this->gateway($payment->method)->fetch($account, $payment);
    }

    public function cancelCharge(ProviderAccount $account, Payment $payment): ProviderCharge
    {
        return $this->gateway($payment->method)->cancel($account, $payment);
    }

    public function refund(ProviderAccount $account, Payment $payment, Refund $refund): ProviderRefund
    {
        return $this->gateway($payment->method)->refund($account, $payment, $refund);
    }

    protected function gateway(PaymentMethod $method): ChargeGateway
    {
        return $this->gateways()[$method->value]
            ?? throw PaymentException::methodNotSupported($method->value, $this->name()->value);
    }
}
