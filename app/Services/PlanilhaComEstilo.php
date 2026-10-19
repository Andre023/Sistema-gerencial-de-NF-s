<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Escreve um .xlsx com várias abas, cores, negrito e células mescladas.
 *
 * É o irmão maior do PlanilhaExcel, e sem biblioteca pelo mesmo motivo: o que
 * a Cotação precisa é pouco — as cores que o comprador pintou na tela saírem
 * iguais no Excel — e um pacote de planilha inteiro para isso pesaria mais na
 * VM que todo o resto da tela.
 *
 * Uma célula é um valor solto (string vira texto, int/float vira número) ou
 *
 *   ['v' => valor, 'bg' => '#rrggbb', 'cor' => '#rrggbb', 'b' => true, 'i' => true,
 *    'fmt' => 'moeda' | 'inteiro']
 *
 * Cada combinação diferente de fonte, fundo e formato vira um estilo no
 * styles.xml — guardado uma vez só e reaproveitado pelas células iguais.
 */
final class PlanilhaComEstilo
{
    /** @var list<array{nome: string, xml: string}> */
    private array $abas = [];

    /** @var array<string, int> fonte (negrito|itálico|cor) → índice */
    private array $fontes = ['||' => 0];

    /** @var array<string, int> cor de fundo → índice (0 e 1 são obrigatórios do Excel) */
    private array $fundos = ['none' => 0, 'gray125' => 1];

    /** @var array<string, int> fonte|fundo|formato → índice em cellXfs */
    private array $estilos = ['0|0|0' => 0];

    private const FORMATOS = ['moeda' => 164, 'inteiro' => 1];

