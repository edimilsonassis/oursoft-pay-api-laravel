<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['payment_id', 'type', 'status', 'source', 'data'];

    protected function casts(): array
    {
        return ['data' => 'array'];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
