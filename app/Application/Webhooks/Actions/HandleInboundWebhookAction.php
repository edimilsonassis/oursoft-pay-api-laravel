<?php

namespace App\Application\Webhooks\Actions;

use App\Application\Payments\Actions\SyncPaymentAction;
use App\Domain\Payments\Contracts\ProviderRegistry;
use App\Models\InboundWebhook;
use App\Models\Payment;
use App\Models\ProviderAccount;
use Throwable;

/**
 * Processa uma notificação de banco: identifica as cobranças citadas e as
 * reconsulta no banco. O conteúdo da notificação nunca altera status diretamente.
 */
class HandleInboundWebhookAction
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly SyncPaymentAction $sync,
    ) {}

    public function __invoke(InboundWebhook $webhook): void
    {
        $account = ProviderAccount::find($webhook->provider_account_id);

        if (! $account) {
            $webhook->update(['status' => 'ignored', 'error' => 'Conta inexistente.', 'processed_at' => now()]);

            return;
        }

        try {
            $payload = $this->decode($webhook);
            $references = $this->providers->get($account->provider)->webhookReferences($account, $payload);

            $payments = Payment::query()
                ->where('provider_account_id', $account->id)
                ->whereIn('provider_reference', $references)
                ->get();

            foreach ($payments as $payment) {
                ($this->sync)($payment, 'webhook');
            }

            $webhook->update([
                'status' => $payments->isEmpty() ? 'ignored' : 'processed',
                'error' => $payments->isEmpty() && $references ? 'Nenhuma cobrança local para: '.implode(', ', $references) : null,
                'processed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $webhook->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000)]);

            throw $e;
        }
    }

    private function decode(InboundWebhook $webhook): array
    {
        $body = (string) $webhook->body;
        $json = json_decode($body, true);

        if (is_array($json)) {
            return $json;
        }

        parse_str($body, $form);

        return $form;
    }
}
