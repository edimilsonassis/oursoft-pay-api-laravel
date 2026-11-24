<?php

namespace App\Infrastructure\Providers\Sicoob;

use App\Domain\Payments\Data\ProviderField;
use App\Domain\Payments\Enums\Environment;
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

    public function label(): string
    {
        return 'Sicoob';
    }

    public function methods(): array
    {
        return [PaymentMethod::Pix, PaymentMethod::Boleto];
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

    public function fields(): array
    {
        return [
            ProviderField::credential('client_id', 'Client ID', required: true),
            ProviderField::credential('certificate', 'Certificado (.p12 / .pfx)', 'file',
                requiredIn: [Environment::Production],
                description: 'Obrigatório em produção. Em homologação, use o token de sandbox.', accept: '.p12,.pfx,.pem,application/x-pkcs12'),
            ProviderField::credential('certificate_password', 'Senha do certificado'),
            ProviderField::credential('sandbox_token', 'Token de sandbox',
                requiredIn: [Environment::Sandbox], requiredWithout: ['certificate'],
                description: 'Usado só em homologação, no lugar do certificado.'),
            ProviderField::setting('pix_key', 'Chave Pix', max: 77,
                description: 'Sem chave Pix a conta não recebe por Pix.',
                placeholder: 'CNPJ, e-mail, telefone ou chave aleatória'),
            ProviderField::setting('numero_cliente', 'Número do cliente', 'integer',
                description: 'Necessário para boleto.'),
            ProviderField::setting('numero_conta_corrente', 'Conta corrente', 'integer',
                description: 'Necessário para boleto.'),
            ProviderField::setting('codigo_modalidade', 'Código da modalidade', 'integer',
                description: 'Modalidade de cobrança do boleto. Padrão: 1.', placeholder: '1'),
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
