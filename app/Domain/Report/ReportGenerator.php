<?php

declare(strict_types=1);

namespace App\Domain\Report;

use App\Domain\Billing\Cents;
use App\Domain\Portfolio\PortfolioLedger;
use App\Models\Instrument;
use App\Models\Investor;
use App\Models\Portfolio;
use App\Models\PortfolioEvent;
use App\Models\Report;
use DateTimeImmutable;
use Illuminate\Support\Str;

/** A7/RB-011/RB-016/RB-020: relatório legível, separa PROPOSTA de REALIZADO e nunca inventa dado ausente. */
final class ReportGenerator
{
    private const COLORS = [
        [37, 99, 235],
        [16, 185, 129],
        [245, 158, 11],
        [148, 163, 184],
    ];

    private const EVOLUTION_MIN_POINTS = 3;

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
        $detailed = $label === 'REALIZADO'
            ? $this->ledger->positionsDetailed($portfolio)
            : [];

        $contributions = $label === 'PROPOSTA'
            ? (int) ($context['proposal_contributions_cents'] ?? 0)
            : PortfolioEvent::query()
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

        $source = (string) ($context['source'] ?? 'LED/DATA');
        $dataAsOf = (string) ($context['data_as_of'] ?? ($context['as_of'] ?? $now->format('Y-m-d')));
        $coverage = $label === 'PROPOSTA'
            ? 'PROPOSTA'
            : ($positions === [] ? 'SEM POSICOES' : ($complete ? 'COMPLETA' : 'PARCIAL'));

        $evolution = $this->evolution($investor, $marketValue, $now);
        if (count($evolution) < self::EVOLUTION_MIN_POINTS) {
            $warnings[] = 'Sem histórico suficiente para evolução.';
        }

        $legend = [];
        $legendLines = [];
        $symbols = $detailed === []
            ? []
            : Instrument::query()->whereIn('id', array_keys($detailed))->pluck('symbol', 'id')->all();
        $index = 0;
        foreach ($positions as $key => $cents) {
            $symbol = (string) ($symbols[$key] ?? (is_string($key) ? $key : 'ATIVO'));
            $legend[] = [
                'label' => $symbol,
                'value' => (int) $cents,
                'color' => self::COLORS[$index % count(self::COLORS)],
            ];
            $quantity = $detailed[$key]['quantity'] ?? null;
            $legendLines[] = $symbol.($quantity === null ? '' : ' QTD '.$quantity).' R$ '.Cents::format((int) $cents);
            ++$index;
        }
        if ($legend === []) {
            $legend[] = ['label' => 'SEM DADOS', 'value' => 1, 'color' => self::COLORS[3]];
            $legendLines[] = 'SEM POSICOES INDISPONIVEL';
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
            'source' => $source,
            'data_as_of' => $dataAsOf,
            'coverage' => $coverage,
            'evolution_cents' => $evolution,
        ];

        $metadata = [
            'Title' => 'AporteAI '.$label,
            'Description' => $label.' | contribuicao liquida '.$contributions
               .' centavos | rendimentos '.($returns ?? 'indisponivel')
               .' | valor de mercado '.($marketValue ?? 'indisponivel'),
            'Timestamp' => $payload['timestamp'],
            'Payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ];

        $view = [
            'title' => $label,
            'subtitle' => 'APORTEAI RELATORIO',
            'lines' => [
                'DATA '.$payload['timestamp'],
                'FONTE '.$source.' BASE '.$dataAsOf,
                'PATRIMONIO '.$this->brl($marketValue),
                'APORTE LIQUIDO '.$this->brl($contributions),
                'RENDIMENTOS '.$this->brl($returns),
                'COBERTURA '.$coverage,
            ],
            'legend_lines' => $legendLines,
            'evolution' => $evolution,
            'warnings' => $warnings,
        ];

        $png = $this->encoder->render(640, 640, $legend, $metadata, $view);

        return Report::query()->create([
            'id' => (string) Str::uuid(),
            'investor_id' => $investor->id,
            'label' => $label,
            'payload' => $payload,
            'png' => $png,
            'created_at' => $now,
        ]);
    }

    /** @return list<int> */
    private function evolution(Investor $investor, ?int $current, DateTimeImmutable $now): array
    {
        $points = Report::query()
            ->where('investor_id', $investor->id)
            ->where('label', 'REALIZADO')
            ->where('created_at', '<', $now)
            ->orderByDesc('created_at')
            ->limit(max(0, self::EVOLUTION_MIN_POINTS - 1))
            ->get()
            ->reverse()
            ->map(static function (Report $report): ?int {
                $value = $report->payload['market_value_cents'] ?? null;
                if (($report->payload['complete'] ?? false) !== true || ! is_numeric($value)) {
                    return null;
                }

                return (int) $value;
            })
            ->filter(static fn (?int $value): bool => $value !== null)
            ->values()
            ->all();

        if ($current !== null) {
            $points[] = $current;
        }

        /** @var list<int> $points */
        return $points;
    }

    private function brl(?int $cents): string
    {
        return $cents === null ? 'INDISPONIVEL' : 'R$ '.Cents::format($cents);
    }
}
