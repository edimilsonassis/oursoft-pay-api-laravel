<?php

namespace App\Models;

use App\Domain\Payments\Enums\Environment;
use App\Domain\Payments\Enums\Provider;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Credenciais de um banco (Efí, Sicoob) pertencentes a um cliente.
 *
 * `credentials` é criptografado com a APP_KEY e nunca é devolvido pela API.
 * `settings` guarda dados não sigilosos (chave Pix, nº do cliente Sicoob, payee_code Efí...).
 */
class ProviderAccount extends Model
{
    use HasUlids;

    protected $fillable = [
        'client_id', 'provider', 'environment', 'name', 'external_reference',
        'credentials', 'settings', 'webhook_token', 'is_default', 'active',
    ];

    protected $hidden = ['credentials', 'webhook_token'];

    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'environment' => Environment::class,
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_default' => 'boolean',
            'active' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials, $key, $default);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    public function isSandbox(): bool
    {
        return $this->environment === Environment::Sandbox;
    }

    /** URL pública que o banco deve chamar para notificar esta conta. */
    public function webhookUrl(): string
    {
        return route('webhooks.provider', ['account' => $this->id, 'token' => $this->webhook_token]);
    }
}
