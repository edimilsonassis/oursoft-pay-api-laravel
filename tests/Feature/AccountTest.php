<?php

namespace Tests\Feature;

use App\Domain\Payments\Enums\Provider;
use App\Models\ProviderAccount;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccountTest extends TestCase
{
    public function test_creates_efi_account_with_p12_certificate_and_never_returns_credentials(): void
    {
        $key = openssl_pkey_get_private(self::pem());
        $cert = openssl_x509_read(self::pem());
        openssl_pkcs12_export($cert, $p12, $key, 'senha');

        $response = $this->withKey()->postJson('/v1/accounts', [
            'provider' => 'efi',
            'environment' => 'sandbox',
            'name' => 'CFC Centro',
            'external_reference' => 'tenant-42',
            'credentials' => [
                'client_id' => 'Client_Id_abc',
                'client_secret' => 'Client_Secret_abc',
                'certificate' => base64_encode($p12),
                'certificate_password' => 'senha',
                'unknown' => 'descartado',
            ],
            'settings' => ['pix_key' => 'pix@cfc.test', 'payee_code' => 'abc123'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.provider', 'efi')
            ->assertJsonPath('data.is_default', true)
            ->assertJsonPath('data.supported_methods', ['pix', 'boleto', 'credit_card'])
            ->assertJsonPath('data.credentials_configured', ['client_id', 'client_secret', 'certificate'])
            ->assertJsonMissingPath('data.credentials');

        $this->assertStringNotContainsString('Client_Secret_abc', $response->getContent());

        $account = ProviderAccount::sole();
        $this->assertStringContainsString('PRIVATE KEY', $account->credential('certificate'));
        $this->assertStringNotContainsString('Client_Secret_abc', $account->getRawOriginal('credentials'));
    }

    public function test_validates_provider_specific_credentials(): void
    {
        $this->withKey()->postJson('/v1/accounts', [
            'provider' => 'sicoob',
            'environment' => 'production',
            'name' => 'Sicoob',
            'credentials' => ['certificate' => 'não é base64 válido!'],
        ])->assertUnprocessable()->assertJsonValidationErrors(['credentials.client_id'], 'error.fields');
    }

    public function test_rejects_invalid_certificate(): void
    {
        $this->withKey()->postJson('/v1/accounts', [
            'provider' => 'sicoob',
            'environment' => 'production',
            'name' => 'Sicoob',
            'credentials' => ['client_id' => 'x', 'certificate' => base64_encode('lixo')],
        ])->assertUnprocessable()->assertJsonValidationErrors(['credentials.certificate'], 'error.fields');
    }

    public function test_partial_update_merges_credentials_and_switches_default(): void
    {
        $efi = $this->createAccount(Provider::Efi);
        $sicoob = $this->createAccount(Provider::Sicoob);
        $efi->update(['is_default' => true]);
        $sicoob->update(['is_default' => false]);

        $this->withKey()->patchJson("/v1/accounts/{$sicoob->id}", [
            'credentials' => ['sandbox_token' => 'novo-token'],
            'is_default' => true,
        ])->assertOk()->assertJsonPath('data.is_default', true);

        $this->assertSame('sicoob-client', $sicoob->fresh()->credential('client_id'));
        $this->assertSame('novo-token', $sicoob->fresh()->credential('sandbox_token'));
        $this->assertFalse($efi->fresh()->is_default);
    }

    public function test_accounts_are_isolated_between_clients(): void
    {
        [$other] = $this->createClient();
        $foreign = $this->createAccount(Provider::Efi, client: $other);

        $this->withKey()->getJson("/v1/accounts/{$foreign->id}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
        $this->withKey()->getJson('/v1/accounts')->assertJsonCount(0, 'data');
    }

    public function test_registers_pix_webhook_at_provider(): void
    {
        $account = $this->createAccount(Provider::Efi);

        Http::fake([
            'pix-h.api.efipay.com.br/oauth/token' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]),
            'pix-h.api.efipay.com.br/v2/webhook/*' => Http::response(['webhookUrl' => $account->webhookUrl()]),
        ]);

        $this->withKey()->postJson("/v1/accounts/{$account->id}/webhooks")
            ->assertOk()
            ->assertJsonPath('data.pix.url', $account->webhookUrl());

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/v2/webhook/'.rawurlencode('pix@cfc.test'))
            && $request->hasHeader('x-skip-mtls-checking', 'true')
            && $request['webhookUrl'] === $account->webhookUrl());
    }
}
