<?php

namespace App\Infrastructure\Providers\Efi;

use App\Infrastructure\Providers\Pix\BacenPixGateway;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\PendingRequest;

/**
 * API Pix da Efí: OAuth2 com Basic Auth + certificado mTLS em todas as chamadas.
 */
class EfiPixGateway extends BacenPixGateway
{
    private const URLS = [
        'production' => 'https://pix.api.efipay.com.br',
        'sandbox' => 'https://pix-h.api.efipay.com.br',
    ];

    protected function providerName(): string
    {
        return 'efi';
    }

    protected function client(ProviderAccount $account): PendingRequest
    {
        $host = self::URLS[$account->environment->value];

        $token = $this->cachedToken($account, 'pix', fn () => $this->send(fn () => $this->http($host, $account)
            ->withBasicAuth((string) $account->credential('client_id'), (string) $account->credential('client_secret'))
            ->post('oauth/token', ['grant_type' => 'client_credentials'])));

        return $this->http($host.'/v2', $account)->withToken($token);
    }

    /** Sem mTLS no nosso receptor: a URL carrega um token secreto por conta. */
    protected function webhookHeaders(): array
    {
        return ['x-skip-mtls-checking' => 'true'];
    }

    protected function afterCreate(ProviderAccount $account, array $data): array
    {
        $locId = data_get($data, 'loc.id');

        if (! $locId) {
            return [];
        }

        $qr = $this->send(fn () => $this->client($account)->get("loc/{$locId}/qrcode"));

        return array_filter(['qr_code_image' => $qr['imagemQrcode'] ?? null]);
    }
}
