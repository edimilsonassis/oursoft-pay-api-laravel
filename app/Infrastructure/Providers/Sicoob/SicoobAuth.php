<?php

namespace App\Infrastructure\Providers\Sicoob;

use App\Infrastructure\Providers\Support\InteractsWithProviderApi;
use App\Models\ProviderAccount;
use Illuminate\Http\Client\PendingRequest;

/**
 * Autenticação do Sicoob: OAuth2 client_credentials com certificado mTLS (produção).
 * No sandbox o portal fornece um Bearer fixo (`credentials.sandbox_token`).
 * Todas as chamadas levam também o cabeçalho `client_id`.
 */
class SicoobAuth
{
    use InteractsWithProviderApi;

    private const TOKEN_URL = 'https://auth.sicoob.com.br/auth/realms/cooperado/protocol/openid-connect';

    protected function providerName(): string
    {
        return 'sicoob';
    }

    public function client(ProviderAccount $account, string $baseUrl, string $scope): PendingRequest
    {
        $token = $account->isSandbox()
            ? (string) $account->credential('sandbox_token')
            : $this->cachedToken($account, $scope, fn () => $this->send(fn () => $this->http(self::TOKEN_URL, $account)
                ->asForm()
                ->post('token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $account->credential('client_id'),
                    'scope' => $scope,
                ])));

        return $this->http($baseUrl, $account)
            ->withToken($token)
            ->withHeaders(['client_id' => (string) $account->credential('client_id')]);
    }
}
