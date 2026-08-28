<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Um aviso de devolução no quadro entre pré-lote e recebimento.
 *
 * Nasceu para tirar do WhatsApp um recado que sempre teve a mesma forma: o
 * print do sistema, a nota, o fornecedor, o motivo, quem autorizou e quando o
 * boleto vence. No grupo isso se perdia na rolagem — aqui fica num cartão que
 * alguém confere e que some sozinho depois.
 *
 * Não confundir com o card 'devolucao' da nota (Card::TIPOS_DOCA): aquele trava
 * a liberação de uma nota da fila; este é independente e não trava nada.
 */
class Devolucao extends Model
{
    protected $table = 'devolucoes';

    protected $fillable = [
        'fornecedor',
        'numero_nota',
        'motivo',
        'autorizado_por',
        'boletos_vencem',
        'sem_boleto',
        'criada_por',
        'conferida_em',
        'conferida_por',
    ];

    protected $casts = [
        // Lista de 'YYYY-MM-DD'. Uma nota grande sai parcelada, e o recado
        // precisa citar todos os vencimentos — com um só, os outros viravam
        // combinado de boca.
        'boletos_vencem' => 'array',
        // Não é o mesmo que a lista vazia: vazia = o boleto ainda não saiu
        // (alguém vai cobrar a data); sem_boleto = não haverá boleto.
        'sem_boleto'     => 'boolean',
        'conferida_em'   => 'datetime',
    ];

    /**
     * Quantos dias os arquivos ficam depois de o card ser conferido.
     *
     * Uma semana: tempo de alguém voltar para reconferir se ficou dúvida, sem
     * transformar o disco da VM em arquivo morto de print. O CARD continua —
     * some só o anexo (ver LimparAnexosDeDevolucao).
     */
    public const DIAS_APOS_CONFERIR = 7;

    public function anexos(): HasMany
    {
        return $this->hasMany(DevolucaoAnexo::class);
    }

    public function criadaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'criada_por');
    }

    public function conferidaPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'conferida_por');
    }

    public function conferida(): bool
    {
        return $this->conferida_em !== null;
    }

    /** Formato que o quadro consome. Nunca expõe o caminho físico do arquivo. */
    public function paraQuadro(): array
    {
        return [
            'id'             => $this->id,
            'fornecedor'     => $this->fornecedor,
            'numero_nota'    => $this->numero_nota,
            'motivo'         => $this->motivo,
            'autorizado_por' => $this->autorizado_por,
            'boletos_vencem' => $this->boletos_vencem ?? [],
            'sem_boleto'     => (bool) $this->sem_boleto,
            'criada_por'     => $this->criadaPor?->name,
            'conferida_em'   => $this->conferida_em,
            'conferida_por'  => $this->conferidaPor?->name,
            'created_at'     => $this->created_at,

            'anexos' => $this->anexos->map(fn(DevolucaoAnexo $a) => [
                'id'      => $a->id,
                'nome'    => $a->nome_original,
                'tamanho' => $a->tamanho,
                'imagem'  => $a->ehImagem(),
            ])->values()->all(),
        ];
    }
}
