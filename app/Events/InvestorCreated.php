<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Investor;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class InvestorCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Investor $investor)
    {}
}
