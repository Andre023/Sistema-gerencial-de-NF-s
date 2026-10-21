import { memo, useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Palette } from '@/lib/tema';
import {
    Coluna, Estilo, FUNDOS, Folha, Linha, Op, TEXTOS, TEXTO_SOBRE_FUNDO, Valor,
    mostrar, novoId, paraEditar, valorDigitado,
} from '@/lib/cotacao';

/**
 * Um Excel pequeno: a grade de uma folha da cotação.
 *
 * Faz o que o comprador faz no Excel de todo dia — clicar e digitar, Enter
 * desce, Tab vai para o lado, selecionar arrastando, colar um pedaço copiado
 * do Excel, Delete limpa, Ctrl+Z desfaz, pintar célula, linha ou coluna,
 * inserir e apagar linha, criar coluna.
 *
 * O componente não guarda a folha: recebe a que está valendo e devolve
 * OPERAÇÕES (onOps). Quem sincroniza com o servidor e com os outros
 * compradores é o useCotacaoAoVivo.
 *
 * Sem virtualização de propósito: a maior folha da cotação tem algumas
 * centenas de linhas, e cada linha só redesenha quando muda (memo) — a grade
 * inteira na tela é o que deixa o Ctrl+F do navegador e a impressão funcionarem.
 */

export interface FocoNaFolha { l: string; c: string; nome: string; cor: string }

interface Props {
    folha: Folha;
    p: Palette;
    onOps: (ops: Op[]) => void;
    onDesfazer: () => void;
    podeDesfazer: boolean;
    /** Onde estão os colegas nesta folha. */
    focos?: FocoNaFolha[];
    onFoco?: (l: string, c: string) => void;
    /** Colunas que não podem ser apagadas (as do sistema). */
    colunasFixas?: string[];
    /** Cor só de tela (não vai para o Excel) — o 1º preço em verde, por exemplo. */
    realce?: (linha: Linha, coluna: Coluna) => Estilo | undefined;
    /** Texto ao parar o mouse na linha. */
    dica?: (linha: Linha) => string | undefined;
    altura?: string;
}

/**
 * A célula aberta para digitar. `porCima`: começou digitando sobre a célula —
 * aí as setas gravam e vão para a vizinha, como no Excel. Aberta por duplo
 * clique ou F2 (para corrigir um texto), as setas andam dentro do texto.
 */
interface Edicao { r: number; c: number; texto: string; porCima: boolean }

interface Selecao { r0: number; c0: number; r1: number; c1: number; modo: 'cel' | 'lin' | 'col' }
interface Cursor { r: number; c: number }

const ALTURA_DA_LINHA = 28;
const LARGURA_DO_NUMERO = 46;

const juntar = (...estilos: (Estilo | undefined)[]): Estilo => {
    const e: Estilo = {};
    for (const x of estilos) {
        if (!x) continue;
        if (x.bg && !e.bg) e.bg = x.bg;
        if (x.cor && !e.cor) e.cor = x.cor;
        if (x.b) e.b = true;
        if (x.i) e.i = true;
    }
    return e;
};

const semAcento = (t: string) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();

/**
 * O filtro de uma coluna, como o do Excel. Só desta tela — filtrar não
 * esconde nada dos colegas.
 *
 *   valores: os textos (como aparecem na grade) que ficam; '' = célula vazia.
 *   cor:     o fundo que fica; '' = sem cor.
 */
interface Filtro { valores?: string[]; cor?: string }

type Realce = (linha: Linha, coluna: Coluna) => Estilo | undefined;

const textoDaCelula = (l: Linha, c: Coluna) => mostrar(l.v[c.id], c.tipo).trim();

/** O fundo que a célula mostra: o pintado à mão ou o realce da tela. */
const fundoDaCelula = (l: Linha, c: Coluna, realce?: Realce) =>
    (juntar(l.e?.[c.id], l.s, c.estilo, realce?.(l, c)).bg ?? '').toLowerCase();

const passaNoFiltro = (l: Linha, c: Coluna, f: Filtro, realce?: Realce) =>
    (!f.valores || f.valores.includes(textoDaCelula(l, c)))
    && (f.cor === undefined || fundoDaCelula(l, c, realce) === f.cor);

const nomeDaCor = (cor: string) =>
    cor === '' ? 'Sem cor' : FUNDOS.find(f => f.cor.toLowerCase() === cor)?.nome ?? 'Outra cor';

/** O texto do Excel: TAB entre colunas, quebra entre linhas, aspas em volta de célula com quebra. */
function lerTsv(texto: string): string[][] {
    const linhas: string[][] = [];
    let linha: string[] = [];
    let celula = '';
    let aspas = false;

    for (let i = 0; i < texto.length; i++) {
        const ch = texto[i];
        if (aspas) {
            if (ch === '"' && texto[i + 1] === '"') { celula += '"'; i++; }
            else if (ch === '"') aspas = false;
            else celula += ch;
        } else if (ch === '"' && celula === '') aspas = true;
        else if (ch === '\t') { linha.push(celula); celula = ''; }
        else if (ch === '\n' || ch === '\r') {
            if (ch === '\r' && texto[i + 1] === '\n') i++;
            linha.push(celula); linhas.push(linha); linha = []; celula = '';
        } else celula += ch;
    }

    if (celula !== '' || linha.length) { linha.push(celula); linhas.push(linha); }

    return linhas;
}

/**
 * Uma função que nunca muda de identidade mas sempre chama a versão mais nova.
 * É o que deixa o memo das linhas valer: sem isso, cada tecla redesenharia as
 * quinhentas linhas porque o "onDuploClique" de cada render é outro.
 */
function useEstavel<A extends unknown[], R>(fn: (...a: A) => R): (...a: A) => R {
    const ref = useRef(fn);
    ref.current = fn;
    return useCallback((...a: A) => ref.current(...a), []);
}

