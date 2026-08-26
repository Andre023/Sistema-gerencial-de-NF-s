# Campanha de aniversário — a aba que gera a carta do fornecedor

> Escrito em 26/08/2026, quando a aba entrou no ar.
> Substitui o `Gerador Emails Hiper.exe` (o programinha de 2025).

## O que é

Uma aba onde **compras** monta a carta que vai ao fornecedor na promoção de
aniversário: preenche o nome do parceiro, o faturamento dos últimos 12 meses e
o investimento sugerido, confere a carta na prévia e baixa um **.docx pronto**
para anexar no e-mail.

Endereço: `/campanha`.

## Quem vê

| | |
|---|---|
| **Papel** | `compras` (e `admin`, que enxerga tudo) |
| **Gate** | `usar-campanha` → `User::podeUsarCampanha()` |
| **Condição extra** | a campanha precisa estar **ligada** pelo admin |

Com a campanha desligada, a aba **some do menu de todo mundo** e o endereço
responde 403 — inclusive para quem já estava com a tela aberta. É o jeito de a
tela não ficar no caminho fora da época da promoção.

## O interruptor (admin)

**Configurações → Campanha de aniversário** (`/configuracoes/campanha`).

Lá o admin faz duas coisas: liga/desliga a aba e edita o **texto padrão da
loja** — o que todo comprador vê ao abrir a tela pela primeira vez.

> A aba **Configurações** nasceu junto: `Usuários` saiu da navbar e virou a
> primeira seção dela. Eram cinco abas disputando espaço com o sino em telas de
> 1024px, e "quem pode o quê" é configuração como as outras.

## Os marcadores

O texto é livre. O programa só precisa saber **onde entra cada dado**, e isso se
diz com marcador entre parênteses:

| Marcador | Vira |
|---|---|
| `(nome do fornecedor)` — ou `(fornecedor)` | O nome digitado, em negrito |
| `(faturamento)` | `R$ 2.536.257,21` |
| `(investimento)` | `R$ 20.000,00` |

Detalhes que evitam surpresa:

- O `R$` **antes** do marcador é absorvido: `de (investimento)` e
  `de R$ (investimento)` dão a mesma linha. Nunca sai `R$ R$`.
- Maiúscula/minúscula não importa.
- Marcador escrito errado **não quebra nada** — fica como texto na carta. É por
  isso que o marcador é em português e entre parênteses, e não `{{variavel}}`.
- Linha em branco separa parágrafo. No Word o respiro vem do espaçamento, não de
  parágrafo vazio.

## O texto de cada comprador

Cada um pode escrever o seu e clicar em **Salvar meu texto** — fica guardado na
conta dele (tabela `campanha_textos`, um registro por pessoa) e é o que abre da
próxima vez. **Restaurar texto padrão** apaga esse registro e devolve o padrão
da loja.

Apagar em vez de sobrescrever é de propósito: no ano que vem, quem restaurou
passa a ver o padrão **novo** (22 anos, datas novas), não uma cópia do antigo.

## O Word

Montado à mão em `App\Services\DocumentoWord` — um `.docx` é um ZIP com alguns
XMLs, e a carta inteira dá uns 3 KB. Sem biblioteca nova de propósito: na VM de
1 GB, `composer install` é operação delicada, e o PhpWord sabe fazer mil coisas
que esta tela não precisa.

O documento sai igual ao modelo de 2025: A4, margens de 2,5 cm em cima/embaixo e
3 cm nas laterais, Calibri 11, parágrafos justificados. O nome do fornecedor e
os dois valores saem em **negrito** — é o que o parceiro procura quando abre o
arquivo. Nome do arquivo: `Aniversário - NOME DO FORNECEDOR.docx`.

Nada é gravado no servidor: a carta é gerada na hora e vai direto para o
download.

## Onde mexer

| O quê | Onde |
|---|---|
| Texto de fábrica e substituição dos marcadores | `app/Support/CartaCampanha.php` |
| A mesma substituição, para a prévia | `resources/js/lib/campanha.ts` |
| Montagem do .docx | `app/Services/DocumentoWord.php` |
| Telas | `resources/js/Pages/Campanha/Index.tsx`, `Pages/Configuracoes/Campanha.tsx` |
| Chaves do sistema (liga/desliga, texto padrão) | `app/Models/Configuracao.php` |
| Testes | `tests/Feature/CampanhaTest.php` |

> `CartaCampanha.php` e `campanha.ts` fazem a MESMA substituição — um para o
> arquivo entregue, outro para a prévia enquanto se digita. Mexeu num, mexa no
> outro; quem manda no que o fornecedor recebe é o PHP.

## Para o ano que vem

1. Entrar em **Configurações → Campanha de aniversário**.
2. Trocar no texto padrão: os anos (`21 anos` → `22 anos`) e as datas do período.
3. Ligar a aba.

Não precisa de deploy para isso.
