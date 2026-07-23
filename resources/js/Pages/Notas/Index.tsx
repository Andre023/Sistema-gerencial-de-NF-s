import React, { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { format, parseISO, addDays, subDays } from 'date-fns';
import { Nota, Card, Fornecedor, FiltrosAtivos, OpcoesSistema, Nivel, ResumoAlertas, Permissoes, TipoCard } from '@/types';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT, Palette, lojaNome, hoje, nivelCor, NIVEL_LABEL, idadeTexto, TIPO_CARD_LABEL, STATUS_NOTA_LABEL } from '@/lib/tema';
import Icone from '@/Components/painel/Icone';
import Modal from '@/Components/painel/Modal';
import THead from '@/Components/painel/THead';
import CampoFornecedor from '@/Components/painel/CampoFornecedor';
import CardBadge from '@/Components/painel/CardBadge';
import ModalComentarios from '@/Components/painel/ModalComentarios';

interface Props {
    recebimento: Nota[];
    preLote: Nota[];
    liberadas: Nota[];
    fornecedores: Fornecedor[];
    dataFiltro: string;
    resumoAlertas: ResumoAlertas;
    totalReconferir: number;
    filtros: FiltrosAtivos;
    opcoes: OpcoesSistema;
}

// ─── Formulário de nota ─────────────────────────────────────────────────────────

interface DadosForm {
    numero_nota: string; fornecedor_id: number | '';
    fornecedor: { id: number | ''; nome: string };
    fornecedor_novo: boolean; fornecedor_nome: string;
    loja: number | ''; origem: string; observacao: string;
}

function FormNota({ fornecedores, opcoes, inicial, origemDefault, onSubmit, onCancelar, carregando, erros, labelSubmit, p }: {
    fornecedores: Fornecedor[]; opcoes: OpcoesSistema; inicial?: Nota; origemDefault: string;
    onSubmit: (d: Omit<DadosForm, 'fornecedor'>) => void; onCancelar: () => void;
    carregando: boolean; erros: Record<string, string>; labelSubmit: string; p: Palette;
}) {
    const [form, setForm] = useState<DadosForm>({
        numero_nota: inicial?.numero_nota ?? '', fornecedor_id: inicial?.fornecedor?.id ?? '',
        fornecedor: { id: inicial?.fornecedor?.id ?? '', nome: inicial?.fornecedor?.nome ?? '' },
        fornecedor_novo: false, fornecedor_nome: '', // checkbox sempre começa desmarcado
        loja: inicial?.loja ?? '', origem: inicial?.origem ?? origemDefault, observacao: inicial?.observacao ?? '',
    });

    const set = <K extends keyof DadosForm>(k: K, v: DadosForm[K]) => setForm(prev => ({ ...prev, [k]: v }));

    const inputStyle = (hasErr?: boolean) => ({
        background: p.INPUT_BG, color: p.TEXT,
        border: `1px solid ${hasErr ? p.RED : p.INPUT_BORDER}`,
    });

    const campo = (label: string, obrigatorio: boolean, children: React.ReactNode, erro?: string) => (
        <div>
            <label className="block text-sm font-medium mb-1.5" style={{ color: p.MUTED }}>
                {label}{obrigatorio && <span style={{ color: p.RED }} className="ml-0.5">*</span>}
            </label>
            {children}
            {erro && <p className="text-xs mt-1" style={{ color: p.RED }}>{erro}</p>}
        </div>
    );

    return (
        <form onSubmit={e => { e.preventDefault(); onSubmit({
                numero_nota: form.numero_nota,
                fornecedor_id: form.fornecedor_novo ? '' : form.fornecedor.id,
                fornecedor_novo: form.fornecedor_novo,
                fornecedor_nome: form.fornecedor_novo ? form.fornecedor_nome : '',
                loja: form.loja, origem: form.origem, observacao: form.observacao,
            }); }}
            className="space-y-4">
            {campo('Número da nota', true,
                <input type="text" value={form.numero_nota} onChange={e => set('numero_nota', e.target.value)}
                    placeholder="Ex: 46252"
                    className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                    style={inputStyle(!!erros.numero_nota)} />, erros.numero_nota
            )}
            <div>
                <label className="block text-sm font-medium mb-1.5" style={{ color: p.MUTED }}>
                    Fornecedor<span style={{ color: p.RED }} className="ml-0.5">*</span>
                </label>
                {form.fornecedor_novo ? (
                    <>
                        <input type="text" value={form.fornecedor_nome} autoComplete="off"
                            onChange={e => set('fornecedor_nome', e.target.value)}
                            placeholder="Nome do novo fornecedor"
                            className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                            style={inputStyle(!!erros.fornecedor_nome)} />
                        {erros.fornecedor_nome && <p className="text-xs mt-1" style={{ color: p.RED }}>{erros.fornecedor_nome}</p>}
                    </>
                ) : (
                    <CampoFornecedor fornecedores={fornecedores} valor={form.fornecedor}
                        onChange={v => setForm(prev => ({ ...prev, fornecedor: v, fornecedor_id: v.id }))}
                        erro={erros.fornecedor_id} p={p} />
                )}
                <label className="flex items-center gap-2 mt-2 cursor-pointer select-none">
                    <input type="checkbox" checked={form.fornecedor_novo}
                        onChange={e => set('fornecedor_novo', e.target.checked)}
                        style={{ accentColor: p.ACCENT }} />
                    <span className="text-sm" style={{ color: p.MUTED }}>Fornecedor novo — cadastra ao lançar</span>
                </label>
            </div>
            <div className="grid grid-cols-2 gap-3">
                {campo('Loja', true,
                    <select value={form.loja} onChange={e => set('loja', Number(e.target.value) || '')}
                        className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                        style={inputStyle(!!erros.loja)}>
                        <option value="">Selecionar...</option>
                        {opcoes.lojas.map(l => <option key={l} value={l}>{lojaNome(l)}</option>)}
                    </select>, erros.loja
                )}
                {campo('Fila', true,
                    <select value={form.origem} onChange={e => set('origem', e.target.value)}
                        className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                        style={inputStyle(!!erros.origem)}>
                        <option value="recebimento">Recebimento (caminhão na porta)</option>
                        <option value="pre_lote">Pré-lote (antecipada)</option>
                    </select>, erros.origem
                )}
            </div>
            {campo('Observação', false,
                <textarea value={form.observacao} onChange={e => set('observacao', e.target.value)}
                    rows={3} placeholder="Detalhes adicionais..."
                    className="block w-full rounded-lg text-sm px-3 py-2 outline-none resize-none"
                    style={inputStyle()} />
            )}
            <div className="flex justify-end gap-3 pt-3 mt-1" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                <button type="button" onClick={onCancelar} className="px-4 py-2 text-sm" style={{ color: p.MUTED }}>
                    Cancelar
                </button>
                <button type="submit" disabled={carregando}
                    className="px-5 py-2 text-sm font-medium text-white rounded-lg transition disabled:opacity-50"
                    style={{ background: p.ACCENT }}>
                    {carregando ? 'Salvando...' : labelSubmit}
                </button>
            </div>
        </form>
    );
}

