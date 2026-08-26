<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Monta um .docx do zero — sem biblioteca.
 *
 * Um .docx é um ZIP com alguns XMLs dentro; para uma carta de sete parágrafos
 * são cinco arquivos pequenos e nenhuma dependência nova. Numa VM de 1 GB, onde
 * até o `composer install` é operação delicada, isso vale mais que a comodidade
 * de um pacote que sabe fazer mil coisas que esta tela não precisa.
 *
 * O que o documento traz de propósito, para a carta sair como a do ano passado:
 * A4, margens de 2,5 cm em cima/embaixo e 3 cm nas laterais, Calibri 11,
 * parágrafos justificados e respiro entre eles vindo do espaçamento — não de
 * linha em branco.
 */
class DocumentoWord
{
    /** Cabe a carta inteira em memória sem susto: o arquivo dá uns 3 KB. */
    public static function carta(array $paragrafos, string $titulo = ''): string
    {
        $caminho = tempnam(sys_get_temp_dir(), 'docx');

        if ($caminho === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário do Word.');
        }

        $zip = new ZipArchive();

        if ($zip->open($caminho, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
            @unlink($caminho);
            throw new RuntimeException('Não foi possível montar o arquivo do Word.');
        }

        $zip->addFromString('[Content_Types].xml', self::tiposDeConteudo());
        $zip->addFromString('_rels/.rels', self::relacoesDoPacote());
        $zip->addFromString('docProps/core.xml', self::propriedades($titulo));
        $zip->addFromString('word/_rels/document.xml.rels', self::relacoesDoDocumento());
        $zip->addFromString('word/styles.xml', self::estilos());
        $zip->addFromString('word/document.xml', self::documento($paragrafos));
        $zip->close();

        $conteudo = file_get_contents($caminho);
        @unlink($caminho);

        if ($conteudo === false) {
            throw new RuntimeException('Não foi possível ler o arquivo do Word recém-criado.');
        }

        return $conteudo;
    }

    /**
     * @param list<list<array{texto: string, negrito: bool}>> $paragrafos
     */
    private static function documento(array $paragrafos): string
    {
        $corpo = '';

        foreach ($paragrafos as $trechos) {
            $runs = '';

            foreach ($trechos as $trecho) {
                $negrito = ! empty($trecho['negrito']) ? '<w:rPr><w:b/></w:rPr>' : '';

                // xml:space="preserve" segura os espaços das pontas — sem ele o
                // Word come o espaço antes do valor e a frase gruda: "deR$ 20".
                $runs .= '<w:r>' . $negrito
                       . '<w:t xml:space="preserve">' . self::escapar((string) $trecho['texto']) . '</w:t>'
                       . '</w:r>';
            }

            // A ordem dentro do pPr segue o esquema do OOXML: espaçamento antes
            // do alinhamento. Fora de ordem, o Word reclama do arquivo.
            $corpo .= '<w:p><w:pPr>'
                    . '<w:spacing w:after="200" w:line="276" w:lineRule="auto"/>'
                    . '<w:jc w:val="both"/>'
                    . '</w:pPr>' . $runs . '</w:p>';
        }

        return self::cabecalhoXml()
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:body>' . $corpo
            . '<w:sectPr>'
            . '<w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1417" w:right="1701" w:bottom="1417" w:left="1701" w:header="708" w:footer="708" w:gutter="0"/>'
            . '</w:sectPr>'
            . '</w:body></w:document>';
    }

    private static function estilos(): string
    {
        // sz 22 = 22 meios-pontos = Calibri 11, o mesmo do documento de 2025.
        return self::cabecalhoXml()
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults>'
            . '<w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
            . '<w:sz w:val="22"/><w:szCs w:val="22"/>'
            . '<w:lang w:val="pt-BR"/>'
            . '</w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="200" w:line="276" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
            . '</w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
            . '<w:name w:val="Normal"/><w:qFormat/>'
            . '</w:style>'
            . '</w:styles>';
    }

    private static function tiposDeConteudo(): string
    {
        return self::cabecalhoXml()
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>';
    }

    private static function relacoesDoPacote(): string
    {
        return self::cabecalhoXml()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>';
    }

    private static function relacoesDoDocumento(): string
    {
        return self::cabecalhoXml()
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    private static function propriedades(string $titulo): string
    {
        $agora = gmdate('Y-m-d\TH:i:s\Z');

        return self::cabecalhoXml()
            . '<cp:coreProperties '
            . 'xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" '
            . 'xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::escapar($titulo) . '</dc:title>'
            . '<dc:creator>Hiper Comercial Monlevade</dc:creator>'
            . '<cp:lastModifiedBy>Hiper Comercial Monlevade</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $agora . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $agora . '</dcterms:modified>'
            . '</cp:coreProperties>';
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
