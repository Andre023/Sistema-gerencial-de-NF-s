import { format } from 'date-fns';
import { useTheme } from '@/Contexts/ThemeContext';

// ─── Paleta ─────────────────────────────────────────────────────────────────────
// Superconjunto usado por todas as telas do painel (Requisições, Cadastros, Usuários).

export interface Palette {
    BG: string; SURFACE: string; BORDER: string; TEXT: string; MUTED: string;
    ACCENT: string; GREEN: string; RED: string; AMBER: string; PURPLE: string; ORANGE: string;
    INPUT_BG: string; INPUT_BORDER: string; HOVER_ROW: string; TOOLTIP_BG: string;
}

export const DARK: Palette = {
    BG: '#0d1117', SURFACE: '#161b22', BORDER: '#21262d', TEXT: '#e6edf3', MUTED: '#7d8590',
    ACCENT: '#2f81f7', GREEN: '#3fb950', RED: '#f85149', AMBER: '#d29922', PURPLE: '#a371f7', ORANGE: '#e3954a',
    INPUT_BG: '#0d1117', INPUT_BORDER: '#30363d', HOVER_ROW: '#21262d', TOOLTIP_BG: '#30363d',
};

export const LIGHT: Palette = {
    BG: '#f6f8fa', SURFACE: '#ffffff', BORDER: '#d0d7de', TEXT: '#1f2328', MUTED: '#656d76',
    ACCENT: '#0969da', GREEN: '#1a7f37', RED: '#d1242f', AMBER: '#9a6700', PURPLE: '#8250df', ORANGE: '#c2410c',
    INPUT_BG: '#ffffff', INPUT_BORDER: '#d0d7de', HOVER_ROW: '#f6f8fa', TOOLTIP_BG: '#1f2328',
};

// ─── Cores por motivo (Requisições + Cadastros — chaves não colidem) ────────────

interface CorMotivo { bg: string; text: string; border: string }

export const MOTIVO_COR_DARK: Record<string, CorMotivo> = {
    Cadastro: { bg: 'rgba(47,129,247,0.15)', text: '#79c0ff', border: 'rgba(47,129,247,0.3)' },
    Preço: { bg: 'rgba(210,153,34,0.15)', text: '#e3b341', border: 'rgba(210,153,34,0.3)' },
    Regra: { bg: 'rgba(248,81,73,0.15)', text: '#ff7b72', border: 'rgba(248,81,73,0.3)' },
    Quantidade: { bg: 'rgba(163,113,247,0.15)', text: '#d2a8ff', border: 'rgba(163,113,247,0.3)' },
    Pedido: { bg: 'rgba(63,185,80,0.15)', text: '#56d364', border: 'rgba(63,185,80,0.3)' },
    'Pré Lote': { bg: 'rgba(47,129,247,0.15)', text: '#79c0ff', border: 'rgba(47,129,247,0.3)' },
    'Caminhão na Porta': { bg: 'rgba(227,149,74,0.15)', text: '#f0a868', border: 'rgba(227,149,74,0.3)' },
};

export const MOTIVO_COR_LIGHT: Record<string, CorMotivo> = {
    Cadastro: { bg: '#dbeafe', text: '#1d4ed8', border: '#bfdbfe' },
    Preço: { bg: '#fef9c3', text: '#854d0e', border: '#fde68a' },
    Regra: { bg: '#fee2e2', text: '#b91c1c', border: '#fecaca' },
    Quantidade: { bg: '#f3e8ff', text: '#7e22ce', border: '#e9d5ff' },
    Pedido: { bg: '#dcfce7', text: '#15803d', border: '#bbf7d0' },
    'Pré Lote': { bg: '#dbeafe', text: '#1d4ed8', border: '#bfdbfe' },
    'Caminhão na Porta': { bg: '#ffedd5', text: '#9a3412', border: '#fed7aa' },
};

// ─── Cards de divergência (tipos vêm do backend — Card::TIPOS) ─────────────────

export const TIPO_CARD_LABEL: Record<string, string> = {
    cadastro: 'Cadastro',
    regra: 'Regra',
    custo: 'Custo',
    quantidade: 'Quantidade',
    sem_pedido: 'Sem pedido',
    item_n_pedido: 'Item n pedido',
    importar_nf: 'Importar NF',
    reconferir: 'Reconferir',
    recusa: 'Recusa',
    devolucao: 'Devolução',
};

