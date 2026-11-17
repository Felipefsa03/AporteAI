<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PortfolioRecommendation extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'portfolio_id',
        'investor_id',
        'allocation',
        'status',
        'proposal_uri',
        'created_at',
    ];

    protected $casts = [
        'allocation' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