    /**
     * @param  list<float|int>         $larguras  em caracteres, uma por coluna
     * @param  list<list<mixed>>       $linhas
     * @param  list<string>            $mesclas   "A1:C1"
     * @param  list<int>               $ocultas   índices (0 = A) das colunas que saem ocultas
     */
    public function aba(string $nome, array $larguras, array $linhas, array $mesclas = [], int $congelarLinhas = 0, bool $filtro = false, array $ocultas = []): self
    {
        $xml = self::cabecalhoXml()
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($congelarLinhas > 0) {
            $xml .= '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $congelarLinhas
                . '" topLeftCell="A' . ($congelarLinhas + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        }

        if ($larguras !== []) {
            $xml .= '<cols>';
            foreach (array_values($larguras) as $i => $largura) {
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . round((float) $largura, 1) . '" customWidth="1"'
                    . (in_array($i, $ocultas, true) ? ' hidden="1"' : '') . '/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        $maiorColuna = 0;

        foreach (array_values($linhas) as $i => $linha) {
            $numero = $i + 1;
            $xml .= '<row r="' . $numero . '">';

            foreach (array_values($linha) as $j => $celula) {
                $xml .= $this->celula(self::coluna($j) . $numero, $celula);
                $maiorColuna = max($maiorColuna, $j);
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        if ($filtro && $linhas !== []) {
            $xml .= '<autoFilter ref="A' . max(1, $congelarLinhas) . ':' . self::coluna($maiorColuna) . count($linhas) . '"/>';
        }

        if ($mesclas !== []) {
            $xml .= '<mergeCells count="' . count($mesclas) . '">';
            foreach ($mesclas as $ref) {
                $xml .= '<mergeCell ref="' . $ref . '"/>';
            }
            $xml .= '</mergeCells>';
        }

        $this->abas[] = ['nome' => $this->nomeDeAba($nome), 'xml' => $xml . '</worksheet>'];

        return $this;
    }

    public function bytes(): string
    {
        if ($this->abas === []) {
            $this->aba('Planilha1', [], []);
        }

        $caminho = tempnam(sys_get_temp_dir(), 'xlsx');

        if ($caminho === false) {
            throw new RuntimeException('Não consegui criar o arquivo temporário da planilha.');
        }

        $zip = new ZipArchive();

        if ($zip->open($caminho, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            throw new RuntimeException('Não consegui montar a planilha.');
        }

        $zip->addFromString('[Content_Types].xml', $this->tiposDeConteudo());
        $zip->addFromString('_rels/.rels', self::relacoesDoPacote());
        $zip->addFromString('xl/workbook.xml', $this->pasta());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->relacoesDaPasta());
        $zip->addFromString('xl/styles.xml', $this->folhaDeEstilos());

        foreach ($this->abas as $i => $aba) {
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $aba['xml']);
        }

        $zip->close();

        $bytes = file_get_contents($caminho);
        unlink($caminho);

        if ($bytes === false) {
            throw new RuntimeException('Não consegui ler a planilha recém-montada.');
        }

        return $bytes;
    }

    // ─── Células e estilos ─────────────────────────────────────────────────────

    private function celula(string $ref, mixed $celula): string
    {
        $dados = is_array($celula) ? $celula : ['v' => $celula];
        $valor = $dados['v'] ?? null;
        $formato = $dados['fmt'] ?? null;

        $estilo = $this->estilo($dados, $formato);
        $s = $estilo > 0 ? ' s="' . $estilo . '"' : '';

        if (is_int($valor) || is_float($valor)) {
            return '<c r="' . $ref . '"' . $s . '><v>' . self::numero($valor) . '</v></c>';
        }

        if ($valor === null || $valor === '') {
            return $s === '' ? '' : '<c r="' . $ref . '"' . $s . '/>';
        }

        return '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">'
            . self::escapar((string) $valor) . '</t></is></c>';
    }

    private function estilo(array $dados, ?string $formato): int
    {
        $cor = self::cor($dados['cor'] ?? null);
        $chaveDaFonte = (! empty($dados['b']) ? 'b' : '') . '|' . (! empty($dados['i']) ? 'i' : '') . '|' . ($cor ?? '');
        $fonte = $this->fontes[$chaveDaFonte] ??= count($this->fontes);

        $bg = self::cor($dados['bg'] ?? null);
        $fundo = $bg === null ? 0 : ($this->fundos[$bg] ??= count($this->fundos));

        $numFmt = self::FORMATOS[$formato] ?? 0;

        return $this->estilos["{$fonte}|{$fundo}|{$numFmt}"] ??= count($this->estilos);
    }

    private function folhaDeEstilos(): string
    {
        $fontes = '';
        foreach (array_keys($this->fontes) as $chave) {
            [$b, $i, $cor] = explode('|', $chave);
            $fontes .= '<font>' . ($b ? '<b/>' : '') . ($i ? '<i/>' : '')
                . '<sz val="11"/>' . ($cor ? '<color rgb="' . $cor . '"/>' : '') . '<name val="Calibri"/></font>';
        }

        $fundos = '';
        foreach (array_keys($this->fundos) as $chave) {
            $fundos .= match ($chave) {
                'none', 'gray125' => '<fill><patternFill patternType="' . $chave . '"/></fill>',
                default => '<fill><patternFill patternType="solid"><fgColor rgb="' . $chave . '"/><bgColor indexed="64"/></patternFill></fill>',
            };
        }

        $xfs = '';
        foreach (array_keys($this->estilos) as $chave) {
            [$fonte, $fundo, $numFmt] = array_map('intval', explode('|', $chave));
            $xfs .= '<xf numFmtId="' . $numFmt . '" fontId="' . $fonte . '" fillId="' . $fundo . '" borderId="0" xfId="0"'
                . ($fonte ? ' applyFont="1"' : '') . ($fundo ? ' applyFill="1"' : '') . ($numFmt ? ' applyNumberFormat="1"' : '') . '/>';
        }

        return self::cabecalhoXml()
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts>'
            . '<fonts count="' . count($this->fontes) . '">' . $fontes . '</fonts>'
            . '<fills count="' . count($this->fundos) . '">' . $fundos . '</fills>'
            . '<borders count="1"><border/></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="' . count($this->estilos) . '">' . $xfs . '</cellXfs>'
            . '</styleSheet>';
    }

    /** "#ffeb3b" → "FFFFEB3B", o ARGB que o Excel quer. */
    private static function cor(mixed $hex): ?string
    {
        return is_string($hex) && preg_match('/^#([0-9a-f]{6})$/i', $hex, $m) ? 'FF' . strtoupper($m[1]) : null;
    }

    private static function numero(int|float $valor): string
    {
        if (is_int($valor)) {
            return (string) $valor;
        }

        return rtrim(rtrim(number_format($valor, 6, '.', ''), '0'), '.');
    }

    // ─── Estrutura do pacote ───────────────────────────────────────────────────

    /** O Excel recusa nome de aba repetido, com : \ / ? * [ ] ou com mais de 31 letras. */
    private function nomeDeAba(string $nome): string
    {
        $base = mb_substr(trim((string) preg_replace('/[:\\\\\/?*\[\]]+/', ' ', $nome)) ?: 'Planilha', 0, 31);
        $usados = array_map('mb_strtolower', array_column($this->abas, 'nome'));
        $candidato = $base;

        for ($n = 2; in_array(mb_strtolower($candidato), $usados, true); $n++) {
            $candidato = mb_substr($base, 0, 31 - strlen(" ($n)")) . " ($n)";
        }

        return $candidato;
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

    private function pasta(): string
    {
        $abas = '';
        foreach ($this->abas as $i => $aba) {
            $abas .= '<sheet name="' . self::escapar($aba['nome']) . '" sheetId="' . ($i + 1) . '" r:id="rId' . ($i + 1) . '"/>';
        }

        return self::cabecalhoXml()
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $abas . '</sheets></workbook>';
    }

    private function relacoesDaPasta(): string
    {
        $rels = '';
        foreach ($this->abas as $i => $aba) {
            $rels .= '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }

        $rels .= '<Relationship Id="rId' . (count($this->abas) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        return self::cabecalhoXml()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>';
    }

    private function tiposDeConteudo(): string
    {
        $abas = '';
        foreach ($this->abas as $i => $aba) {
            $abas .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return self::cabecalhoXml()
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $abas
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

    private static function cabecalhoXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\n";
    }

    private static function escapar(string $texto): string
    {
        return htmlspecialchars($texto, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
