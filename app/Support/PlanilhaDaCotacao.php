<?php

namespace App\Support;

use App\Models\Cotacao;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * O que cada planilha da Cotação CEASA quer dizer.
 *
 * Veio da ferramenta avulsa (cotacao-ceasa/dev/core.js) e lê os mesmos dois
 * arquivos:
 *
 *   • a LISTA DE COMPRA — o relatório de estoque baixo que o ERP exporta, uma
 *     por loja e setor. O título ("Bebidas Hiper Gentil Bicalho") diz de qual
 *     loja é;
 *   • a RESPOSTA do fornecedor — a lista de cotação que mandamos, devolvida com
 *     a coluna "Valor" preenchida e o nome dele no campo "Fornecedor:".
 *
 * A lista de Frios é diferente: só Produto, Descrição, GTIN e a CONTAGEM do
 * estoque — sem última entrada, custo nem venda.
 *
 * Nenhuma das duas tem posição fixa de coluna: o relatório do ERP muda de um
 * mês para o outro e o fornecedor às vezes apaga ou reordena coluna. Por isso
 * tudo é achado pelo nome do cabeçalho.
 */
final class PlanilhaDaCotacao
{
    /** Nomes de cabeçalho aceitos, já normalizados (sem acento, minúsculo). */
    private const ALIAS = [
        'gtin'    => ['gtin principal', 'gtin', 'ean', 'codigo de barras', 'cod barras'],
        // "descrio" e "ltima entrada": o mesmo cabeçalho quando o ERP exporta com
        // a acentuação quebrada e a letra some.
        'desc'    => ['descricao', 'descrio', 'descricao do produto', 'produto descricao'],
        'produto' => ['produto', 'codigo', 'cod'],
        'custo'   => ['custo'],
        'venda'   => ['venda', 'preco venda'],
        'qtd'     => ['qtd.', 'qtd', 'qtd. ult. ent.', 'qtd. lt. ent.', 'quantidade'],
        'data'    => ['data', 'ultima entrada', 'ltima entrada'],
        'forn'    => ['forn. ult. ent.', 'forn. lt. ent.', 'fornecedor'],
        'contagem' => ['contagem'],
        'valor'   => ['valor', 'preco', 'valor unitario', 'preco unitario', 'valor (r$)', 'preco (r$)'],
    ];

    // ─── Colunas de cada folha ─────────────────────────────────────────────────
    //
    // `gerada`: a coluna é escrita pelo sistema ao gerar a folha, e gerar de
    // novo a sobrescreve. As outras são do comprador e sobrevivem.
    //
    // `opcional`: a coluna só aparece na folha que tem alguma coisa nela — a
    // Contagem só vem na lista de Frios, e a última entrada só nas de
    // Mercearia e Bebidas. Coluna vazia na folha inteira só ocuparia lugar.

    public const COLUNAS_LISTA = [
        ['id' => 'produto', 'nome' => 'Produto',         'tipo' => 'texto', 'largura' => 80],
        ['id' => 'desc',    'nome' => 'Descrição',       'tipo' => 'texto', 'largura' => 300],
        ['id' => 'gtin',    'nome' => 'GTIN',            'tipo' => 'texto', 'largura' => 125],
        ['id' => 'contagem', 'nome' => 'Contagem',       'tipo' => 'numero', 'largura' => 85, 'opcional' => true],
        ['id' => 'data',    'nome' => 'Últ. entrada',    'tipo' => 'texto', 'largura' => 95],
        ['id' => 'qtd',     'nome' => 'Qtd.',            'tipo' => 'numero', 'largura' => 70],
        ['id' => 'forn',    'nome' => 'Forn. últ. ent.', 'tipo' => 'texto', 'largura' => 160],
        ['id' => 'custo',   'nome' => 'Custo',           'tipo' => 'moeda', 'largura' => 85],
        ['id' => 'venda',   'nome' => 'Venda',           'tipo' => 'moeda', 'largura' => 85],
    ];

    public const COLUNAS_PREVIA = [
        ['id' => 'setor', 'nome' => 'Setor',     'tipo' => 'texto', 'largura' => 100],
        ['id' => 'gtin',  'nome' => 'GTIN',      'tipo' => 'texto', 'largura' => 125],
        ['id' => 'desc',  'nome' => 'Descrição', 'tipo' => 'texto', 'largura' => 340],
        ['id' => 'valor', 'nome' => 'Valor',     'tipo' => 'moeda', 'largura' => 90],
    ];

