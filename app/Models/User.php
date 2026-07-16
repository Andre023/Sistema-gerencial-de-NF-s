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

    // ─── Papéis ────────────────────────────────────────────────────────────────
    public const ROLE_OPERADOR    = 'operador';
    public const ROLE_ENCARREGADO = 'encarregado';
    public const ROLE_ADMIN       = 'admin';

    public const ROLES = [
        self::ROLE_OPERADOR,
        self::ROLE_ENCARREGADO,
        self::ROLE_ADMIN,
    ];

    /** Nível hierárquico de cada papel (maior = mais permissões) */
    private const NIVEL = [
        self::ROLE_OPERADOR    => 1,
        self::ROLE_ENCARREGADO => 2,
        self::ROLE_ADMIN       => 3,
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

    /** Papel atual tem nível >= ao papel informado? */
    public function temNivel(string $role): bool
    {
        return (self::NIVEL[$this->role] ?? 0) >= (self::NIVEL[$role] ?? 99);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isEncarregado(): bool
    {
        return $this->temNivel(self::ROLE_ENCARREGADO);
    }

    // ─── Permissões (fonte única, usada por Gates e frontend) ───────────────────

    /** Excluir registros e editar campos além de "atender" — encarregado ou admin */
    public function podeGerenciarRegistros(): bool
    {
        return $this->temNivel(self::ROLE_ENCARREGADO);
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
