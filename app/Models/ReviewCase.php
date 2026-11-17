<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ReviewCase extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'investor_id',
        'subject_type',
        'subject_id',
        'reason',
        'status',
        'critical',
        'snapshot',
        'sources',
        'rules',
        'justification',
        'resolved_by_admin_id',
        'resolution_reason',
        'created_at',
        'resolved_at',
    ];

    protected $casts = [
        'critical' => 'boolean',
        'snapshot' => 'array',
        'sources' => 'array',
        'rules' => 'array',
        'created_at' => 'immutable_datetime',
        'resolved_at' => 'immutable_datetime',
    ];
}
