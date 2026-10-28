<?php

namespace App\Http\Controllers\V1;

use App\Application\Accounts\Actions\RegisterProviderWebhooksAction;
use App\Application\Accounts\Actions\SaveProviderAccountAction;
use App\Application\Accounts\DTOs\ProviderAccountData;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Presenters\AccountPresenter;
use App\Http\Requests\ProviderAccountRequest;
use App\Models\ProviderAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Contas bancárias (credenciais Efí/Sicoob) do cliente.
 */
class AccountController extends Controller
{
    /**
     * Listar contas.
     */
    public function index(Request $request): JsonResponse
    {
        $accounts = AuthenticateApiKey::client($request)->providerAccounts()
            ->when($request->query('external_reference'), fn ($q, $v) => $q->where('external_reference', $v))
            ->orderBy('created_at')
            ->get();

        return response()->json(['data' => $accounts->map(fn ($a) => AccountPresenter::present($a))->all()]);
    }

    /**
     * Cadastrar conta.
     */
    public function store(ProviderAccountRequest $request, SaveProviderAccountAction $save): JsonResponse
    {
        $account = $save(AuthenticateApiKey::client($request), $request->toData());

        return response()->json(['data' => AccountPresenter::present($account)], 201);
    }

    /**
     * Consultar conta.
     */
    public function show(Request $request, string $account): JsonResponse
    {
        return response()->json(['data' => AccountPresenter::present($this->find($request, $account))]);
    }

    /**
     * Atualizar conta (parcial; credenciais e settings são mesclados).
     */
    public function update(ProviderAccountRequest $request, string $account, SaveProviderAccountAction $save): JsonResponse
    {
        $client = AuthenticateApiKey::client($request);
        $model = $save($client, $request->toData(), $this->find($request, $account));

        return response()->json(['data' => AccountPresenter::present($model)]);
    }

    /**
     * Desativar conta (o histórico de cobranças é preservado).
     */
    public function destroy(Request $request, string $account, SaveProviderAccountAction $save): JsonResponse
    {
        $model = $save(
            AuthenticateApiKey::client($request),
            new ProviderAccountData(isDefault: false, active: false),
            $this->find($request, $account),
        );

        return response()->json(['data' => AccountPresenter::present($model)]);
    }

    /**
     * Registrar no banco a URL de notificação desta conta (webhook Pix).
     */
    public function registerWebhooks(Request $request, string $account, RegisterProviderWebhooksAction $register): JsonResponse
    {
        return response()->json(['data' => $register($this->find($request, $account))]);
    }

    private function find(Request $request, string $id): ProviderAccount
    {
        return AuthenticateApiKey::client($request)->providerAccounts()->findOrFail($id);
    }
}
