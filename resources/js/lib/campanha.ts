// A carta da campanha de aniversário — a mesma substituição que o servidor faz.
//
// Espelho de app/Support/CartaCampanha.php: aqui ela existe para a prévia
// acompanhar a digitação sem ida ao servidor. Quem manda no arquivo entregue é
// o PHP; mexeu num, mexa no outro.

/** Os marcadores que o comprador deixa no texto para dizer onde entra cada dado. */
export const MARCADORES = {
    fornecedor: '(nome do fornecedor)',
    faturamento: '(faturamento)',
    investimento: '(investimento)',
} as const;

/**
 * O "R$" antes do marcador é absorvido de propósito: o valor já sai formatado
 * como `R$ 20.000,00`, então "de (investimento)" e "de R$ (investimento)"
 * dão a mesma linha — sem `R$ R$` na carta do cliente.
 */
const PADRAO = /(\((?:nome\s+do\s+)?fornecedor\)|(?:R\$\s*)?\((?:faturamento|investimento)\))/i;

export interface Trecho {
    texto: string;
    /** É um valor substituído (nome ou dinheiro)? Sai em negrito no Word. */
    valor: boolean;
    /** O campo ainda está vazio: a prévia mostra o marcador apagado. */
    vazio: boolean;
}

export interface Dados {
    fornecedor: string;
    /** Em reais; null enquanto o campo está vazio. */
    faturamento: number | null;
    investimento: number | null;
}

/** 2536257.21 → "R$ 2.536.257,21" */
export function dinheiro(valor: number): string {
    return 'R$ ' + valor.toLocaleString('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    });
}

/** A carta em parágrafos de trechos, pronta para a prévia. */
export function montarCarta(esqueleto: string, dados: Dados): Trecho[][] {
    return esqueleto
        .split(/\r?\n/)
        .map(linha => linha.trim())
        .filter(linha => linha !== '')
        .map(linha => trechosDa(linha, dados));
}

/** A carta em texto puro — o que o botão "Copiar" leva para o e-mail. */
export function cartaEmTexto(esqueleto: string, dados: Dados): string {
    return montarCarta(esqueleto, dados)
        .map(paragrafo => paragrafo.map(t => t.texto).join(''))
        .join('\n\n');
}

function trechosDa(linha: string, dados: Dados): Trecho[] {
    // O split com grupo de captura devolve texto e marcador alternados — os
    // ímpares são os marcadores.
    return linha
        .split(PADRAO)
        .map((pedaco, i) => (i % 2 === 0
            ? { texto: pedaco, valor: false, vazio: false }
            : valorDoMarcador(pedaco, dados)))
        .filter(t => t.texto !== '');
}

function valorDoMarcador(marcador: string, dados: Dados): Trecho {
    const m = marcador.toLowerCase();

    if (m.includes('fornecedor')) {
        return dados.fornecedor.trim() !== ''
            ? { texto: dados.fornecedor.trim(), valor: true, vazio: false }
            : { texto: MARCADORES.fornecedor, valor: true, vazio: true };
    }

    const faturamento = m.includes('faturamento');
    const valor = faturamento ? dados.faturamento : dados.investimento;

    if (valor === null) {
        return {
            texto: faturamento ? MARCADORES.faturamento : MARCADORES.investimento,
            valor: true,
            vazio: true,
        };
    }

    return { texto: dinheiro(valor), valor: true, vazio: false };
}
