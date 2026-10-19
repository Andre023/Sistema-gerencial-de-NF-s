<?php

namespace App\Services;

use App\Models\Configuracao;
use App\Models\Cotacao;
use App\Models\CotacaoFolha;
use App\Support\PlanilhaDaCotacao as P;
use Collator;
use Illuminate\Support\Collection;

/**
 * Monta as folhas que nascem das outras: a prévia (o que vai ao fornecedor) e
 * a planilha de compra (o menor preço de cada produto, por loja e setor).
 *
 * ── Gerar de novo não apaga trabalho ──────────────────────────────────────────
 * As duas podem ser geradas quantas vezes for preciso — chegou mais uma
 * resposta de fornecedor, alguém corrigiu uma lista. A linha de cada produto é
 * reencontrada pela chave (`k`) e mantém o que é do comprador:
 *
 *   • na prévia, a linha inteira como ele deixou (descrição corrigida, cor);
 *   • na planilha de compra, as cores, o Fator, a Quantidade, o Preço
 *     fornecedor e qualquer coluna que ele criou. Só as colunas `gerada`
 *     (fornecedores, preços, custo) são reescritas — é para isso que se gera.
 *
 * Linha escrita à mão (sem chave) nunca é apagada.
 */
final class MontagemDaCotacao
{
    // ─── Prévia ────────────────────────────────────────────────────────────────

    public static function previa(Cotacao $cotacao): CotacaoFolha
    {
        $folhas = $cotacao->folhas()->get();
        $listas = $folhas->where('tipo', 'lista');
        $atual  = $folhas->firstWhere('tipo', 'previa');

        $existentes = [];
        $manuais = [];
        foreach ($atual?->linhas ?? [] as $linha) {
            if (isset($linha['k'])) {
                $existentes[$linha['k']] = $linha;
            } else {
                $manuais[] = $linha;
            }
        }

        $linhas = [];
        foreach (Cotacao::SETORES as $setor => $nomeDoSetor) {
            $doSetor = [];

            foreach ($listas->filter(fn($l) => ($l->meta['setor'] ?? '') === $setor) as $lista) {
                foreach ($lista->linhas as $item) {
                    $v = $item['v'] ?? [];
                    $k = P::chave($v['gtin'] ?? '', $v['desc'] ?? '');

                    if ($k === 'd' || isset($doSetor[$k])) {
                        continue;
                    }

                    $doSetor[$k] = $existentes[$k] ?? P::linha([
                        'setor' => $nomeDoSetor,
                        'gtin'  => $v['gtin'] ?? '',
                        'desc'  => $v['desc'] ?? '',
                    ], $k);
                }
            }

            $linhas = array_merge($linhas, array_values($doSetor));
        }

        $linhas = array_merge($linhas, $manuais);

        // Setor na ordem da lista que vai ao fornecedor, depois a descrição.
        $ordemDoSetor = array_flip(array_map(fn($s) => P::norm($s), array_values(Cotacao::SETORES)));
        $collator = new Collator('pt_BR');

        usort($linhas, function ($a, $b) use ($ordemDoSetor, $collator) {
            $sa = $ordemDoSetor[P::norm($a['v']['setor'] ?? '')] ?? 99;
            $sb = $ordemDoSetor[P::norm($b['v']['setor'] ?? '')] ?? 99;

            return $sa <=> $sb ?: $collator->compare((string) ($a['v']['desc'] ?? ''), (string) ($b['v']['desc'] ?? ''));
        });

        return self::gravar($cotacao, $atual, [
            'tipo'    => 'previa',
            'nome'    => 'Prévia da cotação',
            'colunas' => P::completarColunas($atual?->colunas ?? self::modeloDeColunas($cotacao, 'previa'), 'previa'),
            'linhas'  => $linhas,
            'ordem'   => 0,
        ]);
    }

    // ─── Resposta em branco ────────────────────────────────────────────────────

    /**
     * Uma resposta para digitar à mão — o fornecedor que passou os preços por
     * telefone. Nasce com os produtos da prévia e a coluna Valor vazia.
     */
    public static function respostaEmBranco(Cotacao $cotacao, string $fornecedor): CotacaoFolha
    {
        $previa = $cotacao->folhas()->where('tipo', 'previa')->first();

        $linhas = array_map(fn($l) => P::linha([
            'gtin' => $l['v']['gtin'] ?? '',
            'desc' => $l['v']['desc'] ?? '',
        ]), $previa?->linhas ?? []);

        return $cotacao->folhas()->create([
            'tipo'    => 'resposta',
            'nome'    => $fornecedor,
            'meta'    => ['fornecedor' => $fornecedor],
            'colunas' => self::colunas($cotacao, 'resposta'),
            'linhas'  => $linhas,
            'ordem'   => self::proximaOrdem($cotacao, 'resposta'),
        ]);
    }

    // ─── Planilha de compra ────────────────────────────────────────────────────

