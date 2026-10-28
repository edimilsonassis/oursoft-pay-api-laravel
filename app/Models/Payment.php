<?php

namespace App\Models;

use App\Domain\Payments\Enums\PaymentMethod;
use App\Domain\Payments\Enums\PaymentStatus;
use App\Domain\Payments\Enums\Provider;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    use HasUlids;

    protected $fillable = [
        'client_id', 'provider_account_id', 'provider', 'method', 'status', 'amount', 'paid_amount',
        'refunded_amount', 'currency', 'description', 'external_reference', 'idempotency_key',
        'provider_reference', 'payer', 'details', 'metadata', 'failure_reason', 'due_date',
        'expires_at', 'paid_at', 'canceled_at', 'last_synced_at',
    ];

    protected $attributes = [
        'paid_amount' => 0,
        'refunded_amount' => 0,
        'currency' => 'BRL',
    ];

    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'amount' => 'integer',
            'paid_amount' => 'integer',
            'refunded_amount' => 'integer',
            'payer' => 'array',
            'details' => 'array',
            'metadata' => 'array',
            'due_date' => 'date',
            'expires_at' => 'datetime',
            'paid_at' => 'datetime',
            'canceled_at' => 'datetime',
            'last_synced_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(ProviderAccount::class, 'provider_account_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class)->orderBy('created_at');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PaymentEvent::class)->orderBy('id');
    }

    public function detail(string $key, mixed $default = null): mixed
    {
        return data_get($this->details, $key, $default);
    }

    public function refundableAmount(): int
    {
        return max(0, ($this->paid_amount ?: $this->amount) - $this->refunded_amount);
    }

    public function recordEvent(string $type, string $source, array $data = []): PaymentEvent
    {
        return $this->events()->create([
            'type' => $type,
            'status' => $this->status->value,
            'source' => $source,
            'data' => $data ?: null,
        ]);
    }
}
