<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use RuntimeException;
use XMLReader;
use ZipArchive;

/**
 * Lê um .xlsx (ou .csv) e devolve cada aba como uma grade de textos.
 *
 * É o mesmo leitor sem biblioteca do PlanilhaDeCompras, só que genérico: não
 * procura coluna nenhuma, entrega as células. Quem sabe o que cada planilha
 * quer dizer é quem chama (PlanilhaDaCotacao).
 *
 * Os valores vêm como o Excel guardou: número cru ("10.536447"), data como o
 * número de dias desde 1900 ("46302"). Converter é de quem conhece a coluna.
 */
final class LeitorDePlanilha
{
    /** Teto de linhas por aba — uma lista de estoque baixo tem centenas. */
    public const LIMITE_DE_LINHAS = 5000;

    /** Colunas além disto são ignoradas: nenhuma planilha da cotação passa de 20. */
    private const LIMITE_DE_COLUNAS = 60;

    /**
     * @return list<array{nome: string, linhas: list<array<int, string>>}>
     *
     * @throws RuntimeException quando o arquivo não é legível.
     */
    public static function ler(string $caminho, string $nomeOriginal = ''): array
    {
        if (preg_match('/\.(csv|txt)$/i', $nomeOriginal)) {
            return [['nome' => 'CSV', 'linhas' => self::csv($caminho)]];
        }

        $zip = new ZipArchive();

        if ($zip->open($caminho) !== true) {
            throw new RuntimeException('Não consegui abrir a planilha. Envie o arquivo .xlsx salvo pelo Excel (o .xls antigo não é aceito — abra no Excel e salve como .xlsx).');
        }

        try {
            $textos = self::textosCompartilhados($zip);
            $abas = [];

            foreach (self::abas($zip) as [$nome, $caminhoDaAba]) {
                $abas[] = ['nome' => $nome, 'linhas' => self::linhasDa($zip, $caminhoDaAba, $textos)];
            }

            return $abas;
        } finally {
            $zip->close();
        }
    }

    /** Número de série do Excel (dias desde 30/12/1899) para "dd/mm/aaaa". */
    public static function dataDoExcel(string $valor): string
    {
        if (! is_numeric($valor) || (float) $valor < 20000 || (float) $valor > 80000) {
            return trim($valor);
        }

        return gmdate('d/m/Y', (int) round(((float) $valor - 25569) * 86400));
    }

    // ─── CSV ───────────────────────────────────────────────────────────────────

    /** @return list<array<int, string>> */
    private static function csv(string $caminho): array
    {
        $conteudo = file_get_contents($caminho);

        if ($conteudo === false) {
            throw new RuntimeException('Não consegui ler o arquivo.');
        }

        // O Excel brasileiro salva CSV em Windows-1252 e com ponto e vírgula.
        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }

        $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo) ?? $conteudo;
        $primeira = strtok($conteudo, "\n") ?: '';
        $separador = substr_count($primeira, ';') >= substr_count($primeira, ',') ? ';' : ',';

        $linhas = [];
        foreach (preg_split('/\r\n|\n|\r/', $conteudo) ?: [] as $texto) {
            if (trim($texto) === '') {
                continue;
            }

            $linhas[] = array_slice(str_getcsv($texto, $separador, '"', ''), 0, self::LIMITE_DE_COLUNAS);

            if (count($linhas) >= self::LIMITE_DE_LINHAS) {
                break;
            }
        }

        return $linhas;
    }

    // ─── Estrutura do .xlsx ────────────────────────────────────────────────────

    /**
     * Nome e caminho de cada aba, na ordem do Excel.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function abas(ZipArchive $zip): array
    {
        $workbook = self::xml($zip, 'xl/workbook.xml');
        $rels     = self::xml($zip, 'xl/_rels/workbook.xml.rels');
        $abas = [];

        if ($workbook !== null && $rels !== null) {
            $destinos = [];
            foreach ($rels->getElementsByTagName('Relationship') as $rel) {
                $destinos[$rel->getAttribute('Id')] = ltrim($rel->getAttribute('Target'), '/');
            }

            foreach ($workbook->getElementsByTagName('sheet') as $sheet) {
                $id = $sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id');
                $destino = $destinos[$id] ?? null;

                if ($destino !== null) {
                    $abas[] = [$sheet->getAttribute('name'), str_starts_with($destino, 'xl/') ? $destino : 'xl/' . $destino];
                }
            }
        }

        if ($abas !== []) {
            return $abas;
        }

        // Plano B: varre o ZIP atrás de xl/worksheets/*.xml.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nome = $zip->getNameIndex($i);

            if (is_string($nome) && preg_match('#^xl/worksheets/(sheet\d+)\.xml$#', $nome, $m)) {
                $abas[] = [$m[1], $nome];
            }
        }

        usort($abas, fn($a, $b) => strnatcmp($a[1], $b[1]));

        return $abas;
    }

    /** @return list<string> */
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
     * As linhas de uma aba, cada uma indexada pela coluna (0 = A). Linha que
     * existe no XML sem nenhum valor entra como array vazio, e quem interpreta
     * pula — o que importa é a ordem (título antes do cabeçalho), não o número
     * da linha no Excel.
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
                $coluna = self::coluna($celula->getAttribute('r'));

                if ($coluna >= self::LIMITE_DE_COLUNAS) {
                    continue;
                }

                $valor = self::valorDaCelula($celula, $textos);

                if ($valor !== '') {
                    $linha[$coluna] = $valor;
                }
            }

            $linhas[] = $linha;

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
            return $textos[(int) self::textoDoPrimeiro($celula, 'v')] ?? '';
        }

        if ($tipo === 'inlineStr') {
            return self::textoDe($celula);
        }

        return self::textoDoPrimeiro($celula, 'v');
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
        return $no->getElementsByTagName($tag)->item(0)?->textContent ?? '';
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
