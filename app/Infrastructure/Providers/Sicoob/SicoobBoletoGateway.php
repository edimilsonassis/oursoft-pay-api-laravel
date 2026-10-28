<?php

namespace App\Infrastructure\Providers\Sicoob;

use App\Domain\Payments\Data\CardData;
use App\Domain\Payments\Data\ProviderCharge;
use App\Domain\Payments\Data\ProviderRefund;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Infrastructure\Providers\ChargeGateway;
use App\Infrastructure\Providers\Support\Documents;
use App\Infrastructure\Providers\Support\InteractsWithProviderApi;
use App\Models\Payment;
use App\Models\ProviderAccount;
use App\Models\Refund;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Boleto pela API Cobrança Bancária v3 do Sicoob.
 *
 * O Sicoob devolve o PDF em base64; guardamos no disco local e servimos por
 * `GET /v1/payments/{id}/boleto.pdf`. A baixa de pagamento é detectada pela
 * sincronização periódica (`pay:sync-payments`).
 */
class SicoobBoletoGateway implements ChargeGateway
{
    use InteractsWithProviderApi;

    private const URLS = [
        'production' => 'https://api.sicoob.com.br/cobranca-bancaria/v3',
        'sandbox' => 'https://sandbox.sicoob.com.br/sicoob/sandbox/cobranca-bancaria/v3',
    ];

    private const SCOPE = 'boletos_inclusao boletos_consulta boletos_alteracao';

    public function __construct(private readonly SicoobAuth $auth) {}

    protected function providerName(): string
    {
        return 'sicoob';
    }

    public function create(ProviderAccount $account, Payment $payment, ?CardData $card = null): ProviderCharge
    {
        $payer = $payment->payer ?? [];
        $address = $payer['address'] ?? null;

        if (! $address || empty($address['zip_code']) || empty($address['city']) || empty($address['state'])) {
            throw new PaymentException('payer_address_required', 'Boleto Sicoob exige o endereço completo do pagador (payer.address).');
        }

        $data = $this->send(fn () => $this->client($account)->post('boletos', array_filter([
            'numeroCliente' => (int) $account->setting('numero_cliente'),
            'codigoModalidade' => $this->modality($account),
            'numeroContaCorrente' => (int) $account->setting('numero_conta_corrente'),
            'codigoEspecieDocumento' => 'DM',
            'dataEmissao' => now()->format('Y-m-d'),
            'seuNumero' => substr($payment->id, -18),
            'identificacaoBoletoEmpresa' => $payment->id,
            'identificacaoEmissaoBoleto' => 1,
            'identificacaoDistribuicaoBoleto' => 2,
            'valor' => round($payment->amount / 100, 2),
            'dataVencimento' => $payment->due_date?->format('Y-m-d'),
            'tipoDesconto' => 0,
            'tipoMulta' => 0,
            'tipoJurosMora' => 3,
            'numeroParcela' => 1,
            'aceite' => true,
            'pagador' => array_filter([
                'numeroCpfCnpj' => Documents::digits($payer['document'] ?? null),
                'nome' => Str::limit($payer['name'] ?? '', 50, ''),
                'endereco' => Str::limit(trim(($address['street'] ?? '').', '.($address['number'] ?? '')), 40, ''),
                'bairro' => Str::limit($address['district'] ?? '', 30, ''),
                'cidade' => Str::limit($address['city'], 40, ''),
                'cep' => Documents::digits($address['zip_code']),
                'uf' => strtoupper($address['state']),
                'email' => $payer['email'] ?? null,
            ]),
            'mensagensInstrucao' => $payment->detail('boleto.instructions')
                ? ['mensagens' => [Str::limit($payment->detail('boleto.instructions'), 40, '')]]
                : null,
            'gerarPdf' => true,
        ], fn ($v) => $v !== null)));

        $result = $data['resultado'] ?? [];
        $charge = $this->toCharge($payment, $result);

        if (! empty($result['pdfBoleto'])) {
            $path = "boletos/{$payment->id}.pdf";
            Storage::disk('local')->put($path, base64_decode($result['pdfBoleto']));
            $charge = new ProviderCharge(
                reference: $charge->reference,
                status: $charge->status,
                details: array_replace_recursive($charge->details, ['boleto' => ['pdf_path' => $path]]),
                raw: array_diff_key($result, ['pdfBoleto' => true]),
            );
        }

        return $charge;
    }

    public function fetch(ProviderAccount $account, Payment $payment): ProviderCharge
    {
        $data = $this->send(fn () => $this->client($account)->get('boletos', [
            'numeroCliente' => (int) $account->setting('numero_cliente'),
            'codigoModalidade' => $this->modality($account),
            'nossoNumero' => $payment->provider_reference,
        ]));

        return $this->toCharge($payment, $data['resultado'] ?? []);
    }

    public function cancel(ProviderAccount $account, Payment $payment): ProviderCharge
    {
        $this->send(fn () => $this->client($account)->post("boletos/{$payment->provider_reference}/baixar", [
            'numeroCliente' => (int) $account->setting('numero_cliente'),
            'codigoModalidade' => $this->modality($account),
        ]));

        return new ProviderCharge($payment->provider_reference, PaymentStatus::Canceled);
    }

    public function refund(ProviderAccount $account, Payment $payment, Refund $refund): ProviderRefund
    {
        throw new PaymentException('refund_not_supported', 'Boleto pago não pode ser estornado pelo banco; faça a devolução por outro meio.');
    }

    private function client(ProviderAccount $account): PendingRequest
    {
        return $this->auth->client($account, self::URLS[$account->environment->value], self::SCOPE);
    }

    private function modality(ProviderAccount $account): int
    {
        return (int) $account->setting('codigo_modalidade', 1);
    }

    private function toCharge(Payment $payment, array $result): ProviderCharge
    {
        $situation = (string) data_get($result, 'situacaoBoleto', '');

        $status = match (true) {
            str_starts_with($situation, 'Liquidado') => PaymentStatus::Paid,
            str_starts_with($situation, 'Baixado') => PaymentStatus::Canceled,
            default => PaymentStatus::WaitingPayment,
        };

        return new ProviderCharge(
            reference: (string) ($result['nossoNumero'] ?? $payment->provider_reference),
            status: $status,
            details: ['boleto' => array_filter([
                'our_number' => isset($result['nossoNumero']) ? (string) $result['nossoNumero'] : null,
                'barcode' => $result['codigoBarras'] ?? null,
                'digitable_line' => $result['linhaDigitavel'] ?? null,
                'due_date' => isset($result['dataVencimento']) ? substr($result['dataVencimento'], 0, 10) : null,
                'pix_qr_code' => $result['qrCode'] ?? null,
            ])],
            paidAmount: $status === PaymentStatus::Paid ? $payment->amount : null,
            raw: $result,
        );
    }
}
