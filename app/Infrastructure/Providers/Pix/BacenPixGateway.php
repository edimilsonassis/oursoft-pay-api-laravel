<?php

namespace App\Infrastructure\Providers\Pix;

use App\Domain\Payments\Data\CardData;
use App\Domain\Payments\Data\ProviderCharge;
use App\Domain\Payments\Data\ProviderRefund;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\RefundStatus;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Infrastructure\Providers\ChargeGateway;
use App\Infrastructure\Providers\Support\Documents;
use App\Infrastructure\Providers\Support\InteractsWithProviderApi;
use App\Infrastructure\Providers\Support\Money;
use App\Models\Payment;
use App\Models\ProviderAccount;
use App\Models\Refund;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Cobrança Pix imediata (`/cob`) no padrão da API Pix do Banco Central,
 * implementada igualmente por Efí e Sicoob. As subclasses só definem
 * URL base e autenticação.
 */
abstract class BacenPixGateway implements ChargeGateway
{
    use InteractsWithProviderApi;

    private const DEFAULT_EXPIRATION = 86400;

    /** Cliente HTTP autenticado apontando para a raiz da API Pix (ex.: .../v2). */
    abstract protected function client(ProviderAccount $account): PendingRequest;

    /** Cabeçalhos extras ao registrar o webhook (ex.: Efí sem mTLS no receptor). */
    protected function webhookHeaders(): array
    {
        return [];
    }

    /** Dados adicionais após criar a cobrança (ex.: imagem do QR Code). */
    protected function afterCreate(ProviderAccount $account, array $data): array
    {
        return [];
    }

    public function create(ProviderAccount $account, Payment $payment, ?CardData $card = null): ProviderCharge
    {
        $pixKey = $account->setting('pix_key')
            ?? throw new PaymentException('account_misconfigured', 'A conta não possui chave Pix configurada (settings.pix_key).');

        $expiresIn = $payment->expires_at
            ? max(60, (int) now()->diffInSeconds($payment->expires_at))
            : self::DEFAULT_EXPIRATION;

        $body = array_filter([
            'calendario' => ['expiracao' => $expiresIn],
            'devedor' => $this->debtor($payment->payer ?? []),
            'valor' => ['original' => Money::toDecimal($payment->amount)],
            'chave' => $pixKey,
            'solicitacaoPagador' => $payment->description ? Str::limit($payment->description, 137) : null,
        ]);

        $txid = self::txid($payment);
        $data = $this->send(fn () => $this->client($account)->put("cob/{$txid}", $body));

        $charge = $this->toCharge($data + ['txid' => $txid]);

        return new ProviderCharge(
            reference: $txid,
            status: $charge->status,
            details: array_replace_recursive($charge->details, ['pix' => $this->afterCreate($account, $data)]),
            expiresAt: $charge->expiresAt,
            raw: $data,
        );
    }

    public function fetch(ProviderAccount $account, Payment $payment): ProviderCharge
    {
        return $this->toCharge($this->send(fn () => $this->client($account)->get('cob/'.$payment->provider_reference)));
    }

    public function cancel(ProviderAccount $account, Payment $payment): ProviderCharge
    {
        return $this->toCharge($this->send(fn () => $this->client($account)->patch(
            'cob/'.$payment->provider_reference,
            ['status' => 'REMOVIDA_PELO_USUARIO_RECEBEDOR'],
        )));
    }

    public function refund(ProviderAccount $account, Payment $payment, Refund $refund): ProviderRefund
    {
        $e2e = $payment->detail('pix.end_to_end_id')
            ?? throw new PaymentException('refund_unavailable', 'O Pix ainda não tem identificador de pagamento (endToEndId); sincronize a cobrança e tente novamente.');

        $data = $this->send(fn () => $this->client($account)->put(
            "pix/{$e2e}/devolucao/".self::refundId($refund),
            ['valor' => Money::toDecimal($refund->amount)],
        ));

        return new ProviderRefund(
            status: match ($data['status'] ?? null) {
                'DEVOLVIDO' => RefundStatus::Succeeded,
                'NAO_REALIZADO' => RefundStatus::Failed,
                default => RefundStatus::Pending,
            },
            reference: $data['rtrId'] ?? $data['id'] ?? null,
            failureReason: $data['motivo'] ?? null,
            raw: $data,
        );
    }

    public function registerWebhook(ProviderAccount $account): array
    {
        $pixKey = $account->setting('pix_key')
            ?? throw new PaymentException('account_misconfigured', 'A conta não possui chave Pix configurada (settings.pix_key).');

        $this->send(fn () => $this->client($account)
            ->withHeaders($this->webhookHeaders())
            ->put('webhook/'.rawurlencode($pixKey), ['webhookUrl' => $account->webhookUrl()]));

        return ['pix' => ['pix_key' => $pixKey, 'url' => $account->webhookUrl()]];
    }

    /** @return list<string> */
    public static function webhookTxids(array $payload): array
    {
        return collect($payload['pix'] ?? [])->pluck('txid')->filter()->map(fn ($id) => (string) $id)->values()->all();
    }

    /** txid: 26–35 caracteres alfanuméricos; o ULID da cobrança já atende (26). */
    public static function txid(Payment $payment): string
    {
        return strtoupper($payment->id);
    }

    private static function refundId(Refund $refund): string
    {
        return strtoupper($refund->id);
    }

    private function debtor(array $payer): ?array
    {
        $document = Documents::digits($payer['document'] ?? null);

        if ($document === '' || empty($payer['name'])) {
            return null;
        }

        return [
            Documents::isCnpj($document) ? 'cnpj' : 'cpf' => $document,
            'nome' => Str::limit($payer['name'], 200, ''),
        ];
    }

    protected function toCharge(array $data): ProviderCharge
    {
        $pix = collect($data['pix'] ?? []);
        $paidAmount = $pix->sum(fn ($p) => Money::toCents($p['valor'] ?? 0));
        $refunded = $pix->flatMap(fn ($p) => $p['devolucoes'] ?? [])
            ->where('status', 'DEVOLVIDO')
            ->sum(fn ($d) => Money::toCents($d['valor'] ?? 0));

        $createdAt = data_get($data, 'calendario.criacao');
        $expiresAt = $createdAt
            ? Carbon::parse($createdAt)->addSeconds((int) data_get($data, 'calendario.expiracao', self::DEFAULT_EXPIRATION))
            : null;

        $status = match ($data['status'] ?? null) {
            'CONCLUIDA' => PaymentStatus::Paid,
            'REMOVIDA_PELO_USUARIO_RECEBEDOR' => PaymentStatus::Canceled,
            'REMOVIDA_PELO_PSP' => PaymentStatus::Expired,
            default => $expiresAt?->isPast() ? PaymentStatus::Expired : PaymentStatus::WaitingPayment,
        };

        $first = $pix->first();

        return new ProviderCharge(
            reference: (string) $data['txid'],
            status: $status,
            details: ['pix' => array_filter([
                'txid' => $data['txid'] ?? null,
                'qr_code' => $data['pixCopiaECola'] ?? $data['brcode'] ?? null,
                'location' => $data['location'] ?? data_get($data, 'loc.location'),
                'end_to_end_id' => $first['endToEndId'] ?? null,
            ])],
            paidAmount: $paidAmount ?: null,
            paidAt: isset($first['horario']) ? Carbon::parse($first['horario']) : null,
            refundedAmount: $refunded ?: null,
            expiresAt: $expiresAt,
            raw: $data,
        );
    }
}
