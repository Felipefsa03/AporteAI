<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SuitabilityAssessment extends Model
{
    public function investor(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Investor::class);
    }

    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'investor_id',
        'version',
        'answers',
        'classification',
        'status',
        'assessed_at',
        'expires_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'answers' => 'array',
        'assessed_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
    ];
}
