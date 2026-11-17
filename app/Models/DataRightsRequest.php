<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DataRightsRequest extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'investor_id',
        'type',
        'status',
        'reason',
        'scheduled_for',
        'created_at',
        'resolved_at',
    ];

    protected $casts = [
        'scheduled_for' => 'immutable_datetime',
        'created_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
    ];
}
