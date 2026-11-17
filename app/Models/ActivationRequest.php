<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ActivationRequest extends Model
{
    protected $table = 'activation_requests';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'investor_id',
        'requested_by_admin_id',
        'idempotency_key',
        'status',
        'template_name',
        'bot_phone_number_id',
        'provider_message_id',
        'requested_at',
        'delivered_at',
        'first_reply_at',
    ];

    protected $casts = [
        'requested_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
        'first_reply_at' => 'immutable_datetime',
    ];
}
