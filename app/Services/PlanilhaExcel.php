<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Escreve um .xlsx simples — cabeçalho em negrito, texto e número.
 *
 * Sem biblioteca, pelo mesmo motivo do DocumentoWord e do PlanilhaDeCompras: um
 * .xlsx é um ZIP com XMLs dentro, e o que precisamos aqui é uma aba só, sem
 * fórmula, gráfico nem aba dupla. Trazer um pacote de 20 MB para montar seis
 * colunas custaria mais em manutenção do que estas linhas.
 *
 * O NÚMERO VAI COMO NÚMERO, e isso é o ponto de não exportar CSV: quem abre a
 * planilha quer somar a coluna e filtrar por valor. Em CSV tudo vira texto e o
 * Excel brasileiro ainda erra a vírgula decimal — a pessoa recebe um arquivo que
 * parece certo e não soma.
 */
final class PlanilhaExcel
{
    /** Recuo da primeira coluna de dados: as linhas do Excel começam em 1. */
    private const PRIMEIRA_LINHA = 1;

    /**
     * Monta a planilha e devolve os bytes.
     *
     * @param  array<int,string>              $cabecalho
     * @param  array<int,array<int,mixed>>    $linhas     string vira texto; int/float vira número
     */
    public static function montar(array $cabecalho, array $linhas, string $aba = 'Dados'): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($caminho === false) {
            throw new RuntimeException('Não consegui criar o arquivo temporário da planilha.');
        }

        $zip = new ZipArchive();

        if ($zip->open($caminho, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Não consegui montar a planilha.');
        }

        $zip->addFromString('[Content_Types].xml', self::tiposDeConteudo());
        $zip->addFromString('_rels/.rels', self::relacoesDoPacote());
        $zip->addFromString('xl/workbook.xml', self::pasta($aba));
        $zip->addFromString('xl/_rels/workbook.xml.rels', self::relacoesDaPasta());
        $zip->addFromString('xl/styles.xml', self::estilos());
        $zip->addFromString('xl/worksheets/sheet1.xml', self::aba($cabecalho, $linhas));

        $zip->close();

        $bytes = file_get_contents($caminho);
        unlink($caminho);

        if ($bytes === false) {
            throw new RuntimeException('Não consegui ler a planilha recém-montada.');
        }

        return $bytes;
    }

    /**
     * A aba: o cabeçalho e as linhas.
     *
     * O tipo de cada célula é decidido pelo valor do PHP — string vira `t="inlineStr"`
     * (texto embutido, que dispensa a tabela de textos compartilhados) e número vai
     * cru, que é o que o Excel soma.
     */
    private static function aba(array $cabecalho, array $linhas): string
    {
        $xml = self::cabecalhoXml()
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData>';

        $numeroDaLinha = self::PRIMEIRA_LINHA;

        // Cabeçalho: estilo 1 (negrito)
        $xml .= '<row r="' . $numeroDaLinha . '">';
        foreach (array_values($cabecalho) as $i => $titulo) {
            $xml .= self::celula(self::coluna($i) . $numeroDaLinha, (string) $titulo, 1);
        }
        $xml .= '</row>';

        foreach ($linhas as $linha) {
            $numeroDaLinha++;
            $xml .= '<row r="' . $numeroDaLinha . '">';

            foreach (array_values($linha) as $i => $valor) {
                $xml .= self::celula(self::coluna($i) . $numeroDaLinha, $valor, 0);
            }

            $xml .= '</row>';
        }

        return $xml . '</sheetData></worksheet>';
    }

    /** Uma célula. Número vai cru; o resto vai como texto embutido. */
    private static function celula(string $ref, mixed $valor, int $estilo): string
    {
        $s = $estilo > 0 ? ' s="' . $estilo . '"' : '';

        if (is_int($valor) || is_float($valor)) {
            // Estilo 2 = duas casas decimais, para dinheiro somar bonito.
            $comEstilo = $estilo > 0 ? $s : ' s="2"';

            return '<c r="' . $ref . '"' . $comEstilo . '><v>'
                . rtrim(rtrim(number_format((float) $valor, 2, '.', ''), '0'), '.')
                . '</v></c>';
        }

        if ($valor === null || $valor === '') {
            return '<c r="' . $ref . '"' . $s . '/>';
        }

        return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
            . self::escapar((string) $valor) . '</t></is></c>';
    }

    /** 0 -> A, 25 -> Z, 26 -> AA. */
    private static function coluna(int $indice): string
    {
        $nome = '';

        for ($n = $indice; $n >= 0; $n = intdiv($n, 26) - 1) {
            $nome = chr(65 + $n % 26) . $nome;
        }

        return $nome;
    }

    private static function pasta(string $aba): string
    {
        return self::cabecalhoXml()
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::escapar(mb_substr($aba, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    /**
     * Três formatos, e só: o padrão, o negrito do cabeçalho e o de duas casas.
     *
     * A ordem importa — o `s` da célula é o índice dentro de `cellXfs`.
     */
    private static function estilos(): string
    {
        return self::cabecalhoXml()
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private static function tiposDeConteudo(): string
    {
        return self::cabecalhoXml()
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }

    private static function relacoesDoPacote(): string
    {
        return self::cabecalhoXml()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function relacoesDaPasta(): string
    {
        return self::cabecalhoXml()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function cabecalhoXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    }

    private static function escapar(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
