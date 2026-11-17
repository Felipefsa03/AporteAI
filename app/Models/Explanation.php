<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Explanation extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'investor_id',
        'model',
        'prompt_hash',
        'allocation_hash',
        'sources',
        'rule_version',
        'body',
        'created_at',
    ];

    protected $casts = [
        'sources' => 'array',
        'created_at' => 'immutable_datetime',
    ];
}
