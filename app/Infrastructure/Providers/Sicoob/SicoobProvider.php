<?php

namespace App\Infrastructure\Providers\Sicoob;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\Provider;
use App\Infrastructure\Providers\AbstractProvider;
use App\Infrastructure\Providers\Pix\BacenPixGateway;
use App\Models\ProviderAccount;

/**
 * Sicoob.
 *
 * - Pix: API Pix v2 — requer `settings.pix_key`.
 * - Boleto: Cobrança Bancária v3 — requer `settings.numero_cliente` e `settings.numero_conta_corrente`.
 * - Cartões: o Sicoob não expõe adquirência em API pública.
 *
 * Produção exige `credentials.certificate` (mTLS); sandbox usa `credentials.sandbox_token`.
 */
class SicoobProvider extends AbstractProvider
{
    public function __construct(
        private readonly SicoobPixGateway $pix,
        private readonly SicoobBoletoGateway $boleto,
    ) {}

    public function name(): Provider
    {
        return Provider::Sicoob;
    }

    protected function gateways(): array
    {
        return [
            PaymentMethod::Pix->value => $this->pix,
            PaymentMethod::Boleto->value => $this->boleto,
        ];
    }

    public function supportedMethods(ProviderAccount $account): array
    {
        return array_values(array_filter([
            $account->setting('pix_key') ? PaymentMethod::Pix : null,
            $account->setting('numero_cliente') && $account->setting('numero_conta_corrente') ? PaymentMethod::Boleto : null,
        ]));
    }

    public function credentialRules(): array
    {
        return [
            'client_id' => ['required', 'string'],
            'certificate' => ['nullable', 'string'],
            'certificate_password' => ['nullable', 'string'],
            'sandbox_token' => ['nullable', 'string'],
        ];
    }

    public function settingsRules(): array
    {
        return [
            'pix_key' => ['nullable', 'string', 'max:77'],
            'numero_cliente' => ['nullable', 'integer'],
            'numero_conta_corrente' => ['nullable', 'integer'],
            'codigo_modalidade' => ['nullable', 'integer'],
        ];
    }

    public function registerWebhooks(ProviderAccount $account): array
    {
        return $account->setting('pix_key') ? $this->pix->registerWebhook($account) : [];
    }

    public function webhookReferences(ProviderAccount $account, array $payload): array
    {
        $txids = BacenPixGateway::webhookTxids($payload);

        // Notificações de boleto: procura `nossoNumero` em qualquer nível do corpo.
        $ourNumbers = [];
        array_walk_recursive($payload, function ($value, $key) use (&$ourNumbers) {
            if ($key === 'nossoNumero' && $value !== null && $value !== '') {
                $ourNumbers[] = (string) $value;
            }
        });

        return array_values(array_unique([...$txids, ...$ourNumbers]));
    }
}
