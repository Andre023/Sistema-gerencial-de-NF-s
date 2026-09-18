<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Fornecedor extends Model
{
    protected $table = 'fornecedores';

    protected $fillable = ['nome', 'cnpj', 'prioridade', 'consignado', 'feira', 'uso_consumo', 'matriz_id'];

    protected $casts = [
        'prioridade'  => 'boolean',
        // As marcas (ver MARCAS): são do fornecedor, não da nota — toda nota
        // dele leva o selo sem ninguém marcar ao lançar.
        'consignado'  => 'boolean',
        'feira'       => 'boolean',
        'uso_consumo' => 'boolean',
    ];

    // ─── Marcas ───────────────────────────────────────────────────────────────
    //
    // Cada marca é uma seção de Configurações (o slug é a URL) e uma coluna
    // booleana aqui. Uma coluna por marca, e não um enum: o mesmo fornecedor
    // pode ser consignado E de feira. O que a marca FAZ é uma coisa só — pôr um
    // selo junto do número em toda nota do fornecedor; o texto e a cor do selo
    // moram no front (resources/js/lib/marcas.ts), que é quem desenha.
    //
    // As colunas entram na lista de campos que a fila carrega
    // (NotaController e Nota::paraTelaAgora): esquecer uma delas lá faria o
    // selo sumir sem erro nenhum.

    public const MARCAS = [
        'consignados' => ['coluna' => 'consignado',  'titulo' => 'Consignados'],
        'feira'       => ['coluna' => 'feira',       'titulo' => 'Feira'],
        'uso-consumo' => ['coluna' => 'uso_consumo', 'titulo' => 'Uso e consumo'],
    ];

    /** Os slugs aceitos na URL, prontos para o `where()` da rota. */
    public static function padraoDeRotaDasMarcas(): string
    {
        return implode('|', array_keys(self::MARCAS));
    }

    /** As colunas das marcas, para o `select` da relação na fila. */
    public static function colunasDasMarcas(): string
    {
        return implode(',', array_column(self::MARCAS, 'coluna'));
    }

    public static function colunaDaMarca(string $slug): string
    {
        return self::MARCAS[$slug]['coluna'];
    }

    public function notas(): HasMany
    {
        return $this->hasMany(Nota::class);
    }

    // ─── Matriz e filial ──────────────────────────────────────────────────────
    //
    // Ver a migration add_matriz_id_to_fornecedores. A filial aponta para a
    // matriz; as notas moram todas na matriz. A filial existe para que o nome
    // dela continue encontrando o fornecedor certo.

    public function matriz(): BelongsTo
    {
        return $this->belongsTo(self::class, 'matriz_id');
    }

    public function filiais(): HasMany
    {
        return $this->hasMany(self::class, 'matriz_id')->orderBy('nome');
    }

    public function ehFilial(): bool
    {
        return $this->matriz_id !== null;
    }

    /**
     * O fornecedor que as notas devem apontar: a matriz, se este for filial.
     *
     * É por aqui que a nota lançada "na filial" cai na matriz — o formulário
     * só lista matrizes, mas um id de filial ainda pode chegar (cliente com a
     * lista velha, ou "fornecedor novo" digitado com o nome de uma filial).
     */
    public function efetivo(): self
    {
        return $this->ehFilial() ? $this->matriz : $this;
    }

    /** Só quem não é filial — é o que as listas de escolha mostram. */
    public function scopeMatrizes(Builder $q): Builder
    {
        return $q->whereNull('matriz_id');
    }

    /**
     * Busca por nome que também acha a matriz pelo nome de uma filial dela.
     *
     * Quem digita "MOINHO GLOBO S/A" (a filial) precisa ver "MOINHO GLOBO S.A."
     * (a matriz) — senão o vínculo esconde o fornecedor em vez de juntá-lo.
     */
    public function scopeComNome(Builder $q, string $termo): Builder
    {
        return $q->where(function (Builder $q) use ($termo) {
            $q->where('nome', 'like', "%{$termo}%")
                ->orWhereHas('filiais', fn(Builder $f) => $f->where('nome', 'like', "%{$termo}%"));
        });
    }
}
