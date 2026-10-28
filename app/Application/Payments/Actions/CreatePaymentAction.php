<?php

namespace App\Application\Payments\Actions;

use App\Application\Payments\DTOs\CreatePaymentData;
use App\Domain\Payments\Contracts\ProviderRegistry;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Exceptions\ProviderException;
use App\Models\Client;
use App\Models\Payment;
use App\Models\ProviderAccount;
use Illuminate\Database\UniqueConstraintViolationException;

class CreatePaymentAction
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly ApplyProviderChargeAction $applyCharge,
    ) {}

    /**
     * @return array{0: Payment, 1: bool} cobrança e se foi criada agora (false = repetição idempotente)
     */
    public function __invoke(Client $client, CreatePaymentData $data): array
    {
        if ($data->idempotencyKey && ($existing = $this->findByIdempotencyKey($client, $data->idempotencyKey))) {
            return [$existing, false];
        }

        $account = $this->resolveAccount($client, $data);
        $provider = $this->providers->get($account->provider);

        if (! in_array($data->method, $provider->supportedMethods($account), true)) {
            throw PaymentException::methodNotSupported($data->method->value, $account->provider->value);
        }

        try {
            $payment = $client->payments()->create([
                'provider_account_id' => $account->id,
                'provider' => $account->provider,
                'method' => $data->method,
                'status' => PaymentStatus::Pending,
                'amount' => $data->amount,
                'description' => $data->description,
                'external_reference' => $data->externalReference,
                'idempotency_key' => $data->idempotencyKey,
                'payer' => $data->payer ?: null,
                'metadata' => $data->metadata ?: null,
                'due_date' => $data->method === PaymentMethod::Boleto ? $data->dueDate : null,
                'expires_at' => $data->method === PaymentMethod::Pix
                    ? now()->addSeconds($data->expiresIn ?? (int) config('pay.pix_default_expiration'))
                    : null,
                'details' => array_filter([
                    'boleto' => $data->instructions ? ['instructions' => $data->instructions] : null,
                    'card' => $data->card ? ['installments' => $data->card->installments] : null,
                ]) ?: null,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Duas requisições simultâneas com a mesma Idempotency-Key.
            return [$this->findByIdempotencyKey($client, $data->idempotencyKey), false];
        }

        $payment->recordEvent('created', 'api');

        try {
            $charge = $provider->createCharge($account, $payment, $data->card);
        } catch (ProviderException|PaymentException $e) {
            $payment->update(['status' => PaymentStatus::Failed, 'failure_reason' => mb_substr($e->getMessage(), 0, 255)]);
            $payment->recordEvent('provider_error', 'api', ['message' => $e->getMessage()]);

            throw $e;
        }

        return [($this->applyCharge)($payment, $charge, 'api'), true];
    }

    private function findByIdempotencyKey(Client $client, string $key): ?Payment
    {
        return $client->payments()->where('idempotency_key', $key)->first();
    }

    private function resolveAccount(Client $client, CreatePaymentData $data): ProviderAccount
    {
        $query = $client->providerAccounts()->where('active', true);

        $account = $data->accountId
            ? $query->whereKey($data->accountId)->first()
            : $query->where('is_default', true)->first();

        return $account ?? throw new PaymentException(
            'account_not_found',
            $data->accountId ? 'Conta bancária não encontrada ou inativa.' : 'Informe account_id ou defina uma conta padrão.',
        );
    }
}
