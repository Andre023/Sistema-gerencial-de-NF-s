<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Aplica numa folha as alterações que chegam da tela, uma a uma.
 *
 * ── Por que operações, e não a folha inteira ──────────────────────────────────
 * Dois compradores mexem na mesma planilha ao mesmo tempo. Se cada um mandasse
 * a folha inteira, o último a salvar apagaria o que o outro acabou de digitar.
 * Mandando só "a célula X da linha Y agora vale 10", as duas alterações valem —
 * e só quando os dois escrevem na MESMA célula é que a última ganha, como no
 * Google Planilhas.
 *
 * As linhas e colunas são achadas pelo id, nunca pela posição: a linha 12 da
 * tela de um pode não ser a 12 da tela do outro, se alguém acabou de inserir.
 *
 * O mesmo código existe na tela (resources/js/lib/cotacao.ts, aplicarOps) —
 * ela aplica a alteração na hora, sem esperar o servidor. Os dois precisam dar
 * o mesmo resultado; mudou um, muda o outro.
 *
 * Operações:
 *   cels    {itens: [[linha, coluna, valor|null], …]}
 *   est     {alvo: cel|lin|col, itens: [{l?, c?, e: estilo|null}]}  (substitui)
 *   ins     {apos: linha|null, linhas: [{id, k?, v?, s?, e?}]}       (null = no topo)
 *   del     {ids: [linha, …]}
 *   ord     {ids: [linha, …]}
 *   col     {coluna: {id, nome?, tipo?, largura?, oculta?}, apos?: coluna|''} ('' = primeira)
 *   delcol  {id}
 *   meta    {nome?, meta?: {…}}
 */
final class OperacoesDaFolha
{
    public const LIMITE_DE_LINHAS  = 5000;
    public const LIMITE_DE_COLUNAS = 40;
    private const LIMITE_DE_TEXTO  = 1000;

    private const TIPOS_DE_COLUNA = ['texto', 'numero', 'moeda'];

    /** O que a tela pode mudar na meta de cada folha. */
    private const CAMPOS_DA_META = ['loja', 'setor', 'fornecedor'];

    /**
     * @param  array{nome: string, meta: array, colunas: list<array>, linhas: list<array>}  $folha
     * @param  list<array>  $ops
     * @return array{nome: string, meta: array, colunas: list<array>, linhas: list<array>}
     *
     * @throws InvalidArgumentException operação malformada — nada é aplicado.
     */
    public static function aplicar(array $folha, array $ops): array
    {
        foreach ($ops as $op) {
            if (! is_array($op) || ! isset($op['t']) || ! is_string($op['t'])) {
                throw new InvalidArgumentException('Operação sem tipo.');
            }

            $folha = match ($op['t']) {
                'cels'   => self::celulas($folha, $op),
                'est'    => self::estilo($folha, $op),
                'ins'    => self::inserir($folha, $op),
                'del'    => self::remover($folha, $op),
                'ord'    => self::ordenar($folha, $op),
                'col'    => self::coluna($folha, $op),
                'delcol' => self::removerColuna($folha, $op),
                'meta'   => self::meta($folha, $op),
                default  => throw new InvalidArgumentException('Operação desconhecida: ' . $op['t']),
            };
        }

        return $folha;
    }

    // ─── Células ───────────────────────────────────────────────────────────────

    private static function celulas(array $folha, array $op): array
    {
        $posicao = self::posicoes($folha['linhas']);

        foreach (self::lista($op, 'itens') as $item) {
            if (! is_array($item) || count($item) !== 3) {
                throw new InvalidArgumentException('Célula malformada.');
            }

            [$l, $c, $v] = array_values($item);
            $c = self::idDeColuna($c);

            // Linha apagada por outro comprador no meio tempo: o valor não tem
            // onde morar. Ignorar é o que o Google Planilhas faz também.
            if (! isset($posicao[$l])) {
                continue;
            }

            $i = $posicao[$l];
            $valor = self::valor($v);

            if ($valor === null) {
                unset($folha['linhas'][$i]['v'][$c]);
            } else {
                $folha['linhas'][$i]['v'][$c] = $valor;
            }
        }

        return $folha;
    }

