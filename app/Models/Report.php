<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Report extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'investor_id',
        'label',
        'payload',
        'png',
        'created_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
