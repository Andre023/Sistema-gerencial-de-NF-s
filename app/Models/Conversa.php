<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;

/**
 * Uma conversa entre duas pessoas.
 *
 * Hoje só existe a direta (tipo 'direta'). O grupo cabe no mesmo desenho: seria
 * outro tipo, com mais de dois participantes e sem chave_direta.
 */
class Conversa extends Model
{
    protected $table = 'conversas';

    protected $fillable = [
        'tipo',
        'chave_direta',
        'ultima_mensagem_em',
    ];

    protected $casts = [
        'ultima_mensagem_em' => 'datetime',
    ];

    public const TIPO_DIRETA = 'direta';

    /** Quantas mensagens a conversa entrega de uma vez ao abrir. */
    public const PAGINA = 40;

    // ─── Relações ───────────────────────────────────────────────────────────────

    public function mensagens(): HasMany
    {
        return $this->hasMany(Mensagem::class);
    }

    public function participantes(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'conversa_participantes')
            ->withPivot('lida_ate_id')
            ->withTimestamps();
    }

    // ─── Abertura ───────────────────────────────────────────────────────────────

    /**
     * A conversa entre duas pessoas — a que já existe, ou uma nova.
     *
     * A chave é montada com os ids ordenados, então "André abre com Maria" e
     * "Maria abre com André" produzem a mesma string e caem na mesma linha.
     *
     * O create pode falhar mesmo depois do first(): duas pessoas clicando no
     * mesmo instante passam as duas pela consulta e as duas tentam inserir. Aí
     * o unique do banco derruba a segunda — e é justamente o que queremos. Nesse
     * caso a conversa do vencedor já existe, e é ela que devolvemos.
     */
    public static function entre(User $a, User $b): self
    {
        $chave = self::chaveDireta($a->id, $b->id);

        $conversa = self::where('chave_direta', $chave)->first();

        if ($conversa) {
            return $conversa;
        }

        try {
            $conversa = self::create([
                'tipo'         => self::TIPO_DIRETA,
                'chave_direta' => $chave,
            ]);

            $conversa->participantes()->attach([$a->id, $b->id]);

            return $conversa;
        } catch (QueryException $e) {
            // Perdeu a corrida: a conversa do outro é a válida
            return self::where('chave_direta', $chave)->firstOrFail();
        }
    }

    /** "3-17" — sempre o menor id primeiro, para os dois lados baterem. */
    public static function chaveDireta(int $a, int $b): string
    {
        return min($a, $b) . '-' . max($a, $b);
    }

    // ─── Consultas ──────────────────────────────────────────────────────────────

    public function temParticipante(User $user): bool
    {
        return $this->participantes()->whereKey($user->id)->exists();
    }

    /** O outro lado da conversa direta (null se a conta foi removida). */
    public function outro(User $user): ?User
    {
        return $this->participantes->firstWhere('id', '!=', $user->id);
    }

    /**
     * Quantas mensagens esta pessoa ainda não leu.
     *
     * "id maior que o ponteiro dela, e que não sejam dela" — o próprio envio
     * nunca conta como não lido.
     */
    public function naoLidasPara(User $user): int
    {
        $lidaAte = $this->participantes()
            ->whereKey($user->id)
            ->first()?->pivot?->lida_ate_id ?? 0;

        return $this->mensagens()
            ->where('id', '>', $lidaAte)
            ->where('user_id', '!=', $user->id)
            ->count();
    }

    /**
     * Anda o ponteiro de leitura desta pessoa até a última mensagem.
     *
     * Só para frente: uma requisição atrasada chegando fora de ordem não pode
     * "desler" o que já foi lido e fazer o contador ressuscitar.
     */
    public function marcarLida(User $user, ?int $ateId = null): void
    {
        $ateId ??= $this->mensagens()->max('id') ?? 0;

        $pivot = $this->participantes()->whereKey($user->id)->first()?->pivot;

        if (! $pivot || ($pivot->lida_ate_id ?? 0) >= $ateId) {
            return;
        }

        $this->participantes()->updateExistingPivot($user->id, ['lida_ate_id' => $ateId]);
    }
}
