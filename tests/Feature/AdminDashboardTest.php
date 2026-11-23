<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AdminSession;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function testClientesNaoPossuemCredencialWeb(): void
    {
        $this->getJson('/api/v1/admin/investors')->assertStatus(401);
        $this->assertDatabaseCount('admin_users', 0);
    }

    public function testCadastroDoProprietarioGeraTrilhaDeAuditoria(): void
    {
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'proprietario@example.test',
            'password_hash' => Hash::make('senha-de-teste'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => false,
            'active' => true,
        ]);

        $token = Str::random(64);
        AdminSession::query()->create([
            'id' => (string) Str::uuid(),
            'admin_user_id' => $admin->id,
            'token_hash' => hash('sha256', $token),
            'mfa_completed_at' => now(),
            'expires_at' => now()->addHour(),
        ]);

        $traceId = (string) Str::uuid();
        $response = $this
            ->withHeader('Authorization', 'Bearer '.$token)
            ->withHeader('X-Trace-Id', $traceId)
            ->postJson('/api/v1/admin/investors', [
                'full_name' => 'Investidor Piloto',
                'phone_e164' => '+5511999999999',
                'email' => 'piloto@example.test',
                'profession' => 'Engenheiro',
                'income_monthly_brl' => 12500.50,
                'lifecycle_status' => 'draft',
                'source_note' => 'Cadastro manual pelo proprietario.',
            ]);

        $response->assertCreated();
        $this->assertDatabaseHas('investors', [
            'phone_e164' => '+5511999999999',
            'created_by_admin_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'action' => 'investor.created',
            'trace_id' => $traceId,
            'subject_type' => 'investor',
            'subject_id' => $response->json('data.id'),
        ]);
    }

    public function testSessaoSemMfaNaoAcessaDashboard(): void
    {
        $admin = AdminUser::query()->create([
            'id' => (string) Str::uuid(),
            'email' => 'proprietario2@example.test',
            'password_hash' => Hash::make('senha-de-teste'),
            'mfa_secret_encrypted' => encrypt('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'),
            'mfa_enabled' => true,
            'active' => true,
        ]);

        $token = Str::random(64);
        AdminSession::query()->create([
            'id' => (string) Str::uuid(),
            'admin_user_id' => $admin->id,
            'token_hash' => hash('sha256', $token),
            'mfa_completed_at' => null,
            'expires_at' => now()->addHour(),
        ]);

        // Requisição com token de sessão incompleta (sem MFA) deve ser rejeitada
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/v1/admin/investors')
            ->assertStatus(401);
    }
}
