<?php

namespace App\Services;

use App\Models\Cotacao;
use App\Models\CotacaoFolha;
use App\Support\PlanilhaDaCotacao as P;

/**
 * Os .xlsx que saem da Cotação, com as cores que o comprador pintou na tela.
 *
 *   • a lista de cotação — o que vai por WhatsApp aos fornecedores, no mesmo
 *     formato da ferramenta antiga, para quem já devolve preenchida não
 *     estranhar;
 *   • a planilha de compra — uma aba por loja e setor, a aba "Por fornecedor"
 *     (o pedido de cada um) e o "Comparativo" com todos os preços;
 *   • qualquer folha avulsa, como está na tela.
 */
final class ExportacaoDaCotacao
{
    private const CABECALHO = ['b' => true, 'bg' => '#dde3ea'];

    /** Linha da planilha de compra com a coluna Fornecedor preenchida: já foi comprada. Mesmo verde da tela clara. */
    private const COMPRADA = ['bg' => '#d3f5dc'];

    /**
     * @param  string|null  $setor  'merc', 'beb' ou 'frios' para só aquele setor; null para todos.
     *                              Há fornecedor que só vende bebida — mandar a lista
     *                              inteira a ele é pedir que ele ache o pedaço dele.
     */
    public static function listaParaFornecedores(Cotacao $cotacao, ?string $setor = null): string
    {
        $previa = $cotacao->folhas()->where('tipo', 'previa')->first();
        $colunas = collect($previa?->colunas ?? P::COLUNAS_PREVIA)->keyBy('id');

        $produtos = $previa?->linhas ?? [];

        if ($setor !== null) {
            $nomeDoSetor = P::norm(Cotacao::SETORES[$setor] ?? '');
            $produtos = array_filter($produtos, fn($l) => P::norm($l['v']['setor'] ?? '') === $nomeDoSetor);
        }

        $titulo = 'Cotação de preços' . ($setor !== null ? ' — ' . Cotacao::SETORES[$setor] : '');

        $linhas = [
            [['v' => $titulo, 'b' => true], '', ''],
            ['Fornecedor: ', '(O fornecedor vai inserir o nome aqui)', ''],
            [['v' => 'GTIN Principal'] + self::CABECALHO, ['v' => 'Descrição'] + self::CABECALHO, ['v' => 'Valor'] + self::CABECALHO],
        ];
        $mesclas = ['A1:C1', 'B2:C2'];
        $setorAtual = null;

        foreach ($produtos as $linha) {
            $doSetor = (string) ($linha['v']['setor'] ?? '');

            // A linha "Bebidas" que separa os setores — o fornecedor costuma
            // ter vendedores diferentes para cada um.
            if ($setorAtual !== null && P::norm($doSetor) !== P::norm($setorAtual) && $doSetor !== '') {
                $linhas[] = ['', '', ''];
                $linhas[] = [['v' => $doSetor, 'b' => true], '', ''];
                $mesclas[] = 'A' . count($linhas) . ':C' . count($linhas);
            }

            $setorAtual = $doSetor !== '' ? $doSetor : $setorAtual;

            $linhas[] = [
                self::celula($linha, $colunas->get('gtin'), 'gtin'),
                self::celula($linha, $colunas->get('desc'), 'desc'),
                self::celula($linha, $colunas->get('valor'), 'valor'),
            ];
        }

        return (new PlanilhaComEstilo())
            ->aba('Planilha1', [16, 50, 12], $linhas, $mesclas, congelarLinhas: 3)
            ->bytes();
    }

    public static function compra(Cotacao $cotacao): string
    {
        $folhas = $cotacao->folhas()->get();
        $compras = $folhas->where('tipo', 'compra')->sortBy('ordem')->values();
        $planilha = new PlanilhaComEstilo();

        foreach ($compras as $folha) {
            self::abaDaFolha($planilha, $folha, Cotacao::LOJAS[$folha->meta['loja'] ?? '']['sigla'] ?? '');
        }

        self::porFornecedor($planilha, $compras);
        self::comparativo($planilha, $compras, $folhas->where('tipo', 'resposta'));

        return $planilha->bytes();
    }

