<?php

namespace App\Http\Controllers\V1;

use App\Application\Payments\Actions\CancelPaymentAction;
use App\Application\Payments\Actions\CreatePaymentAction;
use App\Application\Payments\Actions\RefundPaymentAction;
use App\Application\Payments\Actions\SyncPaymentAction;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiKey;
use App\Http\Presenters\PaymentPresenter;
use App\Http\Requests\RefundPaymentRequest;
use App\Http\Requests\StorePaymentRequest;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cobranças (Pix, boleto, cartão) — API unificada, independente do banco.
 */
class PaymentController extends Controller
{
    /**
     * Listar cobranças.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string'],
            'method' => ['nullable', 'string'],
            'account_id' => ['nullable', 'string'],
            'external_reference' => ['nullable', 'string'],
            'created_from' => ['nullable', 'date'],
            'created_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AuthenticateApiKey::client($request)->payments()
            ->when($request->query('status'), fn ($q, $v) => $q->whereIn('status', explode(',', $v)))
            ->when($request->query('method'), fn ($q, $v) => $q->where('method', $v))
            ->when($request->query('account_id'), fn ($q, $v) => $q->where('provider_account_id', $v))
            ->when($request->query('external_reference'), fn ($q, $v) => $q->where('external_reference', $v))
            ->when($request->query('created_from'), fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($request->query('created_to'), fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->latest()
            ->paginate((int) $request->query('per_page', 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (Payment $p) => PaymentPresenter::present($p))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * Criar cobrança.
     *
     * Envie o cabeçalho `Idempotency-Key` para poder repetir a requisição com segurança:
     * a mesma chave devolve a cobrança já criada (HTTP 200) em vez de criar outra.
     */
    public function store(StorePaymentRequest $request, CreatePaymentAction $create): JsonResponse
    {
        [$payment, $created] = $create(AuthenticateApiKey::client($request), $request->toData());

        return response()->json(['data' => PaymentPresenter::present($payment->load('refunds'))], $created ? 201 : 200);
    }

    /**
     * Consultar cobrança.
     */
    public function show(Request $request, string $payment): JsonResponse
    {
        return response()->json(['data' => PaymentPresenter::present($this->find($request, $payment)->load('refunds'))]);
    }

    /**
     * Cancelar cobrança em aberto.
     */
    public function cancel(Request $request, string $payment, CancelPaymentAction $cancel): JsonResponse
    {
        $result = $cancel($this->find($request, $payment));

        return response()->json(['data' => PaymentPresenter::present($result->load('refunds'))]);
    }

    /**
     * Reconsultar a cobrança no banco.
     */
    public function sync(Request $request, string $payment, SyncPaymentAction $sync): JsonResponse
    {
        $result = $sync($this->find($request, $payment), 'api');

        return response()->json(['data' => PaymentPresenter::present($result->load('refunds'))]);
    }

    /**
     * Estornar (total ou parcial).
     *
     * Pix: devolução; cartão de crédito: estorno. Boleto não admite estorno pelo banco.
     */
    public function refund(RefundPaymentRequest $request, string $payment, RefundPaymentAction $refund): JsonResponse
    {
        $result = $refund(
            $this->find($request, $payment),
            $request->validated('amount') !== null ? (int) $request->validated('amount') : null,
            $request->validated('reason'),
        );

        return response()->json(['data' => PaymentPresenter::refund($result)], 201);
    }

    /**
     * PDF do boleto (quando o banco devolve o arquivo em vez de um link).
     */
    public function boletoPdf(Request $request, string $payment): StreamedResponse
    {
        $path = $this->find($request, $payment)->detail('boleto.pdf_path');

        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, "boleto-{$payment}.pdf", ['Content-Type' => 'application/pdf']);
    }

    private function find(Request $request, string $id): Payment
    {
        return AuthenticateApiKey::client($request)->payments()->findOrFail($id);
    }
}
