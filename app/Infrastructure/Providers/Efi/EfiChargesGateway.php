<?php

namespace App\Infrastructure\Providers\Efi;

use App\Domain\Payments\Data\CardData;
use App\Domain\Payments\Data\ProviderCharge;
use App\Domain\Payments\Data\ProviderRefund;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Infrastructure\Providers\ChargeGateway;
use App\Infrastructure\Providers\Support\Documents;
use App\Infrastructure\Providers\Support\InteractsWithProviderApi;
use App\Models\Payment;
use App\Models\ProviderAccount;
use App\Models\Refund;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Str;

/**
 * API de Cobranças da Efí (boleto e cartão de crédito). Valores em centavos.
 * O cartão chega tokenizado pelo front (payment_token da lib JS da Efí).
 */
class EfiChargesGateway implements ChargeGateway
{
    use InteractsWithProviderApi;

    private const URLS = [
        'production' => 'https://cobrancas.api.efipay.com.br',
        'sandbox' => 'https://cobrancas-h.api.efipay.com.br',
    ];

    protected function providerName(): string
    {
        return 'efi';
    }

    public function create(ProviderAccount $account, Payment $payment, ?CardData $card = null): ProviderCharge
    {
        $payer = $payment->payer ?? [];

        $paymentData = match ($payment->method) {
            PaymentMethod::Boleto => ['banking_billet' => array_filter([
                'expire_at' => $payment->due_date?->format('Y-m-d'),
                'customer' => $this->customer($payer),
                'message' => $payment->detail('boleto.instructions') ? Str::limit($payment->detail('boleto.instructions'), 80, '') : null,
            ])],
            PaymentMethod::CreditCard => ['credit_card' => array_filter([
                'installments' => $card?->installments ?? 1,
                'payment_token' => $card?->token,
                'billing_address' => $this->billingAddress($card?->billingAddress ?? ($payer['address'] ?? null)),
                'customer' => $this->customer($payer, withBirth: true),
            ])],
            default => throw PaymentException::methodNotSupported($payment->method->value, 'efi'),
        };

        $data = $this->send(fn () => $this->client($account)->post('v1/charge/one-step', [
            'items' => [[
                'name' => Str::limit($payment->description ?: 'Cobrança', 255, ''),
                'value' => $payment->amount,
                'amount' => 1,
            ]],
            'metadata' => [
                'custom_id' => $payment->id,
                'notification_url' => $account->webhookUrl(),
            ],
            'payment' => $paymentData,
        ]))['data'] ?? [];

        return $this->toCharge($payment, $data);
    }

    public function fetch(ProviderAccount $account, Payment $payment): ProviderCharge
    {
        $data = $this->send(fn () => $this->client($account)->get('v1/charge/'.$payment->provider_reference))['data'] ?? [];

        return $this->toCharge($payment, $data);
    }

    public function cancel(ProviderAccount $account, Payment $payment): ProviderCharge
    {
        $this->send(fn () => $this->client($account)->put("v1/charge/{$payment->provider_reference}/cancel"));

        return $this->fetch($account, $payment);
    }

    public function refund(ProviderAccount $account, Payment $payment, Refund $refund): ProviderRefund
    {
        if ($payment->method !== PaymentMethod::CreditCard) {
            throw new PaymentException('refund_not_supported', 'Boleto pago não pode ser estornado pelo banco; faça a devolução por outro meio.');
        }

        $data = $this->send(fn () => $this->client($account)->post(
            "v1/charge/card/{$payment->provider_reference}/refund",
            ['amount' => $refund->amount],
        ));

        return new ProviderRefund(RefundStatus::Succeeded, $payment->provider_reference, raw: $data);
    }

