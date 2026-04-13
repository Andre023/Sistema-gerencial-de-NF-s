import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useTheme } from '@/Contexts/ThemeContext';

// ─── Tipos ────────────────────────────────────────────────────────────────────

interface Kpis {
    total: number;
    atendidas: number;
    pendentes: number;
    resolvidasNoDia: number;
    taxaResolucao: number;
    tempoMedioHoras: number | null;
}
interface DiaEvol { dia: string; total: number; atendidas: number; pendentes: number }
interface PorMotivo { motivo: string; total: number; atendidas: number }
interface PorLoja { loja: number; total: number; atendidas: number }
interface PorDia { dia: string; total: number }
interface PorHora { hora: string; total: number }
interface TopForn { fornecedor: string; total: number; atendidas: number }
interface FornMotivo { fornecedor: string; total: number }
interface Reincidente { fornecedor: string; total: number; dias_distintos: number }
interface RankUser { usuario: string; total: number; atendidas: number }
interface PendAntiga {
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

// ─── Paletas de cor (dark / light) ────────────────────────────────────────────

interface Palette {
    BG: string;
    SURFACE: string;
    BORDER: string;
    TEXT: string;
    MUTED: string;
    ACCENT: string;
    GREEN: string;
    RED: string;
    AMBER: string;
    PURPLE: string;
    BAR_BG: string;
    HOVER_ROW: string;
    TOOLTIP_BG: string;
}

const DARK: Palette = {
    BG:          '#0d1117',
    SURFACE:     '#161b22',
    BORDER:      '#21262d',
    TEXT:        '#e6edf3',
    MUTED:       '#7d8590',
    ACCENT:      '#2f81f7',
    GREEN:       '#3fb950',
    RED:         '#f85149',
    AMBER:       '#d29922',
    PURPLE:      '#a371f7',
    BAR_BG:      '#21262d',
    HOVER_ROW:   '#21262d',
    TOOLTIP_BG:  '#30363d',
};

const LIGHT: Palette = {
    BG:          '#f6f8fa',
    SURFACE:     '#ffffff',
    BORDER:      '#d0d7de',
    TEXT:        '#1f2328',
    MUTED:       '#656d76',
    ACCENT:      '#0969da',
    GREEN:       '#1a7f37',
    RED:         '#d1242f',
    AMBER:       '#9a6700',
    PURPLE:      '#8250df',
    BAR_BG:      '#eaeef2',
    HOVER_ROW:   '#f6f8fa',
    TOOLTIP_BG:  '#1f2328',
};

const LOJA_CORES_DARK  = ['#2f81f7', '#d29922', '#3fb950', '#f85149', '#a371f7', '#58a6ff', '#56d364'];
const LOJA_CORES_LIGHT = ['#0969da', '#9a6700', '#1a7f37', '#d1242f', '#8250df', '#0550ae', '#116329'];

// ─── Helpers ──────────────────────────────────────────────────────────────────

const lojaNome = (n: number) => `Loja ${String(n).padStart(2, '0')}`;
const fmtDia   = (s: string) => s.slice(5).replace('-', '/');
const pct      = (v: number, t: number) => (t > 0 ? Math.round((v / t) * 100) : 0);

// ─── Card ─────────────────────────────────────────────────────────────────────

function Card({ title, children, className = '', action, p }: {
    title?: string;
    children: React.ReactNode;
    className?: string;
    action?: React.ReactNode;
    p: Palette;
}) {
    return (
        <div
            className={`rounded-xl overflow-hidden ${className}`}
            style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}
        >
            {title && (
                <div
                    className="flex items-center justify-between px-5 py-3.5"
                    style={{ borderBottom: `1px solid ${p.BORDER}` }}
                >
                    <h3 className="text-sm font-semibold" style={{ color: p.TEXT }}>{title}</h3>
                    {action}
                </div>
            )}
            <div className="p-5">{children}</div>
        </div>
    );
}

