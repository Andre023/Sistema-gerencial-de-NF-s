<?php

namespace App\Http\Controllers;

use App\Events\ConversaAtualizada;
use App\Events\MensagemEnviada;
use App\Models\Conversa;
use App\Models\Mensagem;
use App\Models\User;
use App\Services\Conversas;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * O chat interno — conversa direta entre duas pessoas.
 *
 * Todo mundo conversa com todo mundo, inclusive o visitante: aqui não se
 * executa ação nenhuma sobre nota, é só recado entre colegas. A única
 * autorização que existe é participar da conversa que se está lendo.
 *
 * ── A vida do arquivo ──────────────────────────────────────────────────────
 * Foto e documento não moram no servidor para sempre. O ciclo é:
 *
 *   1. quem envia já reduz e converte para WebP no próprio navegador
 *   2. o arquivo fica no disco privado, fora do alcance do nginx
 *   3. quem abre a conversa recebe o arquivo e o navegador guarda uma cópia
 *      na própria máquina — cada lado fica com a sua
 *   4. passados Mensagem::DIAS_NO_SERVIDOR dias, a faxina noturna apaga o
 *      arquivo daqui; a mensagem continua, e quem tem cópia continua vendo
 *
 * O prazo do passo 4 é o que dá folga ao passo 3: quem recebeu no computador
 * e só no dia seguinte abrir o sistema no celular ainda encontra o arquivo
 * aqui, e o celular faz a cópia dele também.
 */
