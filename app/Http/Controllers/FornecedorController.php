<?php

namespace App\Http\Controllers;

use App\Models\Fornecedor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FornecedorController extends Controller
{
    /**
     * Importa fornecedores de um JSON enviado pelo usuário.
     * Usa upsert para não duplicar nem apagar registros existentes.
     *
     * Formato esperado do JSON:
     * [
     *   { "nome": "DOCES VIERA", "cnpj": "00.000.000/0001-00" },
     *   { "nome": "MINAS MAIS" }
     * ]
     */
    public function importar(Request $request): RedirectResponse
    {
        $request->validate([
            'arquivo' => 'required|file|mimes:json|max:2048',
        ]);

        $conteudo = file_get_contents($request->file('arquivo')->getRealPath());
        $dados    = json_decode($conteudo, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($dados)) {
            return back()->withErrors(['arquivo' => 'JSON inválido ou mal formatado.']);
        }

        // O JSON vem de fora e nada garante o formato: "nome" pode chegar como
        // lista, número ou objeto, e aí o trim() estoura com erro 500 no meio de
        // um upsert em massa. Aqui só passa linha que é objeto com nome de
        // texto — e o tamanho é limitado ao da coluna, senão o banco recusa a
        // carga inteira por causa de um registro.
        $registros = collect($dados)
            ->filter(fn($f) => is_array($f)
                && isset($f['nome'])
                && is_string($f['nome'])
                && trim($f['nome']) !== '')
            ->map(fn($f) => [
                'nome'       => mb_substr(mb_strtoupper(trim($f['nome'])), 0, 255),
                // 255 = o tamanho da coluna. Não corta mais que isso de
                // propósito: cnpj é UNIQUE, e encurtar criaria colisão entre
                // fornecedores que o arquivo trazia como distintos.
                'cnpj'       => isset($f['cnpj']) && is_string($f['cnpj'])
                    ? mb_substr(trim($f['cnpj']), 0, 255)
                    : null,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->values()
            ->toArray();

        if ($registros === []) {
            return back()->withErrors(['arquivo' => 'Nenhum fornecedor válido no arquivo — cada item precisa de um "nome" em texto.']);
        }

        // upsert: insere novos, atualiza cnpj dos existentes — nunca apaga
        Fornecedor::upsert(
            $registros,
            ['nome'],       // coluna de conflito
            ['cnpj', 'updated_at'] // colunas a atualizar se existir
        );

        return back()->with('sucesso', count($registros) . ' fornecedores importados/atualizados.');
    }
}