    /**
     * Uma folha por loja e setor que tenha lista — "Gentil Bicalho · Bebidas".
     *
     * @return Collection<int, CotacaoFolha>
     */
    public static function compra(Cotacao $cotacao): Collection
    {
        $folhas = $cotacao->folhas()->get();
        $listas = $folhas->where('tipo', 'lista');
        $indice = self::indiceDePrecos($folhas->where('tipo', 'resposta'));
        $collator = new Collator('pt_BR');

        $geradas = collect();
        $ordem = 0;

        foreach (Cotacao::LOJAS as $loja => $dadosDaLoja) {
            foreach (Cotacao::SETORES as $setor => $nomeDoSetor) {
                $ordem++;
                $doGrupo = $listas->filter(fn($l) => ($l->meta['loja'] ?? '') === $loja && ($l->meta['setor'] ?? '') === $setor);

                if ($doGrupo->isEmpty()) {
                    continue;
                }

                $atual = $folhas->first(fn($f) => $f->tipo === 'compra'
                    && ($f->meta['loja'] ?? '') === $loja && ($f->meta['setor'] ?? '') === $setor);

                $itens = [];
                foreach ($doGrupo as $lista) {
                    foreach ($lista->linhas as $item) {
                        $v = $item['v'] ?? [];
                        $k = P::chave($v['gtin'] ?? '', $v['desc'] ?? '');

                        if ($k !== 'd' && ! isset($itens[$k])) {
                            $itens[$k] = $v;
                        }
                    }
                }

                $geradas->push(self::gravar($cotacao, $atual, [
                    'tipo'    => 'compra',
                    'nome'    => "{$dadosDaLoja['nome']} · {$nomeDoSetor}",
                    'meta'    => ['loja' => $loja, 'setor' => $setor],
                    'colunas' => P::completarColunas($atual?->colunas ?? self::modeloDeColunas($cotacao, 'compra', ['loja' => $loja, 'setor' => $setor]), 'compra'),
                    'linhas'  => self::linhasDaCompra($itens, $indice, $atual?->linhas ?? [], $collator),
                    'ordem'   => 100 + $ordem,
                ]));
            }
        }

        return $geradas;
    }

    /**
     * As colunas de uma folha nova: o jeito que os compradores deixaram a
     * última folha do mesmo tipo — colunas ocultas, nomes, larguras, cores e
     * as colunas que eles criaram. Assim a cotação da semana que vem já nasce
     * arrumada como a desta.
     */
    public static function colunas(Cotacao $cotacao, string $tipo, array $meta = []): array
    {
        return P::completarColunas(self::modeloDeColunas($cotacao, $tipo, $meta), $tipo);
    }

    /**
     * O modelo de colunas de um tipo de folha: o último jeito em que alguém
     * arrumou as colunas dela (ver guardarModelo). A planilha de compra tem um
     * por loja e setor — a de Bebidas não dita a de Mercearia —, e na falta
     * dele vale o da última planilha de compra arrumada.
     */
    private static function modeloDeColunas(Cotacao $cotacao, string $tipo, array $meta = []): array
    {
        foreach (self::chavesDoModelo($tipo, $meta) as $chave) {
            $colunas = json_decode((string) Configuracao::obter($chave), true);

            if (is_array($colunas) && $colunas !== []) {
                return $colunas;
            }
        }

        return P::colunasPadrao($tipo);
    }

    /**
     * Alguém mexeu nas colunas (ocultou, renomeou, mudou a largura, criou,
     * apagou, pintou a coluna): esse passa a ser o jeito das próximas folhas.
     *
     * Guardado à parte, e não lido da "folha alterada por último": senão
     * corrigir uma célula da lista do WA apagaria a coluna que alguém acabou
     * de ocultar na do GB.
     */
    public static function guardarModelo(CotacaoFolha $folha): void
    {
        $colunas = json_encode(array_values($folha->colunas ?? []), JSON_UNESCAPED_UNICODE);

        foreach (self::chavesDoModelo($folha->tipo, $folha->meta ?? []) as $chave) {
            Configuracao::definir($chave, $colunas);
        }
    }

    /** @return list<string> da mais específica para a mais geral */
    private static function chavesDoModelo(string $tipo, array $meta): array
    {
        $chaves = [];

        if ($tipo === 'compra' && isset($meta['loja'], $meta['setor'])) {
            $chaves[] = "cotacao_colunas_compra_{$meta['loja']}_{$meta['setor']}";
        }

        $chaves[] = "cotacao_colunas_{$tipo}";

        return $chaves;
    }

