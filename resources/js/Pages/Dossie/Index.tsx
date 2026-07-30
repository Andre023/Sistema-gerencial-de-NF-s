import { useState, useEffect, useRef } from 'react';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout';
import { Head, router } from '@inertiajs/react';
import { useTheme } from '@/Contexts/ThemeContext';
import { Fornecedor, StatusNota, TipoCard, OrigemNota } from '@/types';
import { TIPO_CARD_LABEL, STATUS_NOTA_LABEL, ORIGEM_LABEL } from '@/lib/tema';
import {
    DARK, LIGHT, Palette, Card, KpiCard, Linha, BarrasH, Donut, ComparaRede,
    LOJA_CORES_DARK, LOJA_CORES_LIGHT, lojaNome, fmtHoras,
} from '@/Components/estatisticas/Graficos';

interface Divergencia { motivo: string; total: number; taxa: number; taxaRede: number; vezes: number | null }
interface Evolucao { mes: string; total: number; liberadas: number; divergencias: number }
interface PorLoja { loja: number; total: number }
interface UltimaNota {
    id: number; numero_nota: string; loja: number; status: StatusNota; origem: OrigemNota;
    ceasa: number; cards: { tipo: TipoCard; status: string; reaberturas: number }[];
    dias_aberta: number; created_at: string; liberada_em: string | null;
}
interface Dossie {
    kpis: {
        total: number; liberadas: number; canceladas: number;
        qualidade: number | null; qualidadeRede: number | null;
        tempoMedioHoras: number | null; tempoRedeHoras: number | null;
        reaberturas: number; taxaCancelamento: number;
    };
    divergencias: Divergencia[];
    evolucao: Evolucao[];
    porLoja: PorLoja[];
    ultimas: UltimaNota[];
}

interface Props {
    busca: string;
    periodo: number;
    resultados: Fornecedor[];
    fornecedor: Fornecedor | null;
    dossie: Dossie | null;
}

const mesNome = (m: string) => {
    const [ano, mes] = m.split('-');
    return `${['jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'][+mes - 1]}/${ano.slice(2)}`;
};

