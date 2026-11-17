<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DataSource extends Model
{
    protected $table = 'data_sources';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'provider',
        'dataset',
        'license',
        'usage_allowed',
        'redistribution_allowed',
        'freshness_policy',
        'checked_at',
        'status',
    ];

    protected $casts = [
        'usage_allowed' => 'boolean',
        'redistribution_allowed' => 'boolean',
        'freshness_policy' => 'array',
        'checked_at' => 'immutable_datetime',
    ];
}
