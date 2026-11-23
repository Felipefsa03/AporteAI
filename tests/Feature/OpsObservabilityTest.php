<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendWhatsAppOutbox;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Investor;
use App\Models\WhatsAppDeadLetter;
use App\Models\WhatsAppOutboxMessage;
use App\Support\RedactingProcessor;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** OPS: observabilidade sem PII e reprocessamento de DLQ sem duplicação. */
final class OpsObservabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('ops.token', 'token-de-teste-ops');
    }

    public function testHealthEndpointsExigemTokenENaoExpoemRecomendacoes(): void
    {
        $this->get('/ops/health')->assertNotFound();

        $this->withHeaders(['X-Ops-Token' => 'token-de-teste-ops'])
            ->get('/ops/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('public_recommendations', false)
            ->assertJsonPath('targets.rpo_minutes', 15);

        $this->withHeaders(['X-Ops-Token' => 'token-de-teste-ops'])
            ->get('/ops/metrics')
            ->assertOk()
            ->assertSee('public_recommendations 0');
    }

    public function testAlertaSinteticoEGravadoSemPii(): void
    {
        $this->withHeaders(['X-Ops-Token' => 'token-de-teste-ops'])
            ->post('/ops/alerts/test', ['severity' => 'critical'])
            ->assertStatus(202)
            ->assertJsonPath('synthetic', true);

        $alert = AuditEvent::query()->where('action', 'ops.alert.synthetic')->firstOrFail();
        $this->assertTrue($alert->metadata['synthetic']);
        $this->assertSame('critical', $alert->metadata['severity']);

        $this->withHeaders(['X-Ops-Token' => 'token-de-teste-ops'])
            ->get('/ops/metrics')
            ->assertSee('ops_alerts_total_24h 1');
    }

    public function testProcessadorDeLogsRedigeSegredosEPii(): void
    {
        $processor = new RedactingProcessor();
        $record = $processor->redact([
            'message' => 'Bearer abc.def123 chamada de +5575992605555 por cliente@example.test',
            'context' => [
                'password' => 'segredo-local',
                'authorization' => 'Bearer outro-token',
                'safe' => 'operacao-ok',
                'nested' => ['phone_e164' => '+5575992605555'],
            ],
            'extra' => [],
        ]);

        $message = $record['message'];
        $this->assertStringNotContainsString('abc.def123', $message);
        $this->assertStringNotContainsString('+5575992605555', $message);
        $this->assertStringNotContainsString('cliente@example.test', $message);
        $this->assertSame('[REDACTED]', $record['context']['password']);
        $this->assertSame('[REDACTED]', $record['context']['nested']['phone_e164']);
        $this->assertSame('operacao-ok', $record['context']['safe']);
    }

    public function testReplayDeDeadLetterNaoDuplicaEntrega(): void
    {
        Queue::fake();
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'ops-owner@example.test',
            'password_hash' => Hash::make('senha-do-proprietario'),
            'mfa_secret_encrypted' => encrypt('SECRET'),
            'mfa_enabled' => false,
            'active' => true,
        ]);
        $investor = Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $admin->id,
            'full_name' => 'Piloto OPS',
            'phone_e164' => '+5575992699001',
            'email' => 'ops-investidor@example.test',
        ]);
        $outbox = WhatsAppOutboxMessage::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'idempotency_key' => 'ops:dry:1',
            'to_number_e164' => $investor->phone_e164,
            'bot_phone_number_id' => 'bot-ficticio',
            'type' => 'text',
            'payload' => ['text' => 'mensagem ficticia'],
            'status' => 'dead',
            'attempts' => 5,
            'available_at' => new DateTimeImmutable('2026-11-23T12:00:00+00:00'),
            'last_error' => 'provider_falho',
        ]);
        $deadLetter = WhatsAppDeadLetter::query()->create([
            'id' => (string) Str::uuid(),
            'dedupe_key' => 'outbox:'.$outbox->id,
            'reference_type' => 'outbox',
            'reference_id' => $outbox->id,
            'reason' => 'provider_falho',
            'attempts' => 5,
            'payload' => [],
            'failed_at' => new DateTimeImmutable('2026-11-23T12:00:00+00:00'),
        ]);

        $this->artisan('ops:replay-dead-letters')->assertSuccessful();
        $this->artisan('ops:replay-dead-letters')->assertSuccessful();

        Queue::assertPushed(SendWhatsAppOutbox::class, 1);
        $this->assertSame('queued', $outbox->fresh()->status);
        $this->assertSame(1, (int) $deadLetter->fresh()->replay_count);
        $this->assertNotNull($deadLetter->fresh()->replayed_at);
    }
}
