<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WhatsAppOptin extends Model
{
    protected $table = 'whatsapp_optins';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'investor_id',
        'idempotency_key',
        'phone_e164',
        'brand',
        'channel',
        'evidence_uri',
        'accepted_at',
        'revoked_at',
    ];

    protected $casts = [
        'accepted_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];
}
