<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Subscription extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'investor_id',
        'plan_code',
        'status',
        'access_status',
        'due_at',
        'grace_days',
        'started_at',
        'canceled_at',
    ];

    protected $casts = [
        'due_at' => 'immutable_datetime',
        'started_at' => 'immutable_datetime',
        'canceled_at' => 'immutable_datetime',
        'grace_days' => 'integer',
    ];
}
