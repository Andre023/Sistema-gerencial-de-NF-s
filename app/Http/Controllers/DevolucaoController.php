<?php

namespace App\Http\Controllers;

use App\Events\DevolucaoAtualizada;
use App\Models\Devolucao;
use App\Models\DevolucaoAnexo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * O quadro de devoluções entre pré-lote e recebimento.
 *
 * Substitui o recado de WhatsApp que sempre teve a mesma forma — print, nota,
 * fornecedor, motivo, quem autorizou e quando o boleto vence. No grupo isso se
 * perdia na rolagem e ninguém sabia o que já tinha sido conferido.
 *
 * Os dois setores abrem e os dois conferem: o aviso vai numa direção ou na
 * outra conforme quem descobriu o problema, e não há dono fixo do card.
 *
 * O PRINT É OBRIGATÓRIO no lançamento. É ele que dá o contexto — sem a tela do
 * sistema junto, o card vira um bilhete sem prova, exatamente o que já
 * acontecia no WhatsApp quando alguém digitava sem anexar.
 */
class DevolucaoController extends Controller
{
    // ─── ABRIR ────────────────────────────────────────────────────────────────

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('usar-devolucoes');

        $dados = $request->validate([
            'fornecedor'     => ['required', 'string', 'max:255'],
            'numero_nota'    => ['required', 'string', 'max:60'],
            'motivo'         => ['required', 'string', 'max:255'],
            'autorizado_por' => ['required', 'string', 'max:255'],
            'boleto_vence'   => ['nullable', 'date'],

            // Pelo menos um arquivo — é a razão de o card existir.
            'arquivos'   => ['required', 'array', 'min:1', 'max:' . DevolucaoAnexo::MAX_POR_CARD],
            'arquivos.*' => [
                'file',
                'max:' . DevolucaoAnexo::TAMANHO_MAX_KB,
                'mimes:' . implode(',', DevolucaoAnexo::EXTENSOES),
            ],
        ], [
            'arquivos.required' => 'Anexe pelo menos o print — é ele que dá o contexto da devolução.',
            'arquivos.max'      => 'No máximo ' . DevolucaoAnexo::MAX_POR_CARD . ' arquivos por card.',
            'arquivos.*.max'    => 'Arquivo grande demais (máximo ' . (DevolucaoAnexo::TAMANHO_MAX_KB / 1024) . ' MB).',
            'arquivos.*.mimes'  => 'Formato não aceito. Envie foto (JPG, PNG, WebP, HEIC) ou PDF.',
        ]);

        /*
         * Card e arquivos numa transação só.
         *
         * O card existe PARA carregar o print. Se a gravação falhasse no meio
         * (disco cheio, permissão), sem isto sobraria um card sem prova nenhuma
         * no quadro — exatamente o bilhete solto que ele veio substituir, e sem
         * ninguém entender de onde veio.
         *
         * O que já foi para o disco antes da falha é varrido no catch: o banco
         * volta atrás sozinho, o disco não.
         */
        $gravados = [];

        try {
            $devolucao = DB::transaction(function () use ($dados, $request, &$gravados) {
                $devolucao = Devolucao::create([
                    'fornecedor'     => trim($dados['fornecedor']),
                    'numero_nota'    => trim($dados['numero_nota']),
                    'motivo'         => trim($dados['motivo']),
                    'autorizado_por' => trim($dados['autorizado_por']),
                    'boleto_vence'   => $dados['boleto_vence'] ?? null,
                    'criada_por'     => $request->user()->id,
                ]);

                foreach ($request->file('arquivos') as $arquivo) {
                    $gravados[] = $this->guardar($devolucao, $arquivo, $request->user()->id);
                }

                return $devolucao;
            });
        } catch (\Throwable $e) {
            Storage::disk(DevolucaoAnexo::DISCO)->delete($gravados);

            return response()->json([
                'erro' => 'Não foi possível guardar os arquivos. Nada foi lançado — tente de novo.',
            ], 500);
        }

        $devolucao->load(['anexos', 'criadaPor:id,name']);

        event(new DevolucaoAtualizada($devolucao));

