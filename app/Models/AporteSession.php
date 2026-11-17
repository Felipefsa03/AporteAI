<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AporteSession extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'investor_id',
        'amount_cents',
        'phase',
        'answers',
        'proposal',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'answers' => 'array',
        'proposal' => 'array',
        'created_at' => 'immutable_datetime',
        'updated_at' => 'immutable_datetime',
    ];
}
