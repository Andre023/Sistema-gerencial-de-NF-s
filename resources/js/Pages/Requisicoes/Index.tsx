import React, { useState, useEffect } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router, usePage } from '@inertiajs/react';
import { format, parseISO, addDays, subDays } from 'date-fns';
import { Requisicao, Fornecedor, FiltrosAtivos, OpcoesSistema } from '@/types';
import { useTheme } from '@/Contexts/ThemeContext';
import { DARK, LIGHT, Palette, lojaNome, hoje } from '@/lib/tema';
import Icone from '@/Components/painel/Icone';
import Modal from '@/Components/painel/Modal';
import Badge from '@/Components/painel/Badge';
import THead from '@/Components/painel/THead';
import CampoFornecedor from '@/Components/painel/CampoFornecedor';

interface Props {
    auth: { user: { id: number; name: string; email: string } };
    pendentes: Requisicao[];
    atendidas: Requisicao[];
    fornecedores: Fornecedor[];
    dataFiltro: string;
    filtros: FiltrosAtivos;
    opcoes: OpcoesSistema;
}

// ─── Formulário ───────────────────────────────────────────────────────────────

interface DadosForm {
    numero_nota: string; fornecedor_id: number | '';
    fornecedor: { id: number | ''; nome: string }; loja: number | ''; motivo: string; observacao: string;
}

function FormRequisicao({ fornecedores, opcoes, inicial, onSubmit, onCancelar, carregando, erros, labelSubmit, p }: {
    fornecedores: Fornecedor[]; opcoes: OpcoesSistema; inicial?: Requisicao;
    onSubmit: (d: Omit<DadosForm, 'fornecedor'>) => void; onCancelar: () => void;
    carregando: boolean; erros: Record<string, string>; labelSubmit: string; p: Palette;
}) {
    const [form, setForm] = useState<DadosForm>({
        numero_nota: inicial?.numero_nota ?? '', fornecedor_id: inicial?.fornecedor?.id ?? '',
        fornecedor: { id: inicial?.fornecedor?.id ?? '', nome: inicial?.fornecedor?.nome ?? '' },
        loja: inicial?.loja ?? '', motivo: inicial?.motivo ?? '', observacao: inicial?.observacao ?? '',
    });

    const set = <K extends keyof DadosForm>(k: K, v: DadosForm[K]) => setForm(prev => ({ ...prev, [k]: v }));

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        onSubmit({ numero_nota: form.numero_nota, fornecedor_id: form.fornecedor.id, loja: form.loja, motivo: form.motivo, observacao: form.observacao });
    };

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
        <form onSubmit={handleSubmit} className="space-y-4">
            {campo('Número da nota', true,
                <input type="text" value={form.numero_nota} onChange={e => set('numero_nota', e.target.value)}
                    placeholder="Ex: 46252"
                    className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                    style={inputStyle(!!erros.numero_nota)} />, erros.numero_nota
            )}
            {campo('Fornecedor', true,
                <CampoFornecedor fornecedores={fornecedores} valor={form.fornecedor}
                    onChange={v => setForm(prev => ({ ...prev, fornecedor: v, fornecedor_id: v.id }))}
                    erro={erros.fornecedor_id} p={p} />
            )}
            <div className="grid grid-cols-2 gap-3">
                {campo('Loja', true,
                    <select value={form.loja} onChange={e => set('loja', Number(e.target.value) || '')}
                        className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                        style={inputStyle(!!erros.loja)}>
                        <option value="">Selecionar...</option>
                        {opcoes.lojas.map(l => <option key={l} value={l}>{lojaNome(l)}</option>)}
                    </select>, erros.loja
                )}
                {campo('Motivo', true,
                    <select value={form.motivo} onChange={e => set('motivo', e.target.value)}
                        className="block w-full rounded-lg text-sm px-3 py-2 outline-none"
                        style={inputStyle(!!erros.motivo)}>
                        <option value="">Selecionar...</option>
                        {opcoes.motivos.map(m => <option key={m} value={m}>{m}</option>)}
                    </select>, erros.motivo
                )}
            </div>
            {campo('Observação', false,
                <textarea value={form.observacao} onChange={e => set('observacao', e.target.value)}
                    rows={3} placeholder="Detalhes adicionais..."
                    className="block w-full rounded-lg text-sm px-3 py-2 outline-none resize-none"
                    style={inputStyle()} />
            )}
            <div className="flex justify-end gap-3 pt-3 mt-1" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                <button type="button" onClick={onCancelar}
                    className="px-4 py-2 text-sm transition" style={{ color: p.MUTED }}>
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

