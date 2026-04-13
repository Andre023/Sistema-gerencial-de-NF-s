import React, { useState, useMemo } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';

// ─── Tipos ────────────────────────────────────────────────────────────────────

interface Kpis {
    total: number;
    atendidas: number;
    pendentes: number;
    resolvidasNoDia: number;
    taxaResolucao: number;
    tempoMedioHoras: number | null;
}

interface DiaEvol {
    dia: string;
    total: number;
    atendidas: number;
    pendentes: number;
}

interface PorMotivo   { motivo: string; total: number; atendidas: number }
interface PorLoja     { loja: number;   total: number; atendidas: number }
interface PorDia      { dia: string;    total: number }
interface PorHora     { hora: string;   total: number }
interface TopForn     { fornecedor: string; total: number; atendidas: number }
interface FornMotivo  { fornecedor: string; total: number }
interface Reincidente { fornecedor: string; total: number; dias_distintos: number }
interface RankUser    { usuario: string; total: number; atendidas: number }
interface PendAntiga  {
    id: number; numero_nota: string; fornecedor: string;
    motivo: string; loja: number; dias_aberta: number; created_at: string;
}

interface Props {
    periodo: number;
    kpis: Kpis;
    evolucaoDiaria: DiaEvol[];
    porMotivo: PorMotivo[];
    porLoja: PorLoja[];
    porDiaSemana: PorDia[];
    porHora: PorHora[];
    topFornecedores: TopForn[];
    fornecedoresPorMotivo: Record<string, FornMotivo[]>;
    reincidentes: Reincidente[];
    rankingUsuarios: RankUser[];
    pendentesMaisAntigas: PendAntiga[];
}

// ─── Constantes visuais ───────────────────────────────────────────────────────

const MOTIVO_COR: Record<string, string> = {
    Cadastro:   '#3b82f6',
    Preço:      '#f59e0b',
    Regra:      '#ef4444',
    Quantidade: '#8b5cf6',
    Pedido:     '#10b981',
};

const MOTIVO_BG: Record<string, string> = {
    Cadastro:   'bg-blue-50 text-blue-700 ring-blue-600/20',
    Preço:      'bg-yellow-50 text-yellow-700 ring-yellow-600/20',
    Regra:      'bg-red-50 text-red-700 ring-red-600/20',
    Quantidade: 'bg-purple-50 text-purple-700 ring-purple-600/20',
    Pedido:     'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
};

const LOJA_CORES = [
    '#3b82f6','#f59e0b','#10b981','#ef4444','#8b5cf6','#ec4899','#06b6d4',
];

// ─── Helpers ──────────────────────────────────────────────────────────────────

const lojaNome = (n: number) => `Loja ${String(n).padStart(2, '0')}`;
const fmtDia   = (s: string) => s.slice(5).replace('-', '/');   // "2026-04-13" → "13/04"
const pct      = (v: number, t: number) => t > 0 ? Math.round((v / t) * 100) : 0;

// ─── Componentes de UI ────────────────────────────────────────────────────────

function Card({ title, children, className = '' }: { title: string; children: React.ReactNode; className?: string }) {
    return (
        <div className={`bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden ${className}`}>
            <div className="px-5 py-3.5 border-b border-gray-100">
                <h3 className="text-sm font-semibold text-gray-800">{title}</h3>
            </div>
            <div className="p-5">{children}</div>
        </div>
    );
}

