<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class Report extends Model
{
    /** Bind bytea as a LOB instead of a UTF-8 SQL text parameter. */
    protected function png(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: static function ($value): string {
                if (is_resource($value)) {
                    rewind($value);
                    return stream_get_contents($value);
                }
                return (string) $value;
            },
            set: static function (string $value) {
                $stream = fopen('php://memory', 'r+');
                fwrite($stream, $value);
                rewind($stream);
                return $stream;
            },
        );
    }

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
