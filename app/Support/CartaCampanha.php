<?php

namespace App\Support;

/**
 * A carta da campanha de aniversário: o esqueleto que o comprador escreve, os
 * marcadores que ele deixa no meio do texto e o resultado com os valores no
 * lugar.
 *
 * O esqueleto é texto livre — quem escreve é o comprador. O programa só precisa
 * saber ONDE entra cada dado, e isso é dito com marcador entre parênteses:
 *
 *     (nome do fornecedor)   (faturamento)   (investimento)
 *
 * Escolha deliberada: marcador em português e entre parênteses, não `{{var}}`.
 * Quem edita a carta é comprador, não programador — e um parêntese esquecido
 * vira texto normal na carta, não um erro de sintaxe.
 *
 * O "R$" antes do marcador é absorvido de propósito: o valor já sai formatado
 * como `R$ 20.000,00`, então tanto "de (investimento)" quanto "de R$
 * (investimento)" produzem a mesma linha — sem `R$ R$` na carta do cliente.
 *
 * O mesmo desenho está espelhado no front (resources/js/lib/campanha.ts), que
 * monta a prévia enquanto se digita. Mexeu num, mexa no outro: o Word gerado
 * aqui é a versão que vale.
 */
final class CartaCampanha
{
    /**
     * Quebra o esqueleto em pedaços: texto comum e marcador. O grupo de captura
     * envolve tudo porque o preg_split precisa devolver os marcadores junto
     * (PREG_SPLIT_DELIM_CAPTURE) — são eles que viram valor em negrito.
     */
    private const PADRAO = '/(\((?:nome\s+do\s+)?fornecedor\)|(?:R\$\s*)?\((?:faturamento|investimento)\))/iu';

    /** Teto do campo de texto — vale para o esqueleto salvo e para o gerado. */
    public const LIMITE_DE_CARACTERES = 20000;

    /**
     * O investimento que a tela sugere sozinha ao reconhecer o fornecedor na
     * planilha de compras: 2% do faturamento dos últimos 12 meses.
     *
     * É só o ponto de partida — o comprador ajusta na tela antes de gerar a
     * carta, e os atalhos de porcentagem continuam ao lado do campo.
     */
    public const PERCENTUAL_SUGERIDO = 2.0;

    /**
     * O texto de fábrica. É só o ponto de partida: o admin troca o padrão da
     * loja em Configurações, e cada comprador salva o dele por cima.
     */
    public const TEXTO_DE_FABRICA = <<<'TEXTO'
    Prezado parceiro,

    Estamos vivendo mais um grande marco em nossa trajetória: celebramos 21 anos do Hiper Comercial Monlevade e vamos comemorar de forma grandiosa com nossos clientes e parceiros.

    Do dia 01/10/2026 a 11/01/2027, realizaremos a promoção Aniversário Hiper – 21 Anos com você! Uma excelente oportunidade para fortalecer nossa parceria e ampliar os resultados.

    Nos últimos 12 meses, a (nome do fornecedor) conquistou um faturamento de (faturamento) em nossa loja. Com base nesse desempenho, sugerimos um investimento de (investimento) para potencializar ainda mais essa performance.

    Esse valor será direcionado para ações estratégicas no ponto de venda durante toda a promoção: pontos extras, encartes, televisores e painéis de LED, além de divulgação nas redes sociais. Tudo para destacar sua marca e maximizar as vendas, aproveitando o forte impacto da campanha em João Monlevade e região.

    Anexamos o Plano Comercial para sua análise e estamos confiantes de que essa será mais uma parceria de sucesso.

    Agradecemos desde já e contamos com você para celebrar conosco este momento tão especial.
    TEXTO;

    /**
     * O esqueleto com os valores no lugar, em parágrafos de pedaços.
     *
     * Devolve a estrutura que o Word precisa — cada parágrafo é uma lista de
     * trechos {texto, negrito} — e não uma string pronta, porque o nome do
     * fornecedor e os dois valores saem em negrito no documento: são eles que
     * o parceiro procura quando abre o arquivo.
     *
     * @return list<list<array{texto: string, negrito: bool}>>
     */
    public static function montar(
        string $esqueleto,
        string $fornecedor,
        float $faturamento,
        float $investimento,
    ): array {
        $paragrafos = [];

        foreach (preg_split('/\R/u', $esqueleto) as $linha) {
            $linha = trim($linha);

            // Linha em branco separa parágrafo no editor, mas não vira parágrafo
            // vazio no Word: lá o respiro vem do espaçamento depois de cada um.
            if ($linha === '') {
                continue;
            }

            $paragrafos[] = self::trechosDa($linha, $fornecedor, $faturamento, $investimento);
        }

        return $paragrafos;
    }

    /** A carta em texto puro — o que o botão "Copiar" põe na área de transferência. */
    public static function emTextoPuro(
        string $esqueleto,
        string $fornecedor,
        float $faturamento,
        float $investimento,
    ): string {
        $linhas = array_map(
            fn(array $trechos) => implode('', array_column($trechos, 'texto')),
            self::montar($esqueleto, $fornecedor, $faturamento, $investimento),
        );

        return implode("\n\n", $linhas);
    }

    /** 2536257.21 → "R$ 2.536.257,21" */
    public static function dinheiro(float $valor): string
    {
        return 'R$ ' . number_format($valor, 2, ',', '.');
    }

    /**
     * @return list<array{texto: string, negrito: bool}>
     */
    private static function trechosDa(
        string $linha,
        string $fornecedor,
        float $faturamento,
        float $investimento,
    ): array {
        $pedacos = preg_split(self::PADRAO, $linha, -1, PREG_SPLIT_DELIM_CAPTURE);

        $trechos = [];

        foreach ($pedacos as $i => $pedaco) {
            if ($pedaco === '') {
                continue;
            }

            // Os índices ímpares são os marcadores capturados; os pares, o texto
            // que estava entre eles.
            if ($i % 2 === 0) {
                $trechos[] = ['texto' => $pedaco, 'negrito' => false];
                continue;
            }

            $trechos[] = [
                'texto'   => self::valorDoMarcador($pedaco, $fornecedor, $faturamento, $investimento),
                'negrito' => true,
            ];
        }

        return $trechos;
    }

    private static function valorDoMarcador(
        string $marcador,
        string $fornecedor,
        float $faturamento,
        float $investimento,
    ): string {
        if (stripos($marcador, 'fornecedor') !== false) {
            return $fornecedor;
        }

        return self::dinheiro(stripos($marcador, 'faturamento') !== false ? $faturamento : $investimento);
    }
}
