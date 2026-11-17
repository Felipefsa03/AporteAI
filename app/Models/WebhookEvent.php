<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class WebhookEvent extends Model
{
    protected $table = 'webhook_events';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'provider',
        'external_id',
        'payload_hash',
        'processed_at',
    ];

    protected $casts = [
        'processed_at' => 'immutable_datetime',
    ];
}
