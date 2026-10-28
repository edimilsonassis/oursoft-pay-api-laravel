<?php

namespace App\Application\Payments\Actions;

use App\Application\Webhooks\Actions\DispatchClientWebhookAction;
use App\Domain\Payments\Contracts\ProviderRegistry;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Exceptions\ProviderException;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Support\Facades\DB;

class RefundPaymentAction
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly DispatchClientWebhookAction $dispatchWebhook,
    ) {}

    /** @param  int|null  $amount  centavos; null = valor restante */
    public function __invoke(Payment $payment, ?int $amount = null, ?string $reason = null): Refund
    {
        if ($payment->method === PaymentMethod::Boleto) {
            throw new PaymentException('refund_not_supported', 'Boleto pago não pode ser estornado pelo banco; faça a devolução por outro meio.');
        }

        // Reserva o valor sob lock para impedir estornos concorrentes acima do pago.
        $refund = DB::transaction(function () use ($payment, $amount, $reason) {
            $locked = Payment::query()->lockForUpdate()->findOrFail($payment->id);

            if (! $locked->status->isRefundable()) {
                throw PaymentException::invalidState('estornar', $locked->status->value);
            }

            $reserved = (int) $locked->refunds()->whereIn('status', [RefundStatus::Pending, RefundStatus::Succeeded])->sum('amount');
            $available = max(0, ($locked->paid_amount ?: $locked->amount) - $reserved);
            $amount ??= $available;

            if ($amount < 1 || $amount > $available) {
                throw new PaymentException('invalid_refund_amount', "Valor de estorno inválido. Disponível: {$available} centavos.");
            }

            return $locked->refunds()->create([
                'amount' => $amount,
                'status' => RefundStatus::Pending,
                'reason' => $reason,
            ]);
        });

        $account = $payment->account;

        try {
            $result = $this->providers->get($account->provider)->refund($account, $payment, $refund);
        } catch (ProviderException|PaymentException $e) {
            $refund->update(['status' => RefundStatus::Failed, 'failure_reason' => mb_substr($e->getMessage(), 0, 255)]);

            throw $e;
        }

        $refund->update([
            'status' => $result->status,
            'provider_reference' => $result->reference,
            'failure_reason' => $result->failureReason,
            'raw' => $result->raw ?: null,
        ]);

        $payment = $this->recalculate($payment);

        return $refund->refresh();
    }

    private function recalculate(Payment $payment): Payment
    {
        [$payment, $changed] = DB::transaction(function () use ($payment) {
            $payment = Payment::query()->lockForUpdate()->findOrFail($payment->id);
            $previous = $payment->status;

            $payment->refunded_amount = (int) $payment->refunds()->where('status', RefundStatus::Succeeded)->sum('amount');

            if ($payment->refunded_amount > 0) {
                $payment->status = $payment->refunded_amount >= ($payment->paid_amount ?: $payment->amount)
                    ? PaymentStatus::Refunded
                    : PaymentStatus::PartiallyRefunded;
            }

            $payment->save();
            $payment->recordEvent('refund', 'api', ['refunded_amount' => $payment->refunded_amount]);

            return [$payment, $previous !== $payment->status];
        });

        if ($changed) {
            ($this->dispatchWebhook)($payment, 'payment.'.$payment->status->value);
        }

        return $payment;
    }
}
