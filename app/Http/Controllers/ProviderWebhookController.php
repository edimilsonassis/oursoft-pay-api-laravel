<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessInboundWebhook;
use App\Models\InboundWebhook;
use App\Models\ProviderAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

/**
 * Recebe notificações dos bancos. A URL contém o id da conta e um token secreto
 * por conta. A notificação só é registrada e enfileirada; o processamento
 * reconsulta a cobrança no banco antes de alterar qualquer status.
 *
 * O sufixo opcional absorve caminhos que o banco acrescenta (a Efí adiciona `/pix`).
 */
class ProviderWebhookController extends Controller
{
    public function __invoke(Request $request, string $account, string $token, ?string $suffix = null): JsonResponse
    {
        $model = ProviderAccount::find($account);

        if (! $model || ! hash_equals($model->webhook_token, $token)) {
            abort(404);
        }

        $webhook = InboundWebhook::create([
            'provider_account_id' => $model->id,
            'provider' => $model->provider->value,
            'path' => $suffix,
            'headers' => Arr::only(
                array_map(fn ($v) => implode(', ', $v), $request->headers->all()),
                ['content-type', 'user-agent', 'x-forwarded-for', 'x-real-ip'],
            ),
            'body' => $request->getContent() ?: http_build_query($request->post()),
            'status' => 'received',
        ]);

        ProcessInboundWebhook::dispatch($webhook->id);

        return response()->json(['received' => true]);
    }
}