const tsv = (v: string) => (/[\t\n"]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v);

export default function Planilha({
    folha, p, onOps, onDesfazer, podeDesfazer, focos = [], onFoco, colunasFixas = [], realce, dica, altura = '60vh',
}: Props) {
    const [busca, setBusca] = useState('');
    const [sel, setSel] = useState<Selecao>({ r0: 0, c0: 0, r1: 0, c1: 0, modo: 'cel' });
    const [cursor, setCursor] = useState<Cursor>({ r: 0, c: 0 });
    const [edicao, setEdicaoState] = useState<Edicao | null>(null);
    // A edição também num ref: o Enter fecha e o blur que vem logo atrás não
    // pode gravar de novo.
    const edicaoRef = useRef(edicao);
    const setEdicao = (e: typeof edicao | ((x: typeof edicao) => typeof edicao)) => {
        const nova = typeof e === 'function' ? e(edicaoRef.current) : e;
        edicaoRef.current = nova;
        setEdicaoState(nova);
    };
    const [renomeando, setRenomeando] = useState<string | null>(null);
    const [larguras, setLarguras] = useState<Record<string, number>>({});
    const [menu, setMenu] = useState<'fundo' | 'texto' | 'colunas' | null>(null);
    /** O menu de uma coluna (▾ no cabeçalho ou botão direito), na posição do clique. */
    const [menuDaColuna, setMenuDaColuna] = useState<{ id: string; x: number; y: number } | null>(null);

    const grade = useRef<HTMLDivElement>(null);
    const arrastando = useRef<false | 'cel' | 'lin' | 'col'>(false);
    const ancora = useRef<Cursor>({ r: 0, c: 0 });

    // A grade só conhece as colunas visíveis: seleção, setas, copiar e colar
    // pulam a oculta, como no Excel.
    const colunas = useMemo(() => folha.colunas.filter(c => !c.oculta), [folha.colunas]);
    const ocultas = useMemo(() => folha.colunas.filter(c => c.oculta), [folha.colunas]);

    // ── Filtro das colunas ──
    //
    // Como no Excel, o filtro é tirado na hora em que se aplica: a linha que
    // deixou de bater depois (alguém preencheu o Fornecedor que estava vazio)
    // continua na tela até filtrar de novo. Senão ela sumiria debaixo do
    // cursor e o Enter pularia a linha seguinte. Linha nova sempre aparece.
    const [filtros, setFiltros] = useState<Record<string, Filtro>>({});
    const [fotoDoFiltro, setFotoDoFiltro] = useState<Map<string, boolean> | null>(null);
    const [painelDeFiltro, setPainelDeFiltro] = useState<{ id: string; x: number; y: number } | null>(null);

    const filtrar = (novos: Record<string, Filtro>) => {
        setFiltros(novos);
        const ativos = Object.entries(novos)
            .map(([id, f]) => [folha.colunas.find(c => c.id === id), f] as const)
            .filter((x): x is readonly [Coluna, Filtro] => !!x[0]);
        setFotoDoFiltro(ativos.length
            ? new Map(folha.linhas.map(l => [l.id, ativos.every(([c, f]) => passaNoFiltro(l, c, f, realce))]))
            : null);
    };

    const filtrarColuna = (id: string, f: Filtro | undefined) => {
        const { [id]: _, ...resto } = filtros;
        filtrar(f ? { ...resto, [id]: f } : resto);
    };

    const nFiltros = Object.keys(filtros).length;

    // A busca e o filtro escondem linhas, mas a seleção continua contando pelo que aparece.
    const linhas = useMemo(() => {
        const q = semAcento(busca.trim());
        const filtradas = fotoDoFiltro ? folha.linhas.filter(l => fotoDoFiltro.get(l.id) ?? true) : folha.linhas;
        if (!q) return filtradas;
        return filtradas.filter(l => Object.values(l.v).some(v => semAcento(String(v)).includes(q)));
    }, [folha.linhas, busca, fotoDoFiltro]);

    const nLinhas = linhas.length;
    const nColunas = colunas.length;

    // A folha encolheu (alguém apagou linhas): o cursor não pode ficar fora.
    useEffect(() => {
        if (cursor.r >= nLinhas || cursor.c >= nColunas) {
            const r = Math.max(0, Math.min(cursor.r, nLinhas - 1));
            const c = Math.max(0, Math.min(cursor.c, nColunas - 1));
            setCursor({ r, c });
            setSel({ r0: r, c0: c, r1: r, c1: c, modo: 'cel' });
        }
    }, [nLinhas, nColunas]);

    const faixa = useMemo(() => ({
        rMin: Math.min(sel.r0, sel.r1), rMax: Math.max(sel.r0, sel.r1),
        cMin: Math.min(sel.c0, sel.c1), cMax: Math.max(sel.c0, sel.c1),
    }), [sel]);

    const largura = (c: Coluna) => larguras[c.id] ?? c.largura;
    const larguraTotal = LARGURA_DO_NUMERO + colunas.reduce((s, c) => s + largura(c), 0);

    // ── Mover ──

    const irPara = useCallback((r: number, c: number, estender = false) => {
        const rr = Math.max(0, Math.min(r, nLinhas - 1));
        const cc = Math.max(0, Math.min(c, nColunas - 1));
        setCursor({ r: rr, c: cc });
        if (estender) setSel(s => ({ ...s, r1: rr, c1: cc, modo: 'cel' }));
        else { ancora.current = { r: rr, c: cc }; setSel({ r0: rr, c0: cc, r1: rr, c1: cc, modo: 'cel' }); }
    }, [nLinhas, nColunas]);

    // Leva a célula do cursor para dentro da área visível.
    useLayoutEffect(() => {
        const td = grade.current?.querySelector<HTMLElement>(`[data-r="${cursor.r}"][data-c="${cursor.c}"]`);
        td?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }, [cursor]);

    // Conta aos colegas onde estou.
    const idDaLinha = linhas[cursor.r]?.id;
    const idDaColuna = colunas[cursor.c]?.id;
    useEffect(() => {
        if (idDaLinha && idDaColuna) onFoco?.(idDaLinha, idDaColuna);
    }, [idDaLinha, idDaColuna]);

    // ── Editar ──

    const gravar = useCallback((r: number, c: number, texto: string) => {
        const l = linhas[r];
        const col = colunas[c];
        if (!l || !col) return;
        const novo = valorDigitado(texto, col.tipo);
        const antigo = l.v[col.id] ?? null;
        if (novo === antigo || (novo === null && antigo === null)) return;
        onOps([{ t: 'cels', itens: [[l.id, col.id, novo]] }]);
    }, [linhas, colunas, onOps]);

    const abrirEdicao = (texto?: string) => {
        const l = linhas[cursor.r];
        const col = colunas[cursor.c];
        if (!l || !col) return;
        setEdicao({ r: cursor.r, c: cursor.c, texto: texto ?? paraEditar(l.v[col.id]), porCima: texto !== undefined });
    };

    const fecharEdicao = useEstavel((gravarAntes: boolean, depois?: [number, number]) => {
        const ed = edicaoRef.current;
        if (!ed) return;
        if (gravarAntes) gravar(ed.r, ed.c, ed.texto);
        setEdicao(null);
        if (depois) irPara(ed.r + depois[0], ed.c + depois[1]);
        requestAnimationFrame(() => grade.current?.focus({ preventScroll: true }));
    });

    const mudarTexto = useEstavel((texto: string) => setEdicao(ed => (ed ? { ...ed, texto } : ed)));

    // ── Seleção → alvos ──

    const linhasDaSelecao = () => linhas.slice(faixa.rMin, faixa.rMax + 1);
    const colunasDaSelecao = () => colunas.slice(faixa.cMin, faixa.cMax + 1);

    const limpar = () => {
        const itens: [string, string, Valor | null][] = [];
        for (const l of linhasDaSelecao()) for (const c of colunasDaSelecao()) if (l.v[c.id] !== undefined) itens.push([l.id, c.id, null]);
        if (itens.length) onOps([{ t: 'cels', itens }]);
    };

    /**
     * Pinta o que está selecionado. Linha inteira selecionada pinta a linha (e
     * a linha nova que alguém inserir no meio não herda); coluna inteira pinta
     * a coluna; o resto pinta célula por célula.
     */
    const pintar = (mudar: (e: Estilo) => Estilo) => {
        const limpo = (e: Estilo): Estilo | null => {
            const x: Estilo = {};
            if (e.bg) x.bg = e.bg; if (e.cor) x.cor = e.cor; if (e.b) x.b = true; if (e.i) x.i = true;
            return Object.keys(x).length ? x : null;
        };

        if (sel.modo === 'lin') {
            onOps([{ t: 'est', alvo: 'lin', itens: linhasDaSelecao().map(l => ({ l: l.id, e: limpo(mudar(l.s ?? {})) })) }]);
        } else if (sel.modo === 'col') {
            onOps([{ t: 'est', alvo: 'col', itens: colunasDaSelecao().map(c => ({ c: c.id, e: limpo(mudar(c.estilo ?? {})) })) }]);
        } else {
            const itens = [];
            for (const l of linhasDaSelecao()) for (const c of colunasDaSelecao()) itens.push({ l: l.id, c: c.id, e: limpo(mudar(l.e?.[c.id] ?? {})) });
            onOps([{ t: 'est', alvo: 'cel', itens }]);
        }
        setMenu(null);
    };

    /** Negrito liga em tudo, a não ser que tudo já esteja em negrito. */
    const alternar = (campo: 'b' | 'i') => {
        const atuais: Estilo[] = sel.modo === 'lin' ? linhasDaSelecao().map(l => l.s ?? {})
            : sel.modo === 'col' ? colunasDaSelecao().map(c => c.estilo ?? {})
            : linhasDaSelecao().flatMap(l => colunasDaSelecao().map(c => l.e?.[c.id] ?? {}));
        const ligar = !atuais.every(e => e[campo]);
        pintar(e => ({ ...e, [campo]: ligar }));
    };

    const inserirLinhas = (acima: boolean) => {
        const n = sel.modo === 'lin' ? faixa.rMax - faixa.rMin + 1 : 1;
        const ref = linhas[acima ? faixa.rMin : faixa.rMax];
        let apos: string | null = null;
        if (ref) {
            const i = folha.linhas.findIndex(l => l.id === ref.id);
            apos = acima ? (i > 0 ? folha.linhas[i - 1].id : null) : ref.id;
        } else if (folha.linhas.length) {
            apos = folha.linhas[folha.linhas.length - 1].id;
        }
        onOps([{ t: 'ins', apos, linhas: Array.from({ length: n }, () => ({ id: novoId(), v: {} })) }]);
        if (!acima) irPara(faixa.rMax + 1, cursor.c);
    };

    const apagarLinhas = () => {
        const ids = linhasDaSelecao().map(l => l.id);
        if (ids.length) onOps([{ t: 'del', ids }]);
    };

    const novaColuna = () => {
        const id = 'c' + novoId().slice(0, 8);
        const apos = colunas[faixa.cMax]?.id;
        onOps([{ t: 'col', coluna: { id, nome: 'Nova coluna', tipo: 'texto', largura: 120 }, ...(apos ? { apos } : {}) }]);
        setRenomeando(id);
    };

    const apagarColuna = () => {
        const alvo = colunasDaSelecao().filter(c => !colunasFixas.includes(c.id));
        if (!alvo.length) return;
        onOps(alvo.map(c => ({ t: 'delcol' as const, id: c.id })));
    };

    const ocultarColunas = () => {
        const alvo = colunasDaSelecao();
        // Sempre sobra uma: grade sem coluna nenhuma não tem onde clicar.
        if (!alvo.length || alvo.length >= colunas.length) return;
        onOps(alvo.map(c => ({ t: 'col' as const, coluna: { id: c.id, oculta: true } })));
    };

    const reexibir = (ids: string[]) => {
        if (ids.length) onOps(ids.map(id => ({ t: 'col' as const, coluna: { id, oculta: false } })));
    };

    /** Liga ou desliga uma coluna pela lista do menu "Colunas". */
    const alternarVisivel = (c: Coluna) => {
        if (c.oculta) return reexibir([c.id]);
        if (colunas.length <= 1) return; // a última visível fica
        onOps([{ t: 'col', coluna: { id: c.id, oculta: true } }]);
    };

    const ordenar = (desc: boolean, idDaColuna?: string) => {
        const col = idDaColuna ? colunas.find(c => c.id === idDaColuna) : colunas[cursor.c];
        if (!col) return;
        const vazio = (v: Valor | undefined) => v === undefined || v === '';
        const ordenadas = [...folha.linhas].sort((a, b) => {
            const va = a.v[col.id], vb = b.v[col.id];
            if (vazio(va) || vazio(vb)) return vazio(va) ? (vazio(vb) ? 0 : 1) : -1; // vazio sempre no fim
            const r = typeof va === 'number' && typeof vb === 'number' ? va - vb
                : String(va).localeCompare(String(vb), 'pt-BR', { numeric: true });
            return desc ? -r : r;
        });
        onOps([{ t: 'ord', ids: ordenadas.map(l => l.id) }]);
    };

    // ── Copiar e colar ──

    const copiar = (e: React.ClipboardEvent) => {
        if (edicao) return;
        e.preventDefault();
        const texto = linhasDaSelecao().map(l => colunasDaSelecao().map(c => tsv(paraEditar(l.v[c.id]))).join('\t')).join('\r\n');
        e.clipboardData.setData('text/plain', texto);
    };

    const recortar = (e: React.ClipboardEvent) => {
        if (edicao) return;
        copiar(e);
        limpar();
    };

    const colar = (e: React.ClipboardEvent) => {
        if (edicao) return;
        e.preventDefault();
        const blocos = lerTsv(e.clipboardData.getData('text/plain'));
        if (!blocos.length) return;

        // Um valor só com várias células selecionadas: preenche todas (como no Excel).
        const umSo = blocos.length === 1 && blocos[0].length === 1;
        const alturaDoBloco = umSo ? faixa.rMax - faixa.rMin + 1 : blocos.length;
        const larguraDoBloco = umSo ? faixa.cMax - faixa.cMin + 1 : Math.max(...blocos.map(b => b.length));

        const ops: Op[] = [];
        const ids = linhas.slice(faixa.rMin, faixa.rMin + alturaDoBloco).map(l => l.id);

        // Colou mais linhas do que existem até o fim: cria as que faltam.
        const faltam = alturaDoBloco - ids.length;
        if (faltam > 0) {
            const novas = Array.from({ length: faltam }, () => ({ id: novoId(), v: {} }));
            ops.push({ t: 'ins', apos: folha.linhas.length ? folha.linhas[folha.linhas.length - 1].id : null, linhas: novas });
            ids.push(...novas.map(l => l.id));
        }

        const itens: [string, string, Valor | null][] = [];
        for (let i = 0; i < alturaDoBloco; i++) {
            for (let j = 0; j < larguraDoBloco; j++) {
                const col = colunas[faixa.cMin + j];
                if (!col) continue;
                const texto = umSo ? blocos[0][0] : blocos[i]?.[j] ?? '';
                itens.push([ids[i], col.id, valorDigitado(texto, col.tipo)]);
            }
        }
        ops.push({ t: 'cels', itens });
        onOps(ops);

        setSel({ r0: faixa.rMin, c0: faixa.cMin, r1: faixa.rMin + alturaDoBloco - 1, c1: Math.min(nColunas - 1, faixa.cMin + larguraDoBloco - 1), modo: 'cel' });
    };

    // ── Teclado ──

    const tecla = (e: React.KeyboardEvent) => {
        if (edicao || renomeando) return;
        const ctrl = e.ctrlKey || e.metaKey;
        const { r, c } = cursor;
        const pular = (dr: number, dc: number) => {
            e.preventDefault();
            if (ctrl) irPara(dr ? (dr > 0 ? nLinhas - 1 : 0) : r, dc ? (dc > 0 ? nColunas - 1 : 0) : c, e.shiftKey);
            else irPara(r + dr, c + dc, e.shiftKey);
        };

        if (e.key === 'ArrowDown') return pular(1, 0);
        if (e.key === 'ArrowUp') return pular(-1, 0);
        if (e.key === 'ArrowRight') return pular(0, 1);
        if (e.key === 'ArrowLeft') return pular(0, -1);
        if (e.key === 'PageDown') return pular(15, 0);
        if (e.key === 'PageUp') return pular(-15, 0);
        if (e.key === 'Tab') { e.preventDefault(); return irPara(r, c + (e.shiftKey ? -1 : 1)); }
        if (e.key === 'Enter') { e.preventDefault(); return irPara(r + (e.shiftKey ? -1 : 1), c); }
        if (e.key === 'F2') { e.preventDefault(); return abrirEdicao(); }
        if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); return limpar(); }
        if (e.key === 'Escape') return irPara(r, c);

        if (ctrl && e.key.toLowerCase() === 'z') { e.preventDefault(); return onDesfazer(); }
        if (ctrl && e.key.toLowerCase() === 'b') { e.preventDefault(); return alternar('b'); }
        if (ctrl && e.key.toLowerCase() === 'i') { e.preventDefault(); return alternar('i'); }
        if (ctrl && e.key.toLowerCase() === 'a') {
            e.preventDefault();
            return setSel({ r0: 0, c0: 0, r1: nLinhas - 1, c1: nColunas - 1, modo: 'cel' });
        }

        // Letra ou número: começa a escrever por cima, como no Excel.
        if (!ctrl && !e.altKey && e.key.length === 1) {
            e.preventDefault();
            abrirEdicao(e.key);
        }
    };

    // ── Mouse ──

    const descerNaCelula = useCallback((r: number, c: number, e: React.MouseEvent) => {
        if (e.button !== 0) return;
        e.preventDefault();
        fecharEdicao(true);
        grade.current?.focus({ preventScroll: true });
        setMenu(null);
        if (e.shiftKey) {
            setCursor({ r, c });
            setSel(s => ({ ...s, r1: r, c1: c, modo: 'cel' }));
        } else {
            ancora.current = { r, c };
            setCursor({ r, c });
            setSel({ r0: r, c0: c, r1: r, c1: c, modo: 'cel' });
        }
        arrastando.current = 'cel';
    }, []);

    const passarNaCelula = useCallback((r: number, c: number) => {
        if (arrastando.current === 'cel') setSel(s => ({ ...s, r1: r, c1: c }));
        else if (arrastando.current === 'lin') setSel(s => ({ ...s, r1: r }));
    }, []);

    const descerNoNumero = useEstavel((r: number, e: React.MouseEvent) => {
        e.preventDefault();
        fecharEdicao(true);
        grade.current?.focus({ preventScroll: true });
        setCursor(cur => ({ r, c: cur.c }));
        setSel(s => (e.shiftKey && s.modo === 'lin' ? { ...s, r1: r } : { r0: r, r1: r, c0: 0, c1: nColunas - 1, modo: 'lin' }));
        arrastando.current = 'lin';
    });

    const descerNoCabecalho = (c: number, e: React.MouseEvent) => {
        e.preventDefault();
        fecharEdicao(true);
        grade.current?.focus({ preventScroll: true });
        setCursor(cur => ({ r: cur.r, c }));
        setSel(s => (e.shiftKey && s.modo === 'col' ? { ...s, c1: c } : { r0: 0, r1: nLinhas - 1, c0: c, c1: c, modo: 'col' }));
        arrastando.current = 'col';
    };

    useEffect(() => {
        const foraDaCelula = (e: MouseEvent) => {
            const alvo = e.target as HTMLElement | null;
            if (edicaoRef.current && !alvo?.closest('td input')) fecharEdicao(true);
        };
        document.addEventListener('mousedown', foraDaCelula, true);
        return () => document.removeEventListener('mousedown', foraDaCelula, true);
    }, []);

    useEffect(() => {
        const soltar = () => { arrastando.current = false; };
        window.addEventListener('mouseup', soltar);
        return () => window.removeEventListener('mouseup', soltar);
    }, []);

    const duploClique = useEstavel((r: number, c: number) => {
        const l = linhas[r];
        const col = colunas[c];
        if (l && col) setEdicao({ r, c, texto: paraEditar(l.v[col.id]), porCima: false });
    });

    // Arrastar a borda do cabeçalho muda a largura. Só grava ao soltar.
    const redimensionar = (col: Coluna, e: React.MouseEvent) => {
        e.preventDefault();
        e.stopPropagation();
        const inicio = e.clientX;
        const original = largura(col);
        let atual = original;
        const mover = (ev: MouseEvent) => {
            atual = Math.max(40, Math.min(800, original + ev.clientX - inicio));
            setLarguras(l => ({ ...l, [col.id]: atual }));
        };
        const soltar = () => {
            window.removeEventListener('mousemove', mover);
            window.removeEventListener('mouseup', soltar);
            if (atual !== original) onOps([{ t: 'col', coluna: { id: col.id, largura: atual } }]);
            setLarguras(l => { const { [col.id]: _, ...resto } = l; return resto; });
        };
        window.addEventListener('mousemove', mover);
        window.addEventListener('mouseup', soltar);
    };

    // ── Desenho ──

    const focosPorLinha = useMemo(() => {
        const m = new Map<string, FocoNaFolha[]>();
        for (const f of focos) m.set(f.l, [...(m.get(f.l) ?? []), f]);
        return m;
    }, [focos]);

    const botao = 'h-7 min-w-7 px-2 inline-flex items-center justify-center gap-1 rounded-md text-xs transition disabled:opacity-35';
    const estiloBotao = { color: p.TEXT, border: `1px solid ${p.BORDER}`, background: p.SURFACE };
    const temFixa = colunasDaSelecao().some(c => colunasFixas.includes(c.id));
    const celulaAtual = linhas[cursor.r];
    const colunaAtual = colunas[cursor.c];

    return (
        <div className="flex flex-col gap-2">
            {/* ── Barra de ferramentas ── */}
            {/* mousedown sem foco nos botões: a grade continua com o teclado depois do clique. */}
            <div className="flex flex-wrap items-center gap-1.5"
                onMouseDown={e => { if ((e.target as HTMLElement).closest('button')) e.preventDefault(); }}>
                <div className="relative">
                    <button type="button" className={botao} style={estiloBotao} title="Cor de fundo" onClick={() => setMenu(m => (m === 'fundo' ? null : 'fundo'))}>
                        <span className="w-3.5 h-3.5 rounded-sm" style={{ background: '#fff59d', border: '1px solid #00000022' }} /> Preencher ▾
                    </button>
                    {menu === 'fundo' && (
                        <Paleta p={p} cores={FUNDOS} onCor={cor => pintar(e => ({ ...e, bg: cor ?? undefined }))} />
                    )}
                </div>
                <div className="relative">
                    <button type="button" className={botao} style={estiloBotao} title="Cor do texto" onClick={() => setMenu(m => (m === 'texto' ? null : 'texto'))}>
                        <span className="font-bold underline decoration-2" style={{ textDecorationColor: '#c62828' }}>A</span> ▾
                    </button>
                    {menu === 'texto' && (
                        <Paleta p={p} cores={TEXTOS} onCor={cor => pintar(e => ({ ...e, cor: cor ?? undefined }))} />
                    )}
                </div>
                <button type="button" className={botao + ' font-bold'} style={estiloBotao} title="Negrito (Ctrl+B)" onClick={() => alternar('b')}>N</button>
                <button type="button" className={botao + ' italic'} style={estiloBotao} title="Itálico (Ctrl+I)" onClick={() => alternar('i')}>I</button>
                <button type="button" className={botao} style={estiloBotao} title="Tirar cores e negrito da seleção" onClick={() => pintar(() => ({}))}>Limpar cor</button>

                <span className="w-px h-5 mx-1" style={{ background: p.BORDER }} />

                <button type="button" className={botao} style={estiloBotao} onClick={() => inserirLinhas(true)} title="Inserir linha acima da seleção">+ Linha acima</button>
                <button type="button" className={botao} style={estiloBotao} onClick={() => inserirLinhas(false)} title="Inserir linha abaixo da seleção">+ Linha abaixo</button>
                <button type="button" className={botao} style={estiloBotao} onClick={apagarLinhas} disabled={!nLinhas} title="Apagar as linhas selecionadas">
                    Apagar {faixa.rMax > faixa.rMin ? `${faixa.rMax - faixa.rMin + 1} linhas` : 'linha'}
                </button>

                <span className="w-px h-5 mx-1" style={{ background: p.BORDER }} />

                {/* Tudo de coluna num lugar só: criar, apagar e, principalmente, a lista
                    para mostrar e ocultar — é o primeiro lugar onde se procura. */}
                <div className="relative">
                    <button type="button" className={botao}
                        style={ocultas.length ? { ...estiloBotao, color: p.AMBER, borderColor: p.AMBER + '88' } : estiloBotao}
                        onClick={() => setMenu(m => (m === 'colunas' ? null : 'colunas'))}
                        title="Mostrar, ocultar, criar e apagar colunas">
                        ☰ Colunas{ocultas.length ? ` · ${ocultas.length} oculta${ocultas.length > 1 ? 's' : ''}` : ''} ▾
                    </button>
                    {menu === 'colunas' && (
                        <>
                            <div className="fixed inset-0 z-30" onMouseDown={() => setMenu(null)} />
                            <div className="absolute z-40 mt-1 py-1 rounded-lg shadow-lg w-64" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}
                                onMouseDown={e => e.preventDefault()}>
                                <p className="px-3 pt-1 pb-1.5 text-[11px] uppercase tracking-wide" style={{ color: p.MUTED }}>Mostrar na planilha</p>
                                <div className="max-h-72 overflow-y-auto">
                                    {folha.colunas.map(c => (
                                        <button key={c.id} type="button" onClick={() => alternarVisivel(c)}
                                            disabled={!c.oculta && colunas.length <= 1}
                                            className="w-full text-left text-xs px-3 py-1.5 flex items-center gap-2 disabled:opacity-40"
                                            style={{ color: c.oculta ? p.MUTED : p.TEXT }}
                                            onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                                            onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                            <span className="w-4 h-4 rounded inline-grid place-items-center text-[10px] shrink-0"
                                                style={{ border: `1px solid ${c.oculta ? p.INPUT_BORDER : p.ACCENT}`, background: c.oculta ? 'transparent' : p.ACCENT, color: '#fff' }}>
                                                {c.oculta ? '' : '✓'}
                                            </span>
                                            <span className="truncate">{c.nome}</span>
                                            {c.oculta && <span className="ml-auto text-[10px]" style={{ color: p.AMBER }}>oculta</span>}
                                        </button>
                                    ))}
                                </div>
                                {ocultas.length > 0 && (
                                    <button type="button" onClick={() => reexibir(ocultas.map(c => c.id))}
                                        className="w-full text-left text-xs px-3 py-1.5 font-medium" style={{ color: p.ACCENT }}>
                                        Mostrar todas
                                    </button>
                                )}
                                <div className="my-1" style={{ borderTop: `1px solid ${p.BORDER}` }} />
                                <button type="button" onClick={() => { setMenu(null); novaColuna(); }}
                                    className="w-full text-left text-xs px-3 py-1.5" style={{ color: p.TEXT }}>+ Nova coluna à direita da seleção</button>
                                <button type="button" onClick={() => { setMenu(null); ocultarColunas(); }} disabled={faixa.cMax - faixa.cMin + 1 >= nColunas}
                                    className="w-full text-left text-xs px-3 py-1.5 disabled:opacity-40" style={{ color: p.TEXT }}>Ocultar as colunas selecionadas</button>
                                <button type="button" onClick={() => { setMenu(null); apagarColuna(); }} disabled={temFixa || !nColunas}
                                    title={temFixa ? 'As colunas do sistema não podem ser apagadas — dá para ocultar' : undefined}
                                    className="w-full text-left text-xs px-3 py-1.5 disabled:opacity-40" style={{ color: p.RED }}>Apagar a coluna selecionada</button>
                            </div>
                        </>
                    )}
                </div>
                <button type="button" className={botao} style={estiloBotao} onClick={() => ordenar(false)} title={`Ordenar pela coluna ${colunaAtual?.nome ?? ''} (A→Z)`}>A→Z</button>
                <button type="button" className={botao} style={estiloBotao} onClick={() => ordenar(true)} title={`Ordenar pela coluna ${colunaAtual?.nome ?? ''} (Z→A)`}>Z→A</button>

                <span className="w-px h-5 mx-1" style={{ background: p.BORDER }} />

                <button type="button" className={botao} style={estiloBotao} onClick={onDesfazer} disabled={!podeDesfazer} title="Desfazer (Ctrl+Z)">↶ Desfazer</button>

                <input
                    type="search"
                    value={busca}
                    onChange={e => setBusca(e.target.value)}
                    placeholder="Buscar nesta folha…"
                    className="ml-auto h-7 rounded-md text-xs px-2.5 outline-none w-full sm:w-56"
                    style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }}
                />
            </div>

            {/* ── Linha da célula: onde está o cursor e o valor inteiro ── */}
            <div className="flex items-center gap-2 text-xs rounded-md px-2 h-7" style={{ border: `1px solid ${p.BORDER}`, background: p.SURFACE }}>
                <span className="font-mono shrink-0 tabular-nums" style={{ color: p.MUTED, minWidth: 64 }}>
                    {colunaAtual ? `${colunaAtual.nome.slice(0, 14)} ${cursor.r + 1}` : '—'}
                </span>
                <span className="w-px h-4" style={{ background: p.BORDER }} />
                <span className="truncate" style={{ color: p.TEXT }}>
                    {celulaAtual && colunaAtual ? paraEditar(celulaAtual.v[colunaAtual.id]) : ''}
                </span>
                {nFiltros > 0 && (
                    <button type="button" onClick={() => filtrar({})}
                        className="ml-auto shrink-0 px-2 py-0.5 rounded font-medium"
                        style={{ color: p.ACCENT, border: `1px solid ${p.ACCENT}66` }}
                        title="Mostrar todas as linhas de novo">
                        ✕ Limpar {nFiltros > 1 ? `${nFiltros} filtros` : 'filtro'}
                    </button>
                )}
                <span className={`shrink-0 ${nFiltros ? '' : 'ml-auto'}`} style={{ color: p.MUTED }}>
                    {busca || nFiltros ? `${nLinhas} de ${folha.linhas.length}` : nLinhas} linhas
                </span>
            </div>

            {painelDeFiltro && (() => {
                const c = folha.colunas.find(x => x.id === painelDeFiltro.id);
                if (!c) return null;
                return (
                    <FiltroDaColuna
                        coluna={c}
                        linhas={folha.linhas}
                        realce={realce}
                        atual={filtros[c.id]}
                        p={p}
                        x={painelDeFiltro.x}
                        y={painelDeFiltro.y}
                        onAplicar={f => { filtrarColuna(c.id, f); setPainelDeFiltro(null); }}
                        onFechar={() => setPainelDeFiltro(null)}
                    />
                );
            })()}

            {menuDaColuna && (() => {
                const c = folha.colunas.find(x => x.id === menuDaColuna.id);
                if (!c) return null;
                const fixa = colunasFixas.includes(c.id);
                const item = (rotulo: string, acao: () => void, cor = p.TEXT, desligado = false) => (
                    <button type="button" disabled={desligado} onClick={() => { setMenuDaColuna(null); acao(); }}
                        className="w-full text-left text-xs px-3 py-1.5 disabled:opacity-40" style={{ color: cor }}
                        onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                        {rotulo}
                    </button>
                );
                return (
                    <>
                        <div className="fixed inset-0 z-40" onMouseDown={() => setMenuDaColuna(null)}
                            onContextMenu={e => { e.preventDefault(); setMenuDaColuna(null); }} />
                        <div className="fixed z-50 py-1 rounded-lg shadow-lg w-52"
                            style={{ left: Math.min(menuDaColuna.x, window.innerWidth - 216), top: menuDaColuna.y, background: p.SURFACE, border: `1px solid ${p.BORDER}` }}
                            onMouseDown={e => e.preventDefault()}>
                            <p className="px-3 pt-1 pb-1.5 text-[11px] font-semibold truncate" style={{ color: p.MUTED }}>{c.nome}</p>
                            {item('Filtrar…', () => setPainelDeFiltro({ id: c.id, x: menuDaColuna.x, y: menuDaColuna.y }))}
                            {filtros[c.id] && item('Limpar filtro desta coluna', () => filtrarColuna(c.id, undefined), p.ACCENT)}
                            {item('Ocultar esta coluna', () => onOps([{ t: 'col', coluna: { id: c.id, oculta: true } }]), p.TEXT, colunas.length <= 1)}
                            {item('Renomear', () => setRenomeando(c.id))}
                            {item('Ordenar A→Z', () => ordenar(false, c.id))}
                            {item('Ordenar Z→A', () => ordenar(true, c.id))}
                            {!fixa && item('Apagar coluna', () => onOps([{ t: 'delcol', id: c.id }]), p.RED)}
                        </div>
                    </>
                );
            })()}

            {/* ── A grade ── */}
            <div
                ref={grade}
                tabIndex={0}
                onKeyDown={tecla}
                onCopy={copiar}
                onCut={recortar}
                onPaste={colar}
                className="relative overflow-auto rounded-lg outline-none focus-visible:ring-2 select-none"
                style={{ maxHeight: altura, border: `1px solid ${p.BORDER}`, background: p.SURFACE }}
            >
                <table className="text-[13px] border-separate" style={{ borderSpacing: 0, tableLayout: 'fixed', width: larguraTotal }}>
                    <colgroup>
                        <col style={{ width: LARGURA_DO_NUMERO }} />
                        {colunas.map(c => <col key={c.id} style={{ width: largura(c) }} />)}
                    </colgroup>
                    <thead>
                        <tr>
                            <th className="sticky top-0 left-0 z-30" style={{ background: p.BG, borderBottom: `1px solid ${p.BORDER}`, borderRight: `1px solid ${p.BORDER}`, height: ALTURA_DA_LINHA }}
                                onMouseDown={e => { e.preventDefault(); grade.current?.focus(); setSel({ r0: 0, c0: 0, r1: nLinhas - 1, c1: nColunas - 1, modo: 'cel' }); }}
                                title="Selecionar tudo" />
                            {colunas.map((c, j) => {
                                const marcada = j >= faixa.cMin && j <= faixa.cMax;
                                return (
                                    <th key={c.id}
                                        className="group sticky top-0 z-20 text-left font-semibold text-[11px] uppercase tracking-wide pl-2 pr-6 relative"
                                        style={{
                                            background: marcada ? (sel.modo === 'col' ? p.ACCENT + '33' : p.ACCENT + '18') : p.BG,
                                            color: c.estilo?.cor ?? (c.gerada ? p.MUTED : p.TEXT),
                                            borderBottom: `1px solid ${p.BORDER}`, borderRight: `1px solid ${p.BORDER}`,
                                            height: ALTURA_DA_LINHA,
                                            boxShadow: c.estilo?.bg ? `inset 0 -3px 0 ${c.estilo.bg}` : undefined,
                                        }}
                                        title={c.gerada ? `${c.nome} — preenchida pelo sistema; "Atualizar" escreve de novo. Duplo clique para renomear.` : `${c.nome} — duplo clique para renomear`}
                                        onMouseDown={e => descerNoCabecalho(j, e)}
                                        onMouseEnter={() => arrastando.current === 'col' && setSel(s => ({ ...s, c1: j }))}
                                        onDoubleClick={() => setRenomeando(c.id)}
                                        onContextMenu={e => { e.preventDefault(); setMenuDaColuna({ id: c.id, x: e.clientX, y: e.clientY }); }}
                                    >
                                        {renomeando === c.id ? (
                                            <input
                                                autoFocus
                                                defaultValue={c.nome}
                                                onMouseDown={e => e.stopPropagation()}
                                                onFocus={e => e.currentTarget.select()}
                                                onBlur={e => {
                                                    const nome = e.currentTarget.value.trim();
                                                    if (nome && nome !== c.nome) onOps([{ t: 'col', coluna: { id: c.id, nome } }]);
                                                    setRenomeando(null);
                                                    grade.current?.focus({ preventScroll: true });
                                                }}
                                                onKeyDown={e => {
                                                    if (e.key === 'Enter') e.currentTarget.blur();
                                                    if (e.key === 'Escape') { e.currentTarget.value = c.nome; e.currentTarget.blur(); }
                                                }}
                                                className="w-full text-xs normal-case px-1 py-0.5 rounded outline-none"
                                                style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.ACCENT}` }}
                                            />
                                        ) : (
                                            <span className="block truncate">{c.nome}</span>
                                        )}
                                        {renomeando !== c.id && (
                                            <button type="button" aria-label={`Opções da coluna ${c.nome}`}
                                                title={filtros[c.id] ? 'Coluna filtrada — clique para mudar ou limpar o filtro' : 'Opções da coluna e filtro (ou botão direito)'}
                                                onMouseDown={e => e.stopPropagation()}
                                                onClick={e => {
                                                    const r = e.currentTarget.getBoundingClientRect();
                                                    setMenuDaColuna(m => (m?.id === c.id ? null : { id: c.id, x: r.left, y: r.bottom + 2 }));
                                                }}
                                                className={`absolute right-2 top-1/2 -translate-y-1/2 w-4 h-4 rounded text-[10px] leading-none inline-grid place-items-center transition-opacity ${menuDaColuna?.id === c.id || filtros[c.id] ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 focus:opacity-100'}`}
                                                style={filtros[c.id]
                                                    ? { color: '#fff', background: p.ACCENT, border: `1px solid ${p.ACCENT}` }
                                                    : { color: p.TEXT, background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                                                {filtros[c.id] ? (
                                                    <svg viewBox="0 0 16 16" width="9" height="9" fill="currentColor" aria-hidden="true">
                                                        <path d="M1 2h14l-5.5 6.5V14l-3-1.5v-4z" />
                                                    </svg>
                                                ) : '▾'}
                                            </button>
                                        )}
                                        <span onMouseDown={e => redimensionar(c, e)}
                                            className="absolute top-0 right-0 h-full w-1.5 cursor-col-resize hover:bg-blue-400/50" />
                                    </th>
                                );
                            })}
                        </tr>
                    </thead>
                    <tbody>
                        {linhas.map((l, r) => {
                            const naFaixa = r >= faixa.rMin && r <= faixa.rMax;
                            return (
                                <LinhaDaGrade
                                    key={l.id}
                                    linha={l}
                                    r={r}
                                    colunas={colunas}
                                    p={p}
                                    c0={naFaixa ? faixa.cMin : -1}
                                    c1={naFaixa ? faixa.cMax : -1}
                                    linhaInteira={naFaixa && sel.modo === 'lin'}
                                    cursorC={cursor.r === r ? cursor.c : -1}
                                    edicao={edicao && edicao.r === r ? edicao : null}
                                    focos={focosPorLinha.get(l.id)}
                                    realce={realce}
                                    dica={dica}
                                    onDescer={descerNaCelula}
                                    onPassar={passarNaCelula}
                                    onDuplo={duploClique}
                                    onNumero={descerNoNumero}
                                    onTexto={mudarTexto}
                                    onFechar={fecharEdicao}
                                />
                            );
                        })}
                    </tbody>
                </table>

                {!nLinhas && (
                    <div className="px-4 py-8 text-sm text-center" style={{ color: p.MUTED }}>
                        {busca ? 'Nada encontrado nesta folha.' : 'Folha vazia — use "+ Linha abaixo" ou cole do Excel (Ctrl+V).'}
                    </div>
                )}
            </div>

            <p className="text-[11px]" style={{ color: p.MUTED }}>
                Clique e digite · Enter desce · Tab vai para o lado · arraste para selecionar · clique no número da linha
                ou no nome da coluna para pegar inteira · Ctrl+C / Ctrl+V com o Excel · Delete limpa · Ctrl+Z desfaz.
            </p>
        </div>
    );
}

