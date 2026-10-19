// ─── Cotação CEASA: o formato das folhas e as operações sobre elas ────────────
//
// Uma folha é uma planilha da cotação (lista de compra, prévia, resposta de
// fornecedor, planilha de compra). A tela nunca manda a folha inteira ao
// servidor: manda OPERAÇÕES ("a célula X da linha Y vale 10") e aplica a mesma
// operação na hora, sem esperar a resposta.
//
// aplicarOps() é o espelho de App\Support\OperacoesDaFolha — os dois precisam
// dar o mesmo resultado, senão a tela de quem editou e a dos outros divergem.

export type TipoFolha = 'lista' | 'previa' | 'resposta' | 'compra';
export type TipoColuna = 'texto' | 'numero' | 'moeda';
export type Valor = string | number;

export interface Estilo {
    bg?: string;
    cor?: string;
    b?: boolean;
    i?: boolean;
}

export interface Coluna {
    id: string;
    nome: string;
    tipo: TipoColuna;
    largura: number;
    /** Escrita pelo sistema ao gerar a folha — gerar de novo sobrescreve. */
    gerada?: boolean;
    /** Escondida da grade (e oculta no Excel). Vale para todos e para as próximas folhas. */
    oculta?: boolean;
    estilo?: Estilo;
}

export interface Oferta { f: string; p: number }

export interface Linha {
    id: string;
    /** Identidade do produto (GTIN ou descrição) — só nas folhas geradas. */
    k?: string;
    v: Record<string, Valor>;
    /** Cor da linha inteira. */
    s?: Estilo;
    /** Cor de células desta linha, por coluna. */
    e?: Record<string, Estilo>;
    /** Planilha de compra: o preço de cada fornecedor, do menor ao maior. */
    of?: Oferta[];
}

export interface Folha {
    id: number;
    tipo: TipoFolha;
    nome: string;
    arquivo: string | null;
    meta: { loja?: string; setor?: string; fornecedor?: string };
    colunas: Coluna[];
    linhas: Linha[];
    versao: number;
}

export type Op =
    | { t: 'cels'; itens: [string, string, Valor | null][] }
    | { t: 'est'; alvo: 'cel' | 'lin' | 'col'; itens: { l?: string; c?: string; e: Estilo | null }[] }
    | { t: 'ins'; apos: string | null; linhas: Linha[] }
    | { t: 'del'; ids: string[] }
    | { t: 'ord'; ids: string[] }
    | { t: 'col'; coluna: Partial<Coluna> & { id: string }; apos?: string }
    | { t: 'delcol'; id: string }
    | { t: 'meta'; nome?: string; meta?: Record<string, string> };

// ─── Normalização ──────────────────────────────────────────────────────────────

/**
 * O PHP manda objeto vazio como `[]` ("v": []). Um array no lugar do objeto
 * aceita `v['desc'] = …` sem reclamar — e depois some no JSON. Por isso toda
 * folha que chega passa por aqui antes de qualquer coisa.
 */
const objeto = <T>(x: unknown): Record<string, T> =>
    x && typeof x === 'object' && !Array.isArray(x) ? (x as Record<string, T>) : {};

export function normalizarFolha(f: Folha): Folha {
    return {
        ...f,
        meta: objeto<string>(f.meta),
        colunas: Array.isArray(f.colunas) ? f.colunas : [],
        linhas: (Array.isArray(f.linhas) ? f.linhas : []).map(l => ({
            ...l,
            v: objeto<Valor>(l.v),
            ...(l.e ? { e: objeto<Estilo>(l.e) } : {}),
        })),
    };
}

// ─── Aplicar ───────────────────────────────────────────────────────────────────

