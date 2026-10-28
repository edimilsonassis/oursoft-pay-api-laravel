<?php

namespace App\Http\Controllers\V1;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dados do cliente autenticado.
 */
class ClientController extends Controller
{
    /**
     * Cliente autenticado.
     */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present(AuthenticateApiKey::client($request))]);
    }

    /**
     * Atualizar URL de webhook do cliente.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            /** URL HTTPS que receberá os eventos (payment.paid etc.). */
            'webhook_url' => ['present', 'nullable', 'url:https', 'max:255'],
        ]);

        $client = AuthenticateApiKey::client($request);
        $client->update($data);

        return response()->json(['data' => $this->present($client)]);
    }

    private function present(Client $client): array
    {
        return [
            'id' => $client->id,
            'object' => 'client',
            'name' => $client->name,
            'webhook_url' => $client->webhook_url,
        ];
    }
}
