# Fontes de mercado homologadas — DATA (18/11/2026)

Documento de proveniencia exigido pelo card DATA. Cada contrato abaixo foi
verificado sem credenciais na data informada. Nenhuma fonte comercial foi
habilitada e nenhuma decisao C07/ADR003 foi tomada por este trabalho.

## 1. BCB — Taxa Selic (SGS 432)

- Endpoint verificado: `GET https://api.bcb.gov.br/dados/serie/bcdata.sgs.432/dados/ultimos/1?formato=json` → **200**.
- Resposta observada: `[{"data":"09/12/2026","valor":"13.75"}]` (data no formato
  `dd/mm/yyyy`; decimal pode vir com ponto ou virgula — o adaptador aceita ambos).
- Dataset: SGS 432 "Taxa de juros - Selic" (catalogo `dadosabertos.bcb.gov.br`).
- Naturaliza: indicador macroeconomico, unidade percentual ao ano, timezone
  `America/Sao_Paulo`. **Nunca** e cotacao de CDB nem instrumento negociavel.
- Evidencia: `https://dadosabertos.bcb.gov.br/dataset/taxa-de-juros-selic`.
- Observacao honesta: a ultima observacao devolvida pela API era posterior a data
  de referencia deste ambiente; o teste usa fixture com data controlada e o
  adaptador rejeita datas futuras em relacao ao `now()` informado.

## 2. BCB — Expectativas de Mercado (Focus Selic)

- **Nao** e uma serie SGS arbitraria rotulada como Focus. A fonte e o servico
  oficial `Expectativas de Mercado` (sistema EXP), catalogado em
  `https://dadosabertos.bcb.gov.br/dataset/expectativas-mercado` sob licenca ODbL.
- Endpoint verificado: `GET https://olinda.bcb.gov.br/olinda/servico/Expectativas/versao/v1/odata/ExpectativasMercadoSelic?$top=3&$format=json` → **200**.
- Resposta observada (amostra): `Indicador=Selic`, `Data=2026-11-13`,
  `Reuniao=R7/2028`, `Media=11.5902`, `Mediana=11.5000`, `DesvioPadrao=1.0996`,
  `Minimo=9.5000`, `Maximo=15.0000`, `numeroRespondentes=61`, `baseCalculo=0`.
- Esquema: OData JSON com `value[]`; `Data` em `yyyy-mm-dd`; `Mediana` numerica.
- Naturaliza: indicador, percentual. O simbolo inclui a reuniao
  (`FOCUS_SELIC_<REUNIAO>`) para nao achatar expectativas diferentes na mesma data.
- Evidencia: pagina do dataset e documentacao Swagger oficiais do BCB.

## 3. Tesouro Direto — Precos e taxas dos titulos ofertados

- O endpoint antigo `treasurybondsinfo.json` retornou **410 Gone**; nao foi usado.
- Fonte oficial alternativa localizada no site institucional: **Tesouro Transparente /
  CKAN**, dataset `Taxas dos Titulos Ofertados pelo Tesouro Direto`.
- Metadados verificados via `resource_show` (CKAN API): licenca **ODbL**,
  tamanho 14.700.232 bytes, ultima modificacao 2026-11-18.
- Recurso verificado: `GET .../download/precotaxatesourodireto.csv` → **200**.
- Esquema real (delimitador `;`, decimal com virgula, datas `dd/mm/yyyy`):
  `Tipo Titulo;Data Vencimento;Data Base;Taxa Compra Manha;Taxa Venda Manha;PU Compra Manha;PU Venda Manha;PU Base Manha`.
- Linha real observada:
  `Tesouro Selic;01/03/2031;17/11/2026;0,11;0,12;20231,37;20212,35;20212,35`.
- O adaptador usa `Data Base` como data e **`PU Venda Manha` como preco**.
  `Taxa Compra/Venda` sao taxas (percentual), nao precos, e sao ignoradas.
- Evidencia: `https://www.tesourotransparente.gov.br/ckan/dataset/taxas-dos-titulos-ofertados-pelo-tesouro-direto`.

## 4. CVM — Informacoes diarias de fundos (INF_DIARIO)

- Diretorio correto verificado: `https://dados.cvm.gov.br/dados/FI/DOC/INF_DIARIO/DADOS/`
  (o caminho `FIAD` retornou 404 e foi descartado).
- Arquivo mensal verificado: `inf_diario_fi_202611.zip` → **200**, 5.454.910 bytes;
  ultima modificacao 2026-11-18. Contem um unico CSV de 24.979.061 bytes.
- Esquema real (delimitador `;`, datas `yyyy-mm-dd`, decimal com ponto), conforme
  `META/meta_inf_diario_fi.txt`:
  `TP_FUNDO_CLASSE;CNPJ_FUNDO_CLASSE;ID_SUBCLASSE;DT_COMPTC;VL_TOTAL;VL_QUOTA;VL_PATRIM_LIQ;CAPTC_DIA;RESG_DIA;NR_COTST`.
- Linha real observada:
  `CLASSES - FIF;00.017.024/0001-53;;2026-11-03;1131424.27;45.360755300000;1228659.93;0.00;0.00;1`.
- O adaptador usa `CNPJ_FUNDO_CLASSE` (+ `ID_SUBCLASSE`) como simbolo, `DT_COMPTC`
  como data e `VL_QUOTA` como preco da cota.
- Evidencia: `https://dados.cvm.gov.br/dataset/fi-doc-inf_diario`.

## Licenca, redistribuicao e integracao

- Evidencias documentadas acima, mas **redistribuicao nao presumida**: todos os
  sources homologados ficam com `redistribution_allowed = false` ate decisao
  humana C07/ADR003. Nenhuma homepage foi tratada como aprovacao de licenca.
- `usage_allowed = true` apenas para os quatro feeds publicos verificados; o
  provedor `commercial_quotes` permanece sem adapter, sem uso e sem endpoint.
- Sincronizacao automatica e **opt-in** (`MARKET_SCHEDULER_ENABLED`); sem ela,
  nenhum job e registrado. Recomendacoes comerciais e execucao de ordens nao
  sao habilitadas por este trabalho.
- Nenhuma credencial foi usada ou gravada no repositorio.

## Limites aplicados

- Download: timeout configuravel, retries, `Content-Length` verificado **antes**
  da leitura e corpo lido em blocos com teto de bytes.
- Descompressao (CVM): numero maximo de entradas, apenas `.csv`, tamanho
  comprimido e descomprimido por entrada, razao maxima de compressao e teto de
  bytes durante o stream. Entradas inesperadas ou ZIP bomb sao recusadas.

## Falhas externas registradas

- `websearch` indisponivel no ambiente (cancelado); a pesquisa foi feita com
  `webfetch`/`curl` direto nas paginas e APIs oficiais.
- Endpoint antigo do Tesouro Direto (`treasurybondsinfo.json`) → 410 Gone.
- Caminho `FIAD` da CVM → 404 Not Found (corrigido para `FI`).
- `formato=csv` na API SGS do BCB → 406; `formato=xml` → 400. O formato JSON
  verificado (200) e o adotado.