const estiloLimpo = (e: Estilo | null | undefined): Estilo | null => {
    if (!e) return null;
    const limpo: Estilo = {};
    if (e.bg && /^#[0-9a-f]{6}$/i.test(e.bg)) limpo.bg = e.bg.toLowerCase();
    if (e.cor && /^#[0-9a-f]{6}$/i.test(e.cor)) limpo.cor = e.cor.toLowerCase();
    if (e.b) limpo.b = true;
    if (e.i) limpo.i = true;
    return Object.keys(limpo).length ? limpo : null;
};

export function aplicarOps(folha: Folha, ops: Op[]): Folha {
    let { nome, meta, colunas, linhas } = folha;

    for (const op of ops) {
        switch (op.t) {
            case 'cels': {
                const pos = posicoes(linhas);
                linhas = linhas.slice();
                for (const [l, c, v] of op.itens) {
                    const i = pos.get(l);
                    if (i === undefined) continue;
                    const nova = { ...linhas[i], v: { ...linhas[i].v } };
                    if (v === null || v === '') delete nova.v[c]; else nova.v[c] = v;
                    linhas[i] = nova;
                }
                break;
            }
            case 'est': {
                if (op.alvo === 'col') {
                    colunas = colunas.map(c => {
                        const item = op.itens.find(it => it.c === c.id);
                        if (!item) return c;
                        const e = estiloLimpo(item.e);
                        const { estilo: _, ...resto } = c;
                        return e ? { ...resto, estilo: e } : resto;
                    });
                    break;
                }
                const pos = posicoes(linhas);
                linhas = linhas.slice();
                for (const item of op.itens) {
                    const i = item.l === undefined ? undefined : pos.get(item.l);
                    if (i === undefined) continue;
                    const e = estiloLimpo(item.e);
                    const nova = { ...linhas[i] };
                    if (op.alvo === 'lin') {
                        if (e) nova.s = e; else delete nova.s;
                    } else if (item.c) {
                        const es = { ...(nova.e ?? {}) };
                        if (e) es[item.c] = e; else delete es[item.c];
                        if (Object.keys(es).length) nova.e = es; else delete nova.e;
                    }
                    linhas[i] = nova;
                }
                break;
            }
            case 'ins': {
                const existentes = new Set(linhas.map(l => l.id));
                const novas = op.linhas.filter(l => !existentes.has(l.id)).map(l => ({ ...l, v: { ...objeto<Valor>(l.v) } }));
                let indice = 0;
                if (op.apos !== null) {
                    const i = linhas.findIndex(l => l.id === op.apos);
                    indice = i >= 0 ? i + 1 : linhas.length;
                }
                linhas = [...linhas.slice(0, indice), ...novas, ...linhas.slice(indice)];
                break;
            }
            case 'del': {
                const ids = new Set(op.ids);
                linhas = linhas.filter(l => !ids.has(l.id));
                break;
            }
            case 'ord': {
                const porId = new Map(linhas.map(l => [l.id, l]));
                const ordenadas: Linha[] = [];
                for (const id of op.ids) {
                    const l = porId.get(id);
                    if (l) { ordenadas.push(l); porId.delete(id); }
                }
                linhas = [...ordenadas, ...porId.values()];
                break;
            }
            case 'col': {
                const { id, nome: n, tipo, largura, oculta } = op.coluna;
                const campos: Partial<Coluna> = {};
                if (n !== undefined) campos.nome = n.trim().slice(0, 60) || 'Coluna';
                if (tipo !== undefined) campos.tipo = ['texto', 'numero', 'moeda'].includes(tipo) ? tipo : 'texto';
                if (largura !== undefined) campos.largura = Math.max(40, Math.min(800, Math.trunc(largura)));
                const j = colunas.findIndex(c => c.id === id);
                if (oculta !== undefined) campos.oculta = !!oculta;
                if (j >= 0) {
                    colunas = colunas.map((c, k) => {
                        if (k !== j) return c;
                        const nova = { ...c, ...campos };
                        if (!nova.oculta) delete nova.oculta;
                        return nova;
                    });
                    break;
                }
                if (!campos.oculta) delete campos.oculta;
                const nova: Coluna = { id, nome: 'Coluna', tipo: 'texto', largura: 110, ...campos };
                let indice = colunas.length;
                if (op.apos !== undefined) {
                    indice = op.apos === '' ? 0 : colunas.findIndex(c => c.id === op.apos) + 1 || colunas.length;
                }
                colunas = [...colunas.slice(0, indice), nova, ...colunas.slice(indice)];
                break;
            }
            case 'delcol':
                colunas = colunas.filter(c => c.id !== op.id);
                break;
            case 'meta':
                if (op.nome !== undefined) nome = op.nome.trim().slice(0, 120) || nome;
                if (op.meta) {
                    meta = { ...meta };
                    for (const [k, v] of Object.entries(op.meta)) {
                        if (k === 'loja' || k === 'setor' || k === 'fornecedor') meta[k] = String(v).trim().slice(0, 120);
                    }
                }
                break;
        }
    }

    return { ...folha, nome, meta, colunas, linhas };
}

function posicoes(linhas: Linha[]): Map<string, number> {
    return new Map(linhas.map((l, i) => [l.id, i]));
}

// ─── Desfazer ──────────────────────────────────────────────────────────────────

/**
 * As operações que desfazem `ops`, calculadas sobre a folha de ANTES delas.
 *
 * O desfazer é só do que esta pessoa fez — e vira uma alteração como outra
 * qualquer, que chega aos outros compradores do mesmo jeito.
 */
export function inversa(antes: Folha, ops: Op[]): Op[] {
    let folha = antes;
    const volta: Op[] = [];

    for (const op of ops) {
        volta.unshift(...inversaDeUma(folha, op));
        folha = aplicarOps(folha, [op]);
    }

    return volta;
}

function inversaDeUma(f: Folha, op: Op): Op[] {
    const porId = new Map(f.linhas.map(l => [l.id, l]));

    switch (op.t) {
        case 'cels':
            return [{ t: 'cels', itens: op.itens.map(([l, c]) => [l, c, porId.get(l)?.v[c] ?? null]) }];
        case 'est':
            if (op.alvo === 'col') {
                return [{ t: 'est', alvo: 'col', itens: op.itens.map(it => ({ c: it.c, e: f.colunas.find(c => c.id === it.c)?.estilo ?? null })) }];
            }
            return [{
                t: 'est', alvo: op.alvo,
                itens: op.itens.map(it => ({ l: it.l, c: it.c, e: (op.alvo === 'lin' ? porId.get(it.l!)?.s : porId.get(it.l!)?.e?.[it.c!]) ?? null })),
            }];
        case 'ins':
            return [{ t: 'del', ids: op.linhas.map(l => l.id) }];
        case 'del': {
            // Devolve cada trecho apagado depois da linha que vinha antes dele.
            const ids = new Set(op.ids);
            const volta: Op[] = [];
            let anterior: string | null = null;
            let trecho: Linha[] = [];
            let aposDoTrecho: string | null = null;
            for (const l of f.linhas) {
                if (ids.has(l.id)) {
                    if (!trecho.length) aposDoTrecho = anterior;
                    trecho.push(l);
                } else {
                    if (trecho.length) volta.push({ t: 'ins', apos: aposDoTrecho, linhas: trecho });
                    trecho = [];
                    anterior = l.id;
                }
            }
            if (trecho.length) volta.push({ t: 'ins', apos: aposDoTrecho, linhas: trecho });
            return volta;
        }
        case 'ord':
            return [{ t: 'ord', ids: f.linhas.map(l => l.id) }];
        case 'col': {
            const atual = f.colunas.find(c => c.id === op.coluna.id);
            return atual ? [{ t: 'col', coluna: { id: atual.id, nome: atual.nome, tipo: atual.tipo, largura: atual.largura, oculta: !!atual.oculta } }] : [{ t: 'delcol', id: op.coluna.id }];
        }
        case 'delcol': {
            const j = f.colunas.findIndex(c => c.id === op.id);
            if (j < 0) return [];
            const c = f.colunas[j];
            const volta: Op[] = [{ t: 'col', coluna: { id: c.id, nome: c.nome, tipo: c.tipo, largura: c.largura, oculta: !!c.oculta }, apos: j === 0 ? '' : f.colunas[j - 1].id }];
            if (c.estilo) volta.push({ t: 'est', alvo: 'col', itens: [{ c: c.id, e: c.estilo }] });
            return volta;
        }
        case 'meta':
            return [{ t: 'meta', nome: f.nome, meta: { ...f.meta } as Record<string, string> }];
    }
}

// ─── Valores ───────────────────────────────────────────────────────────────────

export const novoId = (): string => {
    const letras = 'abcdefghijklmnopqrstuvwxyz0123456789';
    let id = '';
    const aleatorio = crypto.getRandomValues(new Uint8Array(10));
    for (const b of aleatorio) id += letras[b % letras.length];
    return id;
};

/** "5,50", "R$ 1.234,56", "10.5" → número; o resto continua texto. */
export function lerNumero(texto: string): number | null {
    let s = texto.trim().replace(/r\$/i, '').replace(/\s/g, '');
    if (!s) return null;
    if (s.includes(',')) s = s.replace(/\./g, '').replace(',', '.');
    return /^-?\d+(\.\d+)?$/.test(s) ? Number(s) : null;
}

/** O que a pessoa digitou, já no tipo da coluna. */
export function valorDigitado(texto: string, tipo: TipoColuna): Valor | null {
    if (texto.trim() === '') return null;
    if (tipo === 'texto') return texto;
    return lerNumero(texto) ?? texto;
}

const moeda = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const numero = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 3 });

