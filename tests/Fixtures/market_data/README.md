# Fixtures de formatos oficiais (DATA)

Todas as fixtures reproduzem **esquemas e formatos reais** dos feeds oficiais
consultados em 18/11/2026. Os campos, delimitadores, formatos de data e casas
decimais sao copiados da documentacao/resposta oficial; os valores sao amostras
reais obtidas sem credenciais, exceto `bcb_sgs_selic.json` e `tesouro_future.csv`,
onde a data foi ajustada para tornar o teste deterministico em relacao ao relogio
de referencia. Nenhuma fixture inventa coluna ou formato.

| Fixture | Fonte oficial | Formato real | Verificacao |
| --- | --- | --- | --- |
| `bcb_sgs_selic.json` | BCB SGS serie 432 (Selic) | JSON `[{"data":"dd/mm/yyyy","valor":"decimal"}]` | GET 200 em `api.bcb.gov.br` |
| `bcb_focus_odata.json` | BCB Expectativas de Mercado (OData `ExpectativasMercadoSelic`) | JSON `{"value":[{"Indicador","Data","Reuniao","Mediana",...}]}` | GET 200 em `olinda.bcb.gov.br` |
| `tesouro_precos_taxas.csv` | Tesouro Transparente (CKAN, recurso `precotaxatesourodireto.csv`) | CSV `;` com `Data Base` `dd/mm/yyyy` e `PU Venda Manha` decimal com virgula | GET 200, 14.700.232 bytes |
| `cvm_inf_diario.csv` | CVM INF_DIARIO (ZIP mensal) | CSV `;` com `CNPJ_FUNDO_CLASSE`, `DT_COMPTC`, `VL_QUOTA` | ZIP 200, 5.454.910 bytes; CSV interno 24.979.061 bytes |
| `tesouro_future.csv` | Mesmo esquema de `tesouro_precos_taxas.csv`, com `Data Base` posterior ao relogio de referencia | CSV `;` | usado para provar rejeicao de futuro |

O ZIP da CVM e montado nos testes a partir de `cvm_inf_diario.csv`, preservando o
formato real de arquivo e permitindo testar limites de entradas e descompressao
sem versionar binario.

Regra de ouro: Selic e Focus sao indicadores macroeconomicos; nunca viram
cotacao de instrumento nem entram no universo de alocacao. `Taxa Compra/Venda`
do Tesouro tambem nao e preco: apenas `PU` (preco unitario) e ingerido.
