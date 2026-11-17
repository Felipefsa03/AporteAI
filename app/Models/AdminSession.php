<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AdminSession extends Model
{
    protected $table = 'admin_sessions';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'admin_user_id',
        'token_hash',
        'mfa_completed_at',
        'expires_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'mfa_completed_at' => 'immutable_datetime',
        'expires_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];
}