export function mostrar(v: Valor | undefined, tipo: TipoColuna): string {
    if (v === undefined || v === null || v === '') return '';
    if (typeof v === 'number') return tipo === 'moeda' ? moeda.format(v) : tipo === 'numero' ? numero.format(v) : String(v);
    return v;
}

/** O texto que vai para a edição (e para o Ctrl+C): número com vírgula, sem milhar. */
export function paraEditar(v: Valor | undefined): string {
    if (v === undefined || v === null) return '';
    return typeof v === 'number' ? String(v).replace('.', ',') : v;
}

// ─── Cores do pincel ───────────────────────────────────────────────────────────
//
// Fundos claros e fixos, os mesmos nos dois temas: são as cores que vão para o
// Excel, e a pessoa precisa ver na tela o que vai imprimir. Célula pintada usa
// texto escuro mesmo no tema escuro, como no Excel.

export const FUNDOS = [
    { nome: 'Amarelo', cor: '#fff59d' },
    { nome: 'Verde', cor: '#c8e6c9' },
    { nome: 'Azul', cor: '#bbdefb' },
    { nome: 'Vermelho', cor: '#ffcdd2' },
    { nome: 'Laranja', cor: '#ffe0b2' },
    { nome: 'Roxo', cor: '#e1bee7' },
    { nome: 'Cinza', cor: '#e0e0e0' },
    { nome: 'Verde forte', cor: '#81c784' },
];

export const TEXTOS = [
    { nome: 'Vermelho', cor: '#c62828' },
    { nome: 'Verde', cor: '#2e7d32' },
    { nome: 'Azul', cor: '#1565c0' },
    { nome: 'Laranja', cor: '#e65100' },
    { nome: 'Roxo', cor: '#6a1b9a' },
];

export const TEXTO_SOBRE_FUNDO = '#1f2328';

/** A cor de cada colega na planilha (borda da célula onde ele está). */
const CORES_DE_COLEGA = ['#e3954a', '#a371f7', '#39c5bb', '#f778ba', '#d29922', '#2f81f7', '#3fb950'];
export const corDoColega = (id: number) => CORES_DE_COLEGA[id % CORES_DE_COLEGA.length];
