<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Conversation\LlmGateway;
use App\Domain\WhatsApp\MetaCloudApiGateway;
use App\Domain\WhatsApp\MockWhatsAppGateway;
use App\Domain\WhatsApp\WebhookEventParser;
use App\Domain\WhatsApp\WhatsAppGateway;
use App\Domain\WhatsApp\WhatsAppOutboundService;
use App\Domain\WhatsApp\WhatsAppTransientException;
use App\Jobs\DispatchWhatsAppOutbox;
use App\Jobs\SendWhatsAppOutbox;
use App\Models\AdminUser;
use App\Models\Investor;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppDeadLetter;
use App\Models\WhatsAppDeliveryReceipt;
use App\Models\WhatsAppInboxEvent;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppOptin;
use App\Models\WhatsAppOutboxMessage;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class WhatsAppTransportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('whatsapp.webhook.app_secret', 'webhook-secret');
        config()->set('whatsapp.webhook.verify_token', 'verify-secret');
        config()->set('whatsapp.cloud_api.mock', true);
        $this->app->instance(WhatsAppGateway::class, new MockWhatsAppGateway());
    }

    public function testVerifyReturnsPlainTextChallengeOnBothWebhookRoutes(): void
    {
        foreach (['/api/v1/webhooks/whatsapp-bot/verify', '/api/v1/webhooks/whatsapp-bot'] as $uri) {
            $query = http_build_query([
                'hub_mode' => 'subscribe',
                'hub_verify_token' => 'verify-secret',
                'hub_challenge' => 'desafio-123',
            ]);

            $this->get($uri.'?'.$query)
                ->assertOk()
                ->assertHeader('Content-Type', 'text/plain; charset=utf-8')
                ->assertContent('desafio-123');
        }

        $this->get('/api/v1/webhooks/whatsapp-bot?'.http_build_query([
            'hub_mode' => 'subscribe',
            'hub_verify_token' => 'errado',
            'hub_challenge' => 'desafio-123',
        ]))->assertForbidden()->assertContent('invalid verification');
    }

    public function testRawBodyHmacIsMandatoryAndRejectsTamperedPayload(): void
    {
        $payload = $this->messagePayload('wamid-hmac', 'Ola');
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = 'sha256='.hash_hmac('sha256', $raw, 'webhook-secret');

        $this->signedPost($raw, 'sha256=invalido')->assertUnauthorized();
        self::assertSame(0, WhatsAppInboxEvent::query()->count());

        $this->signedPost($raw, $signature)
            ->assertOk()
            ->assertJsonPath('statuses.wamid-hmac', 'stored');
        self::assertSame(1, WhatsAppInboxEvent::query()->count());

        $tampered = json_encode($this->messagePayload('wamid-hmac', 'Texto alterado'), JSON_THROW_ON_ERROR);
        $this->signedPost($tampered, $signature)->assertUnauthorized();
        self::assertSame(1, WhatsAppInboxEvent::query()->count());
        self::assertSame(1, WhatsAppMessage::query()->count());
    }

    public function testWebhookParsesEveryEntryChangeMessageAndStatusWithoutCallingGateway(): void
    {
        Queue::fake();
        $gateway = $this->gateway();
        $this->bindGateway($gateway);

        $investor = $this->investor();
        $payload = [
            'entry' => [
                [
                    'id' => 'entry-1',
                    'changes' => [[
                        'field' => 'messages',
                        'value' => [
                            'metadata' => ['phone_number_id' => '5575992605555'],
                            'messages' => [
                                $this->message('wamid-e1-m1', $investor, 'Ola 1'),
                                $this->message('wamid-e1-m2', $investor, 'Ola 2'),
                            ],
                            'statuses' => [$this->statusEvent('wamid-e1-s1', $investor, 'sent', '1700000001')],
                        ],
                    ]],
                ],
                [
                    'id' => 'entry-2',
                    'changes' => [[
                        'field' => 'messages',
                        'value' => [
                            'metadata' => ['phone_number_id' => '5575992605555'],
                            'messages' => [$this->message('wamid-e2-m1', $investor, 'Ola 3')],
                            'statuses' => [
                                $this->statusEvent('wamid-e2-s1', $investor, 'delivered', '1700000002'),
                                $this->statusEvent('wamid-e2-s2', $investor, 'read', '1700000003'),
                            ],
                        ],
                    ]],
                ],
            ],
        ];

        $response = $this->postWebhook($payload);

        $response->assertOk();
        foreach (['wamid-e1-m1', 'wamid-e1-m2', 'wamid-e1-s1', 'wamid-e2-m1', 'wamid-e2-s1', 'wamid-e2-s2'] as $id) {
            $response->assertJsonPath('statuses.'.$id, fn (mixed $value): bool => is_string($value) && $value !== '');
        }
        self::assertSame(6, WhatsAppInboxEvent::query()->count());
        self::assertSame(3, WhatsAppMessage::query()->count());
        self::assertSame(3, WhatsAppDeliveryReceipt::query()->count());
        self::assertSame([], $gateway->calls);
        Queue::assertPushed(DispatchWhatsAppOutbox::class, 1);
    }

    public function testReplayAndCompetingClaimCannotCreateDuplicateEffects(): void
    {
        Queue::fake();
        $investor = $this->investor();
        $payload = $this->messagePayload('wamid-replay', 'Ola', $investor);
        $now = new DateTimeImmutable('2026-11-19T12:00:00+00:00');

        $this->postWebhook($payload)
            ->assertOk()
            ->assertJsonPath('statuses.wamid-replay', 'stored');
        $this->postWebhook($payload)
            ->assertOk()
            ->assertJsonPath('statuses.wamid-replay', 'duplicate');

        self::assertSame(1, WhatsAppInboxEvent::query()->count());
        self::assertSame(1, WhatsAppMessage::query()->count());

        $directPayload = $this->messagePayload('wamid-direct-claim', 'Ola direto', $investor);
        $event = app(WebhookEventParser::class)->parse($directPayload, $now)[0];
        $deduplicator = app(\App\Domain\WhatsApp\WebhookDeduplicator::class);
        self::assertNotNull($deduplicator->claim($event, $now));
        self::assertNull($deduplicator->claim($event, $now));
        self::assertSame(2, WhatsAppInboxEvent::query()->count());
    }

    public function testCrashBeforeRelayLeavesRecoverableOutboxAndCommandDispatchesIt(): void
    {
        Queue::fake();
        $investor = $this->investor();
        $this->optin($investor);

        $this->postWebhook($this->messagePayload('wamid-crash', 'Meu plano', $investor))
            ->assertOk()
            ->assertJsonPath('statuses.wamid-crash', 'stored');

        $outbox = WhatsAppOutboxMessage::query()->firstOrFail();
        self::assertSame('queued', $outbox->status);
        self::assertSame('5575992605555', $outbox->bot_phone_number_id);
        self::assertNotNull($outbox->inbox_event_id);
        Queue::assertPushed(DispatchWhatsAppOutbox::class, 1);

        $this->artisan('whatsapp:dispatch-outbox')->assertSuccessful();
        Queue::assertPushed(SendWhatsAppOutbox::class, 1);
    }

    public function testTransient429UsesBackoffAndThenSucceeds(): void
    {
        $calls = 0;
        $gateway = $this->gateway(function () use (&$calls): string {
            ++$calls;
            if ($calls === 1) {
                throw new WhatsAppTransientException('rate limited', 429, 7);
            }

            return 'provider-429-ok';
        });
        $this->bindGateway($gateway);

        $now = new DateTimeImmutable('2026-11-19T12:00:00+00:00');
        [$service, $outbox] = $this->queuedText($now);

        try {
            $service->deliver($outbox->id, $now);
            self::fail('A primeira entrega devia lançar falha transitória.');
        } catch (WhatsAppTransientException $exception) {
            self::assertSame(429, $exception->httpStatus);
        }

        $outbox->refresh();
        self::assertSame('queued', $outbox->status);
        self::assertSame(1, $outbox->attempts);
        self::assertSame('transient:429', $outbox->last_error);
        self::assertSame(
            $now->modify('+7 seconds')->getTimestamp(),
            $outbox->available_at->getTimestamp(),
        );

        $delivered = $service->deliver($outbox->id, $now->modify('+8 seconds'));
        self::assertSame('sent', $delivered->status);
        self::assertSame('provider-429-ok', $delivered->provider_message_id);
        self::assertSame(2, $calls);

        $job = new SendWhatsAppOutbox($outbox->id);
        self::assertSame((int) config('whatsapp.delivery.tries'), $job->tries);
        self::assertSame([10, 30, 60, 120, 300], $job->backoff());
    }

    public function testMetaGatewayRetriesHttp500AndTransientProviderCodes(): void
    {
        Http::preventStrayRequests();
        config()->set('whatsapp.cloud_api.access_token', 'test-token');
        $this->bindGateway(new MetaCloudApiGateway());

        Http::fakeSequence()
            ->push(['error' => ['error_data' => [['code' => 131000]]]], 500)
            ->push(['messages' => [['id' => 'meta-ok-1']]], 200)
            ->push(['error' => ['error_data' => [['code' => 80007]]]], 400)
            ->push(['messages' => [['id' => 'meta-ok-2']]], 200);

        $now = new DateTimeImmutable('2026-11-19T12:00:00+00:00');
        [$service, $outbox] = $this->queuedText($now);

        try {
            $service->deliver($outbox->id, $now);
            self::fail('HTTP 500 devia ser transitório.');
        } catch (WhatsAppTransientException $exception) {
            self::assertSame(500, $exception->httpStatus);
        }

        $first = $service->deliver($outbox->id, $now->modify('+11 seconds'));
        self::assertSame('sent', $first->status);
        self::assertSame('meta-ok-1', $first->provider_message_id);

        [, $retryOutbox] = $this->queuedText($now);

        try {
            $service->deliver($retryOutbox->id, $now->modify('+12 seconds'));
            self::fail('Código Meta 80007 devia ser transitório.');
        } catch (WhatsAppTransientException $exception) {
            self::assertSame(400, $exception->httpStatus);
        }

        $second = $service->deliver($retryOutbox->id, $now->modify('+30 seconds'));
        self::assertSame('meta-ok-2', $second->provider_message_id);
        Http::assertSentCount(4);
    }

    public function testExhaustedRetriesGoToDeadLetterQueueOnce(): void
    {
        $gateway = $this->gateway(function (): never {
            throw new WhatsAppTransientException('sempre falha', 503, 1);
        });
        $this->bindGateway($gateway);

        $now = new DateTimeImmutable('2026-11-19T12:00:00+00:00');
        [$service, $outbox] = $this->queuedText($now);
        $outbox->forceFill(['attempts' => 5])->save();

        $exception = new WhatsAppTransientException('retry exhaust', 503, 1);
        $service->deadLetter($outbox->id, $exception);
        $service->deadLetter($outbox->id, $exception);

        self::assertSame('dead', $outbox->fresh()->status);
        self::assertSame(1, WhatsAppDeadLetter::query()->count());
        $dead = WhatsAppDeadLetter::query()->firstOrFail();
        self::assertSame('outbox:'.$outbox->id, $dead->dedupe_key);
        self::assertSame(5, $dead->attempts);
        self::assertSame('retry exhaust', $dead->reason);
    }

    public function testTextAndPngAreSentOnlyFromBotNumber(): void
    {
        $gateway = $this->gateway();
        $this->bindGateway($gateway);

        $investor = $this->investor();
        $this->optin($investor);
        $now = new DateTimeImmutable('2026-11-19T12:00:00+00:00');
        $conversation = $this->conversation($investor, $now);
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8AAAwAB/wFj7WkAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($png);

        $service = app(WhatsAppOutboundService::class);
        $text = $service->queueText($investor, 'Segue o relatorio', $now, $conversation);
        $image = $service->queueImageBytes($investor, $png, $now, 'Relatorio PNG', $conversation, 'png-1');

        self::assertSame('sent', $service->deliver($text->id, $now)->status);
        self::assertSame('sent', $service->deliver($image->id, $now)->status);

        self::assertCount(2, $gateway->calls);
        self::assertSame('text', $gateway->calls[0][0]);
        self::assertSame($investor->phone_e164, $gateway->calls[0][1]['phone']);
        self::assertSame('5575992605555', $gateway->calls[0][1]['bot']);
        self::assertSame('image-bytes', $gateway->calls[1][0]);
        self::assertSame($png, $gateway->calls[1][1]['bytes']);
        self::assertSame('Relatorio PNG', $gateway->calls[1][1]['caption']);
        self::assertSame(['5575992605555', '5575992605555'], array_column(array_column($gateway->calls, 1), 'bot'));
        self::assertSame([$investor->phone_e164, $investor->phone_e164], array_column(array_column($gateway->calls, 1), 'phone'));
    }

    public function testDeliveryReceiptsAdvanceOutboxAndAreDeduplicated(): void
    {
        $gateway = $this->gateway(function (): string {
            return 'provider-receipt-1';
        });
        $this->bindGateway($gateway);

        $investor = $this->investor();
        $this->optin($investor);
        $now = new DateTimeImmutable('2026-11-19T12:00:00+00:00');
        $conversation = $this->conversation($investor, $now);
        $service = app(WhatsAppOutboundService::class);
        $outbox = $service->queueText($investor, 'Confirmacao', $now, $conversation);
        self::assertSame('sent', $service->deliver($outbox->id, $now)->status);
        $providerId = (string) $outbox->fresh()->provider_message_id;

        $expected = [['sent', 'duplicate'], ['delivered', 'delivered'], ['read', 'read']];
        foreach ($expected as $index => [$providerStatus, $expectedStatus]) {
            $this->postWebhook([
                'entry' => [[
                    'changes' => [[
                        'value' => [
                            'metadata' => ['phone_number_id' => '5575992605555'],
                            'statuses' => [$this->statusEvent($providerId, $investor, $providerStatus, (string) (1700000010 + $index))],
                        ],
                    ]],
                ]],
            ])->assertOk()->assertJsonPath('statuses.'.$providerId, $expectedStatus);
        }

        self::assertSame(3, WhatsAppDeliveryReceipt::query()->count());
        self::assertSame('read', $outbox->fresh()->status);

        $this->postWebhook([
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '5575992605555'],
                        'statuses' => [$this->statusEvent($providerId, $investor, 'delivered', '1700000099')],
                    ],
                ]],
            ]],
        ])->assertOk()->assertJsonPath('statuses.'.$providerId, 'duplicate');

        self::assertSame(3, WhatsAppDeliveryReceipt::query()->count());
        self::assertSame('read', $outbox->fresh()->status);
    }

    public function testOptOutWindowAndTemplatePoliciesBlockOutbound(): void
    {
        $now = new DateTimeImmutable('2026-11-19T12:00:00+00:00');
        $service = app(WhatsAppOutboundService::class);

        $optedOut = $this->investor();
        $this->optin($optedOut);
        $this->conversation($optedOut, $now);
        $this->postWebhook($this->messagePayload('wamid-stop', 'STOP', $optedOut))
            ->assertOk()
            ->assertJsonPath('statuses.wamid-stop', 'opt_out');
        self::assertTrue(WhatsAppOptin::query()
            ->where('investor_id', $optedOut->id)
            ->whereNotNull('revoked_at')
            ->exists());
        $blockedOptOut = $service->queueText($optedOut, 'Ola', $now);
        self::assertSame('blocked', $blockedOptOut->status);
        self::assertSame('opt_out', $blockedOptOut->last_error);

        $stale = $this->investor();
        $this->optin($stale);
        $this->conversation($stale, $now->modify('-25 hours'));
        $blockedWindow = $service->queueText($stale, 'Ola', $now);
        self::assertSame('blocked', $blockedWindow->status);
        self::assertSame('window_closed', $blockedWindow->last_error);

        $allowed = $this->investor();
        $this->optin($allowed);
        $template = $service->queueTemplate($allowed, 'aporteai_activation_v1', '5575992605555', $now, (string) Str::uuid());
        self::assertSame('queued', $template->status);
        self::assertSame('5575992605555', $template->bot_phone_number_id);

        $blockedTemplate = $service->queueTemplate($allowed, 'template_nao_aprovado', '5575992605555', $now);
        self::assertSame('blocked', $blockedTemplate->status);
        self::assertSame('template_not_allowed', $blockedTemplate->last_error);

        $withoutOptin = $this->investor();
        $blockedOptin = $service->queueTemplate($withoutOptin, 'aporteai_activation_v1', '5575992605555', $now);
        self::assertSame('blocked', $blockedOptin->status);
        self::assertSame('opt_in_required', $blockedOptin->last_error);
    }

    public function testHumanHandoffUsesCommercialNumberAndSkipsLlm(): void
    {
        $llm = new class implements LlmGateway
        {
            public int $calls = 0;

            public function complete(string $prompt): string
            {
                ++$this->calls;

                return 'NAO_DEVE_SER_CHAMADO';
            }
        };
        $this->app->instance(LlmGateway::class, $llm);
        app()->forgetInstance(\App\Domain\Conversation\ConversationOrchestrator::class);

        $investor = $this->investor();
        $response = $this->postWebhook($this->messagePayload('wamid-handoff', 'ATENDENTE', $investor));

        $response->assertOk()
            ->assertJsonPath('statuses.wamid-handoff', 'human_handoff')
            ->assertJsonPath('handoff.number_e164', '+15416221559');
        self::assertSame(0, $llm->calls);
        $outbox = WhatsAppOutboxMessage::query()->firstOrFail();
        self::assertStringContainsString('+15416221559', (string) $outbox->payload['text']);
    }

    private function bindGateway(WhatsAppGateway $gateway): void
    {
        $this->app->instance(WhatsAppGateway::class, $gateway);
        app()->forgetInstance(WhatsAppOutboundService::class);
    }

    /** @return object{calls: array<int, array{0: string, 1: array<string, mixed>}>} */
    private function gateway(?callable $handler = null): WhatsAppGateway
    {
        return new class($handler) implements WhatsAppGateway
        {
            /** @var array<int, array{0: string, 1: array<string, mixed>}> */
            public array $calls = [];

            /** @param callable(string, array<string, mixed>): string|null $handler */
            public function __construct(private $handler)
            {
            }

            public function sendTemplate(string $phoneE164, string $templateName, string $botPhoneNumberId): string
            {
                return $this->send('template', [
                    'phone' => $phoneE164,
                    'template' => $templateName,
                    'bot' => $botPhoneNumberId,
                ]);
            }

            public function sendText(string $phoneE164, string $text, string $botPhoneNumberId): string
            {
                return $this->send('text', [
                    'phone' => $phoneE164,
                    'text' => $text,
                    'bot' => $botPhoneNumberId,
                ]);
            }

            public function sendImage(
                string $phoneE164,
                string $botPhoneNumberId,
                string $mediaIdOrUrl,
                ?string $caption = null,
            ): string {
                return $this->send('image', [
                    'phone' => $phoneE164,
                    'bot' => $botPhoneNumberId,
                    'media' => $mediaIdOrUrl,
                    'caption' => $caption,
                ]);
            }

            public function sendImageBytes(
                string $phoneE164,
                string $botPhoneNumberId,
                string $pngBytes,
                ?string $caption = null,
            ): string {
                return $this->send('image-bytes', [
                    'phone' => $phoneE164,
                    'bot' => $botPhoneNumberId,
                    'bytes' => $pngBytes,
                    'caption' => $caption,
                ]);
            }

            /** @param array<string, mixed> $args */
            private function send(string $method, array $args): string
            {
                $this->calls[] = [$method, $args];

                if ($this->handler !== null) {
                    return ($this->handler)($method, $args);
                }

                return 'provider-'.strtoupper($method).'-'.substr(hash('sha256', serialize($args)), 0, 16);
            }
        };
    }

    private function signedPost(string $raw, string $signature)
    {
        return $this->call('POST', '/api/v1/webhooks/whatsapp-bot', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
        ], $raw);
    }

    /** @param array<string, mixed> $payload */
    private function postWebhook(array $payload)
    {
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);

        return $this->signedPost($raw, 'sha256='.hash_hmac('sha256', $raw, 'webhook-secret'));
    }

    /** @return array<string, mixed> */
    private function messagePayload(string $id, string $body, ?Investor $investor = null): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '5575992605555'],
                        'messages' => [$this->message($id, $investor, $body)],
                    ],
                ]],
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function message(string $id, ?Investor $investor, string $body): array
    {
        return [
            'id' => $id,
            'from' => $investor === null ? '557599261234' : ltrim($investor->phone_e164, '+'),
            'timestamp' => '1700000000',
            'type' => 'text',
            'text' => ['body' => $body],
        ];
    }

    /** @return array<string, mixed> */
    private function statusEvent(string $id, Investor $investor, string $status, string $timestamp): array
    {
        return [
            'id' => $id,
            'recipient_id' => ltrim($investor->phone_e164, '+'),
            'status' => $status,
            'timestamp' => $timestamp,
        ];
    }

    /** @return array{0: WhatsAppOutboundService, 1: WhatsAppOutboxMessage} */
    private function queuedText(DateTimeImmutable $now): array
    {
        $investor = $this->investor();
        $this->optin($investor);
        $conversation = $this->conversation($investor, $now);
        $service = app(WhatsAppOutboundService::class);
        $outbox = $service->queueText($investor, 'Mensagem de teste', $now, $conversation);

        return [$service, $outbox];
    }

    private function investor(): Investor
    {
        $admin = AdminUser::query()->where('active', true)->first();
        if ($admin === null) {
            $admin = AdminUser::query()->create([
                'id' => (string) Str::uuid(),
                'email' => 'owner-'.Str::random(8).'@example.test',
                'password_hash' => Hash::make('secret'),
                'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
                'mfa_enabled' => false,
                'active' => true,
            ]);
        }

        return Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $admin->id,
            'full_name' => 'Investidor Teste',
            'phone_e164' => '+55759927'.Str::random(5),
            'email' => 'investidor-'.Str::random(8).'@example.test',
        ]);
    }

    private function optin(Investor $investor): WhatsAppOptin
    {
        return WhatsAppOptin::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'idempotency_key' => Str::random(24),
            'phone_e164' => $investor->phone_e164,
            'evidence_uri' => 'file:///evidence/'.Str::random(8),
            'accepted_at' => new DateTimeImmutable('2026-11-01T12:00:00+00:00'),
        ]);
    }

    private function conversation(Investor $investor, DateTimeImmutable $at): WhatsAppConversation
    {
        return WhatsAppConversation::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'bot_phone_number_id' => '5575992605555',
            'state' => 'active',
            'last_message_at' => $at,
            'last_inbound_at' => $at,
        ]);
    }
}
