<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Entrega um evento ao cliente com assinatura HMAC-SHA256:
 *   X-OurSoft-Pay-Signature: t=<unix>,v1=hex(hmac_sha256(secret, "<t>.<corpo>"))
 * Respostas fora de 2xx são retentadas com backoff crescente (~1 dia no total).
 */
class DeliverClientWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 10;

    public array $backoff = [30, 60, 300, 900, 1800, 3600, 7200, 14400, 28800];

    public function __construct(public readonly string $deliveryId) {}

    public function handle(): void
    {
        $delivery = WebhookDelivery::with('client')->find($this->deliveryId);

        if (! $delivery || $delivery->status === WebhookDelivery::STATUS_DELIVERED) {
            return;
        }

        $body = json_encode($delivery->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, (string) $delivery->client->webhook_secret);

        $delivery->increment('attempts');

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'X-OurSoft-Pay-Event' => $delivery->event,
                    'X-OurSoft-Pay-Delivery' => $delivery->id,
                    'X-OurSoft-Pay-Signature' => "t={$timestamp},v1={$signature}",
                    'User-Agent' => 'OurSoftPay-Webhook/1.0',
                ])
                ->withBody($body, 'application/json')
                ->post($delivery->url);
        } catch (Throwable $e) {
            $delivery->update(['response_status' => null, 'response_body' => mb_substr($e->getMessage(), 0, 1000)]);

            throw $e;
        }

        $delivery->update([
            'response_status' => $response->status(),
            'response_body' => mb_substr($response->body(), 0, 1000),
        ]);

        if (! $response->successful()) {
            throw new RuntimeException("Webhook respondeu HTTP {$response->status()}.");
        }

        $delivery->update(['status' => WebhookDelivery::STATUS_DELIVERED, 'delivered_at' => now()]);
    }

    public function failed(?Throwable $e): void
    {
        WebhookDelivery::whereKey($this->deliveryId)->update(['status' => WebhookDelivery::STATUS_FAILED]);
    }
}
