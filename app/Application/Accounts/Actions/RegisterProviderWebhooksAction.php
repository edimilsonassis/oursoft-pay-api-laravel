<?php

namespace App\Application\Accounts\Actions;

use App\Domain\Payments\Contracts\ProviderRegistry;
use App\Models\ProviderAccount;

class RegisterProviderWebhooksAction
{
    public function __construct(private readonly ProviderRegistry $providers) {}

    public function __invoke(ProviderAccount $account): array
    {
        return $this->providers->get($account->provider)->registerWebhooks($account);
    }
}
