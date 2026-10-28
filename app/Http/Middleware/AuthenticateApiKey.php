<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Models\Client;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autentica o cliente pela chave de API (`Authorization: Bearer osp_...` ou `X-Api-Key`).
 * O cliente autenticado fica em `$request->attributes->get('client')`.
 */
class AuthenticateApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken() ?: $request->header('X-Api-Key');

        $key = $plain && str_starts_with($plain, ApiKey::PREFIX)
            ? ApiKey::query()->usable()->with('client')->where('key_hash', ApiKey::hash($plain))->first()
            : null;

        if (! $key || ! $key->client?->active) {
            return response()->json([
                'error' => ['code' => 'unauthenticated', 'message' => 'Chave de API ausente, inválida ou revogada.'],
            ], 401);
        }

        if (! $key->last_used_at || $key->last_used_at->lt(now()->subMinute())) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        $request->attributes->set('client', $key->client);
        $request->attributes->set('api_key', $key);

        return $next($request);
    }

    public static function client(Request $request): Client
    {
        return $request->attributes->get('client');
    }
}
