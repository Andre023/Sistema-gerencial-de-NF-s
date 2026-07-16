<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
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

    /** Editar campos e excluir notas */
    public function podeGerenciarNotas(): bool
    {
        return $this->ehUmDe(self::ROLE_PRE_LOTE);
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
}
