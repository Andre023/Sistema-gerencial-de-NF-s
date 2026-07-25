import React, { useState, useEffect, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { format, parseISO, addDays, subDays } from 'date-fns';
import { Nota, Card, Fornecedor, FiltrosAtivos, OpcoesSistema, Nivel, ResumoAlertas, ResumoTipos, Permissoes, TipoCard } from '@/types';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT, Palette, lojaNome, hoje, nivelCor, NIVEL_LABEL, idadeTexto, TIPO_CARD_LABEL, STATUS_NOTA_LABEL, CARD_COR_DARK, CARD_COR_LIGHT, ORIGEM_LABEL } from '@/lib/tema';
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
    resumoTipos: ResumoTipos;
    totalReconferir: number;
    filtros: FiltrosAtivos;
    opcoes: OpcoesSistema;
}

// ─── Formulário de nota ─────────────────────────────────────────────────────────

interface DadosForm {
    numero_nota: string; fornecedor_id: number | '';
    fornecedor: { id: number | ''; nome: string };
    fornecedor_novo: boolean; fornecedor_nome: string;
    loja: number | ''; origem: string; ceasa: boolean; observacao: string;
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
        loja: inicial?.loja ?? '', origem: inicial?.origem ?? origemDefault,
        ceasa: inicial?.ceasa ?? false, observacao: inicial?.observacao ?? '',
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
                loja: form.loja, origem: form.origem, ceasa: form.ceasa, observacao: form.observacao,
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
            <label className="flex items-center gap-2 cursor-pointer select-none">
                <input type="checkbox" checked={form.ceasa}
                    onChange={e => set('ceasa', e.target.checked)}
                    style={{ accentColor: p.ACCENT }} />
                <span className="text-sm" style={{ color: p.MUTED }}>
                    Nota de CEASA — o setor de compras também pode abrir cards
                </span>
            </label>
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