class ConversaController extends Controller
{
    // ─── LISTA (a barra lateral) ───────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        return response()->json(Conversas::paraUsuario($request->user()));
    }

    // ─── ABRIR UMA CONVERSA ────────────────────────────────────────────────────

    /**
     * As mensagens da conversa com `$pessoa`.
     *
     * NÃO cria a conversa: quem só clicou num nome para espiar não deve deixar
     * linha no banco. Ela nasce na primeira mensagem enviada (ver `enviar`).
     */
    public function mostrar(Request $request, User $pessoa): JsonResponse
    {
        $user = $request->user();

        abort_if($pessoa->id === $user->id, 404);

        $conversa = Conversa::where('chave_direta', Conversa::chaveDireta($user->id, $pessoa->id))->first();

        if (! $conversa) {
            return response()->json([
                'conversa_id' => null,
                'mensagens'   => [],
                'tem_antigas' => false,
                'lida_pelo_outro_ate' => 0,
            ]);
        }

        $mensagens = $this->pagina($conversa, $request->integer('antes') ?: null);

        // Abrir a conversa é ler o que está nela
        $conversa->marcarLida($user);
        $this->avisarLeitura($conversa, $user);

        return response()->json([
            'conversa_id' => $conversa->id,
            'mensagens'   => $mensagens->map(fn(Mensagem $m) => $m->paraTela())->values()->all(),
            // Ainda há mensagem mais antiga para o "carregar mais"?
            'tem_antigas' => $mensagens->isNotEmpty()
                && $conversa->mensagens()->where('id', '<', $mensagens->first()->id)->exists(),
            // Até onde o OUTRO leu — é o que acende o ✓✓ nas minhas bolhas
            'lida_pelo_outro_ate' => $this->lidaPeloOutro($conversa, $user),
        ]);
    }

    // ─── ENVIAR ────────────────────────────────────────────────────────────────

    public function enviar(Request $request, User $pessoa): JsonResponse
    {
        $user = $request->user();

        abort_if($pessoa->id === $user->id, 422);

        $request->validate([
            'texto'   => ['nullable', 'string', 'max:' . Mensagem::TEXTO_MAX],
            // `mimes` olha o CONTEÚDO do arquivo (via finfo), não o Content-Type
            // que o navegador mandou — que é campo livre e mente à vontade.
            'arquivo' => [
                'nullable',
                'file',
                'max:' . Mensagem::TAMANHO_MAX_KB,
                'mimes:' . implode(',', Mensagem::EXTENSOES),
            ],
        ], [
            'arquivo.max'   => 'Arquivo grande demais (máximo ' . (Mensagem::TAMANHO_MAX_KB / 1024) . ' MB).',
            'arquivo.mimes' => 'Formato não aceito. Envie foto (JPG, PNG, WebP, HEIC) ou PDF.',
        ]);

        $texto   = trim((string) $request->input('texto'));
        $arquivo = $request->file('arquivo');

        // Mensagem vazia não vira bolha em branco na tela do outro
        if ($texto === '' && ! $arquivo) {
            throw ValidationException::withMessages([
                'texto' => 'Escreva uma mensagem ou anexe um arquivo.',
            ]);
        }

        $conversa = Conversa::entre($user, $pessoa);

        $dados = [
            'user_id' => $user->id,
            'texto'   => $texto !== '' ? $texto : null,
        ];

        if ($arquivo) {
            // O nome em disco é gerado por nós. O que veio do cliente é texto
            // livre: serve para exibir e batizar o download, nunca para montar
            // caminho — senão um "../../.env" escolhe onde gravar.
            $extensao = strtolower($arquivo->getClientOriginalExtension() ?: $arquivo->extension());
            $caminho  = "chat/{$conversa->id}/" . Str::uuid() . '.' . $extensao;

            Storage::disk(Mensagem::DISCO)->putFileAs(
                dirname($caminho),
                $arquivo,
                basename($caminho),
            );

            $dados += [
                'anexo_caminho' => $caminho,
                'anexo_nome'    => $this->nomeLimpo($arquivo->getClientOriginalName()),
                // getMimeType() inspeciona o arquivo; getClientMimeType() confiaria no cliente
                'anexo_mime'    => $arquivo->getMimeType() ?: 'application/octet-stream',
                'anexo_tamanho' => $arquivo->getSize(),
            ];
        }

        $mensagem = $conversa->mensagens()->create($dados);

        $conversa->update(['ultima_mensagem_em' => $mensagem->created_at]);

        // Quem manda já leu o que mandou — senão a própria mensagem voltaria
        // como não lida para ele na próxima contagem.
        $conversa->marcarLida($user, $mensagem->id);

        event(new MensagemEnviada(
            $mensagem,
            $conversa->participantes->pluck('id')->all(),
        ));

        return response()->json([
            // A conversa pode ter acabado de nascer neste envio — a tela precisa
            // do id para casar os eventos do Reverb que vierem depois.
            'conversa_id' => $conversa->id,
            'mensagem'    => $mensagem->fresh('autor')->paraTela(),
        ], 201);
    }

    // ─── BAIXAR O ANEXO ────────────────────────────────────────────────────────

    public function arquivo(Request $request, Mensagem $mensagem): StreamedResponse
    {
        $this->garanteParticipacao($request->user(), $mensagem);

        abort_unless($mensagem->temAnexo(), 404);

        // O prazo venceu e a faxina levou: quem tem o arquivo agora é o
        // navegador de quem já o abriu. 410 (Gone) e não 404 — sumiu, mas por
        // decisão, não por erro; a tela usa isso para explicar em vez de dizer
        // "não encontrado".
        abort_if($mensagem->anexo_removido_em !== null, 410, 'Este arquivo não está mais no servidor.');

        $disco = Storage::disk(Mensagem::DISCO);

        abort_unless($disco->exists($mensagem->anexo_caminho), 404);

        // Imagem abre na tela; PDF baixa. PDF exibido na mesma origem pode rodar
        // JavaScript embutido — com o attachment ele vai para o leitor do
        // sistema, fora do contexto da sessão.
        $inline = $mensagem->ehImagem() && ! $request->boolean('baixar');

        return $disco->download(
            $mensagem->anexo_caminho,
            $mensagem->anexo_nome,
            [
                'Content-Type'        => $mensagem->anexo_mime,
                'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                    . '; filename="' . addslashes((string) $mensagem->anexo_nome) . '"',
                // Reforça o nosniff global: o navegador não reinterpreta o tipo
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    // ─── MARCAR COMO LIDA ──────────────────────────────────────────────────────

    public function lida(Request $request, Conversa $conversa): JsonResponse
    {
        $user = $request->user();

        abort_unless($conversa->temParticipante($user), 403);

        $conversa->marcarLida($user, $request->integer('ate') ?: null);

        $this->avisarLeitura($conversa, $user);

        return response()->json(['ok' => true]);
    }

    // ─── HELPERS ───────────────────────────────────────────────────────────────

    /** As últimas N mensagens (ou as N anteriores a `$antes`), em ordem cronológica. */
    private function pagina(Conversa $conversa, ?int $antes)
    {
        return $conversa->mensagens()
            ->with('autor:id,name')
            ->when($antes, fn($q) => $q->where('id', '<', $antes))
            ->orderByDesc('id')
            ->limit(Conversa::PAGINA)
            ->get()
            ->reverse()
            ->values();
    }

    /** Conta ao outro lado até onde eu li — é o que acende o ✓✓ na tela dele. */
    private function avisarLeitura(Conversa $conversa, User $user): void
    {
        $outro = $conversa->outro($user);

        if (! $outro) {
            return;
        }

        event(new ConversaAtualizada(
            $outro->id,
            $conversa->id,
            ConversaAtualizada::LIDA,
            $conversa->participantes->firstWhere('id', $user->id)?->pivot?->lida_ate_id ?? 0,
        ));
    }

    /** Até onde o outro lado leu — a marca d'água do ✓✓ nas minhas bolhas. */
    private function lidaPeloOutro(Conversa $conversa, User $user): int
    {
        return (int) ($conversa->participantes->firstWhere('id', '!=', $user->id)?->pivot?->lida_ate_id ?? 0);
    }

    /** 404 para quem não é dos dois lados da conversa. */
    private function garanteParticipacao(User $user, Mensagem $mensagem): void
    {
        abort_unless($mensagem->conversa->temParticipante($user), 404);
    }

    /**
     * Nome de exibição: só o basename, sem caractere de controle, limitado ao
     * tamanho da coluna. Não é o nome do arquivo em disco — esse é UUID — mas
     * volta no Content-Disposition do download, então também não pode ser lixo.
     */
    private function nomeLimpo(string $nome): string
    {
        $nome = basename(str_replace('\\', '/', $nome));
        $nome = preg_replace('/[\x00-\x1F\x7F"]+/u', '', $nome) ?? '';
        $nome = trim($nome);

        return mb_substr($nome !== '' ? $nome : 'arquivo', 0, 255);
    }
}
