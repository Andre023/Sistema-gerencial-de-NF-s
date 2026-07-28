// Tipos globais do sistema

export type Papel = 'recebimento' | 'pre_lote' | 'compras' | 'visitante' | 'admin';

export type TipoAvatar = 'emoji' | 'monograma';

/** Avatar normalizado que o backend anexa a todo User (getAvatarAttribute). */
export interface Avatar {
    tipo: TipoAvatar;
    /** emoji já com tom de pele, OU a cor do monograma; null = derivar do nome */
    valor: string | null;
}

export interface User {
    id: number;
    name: string;
    email: string;
    role: Papel;
    notificacoes_ativas?: boolean;
    email_verified_at?: string;
    avatar?: Avatar;
}

export interface Permissoes {
    lancarNota: boolean;
    gerirCards: boolean;
    corrigirCard: boolean;
    liberarNota: boolean;
    editarNotas: boolean;
    devolverNota: boolean;
    gerenciarNotas: boolean;
    /** Excluir nota já liberada (histórico fechado) — só admin */
    excluirNotaLiberada: boolean;
    verEstatisticas: boolean;
    gerenciarUsuarios: boolean;
    /** Gerenciar a aba Prioridades (fornecedores prioritários) — só admin */
    gerenciarPrioridades: boolean;
    /** Ações leves na fila (comentar, reservar) — todos menos o visitante */
    interagir: boolean;
    /** Editar a observação de uma nota já liberada — recebimento, compras, pré-lote */
    editarObservacaoLiberada: boolean;
    /** Editar o lembrete CEASA de uma nota já liberada — só recebimento */
    editarCeasaLiberada: boolean;
}

export interface Fornecedor {
    id: number;
    nome: string;
    cnpj?: string | null;
    /** Fornecedor prioritário: sobe ao topo do pré-lote */
    prioridade?: boolean;
}

export type TipoCard = 'cadastro' | 'regra' | 'custo' | 'quantidade' | 'sem_pedido' | 'importar_nf';
export type StatusCard = 'aberto' | 'resolvido';

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
    /** CEASA: 0 = não · 1 = CEASA 1 · 2 = CEASA 2 (compras também abre cards) */
    ceasa: number;
    observacao: string | null;
    status: StatusNota;
    cards: Card[];
    liberada_por: Pick<User, 'id' | 'name'> | null;
    liberada_em: string | null;
    /** Chegada física de uma nota já liberada (o caminhão trouxe depois) */
    recebida_em: string | null;
    /** Quem está "olhando" a nota agora (mostra o avatar dela) — null se livre */
    visualizando_por: (Pick<User, 'id' | 'name'> & { avatar?: Avatar }) | null;
    visualizando_em: string | null;
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
    /** Lojas marcadas para aparecer (vazio = todas) */
    loja?: number[];
    nivel?: Nivel | null;
    status?: StatusNota | null;
    /** Tipo de divergência ainda em aberto (cadastro, custo, ...) */
    tipo?: TipoCard | null;
}

/** Quantas notas da fila têm cada tipo de divergência em aberto */
export type ResumoTipos = Record<TipoCard, number>;

export interface OpcoesSistema {
    lojas: number[];
    origens: OrigemNota[];
    tipos: TipoCard[];
    /** Tipos que o comprador pode marcar como corrigidos (regra fica de fora) */
    tiposCompras?: TipoCard[];
    /** Limiares em dias de cada nível (definidos no backend) */
    sla?: { atencao: number; alerta: number; critico: number };
}

// ─── Sino ────────────────────────────────────────────────────────────────────

export type TipoNotificacao = 'divergencia' | 'corrigido' | 'reaberto' | 'liberada';

export interface Notificacao {
    id: number;
    tipo: TipoNotificacao;
    nota_id: number;
    numero_nota: string | null;
    fornecedor: string | null;
    loja: number | null;
    /** Tipos de card citados no aviso ("CUSTO, CADASTRO") */
    tipos: TipoCard[];
    /** Quem fez a ação que gerou o aviso */
    autor: string | null;
    lida: boolean;
    encerrada: boolean;
    created_at: string;
    updated_at: string;
}

/** Estado do sino — vem nos props compartilhados e pelo canal privado do usuário */
export interface EstadoSino {
    pendentes: number;
    itens: Notificacao[];
    ativas: boolean;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    auth: { user: User; can: Permissoes };
    flash?: { sucesso?: string; erro?: string };
    notificacoes?: EstadoSino | null;
};