                {/* ── Abrir novo card (pré-lote; e compras quando a nota é de CEASA) ── */}
                {!liberada && (can.gerirCards || (nota.ceasa && ehCompras)) && (
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
    return (['cadastro', 'regra', 'custo', 'quantidade', 'sem_pedido'] as TipoCard[]).filter(t => !ativos.includes(t));
}

// ─── Linha da fila ──────────────────────────────────────────────────────────────

function LinhaFila({ nota, onCards, onComentar, onEditar, onExcluir, onLiberar, onVisualizar, usuarioId, can, isDark, p }: {
    nota: Nota; onCards: (n: Nota) => void; onComentar: (n: Nota) => void;
    onEditar: (n: Nota) => void; onExcluir: (n: Nota) => void; onLiberar: (n: Nota) => void;
    onVisualizar: (n: Nota) => void; usuarioId: number;
    can: Permissoes; isDark: boolean; p: Palette;
}) {
    const cor = nivelCor(nota.nivel, p);
    const rowBg = nota.nivel === 'normal' ? 'transparent' : cor + (nota.nivel === 'critico' ? '1f' : '12');
    const ativos = nota.cards.filter(c => c.status !== 'resolvido');

    // Reserva (🙋‍♂️): se ninguém pegou, só aparece no hover; reservada, fica fixa.
    const olhando = nota.visualizando_por;
    const reservaMinha = olhando?.id === usuarioId;
    const reservaCor = reservaMinha ? p.GREEN : olhando ? p.AMBER : p.MUTED;
    const reservaTitulo = reservaMinha
        ? 'Você está olhando esta nota — clique para liberar'
        : olhando
            ? `${olhando.name.split(' ')[0]} está olhando esta nota`
            : 'Avisar que você está olhando esta nota';

    return (
        <tr className="group transition-colors"
            style={{ borderBottom: `1px solid ${p.BORDER}`, background: rowBg }}
            onMouseEnter={e => nota.nivel === 'normal' && (e.currentTarget.style.background = p.HOVER_ROW)}
            onMouseLeave={e => nota.nivel === 'normal' && (e.currentTarget.style.background = rowBg)}>
            <td className="px-4 py-3 text-sm">
                <div className="flex items-center gap-2">
                    <span className="font-medium" style={{ color: p.TEXT }}>{nota.numero_nota}</span>
                    {nota.ceasa && (
                        <span className="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-bold tracking-wide"
                            style={{ background: p.PURPLE + '22', color: p.PURPLE, border: `1px solid ${p.PURPLE}44` }}
                            title="Nota de CEASA — compras pode abrir cards">
                            CEASA
                        </span>
                    )}
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
                    {/* 🙋‍♂️ Reserva: fixa quando alguém pegou, hover-only quando livre */}
                    <button onClick={() => onVisualizar(nota)} title={reservaTitulo}
                        className={`flex items-center p-1.5 rounded-lg transition ${olhando ? '' : 'opacity-0 group-hover:opacity-100'}`}
                        style={{ background: olhando ? reservaCor + '22' : 'transparent' }}
                        onMouseEnter={e => !olhando && (e.currentTarget.style.background = p.HOVER_ROW)}
                        onMouseLeave={e => !olhando && (e.currentTarget.style.background = 'transparent')}>
                        <span className="text-base leading-none"
                            style={{ filter: olhando ? 'none' : 'grayscale(0.7)', opacity: olhando ? 1 : 0.75 }}>
                            🙋‍♂️
                        </span>
                    </button>

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
                        {can.editarNotas && (
                            <button onClick={() => onEditar(nota)} title="Editar"
                                className="p-1.5 rounded-lg transition" style={{ color: p.ACCENT }}
                                onMouseEnter={e => (e.currentTarget.style.background = p.ACCENT + '1a')}
                                onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                <Icone path="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </button>
                        )}
                        {can.gerenciarNotas && (
                            <button onClick={() => onExcluir(nota)} title="Excluir"
                                className="p-1.5 rounded-lg transition" style={{ color: p.RED }}
                                onMouseEnter={e => (e.currentTarget.style.background = p.RED + '1a')}
                                onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                <Icone path="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </button>
                        )}
                    </div>
                </div>
            </td>
        </tr>
    );
}

// ─── Página ─────────────────────────────────────────────────────────────────────

export default function Index({ recebimento, preLote, liberadas, fornecedores, dataFiltro, resumoAlertas, resumoTipos, totalReconferir, filtros, opcoes }: Props) {
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;
    const { can, user } = usePage().props.auth;

    const [modalNova, setModalNova] = useState(false);
    const [modalEditar, setModalEditar] = useState<Nota | null>(null);
    const [cardsId, setCardsId] = useState<number | null>(null);
    const [comentariosNota, setComentariosNota] = useState<Nota | null>(null);
    const [echoTick, setEchoTick] = useState(0);
    const [erros, setErros] = useState<Record<string, string>>({});
    const [submetendo, setSubmetendo] = useState(false);
    const [buscaLocal, setBuscaLocal] = useState(filtros.busca ?? '');
    const [lojaLocal, setLojaLocal] = useState(filtros.loja ? String(filtros.loja) : '');

    // Listas em estado local — permitem atualizar só a linha que mudou (via evento),
    // em vez de todo cliente recarregar a fila inteira a cada mudança.
    const [recebimentoL, setRecebimentoL] = useState(recebimento);
    const [preLoteL, setPreLoteL] = useState(preLote);
    const [liberadasL, setLiberadasL] = useState(liberadas);
    useEffect(() => setRecebimentoL(recebimento), [recebimento]);
    useEffect(() => setPreLoteL(preLote), [preLote]);
    useEffect(() => setLiberadasL(liberadas), [liberadas]);

    const isHoje = dataFiltro === hoje();
    // Visão "simples" (hoje, sem filtros): dá pra atualizar a linha no cliente com segurança
    const visaoSimples = isHoje && !filtros.busca && !filtros.loja && !filtros.nivel && !filtros.status && !filtros.tipo;
    const visaoSimplesRef = useRef(visaoSimples);
    visaoSimplesRef.current = visaoSimples;

    // Reload de segurança (debounced) para os casos que não dá pra patchar no cliente
    const reloadTimer = useRef<ReturnType<typeof setTimeout>>();
    const reloadDebounced = () => {
        clearTimeout(reloadTimer.current);
        reloadTimer.current = setTimeout(() => {
            router.reload({ only: ['recebimento', 'preLote', 'liberadas', 'resumoAlertas', 'resumoTipos', 'totalReconferir'] });
        }, 400);
    };

    // Reposiciona a nota que mudou na lista certa (ou remove) mantendo a ordem
    const patch = (e: { nota?: Nota; removida?: number }) => {
        if (e.removida) {
            const id = e.removida;
            setRecebimentoL(l => l.filter(n => n.id !== id));
            setPreLoteL(l => l.filter(n => n.id !== id));
            setLiberadasL(l => l.filter(n => n.id !== id));
            return;
        }
        const nota = e.nota;
        if (!nota) return;
        const naFila = nota.status !== 'liberada';
        // Liberadas mostra as do dia; evita puxar p/ hoje uma liberada em dia passado
        // — salvo se o caminhão a trouxe hoje (recebida_em), aí ela entra na lista.
        const liberadaHoje = !naFila && (
            (nota.liberada_em ?? '').slice(0, 10) === hoje() ||
            (nota.recebida_em ?? '').slice(0, 10) === hoje()
        );
        const sem = (l: Nota[]) => l.filter(n => n.id !== nota.id);
        const asc = (l: Nota[]) => [...l].sort((a, b) => a.created_at.localeCompare(b.created_at));
        const desc = (l: Nota[]) => [...l].sort((a, b) => (b.liberada_em ?? '').localeCompare(a.liberada_em ?? ''));
        setRecebimentoL(l => naFila && nota.origem === 'recebimento' ? asc([...sem(l), nota]) : sem(l));
        setPreLoteL(l => naFila && nota.origem === 'pre_lote' ? asc([...sem(l), nota]) : sem(l));
        setLiberadasL(l => liberadaHoje ? desc([...sem(l), nota]) : sem(l));
    };

    useEffect(() => {
        window.Echo.private('notas').listen('.NotaAtualizada', (e: { nota?: Nota; removida?: number }) => {
            setEchoTick(t => t + 1); // recarrega a thread aberta de comentários
            if (visaoSimplesRef.current && (e?.nota || e?.removida)) {
                patch(e);            // atualiza só a linha
            } else {
                reloadDebounced();   // casos estruturais/filtrados: reload leve
            }
        });
        return () => { window.Echo.leave('notas'); clearTimeout(reloadTimer.current); };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const todas = [...recebimentoL, ...preLoteL, ...liberadasL];
    // O modal de cards deriva das listas locais — reflete o realtime na hora
    const notaCards = cardsId ? todas.find(n => n.id === cardsId) ?? null : null;

    // Contadores: na visão simples derivam das listas locais (refletem os patches);
    // com filtros ativos, vêm do servidor (as listas estão filtradas)
    const filaLocal = [...recebimentoL, ...preLoteL];
    const resumoEfetivo = visaoSimples ? {
        critico: filaLocal.filter(n => n.nivel === 'critico').length,
        alerta:  filaLocal.filter(n => n.nivel === 'alerta').length,
        atencao: filaLocal.filter(n => n.nivel === 'atencao').length,
    } : resumoAlertas;
    const totalReconferirEfetivo = visaoSimples
        ? filaLocal.filter(n => n.status === 'reconferir').length
        : totalReconferir;

    // Idem para os tipos de divergência: card resolvido não conta, só o que pede ação
    const temCardAtivo = (n: Nota, tipo: TipoCard) =>
        n.cards.some(c => c.tipo === tipo && c.status !== 'resolvido');

    const resumoTiposEfetivo: ResumoTipos = visaoSimples
        ? (opcoes.tipos.reduce((acc, t) => {
            acc[t] = filaLocal.filter(n => temCardAtivo(n, t)).length;
            return acc;
        }, {} as ResumoTipos))
        : resumoTipos;

    const paramsAtuais = () => ({
        data: dataFiltro,
        busca: buscaLocal || undefined,
        loja: lojaLocal || undefined,
        nivel: filtros.nivel || undefined,
        status: filtros.status || undefined,
        tipo: filtros.tipo || undefined,
    });

    const irPara = (extras: Record<string, unknown> = {}) =>
        router.get(route('notas.index'), { ...paramsAtuais(), ...extras }, { preserveState: true, replace: true });

    const mudarData = (d: string) => irPara({ data: d });
    const filtrarNivel = (n: Nivel | null) => irPara({ nivel: n ?? undefined });
    const filtrarStatus = (s: string | null) => irPara({ status: s ?? undefined });
    const filtrarTipo = (t: TipoCard | null) => irPara({ tipo: t ?? undefined });
    const diaAnterior = () => mudarData(format(subDays(parseISO(dataFiltro), 1), 'yyyy-MM-dd'));
    const diaSeguinte = () => mudarData(format(addDays(parseISO(dataFiltro), 1), 'yyyy-MM-dd'));
    const aplicarFiltros = () => irPara();
    const limparFiltros = () => {
        setBuscaLocal(''); setLojaLocal('');
        router.get(route('notas.index'), { data: dataFiltro }, { preserveState: true, replace: true });
    };
    const filtrosAtivos = !!(filtros.busca || filtros.loja || filtros.nivel || filtros.status || filtros.tipo);

    const criar = (dados: any, confirmarMover = false) => {
        setSubmetendo(true);
        router.post(route('notas.store'), { ...dados, confirmar_mover: confirmarMover }, {
            preserveScroll: true,
            onSuccess: () => { setModalNova(false); setErros({}); },
            onError: e => {
                // A nota já existe na outra fila: o backend devolve a fila atual
                // em "duplicada" e espera a confirmação para mover.
                if (e.duplicada) {
                    setErros({});
                    const atual = ORIGEM_LABEL[e.duplicada] ?? e.duplicada;
                    const nova = ORIGEM_LABEL[dados.origem] ?? dados.origem;
                    if (confirm(`Esta nota já está em "${atual}". Deseja mover para "${nova}"?`)) {
                        criar(dados, true);
                    }
                    return;
                }
                setErros(e);
            },
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

    // 🙋‍♂️ "estou olhando esta nota". O servidor decide: reserva, solta (se já é
    // minha) ou avisa quem está nela (volta em flash.erro, que vira toast).
    const visualizar = (n: Nota) => {
        router.post(route('notas.visualizar', n.id), {}, { preserveScroll: true, preserveState: true });
    };

    // Estorna a liberação: tira das liberadas e volta a nota para o recebimento
    const devolver = (n: Nota) => {
        if (!confirm(`Devolver a nota ${n.numero_nota} ao recebimento? Ela sai das liberadas e volta para a fila para reajuste.`)) return;
        router.post(route('notas.devolver', n.id), {}, { preserveScroll: true });
    };

    const excluir = (n: Nota) => {
        // A nota liberada já foi concluída: vale um aviso mais explícito
        const aviso = n.status === 'liberada'
            ? `Excluir a nota ${n.numero_nota}, que JÁ FOI LIBERADA? Ela sai do histórico do dia. Esta ação pode ser revertida pelo administrador.`
            : `Excluir a nota ${n.numero_nota}? Esta ação pode ser revertida pelo administrador.`;

        if (!confirm(aviso)) return;
        router.delete(route('notas.destroy', n.id));
    };

    const sla = opcoes.sla ?? { atencao: 1, alerta: 3, critico: 7 };
    const faixaTexto: Record<Exclude<Nivel, 'normal'>, string> = {
        critico: `${sla.critico}+ dias`,
        alerta: `${sla.alerta}–${sla.critico - 1} dias`,
        atencao: `${sla.atencao}–${sla.alerta - 1} dias`,
    };
    const temAlertas = resumoEfetivo.critico + resumoEfetivo.alerta + resumoEfetivo.atencao > 0;
    const tiposComPendencia = opcoes.tipos.filter(t => (resumoTiposEfetivo[t] ?? 0) > 0);
    const temFiltros = temAlertas || totalReconferirEfetivo > 0 || tiposComPendencia.length > 0;
    const filtrandoReconferir = filtros.status === 'reconferir';

    const COLS_FILA = ['Nota', 'Fornecedor', 'Divergências', 'Loja', 'Observação', 'Lançado', ''];
    const COLS_LIBERADAS = ['Nota', 'Fornecedor', 'Divergências', 'Loja', 'Observação', 'Liberada por', ''];

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
                                onEditar={setModalEditar} onExcluir={excluir} onLiberar={liberarRapido}
                                onVisualizar={visualizar} usuarioId={user.id} />
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
                            const qtd = resumoEfetivo[n];
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
                        {totalReconferirEfetivo > 0 && (
                            <button onClick={() => filtrarStatus(filtrandoReconferir ? null : 'reconferir')}
                                title={filtrandoReconferir ? 'Remover filtro' : 'Ver só as prontas p/ liberar'}
                                className="flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg transition"
                                style={{
                                    background: filtrandoReconferir ? p.AMBER + '33' : p.AMBER + '14',
                                    border: `1px solid ${p.AMBER}${filtrandoReconferir ? 'aa' : '44'}`,
                                    color: p.AMBER,
                                }}>
                                <strong>{totalReconferirEfetivo}</strong>
                                <span>reconferir</span>
                                <span className="text-xs" style={{ opacity: 0.75 }}>(pronta p/ liberar)</span>
                            </button>
                        )}

                        {/* Divergências em aberto: "quais notas estão travadas no custo?" */}
                        {tiposComPendencia.length > 0 && (
                            <span className="w-px h-5 mx-0.5" style={{ background: p.BORDER }} />
                        )}

                        {tiposComPendencia.map(t => {
                            const cor = (isDark ? CARD_COR_DARK : CARD_COR_LIGHT)[t];
                            const ativo = filtros.tipo === t;
                            return (
                                <button key={t} onClick={() => filtrarTipo(ativo ? null : t)}
                                    title={ativo ? 'Remover filtro' : `Ver só as notas com divergência de ${(TIPO_CARD_LABEL[t] ?? t).toLowerCase()} em aberto`}
                                    className="flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-lg transition"
                                    style={{
                                        background: ativo ? cor.bg : 'transparent',
                                        border: `1px solid ${cor.border}`,
                                        color: cor.text,
                                        opacity: ativo ? 1 : 0.85,
                                    }}>
                                    <strong>{resumoTiposEfetivo[t]}</strong>
                                    <span>{TIPO_CARD_LABEL[t] ?? t}</span>
                                </button>
                            );
                        })}

                        {(filtros.nivel || filtros.status || filtros.tipo) && (
                            <button onClick={() => irPara({ nivel: undefined, status: undefined, tipo: undefined })}
                                className="text-xs flex items-center gap-1" style={{ color: p.MUTED }}>
                                <Icone path="M6 18L18 6M6 6l12 12" className="w-3 h-3" /> Ver todas
                            </button>
                        )}
                    </div>
                )}

                {/* ── Filas ───────────────────────────────────────────────────── */}
                {secaoFila('Recebimento', 'caminhão na porta — prioridade', recebimentoL, p.RED)}
                {secaoFila('Pré-lote', 'notas antecipadas', preLoteL, p.ACCENT)}

                {/* ── Liberadas ───────────────────────────────────────────────── */}
                <div className="rounded-xl overflow-hidden" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                    <div className="flex items-center justify-between px-5 py-3.5" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                        <h2 className="text-sm font-semibold flex items-center gap-2" style={{ color: p.MUTED }}>
                            Liberadas neste dia
                            <span className="text-xs font-medium px-2 py-0.5 rounded-full"
                                style={{ background: p.GREEN + '22', color: p.GREEN, border: `1px solid ${p.GREEN}33` }}>
                                {liberadasL.length}
                            </span>
                        </h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full">
                            <THead colunas={COLS_LIBERADAS} p={p} />
                            <tbody>
                                {liberadasL.length === 0 ? (
                                    <tr><td colSpan={7} className="px-4 py-8 text-center text-sm" style={{ color: p.MUTED }}>
                                        Nenhuma nota liberada neste dia.
                                    </td></tr>
                                ) : liberadasL.map(n => (
                                    <tr key={n.id} className="opacity-80 group" style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                        <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>
                                            <span className="line-through">{n.numero_nota}</span>
                                            {/* Liberada em outro dia, mas o caminhão trouxe hoje */}
                                            {n.recebida_em?.slice(0, 10) === hoje() && n.liberada_em?.slice(0, 10) !== hoje() && (
                                                <span className="ml-2 text-[11px] font-medium px-1.5 py-0.5 rounded no-underline"
                                                    style={{ background: p.GREEN + '22', color: p.GREEN }}
                                                    title={`Liberada no pré-lote em ${n.liberada_em ? new Date(n.liberada_em).toLocaleDateString('pt-BR') : '—'}`}>
                                                    recebida hoje
                                                </span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3 text-sm max-w-[180px] truncate" style={{ color: p.TEXT }}>{n.fornecedor.nome}</td>
                                        <td className="px-4 py-3">
                                            <button onClick={() => setCardsId(n.id)} className="flex flex-wrap items-center gap-1" title="Ver histórico de cards">
                                                {n.cards.length === 0
                                                    ? <span className="text-xs" style={{ color: p.MUTED }}>sem divergência</span>
                                                    : n.cards.map(c => <CardBadge key={c.id} card={c} isDark={isDark} />)}
                                            </button>
                                        </td>
                                        <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>{lojaNome(n.loja)}</td>
                                        <td className="px-4 py-3 text-sm max-w-[180px] truncate" style={{ color: p.TEXT }} title={n.observacao ?? ''}>
                                            {n.observacao || <span style={{ color: p.MUTED }}>—</span>}
                                        </td>
                                        <td className="px-4 py-3 text-sm" style={{ color: p.TEXT }}>{n.liberada_por?.name.split(' ')[0] ?? '—'}</td>
                                        <td className="px-4 py-3 text-right">
                                            <button onClick={() => setComentariosNota(n)} title="Comentários"
                                                className={`inline-flex items-center gap-1 p-1.5 rounded-lg transition ${n.comentarios_count > 0 ? '' : 'opacity-0 group-hover:opacity-100'}`}
                                                style={{ color: n.comentarios_count > 0 ? p.ACCENT : p.MUTED }}>
                                                <Icone path="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.8 9.8 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                                                {n.comentarios_count > 0 && <span className="text-xs font-medium">{n.comentarios_count}</span>}
                                            </button>

                                            {/* Conferiu errado: devolve ao recebimento para reajuste (pré-lote/recebimento) */}
                                            {can.devolverNota && (
                                                <button onClick={() => devolver(n)} title="Devolver ao recebimento (conferido errado)"
                                                    className="inline-flex items-center p-1.5 rounded-lg transition opacity-0 group-hover:opacity-100"
                                                    style={{ color: p.AMBER }}
                                                    onMouseEnter={e => (e.currentTarget.style.background = p.AMBER + '1a')}
                                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                                    <Icone path="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3" />
                                                </button>
                                            )}

                                            {/* Apagar o que já foi liberado é ato de admin — some para os outros papéis */}
                                            {can.excluirNotaLiberada && (
                                                <button onClick={() => excluir(n)} title="Excluir nota liberada"
                                                    className="inline-flex items-center p-1.5 rounded-lg transition opacity-0 group-hover:opacity-100"
                                                    style={{ color: p.RED }}
                                                    onMouseEnter={e => (e.currentTarget.style.background = p.RED + '1a')}
                                                    onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                                    <Icone path="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                </button>
                                            )}
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
