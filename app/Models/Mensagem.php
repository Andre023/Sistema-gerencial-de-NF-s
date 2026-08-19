<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Uma linha da conversa: texto, arquivo, ou arquivo com legenda.
 *
 * ── Sobre o arquivo ────────────────────────────────────────────────────────
 * O anexo tem vida curta no servidor de propósito: fica DIAS_NO_SERVIDOR dias
 * e depois some do disco, restando só o registro (nome, tipo, tamanho) para a
 * conversa continuar legível.
 *
 * O que faz isso não ser perda: cada navegador guarda a própria cópia do
 * arquivo assim que o exibe pela primeira vez. Passado o prazo, quem já tinha
 * aberto continua vendo — vindo da máquina, não do site.
 *
 * O prazo existe justamente para dar folga a esse mecanismo: quem recebeu no
 * computador e no dia seguinte abrir no celular ainda pega o arquivo do
 * servidor e faz a cópia local dele também.
 */
class Mensagem extends Model
{
    protected $table = 'mensagens';

    protected $fillable = [
        'conversa_id',
        'user_id',
        'texto',
        'anexo_caminho',
        'anexo_nome',
        'anexo_mime',
        'anexo_tamanho',
        'anexo_removido_em',
    ];

    protected $casts = [
        'anexo_tamanho'     => 'integer',
        'anexo_removido_em' => 'datetime',
    ];

    /** Mesmo disco privado dos anexos de nota (storage/app/private). */
    public const DISCO = 'privado';

    /**
     * O que pode ser enviado. Mesma lista dos anexos de nota, pelo mesmo
     * motivo: SVG é XML, aceita <script> dentro, e servido na mesma origem o
     * script rodaria com a sessão de quem abrisse.
     */
    public const EXTENSOES = ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'];

    /** 12 MB — o mesmo teto do upload_max_filesize do servidor. */
    public const TAMANHO_MAX_KB = 12288;

    public const TEXTO_MAX = 2000;

    /**
     * Quantos dias o arquivo fica no servidor antes de a faxina levar.
     *
     * Três dias é o meio-termo escolhido: espaço suficiente para quem recebeu
     * numa máquina abrir noutra (e fazer a cópia local de lá também), sem que o
     * disco vire depósito de foto de galpão. Depois disso, quem já abriu segue
     * vendo pela cópia do navegador; quem nunca abriu perde o arquivo.
     *
     * Mudar este número é a única coisa que muda o prazo — ver LimparAnexosDeChat.
     */
    public const DIAS_NO_SERVIDOR = 3;

    /**
     * Quantos dias a MENSAGEM inteira vive antes de a faxina levar.
     *
     * Não confundir com DIAS_NO_SERVIDOR, logo acima: aquele é o prazo do
     * ARQUIVO (3 dias), este é o prazo da LINHA (21 dias). Primeiro a foto sai
     * do disco e a mensagem continua legível; três semanas depois do envio a
     * mensagem em si vai embora.
     *
     * ── O prazo é de cada mensagem, não do chat ────────────────────────────
     * A faxina NÃO zera conversa nenhuma. Ela olha uma mensagem de cada vez e
     * pergunta "esta aqui já fez 21 dias?". A que mandei hoje some daqui a três
     * semanas; a que mandei ontem some amanhã-mais-vinte. Uma conversa ativa
     * nunca fica vazia — ela vai perdendo o rabo enquanto ganha começo, como
     * uma janela que desliza.
     *
     * Zerar tudo de três em três semanas seria outra coisa, e errada: apagaria
     * junto a mensagem de dez minutos atrás, só porque o calendário virou.
     *
     * É o que segura o tamanho do chat num patamar em vez de deixar crescer
     * para sempre — numa VM de 1 GB, tabela que só cresce é problema adiado.
     */
    public const DIAS_DE_VIDA = 21;

    // ─── Relações ───────────────────────────────────────────────────────────────

    public function conversa(): BelongsTo
    {
        return $this->belongsTo(Conversa::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Os emojis pendurados nesta mensagem.
     *
     * Some sozinha quando a mensagem some: o cascade está no banco
     * (mensagem_reacoes.mensagem_id), então a faxina de 21 dias não precisa
     * saber que reações existem.
     */
    public function reacoes(): HasMany
    {
        return $this->hasMany(MensagemReacao::class, 'mensagem_id');
    }

    // ─── Anexo ──────────────────────────────────────────────────────────────────

    public function temAnexo(): bool
    {
        return $this->anexo_caminho !== null;
    }

    public function ehImagem(): bool
    {
        return $this->temAnexo() && str_starts_with((string) $this->anexo_mime, 'image/');
    }

    /** O arquivo ainda está no servidor? (false depois de a faxina levar) */
    public function anexoNoServidor(): bool
    {
        return $this->temAnexo() && $this->anexo_removido_em === null;
    }

    /**
     * Apaga o arquivo do disco e marca a hora — o registro continua.
     *
     * Idempotente: chamado duas vezes, o segundo não faz nada. A marca é a
     * fonte da verdade, não a existência do arquivo em disco.
     */
    public function soltarArquivo(): void
    {
        if (! $this->anexoNoServidor()) {
            return;
        }

        Storage::disk(self::DISCO)->delete($this->anexo_caminho);

        $this->update(['anexo_removido_em' => now()]);
    }

    // ─── Tela ───────────────────────────────────────────────────────────────────

    /**
     * Formato que a conversa consome. Nunca expõe o caminho físico — o arquivo
     * sai só pela rota de download, que confere a participação.
     */
    public function paraTela(): array
    {
        /*
         * `loadMissing` e não `load`: quando quem chamou já trouxe as reações
         * junto (é o caso de toda listagem — ver ConversaController::pagina),
         * esta linha não faz nada. Ela existe para o caso avulso, como a
         * mensagem recém-criada que volta no 201 do envio.
         *
         * Sem ela, uma página de 40 mensagens faria 40 consultas de reação —
         * o N+1 clássico, e o tipo de coisa que só aparece quando a conversa
         * fica longa.
         */
        $this->loadMissing('reacoes');

        return [
            'id'         => $this->id,
            'texto'      => $this->texto,
            'autor_id'   => $this->user_id,
            'autor'      => $this->autor?->name,
            'created_at' => $this->created_at,

            /*
             * Cru de propósito: um par {emoji, quem} por reação, sem agrupar e
             * sem dizer qual é "minha".
             *
             * Agrupar aqui obrigaria o servidor a saber para QUEM está
             * montando o payload — e o MensagemEnviada transmite a mesma
             * mensagem para os dois lados da conversa de uma vez só. Teria de
             * virar um payload por destinatário. A tela agrupa em duas linhas,
             * e já sabe quem ela mesma é.
             */
            'reacoes' => $this->reacoes
                ->map(fn(MensagemReacao $r) => ['emoji' => $r->emoji, 'user_id' => $r->user_id])
                ->values()
                ->all(),

            'anexo' => $this->temAnexo() ? [
                'nome'    => $this->anexo_nome,
                'mime'    => $this->anexo_mime,
                'tamanho' => $this->anexo_tamanho,
                'imagem'  => $this->ehImagem(),
                // false = o prazo venceu e o arquivo saiu do disco; a partir
                // daí só a cópia guardada no navegador consegue mostrar
                'no_servidor' => $this->anexoNoServidor(),
                'removido_em' => $this->anexo_removido_em,
            ] : null,
        ];
    }
}
