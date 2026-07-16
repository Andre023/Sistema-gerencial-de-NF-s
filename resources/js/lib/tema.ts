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
