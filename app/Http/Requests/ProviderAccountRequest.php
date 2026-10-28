<?php

namespace App\Http\Requests;

use App\Application\Accounts\DTOs\ProviderAccountData;
use App\Domain\Payments\Contracts\ProviderRegistry;
use App\Domain\Payments\Enums\Environment;
use App\Domain\Payments\Enums\Provider;
use App\Models\ProviderAccount;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro (POST) e atualização parcial (PATCH) de conta bancária.
 * As regras de `credentials.*` e `settings.*` vêm do adaptador do banco.
 */
class ProviderAccountRequest extends FormRequest
{
    public function rules(): array
    {
        $existing = $this->existingAccount();
        $provider = $existing?->provider ?? Provider::tryFrom((string) $this->input('provider'));
        $partial = $existing !== null;

        $rules = [
            'provider' => $partial ? ['prohibited'] : ['required', Rule::enum(Provider::class)],
            'environment' => [$partial ? 'sometimes' : 'required', Rule::enum(Environment::class)],
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:120'],
            /** Identificador desta conta no sistema do cliente (ex.: id do CFC). */
            'external_reference' => ['nullable', 'string', 'max:255'],
            'is_default' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            /** Sigilosas: nunca são devolvidas. Certificado em base64 (.p12/.pfx) ou PEM. */
            'credentials' => [$partial ? 'sometimes' : 'required', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
        ];

        if ($provider) {
            $adapter = app(ProviderRegistry::class)->get($provider);

            foreach ($adapter->credentialRules() as $field => $fieldRules) {
                $rules["credentials.{$field}"] = $partial ? ['sometimes', ...$fieldRules] : $fieldRules;
            }
            foreach ($adapter->settingsRules() as $field => $fieldRules) {
                $rules["settings.{$field}"] = $fieldRules;
            }
        }

        return $rules;
    }

    public function toData(): ProviderAccountData
    {
        $credentials = $this->has('credentials') ? $this->onlyKnown('credentials') : null;
        $settings = $this->has('settings') ? $this->onlyKnown('settings') : null;

        return new ProviderAccountData(
            provider: $this->has('provider') ? Provider::from($this->validated('provider')) : null,
            environment: $this->has('environment') ? Environment::from($this->validated('environment')) : null,
            name: $this->validated('name'),
            externalReference: $this->validated('external_reference'),
            credentials: $credentials,
            settings: $settings,
            isDefault: $this->has('is_default') ? $this->boolean('is_default') : null,
            active: $this->has('active') ? $this->boolean('active') : null,
        );
    }

    /** Descarta chaves que o adaptador do banco não conhece. */
    private function onlyKnown(string $group): array
    {
        $known = collect(array_keys($this->rules()))
            ->filter(fn ($key) => str_starts_with($key, "{$group}."))
            ->map(fn ($key) => substr($key, strlen($group) + 1))
            ->all();

        return array_intersect_key($this->validated($group) ?? [], array_flip($known));
    }

    private function existingAccount(): ?ProviderAccount
    {
        $id = $this->route('account');

        return $id ? ProviderAccount::find($id) : null;
    }
}