    /**
     * Uma notificação da Efí traz só um token; os eventos são buscados com ele.
     *
     * @return list<string> charge_ids afetados
     */
    public function notificationChargeIds(ProviderAccount $account, string $token): array
    {
        $events = $this->send(fn () => $this->client($account)->get('v1/notification/'.rawurlencode($token)))['data'] ?? [];

        return collect($events)
            ->pluck('identifiers.charge_id')
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function client(ProviderAccount $account): PendingRequest
    {
        $host = self::URLS[$account->environment->value];

        $token = $this->cachedToken($account, 'charges', fn () => $this->send(fn () => $this->http($host)
            ->withBasicAuth((string) $account->credential('client_id'), (string) $account->credential('client_secret'))
            ->post('v1/authorize', ['grant_type' => 'client_credentials'])));

        return $this->http($host)->withToken($token);
    }

    private function toCharge(Payment $payment, array $data): ProviderCharge
    {
        $status = match ($data['status'] ?? null) {
            'approved' => PaymentStatus::Authorized,
            'paid', 'settled' => PaymentStatus::Paid,
            'unpaid' => $payment->method === PaymentMethod::Boleto ? PaymentStatus::Expired : PaymentStatus::Failed,
            'refunded' => PaymentStatus::Refunded,
            'contested' => PaymentStatus::Chargeback,
            'canceled' => PaymentStatus::Canceled,
            'expired' => PaymentStatus::Expired,
            default => PaymentStatus::WaitingPayment, // new, waiting, link, identified
        };

        $billet = $data['payment']['banking_billet'] ?? $data;
        $total = isset($data['total']) ? (int) $data['total'] : null;

        $details = match ($payment->method) {
            PaymentMethod::Boleto => ['boleto' => array_filter([
                'digitable_line' => $billet['barcode'] ?? null,
                'pdf_url' => data_get($billet, 'pdf.charge') ?? (is_string($billet['pdf'] ?? null) ? $billet['pdf'] : null),
                'link' => $billet['link'] ?? $data['link'] ?? null,
                'due_date' => $billet['expire_at'] ?? null,
                'pix_qr_code' => data_get($billet, 'pix.qrcode'),
                'pix_qr_code_image' => data_get($billet, 'pix.qrcode_image'),
            ])],
            default => ['card' => array_filter([
                'installments' => $data['installments'] ?? data_get($data, 'payment.credit_card.installments'),
                'installment_value' => $data['installment_value'] ?? data_get($data, 'payment.credit_card.installment_value'),
                'mask' => data_get($data, 'payment.credit_card.mask'),
            ])],
        };

        return new ProviderCharge(
            reference: (string) ($data['charge_id'] ?? $payment->provider_reference),
            status: $status,
            details: $details,
            paidAmount: $status === PaymentStatus::Paid ? ($total ?? $payment->amount) : null,
            failureReason: data_get($data, 'refusal.reason') ?? data_get($data, 'payment.message'),
            raw: $data,
        );
    }

    private function customer(array $payer, bool $withBirth = false): array
    {
        $document = Documents::digits($payer['document'] ?? null);
        $phone = Documents::digits($payer['phone'] ?? null);

        $customer = Documents::isCnpj($document)
            ? ['juridical_person' => ['corporate_name' => $payer['name'] ?? '', 'cnpj' => $document]]
            : ['name' => $payer['name'] ?? null, 'cpf' => $document ?: null];

        return array_filter($customer + [
            'email' => $payer['email'] ?? null,
            'phone_number' => strlen($phone) >= 10 ? substr($phone, -11) : null,
            'birth' => $withBirth ? ($payer['birth_date'] ?? null) : null,
        ]);
    }

    private function billingAddress(?array $address): ?array
    {
        if (! $address) {
            return null;
        }

        return array_filter([
            'street' => $address['street'] ?? null,
            'number' => isset($address['number']) ? (string) $address['number'] : null,
            'neighborhood' => $address['district'] ?? null,
            'zipcode' => Documents::digits($address['zip_code'] ?? null) ?: null,
            'city' => $address['city'] ?? null,
            'complement' => $address['complement'] ?? null,
            'state' => isset($address['state']) ? strtoupper($address['state']) : null,
        ]);
    }
}
