<?php

declare(strict_types=1);

namespace App\Domain\WhatsApp;

/** RB-030/A14: atendimento humano aponta para o numero comercial, sem automacao. */
final class HumanHandoff
{
    public function __construct(private readonly NumberRegistry $numbers)
    {
    }

    /** @return array<string, string> */
    public function instructions(): array
    {
        return [
            'channel' => 'commercial_whatsapp',
            'number_e164' => $this->numbers->commercialNumberE164(),
            'note' => 'Atendimento humano pelo numero comercial; nenhum servico pago e liberado.',
        ];
    }
}
