<?php

namespace App\Infrastructure\Providers;

use App\Domain\Payments\Contracts\PaymentProvider;
use App\Domain\Payments\Contracts\ProviderRegistry;
use App\Domain\Payments\Enums\Provider;
use App\Infrastructure\Providers\Efi\EfiProvider;
use App\Infrastructure\Providers\Sicoob\SicoobProvider;
use Illuminate\Contracts\Container\Container;

class ProviderManager implements ProviderRegistry
{
    public function __construct(private readonly Container $container) {}

    public function get(Provider $provider): PaymentProvider
    {
        return $this->container->make(match ($provider) {
            Provider::Efi => EfiProvider::class,
            Provider::Sicoob => SicoobProvider::class,
        });
    }

    public function all(): array
    {
        return array_map(fn (Provider $provider) => $this->get($provider), Provider::cases());
    }
}
