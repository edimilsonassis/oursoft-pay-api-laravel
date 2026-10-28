<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\Enums\Provider;

interface ProviderRegistry
{
    public function get(Provider $provider): PaymentProvider;
}
