<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Lê o ranking de compras (.xlsx) que vem do ERP e devolve fornecedor + valor.
 *
 * Sem biblioteca, pelo mesmo motivo do Word: um .xlsx é um ZIP com XMLs dentro,
 * e o que esta tela precisa é de duas colunas. O PhpSpreadsheet resolveria — e
 * carregaria a planilha inteira na memória de uma VM de 1 GB para ler dois
 * campos de cada linha.
 *
 * O que ele tolera de propósito, porque planilha de ERP muda de um mês para o
 * outro (a de 2026 tem nove colunas, a "Tabela 1" tem três):
 *
 *   • o cabeçalho não precisa estar na primeira linha;
 *   • as colunas podem estar em qualquer posição — acha pelo nome;
 *   • a aba não precisa ser a primeira: vale a primeira que tiver as colunas;
 *   • linha de total ("TOTAL GERAL") e rodapé de observação são descartadas;
 *   • número escrito como texto ("R$ 1.234,56") é entendido.
 *
 * O que ele NÃO faz: fórmula sem valor calculado. O Excel guarda o resultado
 * junto, então isso só aparece em arquivo gerado por script — e aí a linha cai
 * fora em vez de virar zero silencioso.
 */
final class PlanilhaDeCompras
{
    /** Teto de linhas lidas — a base real tem ~1.100. */
    public const LIMITE_DE_LINHAS = 20000;

    /** O nome da coluna de valor, da mais específica para a mais genérica. */
    private const COLUNAS_DE_VALOR = [
        '/valor\s*de\s*compra\s*total/iu',
        '/compra(s)?\s*total/iu',
        '/faturamento/iu',
        '/valor\s*de\s*compra/iu',
        '/valor\s*total/iu',
        '/^valor/iu',
    ];

    /**
     * @return list<array{nome: string, faturamento: float}>
     *
     * @throws RuntimeException quando o arquivo não é um .xlsx legível ou
     *                          nenhuma aba tem as duas colunas.
     */
    public static function ler(string $caminho): array
    {
        $zip = new ZipArchive();

        if ($zip->open($caminho) !== true) {
            throw new RuntimeException('Não consegui abrir a planilha. Envie o arquivo .xlsx salvo pelo Excel.');
        }

        try {
            $textos = self::textosCompartilhados($zip);

            foreach (self::abas($zip) as $aba) {
                $dados = self::interpretar(self::linhasDa($zip, $aba, $textos));

                if ($dados !== []) {
                    return $dados;
                }
            }
        } finally {
            $zip->close();
        }

        throw new RuntimeException(
            'Não achei as colunas de fornecedor e valor na planilha. '
            . 'Ela precisa de uma linha de cabeçalho com "Fornecedor" e uma coluna de valor '
            . '(por exemplo "Valor de Compra Total").'
        );
    }

    // ─── Estrutura do arquivo ──────────────────────────────────────────────────

    /**
     * Os caminhos das abas, na ordem em que aparecem no Excel.
     *
     * A ordem vem do workbook.xml, não do nome do arquivo: sheet1.xml nem sempre
     * é a primeira aba, e quem exporta do ERP às vezes reordena.
     *
     * @return list<string>
     */
    private static function abas(ZipArchive $zip): array
    {
        $workbook = self::xml($zip, 'xl/workbook.xml');
        $rels     = self::xml($zip, 'xl/_rels/workbook.xml.rels');

        if ($workbook === null || $rels === null) {
            return self::abasPeloNomeDoArquivo($zip);
        }

        $destinos = [];
        foreach ($rels->getElementsByTagName('Relationship') as $rel) {
            $destinos[$rel->getAttribute('Id')] = ltrim($rel->getAttribute('Target'), '/');
        }

        $abas = [];
        foreach ($workbook->getElementsByTagName('sheet') as $sheet) {
            $id = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
            $destino = $destinos[$id] ?? null;

            if ($destino === null) {
                continue;
            }

            // O Target vem relativo à pasta xl/ ("worksheets/sheet1.xml").
            $abas[] = str_starts_with($destino, 'xl/') ? $destino : 'xl/' . $destino;
        }

        return $abas !== [] ? $abas : self::abasPeloNomeDoArquivo($zip);
    }

