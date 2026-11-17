<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\WhatsApp\NumberRegistry;
use App\Domain\WhatsApp\WebhookAuthenticator;
use Tests\TestCase;

final class NumberRegistryTest extends TestCase
{
    public function testWebhookSignatureVerification(): void
    {
        config()->set('whatsapp.webhook.app_secret', 'secret');
        $body = '{"hello":"world"}';
        $signature = 'sha256='.hash_hmac('sha256', $body, 'secret');

        self::assertTrue(app(WebhookAuthenticator::class)->verify($body, $signature));
        self::assertFalse(app(WebhookAuthenticator::class)->verify($body, 'sha256=bad'));
        self::assertFalse(app(WebhookAuthenticator::class)->verify($body, null));
    }

    public function testConfiguredNumbers(): void
    {
        $registry = app(NumberRegistry::class);
        self::assertSame('+5575992605555', $registry->botNumberE164());
        self::assertSame('+15416221559', $registry->commercialNumberE164());
    }
}