// ─── Modal de cards (detalhe da nota) ───────────────────────────────────────────

function ModalCards({ nota, onFechar, can, tiposCompras, isDark, p }: {
    nota: Nota | null; onFechar: () => void; can: Permissoes; tiposCompras: TipoCard[]; isDark: boolean; p: Palette;
}) {
    // Compras só corrige os tipos dela (regra é do pré-lote); admin corrige tudo
    const ehCompras = usePage().props.auth.user.role === 'compras';
    const podeCorrigirEste = (c: Card) =>
        can.corrigirCard && (!ehCompras || tiposCompras.includes(c.tipo));

    const [tipoNovo, setTipoNovo] = useState<TipoCard | ''>('');
    const [detalheNovo, setDetalheNovo] = useState('');
    const [erro, setErro] = useState<string | null>(null);
    const [ocupado, setOcupado] = useState(false);

    useEffect(() => { setTipoNovo(''); setDetalheNovo(''); setErro(null); }, [nota?.id]);

    if (!nota) return null;

    const liberada = nota.status === 'liberada';
    const ativos = nota.cards.filter(c => c.status !== 'resolvido');
    const podeLiberar = !liberada && ativos.length === 0;

    const agir = (fn: () => void) => { setErro(null); setOcupado(true); fn(); };
    const opts = {
        onError: (e: Record<string, string>) => setErro(Object.values(e)[0] ?? 'Não foi possível concluir.'),
        onFinish: () => setOcupado(false),
        preserveScroll: true,
    };

    const abrirCard = (e: React.FormEvent) => {
        e.preventDefault();
        if (!tipoNovo) return;
        agir(() => router.post(route('notas.cards.store', nota.id), { tipo: tipoNovo, detalhe: detalheNovo || undefined } as any, {
            ...opts, onSuccess: () => { setTipoNovo(''); setDetalheNovo(''); },
        }));
    };

    const corrigir = (c: Card) => agir(() => router.patch(route('notas.cards.corrigir', [nota.id, c.id]), {}, opts));
    const resolver = (c: Card) => agir(() => router.patch(route('notas.cards.resolver', [nota.id, c.id]), {}, opts));
    const reabrir  = (c: Card) => agir(() => router.patch(route('notas.cards.reabrir', [nota.id, c.id]), {}, opts));
    const excluirCard = (c: Card) => {
        if (!confirm(`Excluir o card de ${TIPO_CARD_LABEL[c.tipo]}?`)) return;
        agir(() => router.delete(route('notas.cards.destroy', [nota.id, c.id]), opts));
    };
    const liberar = () => {
        if (!confirm(`Liberar a nota ${nota.numero_nota}?`)) return;
        agir(() => router.post(route('notas.liberar', nota.id), {}, { ...opts, onSuccess: () => onFechar() }));
    };

    const statusCor = nota.status === 'com_divergencia' ? p.RED
        : nota.status === 'reconferir' ? p.AMBER
        : nota.status === 'liberada' ? p.GREEN : p.MUTED;

    const btn = (label: string, cor: string, onClick: () => void) => (
        <button onClick={onClick} disabled={ocupado}
            className="px-2.5 py-1 text-xs font-medium rounded-md transition disabled:opacity-40"
            style={{ background: cor + '1a', color: cor, border: `1px solid ${cor}44` }}>
            {label}
        </button>
    );

    return (
        <Modal aberto={!!nota} onFechar={onFechar} titulo={`Nota ${nota.numero_nota} — ${nota.fornecedor.nome}`} p={p}>
            <div className="space-y-4">

                <div className="flex items-center gap-2 text-sm">
                    <span className="font-medium px-2 py-0.5 rounded" style={{ background: statusCor + '1a', color: statusCor, border: `1px solid ${statusCor}44` }}>
                        {STATUS_NOTA_LABEL[nota.status]}
                    </span>
                    <span style={{ color: p.MUTED }}>{lojaNome(nota.loja)} · lançada por {nota.user.name.split(' ')[0]} em {nota.data_origem}</span>
                </div>

                {/* ── Cards ── */}
                <div className="space-y-2">
                    {nota.cards.length === 0 && (
                        <p className="text-sm py-2" style={{ color: p.MUTED }}>
                            Nenhuma divergência registrada{liberada ? '' : ' — nota aguardando análise do pré-lote'}.
                        </p>
                    )}
                    {nota.cards.map(c => (
                        <div key={c.id} className="flex items-center gap-2 rounded-lg px-3 py-2"
                            style={{ border: `1px solid ${p.BORDER}`, background: p.SURFACE }}>
                            <CardBadge card={c} isDark={isDark} />
                            <span className="text-xs truncate flex-1" style={{ color: p.MUTED }} title={c.detalhe ?? ''}>
                                {c.detalhe || ''}
                                {c.reaberturas > 0 && <em> · reaberto {c.reaberturas}x</em>}
                            </span>
                            <div className="flex items-center gap-1.5 shrink-0">
                                {c.status === 'aberto' && podeCorrigirEste(c) && btn('Corrigido ✓', p.GREEN, () => corrigir(c))}
                                {c.status === 'aberto' && can.gerirCards && btn('Resolver', p.GREEN, () => resolver(c))}
                                {c.status === 'aberto' && can.gerirCards && btn('Excluir', p.RED, () => excluirCard(c))}
                                {c.status === 'resolvido' && can.gerirCards && btn('Reabrir', p.RED, () => reabrir(c))}
                            </div>
                        </div>
                    ))}
                </div>

                {/* ── Abrir novo card (pré-lote) ── */}
                {!liberada && can.gerirCards && (
                    <form onSubmit={abrirCard} className="flex items-center gap-2 pt-3" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                        <select value={tipoNovo} onChange={e => setTipoNovo(e.target.value as TipoCard)}
                            className="rounded-lg text-sm px-2.5 py-1.5 outline-none"
                            style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }}>
                            <option value="">Divergência...</option>
                            {opcoesTipos(nota).map(t => <option key={t} value={t}>{TIPO_CARD_LABEL[t]}</option>)}
                        </select>
                        <input type="text" value={detalheNovo} onChange={e => setDetalheNovo(e.target.value)}
                            placeholder="Detalhe (opcional)" maxLength={500}
                            className="flex-1 rounded-lg text-sm px-3 py-1.5 outline-none"
                            style={{ background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` }} />
                        <button type="submit" disabled={!tipoNovo || ocupado}
                            className="px-3 py-1.5 text-sm font-medium text-white rounded-lg disabled:opacity-40"
                            style={{ background: p.ACCENT }}>
                            Abrir card
                        </button>
                    </form>
                )}

                {erro && <p className="text-xs" style={{ color: p.RED }}>{erro}</p>}

                {/* ── Liberar ── */}
                {can.liberarNota && !liberada && (
                    <div className="flex justify-end pt-3" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                        <button onClick={liberar} disabled={!podeLiberar || ocupado}
                            title={podeLiberar ? 'Liberar a nota para o recebimento' : 'Resolva os cards em aberto antes de liberar'}
                            className="px-4 py-2 text-sm font-medium text-white rounded-lg transition disabled:opacity-40"
                            style={{ background: p.GREEN }}>
                            ✓ Liberar nota
                        </button>
                    </div>
                )}
            </div>
        </Modal>
    );
}

/** Tipos que ainda não têm card ativo nesta nota. */
function opcoesTipos(nota: Nota): TipoCard[] {
    const ativos = nota.cards.filter(c => c.status !== 'resolvido').map(c => c.tipo);
    return (['cadastro', 'regra', 'custo', 'quantidade'] as TipoCard[]).filter(t => !ativos.includes(t));
}

// ─── Linha da fila ──────────────────────────────────────────────────────────────

function LinhaFila({ nota, onCards, onComentar, onEditar, onExcluir, onLiberar, can, isDark, p }: {
    nota: Nota; onCards: (n: Nota) => void; onComentar: (n: Nota) => void;
    onEditar: (n: Nota) => void; onExcluir: (n: Nota) => void; onLiberar: (n: Nota) => void;
    can: Permissoes; isDark: boolean; p: Palette;
}) {
    const cor = nivelCor(nota.nivel, p);
    const rowBg = nota.nivel === 'normal' ? 'transparent' : cor + (nota.nivel === 'critico' ? '1f' : '12');
    const ativos = nota.cards.filter(c => c.status !== 'resolvido');

    return (
        <tr className="group transition-colors"
            style={{ borderBottom: `1px solid ${p.BORDER}`, background: rowBg }}
            onMouseEnter={e => nota.nivel === 'normal' && (e.currentTarget.style.background = p.HOVER_ROW)}
            onMouseLeave={e => nota.nivel === 'normal' && (e.currentTarget.style.background = rowBg)}>
            <td className="px-4 py-3 text-sm">
                <div className="flex items-center gap-2">
                    <span className="font-medium" style={{ color: p.TEXT }}>{nota.numero_nota}</span>
                    {nota.nivel !== 'normal' && (
                        <span className="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-semibold whitespace-nowrap"
                            style={{ background: cor + '22', color: cor, border: `1px solid ${cor}44` }}
                            title={`Aberta desde ${nota.data_origem}`}>
                            {idadeTexto(nota.dias_aberta)}
                        </span>
                    )}
                </div>
            </td>
            <td className="px-4 py-3 text-sm max-w-[180px] truncate" style={{ color: p.TEXT }}>{nota.fornecedor.nome}</td>
            <td className="px-4 py-3">
                <button onClick={() => onCards(nota)} className="flex flex-wrap items-center gap-1" title="Abrir cards da nota">
                    {ativos.length > 0
                        ? ativos.map(c => <CardBadge key={c.id} card={c} isDark={isDark} />)
                        : nota.status === 'reconferir'
                            ? <span className="inline-flex items-center gap-1 rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap"
                                style={{ background: p.AMBER + '22', color: p.AMBER, border: `1px solid ${p.AMBER}44` }}>
                                Reconferir
                              </span>
                            : <span className="text-xs" style={{ color: p.MUTED }}>aguardando análise</span>}
                </button>
            </td>
            <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>{lojaNome(nota.loja)}</td>
            <td className="px-4 py-3 text-sm max-w-[180px] truncate" style={{ color: p.TEXT }} title={nota.observacao ?? ''}>
                {nota.observacao || <span style={{ color: p.MUTED }}>—</span>}
            </td>
            <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>{nota.user.name.split(' ')[0]}</td>
            <td className="px-4 py-3 text-right">
                <div className="flex items-center justify-end gap-0.5">
                    <button onClick={() => onComentar(nota)} title="Comentários"
                        className={`flex items-center gap-1 p-1.5 rounded-lg transition ${nota.comentarios_count > 0 ? '' : 'opacity-0 group-hover:opacity-100'}`}
                        style={{ color: nota.comentarios_count > 0 ? p.ACCENT : p.MUTED }}
                        onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                        <Icone path="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.8 9.8 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        {nota.comentarios_count > 0 && <span className="text-xs font-medium">{nota.comentarios_count}</span>}
                    </button>

                    <div className="flex items-center gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                        <button onClick={() => onCards(nota)} title="Cards / divergências"
                            className="p-1.5 rounded-lg transition" style={{ color: p.AMBER }}
                            onMouseEnter={e => (e.currentTarget.style.background = p.AMBER + '1a')}
                            onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                            <Icone path="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                        </button>
                        {can.liberarNota && nota.status === 'pendente' && (
                            <button onClick={() => onLiberar(nota)} title="Liberar nota"
                                className="p-1.5 rounded-lg transition" style={{ color: p.GREEN }}
                                onMouseEnter={e => (e.currentTarget.style.background = p.GREEN + '1a')}
                                onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                <Icone path="M5 13l4 4L19 7" />
                            </button>
                        )}
                        {can.gerenciarNotas && (
                            <>
                                <button onClick={() => onEditar(nota)} title="Editar"
                                    className="p-1.5 rounded-lg transition" style={{ color: p.ACCENT }}
                                    onMouseEnter={e => (e.currentTarget.style.background = p.ACCENT + '1a')}
                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                    <Icone path="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </button>
                                <button onClick={() => onExcluir(nota)} title="Excluir"
                                    className="p-1.5 rounded-lg transition" style={{ color: p.RED }}
                                    onMouseEnter={e => (e.currentTarget.style.background = p.RED + '1a')}
                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                    <Icone path="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </button>
                            </>
                        )}
                    </div>
                </div>
            </td>
        </tr>
    );
}

// ─── Página ─────────────────────────────────────────────────────────────────────

export default function Index({ recebimento, preLote, liberadas, fornecedores, dataFiltro, resumoAlertas, totalReconferir, filtros, opcoes }: Props) {
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;
    const { can, user } = usePage().props.auth;

    useEffect(() => {
        window.Echo.private('notas').listen('.NotaAtualizada', () => {
            router.reload({ only: ['recebimento', 'preLote', 'liberadas', 'resumoAlertas', 'totalReconferir'] });
            setEchoTick(t => t + 1);
        });
        return () => { window.Echo.leave('notas'); };
    }, []);

    const [modalNova, setModalNova] = useState(false);
    const [modalEditar, setModalEditar] = useState<Nota | null>(null);
    const [cardsId, setCardsId] = useState<number | null>(null);
    const [comentariosNota, setComentariosNota] = useState<Nota | null>(null);
    const [echoTick, setEchoTick] = useState(0);
    const [erros, setErros] = useState<Record<string, string>>({});
    const [submetendo, setSubmetendo] = useState(false);
    const [buscaLocal, setBuscaLocal] = useState(filtros.busca ?? '');
    const [lojaLocal, setLojaLocal] = useState(filtros.loja ? String(filtros.loja) : '');

    const isHoje = dataFiltro === hoje();
    const todas = [...recebimento, ...preLote, ...liberadas];
    // O modal de cards deriva das props a cada render — reflete o realtime na hora
    const notaCards = cardsId ? todas.find(n => n.id === cardsId) ?? null : null;

    const paramsAtuais = () => ({
        data: dataFiltro,
        busca: buscaLocal || undefined,
        loja: lojaLocal || undefined,
        nivel: filtros.nivel || undefined,
        status: filtros.status || undefined,
    });

    const irPara = (extras: Record<string, unknown> = {}) =>
        router.get(route('notas.index'), { ...paramsAtuais(), ...extras }, { preserveState: true, replace: true });

    const mudarData = (d: string) => irPara({ data: d });
    const filtrarNivel = (n: Nivel | null) => irPara({ nivel: n ?? undefined });
    const filtrarStatus = (s: string | null) => irPara({ status: s ?? undefined });
    const diaAnterior = () => mudarData(format(subDays(parseISO(dataFiltro), 1), 'yyyy-MM-dd'));
    const diaSeguinte = () => mudarData(format(addDays(parseISO(dataFiltro), 1), 'yyyy-MM-dd'));
    const aplicarFiltros = () => irPara();
    const limparFiltros = () => {
        setBuscaLocal(''); setLojaLocal('');
        router.get(route('notas.index'), { data: dataFiltro }, { preserveState: true, replace: true });
    };
    const filtrosAtivos = !!(filtros.busca || filtros.loja || filtros.nivel || filtros.status);

    const criar = (dados: any) => {
        setSubmetendo(true);
        router.post(route('notas.store'), dados, {
            onSuccess: () => { setModalNova(false); setErros({}); },
            onError: e => setErros(e),
            onFinish: () => setSubmetendo(false),
        });
    };

    const salvarEdicao = (dados: any) => {
        if (!modalEditar) return;
        setSubmetendo(true);
        router.patch(route('notas.update', modalEditar.id), dados, {
            onSuccess: () => { setModalEditar(null); setErros({}); },
            onError: e => setErros(e),
            onFinish: () => setSubmetendo(false),
        });
    };

    const liberarRapido = (n: Nota) => {
        if (!confirm(`Liberar a nota ${n.numero_nota} (${n.fornecedor.nome})?`)) return;
        router.post(route('notas.liberar', n.id), {}, { preserveScroll: true });
    };

    const excluir = (n: Nota) => {
        if (!confirm(`Excluir a nota ${n.numero_nota}? Esta ação pode ser revertida pelo administrador.`)) return;
        router.delete(route('notas.destroy', n.id));
    };

    const sla = opcoes.sla ?? { atencao: 1, alerta: 3, critico: 7 };
    const faixaTexto: Record<Exclude<Nivel, 'normal'>, string> = {
        critico: `${sla.critico}+ dias`,
        alerta: `${sla.alerta}–${sla.critico - 1} dias`,
        atencao: `${sla.atencao}–${sla.alerta - 1} dias`,
    };
    const temAlertas = resumoAlertas.critico + resumoAlertas.alerta + resumoAlertas.atencao > 0;
    const temFiltros = temAlertas || totalReconferir > 0;
    const filtrandoReconferir = filtros.status === 'reconferir';

    const COLS_FILA = ['Nota', 'Fornecedor', 'Divergências', 'Loja', 'Observação', 'Lançado', ''];
    const COLS_LIBERADAS = ['Nota', 'Fornecedor', 'Divergências', 'Loja', 'Liberada por', ''];

    const inputCtrl = { background: p.INPUT_BG, color: p.TEXT, border: `1px solid ${p.INPUT_BORDER}` };

    const secaoFila = (titulo: string, subtitulo: string, notas: Nota[], corBadge: string) => (
        <div className="rounded-xl overflow-hidden" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
            <div className="flex items-center justify-between px-5 py-3.5" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                <h2 className="text-sm font-semibold flex items-center gap-2" style={{ color: p.TEXT }}>
                    {titulo}
                    <span className="text-xs font-normal" style={{ color: p.MUTED }}>{subtitulo}</span>
                    <span className="text-xs font-medium px-2 py-0.5 rounded-full"
                        style={{ background: corBadge + '22', color: corBadge, border: `1px solid ${corBadge}33` }}>
                        {notas.length}
                    </span>
                </h2>
            </div>
            <div className="overflow-x-auto">
                <table className="min-w-full">
                    <THead colunas={COLS_FILA} p={p} />
                    <tbody>
                        {notas.length === 0 ? (
                            <tr><td colSpan={7} className="px-4 py-8 text-center text-sm" style={{ color: p.MUTED }}>
                                Nenhuma nota nesta fila.
                            </td></tr>
                        ) : notas.map(n => (
                            <LinhaFila key={n.id} nota={n} can={can} isDark={isDark} p={p}
                                onCards={x => setCardsId(x.id)} onComentar={setComentariosNota}
                                onEditar={setModalEditar} onExcluir={excluir} onLiberar={liberarRapido} />
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );

    return (
        <AuthenticatedLayout header={null}>
            <Head title="Notas" />

            <Modal aberto={modalNova} onFechar={() => setModalNova(false)} titulo="Lançar nota" p={p}>
                <FormNota fornecedores={fornecedores} opcoes={opcoes} onSubmit={criar}
                    origemDefault={user.role === 'pre_lote' ? 'pre_lote' : 'recebimento'}
                    onCancelar={() => setModalNova(false)} carregando={submetendo} erros={erros}
                    labelSubmit="Lançar nota" p={p} />
            </Modal>

            <Modal aberto={!!modalEditar} onFechar={() => setModalEditar(null)} titulo="Editar nota" p={p}>
                {modalEditar && (
                    <FormNota fornecedores={fornecedores} opcoes={opcoes} inicial={modalEditar}
                        origemDefault={modalEditar.origem}
                        onSubmit={salvarEdicao} onCancelar={() => setModalEditar(null)}
                        carregando={submetendo} erros={erros} labelSubmit="Salvar alterações" p={p} />
                )}
            </Modal>

            <ModalCards nota={notaCards} onFechar={() => setCardsId(null)} can={can}
                tiposCompras={opcoes.tiposCompras ?? ['cadastro', 'custo', 'quantidade']} isDark={isDark} p={p} />

            <ModalComentarios
                aberto={!!comentariosNota}
                onFechar={() => setComentariosNota(null)}
                baseUrl={comentariosNota ? `/notas/${comentariosNota.id}/comentarios` : null}
                titulo={comentariosNota ? `Nota ${comentariosNota.numero_nota} — ${comentariosNota.fornecedor.nome}` : ''}
                onMudou={() => router.reload({ only: ['recebimento', 'preLote', 'liberadas'] })}
                recarregarToken={echoTick}
                p={p} />

            <div className="min-h-screen py-6 px-4 sm:px-6 lg:px-8 max-w-screen-2xl mx-auto space-y-4 transition-colors duration-200"
                style={{ background: p.BG }}>

                {/* ── Barra de controles ─────────────────────────────────────── */}
                <div className="flex flex-wrap items-center gap-2.5">
                    <div className="flex items-center gap-1 rounded-lg px-2 py-1.5"
                        style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                        <button onClick={diaAnterior} className="p-1 rounded transition" style={{ color: p.MUTED }} title="Dia anterior">
                            <Icone path="M15 19l-7-7 7-7" />
                        </button>
                        <input type="date" value={dataFiltro} onChange={e => mudarData(e.target.value)}
                            className="border-none text-sm font-medium focus:ring-0 p-0 bg-transparent cursor-pointer"
                            style={{ color: p.TEXT }} />
                        <button onClick={diaSeguinte} disabled={isHoje}
                            className="p-1 rounded transition disabled:opacity-30" style={{ color: p.MUTED }} title="Próximo dia">
                            <Icone path="M9 5l7 7-7 7" />
                        </button>
                    </div>

                    {isHoje && (
                        <span className="text-xs font-medium px-2.5 py-1 rounded-md"
                            style={{ background: p.ACCENT + '22', color: p.ACCENT, border: `1px solid ${p.ACCENT}44` }}>
                            Hoje
                        </span>
                    )}

                    <div className="relative">
                        <input type="search" placeholder="Buscar nota ou fornecedor..."
                            value={buscaLocal} onChange={e => setBuscaLocal(e.target.value)}
                            onKeyDown={e => e.key === 'Enter' && aplicarFiltros()}
                            className="rounded-lg text-sm pl-8 pr-3 py-2 outline-none w-56" style={inputCtrl} />
                        <span className="absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: p.MUTED }}>
                            <Icone path="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </span>
                    </div>

                    <select value={lojaLocal} onChange={e => setLojaLocal(e.target.value)}
                        className="rounded-lg text-sm px-3 py-2 outline-none" style={inputCtrl}>
                        <option value="">Todas as lojas</option>
                        {opcoes.lojas.map(l => <option key={l} value={l}>{lojaNome(l)}</option>)}
                    </select>

                    <button onClick={aplicarFiltros}
                        className="px-3.5 py-2 text-sm font-medium rounded-lg transition"
                        style={{ background: p.SURFACE, color: p.TEXT, border: `1px solid ${p.BORDER}` }}>
                        Filtrar
                    </button>

                    {filtrosAtivos && (
                        <button onClick={limparFiltros} className="text-xs flex items-center gap-1" style={{ color: p.MUTED }}>
                            <Icone path="M6 18L18 6M6 6l12 12" className="w-3 h-3" /> Limpar
                        </button>
                    )}

                    {can.lancarNota && (
                        <button onClick={() => setModalNova(true)}
                            className="ml-auto flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-white rounded-lg transition"
                            style={{ background: p.ACCENT }}
                            onMouseEnter={e => (e.currentTarget.style.filter = 'brightness(1.1)')}
                            onMouseLeave={e => (e.currentTarget.style.filter = 'none')}>
                            <Icone path="M12 4v16m8-8H4" /> Lançar nota
                        </button>
                    )}
                </div>

                {/* ── Chips de filtro: envelhecimento + prontas p/ liberar ────── */}
                {temFiltros && (
                    <div className="flex flex-wrap items-center gap-2">
                        {(['critico', 'alerta', 'atencao'] as const).map(n => {
                            const qtd = resumoAlertas[n];
                            if (!qtd) return null;
                            const cor = nivelCor(n, p);
                            const ativo = filtros.nivel === n;
                            return (
                                <button key={n} onClick={() => filtrarNivel(ativo ? null : n)}
                                    title={ativo ? 'Remover filtro' : `Ver só as ${NIVEL_LABEL[n]}`}
                                    className="flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg transition"
                                    style={{
                                        background: ativo ? cor + '33' : cor + '14',
                                        border: `1px solid ${cor}${ativo ? 'aa' : '44'}`,
                                        color: cor,
                                    }}>
                                    <strong>{qtd}</strong>
                                    <span>{NIVEL_LABEL[n]}</span>
                                    <span className="text-xs" style={{ opacity: 0.75 }}>({faixaTexto[n]})</span>
                                </button>
                            );
                        })}

                        {/* Reconferir: tudo corrigido, esperando o pré-lote conferir e liberar */}
                        {totalReconferir > 0 && (
                            <button onClick={() => filtrarStatus(filtrandoReconferir ? null : 'reconferir')}
                                title={filtrandoReconferir ? 'Remover filtro' : 'Ver só as prontas p/ liberar'}
                                className="flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg transition"
                                style={{
                                    background: filtrandoReconferir ? p.AMBER + '33' : p.AMBER + '14',
                                    border: `1px solid ${p.AMBER}${filtrandoReconferir ? 'aa' : '44'}`,
                                    color: p.AMBER,
                                }}>
                                <strong>{totalReconferir}</strong>
                                <span>reconferir</span>
                                <span className="text-xs" style={{ opacity: 0.75 }}>(pronta p/ liberar)</span>
                            </button>
                        )}

                        {(filtros.nivel || filtros.status) && (
                            <button onClick={() => irPara({ nivel: undefined, status: undefined })}
                                className="text-xs flex items-center gap-1" style={{ color: p.MUTED }}>
                                <Icone path="M6 18L18 6M6 6l12 12" className="w-3 h-3" /> Ver todas
                            </button>
                        )}
                    </div>
                )}

                {/* ── Filas ───────────────────────────────────────────────────── */}
                {secaoFila('Recebimento', 'caminhão na porta — prioridade', recebimento, p.RED)}
                {secaoFila('Pré-lote', 'notas antecipadas', preLote, p.ACCENT)}

                {/* ── Liberadas ───────────────────────────────────────────────── */}
                <div className="rounded-xl overflow-hidden" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                    <div className="flex items-center justify-between px-5 py-3.5" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                        <h2 className="text-sm font-semibold flex items-center gap-2" style={{ color: p.MUTED }}>
                            Liberadas neste dia
                            <span className="text-xs font-medium px-2 py-0.5 rounded-full"
                                style={{ background: p.GREEN + '22', color: p.GREEN, border: `1px solid ${p.GREEN}33` }}>
                                {liberadas.length}
                            </span>
                        </h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full">
                            <THead colunas={COLS_LIBERADAS} p={p} />
                            <tbody>
                                {liberadas.length === 0 ? (
                                    <tr><td colSpan={6} className="px-4 py-8 text-center text-sm" style={{ color: p.MUTED }}>
                                        Nenhuma nota liberada neste dia.
                                    </td></tr>
                                ) : liberadas.map(n => (
                                    <tr key={n.id} className="opacity-80 group" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                        <td className="px-4 py-3 text-sm line-through" style={{ color: p.TEXT }}>{n.numero_nota}</td>
                                        <td className="px-4 py-3 text-sm max-w-[180px] truncate" style={{ color: p.TEXT }}>{n.fornecedor.nome}</td>
                                        <td className="px-4 py-3">
                                            <button onClick={() => setCardsId(n.id)} className="flex flex-wrap items-center gap-1" title="Ver histórico de cards">
                                                {n.cards.length === 0
                                                    ? <span className="text-xs" style={{ color: p.MUTED }}>sem divergência</span>
                                                    : n.cards.map(c => <CardBadge key={c.id} card={c} isDark={isDark} />)}
                                            </button>
                                        </td>
                                        <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>{lojaNome(n.loja)}</td>
                                        <td className="px-4 py-3 text-sm" style={{ color: p.TEXT }}>{n.liberada_por?.name.split(' ')[0] ?? '—'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <button onClick={() => setComentariosNota(n)} title="Comentários"
                                                className={`inline-flex items-center gap-1 p-1.5 rounded-lg transition ${n.comentarios_count > 0 ? '' : 'opacity-0 group-hover:opacity-100'}`}
                                                style={{ color: n.comentarios_count > 0 ? p.ACCENT : p.MUTED }}>
                                                <Icone path="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.8 9.8 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                                {n.comentarios_count > 0 && <span className="text-xs font-medium">{n.comentarios_count}</span>}
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </AuthenticatedLayout>
    );
}