    public const COLUNAS_RESPOSTA = [
        ['id' => 'gtin',  'nome' => 'GTIN',      'tipo' => 'texto', 'largura' => 125],
        ['id' => 'desc',  'nome' => 'Descrição', 'tipo' => 'texto', 'largura' => 340],
        ['id' => 'valor', 'nome' => 'Valor',     'tipo' => 'moeda', 'largura' => 90],
    ];

    public const COLUNAS_COMPRA = [
        ['id' => 'produto',    'nome' => 'Produto',          'tipo' => 'texto',  'largura' => 75,  'gerada' => true],
        ['id' => 'desc',       'nome' => 'Descrição',        'tipo' => 'texto',  'largura' => 280, 'gerada' => true],
        ['id' => 'gtin',       'nome' => 'GTIN',             'tipo' => 'texto',  'largura' => 120, 'gerada' => true],
        ['id' => 'contagem',   'nome' => 'Contagem',         'tipo' => 'numero', 'largura' => 80,  'gerada' => true, 'opcional' => true],
        ['id' => 'data',       'nome' => 'Últ. entrada',     'tipo' => 'texto',  'largura' => 95,  'gerada' => true, 'opcional' => true],
        ['id' => 'qtd',        'nome' => 'Qtd. últ. ent.',   'tipo' => 'numero', 'largura' => 80,  'gerada' => true, 'opcional' => true],
        ['id' => 'forn',       'nome' => 'Forn. últ. ent.',  'tipo' => 'texto',  'largura' => 150, 'gerada' => true, 'opcional' => true],
        ['id' => 'custo',      'nome' => 'Custo',            'tipo' => 'moeda',  'largura' => 80,  'gerada' => true],
        ['id' => 'venda',      'nome' => 'Venda',            'tipo' => 'moeda',  'largura' => 80,  'gerada' => true],
        ['id' => 'forn1',      'nome' => '1º Fornecedor',    'tipo' => 'texto',  'largura' => 150, 'gerada' => true],
        ['id' => 'preco1',     'nome' => '1º Preço',         'tipo' => 'moeda',  'largura' => 80,  'gerada' => true],
        ['id' => 'vs',         'nome' => 'vs custo',         'tipo' => 'texto',  'largura' => 70,  'gerada' => true],
        ['id' => 'forn2',      'nome' => '2º Fornecedor',    'tipo' => 'texto',  'largura' => 150, 'gerada' => true],
        ['id' => 'preco2',     'nome' => '2º Preço',         'tipo' => 'moeda',  'largura' => 80,  'gerada' => true],
        ['id' => 'cotacoes',   'nome' => 'Cotações',         'tipo' => 'numero', 'largura' => 70,  'gerada' => true],
        ['id' => 'fator',      'nome' => 'Fator',            'tipo' => 'numero', 'largura' => 70],
        ['id' => 'quantidade', 'nome' => 'Quantidade',       'tipo' => 'numero', 'largura' => 90],
        ['id' => 'preco_forn', 'nome' => 'Preço for',        'tipo' => 'moeda',  'largura' => 90],
        ['id' => 'fornecedor', 'nome' => 'Fornecedor',       'tipo' => 'texto',  'largura' => 150],
    ];

    /** As colunas padrão de cada tipo de folha. */
    public static function colunasPadrao(string $tipo): array
    {
        return match ($tipo) {
            'lista'    => self::COLUNAS_LISTA,
            'previa'   => self::COLUNAS_PREVIA,
            'resposta' => self::COLUNAS_RESPOSTA,
            'compra'   => self::COLUNAS_COMPRA,
        };
    }

