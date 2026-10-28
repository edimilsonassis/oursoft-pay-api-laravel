<?php

namespace App\Infrastructure\Providers\Efi;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\Provider;
use App\Infrastructure\Providers\AbstractProvider;
use App\Infrastructure\Providers\Pix\BacenPixGateway;
use App\Models\ProviderAccount;

/**
 * Efí Bank (antiga Gerencianet).
 *
 * - Pix: API Pix (mTLS) — requer `credentials.certificate` e `settings.pix_key`.
 * - Boleto e cartão de crédito: API de Cobranças (só client_id/secret).
 *   Cartão requer `settings.payee_code` (identificador da conta, usado pelo front para tokenizar).
 * - Débito: não oferecido pela Efí.
 *
 * Credenciais de Pix e de Cobranças são as mesmas da aplicação criada no painel da Efí,
 * desde que ela tenha os escopos das duas APIs habilitados.
 */
class EfiProvider extends AbstractProvider
{
    public function __construct(
        private readonly EfiPixGateway $pix,
        private readonly EfiChargesGateway $charges,
    ) {}

    public function name(): Provider
    {
        return Provider::Efi;
    }

    protected function gateways(): array
    {
        return [
            PaymentMethod::Pix->value => $this->pix,
            PaymentMethod::Boleto->value => $this->charges,
            PaymentMethod::CreditCard->value => $this->charges,
        ];
    }

    public function supportedMethods(ProviderAccount $account): array
    {
        return array_values(array_filter([
            $account->setting('pix_key') && $account->credential('certificate') ? PaymentMethod::Pix : null,
            PaymentMethod::Boleto,
            $account->setting('payee_code') ? PaymentMethod::CreditCard : null,
        ]));
    }

    public function credentialRules(): array
    {
        return [
            'client_id' => ['required', 'string'],
            'client_secret' => ['required', 'string'],
            'certificate' => ['nullable', 'string'],
            'certificate_password' => ['nullable', 'string'],
        ];
    }

    public function settingsRules(): array
    {
        return [
            'pix_key' => ['nullable', 'string', 'max:77'],
            'payee_code' => ['nullable', 'string', 'max:64'],
        ];
    }

    public function registerWebhooks(ProviderAccount $account): array
    {
        $result = ['charges' => ['url' => $account->webhookUrl(), 'mode' => 'notification_url enviada em cada cobrança']];

        if ($account->setting('pix_key')) {
            $result += $this->pix->registerWebhook($account);
        }

        return $result;
    }

    public function webhookReferences(ProviderAccount $account, array $payload): array
    {
        if (isset($payload['notification'])) {
            return $this->charges->notificationChargeIds($account, (string) $payload['notification']);
        }

        return BacenPixGateway::webhookTxids($payload);
    }
}
