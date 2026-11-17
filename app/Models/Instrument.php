<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Instrument extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'symbol',
        'isin',
        'instrument_type',
        'issuer',
        'maturity_date',
        'status',
    ];

    protected $casts = [
        'maturity_date' => 'immutable_date',
    ];
}