    public static function folha(CotacaoFolha $folha): string
    {
        return self::abaDaFolha(new PlanilhaComEstilo(), $folha)->bytes();
    }

    // ─── Abas ──────────────────────────────────────────────────────────────────

    private static function abaDaFolha(PlanilhaComEstilo $planilha, CotacaoFolha $folha, string $sigla = ''): PlanilhaComEstilo
    {
        $colunas = $folha->colunas ?? [];

        $linhas = [array_map(fn($c) => ['v' => $c['nome']] + self::estiloDe($c['estilo'] ?? null) + self::CABECALHO, $colunas)];

        foreach ($folha->linhas ?? [] as $linha) {
            // Por baixo das cores pintadas à mão: quem pintou vence o verde.
            $comprada = $folha->tipo === 'compra' ? self::comprada($linha) : [];
            $linhas[] = array_map(fn($c) => self::celula($linha, $c, $c['id']) + $comprada, $colunas);
        }

        // "GB Mercearia" em vez de "Gentil Bicalho · Mercearia": o nome da aba
        // tem 31 letras no máximo.
        $nome = $folha->tipo === 'compra' && $sigla !== ''
            ? $sigla . ' ' . (Cotacao::SETORES[$folha->meta['setor'] ?? ''] ?? '')
            : $folha->nome;

        return $planilha->aba(
            $nome,
            array_map(fn($c) => max(6, round(($c['largura'] ?? 100) / 7)), $colunas),
            $linhas,
            congelarLinhas: 1,
            filtro: true,
            // Oculta na tela, oculta no Excel — e não apagada: quem precisar
            // reexibe lá também.
            ocultas: array_keys(array_filter(array_values($colunas), fn($c) => ! empty($c['oculta']))),
        );
    }

    /** O pedido de cada fornecedor: os produtos em que ele deu o menor preço. */
    private static function porFornecedor(PlanilhaComEstilo $planilha, $compras): void
    {
        $grupos = [];

        foreach ($compras as $folha) {
            $loja = Cotacao::LOJAS[$folha->meta['loja'] ?? '']['nome'] ?? '';
            $setor = Cotacao::SETORES[$folha->meta['setor'] ?? ''] ?? '';

            foreach ($folha->linhas ?? [] as $linha) {
                $v = $linha['v'] ?? [];

                if (! isset($v['preco1'])) {
                    continue;
                }

                $estilo = self::estiloDe($linha['s'] ?? null) + self::comprada($linha);
                $grupos[P::norm($v['forn1'] ?? '')]['nome'] = $v['forn1'] ?? '';
                $grupos[P::norm($v['forn1'] ?? '')]['linhas'][] = array_map(fn($c) => is_array($c) ? $c + $estilo : ['v' => $c] + $estilo, [
                    $v['forn1'] ?? '', $loja, $setor, $v['desc'] ?? '', self::gtin($v['gtin'] ?? ''),
                    ['v' => self::num($v['preco1'] ?? null), 'fmt' => 'moeda'],
                    $v['forn2'] ?? '',
                    ['v' => self::num($v['preco2'] ?? null), 'fmt' => 'moeda'],
                    $v['fator'] ?? '', $v['quantidade'] ?? '',
                    ['v' => self::num($v['preco_forn'] ?? null) ?? ($v['preco_forn'] ?? ''), 'fmt' => 'moeda'],
                    $v['fornecedor'] ?? '',
                ]);
            }
        }

        uasort($grupos, fn($a, $b) => count($b['linhas']) <=> count($a['linhas']) ?: strcmp($a['nome'], $b['nome']));

        $cabecalho = array_map(fn($t) => ['v' => $t] + self::CABECALHO,
            ['Fornecedor', 'Loja', 'Setor', 'Descrição', 'GTIN Principal', 'Preço', '2º menor (fornecedor)', '2º Preço', 'Fator', 'Quantidade', 'Preço for', 'Fornecedor (compra)']);

        $planilha->aba('Por fornecedor', [24, 16, 10, 44, 15, 10, 24, 10, 8, 10, 12, 22],
            array_merge([$cabecalho], ...array_column($grupos, 'linhas')), congelarLinhas: 1, filtro: true);
    }

