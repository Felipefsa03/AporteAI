<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WhatsAppOutboxMessage extends Model
{
    protected $table = 'whatsapp_outbox_messages';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'inbox_event_id',
        'conversation_id',
        'investor_id',
        'idempotency_key',
        'to_number_e164',
        'bot_phone_number_id',
        'type',
        'payload',
        'status',
        'attempts',
        'available_at',
        'provider_message_id',
        'last_error',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'available_at' => 'immutable_datetime',
        'sent_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
        'read_at' => 'immutable_datetime',
    ];
}
