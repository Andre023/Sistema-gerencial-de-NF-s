// Tipos globais do sistema

export type Papel = 'operador' | 'encarregado' | 'admin';

export interface User {
    id: number;
    name: string;
    email: string;
    role: Papel;
    email_verified_at?: string;
}

export interface Permissoes {
    gerenciarRegistros: boolean;
    verEstatisticas: boolean;
    gerenciarUsuarios: boolean;
}

export interface Fornecedor {
    id: number;
    nome: string;
    cnpj?: string | null;
}

export interface Requisicao {
    id: number;
    numero_nota: string;
    fornecedor: Fornecedor;
    user: User;
    loja: number;
    motivo: string;
    observacao: string | null;
    status: 'Pendente' | 'Atendida';
    atendida_por: Pick<User, 'id' | 'name'> | null;
    atendida_em: string | null;
    comentarios_count: number;
    created_at: string;
    updated_at: string;
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

export interface Cadastro {
    id: number;
    numero_nota: string;
    fornecedor: Fornecedor;
    user: User;
    loja: number;
    motivo: 'Pré Lote' | 'Caminhão na Porta';
    observacao: string | null;
    status: 'Pendente' | 'Atendida';
    atendida_por: Pick<User, 'id' | 'name'> | null;
    atendida_em: string | null;
    requisicao_id: number | null;
    created_at: string;
    updated_at: string;
    atrasada: boolean;
    data_origem: string;
}

export interface FiltrosAtivos {
    motivo?: string | null;
    fornecedor?: number | null;
    busca?: string | null;
    loja?: number | null;
    nivel?: Nivel | null;
}

export interface OpcoesSistema {
    motivos: string[];
    lojas: number[];
    status: string[];
    /** Limiares em dias de cada nível (definidos no backend) */
    sla?: { atencao: number; alerta: number; critico: number };
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    auth: { user: User; can: Permissoes };
    flash?: { sucesso?: string; erro?: string };
};
