<?php

namespace App\Providers;

use App\Domain\Payments\Contracts\ProviderRegistry;
use App\Http\Middleware\AuthenticateApiKey;
use App\Infrastructure\Providers\ProviderManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public array $singletons = [
        ProviderRegistry::class => ProviderManager::class,
    ];

    public function boot(): void
    {
        // URLs geradas (webhook registrado no banco, PDF do boleto) usam sempre o domínio
        // público, nunca o host interno pelo qual a requisição chegou.
        URL::forceRootUrl(config('app.url'));
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(config('pay.rate_limit'))
            ->by($request->attributes->has('client') ? AuthenticateApiKey::client($request)->id : $request->ip()));

        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(1200)->by($request->ip()));
    }
}
