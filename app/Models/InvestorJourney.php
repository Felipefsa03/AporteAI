<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** CHAT: estado persistente da jornada do investidor no WhatsApp. */
final class InvestorJourney extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'investor_id',
        'state',
        'payload',
        'last_occurred_at',
        'last_inbox_event_id',
        'version',
    ];

    protected $casts = [
        'payload' => 'array',
        'last_occurred_at' => 'immutable_datetime',
        'version' => 'integer',
    ];

    /** @param array<string, mixed> $payload */
    public function payloadValue(string $key, mixed $default = null): mixed
    {
        $payload = is_array($this->payload) ? $this->payload : [];

        return $payload[$key] ?? $default;
    }

    /** @param array<string, mixed> $value */
    public function putPayload(string $key, mixed $value): void
    {
        $payload = is_array($this->payload) ? $this->payload : [];
        $payload[$key] = $value;
        $this->payload = $payload;
    }
}