function Badge({ label, className }: { label: string; className: string }) {
    return (
        <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${className}`}>
            {label}
        </span>
    );
}

// ─── Gráfico de barras horizontal ─────────────────────────────────────────────

function BarrasH({ items, cor = '#3b82f6', max }: {
    items: { label: string; valor: number; cor?: string }[];
    cor?: string;
    max?: number;
}) {
    const maximo = max ?? Math.max(...items.map(i => i.valor), 1);
    return (
        <div className="space-y-2.5">
            {items.map((item, i) => (
                <div key={i} className="flex items-center gap-3">
                    <span className="text-xs text-gray-500 w-28 truncate shrink-0 text-right" title={item.label}>
                        {item.label}
                    </span>
                    <div className="flex-1 bg-gray-100 rounded-full h-5 overflow-hidden">
                        <div
                            className="h-5 rounded-full flex items-center justify-end pr-2 transition-all duration-500"
                            style={{
                                width: `${Math.max((item.valor / maximo) * 100, 3)}%`,
                                backgroundColor: item.cor ?? cor,
                            }}
                        >
                            <span className="text-white text-xs font-semibold leading-none">{item.valor}</span>
                        </div>
                    </div>
                </div>
            ))}
            {items.length === 0 && (
                <p className="text-sm text-gray-400 text-center py-4">Sem dados no período.</p>
            )}
        </div>
    );
}

// ─── Gráfico de barras vertical ───────────────────────────────────────────────

function BarrasV({ items, altura = 160, cor = '#3b82f6' }: {
    items: { label: string; valor: number; cor?: string }[];
    altura?: number;
    cor?: string;
}) {
    const maximo = Math.max(...items.map(i => i.valor), 1);
    return (
        <div className="flex items-end gap-1 overflow-x-auto pb-2" style={{ height: altura + 32 }}>
            {items.map((item, i) => {
                const h = Math.max((item.valor / maximo) * altura, item.valor > 0 ? 4 : 0);
                return (
                    <div key={i} className="flex flex-col items-center gap-1 min-w-[28px] flex-1 group">
                        <span className="text-xs text-gray-500 opacity-0 group-hover:opacity-100 transition whitespace-nowrap bg-gray-800 text-white px-1.5 py-0.5 rounded text-[10px]">
                            {item.valor}
                        </span>
                        <div
                            className="w-full rounded-t transition-all duration-300"
                            style={{ height: h, backgroundColor: item.cor ?? cor, minWidth: 8 }}
                        />
                        <span className="text-[10px] text-gray-400 whitespace-nowrap">{item.label}</span>
                    </div>
                );
            })}
            {items.length === 0 && (
                <p className="text-sm text-gray-400 text-center py-4 w-full">Sem dados no período.</p>
            )}
        </div>
    );
}

// ─── Gráfico de linha simples ─────────────────────────────────────────────────

function Linha({ dados, cor = '#3b82f6', altura = 120 }: {
    dados: { label: string; valor: number }[];
    cor?: string;
    altura?: number;
}) {
    const w = 800;
    const h = altura;
    const pad = { t: 10, r: 10, b: 24, l: 30 };
    const innerW = w - pad.l - pad.r;
    const innerH = h - pad.t - pad.b;
    const maximo = Math.max(...dados.map(d => d.valor), 1);

    if (dados.length < 2) return (
        <p className="text-sm text-gray-400 text-center py-4">Dados insuficientes.</p>
    );

    const xStep = innerW / (dados.length - 1);
    const pts = dados.map((d, i) => ({
        x: pad.l + i * xStep,
        y: pad.t + innerH - (d.valor / maximo) * innerH,
        ...d,
    }));

    const polyline = pts.map(p => `${p.x},${p.y}`).join(' ');
    const area = `${pts[0].x},${pad.t + innerH} ${polyline} ${pts[pts.length - 1].x},${pad.t + innerH}`;

    // Labels do eixo Y
    const yTicks = [0, 0.25, 0.5, 0.75, 1].map(frac => ({
        y: pad.t + innerH - frac * innerH,
        val: Math.round(frac * maximo),
    }));

    // Labels do eixo X (mostrar alguns)
    const xStep2 = Math.ceil(dados.length / 8);
    const xLabels = dados.filter((_, i) => i % xStep2 === 0 || i === dados.length - 1);

    return (
        <svg viewBox={`0 0 ${w} ${h}`} className="w-full" style={{ height: altura }}>
            {/* Grid */}
            {yTicks.map((t, i) => (
                <g key={i}>
                    <line x1={pad.l} y1={t.y} x2={w - pad.r} y2={t.y} stroke="#f0f0f0" strokeWidth="1" />
                    <text x={pad.l - 4} y={t.y + 4} textAnchor="end" fontSize="9" fill="#9ca3af">{t.val}</text>
                </g>
            ))}
            {/* Área */}
            <polygon points={area} fill={cor} opacity="0.1" />
            {/* Linha */}
            <polyline points={polyline} fill="none" stroke={cor} strokeWidth="2" strokeLinejoin="round" />
            {/* Pontos */}
            {pts.map((p, i) => (
                <circle key={i} cx={p.x} cy={p.y} r="3" fill={cor} stroke="white" strokeWidth="1.5">
                    <title>{p.label}: {p.valor}</title>
                </circle>
            ))}
            {/* X labels */}
            {xLabels.map((d, i) => {
                const idx = dados.indexOf(d);
                return (
                    <text key={i} x={pad.l + idx * xStep} y={h - 4} textAnchor="middle" fontSize="9" fill="#9ca3af">
                        {d.label}
                    </text>
                );
            })}
        </svg>
    );
}

// ─── Gráfico de rosca (donut) ─────────────────────────────────────────────────

function Donut({ itens, size = 120 }: {
    itens: { label: string; valor: number; cor: string }[];
    size?: number;
}) {
    const total = itens.reduce((s, i) => s + i.valor, 0);
    if (total === 0) return <p className="text-sm text-gray-400 text-center py-4">Sem dados.</p>;

    const r = 40; const cx = 60; const cy = 60;
    const circunf = 2 * Math.PI * r;

    let offset = 0;
    const segmentos = itens.map(item => {
        const frac = item.valor / total;
        const dasharray = `${frac * circunf} ${circunf}`;
        const seg = { ...item, dasharray, offset: offset * circunf };
        offset += frac;
        return seg;
    });

    return (
        <div className="flex items-center gap-4 flex-wrap">
            <svg viewBox="0 0 120 120" className="shrink-0" style={{ width: size, height: size }}>
                {segmentos.map((s, i) => (
                    <circle
                        key={i}
                        cx={cx} cy={cy} r={r}
                        fill="none"
                        stroke={s.cor}
                        strokeWidth="18"
                        strokeDasharray={s.dasharray}
                        strokeDashoffset={-s.offset}
                        transform="rotate(-90 60 60)"
                    >
                        <title>{s.label}: {s.valor}</title>
                    </circle>
                ))}
                <text x="60" y="56" textAnchor="middle" fontSize="14" fontWeight="bold" fill="#374151">{total}</text>
                <text x="60" y="70" textAnchor="middle" fontSize="9" fill="#9ca3af">total</text>
            </svg>
            <div className="flex flex-col gap-1.5 min-w-0">
                {itens.map((item, i) => (
                    <div key={i} className="flex items-center gap-2 text-xs text-gray-600">
                        <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ backgroundColor: item.cor }} />
                        <span className="truncate">{item.label}</span>
                        <span className="ml-auto font-semibold text-gray-800 pl-2">{item.valor}</span>
                        <span className="text-gray-400">({pct(item.valor, total)}%)</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

// ─── KPI Card ─────────────────────────────────────────────────────────────────

function KpiCard({ label, valor, sub, cor }: { label: string; valor: string | number; sub?: string; cor: string }) {
    return (
        <div className={`rounded-xl p-4 border ${cor}`}>
            <p className="text-xs font-medium text-current opacity-70 mb-1">{label}</p>
            <p className="text-2xl font-bold text-current leading-none">{valor}</p>
            {sub && <p className="text-xs text-current opacity-60 mt-1">{sub}</p>}
        </div>
    );
}

// ─── Página principal ─────────────────────────────────────────────────────────

const PERIODOS = [7, 15, 30, 60, 90];

export default function Index({
    periodo, kpis, evolucaoDiaria, porMotivo, porLoja, porDiaSemana, porHora,
    topFornecedores, fornecedoresPorMotivo, reincidentes, rankingUsuarios, pendentesMaisAntigas,
}: Props) {

    const [motivoForn, setMotivoForn] = useState(Object.keys(fornecedoresPorMotivo)[0] ?? '');

    const mudarPeriodo = (p: number) =>
        router.get(route('estatisticas.index'), { periodo: p }, { preserveState: false });

    // Dados para o gráfico de linha da evolução diária
    const linhaTotal = evolucaoDiaria.map(d => ({ label: fmtDia(d.dia), valor: d.total }));
    const linhaAtend = evolucaoDiaria.map(d => ({ label: fmtDia(d.dia), valor: d.atendidas }));

    // Donut motivos
    const donutMotivos = porMotivo.map(m => ({
        label: m.motivo,
        valor: m.total,
        cor:   MOTIVO_COR[m.motivo] ?? '#6b7280',
    }));

    // Donut lojas
    const donutLojas = porLoja.map((l, i) => ({
        label: lojaNome(l.loja),
        valor: l.total,
        cor:   LOJA_CORES[i % LOJA_CORES.length],
    }));

    return (
        <AuthenticatedLayout header={null}>
            <Head title="Estatísticas" />

            <div className="py-6 px-4 sm:px-6 lg:px-8 max-w-screen-2xl mx-auto space-y-6">

                {/* ── Header + seletor de período ──────────────────────────────── */}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h1 className="text-lg font-bold text-gray-900">Estatísticas</h1>
                        <p className="text-sm text-gray-500">Últimos {periodo} dias</p>
                    </div>
                    <div className="flex items-center gap-1 bg-white rounded-lg border border-gray-200 shadow-sm p-1">
                        {PERIODOS.map(p => (
                            <button
                                key={p}
                                onClick={() => mudarPeriodo(p)}
                                className={`px-3 py-1.5 text-sm rounded-md font-medium transition ${
                                    p === periodo
                                        ? 'bg-blue-600 text-white shadow-sm'
                                        : 'text-gray-500 hover:bg-gray-100'
                                }`}
                            >
                                {p}d
                            </button>
                        ))}
                    </div>
                </div>

                {/* ── KPIs ────────────────────────────────────────────────────── */}
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <KpiCard label="Total de requisições" valor={kpis.total} cor="bg-gray-50 border-gray-200 text-gray-800" />
                    <KpiCard label="Atendidas" valor={kpis.atendidas} sub={`${kpis.taxaResolucao}% do total`} cor="bg-green-50 border-green-200 text-green-800" />
                    <KpiCard label="Pendentes" valor={kpis.pendentes} cor="bg-red-50 border-red-200 text-red-800" />
                    <KpiCard label="Resolvidas no dia" valor={kpis.resolvidasNoDia} sub="criação = atendimento" cor="bg-blue-50 border-blue-200 text-blue-800" />
                    <KpiCard label="Taxa de resolução" valor={`${kpis.taxaResolucao}%`} cor="bg-purple-50 border-purple-200 text-purple-800" />
                    <KpiCard
                        label="Tempo médio"
                        valor={kpis.tempoMedioHoras !== null ? `${kpis.tempoMedioHoras}h` : '—'}
                        sub="para atender"
                        cor="bg-orange-50 border-orange-200 text-orange-800"
                    />
                </div>

                {/* ── Evolução diária ──────────────────────────────────────────── */}
                <Card title={`Evolução diária — últimos ${periodo} dias`}>
                    <div className="space-y-4">
                        <div>
                            <p className="text-xs text-gray-400 mb-2">Total de requisições por dia</p>
                            <Linha dados={linhaTotal} cor="#3b82f6" altura={130} />
                        </div>
                        <div>
                            <p className="text-xs text-gray-400 mb-2">Atendidas por dia</p>
                            <Linha dados={linhaAtend} cor="#10b981" altura={100} />
                        </div>
                    </div>
                </Card>

                {/* ── Por motivo + Por loja ────────────────────────────────────── */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <Card title="Distribuição por motivo">
                        <Donut itens={donutMotivos} size={110} />
                        <div className="mt-4 space-y-2">
                            {porMotivo.map(m => (
                                <div key={m.motivo} className="flex items-center justify-between text-sm">
                                    <Badge label={m.motivo} className={MOTIVO_BG[m.motivo] ?? 'bg-gray-50 text-gray-600 ring-gray-500/20'} />
                                    <div className="flex items-center gap-3 text-xs text-gray-500">
                                        <span>{m.total} total</span>
                                        <span className="text-green-600">{m.atendidas} atendidas</span>
                                        <span className="text-gray-400">{pct(m.atendidas, m.total)}%</span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card>

                    <Card title="Distribuição por loja">
                        <Donut itens={donutLojas} size={110} />
                        <div className="mt-4 space-y-2">
                            {porLoja.map((l, i) => (
                                <div key={l.loja} className="flex items-center gap-3">
                                    <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ backgroundColor: LOJA_CORES[i % LOJA_CORES.length] }} />
                                    <span className="text-sm text-gray-700 w-16">{lojaNome(l.loja)}</span>
                                    <div className="flex-1 bg-gray-100 rounded-full h-3 overflow-hidden">
                                        <div
                                            className="h-3 rounded-full"
                                            style={{
                                                width: `${pct(l.total, kpis.total)}%`,
                                                backgroundColor: LOJA_CORES[i % LOJA_CORES.length],
                                            }}
                                        />
                                    </div>
                                    <span className="text-xs text-gray-500 w-8 text-right">{l.total}</span>
                                </div>
                            ))}
                        </div>
                    </Card>
                </div>

                {/* ── Por dia da semana + Por hora ─────────────────────────────── */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <Card title="Requisições por dia da semana">
                        <BarrasV
                            items={porDiaSemana.map(d => ({ label: d.dia, valor: d.total }))}
                            cor="#8b5cf6"
                            altura={140}
                        />
                    </Card>

                    <Card title="Requisições por hora do dia">
                        <BarrasV
                            items={porHora.map(h => ({ label: h.hora, valor: h.total }))}
                            cor="#f59e0b"
                            altura={140}
                        />
                        <p className="text-xs text-gray-400 mt-2 text-center">Passe o mouse sobre as barras para ver o valor exato</p>
                    </Card>
                </div>

                {/* ── Top fornecedores geral ───────────────────────────────────── */}
                <Card title="Top fornecedores — geral">
                    <div className="space-y-2.5">
                        {topFornecedores.length === 0 && (
                            <p className="text-sm text-gray-400 text-center py-4">Sem dados no período.</p>
                        )}
                        {topFornecedores.map((f, i) => (
                            <div key={i} className="flex items-center gap-3">
                                <span className="w-5 text-xs font-bold text-gray-400 text-right shrink-0">{i + 1}</span>
                                <span className="text-sm text-gray-700 w-48 truncate shrink-0" title={f.fornecedor}>{f.fornecedor}</span>
                                <div className="flex-1 bg-gray-100 rounded-full h-4 overflow-hidden">
                                    <div
                                        className="h-4 rounded-full bg-blue-500 transition-all"
                                        style={{ width: `${pct(f.total, topFornecedores[0]?.total ?? 1)}%` }}
                                    />
                                </div>
                                <span className="text-xs font-semibold text-gray-700 w-6 text-right">{f.total}</span>
                                <span className="text-xs text-green-600 w-20 text-right shrink-0">{f.atendidas} atend.</span>
                            </div>
                        ))}
                    </div>
                </Card>

                {/* ── Fornecedores por motivo ──────────────────────────────────── */}
                <Card title="Top fornecedores por motivo">
                    {/* Abas de motivo */}
                    <div className="flex flex-wrap gap-1.5 mb-4">
                        {Object.keys(fornecedoresPorMotivo).map(m => (
                            <button
                                key={m}
                                onClick={() => setMotivoForn(m)}
                                className={`px-3 py-1 text-xs rounded-full font-medium ring-1 ring-inset transition ${
                                    motivoForn === m
                                        ? 'bg-gray-800 text-white ring-gray-800'
                                        : (MOTIVO_BG[m] ?? 'bg-gray-50 text-gray-600 ring-gray-500/20') + ' hover:opacity-80'
                                }`}
                            >
                                {m}
                            </button>
                        ))}
                    </div>
                    <BarrasH
                        items={(fornecedoresPorMotivo[motivoForn] ?? []).map(f => ({
                            label: f.fornecedor,
                            valor: f.total,
                            cor:   MOTIVO_COR[motivoForn] ?? '#6b7280',
                        }))}
                    />
                    {(fornecedoresPorMotivo[motivoForn] ?? []).length === 0 && (
                        <p className="text-sm text-gray-400 text-center py-4">Sem requisições de <strong>{motivoForn}</strong> no período.</p>
                    )}
                </Card>

                {/* ── Reincidentes + Ranking usuários ─────────────────────────── */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">

                    <Card title="Fornecedores reincidentes (3+ requisições)">
                        <div className="space-y-2">
                            {reincidentes.length === 0 && (
                                <p className="text-sm text-gray-400 text-center py-4">Nenhum fornecedor reincidente no período.</p>
                            )}
                            {reincidentes.map((r, i) => (
                                <div key={i} className="flex items-center gap-3 py-1.5 border-b border-gray-50 last:border-0">
                                    <span className="w-5 text-xs font-bold text-gray-400 text-right">{i + 1}</span>
                                    <span className="text-sm text-gray-700 flex-1 truncate" title={r.fornecedor}>{r.fornecedor}</span>
                                    <span className="text-xs font-bold text-red-500">{r.total}×</span>
                                    <span className="text-xs text-gray-400">{r.dias_distintos} dia{r.dias_distintos !== 1 ? 's' : ''}</span>
                                </div>
                            ))}
                        </div>
                    </Card>

                    <Card title="Ranking de usuários — quem mais lançou">
                        <div className="space-y-2">
                            {rankingUsuarios.length === 0 && (
                                <p className="text-sm text-gray-400 text-center py-4">Sem dados no período.</p>
                            )}
                            {rankingUsuarios.map((u, i) => (
                                <div key={i} className="flex items-center gap-3">
                                    <span className="w-5 text-xs font-bold text-gray-400 text-right">{i + 1}</span>
                                    <div className="w-7 h-7 rounded-full bg-blue-600 text-white text-xs font-bold flex items-center justify-center shrink-0">
                                        {u.usuario.slice(0, 2).toUpperCase()}
                                    </div>
                                    <span className="text-sm text-gray-700 flex-1 truncate">{u.usuario}</span>
                                    <span className="text-xs font-semibold text-gray-700">{u.total}</span>
                                    <span className="text-xs text-green-600">{u.atendidas} atend.</span>
                                </div>
                            ))}
                        </div>
                    </Card>
                </div>

                {/* ── Pendentes mais antigas ───────────────────────────────────── */}
                {pendentesMaisAntigas.length > 0 && (
                    <Card title="⚠ Pendentes mais antigas — possíveis travadas">
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr className="border-b border-gray-100">
                                        {['Nota', 'Fornecedor', 'Motivo', 'Loja', 'Aberta em', 'Dias aberta'].map(c => (
                                            <th key={c} className="px-3 py-2 text-left text-xs font-semibold text-gray-400 uppercase tracking-wide whitespace-nowrap">
                                                {c}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-gray-50">
                                    {pendentesMaisAntigas.map(p => (
                                        <tr key={p.id} className={`hover:bg-gray-50 ${p.dias_aberta >= 3 ? 'bg-red-50/30' : ''}`}>
                                            <td className="px-3 py-2.5 font-medium text-gray-900">{p.numero_nota}</td>
                                            <td className="px-3 py-2.5 text-gray-600 max-w-[160px] truncate">{p.fornecedor}</td>
                                            <td className="px-3 py-2.5">
                                                <span className={`inline-flex items-center rounded px-2 py-0.5 text-xs font-medium ring-1 ring-inset ${MOTIVO_BG[p.motivo] ?? 'bg-gray-50 text-gray-600 ring-gray-500/20'}`}>
                                                    {p.motivo}
                                                </span>
                                            </td>
                                            <td className="px-3 py-2.5 text-gray-500">Loja {String(p.loja).padStart(2, '0')}</td>
                                            <td className="px-3 py-2.5 text-gray-400 whitespace-nowrap">{p.created_at}</td>
                                            <td className="px-3 py-2.5">
                                                <span className={`font-bold ${p.dias_aberta >= 3 ? 'text-red-600' : p.dias_aberta >= 1 ? 'text-amber-500' : 'text-gray-500'}`}>
                                                    {p.dias_aberta === 0 ? 'Hoje' : `${p.dias_aberta}d`}
                                                </span>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </Card>
                )}

            </div>
        </AuthenticatedLayout>
    );
}
