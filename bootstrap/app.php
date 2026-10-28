<?php

use App\Domain\Payments\Exceptions\PaymentException;
use App\Domain\Payments\Exceptions\ProviderException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

$error = fn (string $code, string $message, int $status, array $extra = []) => response()->json([
    'error' => ['code' => $code, 'message' => $message] + $extra,
], $status);

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) use ($error): void {
        $exceptions->shouldRenderJsonWhen(fn () => true);

        $exceptions->render(fn (PaymentException $e) => $error($e->errorCode, $e->getMessage(), $e->status));

        $exceptions->render(function (ProviderException $e) use ($error) {
            Log::warning('provider_error', [
                'provider' => $e->provider,
                'status' => $e->providerStatus,
                'body' => $e->providerBody,
                'message' => $e->getMessage(),
            ]);

            return $error('provider_error', $e->getMessage(), 502, [
                'provider' => $e->provider,
                'provider_status' => $e->providerStatus,
                'provider_response' => $e->providerBody,
            ]);
        });

        $exceptions->render(fn (ValidationException $e) => $error(
            'validation_error', $e->getMessage(), 422, ['fields' => $e->errors()],
        ));

        $exceptions->render(fn (AuthenticationException $e) => $error('unauthenticated', $e->getMessage(), 401));

        $exceptions->render(fn (ThrottleRequestsException $e) => $error('rate_limited', 'Muitas requisições; tente novamente em instantes.', 429));

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($error) {
            return $error('not_found', $e->getPrevious() instanceof ModelNotFoundException
                ? 'Recurso não encontrado.'
                : 'Rota não encontrada.', 404);
        });

        $exceptions->render(function (HttpExceptionInterface $e) use ($error) {
            return $error('http_error', $e->getMessage() ?: 'Erro HTTP.', $e->getStatusCode());
        });
    })->create();
