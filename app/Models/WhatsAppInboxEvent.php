<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WhatsAppInboxEvent extends Model
{
    protected $table = 'whatsapp_inbox_events';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'dedupe_key',
        'external_id',
        'kind',
        'phone_number_id',
        'from_number_e164',
        'message_type',
        'provider_status',
        'payload_hash',
        'payload',
        'state',
        'received_at',
        'processed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'received_at' => 'immutable_datetime',
        'processed_at' => 'immutable_datetime',
    ];
}
