// Tipos globais do sistema

export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
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
}

export interface OpcoesSistema {
    motivos: string[];
    lojas: number[];
    status: string[];
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    auth: { user: User };
    flash?: { sucesso?: string; erro?: string };
};
