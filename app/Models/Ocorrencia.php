<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma linha do livro de ocorrências da nota.
 *
 * Só nasce — nunca é editada nem apagada. Um registro que alguém corrige depois
 * não serve para o que este existe: dizer o que aconteceu de verdade.
 * Ver a migration create_ocorrencias_table para o porquê da tabela.
 */
class Ocorrencia extends Model
{
    protected $table = 'ocorrencias';

    protected $fillable = [
        'nota_id',
        'user_id',
        'acao',
        'dados',
    ];

    protected $casts = [
        'dados' => 'array',
    ];

    // ─── Os verbos ────────────────────────────────────────────────────────────
    //
    // snake_case, no passado, e sempre do ponto de vista da NOTA: é ela que tem
    // ocorrências. "card_aberto" e não "abriu_card".

    public const NOTA_LANCADA     = 'nota_lancada';
    public const NOTA_EDITADA     = 'nota_editada';
    public const NOTA_LIBERADA    = 'nota_liberada';
    public const NOTA_DEVOLVIDA   = 'nota_devolvida';
    public const NOTA_CANCELADA   = 'nota_cancelada';
    public const NOTA_DESCANCELADA = 'nota_descancelada';
    public const NOTA_MOVIDA      = 'nota_movida';
    public const NOTA_RECEBIDA    = 'nota_recebida';
    public const NOTA_EXCLUIDA    = 'nota_excluida';

    public const CARD_ABERTO    = 'card_aberto';
    public const CARD_CORRIGIDO = 'card_corrigido';
    public const CARD_RESOLVIDO = 'card_resolvido';
    public const CARD_REABERTO  = 'card_reaberto';
    public const CARD_EXCLUIDO  = 'card_excluido';

    public const COMENTARIO_CRIADO   = 'comentario_criado';
    public const COMENTARIO_EXCLUIDO = 'comentario_excluido';

    public const ANEXO_ENVIADO  = 'anexo_enviado';
    public const ANEXO_REMOVIDO = 'anexo_removido';

    /**
     * Nomes de campo como a equipe os chama.
     *
     * A tela mostra "trocou a loja", não "trocou o loja" nem "changed loja".
     * Fica no servidor junto do resto do vocabulário da nota, e não numa segunda
     * lista no frontend — já foi assim com os tipos de card, e as duas listas
     * saíram de sincronia.
     */
    public const CAMPOS = [
        'numero_nota'   => 'o número da nota',
        'fornecedor_id' => 'o fornecedor',
        'loja'          => 'a loja',
        'origem'        => 'a fila',
        'ceasa'         => 'o lembrete CEASA',
        'observacao'    => 'a observação',
    ];

    /** Campos cuja mudança vira ocorrência. O resto é ruído de máquina. */
    public static function camposObservados(): array
    {
        return array_keys(self::CAMPOS);
    }

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Formato que a tela consome. */
    public function paraTela(): array
    {
        return [
            'id'      => $this->id,
            'acao'    => $this->acao,
            'dados'   => $this->dados,
            // Sem conta: ou a pessoa foi removida, ou foi o próprio sistema
            // agindo sozinho (o job que limpa anexos vencidos).
            'usuario' => $this->user?->name ?? ($this->user_id === null ? 'Sistema' : '—'),
            'em'      => $this->created_at,
        ];
    }
}