export const CARD_COR_DARK: Record<string, { bg: string; text: string; border: string }> = {
    cadastro: { bg: 'rgba(47,129,247,0.15)', text: '#79c0ff', border: 'rgba(47,129,247,0.35)' },
    regra: { bg: 'rgba(248,81,73,0.15)', text: '#ff7b72', border: 'rgba(248,81,73,0.35)' },
    custo: { bg: 'rgba(210,153,34,0.15)', text: '#e3b341', border: 'rgba(210,153,34,0.35)' },
    quantidade: { bg: 'rgba(163,113,247,0.15)', text: '#d2a8ff', border: 'rgba(163,113,247,0.35)' },
    sem_pedido: { bg: 'rgba(20,184,166,0.15)', text: '#2dd4bf', border: 'rgba(20,184,166,0.35)' },
    // Amarelo vivo — de propósito mais aberto que o âmbar do "custo", que fica na fila ao lado
    item_n_pedido: { bg: 'rgba(250,204,21,0.15)', text: '#f2e05e', border: 'rgba(250,204,21,0.35)' },
    importar_nf: { bg: 'rgba(63,185,80,0.15)', text: '#56d364', border: 'rgba(63,185,80,0.35)' },
    reconferir: { bg: 'rgba(227,149,74,0.15)', text: '#f0a868', border: 'rgba(227,149,74,0.35)' },
    // Cinza-ardósia: lê como "parou aqui" e não disputa com o vermelho de "regra"
    recusa: { bg: 'rgba(100,116,139,0.18)', text: '#a6b3c2', border: 'rgba(100,116,139,0.4)' },
    // Índigo — a única faixa do azul ainda livre (cadastro usa o azul aberto)
    devolucao: { bg: 'rgba(99,102,241,0.15)', text: '#a5b4fc', border: 'rgba(99,102,241,0.35)' },
};

export const CARD_COR_LIGHT: Record<string, { bg: string; text: string; border: string }> = {
    cadastro: { bg: '#dbeafe', text: '#1d4ed8', border: '#93c5fd' },
    regra: { bg: '#fee2e2', text: '#b91c1c', border: '#fca5a5' },
    custo: { bg: '#fef9c3', text: '#854d0e', border: '#fde047' },
    quantidade: { bg: '#f3e8ff', text: '#7e22ce', border: '#d8b4fe' },
    sem_pedido: { bg: '#ccfbf1', text: '#0f766e', border: '#5eead4' },
    item_n_pedido: { bg: '#fef08a', text: '#713f12', border: '#facc15' },
    importar_nf: { bg: '#dcfce7', text: '#15803d', border: '#86efac' },
    reconferir: { bg: '#ffedd5', text: '#9a3412', border: '#fed7aa' },
    recusa: { bg: '#e2e8f0', text: '#334155', border: '#94a3b8' },
    devolucao: { bg: '#e0e7ff', text: '#4338ca', border: '#a5b4fc' },
};

export const STATUS_NOTA_LABEL: Record<string, string> = {
    pendente: 'Aguardando análise',
    com_divergencia: 'Com divergência',
    reconferir: 'Reconferir',
    liberada: 'Liberada',
    cancelada: 'Cancelada',
};

// ─── Sino (tipos vêm do backend — Notificacao::TIPOS) ──────────────────────────

/** O que a notificação está pedindo de quem recebeu. */
export const NOTIFICACAO_LABEL: Record<string, string> = {
    divergencia: 'Divergência para corrigir',
    reaberto: 'Reaberta — continua errada',
    corrigido: 'Compras corrigiu — reconferir',
    liberada: 'Nota liberada',
    lancada: 'Nota recém lançada — analisar',
    doca: 'Resolver na doca',
};

/** Cor da barra lateral do aviso: vermelho pede ação, verde é conclusão. */
export function notificacaoCor(tipo: string, p: Palette): string {
    switch (tipo) {
        case 'divergencia': return p.AMBER;
        case 'reaberto':    return p.RED;
        case 'corrigido':   return p.ACCENT;
        case 'liberada':    return p.GREEN;
        case 'lancada':     return p.PURPLE;
        // Laranja: a mercadoria está parada esperando alguém decidir o que fazer
        case 'doca':        return p.ORANGE;
        default:            return p.MUTED;
    }
}

export const PAPEL_LABEL: Record<string, string> = {
    recebimento: 'Recebimento',
    pre_lote: 'Pré-lote',
    compras: 'Compras',
    visitante: 'Visitante',
    admin: 'Admin',
};

/** Nome de cada fila (origem da nota), usado em mensagens ao usuário */
export const ORIGEM_LABEL: Record<string, string> = {
    recebimento: 'Caminhão na porta',
    pre_lote: 'Pré-lote',
};

// ─── Severidade de pendência (níveis vêm do backend — trait TemIdade) ──────────

export type Nivel = 'normal' | 'atencao' | 'alerta' | 'critico';

/** Cor de cada nível. Os limiares em dias moram no backend; aqui só a aparência. */
export function nivelCor(nivel: Nivel, p: Palette): string {
    switch (nivel) {
        case 'critico': return p.RED;
        case 'alerta':  return p.ORANGE;
        case 'atencao': return p.AMBER;
        default:        return p.MUTED;
    }
}

export const NIVEL_LABEL: Record<Exclude<Nivel, 'normal'>, string> = {
    critico: 'críticas',
    alerta: 'em alerta',
    atencao: 'em atenção',
};

/** "Hoje", "1 dia", "12 dias" */
export const idadeTexto = (dias: number) =>
    dias === 0 ? 'Hoje' : dias === 1 ? '1 dia' : `${dias} dias`;

// ─── Helpers ─────────────────────────────────────────────────────────────────────

export const lojaNome = (n: number) => `Loja ${String(n).padStart(2, '0')}`;
export const hoje = () => format(new Date(), 'yyyy-MM-dd');

/** Retorna a paleta ativa conforme o tema atual. */
export function usePaleta(): { isDark: boolean; p: Palette } {
    const { isDark } = useTheme();
    return { isDark, p: isDark ? DARK : LIGHT };
}