    /** Plano B: varre o ZIP atrás de xl/worksheets/*.xml. */
    private static function abasPeloNomeDoArquivo(ZipArchive $zip): array
    {
        $abas = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = $zip->getNameIndex($i);

            if (is_string($nome) && preg_match('#^xl/worksheets/sheet\d+\.xml$#', $nome)) {
                $abas[] = $nome;
            }
        }

        sort($abas);

        return $abas;
    }

    /**
     * A tabela de textos do arquivo. O .xlsx guarda cada texto UMA vez aqui e,
     * nas células, só o número da posição.
     *
     * @return list<string>
     */
    private static function textosCompartilhados(ZipArchive $zip): array
    {
        $conteudo = $zip->getFromName('xl/sharedStrings.xml');

        if ($conteudo === false) {
            return [];
        }

        $leitor = new XMLReader();
        $leitor->XML($conteudo);

        $dom = new DOMDocument();
        $textos = [];

        while ($leitor->read()) {
            if ($leitor->nodeType === XMLReader::ELEMENT && $leitor->localName === 'si') {
                $no = $leitor->expand($dom);
                $textos[] = $no instanceof DOMElement ? self::textoDe($no) : '';
            }
        }

        $leitor->close();

        return $textos;
    }

    /**
     * As linhas de uma aba: lista de arrays indexados pela coluna (0 = A).
     *
     * Célula vazia simplesmente não existe no XML — por isso o índice vem do
     * atributo r ("B12"), e não da contagem de células lidas.
     *
     * @param  list<string>  $textos
     * @return list<array<int, string>>
     */
    private static function linhasDa(ZipArchive $zip, string $aba, array $textos): array
    {
        $conteudo = $zip->getFromName($aba);

        if ($conteudo === false) {
            return [];
        }

        $leitor = new XMLReader();
        $leitor->XML($conteudo);

        $dom = new DOMDocument();
        $linhas = [];

        while ($leitor->read()) {
            if ($leitor->nodeType !== XMLReader::ELEMENT || $leitor->localName !== 'row') {
                continue;
            }

            $no = $leitor->expand($dom);

            if (! $no instanceof DOMElement) {
                continue;
            }

            $linha = [];

            foreach ($no->getElementsByTagName('c') as $celula) {
                $valor = self::valorDaCelula($celula, $textos);

                if ($valor !== '') {
                    $linha[self::coluna($celula->getAttribute('r'))] = $valor;
                }
            }

            if ($linha !== []) {
                $linhas[] = $linha;
            }

            // Uma planilha com 200 mil linhas não pode derrubar a VM: para no
            // teto e trabalha com o que leu.
            if (count($linhas) >= self::LIMITE_DE_LINHAS) {
                break;
            }
        }

        $leitor->close();

        return $linhas;
    }

    private static function valorDaCelula(DOMElement $celula, array $textos): string
    {
        $tipo = $celula->getAttribute('t');

        if ($tipo === 's') {
            $indice = (int) self::textoDoPrimeiro($celula, 'v');

            return $textos[$indice] ?? '';
        }

        if ($tipo === 'inlineStr') {
            return self::textoDe($celula);
        }

        return self::textoDoPrimeiro($celula, 'v');
    }

    // ─── Leitura das colunas ───────────────────────────────────────────────────

    /**
     * @param  list<array<int, string>>  $linhas
     * @return list<array{nome: string, faturamento: float}>
     */
    private static function interpretar(array $linhas): array
    {
        [$colunaDoNome, $colunaDoValor, $primeiraLinha] = self::acharColunas($linhas);

        if ($colunaDoNome === null || $colunaDoValor === null) {
            return [];
        }

        $dados = [];
        $vistos = [];

        foreach (array_slice($linhas, $primeiraLinha) as $linha) {
            $nome = trim($linha[$colunaDoNome] ?? '');

            // A linha de fechamento da planilha — e SÓ ela. O padrão precisa
            // casar o nome inteiro: "TOTAL QUIMICA LIMITADA" é fornecedor de
            // verdade, e um `^total` solto o deixava de fora da base.
            if ($nome === '' || preg_match('/^(totais?|total\s+geral|soma\s*(geral)?)$/iu', $nome)) {
                continue;
            }

            $valor = self::numero($linha[$colunaDoValor] ?? null);

            // Sem valor não é fornecedor: é o rodapé de observação da planilha.
            if ($valor === null) {
                continue;
            }

            // Nome repetido: vale a primeira aparição (a planilha vem ordenada
            // do maior para o menor, então é a linha do ranking).
            $chave = mb_strtoupper($nome);

            if (isset($vistos[$chave])) {
                continue;
            }

            $vistos[$chave] = true;
            $dados[] = ['nome' => $nome, 'faturamento' => round($valor, 2)];
        }

        return $dados;
    }

    /**
     * Acha a linha de cabeçalho e as duas colunas que interessam.
     *
     * @param  list<array<int, string>>  $linhas
     * @return array{0: int|null, 1: int|null, 2: int}
     */
    private static function acharColunas(array $linhas): array
    {
        // O cabeçalho costuma ser a primeira linha, mas exportação com título
        // ou logo empurra ele para baixo — daí olhar as dez primeiras.
        foreach (array_slice($linhas, 0, 10, true) as $indice => $linha) {
            $colunaDoNome = null;

            foreach ($linha as $coluna => $texto) {
                if (preg_match('/fornecedor|raz[aã]o\s*social/iu', $texto)) {
                    $colunaDoNome = $coluna;
                    break;
                }
            }

            if ($colunaDoNome === null) {
                continue;
            }

            foreach (self::COLUNAS_DE_VALOR as $padrao) {
                foreach ($linha as $coluna => $texto) {
                    if ($coluna !== $colunaDoNome && preg_match($padrao, trim($texto))) {
                        return [$colunaDoNome, $coluna, $indice + 1];
                    }
                }
            }
        }

        return [null, null, 0];
    }

    // ─── Utilidades ────────────────────────────────────────────────────────────

    /** "1.234,56", "R$ 1.234,56" e "1234.56" viram 1234.56. */
    private static function numero(?string $bruto): ?float
    {
        $texto = preg_replace('/[^0-9,.\-]/', '', trim((string) $bruto)) ?? '';

        if ($texto === '' || $texto === '-') {
            return null;
        }

        // Vírgula presente = decimal brasileiro; o ponto ali é separador de
        // milhar. O .xlsx guarda o número cru ("1234.56"), sem vírgula.
        if (str_contains($texto, ',')) {
            $texto = str_replace(',', '.', str_replace('.', '', $texto));
        }

        return is_numeric($texto) ? (float) $texto : null;
    }

    /** "B12" → 1 (a coluna B). */
    private static function coluna(string $referencia): int
    {
        $letras = preg_replace('/[^A-Z]/', '', strtoupper($referencia)) ?? '';
        $numero = 0;

        foreach (str_split($letras) as $letra) {
            $numero = $numero * 26 + (ord($letra) - 64);
        }

        return max(0, $numero - 1);
    }

    private static function textoDe(DOMElement $no): string
    {
        $texto = '';

        foreach ($no->getElementsByTagName('t') as $pedaco) {
            $texto .= $pedaco->textContent;
        }

        return $texto;
    }

    private static function textoDoPrimeiro(DOMElement $no, string $tag): string
    {
        $achado = $no->getElementsByTagName($tag)->item(0);

        return $achado?->textContent ?? '';
    }

    private static function xml(ZipArchive $zip, string $nome): ?DOMDocument
    {
        $conteudo = $zip->getFromName($nome);

        if ($conteudo === false) {
            return null;
        }

        $dom = new DOMDocument();
        $anterior = libxml_use_internal_errors(true);
        $ok = $dom->loadXML($conteudo);
        libxml_use_internal_errors($anterior);

        return $ok ? $dom : null;
    }
}
