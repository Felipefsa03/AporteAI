<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SubscriptionAccessEvent extends Model
{
    protected $table = 'subscription_access_events';

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'subscription_id',
        'investor_id',
        'previous_status',
        'new_status',
        'reason',
        'source_event_id',
        'occurred_at',
    ];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
    ];
}
