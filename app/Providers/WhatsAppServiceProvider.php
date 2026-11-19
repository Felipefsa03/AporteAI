<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\WhatsApp\MetaCloudApiGateway;
use App\Domain\WhatsApp\MockWhatsAppGateway;
use App\Domain\WhatsApp\WhatsAppGateway;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class WhatsAppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(WhatsAppGateway::class, static function (Application $app): WhatsAppGateway {
            if ((bool) config('whatsapp.cloud_api.mock', true) === true) {
                return $app->make(MockWhatsAppGateway::class);
            }

            return $app->make(MetaCloudApiGateway::class);
        });
    }
}
