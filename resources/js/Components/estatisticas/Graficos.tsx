import React from 'react';

/**
 * Gráficos das telas de análise (Estatísticas e Dossiê do fornecedor).
 *
 * Tudo em SVG/CSS puro, sem biblioteca: o bundle continua leve — o que importa
 * numa VM de 1 GB — e o desenho segue a paleta do tema como o resto do painel.
 */

export interface Palette {
    BG: string; SURFACE: string; BORDER: string; TEXT: string; MUTED: string;
    ACCENT: string; GREEN: string; RED: string; AMBER: string; PURPLE: string;
    BAR_BG: string; HOVER_ROW: string; TOOLTIP_BG: string;
}

export const DARK: Palette = {
    BG: '#0d1117', SURFACE: '#161b22', BORDER: '#21262d', TEXT: '#e6edf3', MUTED: '#7d8590',
    ACCENT: '#2f81f7', GREEN: '#3fb950', RED: '#f85149', AMBER: '#d29922', PURPLE: '#a371f7',
    BAR_BG: '#21262d', HOVER_ROW: '#21262d', TOOLTIP_BG: '#30363d',
};

export const LIGHT: Palette = {
    BG: '#f6f8fa', SURFACE: '#ffffff', BORDER: '#d0d7de', TEXT: '#1f2328', MUTED: '#656d76',
    ACCENT: '#0969da', GREEN: '#1a7f37', RED: '#d1242f', AMBER: '#9a6700', PURPLE: '#8250df',
    BAR_BG: '#eaeef2', HOVER_ROW: '#f6f8fa', TOOLTIP_BG: '#1f2328',
};

export const LOJA_CORES_DARK = ['#2f81f7', '#d29922', '#3fb950', '#f85149', '#a371f7', '#58a6ff', '#56d364', '#e3954a', '#2dd4bf'];
export const LOJA_CORES_LIGHT = ['#0969da', '#9a6700', '#1a7f37', '#d1242f', '#8250df', '#0550ae', '#116329', '#c2410c', '#0f766e'];

export const lojaNome = (n: number) => `Loja ${String(n).padStart(2, '0')}`;
export const fmtDia = (s: string) => s.slice(5).replace('-', '/');
export const pct = (v: number, t: number) => (t > 0 ? Math.round((v / t) * 100) : 0);

/** "12,5 h" / "45 min" — horas viram minutos quando o número fica pequeno demais. */
export const fmtHoras = (h: number | null): string => {
    if (h === null || h === undefined) return '—';
    if (h < 1) return `${Math.round(h * 60)} min`;
    if (h < 48) return `${h.toString().replace('.', ',')} h`;
    return `${(h / 24).toFixed(1).replace('.', ',')} dias`;
};

// ─── Card ─────────────────────────────────────────────────────────────────────

export function Card({ title, children, className = '', action, p }: {
    title?: string; children: React.ReactNode; className?: string;
    action?: React.ReactNode; p: Palette;
}) {
    return (
        <div className={`rounded-xl overflow-hidden ${className}`}
            style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
            {title && (
                <div className="flex items-center justify-between px-4 sm:px-5 py-3.5 gap-3 flex-wrap"
                    style={{ borderBottom: `1px solid ${p.BORDER}` }}>
                    <h3 className="text-sm font-semibold" style={{ color: p.TEXT }}>{title}</h3>
                    {action}
                </div>
            )}
            <div className="p-4 sm:p-5">{children}</div>
        </div>
    );
}

// ─── KPI ──────────────────────────────────────────────────────────────────────

export function KpiCard({ label, valor, sub, trend, trendUp, p }: {
    label: string; valor: string | number; sub?: string;
    trend?: string; trendUp?: boolean; p: Palette;
}) {
    return (
        // h-full + justify-between: todos os cards da fileira ficam da MESMA
        // altura, com ou sem trend (antes os que tinham trend desalinhavam).
        <div className="rounded-xl p-4 flex flex-col justify-between gap-1.5 h-full min-w-0"
            style={{ background: p.SURFACE, border: `1px solid ${p.BORDER}` }}>
            <p className="text-[11px] font-medium uppercase tracking-wide leading-tight" style={{ color: p.MUTED }}>
                {label}
            </p>
            <p className="text-2xl font-bold leading-none tabular-nums break-words" style={{ color: p.TEXT }}>
                {valor}
            </p>
            {/* Espaço reservado mesmo sem trend, para a fileira não desalinhar */}
            <div className="min-h-[15px]">
                {trend && (
                    <span className="text-[11px] font-semibold whitespace-nowrap" style={{ color: trendUp ? p.GREEN : p.RED }}>
                        {trendUp ? '↑' : '↓'} {trend}
                    </span>
                )}
            </div>
            {sub && <p className="text-[11px] leading-tight" style={{ color: p.MUTED }}>{sub}</p>}
        </div>
    );
}

// ─── Linha (SVG) ──────────────────────────────────────────────────────────────

