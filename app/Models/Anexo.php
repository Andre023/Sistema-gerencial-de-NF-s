<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Um documento ou foto preso a uma nota.
 *
 * O arquivo vive no disco 'privado' (storage/app/private), fora do alcance do
 * nginx: nota é documento fiscal, e link solto que abre sem login é vazamento.
 * Quem entrega o conteúdo é o AnexoController, depois de conferir o Gate.
 */
class Anexo extends Model
{
    protected $table = 'anexos';

    protected $fillable = [
        'nota_id',
        'caminho',
        'nome_original',
        'mime',
        'tamanho',
        'enviado_por',
    ];

    protected $casts = [
        'tamanho' => 'integer',
    ];

    /** O disco onde os arquivos moram (config/filesystems.php). */
    public const DISCO = 'privado';

    /**
     * O que o navegador pode mandar. Fechado por extensão E por conteúdo — o
     * `mimes:` do Laravel olha o arquivo de verdade, não o cabeçalho que o
     * cliente enviou.
     *
     * SVG fica de fora de propósito: é XML, aceita <script> dentro, e serví-lo
     * na mesma origem faria o script rodar com a sessão de quem abrisse.
     */
    public const EXTENSOES = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'];

    /** 12 MB — o mesmo teto do upload_max_filesize do servidor. */
    public const TAMANHO_MAX_KB = 12288;

    public function nota(): BelongsTo
    {
        return $this->belongsTo(Nota::class);
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    public function ehImagem(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    /**
     * Apaga o arquivo do disco e o registro. Um método só porque separar os
     * dois é como se cria arquivo órfão: registro apagado e arquivo ocupando
     * disco para sempre, sem nada que aponte para ele.
     */
    public function apagarComArquivo(): void
    {
        Storage::disk(self::DISCO)->delete($this->caminho);

        $this->delete();
    }

    /** Formato que a tela consome. Nunca expõe o caminho físico. */
    public function paraTela(): array
    {
        return [
            'id'        => $this->id,
            'nome'      => $this->nome_original,
            'mime'      => $this->mime,
            'tamanho'   => $this->tamanho,
            'imagem'    => $this->ehImagem(),
            'enviado_por' => $this->enviadoPor?->name,
            'created_at'  => $this->created_at,
        ];
    }
}
