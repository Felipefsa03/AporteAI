<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Review\ReviewException;
use App\Domain\Review\ReviewService;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\Investor;
use App\Models\PortfolioEvent;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HumanReviewTest extends TestCase
{
    use RefreshDatabase;

    public function testA8CriticalCaseStoresSnapshotAndRequiresAuthorizedRoleAndReason(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $investor = $this->investor();
        $viewer = new AdminUser(['role' => 'viewer']);
        $owner = $this->admin('owner');
        $service = app(ReviewService::class);

        $case = $service->openCritical(
            $investor,
            'allocation',
            (string) Str::uuid(),
            'source_stale',
            ['proposal' => ['bps' => 10000]],
            [['source' => 'bcb/selic', 'as_of' => '2026-11-17T10:00:00+00:00']],
            ['rule_version' => 'allocation-2026.11-v1'],
            $now,
        );

        self::assertSame('pending', $case->status);
        self::assertTrue($case->critical);
        self::assertSame('source_stale', $case->reason);
        self::assertSame(['proposal' => ['bps' => 10000]], $case->snapshot);
        self::assertCount(1, $case->sources);
        self::assertSame('allocation-2026.11-v1', $case->rules['rule_version']);

        try {
            $service->resolve($case, $viewer, 'block_confirmed', 'motivo', $now);
            self::fail('Papel não autorizado resolveu caso crítico.');
        } catch (ReviewException $exception) {
            self::assertStringContainsString('autorização', $exception->getMessage());
        }

        try {
            $service->resolve($case, $owner, 'release', 'liberar', $now);
            self::fail('Decisão humana burlou bloqueio crítico.');
        } catch (ReviewException $exception) {
            self::assertStringContainsString('não pode liberar', $exception->getMessage());
        }

        $resolved = $service->resolve($case, $owner, 'block_confirmed', 'fonte vencida confirmada', $now);

        self::assertSame('resolved', $resolved->status);
        self::assertSame('block_confirmed', $resolved->resolution_reason);
        self::assertSame('fonte vencida confirmada', $resolved->justification);
        self::assertSame($owner->id, $resolved->resolved_by_admin_id);
        self::assertTrue(AuditEvent::query()->where('action', 'review.case_opened')->exists());
        self::assertTrue(AuditEvent::query()->where('action', 'review.case_resolved')->exists());
        self::assertSame(0, PortfolioEvent::query()->count());
    }

    public function testPendingCriticalCaseBlocksExecution(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $case = app(ReviewService::class)->openCritical(
            $this->investor(),
            'billing_gate',
            (string) Str::uuid(),
            'suspended_nonpayment',
            [],
            [],
            [],
            $now,
        );

        $this->expectException(ReviewException::class);
        $this->expectExceptionMessage('nenhuma ordem pode ser executada');
        app(ReviewService::class)->assertNoPendingBlock($case);
    }

    private function admin(string $role): AdminUser
    {
        if ($owner = AdminUser::query()->where('active', true)->first()) {
            return $owner;
        }

        return AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => $role.'-'.Str::random(6).'@example.test',
            'role' => $role,
            'password_hash' => Hash::make('x'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => false,
            'active' => true,
        ]);
    }

    private function investor(): Investor
    {
        $owner = $this->admin('owner');

        return Investor::query()->create([
            'id' => (string) Str::uuid(),
            'created_by_admin_id' => $owner->id,
            'full_name' => 'Paula Lima',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'paula-'.Str::random(6).'@example.test',
        ]);
    }
}