    private static function estilo(array $folha, array $op): array
    {
        $alvo = $op['alvo'] ?? null;
        $posicao = self::posicoes($folha['linhas']);

        foreach (self::lista($op, 'itens') as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Estilo malformado.');
            }

            $estilo = self::estiloLimpo($item['e'] ?? null);

            if ($alvo === 'col') {
                $c = self::idDeColuna($item['c'] ?? null);

                foreach ($folha['colunas'] as $j => $coluna) {
                    if ($coluna['id'] === $c) {
                        if ($estilo === null) {
                            unset($folha['colunas'][$j]['estilo']);
                        } else {
                            $folha['colunas'][$j]['estilo'] = $estilo;
                        }
                    }
                }

                continue;
            }

            $l = $item['l'] ?? null;

            if (! is_string($l) || ! isset($posicao[$l])) {
                continue;
            }

            $i = $posicao[$l];

            if ($alvo === 'lin') {
                if ($estilo === null) {
                    unset($folha['linhas'][$i]['s']);
                } else {
                    $folha['linhas'][$i]['s'] = $estilo;
                }
            } elseif ($alvo === 'cel') {
                $c = self::idDeColuna($item['c'] ?? null);

                if ($estilo === null) {
                    unset($folha['linhas'][$i]['e'][$c]);

                    if (($folha['linhas'][$i]['e'] ?? null) === []) {
                        unset($folha['linhas'][$i]['e']);
                    }
                } else {
                    $folha['linhas'][$i]['e'][$c] = $estilo;
                }
            } else {
                throw new InvalidArgumentException('Alvo de estilo inválido.');
            }
        }

