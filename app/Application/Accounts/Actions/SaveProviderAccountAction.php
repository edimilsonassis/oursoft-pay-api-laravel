<?php

namespace App\Application\Accounts\Actions;

use App\Application\Accounts\DTOs\ProviderAccountData;
use App\Infrastructure\Providers\Support\Certificates;
use App\Models\Client;
use App\Models\ProviderAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class SaveProviderAccountAction
{
    public function __invoke(Client $client, ProviderAccountData $data, ?ProviderAccount $account = null): ProviderAccount
    {
        return DB::transaction(function () use ($client, $data, $account) {
            $account ??= new ProviderAccount([
                'client_id' => $client->id,
                'provider' => $data->provider,
                'webhook_token' => Str::random(40),
                'active' => true,
                'is_default' => ! $client->providerAccounts()->where('active', true)->exists(),
            ]);

            $account->fill(array_filter([
                'environment' => $data->environment,
                'name' => $data->name,
                'external_reference' => $data->externalReference,
                'active' => $data->active,
            ], fn ($v) => $v !== null));

            if ($data->credentials !== null) {
                $account->credentials = $this->credentials(array_merge($account->credentials ?? [], $data->credentials));
            }

            if ($data->settings !== null) {
                $account->settings = array_filter(
                    array_merge($account->settings ?? [], $data->settings),
                    fn ($v) => $v !== null && $v !== '',
                ) ?: null;
            }

            if ($data->isDefault !== null) {
                $account->is_default = $data->isDefault;
            }

            $account->save();

            if ($account->is_default) {
                $client->providerAccounts()->whereKeyNot($account->id)->update(['is_default' => false]);
            }

            return $account;
        });
    }

    private function credentials(array $credentials): array
    {
        if (! empty($credentials['certificate'])) {
            try {
                $credentials['certificate'] = Certificates::normalize(
                    $credentials['certificate'],
                    $credentials['certificate_password'] ?? null,
                );
            } catch (InvalidArgumentException $e) {
                throw ValidationException::withMessages(['credentials.certificate' => $e->getMessage()]);
            }
        }

        unset($credentials['certificate_password']);

        return array_filter($credentials, fn ($v) => $v !== null && $v !== '');
    }
}
