import React, { useState, useMemo, useEffect, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { format, parseISO, addDays, subDays } from 'date-fns';
import { Requisicao, Fornecedor, FiltrosAtivos, OpcoesSistema } from '@/types';
import { useTheme } from '@/Contexts/ThemeContext';

interface Props {
    auth: { user: { id: number; name: string; email: string } };
    pendentes: Requisicao[];
    atendidas: Requisicao[];
    fornecedores: Fornecedor[];
    dataFiltro: string;
    filtros: FiltrosAtivos;
    opcoes: OpcoesSistema;
}

// ── Tokens de cor ──────────────────────────────────────────────────────────────
const MOTIVO_COR_LIGHT: Record<string, string> = {
    Cadastro:   'bg-blue-50 text-blue-700 ring-blue-600/20',
    Preço:      'bg-yellow-50 text-yellow-700 ring-yellow-600/20',
    Regra:      'bg-red-50 text-red-700 ring-red-600/20',
    Quantidade: 'bg-purple-50 text-purple-700 ring-purple-600/20',
};
const MOTIVO_COR_DARK: Record<string, string> = {
    Cadastro:   'bg-blue-900/40 text-blue-300 ring-blue-500/30',
    Preço:      'bg-yellow-900/40 text-yellow-300 ring-yellow-500/30',
    Regra:      'bg-red-900/40 text-red-300 ring-red-500/30',
    Quantidade: 'bg-purple-900/40 text-purple-300 ring-purple-500/30',
};

const lojaNome = (n: number) => `Loja ${String(n).padStart(2, '0')}`;
const hoje = () => format(new Date(), 'yyyy-MM-dd');

// ── Componentes utilitários ────────────────────────────────────────────────────

function Badge({ label, isDark }: { label: string; isDark: boolean }) {
    const map = isDark ? MOTIVO_COR_DARK : MOTIVO_COR_LIGHT;
    const cls = map[label] ?? (isDark ? 'bg-gray-800 text-gray-300 ring-gray-600/30' : 'bg-gray-50 text-gray-600 ring-gray-500/20');
    return (
        <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${cls}`}>
            {label}
        </span>
    );
}

function Icone({ path, className = 'w-4 h-4' }: { path: string; className?: string }) {
    return (
        <svg className={className} fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth={1.75} d={path} />
        </svg>
    );
}

// ── Modal ──────────────────────────────────────────────────────────────────────

function Modal({ aberto, onFechar, titulo, children, isDark }: {
    aberto: boolean; onFechar: () => void; titulo: string; children: React.ReactNode; isDark: boolean;
}) {
    useEffect(() => {
        if (!aberto) return;
        const fn = (e: KeyboardEvent) => { if (e.key === 'Escape') onFechar(); };
        window.addEventListener('keydown', fn);
        return () => window.removeEventListener('keydown', fn);
    }, [aberto, onFechar]);

    if (!aberto) return null;

    const bg     = isDark ? 'bg-[#161b22]' : 'bg-white';
    const border = isDark ? 'border-[#21262d]' : 'border-gray-100';
    const title  = isDark ? 'text-[#e6edf3]' : 'text-gray-900';
    const closeBtn = isDark ? 'text-[#7d8590] hover:text-[#e6edf3]' : 'text-gray-400 hover:text-gray-600';

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/50 backdrop-blur-sm" onClick={onFechar} />
            <div className={`relative ${bg} rounded-2xl shadow-2xl w-full max-w-lg border ${border}`}>
                <div className={`flex items-center justify-between px-6 pt-5 pb-4 border-b ${border}`}>
                    <h3 className={`text-sm font-semibold ${title}`}>{titulo}</h3>
                    <button onClick={onFechar} className={`${closeBtn} transition p-0.5 rounded`}>
                        <Icone path="M6 18L18 6M6 6l12 12" />
                    </button>
                </div>
                <div className="px-6 py-5">{children}</div>
            </div>
        </div>
    );
}

// ── Autocomplete de Fornecedor ─────────────────────────────────────────────────

function CampoFornecedor({ fornecedores, valor, onChange, erro, isDark }: {
    fornecedores: Fornecedor[];
    valor: { id: number | ''; nome: string };
    onChange: (f: { id: number | ''; nome: string }) => void;
    erro?: string;
    isDark: boolean;
}) {
    const [busca, setBusca] = useState(valor.nome);
    const [aberto, setAberto] = useState(false);
    const ref = useRef<HTMLDivElement>(null);

    const opcoes = useMemo(() =>
        fornecedores.filter(f => f.nome.toLowerCase().includes(busca.toLowerCase())).slice(0, 12),
        [fornecedores, busca]
    );

    useEffect(() => {
        const fn = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) setAberto(false);
        };
        document.addEventListener('mousedown', fn);
        return () => document.removeEventListener('mousedown', fn);
    }, []);

    const selecionar = (f: Fornecedor) => {
        onChange({ id: f.id, nome: f.nome });
        setBusca(f.nome);
        setAberto(false);
    };

    const inputCls = isDark
        ? `block w-full rounded-lg text-sm bg-[#0d1117] text-[#e6edf3] border-[#30363d] focus:border-blue-500 focus:ring-blue-500 ${erro ? 'border-red-500' : ''}`
        : `block w-full rounded-lg text-sm shadow-sm ${erro ? 'border-red-300 focus:border-red-500 focus:ring-red-500' : 'border-gray-200 focus:border-blue-400 focus:ring-blue-400'}`;

    const listCls = isDark
        ? 'absolute z-20 mt-1 w-full bg-[#161b22] border border-[#30363d] rounded-xl shadow-lg max-h-52 overflow-y-auto'
        : 'absolute z-20 mt-1 w-full bg-white border border-gray-100 rounded-xl shadow-lg max-h-52 overflow-y-auto';

    const itemCls = isDark
        ? 'w-full text-left px-3.5 py-2 text-sm text-[#e6edf3] hover:bg-[#21262d] transition'
        : 'w-full text-left px-3.5 py-2 text-sm hover:bg-blue-50 hover:text-blue-700 transition';

    return (
        <div ref={ref} className="relative">
            <input type="text" value={busca}
                onChange={e => { setBusca(e.target.value); onChange({ id: '', nome: e.target.value }); setAberto(true); }}
                onFocus={() => setAberto(true)}
                placeholder="Buscar fornecedor..." autoComplete="off"
                className={inputCls}
            />
            {aberto && opcoes.length > 0 && (
                <ul className={listCls}>
                    {opcoes.map(f => (
                        <li key={f.id}>
                            <button type="button" onMouseDown={() => selecionar(f)} className={itemCls}>{f.nome}</button>
                        </li>
                    ))}
                </ul>
            )}
            {erro && <p className="text-xs text-red-500 mt-1">{erro}</p>}
        </div>
    );
}

// ── Formulário ─────────────────────────────────────────────────────────────────

interface DadosForm {
    numero_nota: string;
    fornecedor_id: number | '';
    fornecedor: { id: number | ''; nome: string };
    loja: number | '';
    motivo: string;
    observacao: string;
}

function FormRequisicao({ fornecedores, opcoes, inicial, onSubmit, onCancelar, carregando, erros, labelSubmit, isDark }: {
    fornecedores: Fornecedor[]; opcoes: OpcoesSistema; inicial?: Requisicao;
    onSubmit: (d: Omit<DadosForm, 'fornecedor'>) => void; onCancelar: () => void;
    carregando: boolean; erros: Record<string, string>; labelSubmit: string; isDark: boolean;
}) {
    const [form, setForm] = useState<DadosForm>({
        numero_nota:   inicial?.numero_nota ?? '',
        fornecedor_id: inicial?.fornecedor?.id ?? '',
        fornecedor:    { id: inicial?.fornecedor?.id ?? '', nome: inicial?.fornecedor?.nome ?? '' },
        loja:          inicial?.loja ?? '',
        motivo:        inicial?.motivo ?? '',
        observacao:    inicial?.observacao ?? '',
    });

    const set = <K extends keyof DadosForm>(k: K, v: DadosForm[K]) => setForm(p => ({ ...p, [k]: v }));

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        onSubmit({ numero_nota: form.numero_nota, fornecedor_id: form.fornecedor.id, loja: form.loja, motivo: form.motivo, observacao: form.observacao });
    };

    const labelCls = isDark ? 'block text-sm font-medium text-[#7d8590] mb-1.5' : 'block text-sm font-medium text-gray-700 mb-1.5';
    const inputBase = isDark
        ? 'block w-full rounded-lg text-sm bg-[#0d1117] text-[#e6edf3] border-[#30363d] focus:border-blue-500 focus:ring-blue-500'
        : 'block w-full rounded-lg text-sm shadow-sm border-gray-200 focus:border-blue-400 focus:ring-blue-400';
    const inputErr = isDark ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-red-300 focus:border-red-500 focus:ring-red-500';
    const divider = isDark ? 'border-[#21262d]' : 'border-gray-100';
    const cancelCls = isDark ? 'px-4 py-2 text-sm text-[#7d8590] hover:text-[#e6edf3] transition' : 'px-4 py-2 text-sm text-gray-500 hover:text-gray-800 transition';

    const campo = (label: string, obrigatorio: boolean, children: React.ReactNode, erro?: string) => (
        <div>
            <label className={labelCls}>{label}{obrigatorio && <span className="text-red-400 ml-0.5">*</span>}</label>
            {children}
            {erro && <p className="text-xs text-red-500 mt-1">{erro}</p>}
        </div>
    );

    return (
        <form onSubmit={handleSubmit} className="space-y-4">
            {campo('Número da nota', true,
                <input type="text" value={form.numero_nota} onChange={e => set('numero_nota', e.target.value)}
                    placeholder="Ex: 46252"
                    className={`${inputBase} ${erros.numero_nota ? inputErr : ''}`} />, erros.numero_nota
            )}
            {campo('Fornecedor', true,
                <CampoFornecedor fornecedores={fornecedores} valor={form.fornecedor}
                    onChange={v => setForm(p => ({ ...p, fornecedor: v, fornecedor_id: v.id }))}
                    erro={erros.fornecedor_id} isDark={isDark} />
            )}
            <div className="grid grid-cols-2 gap-3">
                {campo('Loja', true,
                    <select value={form.loja} onChange={e => set('loja', Number(e.target.value) || '')}
                        className={`${inputBase} ${erros.loja ? inputErr : ''}`}>
                        <option value="">Selecionar...</option>
                        {opcoes.lojas.map(l => <option key={l} value={l}>{lojaNome(l)}</option>)}
                    </select>, erros.loja
                )}
                {campo('Motivo', true,
                    <select value={form.motivo} onChange={e => set('motivo', e.target.value)}
                        className={`${inputBase} ${erros.motivo ? inputErr : ''}`}>
                        <option value="">Selecionar...</option>
                        {opcoes.motivos.map(m => <option key={m} value={m}>{m}</option>)}
                    </select>, erros.motivo
                )}
            </div>
            {campo('Observação', false,
                <textarea value={form.observacao} onChange={e => set('observacao', e.target.value)}
                    rows={3} placeholder="Detalhes adicionais..."
                    className={`${inputBase} resize-none`} />
            )}
            <div className={`flex justify-end gap-3 pt-1 border-t ${divider} mt-2`}>
                <button type="button" onClick={onCancelar} className={cancelCls}>Cancelar</button>
                <button type="submit" disabled={carregando}
                    className="px-5 py-2 text-sm font-medium bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg transition">
                    {carregando ? 'Salvando...' : labelSubmit}
                </button>
            </div>
        </form>
    );
}

// ── THead ──────────────────────────────────────────────────────────────────────

function THead({ colunas, isDark }: { colunas: string[]; isDark: boolean }) {
    const thCls = isDark ? 'text-[#7d8590]' : 'text-gray-400';
    return (
        <thead>
            <tr className={`border-b ${isDark ? 'border-[#21262d]' : 'border-gray-100'}`}>
                {colunas.map(c => (
                    <th key={c} className={`px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide whitespace-nowrap ${thCls}`}>{c}</th>
                ))}
            </tr>
        </thead>
    );
}

// ── Linha Pendente ─────────────────────────────────────────────────────────────

function LinhaPendente({ req, onEditar, onAtender, onExcluir, carregando, isDark }: {
    req: Requisicao; onEditar: (r: Requisicao) => void; onAtender: (id: number) => void;
    onExcluir: (id: number) => void; carregando: boolean; isDark: boolean;
}) {
    const rowHover = isDark ? 'hover:bg-[#21262d]' : 'hover:bg-gray-50/70';
    const rowAtrasada = isDark ? 'bg-amber-900/10' : 'bg-amber-50/40';
    const textMain = isDark ? 'text-[#e6edf3]' : 'text-gray-900';
    const textSub  = isDark ? 'text-[#7d8590]' : 'text-gray-500';
    const textBody = isDark ? 'text-[#7d8590]' : 'text-gray-700';
    const textObs  = isDark ? 'text-[#7d8590]' : 'text-gray-400';
    const divider  = isDark ? 'divide-[#21262d]' : 'divide-gray-50';

    return (
        <tr className={`group transition-colors ${rowHover} ${req.atrasada ? rowAtrasada : ''}`}>
            <td className="px-4 py-3 text-sm">
                <span className={`font-medium ${textMain}`}>{req.numero_nota}</span>
                {req.atrasada && <span className="ml-2 text-xs font-medium text-amber-500">⚠ {req.data_origem}</span>}
            </td>
            <td className={`px-4 py-3 text-sm max-w-[180px] truncate ${textBody}`}>{req.fornecedor.nome}</td>
            <td className="px-4 py-3"><Badge label={req.motivo} isDark={isDark} /></td>
            <td className={`px-4 py-3 text-sm whitespace-nowrap ${textSub}`}>{lojaNome(req.loja)}</td>
            <td className={`px-4 py-3 text-sm max-w-[200px] truncate ${textObs}`} title={req.observacao ?? ''}>
                {req.observacao || <span className={isDark ? 'text-[#30363d]' : 'text-gray-200'}>—</span>}
            </td>
            <td className={`px-4 py-3 text-sm whitespace-nowrap ${textSub}`}>{req.user.name.split(' ')[0]}</td>
            <td className="px-4 py-3 text-right">
                <div className="flex items-center justify-end gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                    <button onClick={() => onAtender(req.id)} disabled={carregando} title="Atender"
                        className="p-1.5 rounded-lg text-green-500 hover:bg-green-500/10 disabled:opacity-40 transition">
                        <Icone path="M5 13l4 4L19 7" />
                    </button>
                    <button onClick={() => onEditar(req)} title="Editar"
                        className="p-1.5 rounded-lg text-blue-400 hover:bg-blue-500/10 transition">
                        <Icone path="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </button>
                    <button onClick={() => onExcluir(req.id)} title="Excluir"
                        className="p-1.5 rounded-lg text-red-400 hover:bg-red-500/10 transition">
                        <Icone path="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </button>
                </div>
            </td>
        </tr>
    );
}

// ── Página principal ───────────────────────────────────────────────────────────

export default function Index({ pendentes, atendidas, fornecedores, dataFiltro, filtros, opcoes }: Props) {
    const { isDark } = useTheme();

    useEffect(() => {
        window.Echo.private('requisicoes').listen('.RequisicaoAtualizada', () => {
            router.reload({ only: ['pendentes', 'atendidas'] });
        });
        return () => { window.Echo.leave('requisicoes'); };
    }, []);

    const [modalNova, setModalNova]       = useState(false);
    const [modalEditar, setModalEditar]   = useState<Requisicao | null>(null);
    const [erros, setErros]               = useState<Record<string, string>>({});
    const [submetendo, setSubmetendo]     = useState(false);
    const [atendendoId, setAtendendoId]   = useState<number | null>(null);
    const [buscaLocal, setBuscaLocal]     = useState(filtros.busca ?? '');
    const [motivoLocal, setMotivoLocal]   = useState(filtros.motivo ?? '');
    const [lojaLocal, setLojaLocal]       = useState(filtros.loja ? String(filtros.loja) : '');

    const isHoje = dataFiltro === hoje();

    const irPara = (extras: Record<string, unknown> = {}) =>
        router.get(route('requisicoes.index'),
            { data: dataFiltro, busca: buscaLocal || undefined, motivo: motivoLocal || undefined, loja: lojaLocal || undefined, ...extras },
            { preserveState: true, replace: true });

    const mudarData = (d: string) =>
        router.get(route('requisicoes.index'),
            { data: d, busca: buscaLocal || undefined, motivo: motivoLocal || undefined, loja: lojaLocal || undefined },
            { preserveState: true, replace: true });

    const diaAnterior  = () => mudarData(format(subDays(parseISO(dataFiltro), 1), 'yyyy-MM-dd'));
    const diaSeguinte  = () => mudarData(format(addDays(parseISO(dataFiltro), 1), 'yyyy-MM-dd'));
    const aplicarFiltros = () => irPara();
    const limparFiltros  = () => {
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

    // ── Tokens dark/light para a página ──────────────────────────────────────
    const pagePad   = 'py-6 px-4 sm:px-6 lg:px-8 max-w-screen-2xl mx-auto space-y-4';
    const card      = isDark ? 'bg-[#161b22] border border-[#21262d] rounded-xl overflow-hidden' : 'bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden';
    const cardHead  = isDark ? 'flex items-center justify-between px-5 py-3 border-b border-[#21262d]' : 'flex items-center justify-between px-5 py-3 border-b border-gray-100';
    const headTitle = isDark ? 'text-sm font-semibold text-[#e6edf3] flex items-center gap-2' : 'text-sm font-semibold text-gray-800 flex items-center gap-2';
    const headSub   = isDark ? 'text-sm font-semibold text-[#7d8590] flex items-center gap-2' : 'text-sm font-semibold text-gray-500 flex items-center gap-2';
    const badge0    = isDark ? 'bg-[#21262d] text-[#7d8590] text-xs font-medium px-2 py-0.5 rounded-full' : 'bg-gray-100 text-gray-600 text-xs font-medium px-2 py-0.5 rounded-full';
    const badgeGreen= isDark ? 'bg-green-900/30 text-green-400 text-xs font-medium px-2 py-0.5 rounded-full' : 'bg-green-50 text-green-600 text-xs font-medium px-2 py-0.5 rounded-full';
    const divRow    = isDark ? 'divide-y divide-[#21262d]' : 'divide-y divide-gray-50';
    const emptyCell = isDark ? 'text-[#7d8590]' : 'text-gray-400';
    const ctrlBg    = isDark ? 'bg-[#161b22] border border-[#21262d] rounded-lg px-1 py-1 flex items-center gap-1' : 'flex items-center gap-1 bg-white rounded-lg border border-gray-200 shadow-sm px-1 py-1';
    const ctrlBtn   = isDark ? 'p-1.5 rounded hover:bg-[#21262d] text-[#7d8590] transition' : 'p-1.5 rounded hover:bg-gray-100 text-gray-500 transition';
    const dateInput = isDark ? 'border-none text-sm font-medium text-[#e6edf3] focus:ring-0 p-0 bg-transparent cursor-pointer' : 'border-none text-sm font-medium text-gray-800 focus:ring-0 p-0 bg-transparent cursor-pointer';
    const searchBox = isDark ? 'border-[#30363d] bg-[#0d1117] text-[#e6edf3] placeholder:text-[#7d8590] rounded-lg text-sm focus:border-blue-500 focus:ring-blue-500 w-56 pl-8' : 'border-gray-200 rounded-lg text-sm shadow-sm focus:border-blue-400 focus:ring-blue-400 w-56 pl-8';
    const selectCls = isDark ? 'border-[#30363d] bg-[#0d1117] text-[#7d8590] rounded-lg text-sm focus:border-blue-500 focus:ring-blue-500' : 'border-gray-200 rounded-lg text-sm shadow-sm focus:border-blue-400 focus:ring-blue-400 text-gray-600';
    const filterBtn = isDark ? 'px-3.5 py-2 text-sm font-medium text-[#e6edf3] bg-[#21262d] hover:bg-[#30363d] rounded-lg transition' : 'px-3.5 py-2 text-sm font-medium text-white bg-gray-700 hover:bg-gray-800 rounded-lg transition shadow-sm';
    const clearBtn  = isDark ? 'text-xs text-[#7d8590] hover:text-[#e6edf3] transition flex items-center gap-1' : 'text-xs text-gray-400 hover:text-gray-600 transition flex items-center gap-1';
    const iconSearch= isDark ? 'absolute left-2.5 top-1/2 -translate-y-1/2 text-[#7d8590] pointer-events-none' : 'absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none';
    const hojeTag   = isDark ? 'text-xs font-medium bg-blue-900/40 text-blue-300 px-2.5 py-1 rounded-md ring-1 ring-blue-500/30' : 'text-xs font-medium bg-blue-50 text-blue-600 px-2.5 py-1 rounded-md ring-1 ring-blue-200';
    const warnRow   = isDark ? 'flex items-center gap-2 bg-amber-900/20 border border-amber-700/40 text-amber-400 text-sm px-4 py-2.5 rounded-lg' : 'flex items-center gap-2 bg-amber-50 border border-amber-200 text-amber-700 text-sm px-4 py-2.5 rounded-lg';
    const textAtend  = isDark ? 'text-[#7d8590]' : 'text-gray-400';
    const lineThru   = isDark ? 'line-through text-[#7d8590]' : 'line-through text-gray-400';

    return (
        <AuthenticatedLayout header={null}>
            <Head title="Requisições" />

            <Modal aberto={modalNova} onFechar={() => setModalNova(false)} titulo="Nova requisição" isDark={isDark}>
                <FormRequisicao fornecedores={fornecedores} opcoes={opcoes} onSubmit={criar}
                    onCancelar={() => setModalNova(false)} carregando={submetendo} erros={erros}
                    labelSubmit="Criar requisição" isDark={isDark} />
            </Modal>

            <Modal aberto={!!modalEditar} onFechar={() => setModalEditar(null)} titulo="Editar requisição" isDark={isDark}>
                {modalEditar && (
                    <FormRequisicao fornecedores={fornecedores} opcoes={opcoes} inicial={modalEditar}
                        onSubmit={salvarEdicao} onCancelar={() => setModalEditar(null)}
                        carregando={submetendo} erros={erros} labelSubmit="Salvar alterações" isDark={isDark} />
                )}
            </Modal>

            <div className={pagePad}>

                {/* Barra de controles */}
                <div className="flex flex-wrap items-center gap-2.5">
                    <div className={ctrlBg}>
                        <button onClick={diaAnterior} className={ctrlBtn} title="Dia anterior">
                            <Icone path="M15 19l-7-7 7-7" />
                        </button>
                        <input type="date" value={dataFiltro} onChange={e => mudarData(e.target.value)}
                            className={dateInput} />
                        <button onClick={diaSeguinte} disabled={isHoje}
                            className={`${ctrlBtn} disabled:opacity-30`} title="Próximo dia">
                            <Icone path="M9 5l7 7-7 7" />
                        </button>
                    </div>

                    {isHoje && <span className={hojeTag}>Hoje</span>}

                    <div className="relative">
                        <input type="search" placeholder="Buscar nota ou fornecedor..."
                            value={buscaLocal} onChange={e => setBuscaLocal(e.target.value)}
                            onKeyDown={e => e.key === 'Enter' && aplicarFiltros()}
                            className={searchBox} />
                        <span className={iconSearch}><Icone path="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></span>
                    </div>

                    <select value={motivoLocal} onChange={e => setMotivoLocal(e.target.value)} className={selectCls}>
                        <option value="">Todos os motivos</option>
                        {opcoes.motivos.map(m => <option key={m} value={m}>{m}</option>)}
                    </select>

                    <select value={lojaLocal} onChange={e => setLojaLocal(e.target.value)} className={selectCls}>
                        <option value="">Todas as lojas</option>
                        {opcoes.lojas.map(l => <option key={l} value={l}>{lojaNome(l)}</option>)}
                    </select>

                    <button onClick={aplicarFiltros} className={filterBtn}>Filtrar</button>

                    {filtrosAtivos && (
                        <button onClick={limparFiltros} className={clearBtn}>
                            <Icone path="M6 18L18 6M6 6l12 12" className="w-3 h-3" /> Limpar
                        </button>
                    )}

                    <button onClick={() => setModalNova(true)}
                        className="ml-auto flex items-center gap-1.5 px-4 py-2 text-sm font-medium bg-blue-600 hover:bg-blue-700 text-white rounded-lg shadow-sm transition">
                        <Icone path="M12 4v16m8-8H4" /> Nova requisição
                    </button>
                </div>

                {/* Aviso atrasadas */}
                {qtdAtrasadas > 0 && (
                    <div className={warnRow}>
                        <Icone path="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" className="w-4 h-4 shrink-0" />
                        <span><strong>{qtdAtrasadas}</strong> requisição{qtdAtrasadas !== 1 ? 'ões' : ''} pendente{qtdAtrasadas !== 1 ? 's' : ''} de dias anteriores.</span>
                    </div>
                )}

                {/* Tabela Pendentes */}
                <div className={card}>
                    <div className={cardHead}>
                        <h2 className={headTitle}>
                            Pendentes <span className={badge0}>{pendentes.length}</span>
                        </h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full">
                            <THead colunas={COLS_PENDENTES} isDark={isDark} />
                            <tbody className={divRow}>
                                {pendentes.length === 0 ? (
                                    <tr><td colSpan={7} className={`px-4 py-12 text-center text-sm ${emptyCell}`}>Nenhuma requisição pendente para esta data.</td></tr>
                                ) : (
                                    pendentes.map(req => (
                                        <LinhaPendente key={req.id} req={req} onEditar={setModalEditar}
                                            onAtender={atender} onExcluir={excluir}
                                            carregando={atendendoId === req.id} isDark={isDark} />
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* Tabela Atendidas */}
                <div className={card}>
                    <div className={cardHead}>
                        <h2 className={headSub}>
                            Atendidas neste dia <span className={badgeGreen}>{atendidas.length}</span>
                        </h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full">
                            <THead colunas={COLS_ATENDIDAS} isDark={isDark} />
                            <tbody className={divRow}>
                                {atendidas.length === 0 ? (
                                    <tr><td colSpan={6} className={`px-4 py-10 text-center text-sm ${emptyCell}`}>Nenhuma requisição atendida neste dia.</td></tr>
                                ) : (
                                    atendidas.map(req => (
                                        <tr key={req.id} className="opacity-60">
                                            <td className={`px-4 py-3 text-sm ${lineThru}`}>{req.numero_nota}</td>
                                            <td className={`px-4 py-3 text-sm max-w-[180px] truncate ${textAtend}`}>{req.fornecedor.nome}</td>
                                            <td className="px-4 py-3"><Badge label={req.motivo} isDark={isDark} /></td>
                                            <td className={`px-4 py-3 text-sm whitespace-nowrap ${textAtend}`}>{lojaNome(req.loja)}</td>
                                            <td className={`px-4 py-3 text-sm max-w-[200px] truncate ${textAtend}`}>{req.observacao || '—'}</td>
                                            <td className={`px-4 py-3 text-sm ${textAtend}`}>{req.user.name.split(' ')[0]}</td>
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
