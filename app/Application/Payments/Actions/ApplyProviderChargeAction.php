<?php

namespace App\Application\Payments\Actions;

use App\Application\Webhooks\Actions\DispatchClientWebhookAction;
use App\Domain\Payments\Data\ProviderCharge;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

/**
 * Único ponto que grava o estado vindo do banco numa cobrança (criação, sync,
 * webhook, cancelamento). Registra a linha do tempo e notifica o cliente quando
 * o status muda.
 */
class ApplyProviderChargeAction
{
    public function __construct(private readonly DispatchClientWebhookAction $dispatchWebhook) {}

    public function __invoke(Payment $payment, ProviderCharge $charge, string $source): Payment
    {
        [$payment, $previous] = DB::transaction(function () use ($payment, $charge, $source) {
            /** @var Payment $payment */
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $previous = $payment->status;

            if ($charge->refundedAmount !== null) {
                $this->settlePendingRefunds($payment, $charge->refundedAmount);
            }

            $payment->fill([
                'provider_reference' => $payment->provider_reference ?: $charge->reference,
                'details' => array_replace_recursive($payment->details ?? [], $charge->details),
                'status' => $this->resolveStatus($payment, $charge->status),
                'last_synced_at' => now(),
            ]);

            if ($charge->paidAmount !== null) {
                $payment->paid_amount = $charge->paidAmount;
            }
            if ($charge->expiresAt) {
                $payment->expires_at = $charge->expiresAt;
            }
            if ($charge->failureReason && $payment->status === PaymentStatus::Failed) {
                $payment->failure_reason = $charge->failureReason;
            }
            if ($payment->status === PaymentStatus::Paid && ! $payment->paid_at) {
                $payment->paid_at = $charge->paidAt ?? now();
                $payment->paid_amount = $payment->paid_amount ?: $payment->amount;
            }
            if ($payment->status === PaymentStatus::Canceled && ! $payment->canceled_at) {
                $payment->canceled_at = now();
            }

            $payment->save();

            if ($previous !== $payment->status) {
                $payment->recordEvent('status_changed', $source, ['from' => $previous->value, 'to' => $payment->status->value]);
            }

            return [$payment, $previous];
        });

        if ($previous !== $payment->status && $payment->status !== PaymentStatus::Pending) {
            ($this->dispatchWebhook)($payment, 'payment.'.$payment->status->value);
        }

        return $payment;
    }

    /**
     * O banco informa "pago" mesmo após estornos parciais; o estado de estorno é nosso.
     */
    private function resolveStatus(Payment $payment, PaymentStatus $incoming): PaymentStatus
    {
        if ($incoming !== PaymentStatus::Paid || $payment->refunded_amount === 0) {
            return $incoming;
        }

        return $payment->refunded_amount >= ($payment->paid_amount ?: $payment->amount)
            ? PaymentStatus::Refunded
            : PaymentStatus::PartiallyRefunded;
    }

    /** Devoluções Pix são assíncronas: confirma as pendentes conforme o total devolvido no banco. */
    private function settlePendingRefunds(Payment $payment, int $refundedAtProvider): void
    {
        $confirmed = (int) $payment->refunds()->where('status', RefundStatus::Succeeded)->sum('amount');

        foreach ($payment->refunds()->where('status', RefundStatus::Pending)->get() as $refund) {
            if ($confirmed + $refund->amount > $refundedAtProvider) {
                break;
            }
            $refund->update(['status' => RefundStatus::Succeeded]);
            $confirmed += $refund->amount;
        }

        $payment->refunded_amount = max($payment->refunded_amount, $confirmed);
    }
}