        return $folha;
    }

    // ─── Linhas ────────────────────────────────────────────────────────────────

    private static function inserir(array $folha, array $op): array
    {
        $existentes = self::posicoes($folha['linhas']);
        $novas = [];

        foreach (self::lista($op, 'linhas') as $linha) {
            $nova = self::linhaLimpa($linha);

            // Inserir a mesma linha duas vezes (a tela reenviou) não duplica.
            if (! isset($existentes[$nova['id']])) {
                $novas[] = $nova;
                $existentes[$nova['id']] = true;
            }
        }

        if (count($folha['linhas']) + count($novas) > self::LIMITE_DE_LINHAS) {
            throw new InvalidArgumentException('A folha passaria de ' . self::LIMITE_DE_LINHAS . ' linhas.');
        }

        $apos = $op['apos'] ?? null;
        $indice = 0;

        if ($apos !== null) {
            $posicao = self::posicoes($folha['linhas']);
            // A linha de referência sumiu: vai para o fim, que é onde menos
            // atrapalha.
            $indice = isset($posicao[$apos]) ? $posicao[$apos] + 1 : count($folha['linhas']);
        }

        array_splice($folha['linhas'], $indice, 0, $novas);

        return $folha;
    }

    private static function remover(array $folha, array $op): array
    {
        $ids = array_flip(array_filter(self::lista($op, 'ids'), 'is_string'));

        $folha['linhas'] = array_values(array_filter($folha['linhas'], fn($l) => ! isset($ids[$l['id']])));

        return $folha;
    }

    private static function ordenar(array $folha, array $op): array
    {
        $porId = [];
        foreach ($folha['linhas'] as $linha) {
            $porId[$linha['id']] = $linha;
        }

        $ordenadas = [];
        foreach (self::lista($op, 'ids') as $id) {
            if (is_string($id) && isset($porId[$id])) {
                $ordenadas[] = $porId[$id];
                unset($porId[$id]);
            }
        }

        // Linha que outro comprador inseriu depois de esta tela ordenar: fica
        // no fim, em vez de sumir.
        $folha['linhas'] = array_merge($ordenadas, array_values($porId));

        return $folha;
    }

    // ─── Colunas ───────────────────────────────────────────────────────────────

    private static function coluna(array $folha, array $op): array
    {
        $dados = $op['coluna'] ?? null;

        if (! is_array($dados)) {
            throw new InvalidArgumentException('Coluna malformada.');
        }

        $id = self::idDeColuna($dados['id'] ?? null);
        $campos = [];

        if (array_key_exists('nome', $dados)) {
            $campos['nome'] = mb_substr(trim((string) $dados['nome']), 0, 60) ?: 'Coluna';
        }
        if (array_key_exists('tipo', $dados)) {
            $campos['tipo'] = in_array($dados['tipo'], self::TIPOS_DE_COLUNA, true) ? $dados['tipo'] : 'texto';
        }
        if (array_key_exists('largura', $dados)) {
            $campos['largura'] = max(40, min(800, (int) $dados['largura']));
        }
        if (array_key_exists('oculta', $dados)) {
            $campos['oculta'] = (bool) $dados['oculta'];
        }

        foreach ($folha['colunas'] as $j => $coluna) {
            if ($coluna['id'] === $id) {
                $folha['colunas'][$j] = array_merge($coluna, $campos);

                // Só guarda `oculta` quando é verdade — a folha não precisa
                // carregar "oculta: false" em cada coluna.
                if (empty($folha['colunas'][$j]['oculta'])) {
                    unset($folha['colunas'][$j]['oculta']);
                }

                return $folha;
            }
        }

        if (count($folha['colunas']) >= self::LIMITE_DE_COLUNAS) {
            throw new InvalidArgumentException('A folha já tem o máximo de colunas.');
        }

        // Coluna nova: é sempre do comprador, nunca "gerada".
        $nova = array_merge(['id' => $id, 'nome' => 'Coluna', 'tipo' => 'texto', 'largura' => 110], $campos);
        $indice = count($folha['colunas']);

        if (array_key_exists('apos', $op)) {
            if ($op['apos'] === '' || $op['apos'] === null) {
                $indice = 0;
            } else {
                foreach ($folha['colunas'] as $j => $coluna) {
                    if ($coluna['id'] === $op['apos']) {
                        $indice = $j + 1;
                    }
                }
            }
        }

        array_splice($folha['colunas'], $indice, 0, [$nova]);

        return $folha;
    }

    /**
     * Tira a coluna da folha. Os valores ficam guardados nas linhas — é o que
     * deixa o "desfazer" trazer a coluna de volta inteira.
     */
    private static function removerColuna(array $folha, array $op): array
    {
        $id = self::idDeColuna($op['id'] ?? null);

        $folha['colunas'] = array_values(array_filter($folha['colunas'], fn($c) => $c['id'] !== $id));

        return $folha;
    }

    private static function meta(array $folha, array $op): array
    {
        if (isset($op['nome'])) {
            $folha['nome'] = mb_substr(trim((string) $op['nome']), 0, 120) ?: $folha['nome'];
        }

        foreach ((array) ($op['meta'] ?? []) as $campo => $valor) {
            if (in_array($campo, self::CAMPOS_DA_META, true)) {
                $folha['meta'][$campo] = mb_substr(trim((string) $valor), 0, 120);
            }
        }

        return $folha;
    }

    // ─── Validação ─────────────────────────────────────────────────────────────

    /** @return array<string, int> */
    private static function posicoes(array $linhas): array
    {
        $posicao = [];
        foreach ($linhas as $i => $linha) {
            $posicao[$linha['id']] = $i;
        }

        return $posicao;
    }

    private static function lista(array $op, string $campo): array
    {
        $lista = $op[$campo] ?? null;

        if (! is_array($lista) || ! array_is_list($lista)) {
            throw new InvalidArgumentException("Operação sem a lista \"{$campo}\".");
        }

        return $lista;
    }

    private static function idDeColuna(mixed $id): string
    {
        if (! is_string($id) || preg_match('/^[a-z0-9_]{1,24}$/', $id) !== 1) {
            throw new InvalidArgumentException('Coluna inválida.');
        }

        return $id;
    }

    private static function valor(mixed $v): string|int|float|null
    {
        if ($v === null || $v === '') {
            return null;
        }

        if (is_int($v) || is_float($v)) {
            return is_finite((float) $v) ? $v : null;
        }

        if (is_string($v)) {
            return mb_substr($v, 0, self::LIMITE_DE_TEXTO);
        }

        if (is_bool($v)) {
            return $v ? 1 : null;
        }

        throw new InvalidArgumentException('Valor de célula inválido.');
    }

    /** Só as chaves conhecidas, e cor só como "#rrggbb". */
    public static function estiloLimpo(mixed $e): ?array
    {
        if (! is_array($e)) {
            return null;
        }

        $limpo = [];

        foreach (['bg', 'cor'] as $campo) {
            if (isset($e[$campo]) && is_string($e[$campo]) && preg_match('/^#[0-9a-f]{6}$/i', $e[$campo])) {
                $limpo[$campo] = strtolower($e[$campo]);
            }
        }

        foreach (['b', 'i'] as $campo) {
            if (! empty($e[$campo])) {
                $limpo[$campo] = true;
            }
        }

        return $limpo === [] ? null : $limpo;
    }

    private static function linhaLimpa(mixed $linha): array
    {
        if (! is_array($linha) || ! is_string($linha['id'] ?? null) || preg_match('/^[A-Za-z0-9_-]{1,24}$/', $linha['id']) !== 1) {
            throw new InvalidArgumentException('Linha sem id válido.');
        }

        $limpa = ['id' => $linha['id'], 'v' => []];

        if (isset($linha['k']) && is_string($linha['k'])) {
            $limpa['k'] = mb_substr($linha['k'], 0, 200);
        }

        foreach ((array) ($linha['v'] ?? []) as $c => $v) {
            $valor = self::valor($v);

            if ($valor !== null) {
                $limpa['v'][self::idDeColuna((string) $c)] = $valor;
            }
        }

        if ($s = self::estiloLimpo($linha['s'] ?? null)) {
            $limpa['s'] = $s;
        }

        foreach ((array) ($linha['e'] ?? []) as $c => $e) {
            if ($estilo = self::estiloLimpo($e)) {
                $limpa['e'][self::idDeColuna((string) $c)] = $estilo;
            }
        }

        // Os preços de todos os fornecedores, que a planilha de compra guarda
        // para o "passe o mouse e veja todos". Volta junto no desfazer.
        if (isset($linha['of']) && is_array($linha['of'])) {
            $limpa['of'] = array_values(array_filter(array_map(
                fn($o) => is_array($o) && isset($o['f'], $o['p']) && is_numeric($o['p'])
                    ? ['f' => mb_substr((string) $o['f'], 0, 120), 'p' => (float) $o['p']]
                    : null,
                array_slice($linha['of'], 0, 50),
            )));
        }

        return $limpa;
    }
}
