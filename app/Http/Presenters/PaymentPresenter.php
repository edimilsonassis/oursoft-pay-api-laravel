<?php

namespace App\Http\Presenters;

use App\Models\Payment;
use App\Models\Refund;

final class PaymentPresenter
{
    public static function present(Payment $payment): array
    {
        $method = $payment->method->isCard() ? 'card' : $payment->method->value;

        return [
            'id' => $payment->id,
            'object' => 'payment',
            'account_id' => $payment->provider_account_id,
            'provider' => $payment->provider->value,
            'method' => $payment->method->value,
            'status' => $payment->status->value,
            'amount' => $payment->amount,
            'paid_amount' => $payment->paid_amount,
            'refunded_amount' => $payment->refunded_amount,
            'currency' => $payment->currency,
            'description' => $payment->description,
            'external_reference' => $payment->external_reference,
            'provider_reference' => $payment->provider_reference,
            'payer' => $payment->payer,
            $method => self::methodDetails($payment, $method),
            'metadata' => $payment->metadata ?? (object) [],
            'failure_reason' => $payment->failure_reason,
            'due_date' => $payment->due_date?->format('Y-m-d'),
            'expires_at' => $payment->expires_at?->toIso8601String(),
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'canceled_at' => $payment->canceled_at?->toIso8601String(),
            'created_at' => $payment->created_at?->toIso8601String(),
            'updated_at' => $payment->updated_at?->toIso8601String(),
            'refunds' => $payment->relationLoaded('refunds')
                ? $payment->refunds->map(fn (Refund $r) => self::refund($r))->all()
                : null,
        ];
    }

    public static function refund(Refund $refund): array
    {
        return [
            'id' => $refund->id,
            'object' => 'refund',
            'payment_id' => $refund->payment_id,
            'amount' => $refund->amount,
            'status' => $refund->status->value,
            'reason' => $refund->reason,
            'failure_reason' => $refund->failure_reason,
            'created_at' => $refund->created_at?->toIso8601String(),
        ];
    }

    private static function methodDetails(Payment $payment, string $key): object
    {
        $details = $payment->details[$key] ?? [];

        if ($key === 'boleto' && ! empty($details['pdf_path'])) {
            $details['pdf_url'] = route('payments.boleto-pdf', $payment->id);
        }
        unset($details['pdf_path']);

        return (object) $details;
    }
}
