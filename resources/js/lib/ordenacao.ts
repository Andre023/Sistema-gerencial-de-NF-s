import { useCallback, useMemo, useRef, useState } from 'react';

/**
 * Ordenar uma tabela clicando no nome da coluna.
 *
 * Um clique ordena crescente (A→Z, menor→maior, mais antigo→mais novo), o
 * segundo inverte, o terceiro volta à ordem que veio do servidor — que quase
 * sempre já quer dizer algo (a fila vem por prioridade e idade), então "sem
 * ordenação" é um estado de verdade, e não só o ponto de partida.
 *
 * É tudo na tela, sobre o que já veio: nenhuma tabela daqui é paginada, então
 * ordenar o que está à vista é ordenar tudo. Onde a lista vem CORTADA do
 * servidor (a busca de fornecedores), quem usa precisa saber disso — a tela
 * avisa.
 *
 * Vazio vai sempre para o fim, nos dois sentidos: quem ordena por observação
 * quer ver as que TÊM observação, não uma página de traços.
 */

export type Valor = string | number | null | undefined;
export type Direcao = 'asc' | 'desc';
export interface Ordem { chave: string; dir: Direcao }

/** Como tirar, de cada linha, o valor que a coluna ordena. */
export type Valores<T> = Record<string, (item: T) => Valor>;

const vazio = (v: Valor) => v === null || v === undefined || v === '';

/** `numeric`: "NF 1203" vem antes de "NF 987"; `base`: sem caixa e acento. */
const colador = new Intl.Collator('pt-BR', { numeric: true, sensitivity: 'base' });

export function compararValores(a: Valor, b: Valor): number {
    if (typeof a === 'number' && typeof b === 'number') return a - b;
    return colador.compare(String(a), String(b));
}

/**
 * Data que já vem formatada do servidor ("15/10/2026" ou "15/10/2026 09:30")
 * virada em texto que ordena: "2026-10-15 09:30". Como texto puro, 02/11
 * viria antes de 15/10.
 */
export function dataBR(texto: string | null | undefined): string | null {
    const m = texto?.match(/^(\d{2})\/(\d{2})\/(\d{4})(.*)$/);
    return m ? `${m[3]}-${m[2]}-${m[1]}${m[4]}` : (texto ?? null);
}

export function ordenar<T>(itens: T[], ordem: Ordem | null, valores: Valores<T>): T[] {
    const valor = ordem ? valores[ordem.chave] : undefined;
    if (!ordem || !valor) return itens;

    const sinal = ordem.dir === 'asc' ? 1 : -1;

    // Estável: empate mantém a ordem do servidor (Array.sort é estável).
    return itens
        .map(item => ({ item, v: valor(item) }))
        .sort((a, b) => {
            if (vazio(a.v) || vazio(b.v)) return Number(vazio(a.v)) - Number(vazio(b.v));
            return sinal * compararValores(a.v, b.v);
        })
        .map(x => x.item);
}

export function useOrdenacao<T>(itens: T[], valores: Valores<T>) {
    const [ordem, setOrdem] = useState<Ordem | null>(null);

    // As funções de valor costumam ser escritas inline e mudam a cada render;
    // o que importa para reordenar é a lista e a ordem escolhida.
    const valoresRef = useRef(valores);
    valoresRef.current = valores;

    const alternar = useCallback((chave: string) => {
        setOrdem(o => {
            if (!o || o.chave !== chave) return { chave, dir: 'asc' };
            if (o.dir === 'asc') return { chave, dir: 'desc' };
            return null;
        });
    }, []);

    const ordenados = useMemo(() => ordenar(itens, ordem, valoresRef.current), [itens, ordem]);

    return { ordenados, ordem, alternar };
}

/** O que um cabeçalho de coluna precisa para ser clicável. */
export interface Ordenacao {
    ordem: Ordem | null;
    alternar: (chave: string) => void;
}
