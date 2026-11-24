<?php

namespace App\Http\Controllers\V1;

use App\Domain\Payments\Contracts\ProviderRegistry;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ProviderPresenter;
use Illuminate\Http\JsonResponse;

/**
 * Catálogo de bancos suportados, com os métodos e os campos de cadastro de cada um.
 */
class ProviderController extends Controller
{
    /**
     * Listar bancos.
     *
     * Permite ao cliente montar o formulário de conta (`POST /v1/accounts`) sem conhecer
     * cada banco: `fields` descreve `credentials` e `settings`, com tipo e obrigatoriedade.
     */
    public function index(ProviderRegistry $providers): JsonResponse
    {
        return response()->json([
            'data' => array_map(fn ($provider) => ProviderPresenter::present($provider), $providers->all()),
        ]);
    }
}