export function Linha({ dados, cor, altura = 200, onPonto, p }: {
    dados: { label: string; valor: number }[]; cor: string; altura?: number;
    /** Clique num ponto (índice) — usado para abrir o dia daquele ponto */
    onPonto?: (indice: number) => void;
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

    // Ticks SEM repetição: com poucos dados (máximo 1 ou 2) as 5 frações
    // arredondavam para "0 0 1 1 1" no eixo. Aqui o passo é inteiro e único.
    const passo = Math.max(1, Math.ceil(maximo / 4));
    const yTicks = [];
    for (let v = 0; v <= maximo; v += passo) {
        yTicks.push({ y: pad.t + innerH - (v / maximo) * innerH, val: v });
    }

    const xStep2 = Math.ceil(dados.length / 10);
    const xLabels = dados.filter((_, i) => i % xStep2 === 0 || i === dados.length - 1);
    const gradId = `grad-${cor.replace('#', '')}-${altura}`;

    return (
        /* O desenho tem 800 de largura interna. Espremido numa tela de 375px os
           rótulos dos eixos viravam borrões de 4px — aqui ele mantém um mínimo
           legível e rola para o lado no celular. */
        <div className="rolagem-x">
        <svg viewBox={`0 0 ${w} ${h}`} className="w-full min-w-[560px]" style={{ height: altura }}>
            <defs>
                <linearGradient id={gradId} x1="0" y1="0" x2="0" y2="1">
                    <stop offset="0%" stopColor={cor} stopOpacity="0.25" />
                    <stop offset="100%" stopColor={cor} stopOpacity="0" />
                </linearGradient>
            </defs>
            {yTicks.map((t, i) => (
                <g key={i}>
                    <line x1={pad.l} y1={t.y} x2={w - pad.r} y2={t.y}
                        stroke={p.BORDER} strokeWidth="1" strokeDasharray="4 4" />
                    <text x={pad.l - 6} y={t.y + 4} textAnchor="end" fontSize="9" fill={p.MUTED}>{t.val}</text>
                </g>
            ))}
            <polygon points={area} fill={`url(#${gradId})`} />
            <polyline points={polyline} fill="none" stroke={cor}
                strokeWidth="2.5" strokeLinejoin="round" strokeLinecap="round" />
            {pts.map((pt, i) => (
                <g key={i} onClick={onPonto ? () => onPonto(i) : undefined}
                    style={{ cursor: onPonto ? 'pointer' : 'default' }}>
                    {/* Alvo invisível e generoso: acertar um ponto de 3px é sofrido */}
                    {onPonto && <circle cx={pt.x} cy={pt.y} r="12" fill="transparent" />}
                    <circle cx={pt.x} cy={pt.y} r="3.5" fill={cor} stroke={p.SURFACE} strokeWidth="2" />
                    <title>{pt.label}: {pt.valor}{onPonto ? ' — clique para ver o dia' : ''}</title>
                </g>
            ))}
            {xLabels.map((d, i) => {
                const idx = dados.indexOf(d);
                return (
                    <text key={i} x={pad.l + idx * xStep} y={h - 6}
                        textAnchor="middle" fontSize="9" fill={p.MUTED}>{d.label}</text>
                );
            })}
        </svg>
        </div>
    );
}

// ─── Barras horizontais ───────────────────────────────────────────────────────

export function BarrasH({ items, cor, p }: {
    items: { label: string; valor: number; cor?: string }[]; cor: string; p: Palette;
}) {
    const maximo = Math.max(...items.map(i => i.valor), 1);

    return (
        <div className="space-y-3">
            {items.map((item, i) => {
                const larg = (item.valor / maximo) * 100;
                // O número só cabe DENTRO quando a barra é larga; abaixo disso ele
                // ia cortado (o "número que não dá para ler"). Aí vai para fora.
                const dentro = larg >= 22;

                return (
                    <div key={i} className="flex items-center gap-2.5">
                        <span className="text-xs w-20 sm:w-32 truncate shrink-0 text-right"
                            style={{ color: p.MUTED }} title={item.label}>
                            {item.label}
                        </span>
                        <div className="flex-1 rounded-full h-5 min-w-0" style={{ background: p.BAR_BG }}>
                            <div className="h-5 rounded-full flex items-center justify-end pr-2 transition-all duration-500"
                                style={{ width: `${Math.max(larg, item.valor > 0 ? 2 : 0)}%`, background: item.cor ?? cor }}>
                                {dentro && (
                                    <span className="text-xs font-semibold tabular-nums text-white">{item.valor}</span>
                                )}
                            </div>
                        </div>
                        {/* Coluna própria: nunca sobrepõe a barra nem some */}
                        <span className="text-xs font-semibold tabular-nums w-10 text-right shrink-0"
                            style={{ color: dentro ? 'transparent' : p.TEXT }}>
                            {item.valor}
                        </span>
                    </div>
                );
            })}
            {items.length === 0 && (
                <p className="text-sm text-center py-4" style={{ color: p.MUTED }}>Sem dados no período.</p>
            )}
        </div>
    );
}

// ─── Barras verticais ─────────────────────────────────────────────────────────

export function BarrasV({ items, altura = 160, cor, p }: {
    items: { label: string; valor: number; cor?: string }[]; altura?: number; cor: string; p: Palette;
}) {
    const maximo = Math.max(...items.map(i => i.valor), 1);
    // Com poucas barras o valor fica SEMPRE visível (não adianta esconder num
    // hover que ninguém descobre); com muitas, só no hover para não embolar.
    const sempre = items.length <= 12;

    if (items.length === 0) {
        return <p className="text-sm text-center py-6" style={{ color: p.MUTED }}>Sem dados no período.</p>;
    }

    return (
        <div className="flex items-end gap-1 rolagem-x pb-1" style={{ height: altura + 40 }}>
            {items.map((item, i) => {
                const h = Math.max((item.valor / maximo) * altura, item.valor > 0 ? 4 : 0);
                return (
                    <div key={i} className="flex flex-col items-center gap-1 min-w-[26px] flex-1 group">
                        <span className={`text-[10px] font-semibold tabular-nums whitespace-nowrap transition ${
                            sempre ? '' : 'opacity-0 group-hover:opacity-100 px-1.5 py-0.5 rounded'
                        }`}
                            style={sempre
                                ? { color: item.valor > 0 ? p.TEXT : p.MUTED }
                                // tooltip tem fundo escuro nos dois temas → texto branco
                                : { background: p.TOOLTIP_BG, color: '#fff' }}>
                            {item.valor}
                        </span>
                        <div className="w-full rounded-t transition-all duration-300"
                            style={{ height: h, background: item.cor ?? cor, opacity: 0.85, minWidth: 8 }} />
                        <span className="text-[10px] whitespace-nowrap" style={{ color: p.MUTED }}>{item.label}</span>
                    </div>
                );
            })}
        </div>
    );
}

// ─── Donut ────────────────────────────────────────────────────────────────────

export function Donut({ itens, size = 120, p }: {
    itens: { label: string; valor: number; cor: string }[]; size?: number; p: Palette;
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
                        strokeDasharray={s.dasharray} strokeDashoffset={-s.offset}
                        transform="rotate(-90 60 60)">
                        <title>{s.label}: {s.valor}</title>
                    </circle>
                ))}
                {/* Fonte encolhe conforme os dígitos — 12345 estourava o círculo */}
                <text x="60" y="56" textAnchor="middle" fontWeight="bold" fill={p.TEXT}
                    fontSize={total >= 10000 ? 13 : total >= 1000 ? 15 : 17}>
                    {total.toLocaleString('pt-BR')}
                </text>
                <text x="60" y="70" textAnchor="middle" fontSize="9" fill={p.MUTED}>total</text>
            </svg>
            <div className="flex flex-col gap-2 min-w-0 flex-1 basis-[220px]">
                {itens.map((item, i) => (
                    <div key={i} className="flex items-center gap-2 text-xs" style={{ color: p.MUTED }}>
                        <span className="w-2.5 h-2.5 rounded-full shrink-0" style={{ background: item.cor }} />
                        <span className="truncate" title={item.label}>{item.label}</span>
                        <span className="ml-auto font-semibold pl-3 tabular-nums shrink-0" style={{ color: p.TEXT }}>
                            {item.valor}
                        </span>
                        <span className="tabular-nums shrink-0 w-11 text-right">({pct(item.valor, total)}%)</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

/**
 * Barra comparativa "este × média da rede" — o coração do dossiê: um número
 * sozinho não diz nada, comparado com a média vira decisão.
 */
export function ComparaRede({ label, valor, media, sufixo = '%', inverter = false, p }: {
    label: string; valor: number; media: number; sufixo?: string;
    /** true quando MENOR é melhor (ex.: taxa de erro) */
    inverter?: boolean; p: Palette;
}) {
    const maximo = Math.max(valor, media, 1);
    const pior = inverter ? valor > media : valor < media;
    const cor = pior ? p.RED : p.GREEN;

    return (
        <div className="space-y-1.5">
            <div className="flex items-baseline justify-between gap-2">
                <span className="text-xs" style={{ color: p.MUTED }}>{label}</span>
                <span className="text-sm font-semibold" style={{ color: cor }}>
                    {valor.toString().replace('.', ',')}{sufixo}
                </span>
            </div>
            <div className="relative rounded-full h-2.5" style={{ background: p.BAR_BG }}>
                <div className="h-2.5 rounded-full transition-all duration-500"
                    style={{ width: `${(valor / maximo) * 100}%`, background: cor }} />
                {/* Marcador da média da rede */}
                <div className="absolute top-[-3px] w-0.5 h-[17px] rounded"
                    style={{ left: `${(media / maximo) * 100}%`, background: p.TEXT, opacity: 0.55 }}
                    title={`Média da rede: ${media}${sufixo}`} />
            </div>
            <p className="text-[11px]" style={{ color: p.MUTED }}>
                média da rede: {media.toString().replace('.', ',')}{sufixo}
            </p>
        </div>
    );
}
