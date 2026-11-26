<?php

declare(strict_types=1);

namespace App\Domain\Onboarding;

use App\Models\AdvisoryContract;
use App\Models\Investor;
use App\Models\SuitabilityAssessment;

/** Checklist de conclusao do perfil do investidor, derivado dos registros existentes. */
final class ProfileChecklistService
{
    /**
     * @return array<int, array{key: string, label: string, done: bool}>
     */
    public function checklist(Investor $investor): array
    {
        $hasContract = AdvisoryContract::query()
            ->where('investor_id', $investor->id)
            ->where('status', 'signed')
            ->exists();

        $hasSuitability = SuitabilityAssessment::query()
            ->where('investor_id', $investor->id)
            ->where('status', 'valid')
            ->exists();

        $hasProfile = is_string($investor->profession) && $investor->profession !== ''
            && $investor->income_monthly_brl !== null;

        $hasGoals = false; // metas definidas via Portfolio/objetivos (a ser integrado)

        return [
            ['key' => 'profile', 'label' => 'Confirmar dados (nome, telefone, e-mail)', 'done' => $hasProfile],
            ['key' => 'terms', 'label' => 'Aceitar termos e aviso de privacidade', 'done' => $hasContract],
            ['key' => 'suitability', 'label' => 'Responder questionário de suitability', 'done' => $hasSuitability],
            ['key' => 'goals', 'label' => 'Definir objetivos e metas de aporte', 'done' => $hasGoals],
        ];
    }

    public function isComplete(Investor $investor): bool
    {
        foreach ($this->checklist($investor) as $step) {
            if (! $step['done']) {
                return false;
            }
        }

        return true;
    }

    public function summary(Investor $investor): string
    {
        $lines = ['Checklist de perfil:'];
        foreach ($this->checklist($investor) as $step) {
            $mark = $step['done'] ? '✅' : '⬜';
            $lines[] = sprintf('%s %s', $mark, $step['label']);
        }

        return implode("\n", $lines);
    }
}