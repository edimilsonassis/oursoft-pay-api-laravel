<?php

use App\Http\Controllers\ProviderWebhookController;
use App\Http\Controllers\V1\AccountController;
use App\Http\Controllers\V1\ClientController;
use App\Http\Controllers\V1\PaymentController;
use App\Http\Controllers\V1\ProviderController;
use App\Http\Middleware\AuthenticateApiKey;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([AuthenticateApiKey::class, 'throttle:api'])->group(function () {
    Route::get('me', [ClientController::class, 'show']);
    Route::patch('me', [ClientController::class, 'update']);

    Route::get('providers', [ProviderController::class, 'index']);

    Route::get('accounts', [AccountController::class, 'index']);
    Route::post('accounts', [AccountController::class, 'store']);
    Route::get('accounts/{account}', [AccountController::class, 'show']);
    Route::patch('accounts/{account}', [AccountController::class, 'update']);
    Route::delete('accounts/{account}', [AccountController::class, 'destroy']);
    Route::post('accounts/{account}/webhooks', [AccountController::class, 'registerWebhooks']);

    Route::get('payments', [PaymentController::class, 'index']);
    Route::post('payments', [PaymentController::class, 'store']);
    Route::get('payments/{payment}', [PaymentController::class, 'show']);
    Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel']);
    Route::post('payments/{payment}/sync', [PaymentController::class, 'sync']);
    Route::post('payments/{payment}/refunds', [PaymentController::class, 'refund']);
    Route::get('payments/{payment}/boleto.pdf', [PaymentController::class, 'boletoPdf'])->name('payments.boleto-pdf');
});

// Notificações dos bancos (sem chave de API; autenticadas pelo token da conta na URL).
Route::post('webhooks/{account}/{token}/{suffix?}', ProviderWebhookController::class)
    ->where('suffix', '.*')
    ->middleware('throttle:webhooks')
    ->name('webhooks.provider');
