import { useCallback, useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import Secoes, { Cartao } from '@/Components/configuracoes/Secoes';
import Icone from '@/Components/painel/Icone';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT, Palette } from '@/lib/tema';

interface Item {
    id: number;
    nome: string;
    /** Só na lista da campanha. */
    faturamento: number | null;
    /** Só na lista das notas — quantas notas dependem deste nome. */
    notas: number | null;
}

type Lista = 'notas' | 'campanha';

interface Props {
    totalNotas: number;
    totalCampanha: number;
}

const dinheiro = (v: number) =>
    v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 });

/**
 * Uma linha da lista: mostra o nome e vira campo ao clicar em editar.
 *
 * A edição acontece na própria linha, e não num modal, porque o que se conserta
 * aqui é quase sempre uma letra — abrir e fechar janela para trocar um "SS" por
 * "S" custaria mais que o conserto.
 */
function Linha({ item, lista, onSalvo, onExcluido, p }: {
    item: Item; lista: Lista; onSalvo: (i: Item) => void; onExcluido: (id: number) => void; p: Palette;
}) {
    const [editando, setEditando] = useState(false);
    const [nome, setNome] = useState(item.nome);
    const [erro, setErro] = useState<string | null>(null);
    const [salvando, setSalvando] = useState(false);
    const campo = useRef<HTMLInputElement>(null);

    useEffect(() => { setNome(item.nome); setErro(null); }, [item.nome]);
    useEffect(() => { if (editando) campo.current?.focus(); }, [editando]);

    const cancelar = () => { setNome(item.nome); setErro(null); setEditando(false); };

    const salvar = async () => {
        const limpo = nome.trim();

        if (limpo === '' || limpo === item.nome) { cancelar(); return; }

        setSalvando(true);
        setErro(null);
        try {
            const url = lista === 'campanha'
                ? route('configuracoes.fornecedores.campanha.renomear', item.id)
                : route('configuracoes.fornecedores.renomear', item.id);

            const { data } = await window.axios.patch(url, { nome: limpo });

            onSalvo(data.fornecedor);
            setEditando(false);
        } catch (e: any) {
            const porCampo = Object.values(
                (e?.response?.data?.errors ?? {}) as Record<string, string[]>,
            )[0];

            setErro(e?.response?.data?.erro ?? porCampo?.[0] ?? 'Não foi possível salvar.');
        } finally {
            setSalvando(false);
        }
    };

    /**
     * Apagar.
     *
     * O aviso cita o nome inteiro de propósito: numa lista de nomes parecidos —
     * que é exatamente o caso de quem veio limpar duplicado — "tem certeza?"
     * não diz qual dos dois vai embora.
     */
    const excluir = async () => {
        if (!confirm(`Apagar "${item.nome}" da lista?`)) return;

        setSalvando(true);
        setErro(null);
        try {
            const url = lista === 'campanha'
                ? route('configuracoes.fornecedores.campanha.excluir', item.id)
                : route('configuracoes.fornecedores.excluir', item.id);

            await window.axios.delete(url);
            onExcluido(item.id);
        } catch (e: any) {
            setErro(e?.response?.data?.erro ?? 'Não foi possível apagar.');
        } finally {
            setSalvando(false);
        }
    };

    return (
        <div className="px-3 py-2" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
            <div className="flex items-center gap-2">
                {editando ? (
                    <input
                        ref={campo}
                        value={nome}
                        onChange={e => setNome(e.target.value)}
                        onKeyDown={e => {
                            if (e.key === 'Enter') salvar();
                            if (e.key === 'Escape') cancelar();
                        }}
                        maxLength={255}
                        className="flex-1 min-w-0 rounded-lg text-sm px-2.5 py-1.5 outline-none"
                        style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${erro ? p.RED : p.ACCENT}` }}
                    />
                ) : (
                    <span className="flex-1 min-w-0 text-sm truncate" style={{ color: p.TEXT }}>{item.nome}</span>
                )}

                {/* O peso do nome: quantas notas dependem dele, ou o faturamento
                    que ele carrega na campanha. É o que diz se vale mexer. */}
                {!editando && item.notas !== null && (
                    <span className="text-xs shrink-0" style={{ color: p.MUTED }}>
                        {item.notas} nota{item.notas === 1 ? '' : 's'}
                    </span>
                )}
                {!editando && item.faturamento !== null && (
                    <span className="text-xs shrink-0" style={{ color: p.MUTED }}>{dinheiro(item.faturamento)}</span>
                )}

                {editando ? (
                    <>
                        <button type="button" onClick={salvar} disabled={salvando}
                            className="shrink-0 text-xs font-medium px-2 py-1 rounded-md transition disabled:opacity-50"
                            style={{ color: '#fff', background: p.ACCENT }}>
                            {salvando ? '...' : 'Salvar'}
                        </button>
                        <button type="button" onClick={cancelar}
                            className="shrink-0 text-xs px-2 py-1 rounded-md" style={{ color: p.MUTED }}>
                            Cancelar
                        </button>
                    </>
                ) : (
                    <button type="button" onClick={() => setEditando(true)} title="Editar o nome"
                        className="shrink-0 p-1.5 rounded-lg transition" style={{ color: p.MUTED }}
                        onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                        <Icone path="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
                            className="w-4 h-4" />
                    </button>
                )}

                {/* Apagar fica escondido enquanto se edita: ali a pessoa está
                    consertando o nome, e o botão de destruir ao lado do de
                    salvar é convite a errar. */}
                {!editando && (
                    <button type="button" onClick={excluir} disabled={salvando} title="Apagar o fornecedor"
                        className="shrink-0 p-1.5 rounded-lg transition disabled:opacity-40" style={{ color: p.RED }}
                        onMouseEnter={e => (e.currentTarget.style.background = p.RED + '1a')}
                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                        <Icone path="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                            className="w-4 h-4" />
                    </button>
                )}
            </div>

            {erro && <p className="text-xs mt-1.5" style={{ color: p.RED }}>{erro}</p>}
        </div>
    );
}

export default function Fornecedores({ totalNotas, totalCampanha }: Props) {
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;

    const [lista, setLista] = useState<Lista>('notas');
    const [busca, setBusca] = useState('');
    const [itens, setItens] = useState<Item[]>([]);
    const [truncada, setTruncada] = useState(false);
    const [carregando, setCarregando] = useState(false);

    /*
     * A busca é do servidor, e sai com atraso.
     *
     * São ~2.800 nomes: mandar todos para a tela repetiria o erro que custava
     * 136 KB por ação na fila de notas. E sem o atraso, cada tecla viraria um
     * pedido — oito pedidos para escrever "VILMA AL".
     */
    const relogio = useRef<number | null>(null);

    const buscar = useCallback(async (q: string, tipo: Lista) => {
        setCarregando(true);
        try {
            const { data } = await window.axios.get(route('configuracoes.fornecedores.buscar'), {
                params: { q, tipo },
            });
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
        relogio.current = window.setTimeout(() => buscar(busca, lista), 300);

        return () => { if (relogio.current !== null) clearTimeout(relogio.current); };
    }, [busca, lista, buscar]);

    const total = lista === 'notas' ? totalNotas : totalCampanha;

    return (
        <Secoes atual="fornecedores">
            <Head title="Configurações — Fornecedores" />

            <div className="space-y-4">
                <Cartao
                    titulo="Nomes de fornecedor"
                    descricao="Corrige erro de digitação e nome fora do padrão. A nota aponta para o cadastro, não para o texto — então acertar aqui acerta o histórico inteiro."
                    p={p}
                >
                    {/* Duas listas, e elas não se misturam. O aviso da campanha
                        aparece só quando ela está selecionada, para não virar
                        ruído permanente. */}
                    <div className="flex gap-1.5 mb-3">
                        {([
                            { id: 'notas' as const, rotulo: 'Das notas', qtd: totalNotas },
                            { id: 'campanha' as const, rotulo: 'Da campanha', qtd: totalCampanha },
                        ]).map(op => {
                            const ativa = lista === op.id;

                            return (
                                <button key={op.id} type="button" onClick={() => { setLista(op.id); setItens([]); }}
                                    className="text-sm px-3 py-1.5 rounded-lg transition"
                                    style={{
                                        background: ativa ? p.ACCENT + '1a' : 'transparent',
                                        color: ativa ? p.ACCENT : p.MUTED,
                                        border: `1px solid ${ativa ? p.ACCENT + '55' : p.BORDER}`,
                                    }}>
                                    {op.rotulo} <span className="opacity-70">({op.qtd})</span>
                                </button>
                            );
                        })}
                    </div>

                    {lista === 'campanha' && (
                        <p className="text-xs rounded-lg px-3 py-2 mb-3"
                            style={{ background: p.AMBER + '14', color: p.AMBER, border: `1px solid ${p.AMBER}33` }}>
                            A planilha de compras <strong>troca esta lista inteira</strong> a cada envio. O que você
                            corrigir aqui vale até o próximo upload — serve para acertar um nome agora, não para manter.
                        </p>
                    )}

                    <input
                        value={busca}
                        onChange={e => setBusca(e.target.value)}
                        placeholder="Buscar pelo nome..."
                        className="block w-full rounded-lg text-sm px-3 py-2 outline-none mb-3"
                        style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }}
                    />

                    <div className="rounded-lg overflow-hidden" style={{ border: `1px solid ${p.BORDER}` }}>
                        {carregando && itens.length === 0 ? (
                            <p className="px-3 py-6 text-center text-sm" style={{ color: p.MUTED }}>Buscando...</p>
                        ) : itens.length === 0 ? (
                            <p className="px-3 py-6 text-center text-sm" style={{ color: p.MUTED }}>
                                {busca.trim() === ''
                                    ? `Digite para buscar entre os ${total} fornecedores.`
                                    : 'Nenhum fornecedor com esse nome.'}
                            </p>
                        ) : (
                            itens.map(item => (
                                <Linha key={item.id} item={item} lista={lista} p={p}
                                    onSalvo={novo => setItens(l => l.map(x => x.id === novo.id ? novo : x))}
                                    onExcluido={id => setItens(l => l.filter(x => x.id !== id))} />
                            ))
                        )}
                    </div>

                    {truncada && (
                        <p className="text-xs mt-2" style={{ color: p.MUTED }}>
                            Mostrando os primeiros resultados. Escreva mais do nome para estreitar a busca.
                        </p>
                    )}
                </Cartao>
            </div>
        </Secoes>
    );
}
