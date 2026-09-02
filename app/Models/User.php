<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // ─── Papéis funcionais (espelham os setores reais, não uma hierarquia) ──────
    public const ROLE_RECEBIMENTO = 'recebimento'; // lança a nota quando o caminhão chega
    public const ROLE_PRE_LOTE    = 'pre_lote';    // analisa, abre/fecha cards, libera
    public const ROLE_COMPRAS     = 'compras';     // corrige no ERP, marca o card
    public const ROLE_VISITANTE   = 'visitante';   // só leitura: vê as notas, não age
    public const ROLE_ADMIN       = 'admin';

    public const ROLES = [
        self::ROLE_RECEBIMENTO,
        self::ROLE_PRE_LOTE,
        self::ROLE_COMPRAS,
        self::ROLE_VISITANTE,
        self::ROLE_ADMIN,
    ];

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'acumula_recebimento',
        'notificacoes_ativas',
        // Avatar: o tipo ('emoji'|'monograma') e o valor (emoji com tom, ou cor).
        'avatar_tipo',
        'avatar_valor',
    
        'campanha_filtro_comprador',];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        // As colunas cruas do avatar não vão pro JSON — o front consome só o
        // objeto normalizado `avatar` (getAvatarAttribute), anexado via $appends.
        'avatar_tipo',
        'avatar_valor',
    ];

    /** Sempre expõe o avatar montado, mesmo em relação carregada só com id/name. */
    protected $appends = ['avatar'];

    /**
     * O default da coluna vive no banco, mas só chega ao objeto depois de um
     * refresh — quem lesse a marca logo após o create() veria null. Repetir
     * aqui faz o valor em memória bater com o gravado desde o primeiro instante.
     */
    protected $attributes = [
        'acumula_recebimento' => false,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at'   => 'datetime',
            'password'            => 'hashed',
            'notificacoes_ativas' => 'boolean',
            'acumula_recebimento' => 'boolean',
        ];
    }

    // ─── Avatar ─────────────────────────────────────────────────────────────────

    /**
     * Avatar normalizado para o frontend: {tipo, valor}. Se a relação veio
     * carregada sem as colunas do avatar (ex.: user:id,name), o tipo cai em
     * 'monograma' e o componente <Avatar> deriva a cor a partir do nome.
     */
    public function getAvatarAttribute(): array
    {
        return [
            'tipo'  => $this->avatar_tipo ?? 'monograma',
            'valor' => $this->avatar_valor,
        ];
    }

    // ─── Notificações ───────────────────────────────────────────────────────────

    public function notificacoes(): HasMany
    {
        return $this->hasMany(Notificacao::class);
    }

    // ─── Exclusão de conta ──────────────────────────────────────────────────────

    /**
     * Por que esta conta NÃO pode ser apagada — null quando pode.
     *
     * Vive aqui porque são duas portas para o mesmo ato: o admin apagando
     * alguém (tela de Usuários) e a pessoa apagando a si mesma (Perfil). A do
     * Perfil não tinha trava nenhuma, e por ali o único admin conseguia se
     * apagar — ninguém mais criaria usuário nem veria as estatísticas depois
     * disso. Pior: com nota lançada, a FK restritiva derrubava o pedido com
     * erro em vez de explicar o motivo.
     */
    public function impedimentoParaExclusao(): ?string
    {
        if ($this->isAdmin() && static::where('role', self::ROLE_ADMIN)->count() <= 1) {
            return 'Esta é a única conta de administrador — promova outra pessoa antes de excluí-la.';
        }

        if ($this->temHistorico()) {
            return 'Esta conta tem notas no histórico e não pode ser excluída. '
                 . 'Mude o papel dela para Visitante se quiser limitar o acesso.';
        }

        return null;
    }

    /**
     * O usuário é o criador (user_id) de notas — a FK é restritiva, então o
     * histórico manda: preserva-se a conta em vez de apagar o rastro.
     * (As tabelas legadas requisicoes/cadastros também têm FK e podem existir.)
     */
    private function temHistorico(): bool
    {
        if (Nota::withTrashed()->where('user_id', $this->id)->exists()) {
            return true;
        }

        foreach (['requisicoes', 'cadastros'] as $legada) {
            if (Schema::hasTable($legada) && DB::table($legada)->where('user_id', $this->id)->exists()) {
                return true;
            }
        }

        return false;
    }

    // ─── Helpers de papel ───────────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    private function ehUmDe(string ...$roles): bool
    {
        return $this->isAdmin() || in_array($this->role, $roles, true);
    }

    /**
     * A nota que esta pessoa lança precisa avisar o pré-lote?
     *
     * Por padrão, quem é do pré-lote lançando nota antecipada NÃO avisa o
     * setor: quem lança é quem analisa, e o aviso seria ruído para os colegas.
     * Isso vale para a operação central, onde as duas funções são das mesmas
     * pessoas na mesma sala.
     *
     * Nas lojas em que uma pessoa só faz as duas coisas (loja 12), a suposição
     * cai: a nota que ela lança precisa aparecer para o pré-lote da central,
     * que é quem acompanha a fila de todas as lojas. O admin marca a conta em
     * Usuários e o silêncio deixa de valer para ela.
     */
    public function lancamentoAvisaPreLote(): bool
    {
        return $this->role !== self::ROLE_PRE_LOTE || $this->acumula_recebimento;
    }

    /** Visitante: acesso só-leitura — vê as notas, mas não executa nenhuma ação. */
    public function ehVisitante(): bool
    {
        return $this->role === self::ROLE_VISITANTE;
    }

    // ─── Permissões por ação (fonte única, usada por Gates e frontend) ──────────

    /** Lançar nota — recebimento (caminhão na porta) e pré-lote (antecipada) */
    public function podeLancarNota(): bool
    {
        return $this->ehUmDe(self::ROLE_RECEBIMENTO, self::ROLE_PRE_LOTE);
    }

    /** Abrir, resolver, reabrir e excluir cards — quem confere é o pré-lote */
    public function podeGerirCards(): bool
    {
        return $this->ehUmDe(self::ROLE_PRE_LOTE);
    }

    /** Marcar card como corrigido — quem corrige no ERP é compras */
    public function podeCorrigirCard(): bool
    {
        return $this->ehUmDe(self::ROLE_COMPRAS);
    }

    /**
     * Abrir card de CADASTRO (item sem cadastro no ERP) — pré-lote e recebimento.
     *
     * Separado de podeGerirCards() porque não é o pacote inteiro: o recebimento
     * ABRE este card, mas continua sem resolver, reabrir ou excluir card nenhum.
     * Quem CORRIGE o cadastro segue sendo compras (Card::TIPOS_COMPRAS) — é ela
     * quem mexe no ERP.
     *
     * O motivo é de fluxo: o item sem cadastro aparece na hora de digitar a
     * nota, com o caminhão na porta. Quem está ali vê primeiro, e antes disto
     * precisava pedir ao pré-lote para abrir o card por ele.
     */
    public function podeAbrirCardDeCadastro(): bool
    {
        return $this->ehUmDe(self::ROLE_PRE_LOTE, self::ROLE_RECEBIMENTO);
    }

    /** Liberar a nota (o ✅) — ato do pré-lote */
    public function podeLiberarNota(): bool
    {
        return $this->ehUmDe(self::ROLE_PRE_LOTE);
    }

    /** Editar os campos da nota — pré-lote e recebimento (quem lança) */
    public function podeEditarNotas(): bool
    {
        return $this->ehUmDe(self::ROLE_PRE_LOTE, self::ROLE_RECEBIMENTO);
    }

    /** Excluir notas da fila — pré-lote (excluir liberada é só admin) */
    public function podeGerenciarNotas(): bool
    {
        return $this->ehUmDe(self::ROLE_PRE_LOTE);
    }

    /**
     * Devolver uma nota liberada de volta ao recebimento — pré-lote e recebimento.
     * Para o caso de terem conferido errado e liberado, mas a nota segue com erro
     * e precisa ser reajustada.
     */
    public function podeDevolverNota(): bool
    {
        return $this->ehUmDe(self::ROLE_PRE_LOTE, self::ROLE_RECEBIMENTO);
    }

    /**
     * Excluir nota JÁ LIBERADA — só admin.
     *
     * A nota liberada é histórico fechado: o pré-lote pode apagar o que ainda
     * está na fila (lançado errado), mas desfazer o que já foi concluído é ato
     * de administração.
     */
    public function podeExcluirNotaLiberada(): bool
    {
        return $this->isAdmin();
    }

    /** Ver Estatísticas — só admin */
    public function podeVerEstatisticas(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Ver o dossiê do fornecedor — hoje só admin, como Estatísticas.
     * Fica separado de propósito: liberar para compras/pré-lote (que negociam
     * com o fornecedor) é trocar esta linha, sem mexer em mais nada.
     */
    public function podeVerDossie(): bool
    {
        return $this->isAdmin();
    }

    /** Gerenciar usuários — só admin */
    public function podeGerenciarUsuarios(): bool
    {
        return $this->isAdmin();
    }

    /** Gerenciar fornecedores prioritários (a aba Prioridades) — só admin */
    public function podeGerenciarPrioridades(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Importar a base de fornecedores (upsert em massa) — só admin.
     *
     * É o cadastro que TODAS as notas referenciam: um JSON errado renomeia
     * fornecedor em massa e leva junto o histórico de quem já apontou para ele.
     * Não ter tela não protege nada — o endpoint responde a qualquer requisição
     * autenticada, então a trava tem de ser aqui.
     */
    public function podeImportarFornecedores(): bool
    {
        return $this->isAdmin();
    }

    /**
     * Ações leves na tela de notas — comentar e reservar ("estou olhando").
     * Todos os papéis operacionais podem; o visitante é só leitura.
     */
    public function podeInteragir(): bool
    {
        return ! $this->ehVisitante();
    }

    /**
     * Editar a OBSERVAÇÃO da nota (na fila ou já liberada) — recebimento,
     * pré-lote e compras. É recado operacional, não conteúdo fiscal: compras
     * usa para registrar o que combinou com o fornecedor.
     */
    public function podeEditarObservacao(): bool
    {
        return $this->ehUmDe(self::ROLE_RECEBIMENTO, self::ROLE_COMPRAS, self::ROLE_PRE_LOTE);
    }

    /**
     * Cancelar a nota (o fornecedor cancelou a NF) — pré-lote e compras.
     * A nota sai da fila e vai para "Canceladas neste dia"; nada é excluído.
     */
    public function podeCancelarNota(): bool
    {
        return $this->ehUmDe(self::ROLE_PRE_LOTE, self::ROLE_COMPRAS);
    }

    /** Editar o lembrete CEASA de uma nota JÁ LIBERADA — só recebimento */
    public function podeEditarCeasaLiberada(): bool
    {
        return $this->ehUmDe(self::ROLE_RECEBIMENTO);
    }

    /**
     * Anexar documento/foto à nota (e remover) — todo papel operacional.
     *
     * Era só de recebimento e pré-lote, por serem quem tem a mercadoria na mão.
     * Na prática travava compras justamente quando ela tinha o que mostrar: o
     * print do pedido no ERP, o e-mail do fornecedor, a foto que o representante
     * mandou. Ela via os anexos dos outros e não podia responder com um.
     *
     * Anexar é ação leve, da mesma família de comentar — por isso segue a mesma
     * régua: todos, menos o visitante, que é só leitura.
     */
    public function podeAnexarNota(): bool
    {
        return $this->podeInteragir();
    }

    /**
     * Usar o quadro de devoluções (abrir cards e conferir) — recebimento e
     * pré-lote.
     *
     * São os dois lados do recado que o quadro substitui: o recebimento
     * descobre na doca, o pré-lote na conferência, e o aviso vai numa direção
     * ou na outra conforme quem viu primeiro. Por isso os DOIS abrem e os DOIS
     * conferem — não há um dono fixo do card.
     *
     * Compras fica de fora: a devolução com boleto se resolve entre quem está
     * com a mercadoria e a nota na mão.
     */
    public function podeUsarDevolucoes(): bool
    {
        return $this->podeLancarNota();
    }

    /**
     * Usar a aba Campanha — a carta de aniversário que vai ao fornecedor.
     *
     * É trabalho de compras: quem negocia o investimento é quem escreve a
     * carta. O admin entra junto, como em todo o resto do sistema.
     *
     * A segunda metade da regra é o interruptor: com a campanha desligada em
     * Configurações, a aba não existe para ninguém. É o que tira a tela do
     * caminho fora da época da promoção, sem mexer no papel de ninguém.
     */
    public function podeUsarCampanha(): bool
    {
        return $this->ehUmDe(self::ROLE_COMPRAS) && Configuracao::campanhaAtiva();
    }

    /** Abrir Configurações (Usuários e Campanha) — só admin */
    public function podeGerenciarConfiguracoes(): bool
    {
        return $this->isAdmin();
    }
}
