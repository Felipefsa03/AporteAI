<?php

declare(strict_types=1);

namespace App\Providers;

use App\Events\InvestorCreated;
use App\Listeners\SendInvestorWelcomeMessage;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        InvestorCreated::class => [
            SendInvestorWelcomeMessage::class,
        ],
    ];

    public function boot(): void
    {
        //
    }
}
