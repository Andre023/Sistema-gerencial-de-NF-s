<?php

namespace App\Http\Controllers;

use App\Events\NotaAtualizada;
use App\Models\Anexo;
use App\Models\Nota;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos e fotos da nota.
 *
 * Enviar e remover: recebimento e pré-lote (quem tem o papel na mão).
 * VER: qualquer conta autenticada — compras precisa da foto da avaria para
 * resolver o card, e sem isso o anexo não serviria para nada.
 *
 * O arquivo nunca fica em public/: nota é documento fiscal, e link que abre
 * sem login é vazamento. Sai só por download(), depois do middleware 'auth'.
 */
class AnexoController extends Controller
{
    // ─── ENVIAR ───────────────────────────────────────────────────────────────

    public function store(Request $request, Nota $nota): JsonResponse
    {
        Gate::authorize('anexar-nota');

        // Nota que já saiu de cena não recebe anexo novo: o arquivo seria
        // apagado em seguida pela limpeza, e o usuário não entenderia o sumiço.
        if ($nota->cancelada_em) {
            return response()->json(['erro' => 'Esta nota está cancelada — não aceita novos anexos.'], 422);
        }

        $request->validate([
            // `mimes` olha o CONTEÚDO do arquivo (via finfo), não o Content-Type
            // que o navegador mandou — que é campo livre e mente à vontade.
            'arquivo' => [
                'required',
                'file',
                'max:' . Anexo::TAMANHO_MAX_KB,
                'mimes:' . implode(',', Anexo::EXTENSOES),
            ],
        ], [
            'arquivo.max'   => 'Arquivo grande demais (máximo ' . (Anexo::TAMANHO_MAX_KB / 1024) . ' MB).',
            'arquivo.mimes' => 'Formato não aceito. Envie foto (JPG, PNG, WebP, HEIC) ou PDF.',
        ]);

        $arquivo = $request->file('arquivo');

        // Nome do disco é gerado por nós. O nome que veio do cliente é texto
        // livre: serve para exibir e para batizar o download, nunca para montar
        // caminho — senão um "../../.env" escolhe onde gravar.
        $extensao = strtolower($arquivo->getClientOriginalExtension() ?: $arquivo->extension());
        $caminho  = "anexos/{$nota->id}/" . Str::uuid() . '.' . $extensao;

        Storage::disk(Anexo::DISCO)->putFileAs(
            dirname($caminho),
            $arquivo,
            basename($caminho),
        );

        $anexo = $nota->anexos()->create([
            'caminho'       => $caminho,
            'nome_original' => $this->nomeLimpo($arquivo->getClientOriginalName()),
            // getMimeType() inspeciona o arquivo; getClientMimeType() confiaria no cliente
            'mime'          => $arquivo->getMimeType() ?: 'application/octet-stream',
            'tamanho'       => $arquivo->getSize(),
            'enviado_por'   => $request->user()->id,
        ]);

        $nota->limparVisualizacao(); // agiu na nota → solta o 🙋‍♂️
        event(new NotaAtualizada($nota));

        return response()->json(['anexo' => $anexo->fresh('enviadoPor')->paraTela()], 201);
    }

    // ─── LISTAR ───────────────────────────────────────────────────────────────

    public function index(Nota $nota): JsonResponse
    {
        return response()->json([
            'anexos' => $nota->anexos()->with('enviadoPor:id,name')
                ->orderBy('created_at')->get()
                ->map(fn(Anexo $a) => $a->paraTela())->all(),
        ]);
    }

    // ─── BAIXAR / VER ─────────────────────────────────────────────────────────

    public function download(Request $request, Nota $nota, Anexo $anexo): StreamedResponse
    {
        $this->garanteVinculo($nota, $anexo);

        $disco = Storage::disk(Anexo::DISCO);

        // O registro existe mas o arquivo sumiu (limpeza a meio caminho, disco
        // restaurado sem o storage). 404 em vez de erro 500.
        abort_unless($disco->exists($anexo->caminho), 404);

        // Imagem abre na tela (é para isso que serve); PDF baixa. PDF exibido
        // na mesma origem pode rodar JavaScript embutido — com o attachment
        // ele vai para o leitor do sistema, fora do contexto da sessão.
        $inline = $anexo->ehImagem() && ! $request->boolean('baixar');

        return $disco->download(
            $anexo->caminho,
            $anexo->nome_original,
            [
                'Content-Type'        => $anexo->mime,
                'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                    . '; filename="' . addslashes($anexo->nome_original) . '"',
                // Reforça o nosniff global: o navegador não reinterpreta o tipo
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    // ─── REMOVER ──────────────────────────────────────────────────────────────

    public function destroy(Request $request, Nota $nota, Anexo $anexo): JsonResponse
    {
        Gate::authorize('anexar-nota');

        $this->garanteVinculo($nota, $anexo);

        $anexo->apagarComArquivo();

        event(new NotaAtualizada($nota));

        return response()->json(['ok' => true]);
    }

    // ─── HELPERS ──────────────────────────────────────────────────────────────

    /** Garante que o anexo pertence mesmo à nota da URL. */
    private function garanteVinculo(Nota $nota, Anexo $anexo): void
    {
        abort_if($anexo->nota_id !== $nota->id, 404);
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
