<?php

declare(strict_types=1);

namespace App\Domain\Billing;

/** RB-029/RB-033/A13/A14: decisão determinística tomada antes de qualquer inferência. */
final class AccessDecision
{
    private function __construct(
        public readonly string $action,
        public readonly string $reply,
        public readonly bool $allowLlm,
        public readonly string $reason,
    ) {
    }

    public static function blocked(string $reply): self
    {
        return new self('blocked', $reply, false, 'payment_pending_after_grace');
    }

    public static function verification(string $reply): self
    {
        return new self('verification', $reply, false, 'gateway_unavailable_or_reconciling');
    }

    public static function command(string $reply, string $command): self
    {
        return new self('command', $reply, false, 'administrative_command:'.$command);
    }

    public static function grace(string $reply): self
    {
        return new self('grace', $reply, true, 'contractual_grace_period');
    }

    public static function eligible(): self
    {
        return new self('eligible', '', true, 'subscription_active');
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'reply' => $this->reply,
            'allow_llm' => $this->allowLlm,
            'reason' => $this->reason,
        ];
    }
}
