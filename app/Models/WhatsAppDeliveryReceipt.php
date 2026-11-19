<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WhatsAppDeliveryReceipt extends Model
{
    protected $table = 'whatsapp_delivery_receipts';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'dedupe_key',
        'provider_message_id',
        'outbox_id',
        'status',
        'payload',
        'occurred_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];
}