    /**
     * Junta as colunas que a folha (ou a folha-modelo) já tem com as padrão.
     *
     *   • a do sistema que faltar volta no lugar dela — logo depois da coluna
     *     padrão que vem antes, e não no fim;
     *   • nome antigo que o sistema mesmo deu é trocado pelo novo ("Preço
     *     fornecedor" virou "Preço for"); nome que o comprador escolheu fica;
     *   • a `opcional` entra só se estiver em $comDados — e sai se não estiver.
     *
     * O resto (largura, cor, oculta, as colunas criadas pelo comprador) é dele
     * e passa intacto.
     *
     * @param  list<string>  $comDados  as colunas que têm valor em alguma linha da folha
     */
    public static function completarColunas(array $atuais, string $tipo, array $comDados = []): array
    {
        $padrao = self::colunasPadrao($tipo);
        $porId = array_column($padrao, null, 'id');

        $semDados = fn(array $c) => ! empty($c['opcional']) && ! in_array($c['id'], $comDados, true);
        $padrao = array_values(array_filter($padrao, fn($c) => ! $semDados($c)));
        $atuais = array_values(array_filter($atuais, fn($c) => ! (isset($porId[$c['id'] ?? '']) && $semDados($porId[$c['id']]))));

        foreach ($atuais as $j => $coluna) {
            $nomeAntigo = self::NOMES_ANTIGOS[$coluna['id'] ?? ''] ?? null;

            if ($nomeAntigo !== null && ($coluna['nome'] ?? '') === $nomeAntigo) {
                $atuais[$j]['nome'] = $porId[$coluna['id']]['nome'];
            }

            // `gerada` e `opcional` são do sistema: vale o que o padrão diz,
            // nunca o que veio.
            if (isset($porId[$coluna['id'] ?? ''])) {
                foreach (['gerada', 'opcional'] as $marca) {
                    unset($atuais[$j][$marca]);
                    if (! empty($porId[$coluna['id']][$marca])) {
                        $atuais[$j][$marca] = true;
                    }
                }
            }
        }

        $atuais = array_values($atuais);

        foreach ($padrao as $i => $coluna) {
            $ids = array_column($atuais, 'id');

            if (in_array($coluna['id'], $ids, true)) {
                continue;
            }

            // Logo depois da coluna padrão mais próxima, das de antes, que já
            // está na folha.
            $posicao = 0;
            for ($a = $i - 1; $a >= 0; $a--) {
                $anterior = array_search($padrao[$a]['id'], $ids, true);
                if ($anterior !== false) {
                    $posicao = $anterior + 1;
                    break;
                }
            }

            array_splice($atuais, $posicao, 0, [$coluna]);
        }

        return $atuais;
    }

    /**
     * As colunas que têm valor em pelo menos uma das linhas.
     *
     * @return list<string>
     */
    public static function colunasComDados(array $linhas): array
    {
        $ids = [];
        foreach ($linhas as $linha) {
            foreach ($linha['v'] ?? [] as $id => $valor) {
                if ($valor !== null && $valor !== '') {
                    $ids[$id] = true;
                }
            }
        }

        return array_map('strval', array_keys($ids));
    }

    /** Nomes que o próprio sistema já deu a uma coluna e trocou depois. */
    private const NOMES_ANTIGOS = [
        'preco_forn' => 'Preço fornecedor',
    ];

    // ─── Lista de compra ───────────────────────────────────────────────────────

    /**
     * @param  list<array{nome: string, linhas: list<array<int, string>>}>  $abas
     * @return array{titulo: string, loja: string, setor: string, linhas: list<array>}
     */
    public static function lerLista(array $abas, string $nomeDoArquivo): array
    {
        foreach ($abas as $aba) {
            $linhas = array_values(array_filter($aba['linhas'], fn($l) => $l !== []));
            $cabecalho = self::acharCabecalho($linhas);

            if ($cabecalho === null || ! isset($cabecalho['col']['desc'])) {
                continue;
            }

            [$inicio, $col] = [$cabecalho['linha'], $cabecalho['col']];

            $titulo = trim(implode(' ', array_map(
                fn($l) => implode(' ', array_filter(array_map('trim', $l), fn($t) => $t !== '')),
                array_slice($linhas, 0, $inicio),
            )));

            $itens = [];
            foreach (array_slice($linhas, $inicio + 1) as $linha) {
                $pega = fn(string $k) => isset($col[$k]) ? trim((string) ($linha[$col[$k]] ?? '')) : '';

                $desc = $pega('desc');
                $gtin = self::gtin($pega('gtin'));

                if ($desc === '' && $gtin === '') {
                    continue;
                }

                $itens[] = self::linha([
                    'produto' => self::codigo($pega('produto')),
                    'desc'    => $desc,
                    'gtin'    => $gtin,
                    'data'    => LeitorDePlanilha::dataDoExcel($pega('data')),
                    'qtd'     => self::numero($pega('qtd')),
                    'forn'    => $pega('forn'),
                    'custo'   => self::numero($pega('custo')),
                    'venda'   => self::numero($pega('venda')),
                    'contagem' => self::numero($pega('contagem')),
                ]);
            }

            if ($itens === []) {
                continue;
            }

            $peloTitulo  = self::adivinhar($titulo);
            $peloArquivo = self::adivinhar($nomeDoArquivo);

            return [
                'titulo' => mb_substr($titulo, 0, 120),
                'loja'   => $peloTitulo['loja'] ?: $peloArquivo['loja'],
                'setor'  => $peloTitulo['setor'] ?: ($peloArquivo['setor'] ?: 'merc'),
                'linhas' => $itens,
            ];
        }

        throw new RuntimeException('Não encontrei a coluna "GTIN Principal" ou "Descrição" nesta planilha.');
    }

