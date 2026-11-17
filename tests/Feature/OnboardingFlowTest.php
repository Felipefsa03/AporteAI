<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Onboarding\AdvisoryAuthorization;
use App\Domain\Onboarding\ComplianceException;
use App\Domain\Onboarding\OnboardingService;
use App\Models\AdvisoryContract;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Investor;
use App\Models\SuitabilityAssessment;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    public function testA1OnboardingResumesAndIsIdempotent(): void
    {
        $investor = $this->investor();
        $service = app(OnboardingService::class);
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

        $session = $service->start($investor, $now);
        self::assertSame('identity', $session->step);

        $first = $service->answer($session, 'key-1', ['phone_confirmed' => true], $now);
        self::assertSame('terms', $first['session']->step);

        $resumed = $service->start($investor, $now);
        self::assertSame($session->id, $resumed->id);
        self::assertSame('terms', $resumed->step);

        $repeat = $service->answer($resumed, 'key-1', ['phone_confirmed' => true], $now);
        self::assertSame($first['reply'], $repeat['reply']);
        self::assertSame('terms', $repeat['session']->step);

        $terms = $service->answer($resumed, 'key-2', [
            'artifact_uri' => 'file:///contracts/v1.pdf',
            'privacy_notice_uri' => 'file:///privacy/v1.pdf',
        ], $now);
        self::assertSame('suitability', $terms['session']->step);
        self::assertSame(1, AdvisoryContract::query()->where('investor_id', $investor->id)->max('version'));

        $done = $service->answer($terms['session'], 'key-3', $this->answers(), $now);
        self::assertSame('completed', $done['session']->status);
        self::assertSame(1, SuitabilityAssessment::query()->where('investor_id', $investor->id)->max('version'));
        self::assertSame('moderate', SuitabilityAssessment::query()->first()->classification);
    }

    public function testA3UnauthorizedStructureBlocksAnyInstrumentRecommendation(): void
    {
        config()->set('compliance.advisory_authorized', false);
        $assessment = SuitabilityAssessment::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $this->investor()->id,
            'version' => 1,
            'answers' => $this->answers(),
            'classification' => 'moderate',
            'status' => 'valid',
            'assessed_at' => new DateTimeImmutable('2026-11-01T12:00:00+00:00'),
            'expires_at' => new DateTimeImmutable('2027-05-01T12:00:00+00:00'),
        ]);
        $authorization = app(AdvisoryAuthorization::class);
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

        self::assertFalse($authorization->canRecommend($assessment, $now));

        try {
            $authorization->assertCanRecommend($assessment, $now);
            self::fail('Recomendação deveria ter sido bloqueada.');
        } catch (ComplianceException) {
            self::assertTrue(AuditEvent::query()->where('action', 'compliance.recommendation_blocked')->exists());
        }
    }

    public function testIncompleteSuitabilityCannotBeStoredAsValid(): void
    {
        $investor = $this->investor();
        $service = app(OnboardingService::class);
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $session = $service->start($investor, $now);
        $service->answer($session, 'k1', ['phone_confirmed' => true], $now);
        $terms = $service->answer($session, 'k2', [
            'artifact_uri' => 'file:///c.pdf',
            'privacy_notice_uri' => 'file:///p.pdf',
        ], $now);

        $this->expectException(ComplianceException::class);
        $service->answer($terms['session'], 'k3', ['objective' => 'growth'], $now);
    }

    /** @return array<string, mixed> */
    private function answers(): array
    {
        return [
            'objective' => 'growth',
            'horizon' => 'long',
            'knowledge' => 'intermediate',
            'liquidity' => 'monthly',
            'restrictions' => [],
        ];
    }

    private function investor(): Investor
    {
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'owner-'.Str::random(6).'@example.test',
            'password_hash' => Hash::make('x'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => false,
            'active' => true,
        ]);

        return Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $admin->id,
            'full_name' => 'João Lima',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'joao-'.Str::random(6).'@example.test',
        ]);
    }
}
