<?php

namespace App\Http\Requests;

use App\Application\Payments\DTOs\CreatePaymentData;
use App\Domain\Payments\Data\CardData;
use App\Domain\Payments\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePaymentRequest extends FormRequest
{
    public function rules(): array
    {
        $needsPayer = 'required_if:method,boleto,credit_card,debit_card';

        return [
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            /** Valor em centavos. @example 15990 */
            'amount' => ['required', 'integer', 'min:1', 'max:99999999999'],
            /** Conta bancária a usar; se omitido, usa a conta padrão do cliente. */
            'account_id' => ['nullable', 'string', 'size:26'],
            'description' => ['nullable', 'string', 'max:255'],
            /** Identificador da cobrança no sistema do cliente. */
            'external_reference' => ['nullable', 'string', 'max:255'],

            'payer' => [$needsPayer, 'nullable', 'array'],
            'payer.name' => [$needsPayer, 'nullable', 'string', 'max:200'],
            /** CPF ou CNPJ (com ou sem máscara). */
            'payer.document' => [$needsPayer, 'nullable', 'string', 'regex:/^(\D*\d\D*){11}$|^(\D*\d\D*){14}$/'],
            'payer.email' => ['nullable', 'email', 'max:200'],
            'payer.phone' => ['nullable', 'string', 'max:20'],
            'payer.birth_date' => ['nullable', 'date_format:Y-m-d'],
            'payer.address' => ['nullable', 'array'],
            'payer.address.street' => ['required_with:payer.address', 'string', 'max:200'],
            'payer.address.number' => ['nullable', 'string', 'max:20'],
            'payer.address.complement' => ['nullable', 'string', 'max:100'],
            'payer.address.district' => ['nullable', 'string', 'max:100'],
            'payer.address.city' => ['required_with:payer.address', 'string', 'max:100'],
            'payer.address.state' => ['required_with:payer.address', 'string', 'size:2'],
            'payer.address.zip_code' => ['required_with:payer.address', 'string', 'max:9'],

            /** Validade do QR Code Pix em segundos (padrão: 24 h). */
            'pix.expires_in' => ['nullable', 'integer', 'min:60', 'max:2592000'],

            'boleto.due_date' => ['required_if:method,boleto', 'nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
            'boleto.instructions' => ['nullable', 'string', 'max:200'],

            /** Token do cartão gerado no front pela biblioteca do banco (nunca envie o número do cartão). */
            'card.token' => ['required_if:method,credit_card,debit_card', 'nullable', 'string', 'max:500'],
            'card.installments' => ['nullable', 'integer', 'min:1', 'max:12'],
            'card.billing_address' => ['nullable', 'array'],

            'metadata' => ['nullable', 'array', 'max:20'],
        ];
    }

    public function toData(): CreatePaymentData
    {
        $method = PaymentMethod::from($this->validated('method'));

        return new CreatePaymentData(
            method: $method,
            amount: (int) $this->validated('amount'),
            accountId: $this->validated('account_id'),
            description: $this->validated('description'),
            externalReference: $this->validated('external_reference'),
            idempotencyKey: $this->idempotencyKey(),
            payer: $this->validated('payer') ?? [],
            dueDate: $this->validated('boleto.due_date') ? CarbonImmutable::parse($this->validated('boleto.due_date')) : null,
            expiresIn: $this->validated('pix.expires_in'),
            instructions: $this->validated('boleto.instructions'),
            card: $method->isCard() ? new CardData(
                token: $this->validated('card.token'),
                installments: (int) ($this->validated('card.installments') ?? 1),
                billingAddress: $this->validated('card.billing_address'),
            ) : null,
            metadata: $this->validated('metadata') ?? [],
        );
    }

    private function idempotencyKey(): ?string
    {
        $key = $this->header('Idempotency-Key');

        return $key ? mb_substr($key, 0, 255) : null;
    }
}
