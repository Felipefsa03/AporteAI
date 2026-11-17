<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class MarketQuote extends Model
{
    protected $table = 'market_quotes';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'instrument_id',
        'source_id',
        'as_of',
        'price',
        'quality_status',
        'captured_at',
        'metadata',
    ];

    protected $casts = [
        'as_of' => 'immutable_datetime',
        'captured_at' => 'immutable_datetime',
        'metadata' => 'array',
    ];
}
