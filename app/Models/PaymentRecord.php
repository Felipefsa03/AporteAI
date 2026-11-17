<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PaymentRecord extends Model
{
    protected $table = 'payment_records';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'subscription_id',
        'provider_ref',
        'installment_number',
        'amount_cents',
        'due_at',
        'status',
        'paid_at',
        'payment_url',
        'reconciled_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'due_at' => 'immutable_datetime',
        'paid_at' => 'immutable_datetime',
        'reconciled_at' => 'immutable_datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }
}