// ─── KPI Card ─────────────────────────────────────────────────────────────────

function KpiCard({ label, valor, sub, trend, trendUp, p }: {
    label: string;
    valor: string | number;
    sub?: string;
    trend?: string;
    trendUp?: boolean;
    p: Palette;
}) {
    return (
        <div
            className="rounded-xl p-5 flex flex-col gap-2"
            style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}
        >
            {trend && (
                <span
                    className="text-xs font-semibold self-end"
                    style={{ color: trendUp ? p.GREEN : p.RED }}
                >
                    {trendUp ? '↑' : '↓'} {trend}
                </span>
            )}
            <p className="text-xs font-medium uppercase tracking-wider" style={{ color: p.MUTED }}>{label}</p>
            <p className="text-2xl font-bold leading-none" style={{ color: p.TEXT }}>{valor}</p>
            {sub && <p className="text-xs" style={{ color: p.MUTED }}>{sub}</p>}
        </div>
    );
}

// ─── Gráfico de linha SVG ─────────────────────────────────────────────────────

function Linha({ dados, cor, altura = 200, p }: {
    dados: { label: string; valor: number }[];
    cor: string;
    altura?: number;
    p: Palette;
}) {
    const w = 800; const h = altura;
    const pad = { t: 12, r: 12, b: 28, l: 36 };
    const innerW = w - pad.l - pad.r;
    const innerH = h - pad.t - pad.b;
    const maximo = Math.max(...dados.map(d => d.valor), 1);

    if (dados.length < 2) return (
        <p className="text-sm text-center py-6" style={{ color: p.MUTED }}>Dados insuficientes.</p>
    );

    const xStep = innerW / (dados.length - 1);
    const pts = dados.map((d, i) => ({
        x: pad.l + i * xStep,
        y: pad.t + innerH - (d.valor / maximo) * innerH,
        ...d,
    }));

    const polyline = pts.map(pt => `${pt.x},${pt.y}`).join(' ');
    const area = `${pts[0].x},${pad.t + innerH} ${polyline} ${pts[pts.length - 1].x},${pad.t + innerH}`;

    const yTicks = [0, 0.25, 0.5, 0.75, 1].map(frac => ({
        y: pad.t + innerH - frac * innerH,
        val: Math.round(frac * maximo),
    }));

    const xStep2 = Math.ceil(dados.length / 10);
    const xLabels = dados.filter((_, i) => i % xStep2 === 0 || i === dados.length - 1);

    return (
        <svg viewBox={`0 0 ${w} ${h}`} className="w-full" style={{ height: altura }}>
            <defs>
                <linearGradient id={`grad-${cor.replace('#','')}`} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={cor} stopOpacity="0.25" />
                    <stop offset="100%" stopColor={cor} stopOpacity="0" />
                </linearGradient>
            </defs>
            {yTicks.map((t, i) => (
                <g key={i}>
                    <line x1={pad.l} y1={t.y} x2={w - pad.r} y2={t.y}
                        stroke={p.BORDER} strokeWidth="1" strokeDasharray="4 4" />
                    <text x={pad.l - 6} y={t.y + 4} textAnchor="end"
                        fontSize="9" fill={p.MUTED}>{t.val}</text>
                </g>
            ))}
            <polygon points={area} fill={`url(#grad-${cor.replace('#','')})`} />
            <polyline points={polyline} fill="none" stroke={cor}
                strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />
            {pts.map((pt, i) => (
                <circle key={i} cx={pt.x} cy={pt.y} r="3.5" fill={cor} stroke={p.SURFACE} strokeWidth="2">
                    <title>{pt.label}: {pt.valor}</title>
                </circle>
            ))}
            {xLabels.map((d, i) => {
                const idx = dados.indexOf(d);
                return (
                    <text key={i} x={pad.l + idx * xStep} y={h - 6}
                        textAnchor="middle" fontSize="9" fill={p.MUTED}>{d.label}</text>
                );
            })}
        </svg>
    );
}

