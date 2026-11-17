<?php

declare(strict_types=1);

namespace App\Domain\Allocation;

/** A4/RB-007/RB-008: proposta inteira em bps e centavos, sempre somando exatamente o orçamento. */
final class Allocation
{
    /** @param list<array<string, mixed>> $targets */
    private function __construct(
        public readonly string $status,
        public readonly string $reason,
        public readonly string $ruleVersion,
        public readonly array $targets,
        public readonly int $totalBps,
        public readonly int $totalCents,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $targets
     */
    public static function make(string $status, string $reason, string $ruleVersion, array $targets): self
    {
        $bps = 0;
        $cents = 0;
        foreach ($targets as $target) {
            $bps += (int) $target['bps'];
            $cents += (int) $target['amount_cents'];
        }

        return new self($status, $reason, $ruleVersion, $targets, $bps, $cents);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'reason' => $this->reason,
            'rule_version' => $this->ruleVersion,
            'targets' => $this->targets,
            'total_bps' => $this->totalBps,
            'total_cents' => $this->totalCents,
        ];
    }
}