    // ─── Resposta do fornecedor ────────────────────────────────────────────────

    /**
     * @param  list<array{nome: string, linhas: list<array<int, string>>}>  $abas
     * @return array{fornecedor: string, linhas: list<array>}
     */
    public static function lerResposta(array $abas, string $nomeDoArquivo): array
    {
        foreach ($abas as $aba) {
            $linhas = array_values(array_filter($aba['linhas'], fn($l) => $l !== []));
            $cabecalho = self::acharCabecalho($linhas, exigirValor: true);

            if ($cabecalho === null || ! isset($cabecalho['col']['valor'])) {
                continue;
            }

            [$inicio, $col] = [$cabecalho['linha'], $cabecalho['col']];

            $itens = [];
            foreach (array_slice($linhas, $inicio + 1) as $linha) {
                $gtin = self::gtin(isset($col['gtin']) ? (string) ($linha[$col['gtin']] ?? '') : '');
                $desc = trim(isset($col['desc']) ? (string) ($linha[$col['desc']] ?? '') : '');

                if ($gtin === '' && $desc === '') {
                    continue;
                }

                // A linha "Bebidas" que separa os setores na lista que mandamos.
                if ($gtin === '' && self::ehNomeDeSetor($desc)) {
                    continue;
                }

                $valor = self::numero((string) ($linha[$col['valor']] ?? ''));

                $itens[] = self::linha([
                    'gtin'  => $gtin,
                    'desc'  => $desc,
                    'valor' => $valor !== null && $valor > 0 ? $valor : null,
                ]);
            }

            $fornecedor = self::fornecedorDoCabecalho(array_slice($linhas, 0, $inicio));

            return [
                'fornecedor' => $fornecedor !== '' ? $fornecedor : self::semExtensao($nomeDoArquivo),
                'linhas'     => $itens,
            ];
        }

        throw new RuntimeException('Não encontrei a coluna "Valor" nesta planilha.');
    }

    /**
     * O nome escrito no campo "Fornecedor:" — na mesma célula ("Fornecedor:
     * UPSIDE") ou na do lado. O texto de instrução que mandamos não conta.
     *
     * @param  list<array<int, string>>  $linhas
     */
    private static function fornecedorDoCabecalho(array $linhas): string
    {
        foreach ($linhas as $linha) {
            ksort($linha);
            $celulas = array_values($linha);

            foreach ($celulas as $i => $celula) {
                $n = self::norm($celula);

                if (! preg_match('/^fornecedor\s*:?/', $n)) {
                    continue;
                }

                $resto = trim((string) preg_replace('/^\s*fornecedor\s*:?\s*/iu', '', $celula));

                if ($resto === '') {
                    foreach (array_slice($celulas, $i + 1) as $proxima) {
                        if (trim($proxima) !== '') {
                            $resto = trim($proxima);
                            break;
                        }
                    }
                }

                return preg_match('/o fornecedor vai/', self::norm($resto)) ? '' : mb_substr($resto, 0, 120);
            }
        }

        return '';
    }

    // ─── Cabeçalho ─────────────────────────────────────────────────────────────

    /**
     * A linha de cabeçalho (a que tem "GTIN Principal" ou "Descrição") e em
     * que coluna está cada campo.
     *
     * @param  list<array<int, string>>  $linhas
     * @return array{linha: int, col: array<string, int>}|null
     */
    private static function acharCabecalho(array $linhas, bool $exigirValor = false): ?array
    {
        foreach (array_slice($linhas, 0, 30) as $i => $linha) {
            $celulas = array_map(fn($c) => self::norm($c), $linha);

            $temChave = self::temAlgum($celulas, 'gtin') || self::temAlgum($celulas, 'desc');

            if (! $temChave || ($exigirValor && ! self::temAlgum($celulas, 'valor') && ! self::temAlgum($celulas, 'gtin'))) {
                continue;
            }

            ksort($celulas);
            $col = [];

            foreach (self::ALIAS as $campo => $nomes) {
                // A primeira coluna com o nome — o relatório às vezes tem dois "Custo".
                $achada = null;

                foreach ($celulas as $indice => $texto) {
                    if (in_array($texto, $nomes, true)) {
                        $achada = $indice;
                        break;
                    }
                }

                if ($achada === null && in_array($campo, ['custo', 'valor'], true)) {
                    foreach ($celulas as $indice => $texto) {
                        if ($campo === 'custo' ? str_starts_with($texto, 'custo') : (str_starts_with($texto, 'valor') || str_starts_with($texto, 'preco'))) {
                            $achada = $indice;
                            break;
                        }
                    }
                }

                if ($achada !== null) {
                    $col[$campo] = $achada;
                }
            }

            // "Produto" é o código — não pode ser a mesma coluna da descrição.
            if (isset($col['produto'], $col['desc']) && $col['produto'] === $col['desc']) {
                unset($col['produto']);
            }

            return ['linha' => $i, 'col' => $col];
        }

        return null;
    }