// ─── Barras horizontais ───────────────────────────────────────────────────────

function BarrasH({ items, cor, p }: {
    items: { label: string; valor: number; cor?: string }[];
    cor: string;
    p: Palette;
}) {
    const maximo = Math.max(...items.map(i => i.valor), 1);
    return (
        <div className="space-y-3">
            {items.map((item, i) => (
                <div key={i} className="flex items-center gap-3">
                    <span className="text-xs w-32 truncate shrink-0 text-right" style={{ color: p.MUTED }} title={item.label}>
                        {item.label}
                    </span>
                    <div className="flex-1 rounded-full h-5 overflow-hidden" style={{ background: p.BAR_BG }}>
                        <div
                            className="h-5 rounded-full flex items-center justify-end pr-2 transition-all duration-500"
                            style={{
                                width: `${Math.max((item.valor / maximo) * 100, 3)}%`,
                                background: item.cor ?? cor,
                            }}
                        >
                            <span className="text-xs font-semibold" style={{ color: '#fff' }}>{item.valor}</span>
                        </div>
                    </div>
                </div>
            ))}
            {items.length === 0 && (
                <p className="text-sm text-center py-4" style={{ color: p.MUTED }}>Sem dados no período.</p>
            )}
        </div>
    );
}

// ─── Barras verticais ─────────────────────────────────────────────────────────

function BarrasV({ items, altura = 160, cor, p }: {
    items: { label: string; valor: number; cor?: string }[];
    altura?: number;
    cor: string;
    p: Palette;
}) {
    const maximo = Math.max(...items.map(i => i.valor), 1);
    return (
        <div className="flex items-end gap-1 overflow-x-auto pb-2" style={{ height: altura + 32 }}>
            {items.map((item, i) => {
                const h = Math.max((item.valor / maximo) * altura, item.valor > 0 ? 4 : 0);
                return (
                    <div key={i} className="flex flex-col items-center gap-1 min-w-[28px] flex-1 group">
                        <span className="text-xs opacity-0 group-hover:opacity-100 transition whitespace-nowrap px-1.5 py-0.5 rounded text-[10px]"
                            style={{ background: p.TOOLTIP_BG, color: p.TEXT }}>
                            {item.valor}
                        </span>
                        <div
                            className="w-full rounded-t transition-all duration-300"
                            style={{ height: h, background: item.cor ?? cor, opacity: 0.85, minWidth: 8 }}
                        />
                        <span className="text-[10px] whitespace-nowrap" style={{ color: p.MUTED }}>{item.label}</span>
                    </div>
                );
            })}
        </div>
    );
}

// ─── Donut ────────────────────────────────────────────────────────────────────