// ─── Linha Pendente ───────────────────────────────────────────────────────────

function LinhaPendente({ req, onEditar, onAtender, onExcluir, carregando, podeGerenciar, p }: {
    req: Requisicao; onEditar: (r: Requisicao) => void; onAtender: (id: number) => void;
    onExcluir: (id: number) => void; carregando: boolean; podeGerenciar: boolean; p: Palette;
}) {
    const rowBg = req.atrasada ? (p === DARK ? 'rgba(210,153,34,0.07)' : 'rgba(251,191,36,0.06)') : 'transparent';

    return (
        <tr className="group transition-colors"
            style={{ borderBottom: `1px solid ${p.BORDER}`, background: rowBg }}
            onMouseEnter={e => !req.atrasada && (e.currentTarget.style.background = p.HOVER_ROW)}
            onMouseLeave={e => !req.atrasada && (e.currentTarget.style.background = rowBg)}>
            <td className="px-4 py-3 text-sm">
                <span className="font-medium" style={{ color: p.TEXT }}>{req.numero_nota}</span>
                {req.atrasada && (
                    <span className="ml-2 text-xs font-medium" style={{ color: p.AMBER }}>⚠ {req.data_origem}</span>
                )}
            </td>
            <td className="px-4 py-3 text-sm max-w-[180px] truncate" style={{ color: p.TEXT }}>{req.fornecedor.nome}</td>
            <td className="px-4 py-3"><Badge label={req.motivo} isDark={p === DARK} /></td>
            <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>{lojaNome(req.loja)}</td>
            <td className="px-4 py-3 text-sm max-w-[200px] truncate" style={{ color: p.TEXT }} title={req.observacao ?? ''}>
                {req.observacao || <span style={{ color: p.MUTED }}>—</span>}
            </td>
            <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>{req.user.name.split(' ')[0]}</td>
            <td className="px-4 py-3 text-right">
                <div className="flex items-center justify-end gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button onClick={() => onAtender(req.id)} disabled={carregando} title="Atender"
                        className="p-1.5 rounded-lg transition disabled:opacity-40"
                        style={{ color: p.GREEN }}
                        onMouseEnter={e => (e.currentTarget.style.background = p.GREEN + '1a')}
                        onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                        <Icone path="M5 13l4 4L19 7" />
                    </button>
                    {podeGerenciar && (
                        <>
                            <button onClick={() => onEditar(req)} title="Editar"
                                className="p-1.5 rounded-lg transition"
                                style={{ color: p.ACCENT }}
                                onMouseEnter={e => (e.currentTarget.style.background = p.ACCENT + '1a')}
                                onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                <Icone path="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </button>
                            <button onClick={() => onExcluir(req.id)} title="Excluir"
                                className="p-1.5 rounded-lg transition"
                                style={{ color: p.RED }}
                                onMouseEnter={e => (e.currentTarget.style.background = p.RED + '1a')}
                                onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                <Icone path="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </button>
                        </>
                    )}
                </div>
            </td>
        </tr>
    );
}

// ─── Página principal ─────────────────────────────────────────────────────────

