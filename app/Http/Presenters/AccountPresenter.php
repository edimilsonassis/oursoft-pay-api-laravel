<?php

namespace App\Http\Presenters;

use App\Domain\Payments\Contracts\ProviderRegistry;
use App\Domain\Payments\Enums\PaymentMethod;
use App\Models\ProviderAccount;

final class AccountPresenter
{
    public static function present(ProviderAccount $account): array
    {
        $supported = app(ProviderRegistry::class)->get($account->provider)->supportedMethods($account);

        return [
            'id' => $account->id,
            'object' => 'account',
            'provider' => $account->provider->value,
            'environment' => $account->environment->value,
            'name' => $account->name,
            'external_reference' => $account->external_reference,
            'is_default' => $account->is_default,
            'active' => $account->active,
            'supported_methods' => array_map(fn (PaymentMethod $m) => $m->value, $supported),
            'settings' => $account->settings ?? (object) [],
            'credentials_configured' => array_keys($account->credentials ?? []),
            'webhook_url' => $account->webhookUrl(),
            'created_at' => $account->created_at?->toIso8601String(),
            'updated_at' => $account->updated_at?->toIso8601String(),
        ];
    }
}
