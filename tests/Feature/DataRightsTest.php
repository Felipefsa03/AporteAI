<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Privacy\DataRightsException;
use App\Domain\Privacy\DataRightsService;
use App\Models\AdminUser;
use App\Models\AuditEvent;
use App\Models\DataRightsRequest;
use App\Models\Investor;
use App\Models\Subscription;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DataRightsTest extends TestCase
{
    use RefreshDatabase;

    public function testA9ExportCorrectionAndDeletionFollowRetentionWithoutPiiInAudit(): void
    {
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');
        $investor = $this->investor();
        $service = app(DataRightsService::class);

        $export = $service->export($investor, $now);
        self::assertSame($investor->email, $export['investor_data']['email']);
        self::assertSame($investor->phone_e164, $export['investor_data']['phone_e164']);
        self::assertArrayNotHasKey('password_hash', $export);
        self::assertArrayNotHasKey('mfa_secret_encrypted', $export);

        $updated = $service->correct($investor, 'profession', 'engenheira', $now);
        self::assertSame('engenheira', $updated->profession);

        $request = $service->requestDeletion($investor, 'titular solicitou', $now);
        self::assertSame('deletion', $request->type);
        self::assertSame('pending', $request->status);
        self::assertSame('2027-02-15T12:00:00+00:00', $request->scheduled_for->format(DATE_ATOM));
        self::assertSame('deletion_requested', $investor->fresh()->lifecycle_status);
        self::assertNull($investor->fresh()->deleted_at);
        self::assertSame(1, DataRightsRequest::query()->count());

        $audit = AuditEvent::query()->orderBy('occurred_at')->get()->toArray();
        $serialized = json_encode($audit, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($investor->email, $serialized);
        self::assertStringNotContainsString($investor->phone_e164, $serialized);
        self::assertStringNotContainsString('engenheira', $serialized);
    }

    public function testA9OnlyConfiguredFieldsCanBeCorrected(): void
    {
        $service = app(DataRightsService::class);

        $this->expectException(DataRightsException::class);
        $service->correct($this->investor(), 'phone_e164', '+5575999999999', new DateTimeImmutable('2026-11-17T12:00:00+00:00'));
    }

    public function testA14RightsWorkDuringBillingBlockWithoutUnlockingPaidServices(): void
    {
        $investor = $this->investor();
        Subscription::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'plan_code' => 'monthly',
            'status' => 'active',
            'access_status' => 'suspended_nonpayment',
            'started_at' => new DateTimeImmutable('2026-11-01T12:00:00+00:00'),
        ]);
        $service = app(DataRightsService::class);
        $now = new DateTimeImmutable('2026-11-17T12:00:00+00:00');

        $review = $service->review($investor, $now);
        $export = $service->export($investor, $now);
        $billing = $service->billingSnapshot($investor);

        self::assertFalse($review['paid_services_unlocked']);
        self::assertContains('deletion', $review['rights']);
        self::assertNotEmpty($export['investor_data']);
        self::assertSame('suspended_nonpayment', $billing['access_status']);
        self::assertTrue($billing['unchanged_by_data_rights']);
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
            'full_name' => 'Helena Martins',
            'phone_e164' => '+55759926'.Str::random(4),
            'email' => 'helena-'.Str::random(6).'@example.test',
            'profession' => 'médica',
        ]);
    }
}
