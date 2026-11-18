<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\WhatsApp\ActivationService;
use App\Domain\WhatsApp\WhatsAppException;
use App\Jobs\SendActivationTemplate;
use App\Models\AdminUser;
use App\Models\Investor;
use App\Models\Subscription;
use App\Models\WhatsAppOptin;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BotActivationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('whatsapp.webhook.app_secret', 'webhook-secret');
    }

    public function testRequiresOptIn(): void
    {
        $investor = $this->investor();
        $this->subscription($investor);
        $this->expectException(WhatsAppException::class);
        $this->service()->requestActivation($investor, $this->admin(), $this->now());
    }

    public function testQueuesFromBot(): void
    {
        Queue::fake();
        $investor = $this->investor();
        $this->subscription($investor);
        $this->optin($investor);
        $request = $this->service()->requestActivation($investor, $this->admin(), $this->now());
        self::assertSame('5575992605555', $request->bot_phone_number_id);
        Queue::assertPushed(SendActivationTemplate::class, 1);
    }

    public function testWebhookRequiresValidSignatureAndDeduplicates(): void
    {
        $investor = $this->investor();
        $payload = $this->webhookPayload('wamid-1', $investor->phone_e164);
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $headers = fn (string $signature): array => [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ];

        $this->call('POST', '/api/v1/webhooks/whatsapp-bot', [], [], [], $headers('sha256=invalido'), $raw)
            ->assertStatus(401);

        $signature = 'sha256='.hash_hmac('sha256', $raw, 'webhook-secret');
        $this->call('POST', '/api/v1/webhooks/whatsapp-bot', [], [], [], $headers($signature), $raw)
            ->assertOk()
            ->assertJsonPath('statuses.wamid-1', 'stored');
        $this->call('POST', '/api/v1/webhooks/whatsapp-bot', [], [], [], $headers($signature), $raw)
            ->assertJsonPath('statuses.wamid-1', 'duplicate');
    }

    public function testOptOutRevokesConsent(): void
    {
        $investor = $this->investor();
        $this->optin($investor);
        $now = $this->now();

        app(\App\Domain\WhatsApp\WebhookProcessor::class)->process(
            'stop-1',
            $this->webhookPayload('stop-1', $investor->phone_e164, 'STOP'),
            $now,
        );

        self::assertFalse(app(\App\Domain\WhatsApp\OptInPolicy::class)->isEligible($investor->fresh(), $now));
    }

    /** @return array<string, mixed> */
    private function webhookPayload(string $id, string $phone, string $body = 'Ola'): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '5575992605555'],
                        'messages' => [[
                            'id' => $id,
                            'from' => ltrim($phone, '+'),
                            'type' => 'text',
                            'text' => ['body' => $body],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function service(): ActivationService
    {
        return app(ActivationService::class);
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-17T12:00:00+00:00');
    }

    private function admin(): AdminUser
    {
        if ($owner = AdminUser::query()->where('active', true)->first()) {
            return $owner;
        }

        return AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'o'.Str::random(6).'@x.test',
            'password_hash' => Hash::make('x'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => false,
            'active' => true,
        ]);
    }

    private function investor(): Investor
    {
        return Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $this->admin()->id,
            'full_name' => 'Teste',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'i'.Str::random(6).'@x.test',
        ]);
    }

    private function subscription(Investor $investor): Subscription
    {
        return Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'plan_code' => 'monthly',
            'status' => 'active',
            'access_status' => 'active',
            'due_at' => new DateTimeImmutable('2026-12-01T12:00:00+00:00'),
        ]);
    }

    private function optin(Investor $investor): WhatsAppOptin
    {
        return WhatsAppOptin::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'idempotency_key' => Str::random(20),
            'phone_e164' => $investor->phone_e164,
            'evidence_uri' => 'file:///evidence',
            'accepted_at' => new DateTimeImmutable('2026-11-01T12:00:00+00:00'),
        ]);
    }
}
