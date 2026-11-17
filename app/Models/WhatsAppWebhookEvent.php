<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WhatsAppWebhookEvent extends Model
{
    protected $table = 'whatsapp_webhook_events';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'external_id',
        'payload_hash',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'received_at' => 'immutable_datetime',
        'processed_at' => 'immutable_datetime',
    ];
}
