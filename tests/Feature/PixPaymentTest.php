<?php

namespace Tests\Feature;

use App\Domain\Payments\Enums\Provider;
use App\Models\InboundWebhook;
use App\Models\Payment;
use App\Models\WebhookDelivery;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PixPaymentTest extends TestCase
{
    private array $cob;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cob = [
            'status' => 'ATIVA',
            'calendario' => ['criacao' => now()->toIso8601String(), 'expiracao' => 3600],
            'loc' => ['id' => 7],
            'location' => 'pix-h.example/qr/v2/abc',
            'pixCopiaECola' => '00020101021226830014BR.GOV.BCB.PIX...',
        ];

        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_ends_with($url, '/oauth/token') => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
                str_contains($url, '/v2/loc/7/qrcode') => Http::response(['qrcode' => 'x', 'imagemQrcode' => 'data:image/png;base64,AAA']),
                str_contains($url, '/v2/cob/') => Http::response($this->cob + ['txid' => basename(parse_url($url, PHP_URL_PATH))]),
                str_contains($url, '/devolucao/') => Http::response(['id' => 'r1', 'rtrId' => 'D123', 'valor' => '50.00', 'status' => 'EM_PROCESSAMENTO']),
                str_starts_with($url, 'https://cfc.test/') => Http::response(['ok' => true]),
                default => Http::response(['detail' => 'rota inesperada '.$url], 500),
            };
        });
    }

    public function test_full_pix_lifecycle_with_efi(): void
    {
        $account = $this->createAccount(Provider::Efi);

        $create = $this->withKey()->withHeader('Idempotency-Key', 'mensalidade-10')->postJson('/v1/payments', [
            'method' => 'pix',
            'amount' => 15000,
            'description' => 'Mensalidade outubro',
            'external_reference' => 'charge-10',
            'payer' => ['name' => 'Maria', 'document' => '12345678909'],
            'pix' => ['expires_in' => 3600],
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.status', 'waiting_payment')
            ->assertJsonPath('data.provider', 'efi')
            ->assertJsonPath('data.pix.qr_code', $this->cob['pixCopiaECola'])
            ->assertJsonPath('data.pix.qr_code_image', 'data:image/png;base64,AAA');

        $id = $create->json('data.id');
        $txid = strtoupper($id);

        Http::assertSent(fn (Request $r) => $r->method() === 'PUT'
            && str_ends_with($r->url(), "/v2/cob/{$txid}")
            && $r['valor']['original'] === '150.00'
            && $r['chave'] === 'pix@cfc.test'
            && $r['devedor'] === ['cpf' => '12345678909', 'nome' => 'Maria']);

        // Idempotência: mesma chave devolve a mesma cobrança sem chamar o banco de novo.
        $this->withKey()->withHeader('Idempotency-Key', 'mensalidade-10')
            ->postJson('/v1/payments', ['method' => 'pix', 'amount' => 15000])
            ->assertOk()
            ->assertJsonPath('data.id', $id);
        $this->assertSame(1, Payment::count());

        // Banco notifica o pagamento: o gateway reconsulta a cobrança e avisa o cliente.
        $this->cob['status'] = 'CONCLUIDA';
        $this->cob['pix'] = [['endToEndId' => 'E000123', 'txid' => $txid, 'valor' => '150.00', 'horario' => now()->toIso8601String()]];

        $this->postJson($account->webhookUrl().'/pix', ['pix' => [['txid' => $txid, 'endToEndId' => 'E000123', 'valor' => '150.00']]])
            ->assertOk();

        $this->assertSame('processed', InboundWebhook::sole()->status);

        $this->withKey()->getJson("/v1/payments/{$id}")
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paid_amount', 15000)
            ->assertJsonPath('data.pix.end_to_end_id', 'E000123');

        $delivery = WebhookDelivery::where('event', 'payment.paid')->sole();
        $this->assertSame(WebhookDelivery::STATUS_DELIVERED, $delivery->status);

        Http::assertSent(function (Request $r) use ($id) {
            if ($r->url() !== 'https://cfc.test/webhooks/pay' || $r['event'] !== 'payment.paid') {
                return false;
            }
            preg_match('/t=(\d+),v1=([a-f0-9]+)/', $r->header('X-OurSoft-Pay-Signature')[0], $m);

            return $r['data']['id'] === $id
                && hash_equals(hash_hmac('sha256', $m[1].'.'.$r->body(), 'whsec_test'), $m[2]);
        });

        // Devolução parcial: fica pendente até o banco confirmar.
        $this->withKey()->postJson("/v1/payments/{$id}/refunds", ['amount' => 5000, 'reason' => 'desistência'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/v2/pix/E000123/devolucao/') && $r['valor'] === '50.00');

        // Não permite devolver mais que o saldo (o pendente já está reservado).
        $this->withKey()->postJson("/v1/payments/{$id}/refunds", ['amount' => 10001])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_refund_amount');

        $this->cob['pix'][0]['devolucoes'] = [['id' => 'r1', 'valor' => '50.00', 'status' => 'DEVOLVIDO']];

        $this->withKey()->postJson("/v1/payments/{$id}/sync")
            ->assertOk()
            ->assertJsonPath('data.status', 'partially_refunded')
            ->assertJsonPath('data.refunded_amount', 5000)
            ->assertJsonPath('data.refunds.0.status', 'succeeded');
    }

    public function test_webhook_with_wrong_token_is_rejected(): void
    {
        $account = $this->createAccount(Provider::Efi);

        $this->postJson("/webhooks/{$account->id}/token-errado/pix", ['pix' => []])->assertNotFound();
        $this->assertSame(0, InboundWebhook::count());
    }

    public function test_cancel_open_pix(): void
    {
        $this->createAccount(Provider::Sicoob);

        $id = $this->withKey()->postJson('/v1/payments', ['method' => 'pix', 'amount' => 1000])
            ->assertCreated()
            ->assertJsonPath('data.provider', 'sicoob')
            ->json('data.id');

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://sandbox.sicoob.com.br/sicoob/sandbox/pix/api/v2/cob/')
            && $r->hasHeader('client_id', 'sicoob-client')
            && $r->hasHeader('Authorization', 'Bearer sandbox-token'));

        $this->cob['status'] = 'REMOVIDA_PELO_USUARIO_RECEBEDOR';

        $this->withKey()->postJson("/v1/payments/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'canceled');

        $this->withKey()->postJson("/v1/payments/{$id}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'invalid_payment_state');
    }
}