function Donut({ itens, size = 120, p }: {
    itens: { label: string; valor: number; cor: string }[];
    size?: number;
    p: Palette;
}) {
    const total = itens.reduce((s, i) => s + i.valor, 0);
    if (total === 0) return <p className="text-sm text-center py-4" style={{ color: p.MUTED }}>Sem dados.</p>;

    const r = 40; const cx = 60; const cy = 60;
    const circunf = 2 * Math.PI * r;
    let offset = 0;
    const segmentos = itens.map(item => {
        const frac = item.valor / total;
        const seg = { ...item, dasharray: `${frac * circunf} ${circunf}`, offset: offset * circunf };
        offset += frac;
        return seg;
    });

    return (
        <div className="flex items-center gap-5 flex-wrap">
            <svg viewBox="0 0 120 120" style={{ width: size, height: size }}>
                <circle cx={cx} cy={cy} r={r} fill="none" stroke={p.BORDER} strokeWidth="18" />
                {segmentos.map((s, i) => (
                    <circle key={i} cx={cx} cy={cy} r={r} fill="none"
                        stroke={s.cor} strokeWidth="18"
                        strokeDasharray={s.dasharray}
                        strokeDashoffset={-s.offset}
                        transform="rotate(-90 60 60)">
                        <title>{s.label}: {s.valor}</title>
                    </circle>
                ))}
                <text x="60" y="56" textAnchor="middle" fontSize="15" fontWeight="bold" fill={p.TEXT}>{total}</text>
                <text x="60" y="70" textAnchor="middle" fontSize="9" fill={p.MUTED}>total</text>
            </svg>
            <div className="flex flex-col gap-2 min-w-0">
                {itens.map((item, i) => (
                    <div key={i} className="flex items-center gap-2 text-xs" style={{ color: p.MUTED }}>
                        <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ background: item.cor }} />
                        <span className="truncate">{item.label}</span>
                        <span className="ml-auto font-semibold pl-3" style={{ color: p.TEXT }}>{item.valor}</span>
                        <span className="text-xs">({pct(item.valor, total)}%)</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

// ─── Página principal ─────────────────────────────────────────────────────────

const PERIODOS = [7, 15, 30, 60, 90];

export default function Index({
    periodo, kpis, evolucaoDiaria, porMotivo, porLoja, porDiaSemana, porHora,
    topFornecedores, fornecedoresPorMotivo, reincidentes, rankingUsuarios, pendentesMaisAntigas,
}: Props) {

    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;
    const LOJA_CORES = isDark ? LOJA_CORES_DARK : LOJA_CORES_LIGHT;

    const MOTIVO_COR: Record<string, string> = {
        Cadastro:   p.ACCENT,
        Preço:      p.AMBER,
        Regra:      p.RED,
        Quantidade: p.PURPLE,
        Pedido:     p.GREEN,
    };

    const [motivoForn, setMotivoForn] = useState(Object.keys(fornecedoresPorMotivo)[0] ?? '');

    const mudarPeriodo = (per: number) =>
        router.get(route('estatisticas.index'), { periodo: per }, { preserveState: false });

    const linhaTotal = evolucaoDiaria.map(d => ({ label: fmtDia(d.dia), valor: d.total }));
    const linhaAtend = evolucaoDiaria.map(d => ({ label: fmtDia(d.dia), valor: d.atendidas }));

    const donutMotivos = porMotivo.map(m => ({
        label: m.motivo, valor: m.total, cor: MOTIVO_COR[m.motivo] ?? p.MUTED,
    }));
    const donutLojas = porLoja.map((l, i) => ({
        label: lojaNome(l.loja), valor: l.total, cor: LOJA_CORES[i % LOJA_CORES.length],
    }));

    return (
        <AuthenticatedLayout header={null}>
            <Head title="Estatísticas" />

            <div className="min-h-screen py-8 px-4 sm:px-6 lg:px-8 max-w-screen-2xl mx-auto space-y-6 transition-colors duration-200"
                style={{ background: p.BG }}>

                {/* ── Cabeçalho ─────────────────────────────────────────────── */}
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-bold tracking-tight" style={{ color: p.TEXT }}>Estatísticas</h1>
                        <p className="text-sm mt-0.5" style={{ color: p.MUTED }}>Últimos {periodo} dias</p>
                    </div>

                    {/* Seletor de período */}
                    <div className="flex items-center gap-1 rounded-lg p-1"
                        style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                        {PERIODOS.map(per => (
                            <button
                                key={per}
                                onClick={() => mudarPeriodo(per)}
                                className="px-3.5 py-1.5 text-sm rounded-md font-medium transition-all"
                                style={per === periodo
                                    ? { background: p.ACCENT, color: '#fff' }
                                    : { color: p.MUTED, background: 'transparent' }
                                }
                            >
                                {per}d
                            </button>
                        ))}
                    </div>
                </div>

                {/* ── KPIs ──────────────────────────────────────────────────── */}
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <KpiCard label="Total de requisições" valor={kpis.total} p={p} />
                    <KpiCard label="Atendidas" valor={kpis.atendidas}
                        sub={`${kpis.taxaResolucao}% do total`}
                        trend={`${kpis.taxaResolucao}%`} trendUp p={p} />
                    <KpiCard label="Pendentes" valor={kpis.pendentes}
                        trend={kpis.pendentes > 0 ? `${kpis.pendentes}` : undefined} trendUp={false} p={p} />
                    <KpiCard label="Resolvidas no dia" valor={kpis.resolvidasNoDia}
                        sub="criação = atendimento" p={p} />
                    <KpiCard label="Taxa de resolução" valor={`${kpis.taxaResolucao}%`} p={p} />
                    <KpiCard
                        label="Tempo médio"
                        valor={kpis.tempoMedioHoras !== null ? `${kpis.tempoMedioHoras}h` : '—'}
                        sub="para atender" p={p} />
                </div>

                {/* ── Evolução + Top fornecedores ────────────────────────────── */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">

                    {/* Gráfico principal */}
                    <div className="lg:col-span-2">
                        <Card title={`Evolução de Faturamento — últimos ${periodo} dias`} p={p}>
                            <Linha dados={linhaTotal} cor={p.ACCENT} altura={200} p={p} />
                            <div className="mt-4 pt-4" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                                <p className="text-xs mb-2" style={{ color: p.MUTED }}>Atendidas por dia</p>
                                <Linha dados={linhaAtend} cor={p.GREEN} altura={100} p={p} />
                            </div>
                        </Card>
                    </div>

                    {/* Top fornecedores */}
                    <div>
                        <Card title="🏆 Fornecedores mais frequentes" p={p}>
                            <div className="space-y-3">
                                {topFornecedores.slice(0, 6).map((f, i) => (
                                    <div key={i} className="flex items-center justify-between gap-2">
                                        <div className="min-w-0">
                                            <p className="text-sm font-medium truncate" style={{ color: p.TEXT }}
                                                title={f.fornecedor}>{f.fornecedor}</p>
                                            <p className="text-xs" style={{ color: p.MUTED }}>
                                                {f.total} req · {f.atendidas} atend.
                                            </p>
                                        </div>
                                        <div className="text-right shrink-0">
                                            <span className="text-sm font-semibold" style={{ color: p.GREEN }}>
                                                {f.atendidas > 0
                                                    ? `+${pct(f.atendidas, f.total)}%`
                                                    : '0%'}
                                            </span>
                                            <p className="text-xs" style={{ color: p.MUTED }}>resolvidos</p>
                                        </div>
                                    </div>
                                ))}
                                {topFornecedores.length === 0 && (
                                    <p className="text-sm text-center py-4" style={{ color: p.MUTED }}>
                                        Sem dados no período.
                                    </p>
                                )}
                            </div>
                        </Card>
                    </div>
                </div>

                {/* ── Motivos + Lojas ────────────────────────────────────────── */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <Card title="Distribuição por motivo" p={p}>
                        <Donut itens={donutMotivos} size={110} p={p} />
                        <div className="mt-5 space-y-2" style={{ borderTop: `1px solid ${p.BORDER}`, paddingTop: 16 }}>
                            {porMotivo.map(m => (
                                <div key={m.motivo} className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <span className="w-2 h-2 rounded-full"
                                            style={{ background: MOTIVO_COR[m.motivo] ?? p.MUTED }} />
                                        <span className="text-sm" style={{ color: p.TEXT }}>{m.motivo}</span>
                                    </div>
                                    <div className="flex items-center gap-4 text-xs" style={{ color: p.MUTED }}>
                                        <span>{m.total} total</span>
                                        <span style={{ color: p.GREEN }}>{m.atendidas} atend.</span>
                                        <span className="font-semibold w-8 text-right" style={{ color: p.TEXT }}>
                                            {pct(m.atendidas, m.total)}%
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card>

                    <Card title="Distribuição por loja" p={p}>
                        <Donut itens={donutLojas} size={110} p={p} />
                        <div className="mt-5 space-y-2.5" style={{ borderTop: `1px solid ${p.BORDER}`, paddingTop: 16 }}>
                            {porLoja.map((l, i) => (
                                <div key={l.loja} className="flex items-center gap-3">
                                    <span className="w-2 h-2 rounded-full shrink-0"
                                        style={{ background: LOJA_CORES[i % LOJA_CORES.length] }} />
                                    <span className="text-sm w-16" style={{ color: p.TEXT }}>{lojaNome(l.loja)}</span>
                                    <div className="flex-1 rounded-full h-2.5 overflow-hidden" style={{ background: p.BORDER }}>
                                        <div className="h-2.5 rounded-full"
                                            style={{
                                                width: `${pct(l.total, kpis.total)}%`,
                                                background: LOJA_CORES[i % LOJA_CORES.length],
                                            }} />
                                    </div>
                                    <span className="text-xs w-8 text-right" style={{ color: p.MUTED }}>{l.total}</span>
                                </div>
                            ))}
                        </div>
                    </Card>
                </div>

                {/* ── Distribuição Temporal ─────────────────────────────────── */}
                <div>
                    <h2 className="text-sm font-semibold mb-3" style={{ color: p.TEXT }}>
                        Análise de Distribuição Temporal
                    </h2>
                    <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
                        <Card title="Requisições por dia da semana" p={p}>
                            <BarrasV
                                items={porDiaSemana.map(d => ({ label: d.dia, valor: d.total }))}
                                cor={p.PURPLE} altura={150} p={p} />
                        </Card>
                        <Card title="Requisições por hora do dia" p={p}>
                            <BarrasV
                                items={porHora.map(h => ({ label: h.hora, valor: h.total }))}
                                cor={p.AMBER} altura={150} p={p} />
                            <p className="text-xs text-center mt-2" style={{ color: p.MUTED }}>
                                Passe o mouse sobre as barras para ver o valor
                            </p>
                        </Card>
                    </div>
                </div>

                {/* ── Fornecedores por motivo ────────────────────────────────── */}
                <Card title="Top fornecedores por motivo" p={p}
                    action={
                        <div className="flex flex-wrap gap-1.5">
                            {Object.keys(fornecedoresPorMotivo).map(m => (
                                <button key={m} onClick={() => setMotivoForn(m)}
                                    className="px-3 py-1 text-xs rounded-full font-medium transition-all"
                                    style={motivoForn === m
                                        ? { background: MOTIVO_COR[m] ?? p.ACCENT, color: '#fff' }
                                        : { background: p.BAR_BG, color: p.MUTED, border: `1px solid ${p.BORDER}` }
                                    }>
                                    {m}
                                </button>
                            ))}
                        </div>
                    }>
                    <BarrasH
                        items={(fornecedoresPorMotivo[motivoForn] ?? []).map(f => ({
                            label: f.fornecedor, valor: f.total,
                            cor: MOTIVO_COR[motivoForn] ?? p.ACCENT,
                        }))}
                        cor={p.ACCENT} p={p}
                    />
                    {(fornecedoresPorMotivo[motivoForn] ?? []).length === 0 && (
                        <p className="text-sm text-center py-4" style={{ color: p.MUTED }}>
                            Sem requisições de <strong style={{ color: p.TEXT }}>{motivoForn}</strong> no período.
                        </p>
                    )}
                </Card>

                {/* ── Reincidentes + Ranking usuários ───────────────────────── */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">

                    <Card title="Fornecedores reincidentes (3+ requisições)" p={p}>
                        <div className="space-y-2">
                            {reincidentes.length === 0 && (
                                <p className="text-sm text-center py-4" style={{ color: p.MUTED }}>
                                    Nenhum fornecedor reincidente no período.
                                </p>
                            )}
                            {reincidentes.map((r, i) => (
                                <div key={i} className="flex items-center gap-3 py-2"
                                    style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                    <span className="text-xs font-bold w-5 text-right" style={{ color: p.MUTED }}>{i + 1}</span>
                                    <span className="text-sm flex-1 truncate" style={{ color: p.TEXT }} title={r.fornecedor}>
                                        {r.fornecedor}
                                    </span>
                                    <span className="text-xs font-bold" style={{ color: p.RED }}>{r.total}×</span>
                                    <span className="text-xs" style={{ color: p.MUTED }}>
                                        {r.dias_distintos} dia{r.dias_distintos !== 1 ? 's' : ''}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </Card>

                    <Card title="Ranking de usuários — quem mais lançou" p={p}>
                        <div className="space-y-3">
                            {rankingUsuarios.length === 0 && (
                                <p className="text-sm text-center py-4" style={{ color: p.MUTED }}>Sem dados no período.</p>
                            )}
                            {rankingUsuarios.map((u, i) => (
                                <div key={i} className="flex items-center gap-3">
                                    <span className="text-xs font-bold w-5 text-right" style={{ color: p.MUTED }}>{i + 1}</span>
                                    <div className="w-7 h-7 rounded-full flex items-center justify-center shrink-0 text-xs font-bold"
                                        style={{ background: p.ACCENT, color: '#fff' }}>
                                        {u.usuario.slice(0, 2).toUpperCase()}
                                    </div>
                                    <span className="text-sm flex-1 truncate" style={{ color: p.TEXT }}>{u.usuario}</span>
                                    <span className="text-xs font-semibold" style={{ color: p.TEXT }}>{u.total}</span>
                                    <span className="text-xs" style={{ color: p.GREEN }}>{u.atendidas} atend.</span>
                                </div>
                            ))}
                        </div>
                    </Card>
                </div>

                {/* ── Pendentes mais antigas ─────────────────────────────────── */}
                {pendentesMaisAntigas.length > 0 && (
                    <Card title="⚠ Pendentes mais antigas — possíveis travadas" p={p}>
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead>
                                    <tr style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                        {['Nota', 'Fornecedor', 'Motivo', 'Loja', 'Aberta em', 'Dias aberta'].map(c => (
                                            <th key={c}
                                                className="px-3 py-2.5 text-left text-xs font-semibold uppercase tracking-wider whitespace-nowrap"
                                                style={{ color: p.MUTED }}>
                                                {c}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {pendentesMaisAntigas.map(pen => (
                                        <tr key={pen.id}
                                            className="transition-colors"
                                            style={{ borderBottom: `1px solid ${p.BORDER}` }}
                                            onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                                            onMouseLeave={e => (e.currentTarget.style.background = 'transparent')}>
                                            <td className="px-3 py-3 font-medium" style={{ color: p.TEXT }}>{pen.numero_nota}</td>
                                            <td className="px-3 py-3 max-w-[160px] truncate" style={{ color: p.MUTED }}>{pen.fornecedor}</td>
                                            <td className="px-3 py-3">
                                                <span className="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium"
                                                    style={{
                                                        background: (MOTIVO_COR[pen.motivo] ?? p.ACCENT) + '22',
                                                        color: MOTIVO_COR[pen.motivo] ?? p.ACCENT,
                                                        border: `1px solid ${(MOTIVO_COR[pen.motivo] ?? p.ACCENT)}44`,
                                                    }}>
                                                    {pen.motivo}
                                                </span>
                                            </td>
                                            <td className="px-3 py-3" style={{ color: p.MUTED }}>
                                                Loja {String(pen.loja).padStart(2, '0')}
                                            </td>
                                            <td className="px-3 py-3 whitespace-nowrap" style={{ color: p.MUTED }}>{pen.created_at}</td>
                                            <td className="px-3 py-3">
                                                <span className="font-bold" style={{
                                                    color: pen.dias_aberta >= 3 ? p.RED : pen.dias_aberta >= 1 ? p.AMBER : p.MUTED
                                                }}>
                                                    {pen.dias_aberta === 0 ? 'Hoje' : `${pen.dias_aberta}d`}
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
