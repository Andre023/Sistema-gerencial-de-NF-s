<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um emoji que alguém pendurou numa mensagem.
 *
 * Serve para responder sem virar mensagem: o "👍" que confirma que leu e
 * concorda não precisa ocupar uma bolha nova na conversa — e, principalmente,
 * não acende o balãozinho de não lida do outro lado nem faz o celular apitar.
 */
class MensagemReacao extends Model
{
    protected $table = 'mensagem_reacoes';

    protected $fillable = [
        'mensagem_id',
        'user_id',
        'emoji',
    ];

    /**
     * Os emojis que a barra oferece — e os ÚNICOS que o servidor aceita.
     *
     * A lista existe por dois motivos. O primeiro é de tela: reação é escolha
     * de um toque, não uma segunda caixa de escrever; seis cabem numa fileira
     * no celular sem virar rolagem.
     *
     * O segundo é de servidor: sem lista, o campo aceitaria qualquer texto de
     * 32 caracteres vindo do navegador. Não é falha de segurança grave (a tela
     * escapa tudo que desenha), mas viraria um campo livre disfarçado de emoji,
     * com gente mandando "kkkk" por uma requisição montada à mão.
     *
     * Todos conferidos em public/emoji/ — o desenho vem do Noto auto-hospedado,
     * igual em Windows 10, 11 e celular. Se trocar algum, confira se o SVG
     * existe lá, senão o <Emoji> cai no desenho do sistema operacional e volta
     * a variar de máquina para máquina.
     *
     * 😮 ficou de fora de propósito: o SVG dele não está no pacote. 😯 está, e
     * quer dizer a mesma coisa.
     */
    public const PERMITIDOS = ['👍', '❤️', '😂', '😯', '😢', '🙏'];

    public function mensagem(): BelongsTo
    {
        return $this->belongsTo(Mensagem::class, 'mensagem_id');
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
