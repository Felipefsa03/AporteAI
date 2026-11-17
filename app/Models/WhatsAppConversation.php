<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WhatsAppConversation extends Model
{
    protected $table = 'whatsapp_conversations';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'investor_id',
        'bot_phone_number_id',
        'state',
        'last_message_at',
    ];

    protected $casts = [
        'last_message_at' => 'immutable_datetime',
    ];
}
