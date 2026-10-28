<?php

namespace App\Infrastructure\Providers;

use App\Domain\Payments\Data\CardData;
use App\Domain\Payments\Data\ProviderCharge;
use App\Domain\Payments\Data\ProviderRefund;
use App\Models\Payment;
use App\Models\ProviderAccount;
use App\Models\Refund;

/**
 * Operações de um método de pagamento em uma API específica do banco
 * (ex.: Pix da Efí, boleto do Sicoob). Um provedor compõe vários gateways.
 */
interface ChargeGateway
{
    public function create(ProviderAccount $account, Payment $payment, ?CardData $card = null): ProviderCharge;

    public function fetch(ProviderAccount $account, Payment $payment): ProviderCharge;

    public function cancel(ProviderAccount $account, Payment $payment): ProviderCharge;

    public function refund(ProviderAccount $account, Payment $payment, Refund $refund): ProviderRefund;
}
