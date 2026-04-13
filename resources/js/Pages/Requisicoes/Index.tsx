import React, { useState, useMemo, useEffect, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { format, parseISO, addDays, subDays } from 'date-fns';
import { ptBR } from 'date-fns/locale';
import { Requisicao, Fornecedor, FiltrosAtivos, OpcoesSistema } from '@/types';

// ─── Tipos ────────────────────────────────────────────────────────────────────

interface Props {
    auth:         { user: { id: number; name: string; email: string } };
    pendentes:    Requisicao[];
    atendidas:    Requisicao[];
    fornecedores: Fornecedor[];
    dataFiltro:   string;
    filtros:      FiltrosAtivos;
    opcoes:       OpcoesSistema;
}

// ─── Helpers visuais ──────────────────────────────────────────────────────────

const MOTIVO_COR: Record<string, string> = {
    Cadastro:   'bg-blue-50 text-blue-700 ring-blue-600/20',
    Preço:      'bg-yellow-50 text-yellow-700 ring-yellow-600/20',
    Regra:      'bg-red-50 text-red-700 ring-red-600/20',
    Quantidade: 'bg-purple-50 text-purple-700 ring-purple-600/20',
};

const lojaNome = (n: number) => `Loja ${String(n).padStart(2, '0')}`;
const hoje = () => format(new Date(), 'yyyy-MM-dd');

// ─── Componentes utilitários ──────────────────────────────────────────────────

function Badge({ label, className }: { label: string; className: string }) {
    return (
        <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${className}`}>
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

// ─── Modal ────────────────────────────────────────────────────────────────────

function Modal({ aberto, onFechar, titulo, children }: {
    aberto: boolean;
    onFechar: () => void;
    titulo: string;
    children: React.ReactNode;
}) {
    // Fechar com ESC
    useEffect(() => {
        if (!aberto) return;
        const fn = (e: KeyboardEvent) => { if (e.key === 'Escape') onFechar(); };
        window.addEventListener('keydown', fn);
        return () => window.removeEventListener('keydown', fn);
    }, [aberto, onFechar]);

    if (!aberto) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div className="absolute inset-0 bg-black/30 backdrop-blur-sm" onClick={onFechar} />
            <div className="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg">
                <div className="flex items-center justify-between px-6 pt-5 pb-4 border-b border-gray-100">
                    <h3 className="text-sm font-semibold text-gray-900">{titulo}</h3>
                    <button onClick={onFechar} className="text-gray-400 hover:text-gray-600 transition p-0.5 rounded">
                        <Icone path="M6 18L18 6M6 6l12 12" />
                    </button>
                </div>
                <div className="px-6 py-5">{children}</div>
            </div>
        </div>
    );
}

// ─── Autocomplete de Fornecedor ───────────────────────────────────────────────

function CampoFornecedor({ fornecedores, valor, onChange, erro }: {
    fornecedores: Fornecedor[];
    valor: { id: number | ''; nome: string };
    onChange: (f: { id: number | ''; nome: string }) => void;
    erro?: string;
}) {
    const [busca, setBusca]           = useState(valor.nome);
    const [aberto, setAberto]         = useState(false);
    const ref                         = useRef<HTMLDivElement>(null);

    const opcoes = useMemo(() =>
        fornecedores.filter(f => f.nome.toLowerCase().includes(busca.toLowerCase())).slice(0, 12),
        [fornecedores, busca]
    );

    // Fechar ao clicar fora
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

    return (
        <div ref={ref} className="relative">
            <input
                type="text"
                value={busca}
                onChange={e => {
                    setBusca(e.target.value);
                    onChange({ id: '', nome: e.target.value });
                    setAberto(true);
                }}
                onFocus={() => setAberto(true)}
                placeholder="Buscar fornecedor..."
                autoComplete="off"
                className={`block w-full rounded-lg text-sm shadow-sm ${
                    erro ? 'border-red-300 focus:border-red-500 focus:ring-red-500' : 'border-gray-200 focus:border-blue-400 focus:ring-blue-400'
                }`}
            />
            {aberto && opcoes.length > 0 && (
                <ul className="absolute z-20 mt-1 w-full bg-white border border-gray-100 rounded-xl shadow-lg max-h-52 overflow-y-auto">
                    {opcoes.map(f => (
                        <li key={f.id}>
                            <button
                                type="button"
                                onMouseDown={() => selecionar(f)}
                                className="w-full text-left px-3.5 py-2 text-sm hover:bg-blue-50 hover:text-blue-700 transition"
                            >
                                {f.nome}
                            </button>
                        </li>
                    ))}
                </ul>
            )}
            {erro && <p className="text-xs text-red-500 mt-1">{erro}</p>}
        </div>
    );
}

// ─── Formulário de Requisição ─────────────────────────────────────────────────

interface DadosForm {
    numero_nota:   string;
    fornecedor_id: number | '';
    fornecedor:    { id: number | ''; nome: string };
    loja:          number | '';
    motivo:        string;
    observacao:    string;
}

function FormRequisicao({ fornecedores, opcoes, inicial, onSubmit, onCancelar, carregando, erros, labelSubmit }: {
    fornecedores: Fornecedor[];
    opcoes:       OpcoesSistema;
    inicial?:     Requisicao;
    onSubmit:     (d: Omit<DadosForm, 'fornecedor'>) => void;
    onCancelar:   () => void;
    carregando:   boolean;
    erros:        Record<string, string>;
    labelSubmit:  string;
}) {
    const [form, setForm] = useState<DadosForm>({
        numero_nota:   inicial?.numero_nota   ?? '',
        fornecedor_id: inicial?.fornecedor?.id ?? '',
        fornecedor:    { id: inicial?.fornecedor?.id ?? '', nome: inicial?.fornecedor?.nome ?? '' },
        loja:          inicial?.loja           ?? '',
        motivo:        inicial?.motivo         ?? '',
        observacao:    inicial?.observacao     ?? '',
    });

    const set = <K extends keyof DadosForm>(k: K, v: DadosForm[K]) =>
        setForm(p => ({ ...p, [k]: v }));

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        onSubmit({
            numero_nota:   form.numero_nota,
            fornecedor_id: form.fornecedor.id,
            loja:          form.loja,
            motivo:        form.motivo,
            observacao:    form.observacao,
        });
    };

    const campo = (label: string, obrigatorio: boolean, children: React.ReactNode, erro?: string) => (
        <div>
            <label className="block text-sm font-medium text-gray-700 mb-1.5">
                {label}{obrigatorio && <span className="text-red-400 ml-0.5">*</span>}
            </label>
            {children}
            {erro && <p className="text-xs text-red-500 mt-1">{erro}</p>}
        </div>
    );

    const inputClass = (err?: string) =>
        `block w-full rounded-lg text-sm shadow-sm ${
            err ? 'border-red-300 focus:border-red-500 focus:ring-red-500'
                : 'border-gray-200 focus:border-blue-400 focus:ring-blue-400'
        }`;

    return (
        <form onSubmit={handleSubmit} className="space-y-4">

            {campo('Número da nota', true,
                <input
                    type="text"
                    value={form.numero_nota}
                    onChange={e => set('numero_nota', e.target.value)}
                    placeholder="Ex: 46252"
                    className={inputClass(erros.numero_nota)}
                />, erros.numero_nota
            )}

            {campo('Fornecedor', true,
                <CampoFornecedor
                    fornecedores={fornecedores}
                    valor={form.fornecedor}
                    onChange={v => setForm(p => ({ ...p, fornecedor: v, fornecedor_id: v.id }))}
                    erro={erros.fornecedor_id}
                />
            )}

            <div className="grid grid-cols-2 gap-3">
                {campo('Loja', true,
                    <select
                        value={form.loja}
                        onChange={e => set('loja', Number(e.target.value) || '')}
                        className={inputClass(erros.loja)}
                    >
                        <option value="">Selecionar...</option>
                        {opcoes.lojas.map(l => <option key={l} value={l}>{lojaNome(l)}</option>)}
                    </select>, erros.loja
                )}
                {campo('Motivo', true,
                    <select
                        value={form.motivo}
                        onChange={e => set('motivo', e.target.value)}
                        className={inputClass(erros.motivo)}
                    >
                        <option value="">Selecionar...</option>
                        {opcoes.motivos.map(m => <option key={m} value={m}>{m}</option>)}
                    </select>, erros.motivo
                )}
            </div>

            {campo('Observação', false,
                <textarea
                    value={form.observacao}
                    onChange={e => set('observacao', e.target.value)}
                    rows={3}
                    placeholder="Detalhes adicionais..."
                    className={`${inputClass()} resize-none`}
                />
            )}

            <div className="flex justify-end gap-3 pt-1 border-t border-gray-100 mt-2">
                <button
                    type="button"
                    onClick={onCancelar}
                    className="px-4 py-2 text-sm text-gray-500 hover:text-gray-800 transition"
                >
                    Cancelar
                </button>
                <button
                    type="submit"
                    disabled={carregando}
                    className="px-5 py-2 text-sm font-medium bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white rounded-lg transition"
                >
                    {carregando ? 'Salvando...' : labelSubmit}
                </button>
            </div>
        </form>
    );
}

// ─── Cabeçalho da tabela ──────────────────────────────────────────────────────

function THead({ colunas }: { colunas: string[] }) {
    return (
        <thead>
            <tr className="border-b border-gray-100">
                {colunas.map(c => (
                    <th
                        key={c}
                        className="px-4 py-2.5 text-left text-xs font-semibold text-gray-400 uppercase tracking-wide whitespace-nowrap"
                    >
                        {c}
                    </th>
                ))}
            </tr>
        </thead>
    );
}

// ─── Linha da tabela de Pendentes ─────────────────────────────────────────────

function LinhaPendente({ req, onEditar, onAtender, onExcluir, carregando }: {
    req:         Requisicao;
    onEditar:    (r: Requisicao) => void;
    onAtender:   (id: number) => void;
    onExcluir:   (id: number) => void;
    carregando:  boolean;
}) {
    return (
        <tr className={`group hover:bg-gray-50/70 transition-colors ${req.atrasada ? 'bg-amber-50/40' : ''}`}>

            {/* Nota */}
            <td className="px-4 py-3 text-sm">
                <span className="font-medium text-gray-900">{req.numero_nota}</span>
                {req.atrasada && (
                    <span className="ml-2 inline-flex items-center gap-0.5 text-xs font-medium text-amber-600">
                        ⚠ {req.data_origem}
                    </span>
                )}
            </td>

            {/* Fornecedor */}
            <td className="px-4 py-3 text-sm text-gray-700 max-w-[180px] truncate">
                {req.fornecedor.nome}
            </td>

            {/* Motivo */}
            <td className="px-4 py-3">
                <Badge label={req.motivo} className={MOTIVO_COR[req.motivo] ?? 'bg-gray-50 text-gray-600 ring-gray-500/20'} />
            </td>

            {/* Loja */}
            <td className="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                {lojaNome(req.loja)}
            </td>

            {/* Observação */}
            <td className="px-4 py-3 text-sm text-gray-400 max-w-[200px] truncate" title={req.observacao ?? ''}>
                {req.observacao || <span className="text-gray-200">—</span>}
            </td>

            {/* Solicitado por */}
            <td className="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                {req.user.name.split(' ')[0]}
            </td>

            {/* Ações */}
            <td className="px-4 py-3 text-right">
                <div className="flex items-center justify-end gap-0.5 opacity-0 group-hover:opacity-100 transition-opacity">
                    {/* Atender */}
                    <button
                        onClick={() => onAtender(req.id)}
                        disabled={carregando}
                        title="Marcar como atendida"
                        className="p-1.5 rounded-lg text-green-600 hover:bg-green-50 disabled:opacity-40 transition"
                    >
                        <Icone path="M5 13l4 4L19 7" />
                    </button>
                    {/* Editar */}
                    <button
                        onClick={() => onEditar(req)}
                        title="Editar"
                        className="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50 transition"
                    >
                        <Icone path="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </button>
                    {/* Excluir */}
                    <button
                        onClick={() => onExcluir(req.id)}
                        title="Excluir"
                        className="p-1.5 rounded-lg text-red-400 hover:bg-red-50 transition"
                    >
                        <Icone path="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </button>
                </div>
            </td>
        </tr>
    );
}

// ─── Página principal ─────────────────────────────────────────────────────────

export default function Index({ pendentes, atendidas, fornecedores, dataFiltro, filtros, opcoes }: Props) {

    // ── Modais
    const [modalNova,    setModalNova]    = useState(false);
    const [modalEditar,  setModalEditar]  = useState<Requisicao | null>(null);
    const [erros,        setErros]        = useState<Record<string, string>>({});
    const [submetendo,   setSubmetendo]   = useState(false);
    const [atendendoId,  setAtendendoId]  = useState<number | null>(null);

    // ── Filtros locais (evita reload a cada keystroke)
    const [buscaLocal,  setBuscaLocal]  = useState(filtros.busca  ?? '');
    const [motivoLocal, setMotivoLocal] = useState(filtros.motivo ?? '');
    const [lojaLocal,   setLojaLocal]   = useState(filtros.loja ? String(filtros.loja) : '');

    const isHoje = dataFiltro === hoje();

    // ── Navegação
    const irPara = (extras: Record<string, unknown> = {}) =>
        router.get(route('requisicoes.index'),
            { data: dataFiltro, busca: buscaLocal || undefined, motivo: motivoLocal || undefined, loja: lojaLocal || undefined, ...extras },
            { preserveState: true, replace: true }
        );

    const mudarData = (d: string) =>
        router.get(route('requisicoes.index'),
            { data: d, busca: buscaLocal || undefined, motivo: motivoLocal || undefined, loja: lojaLocal || undefined },
            { preserveState: true, replace: true }
        );

    const diaAnterior = () => mudarData(format(subDays(parseISO(dataFiltro), 1), 'yyyy-MM-dd'));
    const diaSeguinte = () => mudarData(format(addDays(parseISO(dataFiltro), 1), 'yyyy-MM-dd'));

    const aplicarFiltros = () => irPara();
    const limparFiltros  = () => {
        setBuscaLocal(''); setMotivoLocal(''); setLojaLocal('');
        router.get(route('requisicoes.index'), { data: dataFiltro }, { preserveState: true, replace: true });
    };

    const filtrosAtivos = !!(filtros.busca || filtros.motivo || filtros.loja);

    // ── CRUD
    const criar = (dados: any) => {
        setSubmetendo(true);
        router.post(route('requisicoes.store'), dados, {
            onSuccess: () => { setModalNova(false); setErros({}); },
            onError:   e  => setErros(e),
            onFinish:  () => setSubmetendo(false),
        });
    };

    const salvarEdicao = (dados: any) => {
        if (!modalEditar) return;
        setSubmetendo(true);
        router.patch(route('requisicoes.update', modalEditar.id), dados, {
            onSuccess: () => { setModalEditar(null); setErros({}); },
            onError:   e  => setErros(e),
            onFinish:  () => setSubmetendo(false),
        });
    };

    const atender = (id: number) => {
        setAtendendoId(id);
        router.patch(route('requisicoes.update', id), { status: 'Atendida' }, {
            onFinish: () => setAtendendoId(null),
        });
    };

    const excluir = (id: number) => {
        if (!confirm('Excluir esta requisição? Esta ação pode ser revertida pelo administrador.')) return;
        router.delete(route('requisicoes.destroy', id));
    };

    const qtdAtrasadas = pendentes.filter(r => r.atrasada).length;

    const COLS_PENDENTES  = ['Nota', 'Fornecedor', 'Motivo', 'Loja', 'Observação', 'Solicitado', ''];
    const COLS_ATENDIDAS  = ['Nota', 'Fornecedor', 'Motivo', 'Loja', 'Observação', 'Atendida por'];

    return (
        <AuthenticatedLayout header={null}>
            <Head title="Requisições" />

            {/* ─── Modal: Nova requisição ─────────────────────────────────────── */}
            <Modal aberto={modalNova} onFechar={() => setModalNova(false)} titulo="Nova requisição">
                <FormRequisicao
                    fornecedores={fornecedores}
                    opcoes={opcoes}
                    onSubmit={criar}
                    onCancelar={() => setModalNova(false)}
                    carregando={submetendo}
                    erros={erros}
                    labelSubmit="Criar requisição"
                />
            </Modal>

            {/* ─── Modal: Editar requisição ───────────────────────────────────── */}
            <Modal aberto={!!modalEditar} onFechar={() => setModalEditar(null)} titulo="Editar requisição">
                {modalEditar && (
                    <FormRequisicao
                        fornecedores={fornecedores}
                        opcoes={opcoes}
                        inicial={modalEditar}
                        onSubmit={salvarEdicao}
                        onCancelar={() => setModalEditar(null)}
                        carregando={submetendo}
                        erros={erros}
                        labelSubmit="Salvar alterações"
                    />
                )}
            </Modal>

            <div className="py-6 px-4 sm:px-6 lg:px-8 max-w-screen-2xl mx-auto space-y-4">

                {/* ─── Barra de controles ─────────────────────────────────────── */}
                <div className="flex flex-wrap items-center gap-2.5">

                    {/* Navegação de data */}
                    <div className="flex items-center gap-1 bg-white rounded-lg border border-gray-200 shadow-sm px-1 py-1">
                        <button
                            onClick={diaAnterior}
                            className="p-1.5 rounded hover:bg-gray-100 text-gray-500 transition"
                            title="Dia anterior"
                        >
                            <Icone path="M15 19l-7-7 7-7" />
                        </button>
                        <input
                            type="date"
                            value={dataFiltro}
                            onChange={e => mudarData(e.target.value)}
                            className="border-none text-sm font-medium text-gray-800 focus:ring-0 p-0 bg-transparent cursor-pointer"
                        />
                        <button
                            onClick={diaSeguinte}
                            disabled={isHoje}
                            className="p-1.5 rounded hover:bg-gray-100 disabled:opacity-30 text-gray-500 transition"
                            title="Próximo dia"
                        >
                            <Icone path="M9 5l7 7-7 7" />
                        </button>
                    </div>

                    {isHoje && (
                        <span className="text-xs font-medium bg-blue-50 text-blue-600 px-2.5 py-1 rounded-md ring-1 ring-blue-200">
                            Hoje
                        </span>
                    )}

                    {/* Busca */}
                    <div className="relative">
                        <input
                            type="search"
                            placeholder="Buscar nota ou fornecedor..."
                            value={buscaLocal}
                            onChange={e => setBuscaLocal(e.target.value)}
                            onKeyDown={e => e.key === 'Enter' && aplicarFiltros()}
                            className="border-gray-200 rounded-lg text-sm shadow-sm focus:border-blue-400 focus:ring-blue-400 w-56 pl-8"
                        />
                        <span className="absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 pointer-events-none">
                            <Icone path="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                        </span>
                    </div>

                    {/* Filtro por motivo */}
                    <select
                        value={motivoLocal}
                        onChange={e => setMotivoLocal(e.target.value)}
                        className="border-gray-200 rounded-lg text-sm shadow-sm focus:border-blue-400 focus:ring-blue-400 text-gray-600"
                    >
                        <option value="">Todos os motivos</option>
                        {opcoes.motivos.map(m => <option key={m} value={m}>{m}</option>)}
                    </select>

                    {/* Filtro por loja */}
                    <select
                        value={lojaLocal}
                        onChange={e => setLojaLocal(e.target.value)}
                        className="border-gray-200 rounded-lg text-sm shadow-sm focus:border-blue-400 focus:ring-blue-400 text-gray-600"
                    >
                        <option value="">Todas as lojas</option>
                        {opcoes.lojas.map(l => <option key={l} value={l}>{lojaNome(l)}</option>)}
                    </select>

                    {/* Botão filtrar */}
                    <button
                        onClick={aplicarFiltros}
                        className="px-3.5 py-2 text-sm font-medium text-white bg-gray-700 hover:bg-gray-800 rounded-lg transition shadow-sm"
                    >
                        Filtrar
                    </button>

                    {/* Limpar filtros */}
                    {filtrosAtivos && (
                        <button
                            onClick={limparFiltros}
                            className="text-xs text-gray-400 hover:text-gray-600 transition flex items-center gap-1"
                        >
                            <Icone path="M6 18L18 6M6 6l12 12" className="w-3 h-3" />
                            Limpar
                        </button>
                    )}

                    {/* Nova requisição — empurrado para a direita */}
                    <button
                        onClick={() => setModalNova(true)}
                        className="ml-auto flex items-center gap-1.5 px-4 py-2 text-sm font-medium bg-blue-600 hover:bg-blue-700 text-white rounded-lg shadow-sm transition"
                    >
                        <Icone path="M12 4v16m8-8H4" />
                        Nova requisição
                    </button>
                </div>

                {/* ─── Aviso de atrasadas ─────────────────────────────────────── */}
                {qtdAtrasadas > 0 && (
                    <div className="flex items-center gap-2 bg-amber-50 border border-amber-200 text-amber-700 text-sm px-4 py-2.5 rounded-lg">
                        <Icone path="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z" className="w-4 h-4 shrink-0" />
                        <span>
                            <strong>{qtdAtrasadas}</strong> requisição{qtdAtrasadas !== 1 ? 'ões' : ''} pendente{qtdAtrasadas !== 1 ? 's' : ''} de dias anteriores.
                        </span>
                    </div>
                )}

                {/* ─── Tabela: Pendentes ──────────────────────────────────────── */}
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <div className="flex items-center justify-between px-5 py-3 border-b border-gray-100">
                        <h2 className="text-sm font-semibold text-gray-800 flex items-center gap-2">
                            Pendentes
                            <span className="bg-gray-100 text-gray-600 text-xs font-medium px-2 py-0.5 rounded-full">
                                {pendentes.length}
                            </span>
                        </h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full">
                            <THead colunas={COLS_PENDENTES} />
                            <tbody className="divide-y divide-gray-50">
                                {pendentes.length === 0 ? (
                                    <tr>
                                        <td colSpan={7} className="px-4 py-12 text-center text-sm text-gray-400">
                                            Nenhuma requisição pendente para esta data.
                                        </td>
                                    </tr>
                                ) : (
                                    pendentes.map(req => (
                                        <LinhaPendente
                                            key={req.id}
                                            req={req}
                                            onEditar={setModalEditar}
                                            onAtender={atender}
                                            onExcluir={excluir}
                                            carregando={atendendoId === req.id}
                                        />
                                    ))
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* ─── Tabela: Atendidas ──────────────────────────────────────── */}
                <div className="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
                    <div className="flex items-center justify-between px-5 py-3 border-b border-gray-100">
                        <h2 className="text-sm font-semibold text-gray-500 flex items-center gap-2">
                            Atendidas neste dia
                            <span className="bg-green-50 text-green-600 text-xs font-medium px-2 py-0.5 rounded-full">
                                {atendidas.length}
                            </span>
                        </h2>
                    </div>
                    <div className="overflow-x-auto">
                        <table className="min-w-full">
                            <THead colunas={COLS_ATENDIDAS} />
                            <tbody className="divide-y divide-gray-50">
                                {atendidas.length === 0 ? (
                                    <tr>
                                        <td colSpan={6} className="px-4 py-10 text-center text-sm text-gray-400">
                                            Nenhuma requisição atendida neste dia.
                                        </td>
                                    </tr>
                                ) : (
                                    atendidas.map(req => (
                                        <tr key={req.id} className="opacity-60">
                                            <td className="px-4 py-3 text-sm text-gray-400 line-through">{req.numero_nota}</td>
                                            <td className="px-4 py-3 text-sm text-gray-500 max-w-[180px] truncate">{req.fornecedor.nome}</td>
                                            <td className="px-4 py-3">
                                                <Badge label={req.motivo} className={MOTIVO_COR[req.motivo] ?? 'bg-gray-50 text-gray-400 ring-gray-500/20'} />
                                            </td>
                                            <td className="px-4 py-3 text-sm text-gray-400 whitespace-nowrap">{lojaNome(req.loja)}</td>
                                            <td className="px-4 py-3 text-sm text-gray-400 max-w-[200px] truncate">{req.observacao || '—'}</td>
                                            <td className="px-4 py-3 text-sm text-gray-400">{req.user.name.split(' ')[0]}</td>
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
