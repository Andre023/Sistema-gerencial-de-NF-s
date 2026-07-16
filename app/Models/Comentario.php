<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Comentario extends Model
{
    protected $table = 'comentarios';

    protected $fillable = [
        'comentavel_type',
        'comentavel_id',
        'user_id',
        'texto',
    ];

    /** O registro comentado (Nota, ...) */
    public function comentavel(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Autor do comentário, ou quem pode gerenciar notas, pode apagar. */
    public function podeSerExcluidoPor(User $user): bool
    {
        return $this->user_id === $user->id || $user->podeGerenciarNotas();
    }
}