    private static function linhasDaCompra(array $itens, array $indice, array $atuais, Collator $collator): array
    {
        $doSistema = array_column(array_filter(P::COLUNAS_COMPRA, fn($c) => $c['gerada'] ?? false), 'id');
        $doSistema = array_flip($doSistema);

        $anteriores = [];
        $manuais = [];
        foreach ($atuais as $linha) {
            isset($linha['k']) ? $anteriores[$linha['k']] = $linha : $manuais[] = $linha;
        }

        uasort($itens, fn($a, $b) => $collator->compare((string) ($a['desc'] ?? ''), (string) ($b['desc'] ?? '')));

        $linhas = [];
        foreach ($itens as $k => $v) {
            $ofertas = self::ofertas($k, (string) ($v['desc'] ?? ''), $indice);
            [$p1, $p2] = [$ofertas[0] ?? null, $ofertas[1] ?? null];
            $custo = isset($v['custo']) && is_numeric($v['custo']) ? (float) $v['custo'] : null;

            $gerado = array_filter([
                'produto'  => $v['produto'] ?? null,
                'desc'     => $v['desc'] ?? null,
                'gtin'     => $v['gtin'] ?? null,
                'custo'    => $custo,
                'venda'    => $v['venda'] ?? null,
                'forn1'    => $p1['f'] ?? 'sem cotação',
                'preco1'   => $p1['p'] ?? null,
                'vs'       => $p1 && $custo ? self::diferenca($p1['p'], $custo) : null,
                'forn2'    => $p2['f'] ?? null,
                'preco2'   => $p2['p'] ?? null,
                'cotacoes' => count($ofertas),
            ], fn($x) => $x !== null && $x !== '');

            $anterior = $anteriores[$k] ?? null;
            unset($anteriores[$k]);

            $linha = $anterior ?? ['id' => P::novoId(), 'k' => $k];
            $doComprador = array_diff_key($anterior['v'] ?? [], $doSistema);
            $linha['v'] = $gerado + $doComprador;
            $linha['of'] = $ofertas;

            $linhas[] = $linha;
        }

        // Produto que saiu das listas mas em que o comprador já escreveu: fica,
        // no fim, em vez de levar a anotação junto.
        foreach ($anteriores as $linha) {
            if (array_diff_key($linha['v'] ?? [], $doSistema) !== []) {
                $linhas[] = $linha;
            }
        }

        return array_merge($linhas, $manuais);
    }

    /** "+12%" / "-5%": o 1º preço comparado com o custo da lista. */
    private static function diferenca(float $preco, float $custo): string
    {
        $d = round(($preco - $custo) / $custo * 100);

        return ($d > 0 ? '+' : '') . (int) $d . '%';
    }

    /**
     * Produto → os preços de cada fornecedor (o menor, se o mesmo fornecedor
     * mandou duas respostas). Indexado pela chave e, à parte, pela descrição —
     * o fornecedor às vezes apaga o GTIN e deixa só o nome.
     *
     * @return array{chave: array<string, array<string, array{f: string, p: float}>>, desc: array<string, array<string, array{f: string, p: float}>>}
     */
    public static function indiceDePrecos(Collection $respostas): array
    {
        $indice = ['chave' => [], 'desc' => []];

        foreach ($respostas as $resposta) {
            $fornecedor = trim((string) ($resposta->meta['fornecedor'] ?? '')) ?: $resposta->nome;
            $quem = P::norm($fornecedor);

            foreach ($resposta->linhas as $linha) {
                $v = $linha['v'] ?? [];
                $preco = P::numero($v['valor'] ?? null);

                if ($preco === null || $preco <= 0) {
                    continue;
                }

                foreach (['chave' => P::chave($v['gtin'] ?? '', $v['desc'] ?? ''), 'desc' => 'd' . P::norm($v['desc'] ?? '')] as $mapa => $k) {
                    $atual = $indice[$mapa][$k][$quem] ?? null;

                    if ($atual === null || $preco < $atual['p']) {
                        $indice[$mapa][$k][$quem] = ['f' => $fornecedor, 'p' => round($preco, 4)];
                    }
                }
            }
        }

        return $indice;
    }

    /** @return list<array{f: string, p: float}> do menor para o maior */
    private static function ofertas(string $k, string $desc, array $indice): array
    {
        $porFornecedor = $indice['chave'][$k]
            ?? (str_starts_with($k, 'g') ? ($indice['desc']['d' . P::norm($desc)] ?? []) : []);

        $ofertas = array_values($porFornecedor);
        usort($ofertas, fn($a, $b) => $a['p'] <=> $b['p'] ?: strcmp($a['f'], $b['f']));

        return $ofertas;
    }

    // ─── Gravação ──────────────────────────────────────────────────────────────

    private static function gravar(Cotacao $cotacao, ?CotacaoFolha $atual, array $dados): CotacaoFolha
    {
        if ($atual === null) {
            return $cotacao->folhas()->create($dados);
        }

        $atual->fill($dados);
        $atual->versao++;
        $atual->save();

        return $atual;
    }

    public static function proximaOrdem(Cotacao $cotacao, string $tipo): int
    {
        return (int) $cotacao->folhas()->where('tipo', $tipo)->max('ordem') + 1;
    }
}
