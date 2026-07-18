// Tipos globais do sistema

export type Papel = 'recebimento' | 'pre_lote' | 'compras' | 'admin';

export interface User {
    id: number;
    name: string;
    email: string;
    role: Papel;
    email_verified_at?: string;
}

export interface Permissoes {
    lancarNota: boolean;
    gerirCards: boolean;
    corrigirCard: boolean;
    liberarNota: boolean;
    gerenciarNotas: boolean;
    verEstatisticas: boolean;
    gerenciarUsuarios: boolean;
}

export interface Fornecedor {
    id: number;
    nome: string;
    cnpj?: string | null;
}

export type TipoCard = 'cadastro' | 'regra' | 'custo' | 'quantidade';
export type StatusCard = 'aberto' | 'corrigido' | 'resolvido';

export interface Card {
    id: number;
    tipo: TipoCard;
    status: StatusCard;
    detalhe: string | null;
    reaberturas: number;
}

export type StatusNota = 'pendente' | 'com_divergencia' | 'reconferir' | 'liberada';
export type OrigemNota = 'recebimento' | 'pre_lote';

export interface Nota {
    id: number;
    numero_nota: string;
    fornecedor: Fornecedor;
    user: User;
    loja: number;
    origem: OrigemNota;
    observacao: string | null;
    status: StatusNota;
    cards: Card[];
    liberada_por: Pick<User, 'id' | 'name'> | null;
    liberada_em: string | null;
    comentarios_count: number;
    created_at: string;
    atrasada: boolean;
    dias_aberta: number;
    nivel: Nivel;
    data_origem: string;
}

export type Nivel = 'normal' | 'atencao' | 'alerta' | 'critico';

/** Contagem de pendentes por severidade — calculada antes do filtro de nível. */
export interface ResumoAlertas {
    critico: number;
    alerta: number;
    atencao: number;
}

export interface FiltrosAtivos {
    busca?: string | null;
    loja?: number | null;
    nivel?: Nivel | null;
}

export interface OpcoesSistema {
    lojas: number[];
    origens: OrigemNota[];
    tipos: TipoCard[];
    /** Tipos que o comprador pode marcar como corrigidos (regra fica de fora) */
    tiposCompras?: TipoCard[];
    /** Limiares em dias de cada nível (definidos no backend) */
    sla?: { atencao: number; alerta: number; critico: number };
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    auth: { user: User; can: Permissoes };
    flash?: { sucesso?: string; erro?: string };
};