        return response()->json(['devolucao' => $devolucao->paraQuadro()], 201);
    }

    // ─── ANEXAR MAIS ──────────────────────────────────────────────────────────

    public function anexar(Request $request, Devolucao $devolucao): JsonResponse
    {
        Gate::authorize('usar-devolucoes');

        $request->validate([
            'arquivos'   => ['required', 'array', 'min:1'],
            'arquivos.*' => [
                'file',
                'max:' . DevolucaoAnexo::TAMANHO_MAX_KB,
                'mimes:' . implode(',', DevolucaoAnexo::EXTENSOES),
            ],
        ]);

        $cabem = DevolucaoAnexo::MAX_POR_CARD - $devolucao->anexos()->count();

        if (count($request->file('arquivos')) > $cabem) {
            return response()->json([
                'erro' => "Este card já está no limite — cabem mais {$cabem} arquivo(s).",
            ], 422);
        }

        foreach ($request->file('arquivos') as $arquivo) {
            $this->guardar($devolucao, $arquivo, $request->user()->id);
        }

        $devolucao->load(['anexos', 'criadaPor:id,name', 'conferidaPor:id,name']);

        event(new DevolucaoAtualizada($devolucao));

        return response()->json(['devolucao' => $devolucao->paraQuadro()]);
    }

    // ─── VER O ARQUIVO ────────────────────────────────────────────────────────

    public function arquivo(Request $request, Devolucao $devolucao, DevolucaoAnexo $anexo): StreamedResponse
    {
        Gate::authorize('usar-devolucoes');

        abort_if($anexo->devolucao_id !== $devolucao->id, 404);

        $disco = Storage::disk(DevolucaoAnexo::DISCO);

        // O registro existe mas o arquivo já foi apagado pela faxina: 410 em vez
        // de 404 — sumiu por decisão, e a tela usa isso para explicar.
        abort_unless($disco->exists($anexo->caminho), 410, 'Este arquivo já passou do prazo e foi removido.');

        // Imagem abre na tela; PDF baixa. PDF exibido na mesma origem pode rodar
        // JavaScript embutido — com o attachment ele vai para o leitor do sistema.
        $inline = $anexo->ehImagem() && ! $request->boolean('baixar');

        return $disco->download(
            $anexo->caminho,
            $anexo->nome_original,
            [
                'Content-Type'        => $anexo->mime,
                'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                    . '; filename="' . addslashes($anexo->nome_original) . '"',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    public function removerAnexo(Request $request, Devolucao $devolucao, DevolucaoAnexo $anexo): JsonResponse
    {
        Gate::authorize('usar-devolucoes');

        abort_if($anexo->devolucao_id !== $devolucao->id, 404);

        // O card não pode ficar sem prova nenhuma: quem quer zerar, exclui o card.
        if ($devolucao->anexos()->count() <= 1) {
            return response()->json([
                'erro' => 'O card precisa de pelo menos um arquivo. Para desfazer, exclua o card.',
            ], 422);
        }

        $anexo->apagarComArquivo();

        $devolucao->load(['anexos', 'criadaPor:id,name', 'conferidaPor:id,name']);

        event(new DevolucaoAtualizada($devolucao));

        return response()->json(['devolucao' => $devolucao->paraQuadro()]);
    }

    // ─── CONFERIR ─────────────────────────────────────────────────────────────

    public function conferir(Request $request, Devolucao $devolucao): JsonResponse
    {
        Gate::authorize('usar-devolucoes');

        // Conferir de novo o que já está conferido só trocaria o nome de quem
        // conferiu, e a contagem da semana começaria outra vez.
        if ($devolucao->conferida()) {
            return response()->json(['erro' => 'Este card já foi conferido.'], 422);
        }

        $devolucao->update([
            'conferida_em'  => now(),
            'conferida_por' => $request->user()->id,
        ]);

        $devolucao->load(['anexos', 'criadaPor:id,name', 'conferidaPor:id,name']);

        event(new DevolucaoAtualizada($devolucao));

        return response()->json(['devolucao' => $devolucao->paraQuadro()]);
    }

    /** Conferido por engano — volta para o quadro aberto. */
    public function reabrir(Request $request, Devolucao $devolucao): JsonResponse
    {
        Gate::authorize('usar-devolucoes');

        $devolucao->update(['conferida_em' => null, 'conferida_por' => null]);

        $devolucao->load(['anexos', 'criadaPor:id,name']);

        event(new DevolucaoAtualizada($devolucao));

        return response()->json(['devolucao' => $devolucao->paraQuadro()]);
    }

    // ─── EXCLUIR ──────────────────────────────────────────────────────────────

    public function destroy(Request $request, Devolucao $devolucao): JsonResponse
    {
        Gate::authorize('usar-devolucoes');

        // O cascade da FK leva as linhas; os arquivos em disco o banco não sabe
        // que existem, então saem daqui.
        foreach ($devolucao->anexos as $anexo) {
            Storage::disk(DevolucaoAnexo::DISCO)->delete($anexo->caminho);
        }

        $id = $devolucao->id;
        $devolucao->delete();

        event(new DevolucaoAtualizada(removidaId: $id));

        return response()->json(['ok' => true]);
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    /** @return string o caminho gravado, para a limpeza em caso de falha */
    private function guardar(Devolucao $devolucao, UploadedFile $arquivo, int $userId): string
    {
        // O nome em disco é gerado por nós. O que veio do cliente é texto livre:
        // serve para exibir e batizar o download, nunca para montar caminho —
        // senão um "../../.env" escolhe onde gravar.
        $extensao = strtolower($arquivo->getClientOriginalExtension() ?: $arquivo->extension());
        $caminho  = "devolucoes/{$devolucao->id}/" . Str::uuid() . '.' . $extensao;

        Storage::disk(DevolucaoAnexo::DISCO)->putFileAs(
            dirname($caminho),
            $arquivo,
            basename($caminho),
        );

        $devolucao->anexos()->create([
            'caminho'       => $caminho,
            'nome_original' => $this->nomeLimpo($arquivo->getClientOriginalName()),
            // getMimeType() inspeciona o arquivo; getClientMimeType() confiaria no cliente
            'mime'          => $arquivo->getMimeType() ?: 'application/octet-stream',
            'tamanho'       => $arquivo->getSize(),
            'enviado_por'   => $userId,
        ]);

        return $caminho;
    }

    /** Só o basename, sem caractere de controle, no tamanho da coluna. */
    private function nomeLimpo(string $nome): string
    {
        $nome = basename(str_replace('\\', '/', $nome));
        $nome = preg_replace('/[\x00-\x1F\x7F"]+/u', '', $nome) ?? '';
        $nome = trim($nome);

        return mb_substr($nome !== '' ? $nome : 'arquivo', 0, 255);
    }
}
