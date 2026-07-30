import React, { useState } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useTheme } from '@/Contexts/ThemeContext';
import { nivelCor, idadeTexto } from '@/lib/tema';
import { Nivel } from '@/types';
import {
    DARK, LIGHT, Palette, Card, KpiCard, Linha, BarrasH, BarrasV, Donut, ComparaRede,
    LOJA_CORES_DARK, LOJA_CORES_LIGHT, lojaNome, fmtDia, pct, fmtHoras,
} from '@/Components/estatisticas/Graficos';

// ─── Tipos ────────────────────────────────────────────────────────────────────

interface Kpis {
    total: number;
    atendidas: number;
    pendentes: number;
    resolvidasNoDia: number;
    taxaResolucao: number;
    tempoMedioHoras: number | null;
    /** Variação vs. o período anterior de mesmo tamanho (null = sem base) */
    variacao: { total: number | null; taxaResolucao: number | null; tempoMedio: number | null };
}
interface Etapas {
    ateAnalise: number | null;
    correcao: number | null;
    reconferencia: number | null;
    semDivergencia: number | null;
}
interface Retrabalho {
    totalCards: number; reabertos: number; taxa: number;
    porTipo: { motivo: string; cards: number; reaberturas: number }[];
    porFornecedor: { fornecedor: string; total: number }[];
}
interface PorOrigem {
    origem: string; total: number; atendidas: number; taxa: number; tempoMedioHoras: number | null;
}
interface PorCeasa {
    grupo: string; total: number; atendidas: number;
    comDivergencia: number; taxaDivergencia: number; tempoMedioHoras: number | null;
}
interface Cancelamento {
    total: number; taxa: number; tardias: number; tempoMedioHoras: number | null;
    porFornecedor: { fornecedor: string; total: number }[];
    porQuem: { usuario: string; total: number }[];
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
    motivo: string; loja: number; dias_aberta: number; nivel: Nivel; created_at: string;
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
    // ── Blocos novos ──
    etapas: Etapas;
    retrabalho: Retrabalho;
    porOrigem: PorOrigem[];
    porCeasa: PorCeasa[];
    cancelamento: Cancelamento;
    filtros: { loja: number[]; origem: string | null; ceasa: string | null };
    opcoes: { lojas: number[]; origens: string[] };
}

// ─── Página principal ─────────────────────────────────────────────────────────

const PERIODOS = [7, 15, 30, 60, 90];

