<?php

namespace App\Infrastructure\Providers\Support;

use App\Domain\Payments\Exceptions\ProviderException;
use App\Models\ProviderAccount;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

trait InteractsWithProviderApi
{
    abstract protected function providerName(): string;

    protected function http(string $baseUrl, ?ProviderAccount $withCertificateOf = null): PendingRequest
    {
        $request = Http::baseUrl($baseUrl)
            ->acceptJson()
            ->connectTimeout(10)
            ->timeout((int) config('pay.http_timeout', 30));

        if ($withCertificateOf && ($cert = Certificates::path($withCertificateOf))) {
            $request->withOptions(['cert' => $cert]);
        }

        return $request;
    }

    /**
     * Executa a chamada e normaliza falhas em {@see ProviderException}.
     *
     * @param  Closure(): Response  $call
     */
    protected function send(Closure $call): array
    {
        try {
            $response = $call();
        } catch (ConnectionException $e) {
            throw new ProviderException($this->providerName(), 'Falha de comunicação com o banco: '.$e->getMessage(), previous: $e);
        }

        if ($response->failed()) {
            throw new ProviderException(
                $this->providerName(),
                $this->errorMessage($response),
                $response->status(),
                $response->json() ?? $response->body(),
            );
        }

        return $response->json() ?? [];
    }

    /**
     * Token OAuth em cache até pouco antes de expirar. A chave inclui o `updated_at`
     * da conta para que troca de credenciais invalide o token antigo.
     *
     * @param  Closure(): array{access_token: string, expires_in?: int}  $issue
     */
    protected function cachedToken(ProviderAccount $account, string $scope, Closure $issue): string
    {
        $key = sprintf('pay:token:%s:%s:%s', $account->id, md5($scope), $account->updated_at?->timestamp);

        if ($token = Cache::get($key)) {
            return $token;
        }

        $data = $issue();
        $token = (string) ($data['access_token'] ?? '');

        if ($token === '') {
            throw new ProviderException($this->providerName(), 'O banco não retornou access_token.');
        }

        Cache::put($key, $token, max(30, (int) ($data['expires_in'] ?? 300) - 60));

        return $token;
    }

    protected function errorMessage(Response $response): string
    {
        $body = $response->json();

        $candidates = [
            data_get($body, 'error_description.message'),
            data_get($body, 'error_description'),
            data_get($body, 'mensagens.0.mensagem'),
            data_get($body, 'violacoes.0.razao'),
            data_get($body, 'detail'),
            data_get($body, 'title'),
            data_get($body, 'message'),
            data_get($body, 'error'),
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return "O banco respondeu HTTP {$response->status()}.";
    }
}
