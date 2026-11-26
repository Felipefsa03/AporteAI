<?php

declare(strict_types=1);

namespace App\Models;

use App\Events\InvestorCreated;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Investor extends Model
{
    use SoftDeletes;

    protected static function boot(): void
    {
        parent::boot();

        static::created(fn (self $investor) => InvestorCreated::dispatch($investor));
    }

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'created_by_admin_id',
        'full_name',
        'phone_e164',
        'email',
        'profession',
        'income_monthly_brl',
        'lifecycle_status',
        'source_note',
    ];

    protected $casts = [
        'income_monthly_brl' => 'decimal:2',
    ];
}
