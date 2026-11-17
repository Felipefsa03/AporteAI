<?php

declare(strict_types=1);

namespace App\Domain\Report;

use App\Domain\Portfolio\PortfolioLedger;
use App\Models\Investor;
use App\Models\Portfolio;
use App\Models\PortfolioEvent;
use App\Models\Report;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** A7/RB-011/RB-016: relatório separa PROPOSTA de REALIZADO e nunca inventa dado ausente. */
final class ReportGenerator
{
    private const COLORS = [
        [37, 99, 235],
        [16, 185, 129],
        [245, 158, 11],
        [148, 163, 184],
    ];

    public function __construct(
        private readonly PortfolioLedger $ledger,
        private readonly PngEncoder $encoder,
    ) {
    }

    /** @param array<string, mixed> $context */
    public function generate(
        Investor $investor,
        Portfolio $portfolio,
        string $label,
        array $context,
        DateTimeImmutable $now,
    ): Report {
        $label = strtoupper($label);
        if (! in_array($label, ['PROPOSTA', 'REALIZADO'], true)) {
            throw new \InvalidArgumentException('Rótulo de relatório inválido.');
        }

        $positions = $label === 'REALIZADO'
            ? $this->ledger->positions($portfolio)
            : (array) ($context['proposal_positions'] ?? []);

        $contributions = PortfolioEvent::query()
            ->where('portfolio_id', $portfolio->id)
            ->whereIn('event_type', ['ORDER_CONFIRMED', 'PARTIAL_FILL', 'REVERSAL'])
            ->get()
            ->sum(static fn (PortfolioEvent $event): int =>
                $event->event_type === 'REVERSAL' ? -$event->amount_cents : $event->amount_cents);

        $returns = $context['returns_cents'] ?? null;
        $marketValue = $context['market_value_cents'] ?? null;
        $complete = $returns !== null && $marketValue !== null && $positions !== [];
        $warnings = [];

        if (! $complete) {
            $warnings[] = 'Dados incompletos: valores de mercado/rendimento não disponíveis para todas as posições.';
        }

        $legend = [];
        $index = 0;
        foreach ($positions as $symbol => $cents) {
            $legend[] = [
                'label' => (string) $symbol,
                'value' => (int) $cents,
                'color' => self::COLORS[$index % count(self::COLORS)],
            ];
            ++$index;
        }
        if ($legend === []) {
            $legend[] = ['label' => 'SEM DADOS', 'value' => 1, 'color' => self::COLORS[3]];
        }

        $payload = [
            'label' => $label,
            'timestamp' => $now->format(DATE_ATOM),
            'positions_cents' => $positions,
            'contributions_cents' => $contributions,
            'returns_cents' => $returns,
            'market_value_cents' => $marketValue,
            'warnings' => $warnings,
            'complete' => $complete,
        ];

        $metadata = [
            'Title' => 'AporteAI '.$label,
            'Description' => $label.' | contribuicao liquida '.$contributions
               .' centavos | rendimentos '.($returns ?? 'indisponivel')
               .' | valor de mercado '.($marketValue ?? 'indisponivel'),
            'Timestamp' => $payload['timestamp'],
            'Payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];

        $png = $this->encoder->render(640, 360, $legend, $metadata);

        return Report::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'label' => $label,
            'payload' => $payload,
            'png' => $png,
            'created_at' => $now,
        ]);
    }
}
