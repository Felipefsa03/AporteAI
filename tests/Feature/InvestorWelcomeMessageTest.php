<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\WhatsApp\WhatsAppGateway;
use App\Events\InvestorCreated;
use App\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Validar envio de mensagem de boas-vindas ao novo investidor. */
final class InvestorWelcomeMessageTest extends TestCase
{
    use RefreshDatabase;

    public function testWelcomeMessageDispatchedOnInvestorCreation(): void
    {
        Event::fake();

        Investor::create([
            'id' => (string) Str::uuid(),
            'full_name' => 'João Silva',
            'phone_e164' => '+5575991234567',
            'email' => 'joao@example.test',
            'created_by_admin_id' => (string) Str::uuid(),
        ]);

        Event::assertDispatched(InvestorCreated::class);
    }

    public function testWelcomeMessageSentToCorrectNumber(): void
    {
        Config::set('whatsapp.bot.phone_number_id', '123456789');

        $this->mock(WhatsAppGateway::class, function ($mock) {
            $mock->shouldReceive('sendText')
                ->withArgs(fn ($phoneE164, $text, $botPhoneNumberId) =>
                    str_contains($phoneE164, '5575991234567') &&
                    str_contains($text, 'Bem-vindo ao AporteAI')
                )
                ->once()
                ->andReturn('message-id-123');
        });

        Investor::create([
            'id' => (string) Str::uuid(),
            'full_name' => 'Maria Santos',
            'phone_e164' => '+5575991234567',
            'email' => 'maria@example.test',
            'created_by_admin_id' => (string) Str::uuid(),
        ]);
    }

    public function testWelcomeMessageContainsInvestorName(): void
    {
        Config::set('whatsapp.bot.phone_number_id', '123456789');

        $this->mock(WhatsAppGateway::class, function ($mock) {
            $mock->shouldReceive('sendText')
                ->withArgs(fn ($phoneE164, $text, $botPhoneNumberId) =>
                    str_contains($text, 'Carlos')
                )
                ->once()
                ->andReturn('message-id-456');
        });

        Investor::create([
            'id' => (string) Str::uuid(),
            'full_name' => 'Carlos Mendes',
            'phone_e164' => '+5575991111111',
            'email' => 'carlos@example.test',
            'created_by_admin_id' => (string) Str::uuid(),
        ]);
    }
}
