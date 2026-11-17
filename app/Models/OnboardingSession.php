<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OnboardingSession extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'investor_id',
        'phone_e164',
        'step',
        'payload',
        'status',
        'last_answer_at',
        'completed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'last_answer_at' => 'immutable_datetime',
        'completed_at' => 'immutable_datetime',
    ];
}
