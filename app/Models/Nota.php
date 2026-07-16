<?php

namespace App\Models;

use App\Models\Concerns\TemIdade;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Nota extends Model
{
    use SoftDeletes, TemIdade;

    protected $table = 'notas';

    protected $fillable = [
        'numero_nota',
        'fornecedor_id',
        'user_id',
        'loja',
        'origem',
        'observacao',
        'liberada_por',
        'liberada_em',
    ];

    protected $casts = [
        'loja'        => 'integer',
        'liberada_em' => 'datetime',
    ];

    public const LOJAS = [1, 2, 3, 9, 11, 12];

    /** recebimento = caminhão na porta (prioridade) | pre_lote = antecipada */
    public const ORIGENS = ['recebimento', 'pre_lote'];

    // Estados derivados dos cards — nunca gravados, sempre calculados
    public const STATUS_PENDENTE    = 'pendente';        // aguardando análise do pré-lote
    public const STATUS_DIVERGENCIA = 'com_divergencia'; // tem card aberto
    public const STATUS_RECONFERIR  = 'reconferir';      // cards corrigidos aguardando o pré-lote
    public const STATUS_LIBERADA    = 'liberada';

    // ─── Relações ───────────────────────────────────────────────────────────────

    public function fornecedor(): BelongsTo
    {
        return $this->belongsTo(Fornecedor::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function liberadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'liberada_por');
    }

    public function cards(): HasMany
    {
        return $this->hasMany(Card::class);
    }

    public function comentarios(): MorphMany
    {
        return $this->morphMany(Comentario::class, 'comentavel');
    }

    // ─── Estado derivado ────────────────────────────────────────────────────────

    /**
     * O status da nota nasce dos cards — não existe campo para dessincronizar.
     * Requer a relação cards carregada (o controller sempre carrega).
     */
    public function statusCalculado(): string
    {
        if ($this->liberada_em) {
            return self::STATUS_LIBERADA;
        }

        $cards = $this->cards;

        if ($cards->contains(fn($c) => $c->status === Card::STATUS_ABERTO)) {
            return self::STATUS_DIVERGENCIA;
        }

        if ($cards->contains(fn($c) => $c->status === Card::STATUS_CORRIGIDO)) {
            return self::STATUS_RECONFERIR;
        }

        return self::STATUS_PENDENTE;
    }

    /** Nenhum card aberto nem aguardando reconferência — pode ser liberada. */
    public function podeSerLiberada(): bool
    {
        return $this->liberada_em === null &&
            ! $this->cards->contains(fn($c) => $c->status !== Card::STATUS_RESOLVIDO);
    }
}
