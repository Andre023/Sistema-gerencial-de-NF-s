// Dados e helpers do avatar personalizado (emoji com tom de pele, monograma, foto).

// ─── Emojis de pessoas (a grade do seletor) ────────────────────────────────────
// Personagens agrupados por gesto / cabelo / profissão (roupa de trabalho) /
// trajes e realeza / fantasia. Cada um rende 6 variações (padrão + 5 tons de
// pele), menos os que o Unicode não define tom (gênio, zumbi, troll).

export const EMOJIS_BASE: string[] = [
    // Gestos
    '🙋', '🙋‍♀️', '🙋‍♂️', '🙆‍♀️', '🙆‍♂️', '💁‍♀️', '💁‍♂️', '🤷‍♀️', '🤷‍♂️',
    // Cabelos (cortes e cores)
    '🧑‍🦰', '🧑‍🦱', '🧑‍🦳', '🧑‍🦲',
    // Profissões (roupa de trabalho)
    '🧑‍💼', '👨‍💼', '👩‍💼', '👷', '👷‍♀️', '🧑‍🔧', '🧑‍🍳', '🧑‍🌾', '🧑‍🏫',
    '🧑‍💻', '🧑‍🔬', '🧑‍⚕️', '🕵️', '👮', '💂', '🧑‍✈️', '🧑‍🚀',
    '🧑‍🚒', '🧑‍🎨', '🧑‍⚖️', '🧑‍🎤', '🧑‍🏭', '🧑‍🎓',
    // Trajes e realeza
    '🤵', '👰', '🤴', '👸', '🫅', '👳', '🧕', '👲', '💃', '🕺',
    // Fantasia e personagens
    '🦸', '🦸‍♀️', '🦹', '🧙', '🧙‍♀️', '🧛', '🧝', '🧚', '🧞', '🥷',
    '🎅', '🤶', '🧑‍🎄', '🧜', '🧟', '🧌',
];

/** Modificadores Fitzpatrick de tom de pele (índice 0 = sem tom). */
export const TONS_PELE = ['', '🏻', '🏼', '🏽', '🏾', '🏿'];

export const TONS_LABEL = ['Padrão', 'Clara', 'Média-clara', 'Parda', 'Média-escura', 'Escura'];

/** Emojis que NÃO aceitam tom de pele (o Unicode não define pele pra eles). */
const SEM_TOM = new Set(['🧞', '🧟', '🧌']);

export const aceitaTom = (base: string) => !SEM_TOM.has(base);

/**
 * Aplica um tom de pele a um emoji de pessoa. O modificador entra logo após o
 * primeiro codepoint (a "pessoa"), antes de qualquer ZWJ (gênero/profissão).
 * Ex.: 🧑‍💼 + 🏾 → 🧑🏾‍💼. Devolve o próprio base quando ele não aceita tom.
 */
export function aplicarTom(base: string, tomIdx: number): string {
    if (tomIdx <= 0 || SEM_TOM.has(base)) return base;
    const cps = Array.from(base);
    return cps[0] + TONS_PELE[tomIdx] + cps.slice(1).join('');
}

// ─── Monograma (iniciais numa cor) ─────────────────────────────────────────────

/** Paleta do monograma — cores fortes o bastante para texto branco por cima. */
export const CORES_MONOGRAMA = [
    '#2f81f7', '#8250df', '#1a7f37', '#c2410c', '#d1242f',
    '#0e7490', '#4f46e5', '#be185d', '#b45309', '#0f766e',
];

/** Cor estável derivada do nome (usada quando a pessoa não escolheu uma). */
export function corDoNome(nome: string): string {
    let hash = 0;
    for (let i = 0; i < nome.length; i++) hash = (hash + nome.charCodeAt(i)) % 997;
    return CORES_MONOGRAMA[hash % CORES_MONOGRAMA.length];
}

/** Iniciais: primeira letra do primeiro e do último nome (ou as 2 primeiras). */
export function iniciais(nome: string): string {
    const partes = nome.trim().split(/\s+/).filter(Boolean);
    if (partes.length === 0) return '?';
    if (partes.length === 1) return partes[0].slice(0, 2).toUpperCase();
    return (partes[0][0] + partes[partes.length - 1][0]).toUpperCase();
}
