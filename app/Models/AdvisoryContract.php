<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AdvisoryContract extends Model
{
    public $timestamps = false;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'investor_id',
        'version',
        'status',
        'signed_at',
        'artifact_uri',
        'privacy_notice_uri',
    ];

    protected $casts = [
        'version' => 'integer',
        'signed_at' => 'immutable_datetime',
    ];
}
