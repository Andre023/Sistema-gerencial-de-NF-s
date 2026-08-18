<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Print ou PDF preso a um aviso de devolução.
 *
 * Mesmo desenho do Anexo da nota, e pelo mesmo motivo: o arquivo mora no disco
 * 'privado', fora do alcance do nginx, e só sai por uma rota que confere a
 * sessão. Print de sistema mostra preço, fornecedor e quantidade — link solto
 * que abre sem login é vazamento.
 *
 * Tabela própria em vez de reaproveitar `anexos` porque a vida útil é outra: o
 * da nota morre 2 dias depois da liberação; este morre uma semana depois de o
 * card ser conferido.
 */
class DevolucaoAnexo extends Model
{
    protected $table = 'devolucao_anexos';

    protected $fillable = [
        'devolucao_id',
        'caminho',
        'nome_original',
        'mime',
        'tamanho',
        'enviado_por',
    ];

    protected $casts = [
        'tamanho' => 'integer',
    ];

    public const DISCO = 'privado';

    /** SVG fica de fora: é XML, aceita <script>, e rodaria com a sessão de quem abrisse. */
    public const EXTENSOES = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'];

    /** 12 MB — o mesmo teto do upload_max_filesize do servidor. */
    public const TAMANHO_MAX_KB = 12288;

    /** Quantos arquivos cabem num card. */
    public const MAX_POR_CARD = 10;

    public function devolucao(): BelongsTo
    {
        return $this->belongsTo(Devolucao::class);
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    public function ehImagem(): bool
    {
        return str_starts_with((string) $this->mime, 'image/');
    }

    /** Apaga o arquivo do disco junto com o registro. */
    public function apagarComArquivo(): void
    {
        Storage::disk(self::DISCO)->delete($this->caminho);
        $this->delete();
    }
}