    private static function temAlgum(array $celulas, string $campo): bool
    {
        return array_intersect($celulas, self::ALIAS[$campo]) !== [];
    }

    // ─── Utilidades (usadas também na montagem) ────────────────────────────────

    /** Sem acento, minúsculo e com um espaço só — para comparar nomes. */
    public static function norm(mixed $texto): string
    {
        $texto = str_replace("\u{FFFD}", '', (string) $texto);
        $texto = \Normalizer::normalize($texto, \Normalizer::FORM_D) ?: $texto;
        $texto = preg_replace('/\p{Mn}+/u', '', $texto) ?? $texto;

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($texto)) ?? '');
    }

    /**
     * A identidade do produto: o GTIN sem zeros à esquerda ou, sem GTIN, a
     * descrição. É o que junta o mesmo produto das duas lojas e acha o preço
     * dele na resposta do fornecedor.
     */
    public static function chave(mixed $gtin, mixed $desc): string
    {
        $g = ltrim(self::gtin((string) $gtin), '0');

        return $g !== '' ? 'g' . $g : 'd' . self::norm($desc);
    }

    /** Só os dígitos. Número grande do Excel pode vir em notação científica. */
    public static function gtin(string $valor): string
    {
        $valor = trim($valor);

        if (preg_match('/^\d+(\.\d+)?E\+?\d+$/i', $valor)) {
            $valor = number_format((float) $valor, 0, '', '');
        } elseif (preg_match('/^\d+\.0+$/', $valor)) {
            $valor = strstr($valor, '.', true);
        }

        return preg_replace('/\D/', '', $valor) ?? '';
    }

    /** "R$ 5,50", "1.234,56" e "10.536447" (o número cru do Excel). */
    public static function numero(mixed $bruto): ?float
    {
        if (is_int($bruto) || is_float($bruto)) {
            return is_finite((float) $bruto) ? (float) $bruto : null;
        }

        $texto = preg_replace('/\s|r\$/iu', '', trim((string) $bruto)) ?? '';

        if ($texto === '') {
            return null;
        }

        if (str_contains($texto, ',')) {
            $texto = str_replace(',', '.', str_replace('.', '', $texto));
        }

        if (preg_match('/^-?\d+(\.\d+)?(E[+-]?\d+)?$/i', $texto) !== 1) {
            return null;
        }

        return (float) $texto;
    }

    /** Código do produto: "40938" fica "40938", e não "40938.0". */
    private static function codigo(string $valor): string
    {
        return preg_match('/^\d+\.0+$/', $valor) ? (string) strstr($valor, '.', true) : $valor;
    }

    /** @return array{loja: string, setor: string} */
    public static function adivinhar(string $texto): array
    {
        $t = self::norm($texto);

        return [
            'loja'  => preg_match('/wilson|alvarenga/', $t) ? 'wa' : (preg_match('/gentil|bicalho/', $t) ? 'gb' : ''),
            'setor' => match (true) {
                (bool) preg_match('/bebida|\bbeb\b/', $t)     => 'beb',
                (bool) preg_match('/mercearia|\bmerc\b/', $t) => 'merc',
                (bool) preg_match('/\bfrios?\b/', $t)          => 'frios',
                default                                        => '',
            },
        ];
    }

    public static function ehNomeDeSetor(string $texto): bool
    {
        return in_array(self::norm($texto), array_map(fn($s) => self::norm($s), Cotacao::SETORES), true);
    }

    public static function semExtensao(string $arquivo): string
    {
        return mb_substr((string) preg_replace('/\.[^.]+$/', '', $arquivo), 0, 120);
    }

    /** Uma linha nova da folha, sem as células vazias. */
    public static function linha(array $valores, ?string $k = null): array
    {
        $v = array_filter($valores, fn($x) => $x !== null && $x !== '');
        $linha = ['id' => self::novoId(), 'v' => $v];

        if ($k !== null) {
            $linha['k'] = $k;
        }

        return $linha;
    }

    public static function novoId(): string
    {
        return Str::lower(Str::random(10));
    }
}
