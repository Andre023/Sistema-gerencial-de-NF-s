import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT, Palette } from '@/lib/tema';

interface Ref { id: number; nome: string }

interface Item {
    id: number;
    nome: string;
    notas: number;
    matriz: Ref | null;
    filiais: Ref[];
}

interface Vinculo {
    id: number;
    nome: string;
    notas: number;
    filiais: Ref[];
}

interface Props {
    vinculos: Vinculo[];
    total: number;
}

const plural = (n: number, s: string, p: string) => `${n} ${n === 1 ? s : p}`;

/**
 * Busca no servidor, com atraso de digitação. São ~2.800 nomes — mandar todos
 * para a tela repetiria o erro que custava 136 KB por ação na fila.
 */
function useBusca() {
    const [termo, setTermo] = useState('');
    const [itens, setItens] = useState<Item[]>([]);
    const [truncada, setTruncada] = useState(false);
    const [carregando, setCarregando] = useState(false);
    const relogio = useRef<number | null>(null);

    const buscar = useCallback(async (q: string) => {
        if (q.trim() === '') { setItens([]); setTruncada(false); return; }
        setCarregando(true);
        try {
            const { data } = await window.axios.get(route('fornecedores.buscar'), { params: { q } });
            setItens(data.fornecedores);
            setTruncada(data.truncada);
        } catch {
            setItens([]);
        } finally {
            setCarregando(false);
        }
    }, []);

    useEffect(() => {
        if (relogio.current !== null) clearTimeout(relogio.current);
        relogio.current = window.setTimeout(() => buscar(termo), 300);
        return () => { if (relogio.current !== null) clearTimeout(relogio.current); };
    }, [termo, buscar]);

    const limpar = () => { setTermo(''); setItens([]); setTruncada(false); };

    return { termo, setTermo, itens, truncada, carregando, limpar };
}

function Etiqueta({ item, p }: { item: Item; p: Palette }) {
    if (item.matriz) {
        return <span className="text-xs shrink-0" style={{ color: p.AMBER }}>filial de {item.matriz.nome}</span>;
    }
    if (item.filiais.length > 0) {
        return <span className="text-xs shrink-0" style={{ color: p.ACCENT }}>{plural(item.filiais.length, 'filial', 'filiais')}</span>;
    }
    return null;
}

function Caixa({ children, p, titulo }: { children: ReactNode; p: Palette; titulo?: string }) {
    return (
        <div className="rounded-xl overflow-hidden" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
            {titulo && (
                <div className="px-4 py-3 text-sm font-semibold" style={{ color: p.TEXT, borderBottom: `1px solid ${p.BORDER}` }}>
                    {titulo}
                </div>
            )}
            {children}
        </div>
    );
}