    /** Todos os preços lado a lado — para conferir se a escolha faz sentido. */
    private static function comparativo(PlanilhaComEstilo $planilha, $compras, $respostas): void
    {
        $fornecedores = [];
        foreach ($respostas as $resposta) {
            $nome = trim((string) ($resposta->meta['fornecedor'] ?? '')) ?: $resposta->nome;
            $fornecedores[P::norm($nome)] = $nome;
        }

        $linhas = [array_map(fn($t) => ['v' => $t] + self::CABECALHO, ['Setor', 'Descrição', 'GTIN Principal', ...array_values($fornecedores)])];
        $vistos = [];

        foreach ($compras as $folha) {
            foreach ($folha->linhas ?? [] as $linha) {
                $k = $linha['k'] ?? null;

                if ($k === null || isset($vistos[$k])) {
                    continue;
                }

                $vistos[$k] = true;
                $precos = [];
                foreach ($linha['of'] ?? [] as $oferta) {
                    $precos[P::norm($oferta['f'])] = $oferta['p'];
                }

                $linhas[] = [
                    Cotacao::SETORES[$folha->meta['setor'] ?? ''] ?? '',
                    $linha['v']['desc'] ?? '',
                    self::gtin($linha['v']['gtin'] ?? ''),
                    ...array_map(fn($k) => ['v' => $precos[$k] ?? null, 'fmt' => 'moeda'], array_keys($fornecedores)),
                ];
            }
        }

        $planilha->aba('Comparativo', [10, 44, 15, ...array_fill(0, count($fornecedores), 14)], $linhas, congelarLinhas: 1, filtro: true);
    }

    // ─── Células ───────────────────────────────────────────────────────────────

    /** Valor e cor de uma célula: a da célula vence a da linha, que vence a da coluna. */
    private static function celula(array $linha, ?array $coluna, string $id): array
    {
        $valor = $linha['v'][$id] ?? null;
        $tipo = $coluna['tipo'] ?? 'texto';

        $estilo = self::estiloDe($linha['e'][$id] ?? null)
            + self::estiloDe($linha['s'] ?? null)
            + self::estiloDe($coluna['estilo'] ?? null);

        if ($id === 'gtin') {
            return self::gtin($valor) + $estilo;
        }

        if ($tipo !== 'texto' && is_numeric($valor)) {
            return ['v' => (float) $valor, 'fmt' => $tipo === 'moeda' ? 'moeda' : null] + $estilo;
        }

        return ['v' => $valor] + $estilo;
    }

    /** GTIN vai como número (o fornecedor soma, filtra e cola de volta) quando cabe. */
    private static function gtin(mixed $valor): array
    {
        $g = (string) $valor;

        return preg_match('/^[1-9]\d{0,14}$/', $g) ? ['v' => (int) $g, 'fmt' => 'inteiro'] : ['v' => $g];
    }

    private static function comprada(array $linha): array
    {
        return trim((string) ($linha['v']['fornecedor'] ?? '')) !== '' ? self::COMPRADA : [];
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v : null;
    }

    private static function estiloDe(?array $e): array
    {
        if (! $e) {
            return [];
        }

        return array_filter([
            'bg'  => $e['bg'] ?? null,
            'cor' => $e['cor'] ?? null,
            'b'   => $e['b'] ?? null,
            'i'   => $e['i'] ?? null,
        ]);
    }
}
