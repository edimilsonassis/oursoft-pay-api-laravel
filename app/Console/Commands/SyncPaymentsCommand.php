<?php

namespace App\Console\Commands;

use App\Application\Payments\Actions\SyncPaymentAction;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Models\Payment;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rede de segurança para webhooks perdidos e para bancos sem notificação
 * (boleto Sicoob): reconsulta cobranças em aberto. Pix é verificado com mais
 * frequência que boleto, que só compensa em D+1.
 */
#[Signature('pay:sync-payments {--limit=200}')]
#[Description('Reconsulta no banco as cobranças em aberto')]
class SyncPaymentsCommand extends Command
{
    public function handle(SyncPaymentAction $sync): int
    {
        $payments = Payment::query()
            ->with('account')
            ->whereIn('status', [PaymentStatus::WaitingPayment, PaymentStatus::Authorized])
            ->whereNotNull('provider_reference')
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('method', 'pix')->where('last_synced_at', '<', now()->subMinutes(5)))
                ->orWhere(fn ($q) => $q->where('method', '!=', 'pix')->where('last_synced_at', '<', now()->subHour()))
                ->orWhereNull('last_synced_at'))
            ->where('created_at', '>', now()->subDays(90))
            ->orderBy('last_synced_at')
            ->limit((int) $this->option('limit'))
            ->get();

        $failures = 0;
        foreach ($payments as $payment) {
            try {
                $sync($payment);
            } catch (Throwable $e) {
                $failures++;
                $payment->forceFill(['last_synced_at' => now()])->saveQuietly();
                report($e);
            }
        }

        $this->components->info("{$payments->count()} cobrança(s) verificadas, {$failures} falha(s).");

        return self::SUCCESS;
    }
}
