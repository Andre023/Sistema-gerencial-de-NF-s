<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // ─── Papéis funcionais (espelham os setores reais, não uma hierarquia) ──────
    public const ROLE_RECEBIMENTO = 'recebimento'; // lança a nota quando o caminhão chega
    public const ROLE_PRE_LOTE    = 'pre_lote';    // analisa, abre/fecha cards, libera
    public const ROLE_COMPRAS     = 'compras';     // corrige no ERP, marca o card
    public const ROLE_ADMIN       = 'admin';

    public const ROLES = [
        self::ROLE_RECEBIMENTO,
        self::ROLE_PRE_LOTE,
        self::ROLE_COMPRAS,
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
        'notificacoes_ativas',
        // Avatar: o tipo ('emoji'|'monograma') e o valor (emoji com tom, ou cor).
        'avatar_tipo',
        'avatar_valor',
    ];

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

    // ─── Helpers de papel ───────────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    private function ehUmDe(string ...$roles): bool
    {
        return $this->isAdmin() || in_array($this->role, $roles, true);
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
}
