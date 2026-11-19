<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WhatsAppDeadLetter extends Model
{
    protected $table = 'whatsapp_dead_letters';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'dedupe_key',
        'reference_type',
        'reference_id',
        'reason',
        'attempts',
        'payload',
        'failed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'failed_at' => 'immutable_datetime',
    ];
}
