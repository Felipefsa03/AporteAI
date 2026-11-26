<?php

declare(strict_types=1);

namespace App\Domain\Report;

use App\Domain\WhatsApp\WhatsAppGateway;
use App\Models\Investor;
use App\Models\Portfolio;
use DateTimeImmutable;

/** Envia resumo mensal de carteira e performance ao investidor via WhatsApp. */
final class MonthlyReportService
{
    public function __construct(
        private readonly WhatsAppGateway $whatsapp,
        private readonly ?ReportGenerator $reports = null,
    ) {
    }

    public function send(Investor $investor, DateTimeImmutable $now): void
    {
        $portfolio = Portfolio::query()->where('investor_id', $investor->id)->first();
        $month = $now->format('m/Y');

        $botPhoneNumberId = (string) config('whatsapp.bot.phone_number_id');
        $lines = [
            sprintf('Olá %s! 👋', $investor->full_name),
            '',
            sprintf('Resumo mensal AporteAI — %s', $month),
            '',
        ];

        if ($portfolio === null) {
            $lines[] = 'Ainda não há carteira registrada. Complete seu onboarding para receber análises.';
            $lines[] = '';
            $lines[] = 'Precisa de ajuda? Digite \'ajuda\'.';
            $this->whatsapp->sendText($investor->phone_e164, implode("\n", $lines), $botPhoneNumberId);

            return;
        }

        $lines[] = 'Carteira ativa. Consulte o painel para detalhes de posições e performance.';
        $lines[] = '';
        $lines[] = 'Precisa de ajuda? Digite \'ajuda\'.';

        if ($this->reports !== null) {
            try {
                $report = $this->reports->generate($investor, $portfolio, 'REALIZADO', [], $now);
                $this->whatsapp->sendImageBytes(
                    $investor->phone_e164,
                    $botPhoneNumberId,
                    $report->png,
                    sprintf('Relatório mensal %s', $month),
                );
            } catch (\Throwable $exception) {
                // Falha na geração do PNG não bloqueia o envio do texto.
            }
        }

        $this->whatsapp->sendText($investor->phone_e164, implode("\n", $lines), $botPhoneNumberId);
    }
}