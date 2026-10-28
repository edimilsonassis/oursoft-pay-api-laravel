<?php

namespace App\Models;

use App\Domain\Payments\Enums\RefundStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Refund extends Model
{
    use HasUlids;

    protected $fillable = ['payment_id', 'amount', 'status', 'reason', 'provider_reference', 'failure_reason', 'raw'];

    protected $hidden = ['raw'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'status' => RefundStatus::class,
            'raw' => 'array',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
