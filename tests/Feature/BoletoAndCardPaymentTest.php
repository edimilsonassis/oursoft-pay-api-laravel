<?php

namespace Tests\Feature;

use App\Domain\Payments\Enums\Provider;
use App\Models\Payment;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BoletoAndCardPaymentTest extends TestCase
{
    public function test_sicoob_boleto_with_pdf_and_cancel(): void
    {
        Storage::fake('local');
        $this->createAccount(Provider::Sicoob);

        Http::fake([
            'sandbox.sicoob.com.br/sicoob/sandbox/cobranca-bancaria/v3/boletos/*/baixar' => Http::response(null, 204),
            'sandbox.sicoob.com.br/sicoob/sandbox/cobranca-bancaria/v3/boletos' => Http::response(['resultado' => [
                'nossoNumero' => 2588658,
                'codigoBarras' => '75691000000000000000000000000000000000000000',
                'linhaDigitavel' => '75690.00000 00000.000000 00000.000000 0 00000000000000',
                'dataVencimento' => '2026-11-10T00:00:00-03:00',
                'pdfBoleto' => base64_encode('%PDF-1.4 boleto'),
            ]]),
            'cfc.test/*' => Http::response(),
        ]);

        $response = $this->withKey()->postJson('/v1/payments', [
            'method' => 'boleto',
            'amount' => 25050,
            'description' => 'Pacote de aulas',
            'payer' => $this->payer(),
            'boleto' => ['due_date' => now()->addDays(10)->format('Y-m-d'), 'instructions' => 'Não receber após 30 dias'],
        ]);

        $id = $response->assertCreated()
            ->assertJsonPath('data.status', 'waiting_payment')
            ->assertJsonPath('data.provider_reference', '2588658')
            ->assertJsonPath('data.boleto.our_number', '2588658')
            ->assertJsonPath('data.boleto.pdf_url', route('payments.boleto-pdf', $response->json('data.id')))
            ->assertJsonMissingPath('data.boleto.pdf_path')
            ->json('data.id');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/cobranca-bancaria/v3/boletos')
            && $r['valor'] === 250.5
            && $r['numeroCliente'] === 25546454
            && $r['pagador']['numeroCpfCnpj'] === '12345678909'
            && $r['pagador']['cep'] === '01001000');

        $this->withKey()->get("/v1/payments/{$id}/boleto.pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->withKey()->postJson("/v1/payments/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/boletos/2588658/baixar') && $r['codigoModalidade'] === 1);
    }

    public function test_sicoob_boleto_requires_payer_address(): void
    {
        $this->createAccount(Provider::Sicoob);

        $this->withKey()->postJson('/v1/payments', [
            'method' => 'boleto',
            'amount' => 1000,
            'payer' => ['name' => 'Maria', 'document' => '12345678909'],
            'boleto' => ['due_date' => now()->addDay()->format('Y-m-d')],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'payer_address_required');
    }

    public function test_efi_credit_card_payment_and_refund(): void
    {
        $this->createAccount(Provider::Efi);
        $status = 'approved';

        Http::fake(function (Request $r) use (&$status) {
            return match (true) {
                str_ends_with($r->url(), '/v1/authorize') => Http::response(['access_token' => 'tok', 'expires_in' => 600]),
                str_ends_with($r->url(), '/v1/charge/one-step') => Http::response(['code' => 200, 'data' => [
                    'charge_id' => 9001, 'status' => $status, 'installments' => 3, 'installment_value' => 3334, 'total' => 10000,
                ]]),
                str_ends_with($r->url(), '/v1/charge/9001') => Http::response(['code' => 200, 'data' => [
                    'charge_id' => 9001, 'status' => $status, 'total' => 10000,
                    'payment' => ['credit_card' => ['mask' => 'XXXXXXXXXXXX1111', 'installments' => 3]],
                ]]),
                str_ends_with($r->url(), '/v1/charge/card/9001/refund') => Http::response(['code' => 200]),
                str_starts_with($r->url(), 'https://cfc.test/') => Http::response(),
                default => Http::response(['error' => 'rota inesperada'], 500),
            };
        });

        $id = $this->withKey()->postJson('/v1/payments', [
            'method' => 'credit_card',
            'amount' => 10000,
            'payer' => $this->payer(['birth_date' => '1990-05-01']),
            'card' => ['token' => 'tok_efi_123', 'installments' => 3],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'authorized')
            ->assertJsonPath('data.card.installments', 3)
            ->json('data.id');

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/charge/one-step')
            && $r['items'][0]['value'] === 10000
            && $r['metadata']['custom_id'] === $id
            && $r['payment']['credit_card']['payment_token'] === 'tok_efi_123'
            && $r['payment']['credit_card']['billing_address']['zipcode'] === '01001000'
            && $r['payment']['credit_card']['customer']['cpf'] === '12345678909');

        // Refund only after paid.
        $this->withKey()->postJson("/v1/payments/{$id}/refunds")->assertUnprocessable();

        $status = 'paid';
        $this->withKey()->postJson("/v1/payments/{$id}/sync")
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.card.mask', 'XXXXXXXXXXXX1111');

        $this->withKey()->postJson("/v1/payments/{$id}/refunds")
            ->assertCreated()
            ->assertJsonPath('data.status', 'succeeded')
            ->assertJsonPath('data.amount', 10000);

        $this->assertSame('refunded', Payment::find($id)->status->value);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/v1/charge/card/9001/refund') && $r['amount'] === 10000);
    }

    public function test_efi_charge_notification_token_triggers_sync(): void
    {
        $account = $this->createAccount(Provider::Efi);
        $payment = Payment::create([
            'client_id' => $this->client->id, 'provider_account_id' => $account->id, 'provider' => 'efi',
            'method' => 'boleto', 'status' => 'waiting_payment', 'amount' => 5000, 'provider_reference' => '777',
        ]);

        Http::fake([
            '*/v1/authorize' => Http::response(['access_token' => 'tok']),
            '*/v1/notification/abc' => Http::response(['data' => [['identifiers' => ['charge_id' => 777], 'status' => ['current' => 'paid']]]]),
            '*/v1/charge/777' => Http::response(['data' => ['charge_id' => 777, 'status' => 'paid', 'total' => 5000]]),
            'cfc.test/*' => Http::response(),
        ]);

        $this->post($account->webhookUrl(), ['notification' => 'abc'])->assertOk();

        $this->assertSame('paid', $payment->fresh()->status->value);
        $this->assertNotNull($payment->fresh()->paid_at);
    }

    public function test_unsupported_method_and_provider_error(): void
    {
        $this->createAccount(Provider::Sicoob);

        $this->withKey()->postJson('/v1/payments', [
            'method' => 'debit_card', 'amount' => 1000, 'payer' => $this->payer(), 'card' => ['token' => 't'],
        ])->assertUnprocessable()->assertJsonPath('error.code', 'method_not_supported');

        Http::fake(['*' => Http::response(['title' => 'Cobrança inválida', 'detail' => 'Chave Pix não pertence ao recebedor'], 400)]);

        $this->withKey()->postJson('/v1/payments', ['method' => 'pix', 'amount' => 1000])
            ->assertStatus(502)
            ->assertJsonPath('error.code', 'provider_error')
            ->assertJsonPath('error.message', 'Chave Pix não pertence ao recebedor')
            ->assertJsonPath('error.provider_status', 400);

        $this->assertSame('failed', Payment::sole()->status->value);
    }

    public function test_payments_are_scoped_to_client_and_filterable(): void
    {
        [$other] = $this->createClient();
        $foreign = $this->createAccount(Provider::Efi, client: $other);
        $foreignPayment = Payment::create([
            'client_id' => $other->id, 'provider_account_id' => $foreign->id, 'provider' => 'efi',
            'method' => 'pix', 'status' => 'paid', 'amount' => 100,
        ]);

        $this->withKey()->getJson("/v1/payments/{$foreignPayment->id}")->assertNotFound();
        $this->withKey()->getJson('/v1/payments?status=paid,waiting_payment')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