export default function Index({
    periodo, kpis, evolucaoDiaria, porMotivo, porLoja, porDiaSemana, porHora,
    topFornecedores, fornecedoresPorMotivo, reincidentes, rankingUsuarios, pendentesMaisAntigas,
    etapas, retrabalho, porOrigem, porCeasa, cancelamento, filtros, opcoes,
}: Props) {

    const { isDark } = useTheme();
    const p = isDark ? DARK : LIGHT;
    const LOJA_CORES = isDark ? LOJA_CORES_DARK : LOJA_CORES_LIGHT;

    // Tipos de card + a categoria "Sem divergência" (notas limpas)
    const MOTIVO_COR: Record<string, string> = {
        Cadastro: p.ACCENT,
        Regra: p.RED,
        Custo: p.AMBER,
        Quantidade: p.PURPLE,
        'Sem pedido': '#2dd4bf',
        'Importar nf': p.GREEN,
        Reconferir: '#e3954a',
        'Sem divergência': p.GREEN,
    };

    const [motivoForn, setMotivoForn] = useState(Object.keys(fornecedoresPorMotivo)[0] ?? '');

    // Navegação preservando os filtros ativos
    const irPara = (extras: Record<string, unknown>) =>
        router.get(route('estatisticas.index'), {
            periodo,
            loja: filtros.loja.length ? filtros.loja : undefined,
            origem: filtros.origem ?? undefined,
            ceasa: filtros.ceasa ?? undefined,
            ...extras,
        } as any, { preserveState: false });

    const mudarPeriodo = (per: number) => irPara({ periodo: per });
    const alternarLoja = (l: number) => {
        const novo = filtros.loja.includes(l) ? filtros.loja.filter(x => x !== l) : [...filtros.loja, l];
        irPara({ loja: novo.length ? novo : undefined });
    };
    const filtrarOrigem = (o: string | null) => irPara({ origem: o ?? undefined });
    const filtrarCeasa = (c: string | null) => irPara({ ceasa: c ?? undefined });
    const temFiltro = filtros.loja.length > 0 || !!filtros.origem || !!filtros.ceasa;

    /** "↑ 12%" — sinal e cor conforme a variação, e se subir é bom ou ruim. */
    const varTexto = (v: number | null) => v === null ? undefined : `${Math.abs(v)}% vs. anterior`;

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

                {/* ── Filtros (loja / fila / CEASA) ─────────────────────────── */}
                <div className="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-xl px-4 py-3"
                    style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
                    <div className="flex items-center gap-1.5 flex-wrap">
                        <span className="text-xs font-medium" style={{ color: p.MUTED }}>Lojas:</span>
                        {opcoes.lojas.map(l => {
                            const ativo = filtros.loja.includes(l);
                            return (
                                <button key={l} onClick={() => alternarLoja(l)}
                                    className="text-xs font-medium px-2 py-1 rounded-lg transition"
                                    style={{
                                        background: ativo ? p.ACCENT + '22' : 'transparent',
                                        color: ativo ? p.ACCENT : p.MUTED,
                                        border: `1px solid ${ativo ? p.ACCENT : p.BORDER}`,
                                    }}>
                                    {String(l).padStart(2, '0')}
                                </button>
                            );
                        })}
                    </div>

                    <div className="flex items-center gap-1.5">
                        <span className="text-xs font-medium" style={{ color: p.MUTED }}>Fila:</span>
                        {[{ v: null, l: 'Ambas' }, { v: 'recebimento', l: 'Caminhão' }, { v: 'pre_lote', l: 'Pré-lote' }].map(o => {
                            const ativo = filtros.origem === o.v;
                            return (
                                <button key={o.l} onClick={() => filtrarOrigem(o.v)}
                                    className="text-xs font-medium px-2 py-1 rounded-lg transition"
                                    style={{
                                        background: ativo ? p.PURPLE + '22' : 'transparent',
                                        color: ativo ? p.PURPLE : p.MUTED,
                                        border: `1px solid ${ativo ? p.PURPLE : p.BORDER}`,
                                    }}>
                                    {o.l}
                                </button>
                            );
                        })}
                    </div>

                    <div className="flex items-center gap-1.5">
                        <span className="text-xs font-medium" style={{ color: p.MUTED }}>CEASA:</span>
                        {[{ v: null, l: 'Tudo' }, { v: 'so', l: 'Só CEASA' }, { v: 'sem', l: 'Sem CEASA' }].map(c => {
                            const ativo = filtros.ceasa === c.v;
                            return (
                                <button key={c.l} onClick={() => filtrarCeasa(c.v)}
                                    className="text-xs font-medium px-2 py-1 rounded-lg transition"
                                    style={{
                                        background: ativo ? p.AMBER + '22' : 'transparent',
                                        color: ativo ? p.AMBER : p.MUTED,
                                        border: `1px solid ${ativo ? p.AMBER : p.BORDER}`,
                                    }}>
                                    {c.l}
                                </button>
                            );
                        })}
                    </div>

                    {temFiltro && (
                        <button onClick={() => irPara({ loja: undefined, origem: undefined, ceasa: undefined })}
                            className="text-xs ml-auto" style={{ color: p.MUTED }}>
                            ✕ limpar filtros
                        </button>
                    )}
                </div>

                {/* ── KPIs ──────────────────────────────────────────────────── */}
                <div className="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <KpiCard label="Total de notas" valor={kpis.total}
                        trend={varTexto(kpis.variacao.total)}
                        trendUp={(kpis.variacao.total ?? 0) >= 0} p={p} />
                    <KpiCard label="Liberadas" valor={kpis.atendidas}
                        sub={`${kpis.taxaResolucao}% do total`} p={p} />
                    <KpiCard label="Na fila" valor={kpis.pendentes}
                        sub="canceladas não contam" p={p} />
                    <KpiCard label="Liberadas no dia" valor={kpis.resolvidasNoDia}
                        sub="lançamento = liberação" p={p} />
                    <KpiCard label="Taxa de liberação" valor={`${kpis.taxaResolucao}%`}
                        trend={varTexto(kpis.variacao.taxaResolucao)}
                        trendUp={(kpis.variacao.taxaResolucao ?? 0) >= 0} p={p} />
                    <KpiCard label="Tempo médio" valor={fmtHoras(kpis.tempoMedioHoras)}
                        sub="para liberar"
                        trend={varTexto(kpis.variacao.tempoMedio)}
                        // menos tempo é melhor: cai = verde
                        trendUp={(kpis.variacao.tempoMedio ?? 0) <= 0} p={p} />
                </div>

                {/* ── Onde o tempo é gasto (etapas) ─────────────────────────── */}
                <Card title="Onde o tempo é gasto" p={p}
                    action={<span className="text-xs" style={{ color: p.MUTED }}>média por etapa do fluxo</span>}>
                    <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                        {[
                            { l: 'Até o pré-lote analisar', v: etapas.ateAnalise, d: 'lançamento → 1ª divergência' },
                            { l: 'Compras corrigir', v: etapas.correcao, d: 'card aberto → corrigido' },
                            { l: 'Reconferir e liberar', v: etapas.reconferencia, d: 'corrigido → liberada' },
                            { l: 'Nota limpa', v: etapas.semDivergencia, d: 'sem nenhuma divergência' },
                        ].map(e => (
                            <div key={e.l} className="rounded-lg p-4" style={{ background: p.BG, border: `1px solid ${p.BORDER}` }}>
                                <p className="text-xl font-bold" style={{ color: p.TEXT }}>{fmtHoras(e.v)}</p>
                                <p className="text-xs font-medium mt-1" style={{ color: p.TEXT }}>{e.l}</p>
                                <p className="text-[11px] mt-0.5" style={{ color: p.MUTED }}>{e.d}</p>
                            </div>
                        ))}
                    </div>
                </Card>

                {/* ── Fila × fila e CEASA ───────────────────────────────────── */}
                <div className="grid lg:grid-cols-2 gap-4">
                    <Card title="Caminhão na porta × Pré-lote" p={p}>
                        <div className="space-y-3">
                            {porOrigem.map(o => (
                                <div key={o.origem} className="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5"
                                    style={{ background: p.BG }}>
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium" style={{ color: p.TEXT }}>{o.origem}</p>
                                        <p className="text-xs" style={{ color: p.MUTED }}>{o.total} notas · {o.taxa}% liberadas</p>
                                    </div>
                                    <div className="text-right shrink-0">
                                        <p className="text-sm font-semibold" style={{ color: p.ACCENT }}>{fmtHoras(o.tempoMedioHoras)}</p>
                                        <p className="text-[11px]" style={{ color: p.MUTED }}>até liberar</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card>

                    <Card title="CEASA × resto" p={p}
                        action={<span className="text-xs" style={{ color: p.MUTED }}>hortifrúti tem outra dinâmica</span>}>
                        <div className="space-y-3">
                            {porCeasa.map(c => (
                                <div key={c.grupo} className="flex items-center justify-between gap-3 rounded-lg px-3 py-2.5"
                                    style={{ background: p.BG }}>
                                    <div className="min-w-0">
                                        <p className="text-sm font-medium" style={{ color: p.TEXT }}>{c.grupo}</p>
                                        <p className="text-xs" style={{ color: p.MUTED }}>
                                            {c.total} notas · {c.taxaDivergencia}% com divergência
                                        </p>
                                    </div>
                                    <div className="text-right shrink-0">
                                        <p className="text-sm font-semibold" style={{ color: p.AMBER }}>{fmtHoras(c.tempoMedioHoras)}</p>
                                        <p className="text-[11px]" style={{ color: p.MUTED }}>até liberar</p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </Card>
                </div>

                {/* ── Retrabalho + Cancelamento ─────────────────────────────── */}
                <div className="grid lg:grid-cols-2 gap-4">
                    <Card title="Retrabalho (cards reabertos)" p={p}
                        action={<span className="text-xs font-semibold"
                            style={{ color: retrabalho.taxa > 10 ? p.RED : p.MUTED }}>{retrabalho.taxa}% dos cards</span>}>
                        <div className="flex items-center gap-4 mb-4">
                            <div className="text-center px-3">
                                <p className="text-2xl font-bold" style={{ color: retrabalho.reabertos > 0 ? p.RED : p.GREEN }}>
                                    {retrabalho.reabertos}
                                </p>
                                <p className="text-[11px] mt-0.5" style={{ color: p.MUTED }}>de {retrabalho.totalCards} cards</p>
                            </div>
                            <p className="text-xs flex-1" style={{ color: p.MUTED }}>
                                Card reaberto = compras marcou como corrigido e o pré-lote achou que continuava errado.
                            </p>
                        </div>
                        {retrabalho.porFornecedor.length > 0 && (
                            <>
                                <p className="text-xs mb-2" style={{ color: p.MUTED }}>Quem gera mais retrabalho</p>
                                <BarrasH cor={p.RED} p={p}
                                    items={retrabalho.porFornecedor.map(f => ({ label: f.fornecedor, valor: f.total }))} />
                            </>
                        )}
                    </Card>

                    <Card title="Cancelamentos" p={p}
                        action={<span className="text-xs font-semibold" style={{ color: p.MUTED }}>{cancelamento.taxa}% das notas</span>}>
                        <div className="grid grid-cols-3 gap-3 mb-4">
                            <div className="text-center rounded-lg py-2.5" style={{ background: p.BG }}>
                                <p className="text-xl font-bold" style={{ color: p.TEXT }}>{cancelamento.total}</p>
                                <p className="text-[11px]" style={{ color: p.MUTED }}>canceladas</p>
                            </div>
                            <div className="text-center rounded-lg py-2.5" style={{ background: p.BG }}>
                                <p className="text-xl font-bold" style={{ color: cancelamento.tardias > 0 ? p.AMBER : p.TEXT }}>
                                    {cancelamento.tardias}
                                </p>
                                <p className="text-[11px]" style={{ color: p.MUTED }}>já conferidas</p>
                            </div>
                            <div className="text-center rounded-lg py-2.5" style={{ background: p.BG }}>
                                <p className="text-xl font-bold" style={{ color: p.TEXT }}>{fmtHoras(cancelamento.tempoMedioHoras)}</p>
                                <p className="text-[11px]" style={{ color: p.MUTED }}>até cancelar</p>
                            </div>
                        </div>
                        {cancelamento.porFornecedor.length > 0 ? (
                            <>
                                <p className="text-xs mb-2" style={{ color: p.MUTED }}>Fornecedores que mais cancelam</p>
                                <BarrasH cor={p.AMBER} p={p}
                                    items={cancelamento.porFornecedor.map(f => ({ label: f.fornecedor, valor: f.total }))} />
                            </>
                        ) : (
                            <p className="text-sm text-center py-3" style={{ color: p.MUTED }}>
                                Nenhum cancelamento no período.
                            </p>
                        )}
                    </Card>
                </div>

                {/* ── Evolução + Top fornecedores ────────────────────────────── */}
                <div className="grid grid-cols-1 lg:grid-cols-3 gap-4">

                    {/* Gráfico principal */}
                    <div className="lg:col-span-2">
                        <Card title={`Notas lançadas — últimos ${periodo} dias`} p={p}>
                            <Linha dados={linhaTotal} cor={p.ACCENT} altura={200} p={p} />
                            <div className="mt-4 pt-4" style={{ borderTop: `1px solid ${p.BORDER}` }}>
                                <p className="text-xs mb-2" style={{ color: p.MUTED }}>Liberadas por dia</p>
                                <Linha dados={linhaAtend} cor={p.GREEN} altura={100} p={p} />
                            </div>
                        </Card>
                    </div>

                    {/* Top fornecedores */}
                    <div>
                        <Card title="⚠️ Fornecedores com mais notas" p={p}>
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
                    <Card title="Distribuição por divergência" p={p}>
                        <Donut itens={donutMotivos} size={110} p={p} />
                        <div className="mt-5 space-y-2" style={{ borderTop: `1px solid ${p.BORDER}`, paddingTop: 16 }}>
                            {porMotivo.map(m => (
                                <div key={m.motivo} className="flex items-center justify-between">
                                    <div className="flex items-center gap-2">
                                        <span className="w-2 h-2 rounded-full"
                                            style={{ background: MOTIVO_COR[m.motivo] ?? p.MUTED }} />
                                        <span className="text-sm" style={{ color: p.TEXT }}>{m.motivo}</span>
                                    </div>
                                    <div className="flex items-center gap-3 text-xs tabular-nums shrink-0" style={{ color: p.MUTED }}>
                                        <span>{m.total} total</span>
                                        <span style={{ color: p.GREEN }}>{m.atendidas} atend.</span>
                                        <span className="font-semibold w-11 text-right" style={{ color: p.TEXT }}>
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
                                    <span className="text-sm w-16 shrink-0" style={{ color: p.TEXT }}>{lojaNome(l.loja)}</span>
                                    <div className="flex-1 rounded-full h-2.5 overflow-hidden min-w-0" style={{ background: p.BORDER }}>
                                        <div className="h-2.5 rounded-full"
                                            style={{
                                                width: `${pct(l.total, kpis.total)}%`,
                                                background: LOJA_CORES[i % LOJA_CORES.length],
                                            }} />
                                    </div>
                                    <span className="text-xs w-12 text-right tabular-nums shrink-0" style={{ color: p.MUTED }}>{l.total}</span>
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
                        <Card title="Notas por dia da semana" p={p}>
                            <BarrasV
                                items={porDiaSemana.map(d => ({ label: d.dia, valor: d.total }))}
                                cor={p.PURPLE} altura={150} p={p} />
                        </Card>
                        <Card title="Notas por hora do dia" p={p}>
                            <BarrasV
                                items={porHora.map(h => ({ label: h.hora, valor: h.total }))}
                                cor={p.AMBER} altura={150} p={p} />
                            <p className="text-xs text-center mt-1" style={{ color: p.MUTED }}>
                                Passe o mouse nas barras para ver o total de cada hora
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
                    {(fornecedoresPorMotivo[motivoForn] ?? []).length === 0 ? (
                        <p className="text-sm text-center py-4" style={{ color: p.MUTED }}>
                            Sem divergências de <strong style={{ color: p.TEXT }}>{motivoForn}</strong> no período.
                        </p>
                    ) : (
                        <BarrasH
                            items={(fornecedoresPorMotivo[motivoForn] ?? []).map(f => ({
                                label: f.fornecedor, valor: f.total,
                                cor: MOTIVO_COR[motivoForn] ?? p.ACCENT,
                            }))}
                            cor={p.ACCENT} p={p}
                        />
                    )}
                </Card>

                {/* ── Reincidentes + Ranking usuários ───────────────────────── */}
                <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">

                    <Card title="Fornecedores reincidentes (3+ notas com divergência)" p={p}>
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
                                                {/* Cor vem do nível calculado no backend (trait TemIdade) */}
                                                <span className="font-bold" style={{ color: nivelCor(pen.nivel, p as any) }}>
                                                    {idadeTexto(pen.dias_aberta)}
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
