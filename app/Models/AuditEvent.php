<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AuditEvent extends Model
{
    public $timestamps = false;

    protected $table = 'audit_events';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'actor_type',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'trace_id',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'immutable_datetime',
    ];
}
