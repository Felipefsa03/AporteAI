<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Privacy\DataRightsException;
use App\Domain\Privacy\DataRightsService;
use App\Domain\Privacy\RetentionExecutionService;
use App\Domain\Review\ReviewService;
use App\Models\AdminSession;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\DataRightsRequest;
use App\Models\Investor;
use App\Models\ReviewCase;
use App\Models\SuitabilityAssessment;
use App\Models\Subscription;
use App\Models\WhatsAppOptin;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RetentionExecutionTest extends TestCase
{
    use RefreshDatabase;

    public function testDryRunSimulatesWithoutApprovedPolicyAndKeepsAllData(): void
    {
        config()->set('retention.policy.approved', false);
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $admin = $this->admin();
        $investor = $this->investor($admin);
        $request = app(DataRightsService::class)->requestDeletion($investor, 'titular solicitou', $now);

        $report = app(RetentionExecutionService::class)->execute($request, true, $now);

        self::assertSame('simulated', $report['status']);
        self::assertTrue($report['dry_run']);
        self::assertFalse($report['policy_approved']);
        self::assertContains('audit', $report['retained_categories']);
        self::assertContains('billing', $report['retained_categories']);
        self::assertContains('identity', $report['anonymized_categories']);
        self::assertSame('Helena Martins', $investor->fresh()->full_name);
        self::assertNull($investor->fresh()->deleted_at);
        self::assertSame('pending', $request->fresh()->status);
        self::assertTrue(AuditEvent::query()->where('action', 'data_rights.retention_simulated')->exists());
        $this->assertNoPiiInAudit($investor);
    }

    public function testApprovedPolicyExecutesIdempotentlyWithHumanReviewAndLegalHold(): void
    {
        config()->set('retention.policy.approved', true);
        config()->set('retention.legal_hold.active', true);
        config()->set('retention.legal_hold.categories', ['contract', 'portfolio', 'billing', 'audit']);
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $admin = $this->admin();
        $investor = $this->investor($admin);
        $originalEmail = $investor->email;
        $originalPhone = $investor->phone_e164;
        $assessment = $this->assessment($investor);
        $optin = $this->optin($investor);
        $subscription = $this->subscription($investor);

        $request = app(DataRightsService::class)->requestDeletion($investor, 'titular solicitou', $now);
        $this->resolveReview($request, $admin, $now);

        $report = app(RetentionExecutionService::class)->execute($request, false, $now);

        self::assertSame('executed', $report['status']);
        self::assertFalse($report['dry_run']);
        self::assertContains('identity', $report['anonymized_categories']);
        self::assertContains('suitability', $report['anonymized_categories']);
        self::assertContains('audit', $report['retained_categories']);
        self::assertContains('contract', $report['retained_categories']);
        self::assertContains('billing', $report['retained_categories']);

        $fresh = $investor->fresh();
        self::assertSame('TITULAR-ANONIMIZADO', $fresh->full_name);
        self::assertStringEndsWith('@invalid.test', $fresh->email);
        self::assertNotSame($originalEmail, $fresh->email);
        self::assertNotSame($originalPhone, $fresh->phone_e164);
        self::assertSame('anonymized', $fresh->lifecycle_status);
        self::assertNotNull($fresh->deleted_at);
        self::assertSame([], $assessment->fresh()->answers);
        self::assertSame('anonymized', $assessment->fresh()->status);
        self::assertSame($optin->phone_e164, $optin->fresh()->phone_e164);
        self::assertSame('executed_under_approved_policy', $request->fresh()->reason);
        self::assertNotNull($request->fresh()->resolved_at);
        self::assertSame('suspended_nonpayment', $subscription->fresh()->access_status);
        self::assertTrue($report['billing']['unchanged_by_retention']);
        self::assertSame(1, AuditEvent::query()->where('action', 'data_rights.retention_executed')->count());
        $this->assertNoPiiInAudit($investor, [$originalEmail, $originalPhone, 'Helena Martins', 'médica']);

        $replay = app(RetentionExecutionService::class)->execute($request, false, $now);

        self::assertSame('already_executed', $replay['status']);
        self::assertTrue($replay['replayed']);
        self::assertSame($fresh->email, $investor->fresh()->email);
        self::assertSame(1, AuditEvent::query()->where('action', 'data_rights.retention_executed')->count());
        self::assertSame(1, AuditEvent::query()->where('action', 'data_rights.retention_replay_ignored')->count());
    }

    public function testExecutionRequiresResolvedHumanReview(): void
    {
        config()->set('retention.policy.approved', true);
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $investor = $this->investor($this->admin());
        $request = app(DataRightsService::class)->requestDeletion($investor, 'titular solicitou', $now);

        $this->expectException(DataRightsException::class);
        $this->expectExceptionMessage('revisão humana');

        app(RetentionExecutionService::class)->execute($request, false, $now);
    }

    public function testLegalHoldRetainsIdentityEvenWithApprovedPolicy(): void
    {
        config()->set('retention.policy.approved', true);
        config()->set('retention.legal_hold.active', true);
        config()->set('retention.legal_hold.categories', ['identity', 'contract', 'portfolio', 'billing', 'audit']);
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $admin = $this->admin();
        $investor = $this->investor($admin);

        $request = app(DataRightsService::class)->requestDeletion($investor, 'titular solicitou', $now);
        $this->resolveReview($request, $admin, $now);

        $report = app(RetentionExecutionService::class)->execute($request, false, $now);

        self::assertContains('identity', $report['retained_categories']);
        self::assertNotContains('identity', $report['anonymized_categories']);
        self::assertSame('Helena Martins', $investor->fresh()->full_name);
        self::assertNull($investor->fresh()->deleted_at);
    }

    public function testCrossScopeExportAndCorrectionAreDenied(): void
    {
        $ownerA = $this->admin();
        $ownerB = $this->admin();
        $investorA = $this->investor($ownerA);
        $investorB = $this->investor($ownerB);
        $tokenA = $this->adminSessionToken($ownerA);
        $tokenB = $this->adminSessionToken($ownerB);

        $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->getJson('/api/v1/admin/clients/'.$investorA->id.'/data/export')
            ->assertStatus(403);

        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer '.$tokenB)
            ->postJson('/api/v1/admin/clients/'.$investorA->id.'/data/correction', [
                'field' => 'profession',
                'value' => 'engenheira',
            ])
            ->assertStatus(403);

        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->getJson('/api/v1/admin/clients/'.$investorA->id.'/data/export')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');

        $this->flushHeaders();
        $this->withHeader('Authorization', 'Bearer '.$tokenA)
            ->postJson('/api/v1/admin/clients/'.$investorA->id.'/data/correction', [
                'field' => 'profession',
                'value' => 'engenheira',
            ])
            ->assertOk()
            ->assertJson(['updated_field' => 'profession']);

        self::assertSame('engenheira', $investorA->fresh()->profession);
        self::assertNotSame('engenheira', $investorB->fresh()->profession);
    }

    private function resolveReview(DataRightsRequest $request, AdminUser $admin, DateTimeImmutable $now): void
    {
        $case = ReviewCase::query()
            ->where('subject_type', 'data_rights_request')
            ->where('subject_id', $request->id)
            ->firstOrFail();

        app(ReviewService::class)->resolve($case, $admin, 'deletion_approved', 'Política aprovada pelo operador', $now);
    }

    /** @param list<string> $extra */
    private function assertNoPiiInAudit(Investor $investor, array $extra = []): void
    {
        $serialized = json_encode(AuditEvent::query()->get()->toArray(), JSON_THROW_ON_ERROR);
        foreach (array_merge([
            $investor->full_name,
            $investor->email,
            $investor->phone_e164,
            (string) $investor->profession,
        ], $extra) as $needle) {
            if ($needle !== '') {
                self::assertStringNotContainsString($needle, $serialized);
            }
        }
    }

    private function admin(string $role = 'owner'): AdminUser
    {
        /** @var AdminUser $admin */
        $admin = (new AdminUser())->forceFill([
            'id' => (string) Str::uuid(),
            'email' => 'owner-'.Str::random(8).'@example.test',
            'password_hash' => Hash::make('x'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => false,
            'active' => true,
            'role' => $role,
        ]);
        $admin->save();

        return $admin;
    }

    private function adminSessionToken(AdminUser $admin): string
    {
        $token = Str::random(64);
        AdminSession::query()->create([
            'id' => (string) Str::uuid(),
            'admin_user_id' => $admin->id,
            'token_hash' => hash('sha256', $token),
            'mfa_completed_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        return $token;
    }

    private function investor(AdminUser $admin): Investor
    {
        return Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $admin->id,
            'full_name' => 'Helena Martins',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'helena-'.Str::random(8).'@example.test',
            'profession' => 'médica',
        ]);
    }

    private function assessment(Investor $investor): SuitabilityAssessment
    {
        return SuitabilityAssessment::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'version' => 1,
            'answers' => ['objective' => 'aposentadoria', 'income' => 'privado'],
            'classification' => 'moderado',
            'status' => 'complete',
        ]);
    }

    private function optin(Investor $investor): WhatsAppOptin
    {
        return WhatsAppOptin::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'idempotency_key' => 'optin-'.(string) Str::uuid(),
            'phone_e164' => $investor->phone_e164,
            'evidence_uri' => 'https://consent.example.test/'.(string) Str::uuid(),
            'accepted_at' => new DateTimeImmutable('2026-11-01T12:00:00+00:00'),
        ]);
    }

    private function subscription(Investor $investor): Subscription
    {
        return Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'plan_code' => 'monthly',
            'status' => 'active',
            'access_status' => 'suspended_nonpayment',
            'started_at' => new DateTimeImmutable('2026-11-01T12:00:00+00:00'),
        ]);
    }
}
