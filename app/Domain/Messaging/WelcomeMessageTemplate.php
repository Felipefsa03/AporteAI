<?php

declare(strict_types=1);

namespace App\Domain\Messaging;

final class WelcomeMessageTemplate
{
    public static function forInvestor(string $investorName): string
    {
        $template = "Olá %s! 👋\n\n" .
            "Bem-vindo ao AporteAI!\n\n" .
            "Sua conta foi criada com sucesso no piloto fictício. " .
            "Você já pode:\n\n" .
            "• Acessar o painel exclusivo\n" .
            "• Receber recomendações personalizadas\n" .
            "• Simular investimentos\n" .
            "• Consultar análises e relatórios\n\n" .
            "Para liberar recomendações personalizadas, complete seu perfil:\n" .
            "1. Confirme seus dados (nome, telefone, e-mail)\n" .
            "2. Aceite os termos e o aviso de privacidade\n" .
            "3. Responda o questionário de suitability\n" .
            "4. Defina seus objetivos e metas de aporte\n\n" .
            "Próximos passos:\n" .
            "1. Complete seu perfil de investidor\n" .
            "2. Defina seus objetivos\n" .
            "3. Comece a explorar oportunidades\n\n" .
            "Precisa de ajuda? Digite 'ajuda' ou contate nosso time.";

        return sprintf($template, $investorName);
    }
}
