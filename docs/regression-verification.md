# REG — verificação de regressões (18/11/2026)

Base: PR #19 (`fad2757`), dependência de CI ainda não integrada em main.

## Resultado local

PHP 8.4.27 e PostgreSQL 16 descartável, sem rede e sem credenciais de produção:
`DB_HOST=/tmp/aporteai-reg-socket composer test -- --log-junit /tmp/aporteai-reg-final.xml`

71 testes, 328 assertions, zero falhas/erros. Antes: 71 testes, 36 erros e 5 falhas.
Composer validate --strict, check-platform-reqs, sintaxe PHP e git diff --check aprovados.
CI do PR verifica separadamente PHP 8.3/PostgreSQL 16; consultar o run para seu resultado.

## Correções e limites

- Auditoria aceita identificadores polimórficos (UUIDs e nomes de serviços/fontes), preservando registros existentes. Migração ALTER TYPE pode bloquear a tabela: revisar janela antes de produção. O rollback para UUID recusa identificadores nominais; não apagar auditoria para forçar rollback. Preferir correção adiante.
- Defaults de assinatura mantêm bloqueio inicial; comandos acentuados são reconhecidos; resposta do LLM simulado não é descartada.
- Relações de suitability/aporte resolvem investidores. Respostas do aporte guardam as chaves processadas, com leitura bloqueada em transação para evitar sobrescrita de instância desatualizada. O teste repete a primeira mensagem após as quatro perguntas usando instância antiga.
- PNG usa LOB para bytea; teste verifica igualdade após persistência e leituras repetidas.
- Piloto informa campos obrigatórios de ativação.
- Fixtures respeitam admin único e MFA obrigatório. Cenário inadimplente usa pagamento aberto; ativo usa a fonte configurada. Contagem do piloto corresponde aos dois ativos definidos; três eventos de execução geram três registros. Teste de revisão usa papel viewer separado em memória e mantém a proibição de liberar bloqueio crítico.

## Prontidão real

Testável: API e regras locais com dados fictícios, autorização/MFA, cobrança, gate antes do LLM, aporte/carteira, auditoria e persistência de PNG. Não houve envio real de WhatsApp nem chamada a provedor LLM.

Lacunas observadas: interface visual do proprietário ausente; webhook retorna respostas em JSON sem envio de texto/mídia via WhatsApp; LlmGateway sem provedor real; textos/valores do PNG estão em metadados e precisam ser desenhados na imagem. Requerem entregas próprias e teste integrado.

C01–C07 e GO continuam abertos para decisão humana jurídica/compliance. Recomendações públicas permanecem desabilitadas. Esta entrega exige revisão humana e não autoriza produção.
