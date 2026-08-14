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
    /** Cancelar a nota (o fornecedor cancelou a NF) — pré-lote e compras */
    cancelarNota: boolean;
    verEstatisticas: boolean;
    /** Ver o dossiê do fornecedor (aba Fornecedores) */
    verDossie: boolean;
    gerenciarUsuarios: boolean;
    /** Gerenciar a aba Prioridades (fornecedores prioritários) — só admin */
    gerenciarPrioridades: boolean;
    /** Ações leves na fila (comentar, reservar) — todos menos o visitante */
    interagir: boolean;
    /** Editar a observação da nota (fila ou liberada) — recebimento, compras, pré-lote */
    editarObservacao: boolean;
    /** Editar o lembrete CEASA de uma nota já liberada — só recebimento */
    editarCeasaLiberada: boolean;
    /** Anexar e remover documento/foto da nota — recebimento e pré-lote */
    anexarNota: boolean;
}

/**
 * Documento ou foto preso à nota. O arquivo NÃO vem aqui: só o ponteiro e o
 * que a tela mostra sem abri-lo. O conteúdo sai pela rota notas.anexos.download,
 * que confere a sessão antes de entregar.
 */
export interface Anexo {
    id: number;
    nome: string;
    mime: string;
    /** bytes */
    tamanho: number;
    imagem: boolean;
    enviado_por: string | null;
    created_at: string;
}

export interface Fornecedor {
    id: number;
    nome: string;
    cnpj?: string | null;
    /** Fornecedor prioritário: sobe ao topo do pré-lote */
    prioridade?: boolean;
}

export type TipoCard = 'cadastro' | 'regra' | 'custo' | 'quantidade' | 'sem_pedido' | 'item_n_pedido' | 'importar_nf' | 'reconferir' | 'trocar_nota' | 'recusa' | 'devolucao';
export type StatusCard = 'aberto' | 'resolvido';

export interface Card {
    id: number;
    tipo: TipoCard;
    status: StatusCard;
    detalhe: string | null;
    reaberturas: number;
}

export type StatusNota = 'pendente' | 'com_divergencia' | 'reconferir' | 'liberada' | 'cancelada';
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
    /** Cancelamento (o fornecedor cancelou a NF) — null se ativa */
    cancelada_em: string | null;
    cancelada_por: Pick<User, 'id' | 'name'> | null;
    motivo_cancelamento: string | null;
    /** Fila de onde veio, quando a nota trocou de fila ("Pré-lote desde 19/06") */
    origem_anterior: OrigemNota | null;
    origem_anterior_data: string | null;
    origem_alterada_em: string | null;
    /** Quem está "olhando" a nota agora (mostra o avatar dela) — null se livre */
    visualizando_por: (Pick<User, 'id' | 'name'> & { avatar?: Avatar }) | null;
    visualizando_em: string | null;
    comentarios_count: number;
    anexos_count: number;
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
    /** Tipos que qualquer papel operacional pode ABRIR (Card::abertosPorQualquerPapel) */
    tiposQualquerPapel?: TipoCard[];
    /** Limiares em dias de cada nível (definidos no backend) */
    sla?: { atencao: number; alerta: number; critico: number };
}

// ─── Sino ────────────────────────────────────────────────────────────────────

export type TipoNotificacao = 'divergencia' | 'corrigido' | 'reaberto' | 'liberada' | 'lancada';

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

// ─── Chat ────────────────────────────────────────────────────────────────────

/**
 * O anexo de uma mensagem. O arquivo não vem aqui — só o suficiente para a
 * bolha se desenhar antes de qualquer download.
 */
export interface AnexoMensagem {
    nome: string;
    mime: string;
    /** bytes */
    tamanho: number;
    imagem: boolean;
    /**
     * false = o prazo venceu e o arquivo saiu do servidor. A bolha ainda pode
     * mostrá-lo se ESTE navegador guardou a cópia quando o exibiu (IndexedDB,
     * ver lib/arquivosLocais). Se não guardou, mostra o aviso de expirado.
     */
    no_servidor: boolean;
    removido_em: string | null;
}

export interface Mensagem {
    id: number;
    texto: string | null;
    autor_id: number | null;
    autor: string | null;
    created_at: string;
    anexo: AnexoMensagem | null;
    /**
     * Só no cliente: mensagem que ainda não voltou do servidor. A bolha aparece
     * na hora (com id negativo) e é substituída pela real quando a resposta
     * chega — é o que faz o envio parecer instantâneo.
     */
    pendente?: boolean;
    /** Só no cliente: o envio falhou e há o que tentar de novo. */
    falhou?: boolean;
}

/** Uma linha da lista de pessoas da barra lateral. */
export interface PessoaChat {
    id: number;
    nome: string;
    papel: Papel;
    avatar: Avatar | null;
    conversa_id: number | null;
    nao_lidas: number;
    ultima: {
        previa: string;
        em: string;
        /** true = a última mensagem foi minha (mostra o ✓ na lista) */
        minha: boolean;
    } | null;
}

/**
 * Alguém com mensagem por ler. Chega junto com a página (props compartilhadas),
 * para o rosto aparecer no topo da barra recolhida sem precisar abrir nada.
 */
export interface PendenteChat {
    id: number;
    nome: string;
    avatar: Avatar | null;
    nao_lidas: number;
    /** Data da última mensagem — é por ela que a ordem é decidida */
    em: string;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    auth: { user: User; can: Permissoes };
    flash?: { sucesso?: string; erro?: string };
    notificacoes?: EstadoSino | null;
    /** Quem está com mensagem por ler, do mais recente para o mais antigo */
    conversasPendentes?: PendenteChat[];
};
