<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'active', 'webhook_url', 'webhook_secret'];

    protected $hidden = ['webhook_secret'];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'webhook_secret' => 'encrypted',
        ];
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class);
    }

    public function providerAccounts(): HasMany
    {
        return $this->hasMany(ProviderAccount::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
