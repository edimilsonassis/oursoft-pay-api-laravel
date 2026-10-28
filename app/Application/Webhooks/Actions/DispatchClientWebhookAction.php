<?php

namespace App\Application\Webhooks\Actions;

use App\Http\Presenters\PaymentPresenter;
use App\Jobs\DeliverClientWebhook;
use App\Models\Payment;
use App\Models\WebhookDelivery;
use Illuminate\Support\Str;

/**
 * Enfileira a notificação de um evento para o cliente dono da cobrança.
 */
class DispatchClientWebhookAction
{
    public function __invoke(Payment $payment, string $event): ?WebhookDelivery
    {
        $client = $payment->client;

        if (! $client->webhook_url) {
            return null;
        }

        $id = (string) Str::ulid();

        $delivery = WebhookDelivery::create([
            'id' => $id,
            'client_id' => $client->id,
            'payment_id' => $payment->id,
            'event' => $event,
            'url' => $client->webhook_url,
            'status' => WebhookDelivery::STATUS_PENDING,
            'payload' => [
                'id' => $id,
                'event' => $event,
                'created_at' => now()->toIso8601String(),
                'data' => PaymentPresenter::present($payment->fresh(['refunds'])),
            ],
        ]);

        DeliverClientWebhook::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }
}