export default function Vinculos({ vinculos, total }: Props) {
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;

    const busca = useBusca();
    const buscaMatriz = useBusca();

    /** A filial escolhida no passo 1; enquanto houver uma, o passo 2 está aberto. */
    const [filial, setFilial] = useState<Item | null>(null);
    const [salvando, setSalvando] = useState(false);
    const [erro, setErro] = useState<string | null>(null);
    const [aviso, setAviso] = useState<string | null>(null);

    const input = { background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` };

    const escolherFilial = (item: Item) => {
        setFilial(item);
        setErro(null);
        setAviso(null);
        buscaMatriz.limpar();
    };

    const cancelar = () => { setFilial(null); setErro(null); buscaMatriz.limpar(); };

    /**
     * O aviso cita os dois nomes e o número de notas de propósito: numa lista
     * de nomes parecidos — que é o caso de quem veio juntar duplicado —
     * "tem certeza?" não diz qual vira filial de qual.
     */
    const vincular = async (matriz: Item) => {
        if (!filial) return;

        const msg = `"${filial.nome}" vira filial de "${matriz.nome}".\n\n`
            + `${plural(filial.notas, 'nota', 'notas')} de "${filial.nome}" passam para "${matriz.nome}", `
            + `e o nome "${filial.nome}" deixa de aparecer na hora de lançar nota.\n\nConfirmar?`;
        if (!confirm(msg)) return;

        setSalvando(true);
        setErro(null);
        try {
            const { data } = await window.axios.patch(route('fornecedores.vincular', filial.id), { matriz_id: matriz.id });
            setAviso(`"${filial.nome}" agora é filial de "${matriz.nome}" — ${plural(data.movidas, 'nota movida', 'notas movidas')}.`);
            setFilial(null);
            busca.limpar();
            buscaMatriz.limpar();
            router.reload({ only: ['vinculos'] });
        } catch (e: any) {
            setErro(e?.response?.data?.erro ?? 'Não foi possível vincular.');
        } finally {
            setSalvando(false);
        }
    };

    const desvincular = async (matriz: Vinculo, f: Ref) => {
        const msg = `Desfazer o vínculo de "${f.nome}" com "${matriz.nome}"?\n\n`
            + `As notas que já passaram para "${matriz.nome}" ficam lá. "${f.nome}" volta a ser um cadastro separado, sem notas.`;
        if (!confirm(msg)) return;

        setErro(null);
        try {
            await window.axios.delete(route('fornecedores.desvincular', f.id));
            setAviso(`"${f.nome}" deixou de ser filial de "${matriz.nome}".`);
            router.reload({ only: ['vinculos'] });
        } catch (e: any) {
            setErro(e?.response?.data?.erro ?? 'Não foi possível desfazer.');
        }
    };

    /** No passo 2 não faz sentido oferecer a própria filial nem outras filiais. */
    const candidatasAMatriz = buscaMatriz.itens.filter(i => i.id !== filial?.id && i.matriz === null);

    const linha = (item: Item, acao: ReactNode) => (
        <div key={item.id} className="flex items-center gap-3 px-4 py-2.5" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
            <div className="flex-1 min-w-0">
                <div className="flex items-center gap-2 min-w-0">
                    <span className="text-sm truncate" style={{ color: p.TEXT }}>{item.nome}</span>
                    <Etiqueta item={item} p={p} />
                </div>
                <span className="text-xs" style={{ color: p.MUTED }}>{plural(item.notas, 'nota', 'notas')}</span>
            </div>
            {acao}
        </div>
    );

    const botao = (rotulo: string, onClick: () => void, cor: string) => (
        <button type="button" onClick={onClick} disabled={salvando}
            className="shrink-0 text-xs font-medium px-3 py-1.5 rounded-lg transition disabled:opacity-50"
            style={{ color: cor, border: `1px solid ${cor}55` }}
            onMouseEnter={e => (e.currentTarget.style.background = cor + '1a')}
            onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
            {rotulo}
        </button>
    );

    const vazio = (texto: string) => <p className="text-sm px-4 py-6 text-center" style={{ color: p.MUTED }}>{texto}</p>;

    return (
        <AuthenticatedLayout header={null}>
            <Head title="Matriz e filial" />

            <div className="flex-1 w-full py-6 px-4 sm:px-6 lg:px-8 max-w-3xl mx-auto space-y-5 transition-colors duration-200"
                style={{ background: p.BG }}>

                <div>
                    <h1 className="text-lg font-semibold" style={{ color: p.TEXT }}>Matriz e filial</h1>
                    <p className="text-sm mt-1" style={{ color: p.MUTED }}>
                        O mesmo fornecedor cadastrado duas vezes — matriz e filial, ou "S.A." e "S/A". Ao vincular,
                        as notas da filial passam para a matriz e só a matriz aparece na hora de lançar nota;
                        buscar pelo nome da filial encontra a matriz. Nada é apagado.
                    </p>
                </div>

                {aviso && (
                    <p className="text-sm rounded-lg px-4 py-2.5"
                        style={{ background: p.GREEN + '14', color: p.GREEN, border: `1px solid ${p.GREEN}33` }}>
                        {aviso}
                    </p>
                )}
                {erro && (
                    <p className="text-sm rounded-lg px-4 py-2.5"
                        style={{ background: p.RED + '14', color: p.RED, border: `1px solid ${p.RED}33` }}>
                        {erro}
                    </p>
                )}

                {/* Passo 1: qual é a filial */}
                {!filial && (
                    <div className="rounded-xl p-4 space-y-3" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                        <label className="block text-sm font-medium" style={{ color: p.MUTED }}>
                            1. Qual cadastro é a filial?
                        </label>
                        <input value={busca.termo} onChange={e => busca.setTermo(e.target.value)}
                            placeholder={`Buscar entre os ${total} fornecedores...`}
                            className="block w-full rounded-lg text-sm px-3 py-2 outline-none" style={input} />

                        {busca.termo.trim() !== '' && (
                            <div className="rounded-lg overflow-hidden" style={{ border: `1px solid ${p.BORDER}` }}>
                                {busca.carregando && busca.itens.length === 0
                                    ? vazio('Buscando...')
                                    : busca.itens.length === 0
                                        ? vazio('Nenhum fornecedor com esse nome.')
                                        : busca.itens.map(item => linha(item,
                                            item.matriz
                                                ? <span className="text-xs shrink-0" style={{ color: p.MUTED }}>já vinculado</span>
                                                : botao('É filial de…', () => escolherFilial(item), p.ACCENT),
                                        ))}
                            </div>
                        )}
                        {busca.truncada && (
                            <p className="text-xs" style={{ color: p.MUTED }}>
                                Mostrando os primeiros resultados. Escreva mais do nome para estreitar a busca.
                            </p>
                        )}
                    </div>
                )}

                {/* Passo 2: de qual matriz */}
                {filial && (
                    <div className="rounded-xl p-4 space-y-3" style={{ background: p.SURFACE, border: `1px solid ${p.ACCENT}66` }}>
                        <div className="flex items-start justify-between gap-3">
                            <label className="block text-sm font-medium" style={{ color: p.MUTED }}>
                                2. <span style={{ color: p.TEXT }}>{filial.nome}</span> é filial de qual matriz?
                            </label>
                            <button type="button" onClick={cancelar} className="text-xs shrink-0" style={{ color: p.MUTED }}>
                                Cancelar
                            </button>
                        </div>
                        <input autoFocus value={buscaMatriz.termo} onChange={e => buscaMatriz.setTermo(e.target.value)}
                            placeholder="Buscar a matriz pelo nome..."
                            className="block w-full rounded-lg text-sm px-3 py-2 outline-none" style={input} />

                        {buscaMatriz.termo.trim() !== '' && (
                            <div className="rounded-lg overflow-hidden" style={{ border: `1px solid ${p.BORDER}` }}>
                                {buscaMatriz.carregando && buscaMatriz.itens.length === 0
                                    ? vazio('Buscando...')
                                    : candidatasAMatriz.length === 0
                                        ? vazio('Nenhuma matriz com esse nome.')
                                        : candidatasAMatriz.map(item => linha(item,
                                            botao(salvando ? '...' : 'Esta é a matriz', () => vincular(item), p.GREEN),
                                        ))}
                            </div>
                        )}
                    </div>
                )}

                {/* O que já está vinculado */}
                <Caixa p={p} titulo={`Vínculos (${vinculos.length})`}>
                    {vinculos.length === 0
                        ? vazio('Nenhum vínculo ainda. Busque acima o cadastro duplicado e escolha a matriz.')
                        : vinculos.map(m => (
                            <div key={m.id} className="px-4 py-3" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                <div className="flex items-center gap-2">
                                    <span className="text-sm font-medium truncate" style={{ color: p.TEXT }}>{m.nome}</span>
                                    <span className="text-xs shrink-0" style={{ color: p.MUTED }}>{plural(m.notas, 'nota', 'notas')}</span>
                                </div>
                                <div className="mt-1.5 space-y-1">
                                    {m.filiais.map(f => (
                                        <div key={f.id} className="flex items-center gap-2 pl-4">
                                            <span className="text-xs" style={{ color: p.MUTED }}>↳</span>
                                            <span className="text-sm truncate flex-1 min-w-0" style={{ color: p.TEXT }}>{f.nome}</span>
                                            {botao('Desfazer', () => desvincular(m, f), p.RED)}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        ))}
                </Caixa>

            </div>
        </AuthenticatedLayout>
    );
}
