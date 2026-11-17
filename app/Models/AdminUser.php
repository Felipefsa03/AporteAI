<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AdminUser extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'email',
        'password_hash',
        'mfa_secret_encrypted',
        'mfa_enabled',
        'active',
    ];

    protected $hidden = [
        'password_hash',
        'mfa_secret_encrypted',
    ];

    protected $casts = [
        'mfa_enabled' => 'boolean',
        'active' => 'boolean',
        'last_login_at' => 'immutable_datetime',
    ];
}
