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

        $registros = collect($dados)
            ->filter(fn($f) => !empty($f['nome']))
            ->map(fn($f) => [
                'nome'       => mb_strtoupper(trim($f['nome'])),
                'cnpj'       => $f['cnpj'] ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ])
            ->toArray();

        // upsert: insere novos, atualiza cnpj dos existentes — nunca apaga
        Fornecedor::upsert(
            $registros,
            ['nome'],       // coluna de conflito
            ['cnpj', 'updated_at'] // colunas a atualizar se existir
        );

        return back()->with('sucesso', count($registros) . ' fornecedores importados/atualizados.');
    }
}
