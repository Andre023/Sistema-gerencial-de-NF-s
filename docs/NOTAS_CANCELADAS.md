# Notas canceladas — modelo de dados e uso nas Estatísticas

> Documento de referência para incrementar a página de **Estatísticas** no futuro.
> Escrito em 28/07/2026, quando o cancelamento entrou no ar.

## O que é

Uma NF pode ser **cancelada pelo fornecedor** depois de lançada. Antes disso, a
única saída era *excluir* a nota — o que apagava o rastro e falseava os números
(a nota "sumia" como se nunca tivesse existido).

Agora a nota cancelada **sai da fila mas continua no banco**, com o histórico
inteiro: cards, comentários, quem cancelou, quando e por quê. Ela aparece na
seção **"Canceladas neste dia"**, abaixo de "Liberadas neste dia".

## Quem pode cancelar

**Pré-lote** e **compras** (gate `cancelar-nota` → `User::podeCancelarNota()`).
Admin também, por herdar tudo. Recebimento **não** cancela.

O cancelamento é **reversível**: quem pode cancelar pode desfazer
(`POST /notas/{nota}/descancelar`), e a nota volta para a fila exatamente como
estava. Nada é excluído em nenhum dos dois sentidos.

## Colunas (migration `2026_07_28_190000`)

| Coluna | Tipo | Significado |
|---|---|---|
| `cancelada_em` | `timestamp` null | Quando foi cancelada. **É o marcador**: `null` = ativa. |
| `cancelada_por` | FK `users` null | Quem cancelou (`nullOnDelete`). |
| `motivo_cancelamento` | `string(500)` null | Texto livre, opcional. |

O status derivado `Nota::STATUS_CANCELADA` (`'cancelada'`) tem **precedência
sobre tudo** em `statusCalculado()` — inclusive sobre `liberada`. Uma nota
cancelada não é "pendente", nem "com divergência", nem "liberada".

### Onde a exclusão acontece hoje

- **Fila** (`recebimento` e `preLote`): `whereNull('cancelada_em')`
- **Liberadas**: `whereNull('cancelada_em')`
- **Canceladas**: `whereNotNull('cancelada_em')` + `whereDate('cancelada_em', $data)`

> ⚠️ **Ao escrever qualquer consulta nova de "notas ativas", lembre de excluir as
> canceladas.** Elas continuam na tabela `notas` e, sem o filtro, entram em
> qualquer contagem por engano.

## Ideias de observações para as Estatísticas

Tudo abaixo já é calculável com os dados que estão sendo gravados:

1. **Taxa de cancelamento** — `canceladas / (liberadas + canceladas)` no período.
   Um salto costuma indicar problema com um fornecedor específico.
2. **Ranking de fornecedores que mais cancelam** — `GROUP BY fornecedor_id`
   sobre `cancelada_em IS NOT NULL`. Cruza com a aba **Prioridades**.
3. **Cancelamento tardio** — `cancelada_em - created_at`. Nota que passou dias na
   fila e no fim foi cancelada representa trabalho de conferência jogado fora.
   Vale destacar as que já tinham **cards resolvidos** quando foram canceladas.
4. **Cancelada por setor** — `cancelada_por` → papel do usuário (pré-lote ×
   compras): quem costuma detectar o cancelamento.
5. **Motivos recorrentes** — `motivo_cancelamento` é texto livre; um
   agrupamento simples por termo já revela padrões (ex.: "duplicada",
   "fora de rota"). Se virar recorrente, promover a um campo com opções fixas.
6. **Cancelamento × CEASA** — cruzar com a coluna `ceasa` (0/1/2/3): hortifrúti
   tem dinâmica própria e pode distorcer a média geral.
7. **Desfeitos** — hoje o "descancelar" limpa as colunas e não deixa rastro. Se
   a taxa de arrependimento importar, será preciso uma tabela de auditoria ou
   um contador (`cancelamentos_desfeitos`) — **decisão pendente**.

## Relacionado

- **Troca de fila** (mesma migration): `origem_alterada_em` + `origem_anterior`
  registram quando a nota mudou de pré-lote para caminhão na porta. O
  envelhecimento (`TemIdade::inicioContagem()`) passa a contar **da troca**, e a
  tela mostra "Pré-lote desde 19/06". Para estatísticas de tempo de espera, use
  `created_at` para o tempo **total** e `origem_alterada_em` para o tempo **na
  fila atual** — os dois números são diferentes e ambos são legítimos.