// ─── Paleta ────────────────────────────────────────────────────────────────────

function Paleta({ p, cores, onCor }: { p: Palette; cores: { nome: string; cor: string }[]; onCor: (cor: string | null) => void }) {
    return (
        <div className="absolute z-40 mt-1 p-2 rounded-lg shadow-lg w-44" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}
            onMouseDown={e => e.preventDefault()}>
            <div className="grid grid-cols-4 gap-1.5">
                {cores.map(c => (
                    <button key={c.cor} type="button" title={c.nome} onClick={() => onCor(c.cor)}
                        className="h-7 rounded-md transition hover:scale-110" style={{ background: c.cor, border: '1px solid #00000026' }} />
                ))}
            </div>
            <button type="button" onClick={() => onCor(null)} className="mt-2 w-full text-xs py-1 rounded-md"
                style={{ color: p.TEXT, border: `1px solid ${p.BORDER}` }}>
                Sem cor
            </button>
        </div>
    );
}

// ─── Filtro de uma coluna ──────────────────────────────────────────────────────

interface PropsDoFiltro {
    coluna: Coluna;
    linhas: Linha[];
    realce?: Realce;
    atual?: Filtro;
    p: Palette;
    x: number;
    y: number;
    onAplicar: (f: Filtro | undefined) => void;
    onFechar: () => void;
}

