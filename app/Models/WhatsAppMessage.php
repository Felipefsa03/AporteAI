<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'conversation_id',
        'external_id',
        'direction',
        'type',
        'encrypted_content',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
    ];
}
