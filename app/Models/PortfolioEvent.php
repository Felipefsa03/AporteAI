<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PortfolioEvent extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'portfolio_id',
        'investor_id',
        'event_type',
        'external_id',
        'instrument_id',
        'amount_cents',
        'affects_positions',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'affects_positions' => 'boolean',
        'metadata' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];
}
