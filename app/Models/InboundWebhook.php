<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InboundWebhook extends Model
{
    protected $fillable = ['provider_account_id', 'provider', 'path', 'headers', 'body', 'status', 'error', 'processed_at'];

    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
