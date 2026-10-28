<?php

namespace Tests;

use App\Domain\Payments\Enums\Environment;
use App\Domain\Payments\Enums\Provider;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\ProviderAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected Client $client;

    protected string $apiKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', config('database.default'), 'Os testes devem rodar no SQLite em memória.');

        Http::preventStrayRequests();
        [$this->client, $this->apiKey] = $this->createClient();
    }

    /** @return array{0: Client, 1: string} */
    protected function createClient(?string $webhookUrl = 'https://cfc.test/webhooks/pay'): array
    {
        $client = Client::create([
            'name' => 'CFC API '.Str::random(4),
            'webhook_url' => $webhookUrl,
            'webhook_secret' => 'whsec_test',
        ]);

        [, $plain] = ApiKey::issue($client, 'test');

        return [$client, $plain];
    }

    protected function withKey(?string $key = null): static
    {
        return $this->withHeader('Authorization', 'Bearer '.($key ?? $this->apiKey));
    }

    protected function createAccount(Provider $provider, array $settings = [], array $credentials = [], ?Client $client = null): ProviderAccount
    {
        $defaults = match ($provider) {
            Provider::Efi => [
                'credentials' => ['client_id' => 'Client_Id_x', 'client_secret' => 'Client_Secret_x', 'certificate' => self::pem()],
                'settings' => ['pix_key' => 'pix@cfc.test', 'payee_code' => 'payee123'],
            ],
            Provider::Sicoob => [
                'credentials' => ['client_id' => 'sicoob-client', 'sandbox_token' => 'sandbox-token'],
                'settings' => ['pix_key' => '12345678000199', 'numero_cliente' => 25546454, 'numero_conta_corrente' => 123456, 'codigo_modalidade' => 1],
            ],
        };

        return ProviderAccount::create([
            'client_id' => ($client ?? $this->client)->id,
            'provider' => $provider,
            'environment' => Environment::Sandbox,
            'name' => 'Conta '.$provider->value,
            'credentials' => array_merge($defaults['credentials'], $credentials),
            'settings' => array_merge($defaults['settings'], $settings),
            'webhook_token' => Str::random(40),
            'is_default' => true,
        ]);
    }

    /** Certificado autoassinado (certificado + chave) para simular o mTLS. */
    protected static function pem(): string
    {
        static $pem;

        if (! $pem) {
            $key = openssl_pkey_new(['private_key_bits' => 2048]);
            $csr = openssl_csr_new(['commonName' => 'oursoft-pay-test'], $key);
            $cert = openssl_csr_sign($csr, null, $key, 1);
            openssl_x509_export($cert, $certPem);
            openssl_pkey_export($key, $keyPem);
            $pem = $certPem.$keyPem;
        }

        return $pem;
    }

    protected function payer(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'Maria da Silva',
            'document' => '123.456.789-09',
            'email' => 'maria@example.com',
            'phone' => '(11) 98888-7777',
            'address' => [
                'street' => 'Rua das Flores',
                'number' => '100',
                'district' => 'Centro',
                'city' => 'São Paulo',
                'state' => 'SP',
                'zip_code' => '01001-000',
            ],
        ], $overrides);
    }
}
