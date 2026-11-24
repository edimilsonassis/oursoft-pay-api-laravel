<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Data\CardData;
use App\Domain\Payments\Data\ProviderCharge;
use App\Domain\Payments\Data\ProviderField;
use App\Domain\Payments\Data\ProviderRefund;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\Provider;
use App\Models\Payment;
use App\Models\ProviderAccount;
use App\Models\Refund;

/**
 * Adaptador de um banco. Cada implementação traduz a API unificada do gateway
 * para a API do banco e normaliza as respostas em {@see ProviderCharge}.
 */
interface PaymentProvider
{
    public function name(): Provider;

    /** Nome do banco para exibição. */
    public function label(): string;

    /**
     * Todos os métodos que o banco oferece (independente da configuração da conta).
     *
     * @return list<PaymentMethod>
     */
    public function methods(): array;

    /**
     * Métodos que o banco suporta e que a conta está configurada para usar.
     *
     * @return list<PaymentMethod>
     */
    public function supportedMethods(ProviderAccount $account): array;

    /**
     * Campos de `credentials` e `settings` da conta: geram as regras de validação e o
     * catálogo `GET /v1/providers`.
     *
     * @return list<ProviderField>
     */
    public function fields(): array;

    public function createCharge(ProviderAccount $account, Payment $payment, ?CardData $card = null): ProviderCharge;

    public function fetchCharge(ProviderAccount $account, Payment $payment): ProviderCharge;

    public function cancelCharge(ProviderAccount $account, Payment $payment): ProviderCharge;

    public function refund(ProviderAccount $account, Payment $payment, Refund $refund): ProviderRefund;

    /**
     * Configura no banco a URL de notificação desta conta.
     *
     * @return array<string, mixed> resumo do que foi registrado
     */
    public function registerWebhooks(ProviderAccount $account): array;

    /**
     * Extrai da notificação recebida (corpo já decodificado: JSON ou form) as referências
     * (txid, charge_id, nosso número) das cobranças afetadas. O status em si é sempre
     * reconsultado no banco, então o conteúdo da notificação nunca é tomado como verdade.
     *
     * @return list<string>
     */
    public function webhookReferences(ProviderAccount $account, array $payload): array;
}
