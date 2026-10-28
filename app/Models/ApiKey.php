<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasUlids;

    public const PREFIX = 'osp_';

    protected $fillable = ['client_id', 'name', 'prefix', 'key_hash', 'last_used_at', 'expires_at', 'revoked_at'];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Gera uma nova chave e retorna [modelo, texto puro]. O texto puro não é persistido.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(Client $client, string $name, ?\DateTimeInterface $expiresAt = null): array
    {
        $plain = self::PREFIX.Str::random(48);

        $key = $client->apiKeys()->create([
            'name' => $name,
            'prefix' => substr($plain, 0, 12),
            'key_hash' => self::hash($plain),
            'expires_at' => $expiresAt,
        ]);

        return [$key, $plain];
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }
}