export default function Index({ pendentes, atendidas, fornecedores, dataFiltro, filtros, opcoes }: Props) {
    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;
    const podeGerenciar = usePage().props.auth.can.gerenciarRegistros;

    useEffect(() => {
        window.Echo.private('requisicoes').listen('.RequisicaoAtualizada', () => {
            router.reload({ only: ['pendentes', 'atendidas'] });
        });
        return () => { window.Echo.leave('requisicoes'); };
    }, []);

    const [modalNova, setModalNova] = useState(false);
    const [modalEditar, setModalEditar] = useState<Requisicao | null>(null);
    const [erros, setErros] = useState<Record<string, string>>({});
    const [submetendo, setSubmetendo] = useState(false);
    const [atendendoId, setAtendendoId] = useState<number | null>(null);
    const [buscaLocal, setBuscaLocal] = useState(filtros.busca ?? '');
    const [motivoLocal, setMotivoLocal] = useState(filtros.motivo ?? '');
    const [lojaLocal, setLojaLocal] = useState(filtros.loja ? String(filtros.loja) : '');

    const isHoje = dataFiltro === hoje();

    const irPara = (extras: Record<string, unknown> = {}) =>
        router.get(route('requisicoes.index'),
            { data: dataFiltro, busca: buscaLocal || undefined, motivo: motivoLocal || undefined, loja: lojaLocal || undefined, ...extras },
            { preserveState: true, replace: true });

    const mudarData = (d: string) =>
        router.get(route('requisicoes.index'),
            { data: d, busca: buscaLocal || undefined, motivo: motivoLocal || undefined, loja: lojaLocal || undefined },
            { preserveState: true, replace: true });

    const diaAnterior = () => mudarData(format(subDays(parseISO(dataFiltro), 1), 'yyyy-MM-dd'));
    const diaSeguinte = () => mudarData(format(addDays(parseISO(dataFiltro), 1), 'yyyy-MM-dd'));
    const aplicarFiltros = () => irPara();
    const limparFiltros = () => {
        setBuscaLocal(''); setMotivoLocal(''); setLojaLocal('');
        router.get(route('requisicoes.index'), { data: dataFiltro }, { preserveState: true, replace: true });
    };
    const filtrosAtivos = !!(filtros.busca || filtros.motivo || filtros.loja);

    const criar = (dados: any) => {
        setSubmetendo(true);
        router.post(route('requisicoes.store'), dados, {
            onSuccess: () => { setModalNova(false); setErros({}); },
            onError: e => setErros(e),
            onFinish: () => setSubmetendo(false),
        });
    };

    const salvarEdicao = (dados: any) => {
        if (!modalEditar) return;
        setSubmetendo(true);
        router.patch(route('requisicoes.update', modalEditar.id), dados, {
            onSuccess: () => { setModalEditar(null); setErros({}); },
            onError: e => setErros(e),
            onFinish: () => setSubmetendo(false),
        });
    };

    const atender = (id: number) => {
        setAtendendoId(id);
        router.patch(route('requisicoes.update', id), { status: 'Atendida' }, { onFinish: () => setAtendendoId(null) });
    };

    const excluir = (id: number) => {
        if (!confirm('Excluir esta requisição? Esta ação pode ser revertida pelo administrador.')) return;
        router.delete(route('requisicoes.destroy', id));
    };

    const qtdAtrasadas = pendentes.filter(r => r.atrasada).length;
    const COLS_PENDENTES = ['Nota', 'Fornecedor', 'Motivo', 'Loja', 'Observação', 'Solicitado', ''];
    const COLS_ATENDIDAS = ['Nota', 'Fornecedor', 'Motivo', 'Loja', 'Observação', 'Atendida por'];

    const inputCtrl = {
        background: p.INPUT_BG, color: p.TEXT,
        border: `1px solid ${p.INPUT_BORDER}`,
    };

    return (
        <AuthenticatedLayout header={null}>
            <Head title="Requisições" />

            <Modal aberto={modalNova} onFechar={() => setModalNova(false)} titulo="Nova requisição" p={p}>
                <FormRequisicao fornecedores={fornecedores} opcoes={opcoes} onSubmit={criar}
                    onCancelar={() => setModalNova(false)} carregando={submetendo} erros={erros}
                    labelSubmit="Criar requisição" p={p} />
            </Modal>

            <Modal aberto={!!modalEditar} onFechar={() => setModalEditar(null)} titulo="Editar requisição" p={p}>
                {modalEditar && (
                    <FormRequisicao fornecedores={fornecedores} opcoes={opcoes} inicial={modalEditar}
                        onSubmit={salvarEdicao} onCancelar={() => setModalEditar(null)}
                        carregando={submetendo} erros={erros} labelSubmit="Salvar alterações" p={p} />
                )}
            </Modal>

            <div className="min-h-screen py-6 px-4 sm:px-6 lg:px-8 max-w-screen-2xl mx-auto space-y-4 transition-colors duration-200"
                style={{ background: p.BG }}>

                {/* ── Barra de controles ─────────────────────────────────────── */}
                <div className="flex flex-wrap items-center gap-2.5">

                    {/* Navegação de data */}
                    <div className="flex items-center gap-1 rounded-lg px-2 py-1.5"
                        style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                        <button onClick={diaAnterior}
                            className="p-1 rounded transition" style={{ color: p.MUTED }}
                            onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                            onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}
                            title="Dia anterior">
                            <Icone path="M15 19l-7-7 7-7" />
                        </button>
                        <input type="date" value={dataFiltro} onChange={e => mudarData(e.target.value)}
                            className="border-none text-sm font-medium focus:ring-0 p-0 bg-transparent cursor-pointer"
                            style={{ color: p.TEXT }} />
                        <button onClick={diaSeguinte} disabled={isHoje}
                            className="p-1 rounded transition disabled:opacity-30" style={{ color: p.MUTED }}
                            onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                            onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}
                            title="Próximo dia">
                            <Icone path="M9 5l7 7-7 7" />
                        </button>
                    </div>

                    {isHoje && (
                        <span className="text-xs font-medium px-2.5 py-1 rounded-md"
                            style={{ background: p.ACCENT + '22', color: p.ACCENT, border: `1px solid ${p.ACCENT}44` }}>
                            Hoje
                        </span>
                    )}

                    {/* Busca */}
                    <div className="relative">
                        <input type="search" placeholder="Buscar nota ou fornecedor..."
                            value={buscaLocal} onChange={e => setBuscaLocal(e.target.value)}
                            onKeyDown={e => e.key === 'Enter' && aplicarFiltros()}
                            className="rounded-lg text-sm pl-8 pr-3 py-2 outline-none w-56"
                            style={inputCtrl} />
                        <span className="absolute left-2.5 top-1/2 -translate-y-1/2 pointer-events-none" style={{ color: p.MUTED }}>
                            <Icone path="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </span>
                    </div>

                    <select value={motivoLocal} onChange={e => setMotivoLocal(e.target.value)}
                        className="rounded-lg text-sm px-3 py-2 outline-none" style={inputCtrl}>
                        <option value="">Todos os motivos</option>
                        {opcoes.motivos.map(m => <option key={m} value={m}>{m}</option>)}
                    </select>

                    <select value={lojaLocal} onChange={e => setLojaLocal(e.target.value)}
                        className="rounded-lg text-sm px-3 py-2 outline-none" style={inputCtrl}>
                        <option value="">Todas as lojas</option>
                        {opcoes.lojas.map(l => <option key={l} value={l}>{lojaNome(l)}</option>)}
                    </select>

                    <button onClick={aplicarFiltros}
                        className="px-3.5 py-2 text-sm font-medium rounded-lg transition"
                        style={{ background: p.SURFACE, color: p.TEXT, border: `1px solid ${p.BORDER}` }}
                        onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                        onMouseLeave={e => (e.currentTarget.style.background = p.SURFACE)}>
                        Filtrar
                    </button>

                    {filtrosAtivos && (
                        <button onClick={limparFiltros}
                            className="text-xs flex items-center gap-1 transition" style={{ color: p.MUTED }}
                            onMouseEnter={e => (e.currentTarget.style.color = p.TEXT)}
                            onMouseLeave={e => (e.currentTarget.style.color = p.MUTED)}>
                            <Icone path="M6 18L18 6M6 6l12 12" className="w-3 h-3" /> Limpar
                        </button>
                    )}

                    <button onClick={() => setModalNova(true)}
                        className="ml-auto flex items-center gap-1.5 px-4 py-2 text-sm font-medium text-white rounded-lg transition"
                        style={{ background: p.ACCENT }}
                        onMouseEnter={e => (e.currentTarget.style.filter = 'brightness(1.1)')}
                        onMouseLeave={e => (e.currentTarget.style.filter = 'none')}>
                        <Icone path="M12 4v16m8-8H4" /> Nova requisição
                    </button>
                </div>

                {/* ── Aviso atrasadas ────────────────────────────────────────── */}
                {qtdAtrasadas > 0 && (
                    <div className="flex items-center gap-2 text-sm px-4 py-2.5 rounded-lg"
                        style={{
                            background: p.AMBER + '18',
                            border: `1px solid ${p.AMBER}44`,
                            color: p.AMBER,
                        }}>
                        <Icone path="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" className="w-4 h-4 shrink-0" />
                        <span><strong>{qtdAtrasadas}</strong> requisição{qtdAtrasadas !== 1 ? 'ões' : ''} pendente{qtdAtrasadas !== 1 ? 's' : ''} de dias anteriores.</span>
                    </div>
                )}

                {/* ── Tabela Pendentes ───────────────────────────────────────── */}
                <div className="rounded-xl overflow-hidden" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                    <div className="flex items-center justify-between px-5 py-3.5"
                        style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                        <h2 className="text-sm font-semibold flex items-center gap-2" style={{ color: p.TEXT }}>
                            Pendentes
                            <span className="text-xs font-medium px-2 py-0.5 rounded-full"
                                style={{ background: p.HOVER_ROW, color: p.MUTED }}>
                                {pendentes.length}
                            </span>
                        </h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full">
                            <THead colunas={COLS_PENDENTES} p={p} />
                            <tbody>
                                {pendentes.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-12 text-center text-sm" style={{ color: p.MUTED }}>
                                            Nenhuma requisição pendente para esta data.
                                        </td>
                                    </tr>
                                ) : (
                                    pendentes.map(req => (
                                        <LinhaPendente key={req.id} req={req} onEditar={setModalEditar}
                                            onAtender={atender} onExcluir={excluir}
                                            carregando={atendendoId === req.id} podeGerenciar={podeGerenciar} p={p} />
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* ── Tabela Atendidas ───────────────────────────────────────── */}
                <div className="rounded-xl overflow-hidden" style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                    <div className="flex items-center justify-between px-5 py-3.5"
                        style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                        <h2 className="text-sm font-semibold flex items-center gap-2" style={{ color: p.MUTED }}>
                            Atendidas neste dia
                            <span className="text-xs font-medium px-2 py-0.5 rounded-full"
                                style={{ background: p.GREEN + '22', color: p.GREEN, border: `1px solid ${p.GREEN}33` }}>
                                {atendidas.length}
                            </span>
                        </h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full">
                            <THead colunas={COLS_ATENDIDAS} p={p} />
                            <tbody>
                                {atendidas.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-10 text-center text-sm" style={{ color: p.TEXT }}>
                                            Nenhuma requisição atendida neste dia.
                                        </td>
                                    </tr>
                                ) : (
                                    atendidas.map(req => (
                                        <tr key={req.id} className="opacity-80"
                                            style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                            <td className="px-4 py-3 text-sm line-through" style={{ color: p.TEXT }}>{req.numero_nota}</td>
                                            <td className="px-4 py-3 text-sm max-w-[180px] truncate" style={{ color: p.TEXT }}>{req.fornecedor.nome}</td>
                                            <td className="px-4 py-3"><Badge label={req.motivo} isDark={isDark} /></td>
                                            <td className="px-4 py-3 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>{lojaNome(req.loja)}</td>
                                            <td className="px-4 py-3 text-sm max-w-[200px] truncate" style={{ color: p.TEXT }}>{req.observacao || '—'}</td>
                                            <td className="px-4 py-3 text-sm" style={{ color: p.TEXT }}>{req.atendida_por?.name.split(' ')[0] ?? '—'}</td>
                                        </tr>
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </AuthenticatedLayout>
    );
}