/**
 * O painel do filtro, como o do Excel: a lista dos valores da coluna para
 * marcar, as cores de fundo que aparecem nela e atalhos para vazias e
 * preenchidas. Digitar na busca e dar Enter deixa só o que bateu.
 */
function FiltroDaColuna({ coluna, linhas, realce, atual, p, x, y, onAplicar, onFechar }: PropsDoFiltro) {
    const valores = useMemo(() => {
        const n = new Map<string, number>();
        for (const l of linhas) {
            const t = textoDaCelula(l, coluna);
            n.set(t, (n.get(t) ?? 0) + 1);
        }
        return [...n.entries()].sort(([a], [b]) =>
            a === '' ? -1 : b === '' ? 1 : a.localeCompare(b, 'pt-BR', { numeric: true }));
    }, [linhas, coluna]);

    const cores = useMemo(() => {
        const n = new Map<string, number>();
        for (const l of linhas) {
            const cor = fundoDaCelula(l, coluna, realce);
            n.set(cor, (n.get(cor) ?? 0) + 1);
        }
        return [...n.entries()].sort(([a], [b]) => (a === '' ? -1 : b === '' ? 1 : 0));
    }, [linhas, coluna, realce]);

    const [marcados, setMarcados] = useState<Set<string>>(() => new Set(atual?.valores ?? valores.map(([v]) => v)));
    const [cor, setCor] = useState<string | undefined>(atual?.cor);
    const [busca, setBusca] = useState('');

    const q = semAcento(busca.trim());
    const visiveis = q ? valores.filter(([v]) => v !== '' && semAcento(v).includes(q)) : valores;
    const todosVisiveisMarcados = visiveis.every(([v]) => marcados.has(v));

    const montar = (valoresQueFicam: Set<string>, corQueFica = cor): Filtro | undefined => {
        const f: Filtro = {};
        if (valoresQueFicam.size < valores.length) f.valores = [...valoresQueFicam];
        if (corQueFica !== undefined) f.cor = corQueFica;
        return Object.keys(f).length ? f : undefined;
    };

    const alternarValor = (v: string) => setMarcados(m => {
        const n = new Set(m);
        n.has(v) ? n.delete(v) : n.add(v);
        return n;
    });

    const alternarVisiveis = () => setMarcados(m => {
        const n = new Set(m);
        for (const [v] of visiveis) todosVisiveisMarcados ? n.delete(v) : n.add(v);
        return n;
    });

    const so = (vazias: boolean) =>
        onAplicar(montar(new Set(valores.map(([v]) => v).filter(v => (v === '') === vazias))));

    const largura = 272;
    const botao = 'text-xs px-2 py-1 rounded-md disabled:opacity-40';

    return (
        <>
            <div className="fixed inset-0 z-40" onMouseDown={onFechar} onContextMenu={e => { e.preventDefault(); onFechar(); }} />
            <div className="fixed z-50 rounded-lg shadow-lg flex flex-col text-xs"
                style={{
                    left: Math.max(8, Math.min(x, window.innerWidth - largura - 8)), top: Math.max(8, Math.min(y, window.innerHeight - 420)),
                    width: largura, maxHeight: 'min(80vh, 520px)', background: p.SURFACE, border: `1px solid ${p.BORDER}`, color: p.TEXT,
                }}
                onKeyDown={e => { if (e.key === 'Escape') onFechar(); }}>
                <p className="px-3 pt-2.5 pb-1.5 font-semibold truncate">Filtrar: {coluna.nome}</p>

                <div className="px-3 pb-2 flex gap-1.5">
                    <button type="button" className={botao} style={{ border: `1px solid ${p.BORDER}` }}
                        disabled={!valores.some(([v]) => v === '')} onClick={() => so(true)}>Só vazias</button>
                    <button type="button" className={botao} style={{ border: `1px solid ${p.BORDER}` }}
                        disabled={!valores.some(([v]) => v !== '')} onClick={() => so(false)}>Só preenchidas</button>
                </div>

                {cores.length > 1 && (
                    <div className="px-3 pb-2">
                        <p className="pb-1 text-[11px] uppercase tracking-wide" style={{ color: p.MUTED }}>Pela cor de fundo</p>
                        <div className="flex flex-wrap gap-1.5">
                            {cores.map(([c, n]) => {
                                const escolhida = cor === c;
                                return (
                                    <button key={c || 'sem'} type="button" title={`${nomeDaCor(c)} (${n})`}
                                        onClick={() => setCor(escolhida ? undefined : c)}
                                        className="h-6 px-1.5 rounded-md inline-flex items-center gap-1"
                                        style={{ border: `1px solid ${escolhida ? p.ACCENT : p.BORDER}`, boxShadow: escolhida ? `0 0 0 1px ${p.ACCENT}` : undefined }}>
                                        <span className="w-3.5 h-3.5 rounded-sm"
                                            style={{ background: c || 'transparent', border: `1px ${c ? 'solid' : 'dashed'} ${c ? '#00000026' : p.MUTED}` }} />
                                        <span className="tabular-nums" style={{ color: p.MUTED }}>{n}</span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                )}

                <div className="px-3 pb-1.5">
                    <input type="search" autoFocus value={busca} onChange={e => setBusca(e.target.value)}
                        onKeyDown={e => {
                            if (e.key === 'Enter' && q && visiveis.length) onAplicar(montar(new Set(visiveis.map(([v]) => v))));
                        }}
                        placeholder="Procurar (Enter filtra o que achar)"
                        className="w-full h-7 rounded-md px-2 outline-none"
                        style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }} />
                </div>

                <div className="flex-1 min-h-0 overflow-y-auto px-1.5 pb-1" style={{ maxHeight: 260 }}>
                    <label className="flex items-center gap-2 px-1.5 py-1 rounded cursor-pointer font-medium">
                        <input type="checkbox" checked={visiveis.length > 0 && todosVisiveisMarcados} onChange={alternarVisiveis} />
                        {q ? 'Marcar os encontrados' : 'Selecionar tudo'}
                    </label>
                    {visiveis.slice(0, 500).map(([v, n]) => (
                        <label key={v || '(vazias)'} className="flex items-center gap-2 px-1.5 py-1 rounded cursor-pointer"
                            onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                            onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                            <input type="checkbox" checked={marcados.has(v)} onChange={() => alternarValor(v)} />
                            <span className="truncate" style={v === '' ? { color: p.MUTED, fontStyle: 'italic' } : undefined}>{v === '' ? '(Vazias)' : v}</span>
                            <span className="ml-auto tabular-nums" style={{ color: p.MUTED }}>{n}</span>
                        </label>
                    ))}
                    {visiveis.length > 500 && (
                        <p className="px-1.5 py-1" style={{ color: p.MUTED }}>…e mais {visiveis.length - 500}. Procure para achar.</p>
                    )}
                    {!visiveis.length && <p className="px-1.5 py-1" style={{ color: p.MUTED }}>Nada encontrado.</p>}
                </div>

                <div className="flex items-center gap-1.5 px-3 py-2" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                    <button type="button" className={botao} style={{ color: p.RED }} disabled={!atual} onClick={() => onAplicar(undefined)}>Limpar</button>
                    <button type="button" className={botao + ' ml-auto'} style={{ border: `1px solid ${p.BORDER}` }} onClick={onFechar}>Cancelar</button>
                    <button type="button" className={botao + ' font-medium'} style={{ background: p.ACCENT, color: '#fff' }}
                        disabled={!marcados.size} onClick={() => onAplicar(montar(marcados))}>Filtrar</button>
                </div>
            </div>
        </>
    );
}

// ─── Uma linha ─────────────────────────────────────────────────────────────────

interface PropsDaLinha {
    linha: Linha;
    r: number;
    colunas: Coluna[];
    p: Palette;
    /** Colunas selecionadas nesta linha (-1 = nenhuma). */
    c0: number;
    c1: number;
    linhaInteira: boolean;
    cursorC: number;
    edicao: Edicao | null;
    focos?: FocoNaFolha[];
    realce?: (linha: Linha, coluna: Coluna) => Estilo | undefined;
    dica?: (linha: Linha) => string | undefined;
    onDescer: (r: number, c: number, e: React.MouseEvent) => void;
    onPassar: (r: number, c: number) => void;
    onDuplo: (r: number, c: number) => void;
    onNumero: (r: number, e: React.MouseEvent) => void;
    onTexto: (texto: string) => void;
    onFechar: (gravar: boolean, depois?: [number, number]) => void;
}

const LinhaDaGrade = memo(function LinhaDaGrade({
    linha, r, colunas, p, c0, c1, linhaInteira, cursorC, edicao, focos, realce, dica,
    onDescer, onPassar, onDuplo, onNumero, onTexto, onFechar,
}: PropsDaLinha) {
    const borda = `1px solid ${p.BORDER}`;

    return (
        <tr title={dica?.(linha)}>
            <td
                className="sticky left-0 z-10 text-right pr-2 text-[11px] font-mono tabular-nums cursor-pointer"
                style={{
                    background: linhaInteira ? p.ACCENT + '33' : c0 >= 0 ? p.ACCENT + '18' : p.BG,
                    color: p.MUTED, borderBottom: borda, borderRight: borda, height: ALTURA_DA_LINHA,
                }}
                onMouseDown={e => onNumero(r, e)}
                onMouseEnter={() => onPassar(r, 0)}
            >
                {r + 1}
            </td>
            {colunas.map((col, c) => {
                const v = linha.v[col.id];
                const e = juntar(linha.e?.[col.id], linha.s, col.estilo, realce?.(linha, col));
                const selecionada = c >= c0 && c <= c1;
                const noCursor = c === cursorC;
                const colega = focos?.find(f => f.c === col.id);
                const editando = edicao && edicao.c === c;
                const numero = col.tipo !== 'texto' && typeof v === 'number';

                const sombras = [
                    noCursor ? `inset 0 0 0 2px ${p.ACCENT}` : colega ? `inset 0 0 0 2px ${colega.cor}` : '',
                    selecionada && !noCursor ? `inset 0 0 0 999px ${p.ACCENT}22` : '',
                ].filter(Boolean).join(', ');

                return (
                    <td
                        key={col.id}
                        data-r={r}
                        data-c={c}
                        className={`relative px-2 overflow-hidden whitespace-nowrap text-ellipsis ${numero ? 'text-right tabular-nums' : ''}`}
                        style={{
                            height: ALTURA_DA_LINHA,
                            borderBottom: borda,
                            borderRight: borda,
                            background: e.bg,
                            color: e.cor ?? (e.bg ? TEXTO_SOBRE_FUNDO : p.TEXT),
                            fontWeight: e.b ? 700 : undefined,
                            fontStyle: e.i ? 'italic' : undefined,
                            boxShadow: sombras || undefined,
                            scrollMarginTop: ALTURA_DA_LINHA,
                            scrollMarginLeft: LARGURA_DO_NUMERO,
                        }}
                        onMouseDown={ev => onDescer(r, c, ev)}
                        onMouseEnter={() => onPassar(r, c)}
                        onDoubleClick={() => onDuplo(r, c)}
                    >
                        {editando ? (
                            <input
                                autoFocus
                                onFocus={ev => { const n = ev.currentTarget.value.length; ev.currentTarget.setSelectionRange(n, n); }}
                                value={edicao.texto}
                                onChange={ev => onTexto(ev.target.value)}
                                onMouseDown={ev => ev.stopPropagation()}
                                onBlur={() => onFechar(true)}
                                onKeyDown={ev => {
                                    if (ev.key === 'Enter') { ev.preventDefault(); onFechar(true, [ev.shiftKey ? -1 : 1, 0]); }
                                    else if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') { ev.preventDefault(); onFechar(true, [ev.key === 'ArrowDown' ? 1 : -1, 0]); }
                                    else if ((ev.key === 'ArrowRight' || ev.key === 'ArrowLeft') && edicao.porCima) { ev.preventDefault(); onFechar(true, [0, ev.key === 'ArrowRight' ? 1 : -1]); }
                                    else if (ev.key === 'Tab') { ev.preventDefault(); onFechar(true, [0, ev.shiftKey ? -1 : 1]); }
                                    else if (ev.key === 'Escape') { ev.preventDefault(); onFechar(false); }
                                }}
                                className={`absolute inset-0 w-full h-full px-2 outline-none text-[13px] ${col.tipo !== 'texto' ? 'text-right' : ''}`}
                                style={{ background: p.INPUT_BG, color: p.TEXT, boxShadow: `inset 0 0 0 2px ${p.ACCENT}` }}
                            />
                        ) : (
                            mostrar(v, col.tipo)
                        )}
                        {colega && !editando && (
                            <span className="absolute -top-px right-0 px-1 text-[9px] leading-[12px] font-semibold text-white rounded-bl pointer-events-none"
                                style={{ background: colega.cor }}>
                                {colega.nome.split(' ')[0]}
                            </span>
                        )}
                    </td>
                );
            })}
        </tr>
    );
});
