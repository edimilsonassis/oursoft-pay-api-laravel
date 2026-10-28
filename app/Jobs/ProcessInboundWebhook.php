<?php

namespace App\Jobs;

use App\Application\Webhooks\Actions\HandleInboundWebhookAction;
use App\Models\InboundWebhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessInboundWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly int $inboundWebhookId) {}

    public function handle(HandleInboundWebhookAction $handle): void
    {
        if ($webhook = InboundWebhook::find($this->inboundWebhookId)) {
            $handle($webhook);
        }
    }
}