export default function Index({ busca, periodo, resultados, fornecedor, dossie }: Props) {
    const { isDark } = useTheme();
    const p: Palette = isDark ? DARK : LIGHT;
    const CORES = isDark ? LOJA_CORES_DARK : LOJA_CORES_LIGHT;

    const [termo, setTermo] = useState(busca);
    const primeiro = useRef(true);

    // Busca debounced — a URL guarda o estado (dá para compartilhar o link)
    useEffect(() => {
        if (primeiro.current) { primeiro.current = false; return; }
        const t = setTimeout(() => {
            router.get(route('dossie.index'), termo ? { busca: termo, periodo } : { periodo },
                { preserveState: true, preserveScroll: true, replace: true });
        }, 350);
        return () => clearTimeout(t);
    }, [termo]);

    const abrir = (id: number) => router.get(route('dossie.index'),
        { fornecedor_id: id, busca: termo, periodo }, { preserveState: true, preserveScroll: true });

    const mudarPeriodo = (d: number) => router.get(route('dossie.index'),
        { fornecedor_id: fornecedor?.id, busca: termo, periodo: d }, { preserveState: true, preserveScroll: true });

    const k = dossie?.kpis;

    return (
        <AuthenticatedLayout header={null}>
            <Head title="Dossiê do fornecedor" />

            <div className="min-h-screen py-6 px-4 sm:px-6 lg:px-8 max-w-screen-xl mx-auto space-y-5 transition-colors duration-200"
                style={{ background: p.BG }}>

                <div>
                    <h1 className="text-lg font-semibold" style={{ color: p.TEXT }}>Dossiê do fornecedor</h1>
                    <p className="text-sm mt-1" style={{ color: p.MUTED }}>
                        Busque um fornecedor para ver o histórico dele comparado com a média da rede.
                    </p>
                </div>

                {/* ── Busca ── */}
                <Card p={p}>
                    <input value={termo} onChange={e => setTermo(e.target.value)}
                        placeholder="Buscar fornecedor por nome..."
                        className="block w-full rounded-lg text-sm px-3 py-2.5 outline-none"
                        style={{ background: p.BG, color: p.TEXT, border: `1px solid ${p.BORDER}` }} />

                    {termo.trim() !== '' && resultados.length > 0 && (
                        <div className="mt-3 max-h-60 overflow-y-auto">
                            {resultados.map(f => (
                                <button key={f.id} onClick={() => abrir(f.id)}
                                    className="w-full flex items-center gap-2 py-2 px-2 text-left rounded-lg transition"
                                    style={{ background: fornecedor?.id === f.id ? p.ACCENT + '1a' : 'transparent' }}
                                    onMouseEnter={e => (e.currentTarget.style.background = p.HOVER_ROW)}
                                    onMouseLeave={e => (e.currentTarget.style.background = fornecedor?.id === f.id ? p.ACCENT + '1a' : 'transparent')}>
                                    {f.prioridade && <span style={{ color: p.AMBER }}>★</span>}
                                    <span className="text-sm truncate" style={{ color: p.TEXT }}>{f.nome}</span>
                                    {f.cnpj && <span className="ml-auto text-xs shrink-0" style={{ color: p.MUTED }}>{f.cnpj}</span>}
                                </button>
                            ))}
                        </div>
                    )}
                    {termo.trim() !== '' && resultados.length === 0 && (
                        <p className="text-sm mt-3" style={{ color: p.MUTED }}>Nenhum fornecedor encontrado.</p>
                    )}
                </Card>

                {!fornecedor && (
                    <Card p={p}>
                        <p className="text-sm text-center py-10" style={{ color: p.MUTED }}>
                            Escolha um fornecedor acima para abrir o dossiê.
                        </p>
                    </Card>
                )}

                {fornecedor && dossie && k && (
                    <>
                        {/* ── Cabeçalho + período ── */}
                        <div className="flex items-end justify-between gap-3 flex-wrap">
                            <div>
                                <h2 className="text-xl font-bold flex items-center gap-2" style={{ color: p.TEXT }}>
                                    {fornecedor.prioridade && <span style={{ color: p.AMBER }} title="Fornecedor prioritário">★</span>}
                                    {fornecedor.nome}
                                </h2>
                                {fornecedor.cnpj && <p className="text-xs mt-0.5" style={{ color: p.MUTED }}>CNPJ {fornecedor.cnpj}</p>}
                            </div>
                            <div className="flex gap-1.5">
                                {[30, 90, 180, 365].map(d => (
                                    <button key={d} onClick={() => mudarPeriodo(d)}
                                        className="text-xs font-medium px-2.5 py-1.5 rounded-lg transition"
                                        style={{
                                            background: periodo === d ? p.ACCENT + '22' : 'transparent',
                                            color: periodo === d ? p.ACCENT : p.MUTED,
                                            border: `1px solid ${periodo === d ? p.ACCENT : p.BORDER}`,
                                        }}>
                                        {d === 365 ? '1 ano' : `${d}d`}
                                    </button>
                                ))}
                            </div>
                        </div>

                        {/* ── KPIs ── */}
                        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
                            <KpiCard label="Notas no período" valor={k.total}
                                sub={`${k.liberadas} liberadas`} p={p} />
                            <KpiCard label="Índice de qualidade" valor={k.qualidade !== null ? `${k.qualidade}%` : '—'}
                                sub="notas sem nenhuma divergência"
                                trend={k.qualidade !== null && k.qualidadeRede !== null
                                    ? `${Math.abs(Math.round(k.qualidade - k.qualidadeRede))} p.p. vs. rede` : undefined}
                                trendUp={(k.qualidade ?? 0) >= (k.qualidadeRede ?? 0)} p={p} />
                            <KpiCard label="Tempo médio até liberar" valor={fmtHoras(k.tempoMedioHoras)}
                                sub={`rede: ${fmtHoras(k.tempoRedeHoras)}`} p={p} />
                            <KpiCard label="Canceladas" valor={k.canceladas}
                                sub={k.taxaCancelamento > 0 ? `${k.taxaCancelamento}% das notas` : 'nenhum cancelamento'} p={p} />
                        </div>

                        {/* ── Comparação com a rede ── */}
                        <div className="grid lg:grid-cols-2 gap-4">
                            <Card title="Este fornecedor × média da rede" p={p}>
                                <div className="space-y-4">
                                    {k.qualidade !== null && k.qualidadeRede !== null && (
                                        <ComparaRede label="Notas limpas (sem divergência)"
                                            valor={k.qualidade} media={k.qualidadeRede} p={p} />
                                    )}
                                    {k.tempoMedioHoras !== null && k.tempoRedeHoras !== null && (
                                        <ComparaRede label="Horas até liberar" valor={k.tempoMedioHoras}
                                            media={k.tempoRedeHoras} sufixo=" h" inverter p={p} />
                                    )}
                                    {dossie.divergencias.length === 0 && (
                                        <p className="text-sm" style={{ color: p.GREEN }}>
                                            Nenhuma divergência registrada no período. 👏
                                        </p>
                                    )}
                                </div>
                            </Card>

                            <Card title="Onde este fornecedor erra" p={p}>
                                {dossie.divergencias.length === 0 ? (
                                    <p className="text-sm text-center py-6" style={{ color: p.MUTED }}>Sem divergências no período.</p>
                                ) : (
                                    <div className="space-y-3">
                                        {dossie.divergencias.map(d => (
                                            <div key={d.motivo} className="flex items-center gap-3">
                                                <span className="text-xs w-24 truncate shrink-0 text-right" style={{ color: p.MUTED }}>{d.motivo}</span>
                                                <div className="flex-1 rounded-full h-5 overflow-hidden" style={{ background: p.BAR_BG }}>
                                                    <div className="h-5 rounded-full flex items-center justify-end pr-2"
                                                        style={{
                                                            width: `${Math.max(Math.min(d.taxa, 100), 4)}%`,
                                                            background: d.vezes && d.vezes >= 1.5 ? p.RED : p.ACCENT,
                                                        }}>
                                                        <span className="text-xs font-semibold text-white">{d.total}</span>
                                                    </div>
                                                </div>
                                                <span className="text-[11px] w-24 shrink-0"
                                                    style={{ color: d.vezes && d.vezes >= 1.5 ? p.RED : p.MUTED }}>
                                                    {d.vezes !== null
                                                        ? `${d.vezes.toString().replace('.', ',')}× a rede`
                                                        : `${d.taxa}%`}
                                                </span>
                                            </div>
                                        ))}
                                        <p className="text-[11px] pt-1" style={{ color: p.MUTED }}>
                                            Comparação por 100 notas — vermelho = 1,5× ou mais acima da média da rede.
                                        </p>
                                    </div>
                                )}
                            </Card>
                        </div>

                        {/* ── Evolução ── */}
                        <Card title="Evolução mensal" p={p}
                            action={<span className="text-xs" style={{ color: p.MUTED }}>notas lançadas × divergências</span>}>
                            {dossie.evolucao.length < 2 ? (
                                <p className="text-sm text-center py-6" style={{ color: p.MUTED }}>
                                    Precisa de pelo menos 2 meses de histórico no período escolhido.
                                </p>
                            ) : (
                                <>
                                    <Linha dados={dossie.evolucao.map(e => ({ label: mesNome(e.mes), valor: e.total }))}
                                        cor={p.ACCENT} p={p} />
                                    <div className="mt-4">
                                        <p className="text-xs mb-2" style={{ color: p.MUTED }}>Divergências por mês</p>
                                        <BarrasH cor={p.AMBER} p={p}
                                            items={dossie.evolucao.map(e => ({ label: mesNome(e.mes), valor: e.divergencias }))} />
                                    </div>
                                </>
                            )}
                        </Card>

                        {/* ── Loja + retrabalho ── */}
                        <div className="grid lg:grid-cols-2 gap-4">
                            <Card title="Notas por loja" p={p}>
                                <Donut p={p} itens={dossie.porLoja.map((l, i) => ({
                                    label: lojaNome(l.loja), valor: l.total, cor: CORES[i % CORES.length],
                                }))} />
                            </Card>

                            <Card title="Retrabalho" p={p}>
                                <div className="flex items-center gap-4">
                                    <div className="text-center px-4">
                                        <p className="text-3xl font-bold" style={{ color: k.reaberturas > 0 ? p.RED : p.GREEN }}>
                                            {k.reaberturas}
                                        </p>
                                        <p className="text-xs mt-1" style={{ color: p.MUTED }}>reaberturas</p>
                                    </div>
                                    <p className="text-sm flex-1" style={{ color: p.MUTED }}>
                                        {k.reaberturas > 0
                                            ? 'Cards que compras marcou como corrigidos e o pré-lote precisou reabrir — é o retrabalho que este fornecedor gera.'
                                            : 'Nenhuma correção precisou ser refeita no período.'}
                                    </p>
                                </div>
                            </Card>
                        </div>

                        {/* ── Últimas notas ── */}
                        <Card title="Últimas notas" p={p}>
                            <div className="overflow-x-auto">
                                <table className="min-w-full">
                                    <thead>
                                        <tr style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                            {['Nota', 'Loja', 'Fila', 'Status', 'Divergências', 'Lançada', 'Liberada'].map(c => (
                                                <th key={c} className="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide whitespace-nowrap"
                                                    style={{ color: p.MUTED }}>{c}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {dossie.ultimas.length === 0 ? (
                                            <tr><td colSpan={7} className="px-3 py-8 text-center text-sm" style={{ color: p.MUTED }}>
                                                Nenhuma nota deste fornecedor.
                                            </td></tr>
                                        ) : dossie.ultimas.map(n => (
                                            <tr key={n.id} style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                                                <td className="px-3 py-2.5 text-sm font-medium whitespace-nowrap" style={{ color: p.TEXT }}>
                                                    {n.numero_nota}
                                                    {n.ceasa > 0 && (
                                                        <span className="ml-1.5 text-[10px] font-bold px-1 py-0.5 rounded"
                                                            style={{ background: p.PURPLE + '22', color: p.PURPLE }}>
                                                            {n.ceasa === 3 ? 'CEASA' : `CEASA ${n.ceasa}`}
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="px-3 py-2.5 text-sm whitespace-nowrap" style={{ color: p.TEXT }}>{lojaNome(n.loja)}</td>
                                                <td className="px-3 py-2.5 text-xs whitespace-nowrap" style={{ color: p.MUTED }}>{ORIGEM_LABEL[n.origem]}</td>
                                                <td className="px-3 py-2.5 text-xs whitespace-nowrap">
                                                    <span className="px-2 py-0.5 rounded font-medium" style={{
                                                        background: (n.status === 'liberada' ? p.GREEN : n.status === 'cancelada' ? p.MUTED : p.AMBER) + '1a',
                                                        color: n.status === 'liberada' ? p.GREEN : n.status === 'cancelada' ? p.MUTED : p.AMBER,
                                                    }}>
                                                        {STATUS_NOTA_LABEL[n.status]}
                                                    </span>
                                                </td>
                                                <td className="px-3 py-2.5 text-xs" style={{ color: p.MUTED }}>
                                                    {n.cards.length === 0 ? '—' : n.cards.map(c =>
                                                        `${TIPO_CARD_LABEL[c.tipo] ?? c.tipo}${c.reaberturas > 0 ? ` (${c.reaberturas}x reaberto)` : ''}`
                                                    ).join(', ')}
                                                </td>
                                                <td className="px-3 py-2.5 text-xs whitespace-nowrap" style={{ color: p.MUTED }}>{n.created_at}</td>
                                                <td className="px-3 py-2.5 text-xs whitespace-nowrap" style={{ color: p.MUTED }}>{n.liberada_em ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        </Card>
                    </>
                )}
            </div>
        </AuthenticatedLayout>
    );
}
